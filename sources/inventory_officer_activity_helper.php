<?php

/**
 * Shared Inventory Officer audit + Branch Admin notification helpers.
 *
 * These helpers intentionally do not start/commit transactions. Call them
 * inside the caller's transaction when the inventory change must be atomic
 * with its audit log and notification.
 */

if (!function_exists('inventoryOfficerAudit')) {
    function inventoryOfficerAudit(
        mysqli $conn,
        int $userId,
        ?string $branchId,
        string $module,
        string $action
    ): bool {
        $stmt = $conn->prepare(
            "INSERT INTO audit_logs (user_id, branch_id, action, module, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('isss', $userId, $branchId, $action, $module);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('inventoryOfficerNotifyBranchAdmins')) {
    function inventoryOfficerNotifyBranchAdmins(
        mysqli $conn,
        string $branchId,
        string $title,
        string $message,
        string $notificationType = 'inventory_activity',
        ?string $sourceKey = null
    ): bool {
        $adminStmt = $conn->prepare(
            "SELECT user_id
             FROM users
             WHERE branch_id = ?
               AND role_id = 2
               AND status = 'Active'"
        );

        if (!$adminStmt) {
            return false;
        }

        $adminStmt->bind_param('s', $branchId);

        if (!$adminStmt->execute()) {
            $adminStmt->close();
            return false;
        }

        $result = $adminStmt->get_result();
        $adminIds = [];

        while ($row = $result->fetch_assoc()) {
            $adminIds[] = (int)$row['user_id'];
        }

        $adminStmt->close();

        // A branch may temporarily have no active Branch Admin. That should
        // not block inventory work; there is simply nobody to notify yet.
        if (empty($adminIds)) {
            return true;
        }

        $insert = $conn->prepare(
            "INSERT INTO notifications
                (user_id, title, message, notification_type, source_key, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                message = VALUES(message),
                notification_type = VALUES(notification_type),
                is_read = 0,
                created_at = NOW()"
        );

        if (!$insert) {
            return false;
        }

        foreach ($adminIds as $adminId) {
            $key = $sourceKey;
            $insert->bind_param(
                'issss',
                $adminId,
                $title,
                $message,
                $notificationType,
                $key
            );

            if (!$insert->execute()) {
                $insert->close();
                return false;
            }
        }

        $insert->close();
        return true;
    }
}

if (!function_exists('inventoryOfficerStockTransactionLabel')) {
    function inventoryOfficerStockTransactionLabel(string $transactionType): string
    {
        return match (strtoupper(trim($transactionType))) {
            'IN' => 'Stock In',
            'OUT' => 'Stock Out',
            'ADJUSTMENT' => 'Stock Adjustment',
            'RETURN' => 'Stock Return',
            'EXPIRED' => 'Expired Stock Disposal',
            'TRANSFER_IN' => 'Transfer In',
            'TRANSFER_OUT' => 'Transfer Out',
            default => ucwords(strtolower(str_replace('_', ' ', $transactionType)))
        };
    }
}

if (!function_exists('inventoryOfficerRecordStockActivity')) {
    function inventoryOfficerRecordStockActivity(
        mysqli $conn,
        int $userId,
        string $branchId,
        string $branchName,
        string $officerName,
        int $transactionId,
        string $transactionType,
        string $itemName,
        float $quantity,
        string $unitName,
        string $details = ''
    ): bool {
        $label = inventoryOfficerStockTransactionLabel($transactionType);
        $qtyText = rtrim(rtrim(number_format(abs($quantity), 4, '.', ''), '0'), '.');
        if ($qtyText === '') {
            $qtyText = '0';
        }

        $auditAction = sprintf(
            '%s transaction #%d: %s | Quantity: %s %s%s',
            $label,
            $transactionId,
            $itemName,
            $qtyText,
            $unitName,
            $details !== '' ? ' | ' . $details : ''
        );

        if (!inventoryOfficerAudit(
            $conn,
            $userId,
            $branchId,
            'Stock Management',
            $auditAction
        )) {
            return false;
        }

        $message = sprintf(
            '%s recorded %s at %s. Item: %s. Quantity: %s %s. Transaction ID: %d.%s',
            $officerName,
            $label,
            $branchName,
            $itemName,
            $qtyText,
            $unitName,
            $transactionId,
            $details !== '' ? ' Details: ' . $details . '.' : ''
        );

        return inventoryOfficerNotifyBranchAdmins(
            $conn,
            $branchId,
            'Inventory ' . $label,
            $message,
            'inventory_stock_transaction',
            'inventory_tx:' . $transactionId
        );
    }
}

if (!function_exists('inventoryOfficerRecordReturnActivity')) {
    function inventoryOfficerRecordReturnActivity(
        mysqli $conn,
        int $userId,
        string $branchId,
        string $branchName,
        string $officerName,
        int $returnId,
        string $returnNumber,
        string $actionText,
        string $message,
        string $sourceSuffix
    ): bool {
        if (!inventoryOfficerAudit(
            $conn,
            $userId,
            $branchId,
            'Return Management',
            $actionText
        )) {
            return false;
        }

        return inventoryOfficerNotifyBranchAdmins(
            $conn,
            $branchId,
            'Inventory Return Update',
            $officerName . ' at ' . $branchName . ': ' . $message,
            'inventory_return',
            'inventory_return:' . $returnId . ':' . $sourceSuffix
        );
    }
}

/**
 * Notify active nurses of one committed medical-supply receipt. The caller
 * holds the stock-receipt transaction; this helper never commits or adjusts
 * stock. The receipt's branch, quantity and units come from saved records.
 */
if (!function_exists('inventoryOfficerNotifyNursesStockIn')) {
    function inventoryOfficerNotifyNursesStockIn(
        mysqli $conn,
        string $branchId,
        int $transactionId,
        int $stockId
    ): bool {
        $receiptStmt = $conn->prepare(
            "SELECT t.transaction_id,t.quantity,DATE(t.transaction_date) AS receipt_date,
                    i.item_name,c.category_name,
                    COALESCE(NULLIF(TRIM(i.base_unit_label),''),u.unit_name) AS base_unit,
                    b.branch_name,officer.username AS officer_name,
                    s.stock_id,s.batch_lot_no,s.expiration_date
             FROM stock_transactions t
             JOIN inventory_items i ON i.item_id=t.item_id
             JOIN inventory_categories c ON c.category_id=i.category_id
             JOIN units u ON u.unit_id=i.unit_id
             JOIN branches b ON b.branch_id=t.branch_id
             JOIN users officer ON officer.user_id=t.user_id
             LEFT JOIN inventory_stocks s ON s.stock_id=?
                AND s.item_id=t.item_id AND s.branch_id=t.branch_id
             WHERE t.transaction_id=? AND t.branch_id=? AND t.transaction_type='IN'
             LIMIT 1"
        );
        if (!$receiptStmt) return false;
        $receiptStmt->bind_param('iis', $stockId, $transactionId, $branchId);
        if (!$receiptStmt->execute()) {
            $receiptStmt->close();
            return false;
        }
        $receipt = $receiptStmt->get_result()->fetch_assoc();
        $receiptStmt->close();
        if (!$receipt) return false;
        $category = strtolower(trim((string)$receipt['category_name']));
        if (!in_array($category, ['medical supplies', 'medical supply', 'vaccine', 'vaccines'], true)) {
            return true;
        }
        if ($receipt['stock_id'] === null) return false;

        $quantity = rtrim(rtrim(number_format((float)$receipt['quantity'], 4, '.', ''), '0'), '.');
        $title = 'Medical Supplies Received';
        $message = (string)$receipt['officer_name'] . ' recorded Stock In at ' . (string)$receipt['branch_name'] . '.'
            . "\nItem: " . (string)$receipt['item_name']
            . "\nReceived: " . $quantity . ' ' . (string)$receipt['base_unit']
            . "\nReceipt date: " . (string)$receipt['receipt_date']
            . "\nBatch/Lot: " . (trim((string)$receipt['batch_lot_no']) ?: 'N/A')
            . "\nExpiration: " . ((string)($receipt['expiration_date'] ?? '') ?: 'No expiration')
            . "\nTransaction ID: " . $transactionId
            . "\nInclude this receipt in the Daily Inventory delivery total for "
            . (string)$receipt['receipt_date'] . ', after verifying the day\'s receipts.';
        $type = 'stock_received';
        $sourceKey = 'nurse_stock_in:' . $transactionId;
        $insert = $conn->prepare(
            "INSERT INTO notifications (user_id,title,message,notification_type,source_key,is_read,created_at)
             SELECT user_id,?,?,?,?,0,NOW() FROM users
             WHERE branch_id=? AND role_id=3 AND status='Active'
             ON DUPLICATE KEY UPDATE notification_id=notification_id"
        );
        if (!$insert) return false;
        // Zero active recipients is a successful no-op; it does not block Stock In.
        $insert->bind_param('sssss', $title, $message, $type, $sourceKey, $branchId);
        $ok = $insert->execute();
        $insert->close();
        return $ok;
    }
}



/** Called inside the return transaction; one notice per receiving officer/return. */
function inventoryOfficerNotifyIncomingReturn(mysqli $conn, int $returnId): bool
{
    $stmt = $conn->prepare("SELECT ir.*, sb.branch_name AS source_name, db.branch_name AS destination_name,
        u.unit_name FROM inventory_returns ir
        JOIN branches sb ON sb.branch_id=ir.branch_id JOIN branches db ON db.branch_id=ir.destination_branch_id
        LEFT JOIN units u ON u.unit_id=ir.unit_id WHERE ir.return_id=?");
    if (!$stmt) return false;
    $stmt->bind_param('i', $returnId);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) return false;
    $received=$row['status']==='Received';
    $title=$received ? 'Return Received' : 'Incoming Return';
    $message='Return '.($row['return_number'] ?: ('RET-'.$returnId)).' from '.$row['source_name']
        .' to '.$row['destination_name'].".\nItem: ".$row['item_name']
        ."\nQuantity: ".rtrim(rtrim((string)$row['quantity'],'0'),'.').' '.($row['unit_name'] ?: 'unit')
        ."\nReason: ".$row['return_reason']."\nStatus: ".$row['status']
        .($row['transportation_details'] ? "\nTransportation: ".$row['transportation_details'] : '')
        .($received ? "\nPhysical delivery was confirmed. Stock movements are recorded separately."
            : "\nOpen Return Management and confirm receipt after the shipment physically arrives.");
    $key='incoming_return:'.$returnId;
    $branch=(string)$row['destination_branch_id'];
    if ($received) {
        $stmt=$conn->prepare("UPDATE notifications n JOIN users u ON u.user_id=n.user_id
            SET n.title=?,n.message=?,n.is_read=1
            WHERE n.source_key=? AND n.notification_type='incoming_return'
              AND u.branch_id=? AND u.role_id=5");
        if (!$stmt) return false;
        $stmt->bind_param('ssss',$title,$message,$key,$branch);
    } else {
        $stmt=$conn->prepare("INSERT INTO notifications (user_id,title,message,notification_type,source_key,is_read)
            SELECT user_id,?,?,'incoming_return',?,0 FROM users
            WHERE branch_id=? AND role_id=5 AND status='Active'
            ON DUPLICATE KEY UPDATE notification_id=notification_id");
        if (!$stmt) return false;
        $stmt->bind_param('ssss',$title,$message,$key,$branch);
    }
    $ok=$stmt->execute(); $stmt->close(); return $ok;
}


/** One receipt-confirmation notice per active sending-branch officer/return. */
function inventoryOfficerNotifyReturnSenderReceived(mysqli $conn, int $returnId): bool
{
    $stmt=$conn->prepare("SELECT ir.*, sb.branch_name AS source_name, db.branch_name AS destination_name,
        u.unit_name, COALESCE(receiver.username,'Inventory Officer') AS receiver_name
        FROM inventory_returns ir
        JOIN branches sb ON sb.branch_id=ir.branch_id
        JOIN branches db ON db.branch_id=ir.destination_branch_id
        LEFT JOIN units u ON u.unit_id=ir.unit_id
        LEFT JOIN users receiver ON receiver.user_id=ir.processed_by
        WHERE ir.return_id=? AND ir.status='Received'
          AND ir.received_at IS NOT NULL AND ir.processed_by IS NOT NULL");
    if (!$stmt) return false;
    $stmt->bind_param('i',$returnId);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) return false;
    $title='Your Return Was Received';
    $message='Return '.($row['return_number'] ?: ('RET-'.$returnId))
        .' from '.$row['source_name'].' was confirmed as received at '.$row['destination_name'].'.'
        ."\nItem: ".$row['item_name']
        ."\nQuantity: ".rtrim(rtrim((string)$row['quantity'],'0'),'.').' '.($row['unit_name'] ?: 'unit')
        ."\nReceived by: ".$row['receiver_name']
        ."\nReceipt confirmed: ".$row['received_at']
        ."\nStatus: Received\nPhysical delivery was confirmed. Open Return Management to view the record.";
    $sourceKey='return_received:'.$returnId;
    $branch=(string)$row['branch_id'];
    $stmt=$conn->prepare("INSERT INTO notifications (user_id,title,message,notification_type,source_key,is_read)
        SELECT user_id,?,?,'return_received',?,0 FROM users
        WHERE branch_id=? AND role_id=5 AND status='Active'
        ON DUPLICATE KEY UPDATE notification_id=notification_id");
    if (!$stmt) return false;
    $stmt->bind_param('ssss',$title,$message,$sourceKey,$branch);
    $ok=$stmt->execute(); $stmt->close(); return $ok;
}
