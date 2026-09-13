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
