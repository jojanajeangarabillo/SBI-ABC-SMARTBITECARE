<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_unit_helpers.php';
require_once __DIR__ . '/stock_quantity_helpers.php';
require_once __DIR__ . '/inventory_officer_activity_helper.php';

/*
 * Stock-changing operations of the Inventory Officer's Stock Management page
 * (Stock Out / pull-out, expired-batch disposal, stock adjustment), moved here
 * unchanged so they run only when the Branch Admin APPROVES a request
 * (sources/inventory_approval_helpers.php). Same FEFO order, same batch rules,
 * same stock_transactions rows as before, so Daily Inventory still shows a
 * Stock Out or disposal as Pull-out (an OUT without a vaccination or usage
 * record) on the transaction date, never as Consumed.
 *
 * The caller owns the database transaction. $actor holds user_id (recorded on
 * the stock transaction: the officer who requested the movement), branch_id,
 * branch_name and username. $approvalRequestId links the movement to its
 * request (stock_transactions.approval_request_id) for the item history.
 * Errors throw; nothing is committed here.
 */

/** Same rule as the Stock Management page: medical supplies and vaccines are batch tracked. */
function inventoryOpsRequiresBatchTracking($categoryName): bool
{
    $normalized = strtolower(trim((string)$categoryName));
    return in_array($normalized, ['medical supplies', 'medical supply', 'vaccine', 'vaccines'], true);
}

/** Item with its unit fields (base/display/conversion) for messages and validation. */
function inventoryOpsItem(mysqli $conn, int $itemId): array
{
    $stmt = $conn->prepare(
        "SELECT i.item_id, i.item_name, i.category_id, i.minimum_stock, i.is_forecastable, c.category_name,
                COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name,
                COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), u.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base
         FROM inventory_items i
         INNER JOIN inventory_categories c ON i.category_id = c.category_id
         INNER JOIN units u ON i.unit_id = u.unit_id
         WHERE i.item_id = ? LIMIT 1"
    );
    if (!$stmt) throw new Exception('Unable to verify item.');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) throw new Exception('Selected item does not exist.');
    return $item;
}

/** Insert one stock_transactions row; approval_request_id only when given (column added by the migration). */
function inventoryOpsInsertTransaction(
    mysqli $conn,
    int $itemId,
    int $userId,
    string $branchId,
    string $type,
    string $quantity,
    string $remarks,
    string $transactionDateTime,
    ?int $approvalRequestId
): int {
    if ($approvalRequestId !== null) {
        $stmt = $conn->prepare("INSERT INTO stock_transactions
            (item_id, user_id, vaccination_id, branch_id, transaction_type, quantity, remarks, transaction_date, approval_request_id)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception('Unable to prepare stock transaction.');
        $stmt->bind_param('iisssssi', $itemId, $userId, $branchId, $type, $quantity, $remarks, $transactionDateTime, $approvalRequestId);
    } else {
        $stmt = $conn->prepare("INSERT INTO stock_transactions
            (item_id, user_id, vaccination_id, branch_id, transaction_type, quantity, remarks, transaction_date)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception('Unable to prepare stock transaction.');
        $stmt->bind_param('iisssss', $itemId, $userId, $branchId, $type, $quantity, $remarks, $transactionDateTime);
    }
    if (!$stmt->execute()) throw new Exception('Unable to record stock transaction: ' . $stmt->error);
    $transactionId = (int)$conn->insert_id;
    $stmt->close();
    return $transactionId;
}

/** Audit entry of the movement itself, in the same wording as the Stock Management page. */
function inventoryOpsAuditMovement(mysqli $conn, array $actor, int $transactionId, string $type, string $itemName,
    string $quantity, string $unitName, string $details): void
{
    $label = inventoryOfficerStockTransactionLabel($type);
    $qtyText = rtrim(rtrim(number_format(abs((float)$quantity), 4, '.', ''), '0'), '.') ?: '0';
    $text = sprintf('%s transaction #%d: %s | Quantity: %s %s%s', $label, $transactionId, $itemName, $qtyText, $unitName,
        $details !== '' ? ' | ' . $details : '');
    if (!inventoryOfficerAudit($conn, (int)$actor['user_id'], (string)$actor['branch_id'], 'Stock Management', $text)) {
        throw new Exception('Unable to save the stock movement audit entry.');
    }
}

/** Usable (or, for 'Expired', expired) stock of an item in the branch, scaled ten-thousandths. */
function inventoryOpsAvailableScaled(mysqli $conn, int $itemId, string $branchId, bool $expired): int
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity_available), 0) AS total FROM inventory_stocks
         WHERE item_id = ? AND branch_id = ? AND quantity_available > 0
           AND (" . ($expired
            ? 'expiration_date IS NOT NULL AND expiration_date < CURDATE()'
            : 'expiration_date IS NULL OR expiration_date >= CURDATE()') . ")"
    );
    $stmt->bind_param('is', $itemId, $branchId);
    $stmt->execute();
    $total = (string)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return stockQuantityParse($total, true);
}

/**
 * STOCK OUT / PULL-OUT (moved from InventoryOfficer_StockManagement.php).
 * FEFO: normal Stock Out uses only unexpired batches; reason 'Expired' uses
 * only expired batches. Returns transaction_id, remarks, item, before, after.
 */
function inventoryStockOutApply(
    mysqli $conn,
    array $actor,
    int $itemId,
    int $quantityScaled,
    string $reason,
    string $remarks,
    string $transactionDateTime,
    ?int $approvalRequestId = null
): array {
    $branchId = (string)$actor['branch_id'];
    $quantity = stockQuantityDecimal($quantityScaled);
    $itemData = inventoryOpsItem($conn, $itemId);
    $requiresBatchTracking = inventoryOpsRequiresBatchTracking($itemData['category_name']);
    stockQuantityValidateUnit($quantityScaled, $itemData);
    $before = inventoryOpsAvailableScaled($conn, $itemId, $branchId, $reason === 'Expired');
    $remaining = $quantityScaled;
    $usedBatches = [];

    if ($requiresBatchTracking) {
        if ($reason === 'Expired') {
            $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
                                         FROM inventory_stocks
                                         WHERE item_id = ?
                                           AND branch_id = ?
                                           AND quantity_available > 0
                                           AND expiration_date IS NOT NULL
                                           AND expiration_date < CURDATE()
                                         ORDER BY expiration_date IS NULL, expiration_date ASC, stock_id ASC
                                         FOR UPDATE");
        } else {
            $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
                                         FROM inventory_stocks
                                         WHERE item_id = ?
                                           AND branch_id = ?
                                           AND quantity_available > 0
                                           AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                                         ORDER BY expiration_date IS NULL, expiration_date ASC, stock_id ASC
                                         FOR UPDATE");
        }
        if (!$stockStmt) throw new Exception('Unable to load batch-tracked stock.');
        $stockStmt->bind_param('is', $itemId, $branchId);
    } else {
        $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
                                     FROM inventory_stocks
                                     WHERE item_id = ?
                                       AND branch_id = ?
                                       AND quantity_available > 0
                                       AND ((? = 'Expired' AND expiration_date IS NOT NULL AND expiration_date < CURDATE())
                                         OR (? <> 'Expired' AND (expiration_date IS NULL OR expiration_date >= CURDATE())))
                                     ORDER BY expiration_date IS NULL, expiration_date ASC, stock_id ASC
                                     FOR UPDATE");
        if (!$stockStmt) throw new Exception('Unable to load stock.');
        $stockStmt->bind_param('isss', $itemId, $branchId, $reason, $reason);
    }

    $stockStmt->execute();
    $stockRows = $stockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stockStmt->close();

    foreach ($stockRows as $stockRow) {
        if ($remaining <= 0) break;
        $available = stockQuantityParse($stockRow['quantity_available'], true);
        if ($available <= 0) continue;
        $deduct = min($remaining, $available);
        $newQuantity = stockQuantityDecimal($available - $deduct);
        $stockId = (int)$stockRow['stock_id'];
        $update = $conn->prepare("UPDATE inventory_stocks
                                  SET quantity_available = ?
                                  WHERE stock_id = ? AND branch_id = ?");
        if (!$update) throw new Exception('Unable to prepare stock out update.');
        $update->bind_param('sis', $newQuantity, $stockId, $branchId);
        if (!$update->execute()) throw new Exception('Unable to update stock: ' . $update->error);
        $update->close();
        $batch = $stockRow['batch_lot_no'];
        if ($batch !== null && $batch !== '') {
            $usedBatches[] = $batch . ' (-' . stockQuantityText($deduct) . ')';
        }
        $remaining -= $deduct;
    }

    if ($remaining > 0) {
        throw new Exception('Stock Out cannot exceed the current available stock (' . $itemData['item_name'] . ': '
            . inventoryQuantityText((float)stockQuantityDecimal($before), $itemData) . ' available now).');
    }

    $combinedRemarks = 'Reason: ' . $reason;
    if ($usedBatches) $combinedRemarks .= ' | Batch/Lot: ' . implode(', ', $usedBatches);
    if ($remarks !== '') $combinedRemarks .= ' | ' . $remarks;

    $transactionId = inventoryOpsInsertTransaction($conn, $itemId, (int)$actor['user_id'], $branchId, 'OUT',
        $quantity, $combinedRemarks, $transactionDateTime, $approvalRequestId);
    inventoryOpsAuditMovement($conn, $actor, $transactionId, 'OUT', (string)$itemData['item_name'], $quantity,
        (string)$itemData['unit_name'], $combinedRemarks);
    return ['transaction_id' => $transactionId, 'remarks' => $combinedRemarks, 'item' => $itemData,
        'before' => $before, 'after' => $before - $quantityScaled];
}

/**
 * DISPOSE / ARCHIVE AN EXPIRED BATCH (moved from the Stock Management page).
 * The whole remaining quantity of an already-expired batch is disposed: an OUT
 * transaction (Pull-out in Daily Inventory), the batch is copied to
 * inventory_stocks_archive and removed from active stock.
 */
function inventoryExpiredDisposalApply(
    mysqli $conn,
    array $actor,
    int $stockId,
    string $transactionDateTime,
    ?int $approvalRequestId = null
): array {
    $branchId = (string)$actor['branch_id'];
    $userId = (int)$actor['user_id'];
    $expiredStmt = $conn->prepare("SELECT
                                        s.stock_id,
                                        s.item_id,
                                        s.batch_lot_no,
                                        s.manufacturing_date,
                                        s.quantity_available,
                                        s.expiration_date,
                                        s.last_updated,
                                        i.item_name,
                                        COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name
                                   FROM inventory_stocks s
                                   INNER JOIN inventory_items i ON s.item_id = i.item_id
                                   INNER JOIN units u ON i.unit_id = u.unit_id
                                   WHERE s.stock_id = ?
                                     AND s.branch_id = ?
                                   LIMIT 1
                                   FOR UPDATE");
    if (!$expiredStmt) throw new Exception('Unable to load expired stock.');
    $expiredStmt->bind_param('is', $stockId, $branchId);
    $expiredStmt->execute();
    $expiredRow = $expiredStmt->get_result()->fetch_assoc();
    $expiredStmt->close();

    if (!$expiredRow) {
        throw new Exception('Expired stock record was not found in your branch (it may already be disposed).');
    }
    if (empty($expiredRow['expiration_date']) || $expiredRow['expiration_date'] >= date('Y-m-d')) {
        throw new Exception('Only already-expired stock can be disposed and archived.');
    }
    $disposeScaled = stockQuantityParse($expiredRow['quantity_available'], true);
    $disposeQty = stockQuantityDecimal($disposeScaled);
    if ($disposeScaled <= 0) {
        throw new Exception('This expired batch has no remaining stock to dispose.');
    }

    $archiveReason = 'Expired stock disposed and archived';
    $archiveStmt = $conn->prepare("INSERT INTO inventory_stocks_archive
                                   (stock_id, item_id, batch_lot_no, manufacturing_date, branch_id,
                                    quantity_available, expiration_date, last_updated,
                                    archived_at, archived_by, archive_reason)
                                   SELECT stock_id, item_id, batch_lot_no, manufacturing_date, branch_id,
                                          quantity_available, expiration_date, last_updated,
                                          NOW(), ?, ?
                                   FROM inventory_stocks
                                   WHERE stock_id = ? AND branch_id = ?");
    if (!$archiveStmt) throw new Exception('Unable to prepare expired stock archive.');
    $archiveStmt->bind_param('isis', $userId, $archiveReason, $stockId, $branchId);
    if (!$archiveStmt->execute()) {
        throw new Exception('Unable to archive expired stock: ' . $archiveStmt->error);
    }
    $archiveStmt->close();

    $batchText = !empty($expiredRow['batch_lot_no']) ? $expiredRow['batch_lot_no'] : 'N/A';
    $transactionRemarks = 'Expired stock disposal and archive'
        . ' | Batch/Lot: ' . $batchText
        . ' | Expiration Date: ' . $expiredRow['expiration_date']
        . ' | Original Stock ID: ' . $stockId;
    $itemId = (int)$expiredRow['item_id'];
    $transactionId = inventoryOpsInsertTransaction($conn, $itemId, $userId, $branchId, 'OUT', $disposeQty,
        $transactionRemarks, $transactionDateTime, $approvalRequestId);

    $deleteStmt = $conn->prepare("DELETE FROM inventory_stocks WHERE stock_id = ? AND branch_id = ?");
    if (!$deleteStmt) throw new Exception('Unable to prepare removal of archived stock.');
    $deleteStmt->bind_param('is', $stockId, $branchId);
    if (!$deleteStmt->execute() || $deleteStmt->affected_rows !== 1) {
        throw new Exception('Unable to remove expired stock from active inventory.');
    }
    $deleteStmt->close();

    inventoryOpsAuditMovement($conn, $actor, $transactionId, 'EXPIRED', (string)$expiredRow['item_name'], $disposeQty,
        (string)$expiredRow['unit_name'], 'Expired stock disposal and archive | Batch/Lot: ' . $batchText
        . ' | Expiration: ' . $expiredRow['expiration_date']);
    return ['transaction_id' => $transactionId, 'remarks' => $transactionRemarks, 'item_id' => $itemId,
        'item_name' => (string)$expiredRow['item_name'], 'before' => $disposeScaled, 'after' => 0];
}

/**
 * STOCK ADJUSTMENT of one stock record (moved from the Stock Management page).
 * The officer counted $countedScaled when the system showed $systemAtCountScaled.
 * Approval applies that counted DIFFERENCE to the current quantity, so usage
 * recorded between the count and the approval is not erased; when nothing
 * moved in between the result is exactly the counted quantity.
 */
function inventoryStockAdjustmentApply(
    mysqli $conn,
    array $actor,
    int $itemId,
    int $stockId,
    int $countedScaled,
    int $systemAtCountScaled,
    string $reason,
    string $remarks,
    string $transactionDateTime,
    ?int $approvalRequestId = null
): array {
    $branchId = (string)$actor['branch_id'];
    $itemData = inventoryOpsItem($conn, $itemId);
    $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
        FROM inventory_stocks WHERE stock_id=? AND item_id=? AND branch_id=?
        LIMIT 1 FOR UPDATE");
    if (!$stockStmt) throw new Exception('Unable to load the selected stock record.');
    $stockStmt->bind_param('iis', $stockId, $itemId, $branchId);
    $stockStmt->execute();
    $stockRow = $stockStmt->get_result()->fetch_assoc();
    $stockStmt->close();
    if (!$stockRow) throw new Exception('No matching stock record exists for the selected item in this branch.');

    stockQuantityValidateUnit($countedScaled, $itemData);
    $currentScaled = stockQuantityParse($stockRow['quantity_available'], true);
    $currentQuantity = stockQuantityDecimal($currentScaled);
    $deltaScaled = $countedScaled - $systemAtCountScaled;
    $newScaled = $currentScaled + $deltaScaled;
    if ($deltaScaled === 0) {
        throw new Exception('The adjusted quantity is the same as the stock at the time of the count. No change was made.');
    }
    if ($newScaled < 0) {
        throw new Exception('The adjustment would make the stock negative (current ' . stockQuantityText($currentScaled)
            . ', counted difference ' . stockQuantityText($deltaScaled) . '). Count the batch again and send a new request.');
    }
    $adjustedQuantity = stockQuantityDecimal($newScaled);
    $delta = stockQuantityDecimal($deltaScaled);

    $update = $conn->prepare("UPDATE inventory_stocks SET quantity_available = ? WHERE stock_id = ? AND branch_id = ?");
    if (!$update) throw new Exception('Unable to prepare adjustment update.');
    $update->bind_param('sis', $adjustedQuantity, $stockId, $branchId);
    if (!$update->execute()) throw new Exception('Unable to update stock: ' . $update->error);
    $update->close();

    $batchText = !empty($stockRow['batch_lot_no']) ? ' | Batch/Lot: ' . $stockRow['batch_lot_no'] : '';
    $combinedRemarks = 'Reason: ' . $reason . $batchText . ' | Previous Stock: ' . $currentQuantity . ' | New Stock: ' . $adjustedQuantity;
    if ($currentScaled !== $systemAtCountScaled) {
        $combinedRemarks .= ' | Stock moved after the count (' . stockQuantityText($systemAtCountScaled)
            . ' at count): the counted difference was applied';
    }
    if ($remarks !== '') $combinedRemarks .= ' | ' . $remarks;

    $transactionId = inventoryOpsInsertTransaction($conn, $itemId, (int)$actor['user_id'], $branchId, 'ADJUSTMENT',
        $delta, $combinedRemarks, $transactionDateTime, $approvalRequestId);
    inventoryOpsAuditMovement($conn, $actor, $transactionId, 'ADJUSTMENT', (string)$itemData['item_name'], $delta,
        (string)$itemData['unit_name'], $combinedRemarks);
    return ['transaction_id' => $transactionId, 'remarks' => $combinedRemarks, 'item' => $itemData,
        'before' => $currentScaled, 'after' => $newScaled];
}
