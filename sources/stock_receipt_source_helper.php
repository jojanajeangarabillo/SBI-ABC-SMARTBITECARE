<?php
declare(strict_types=1);
require_once __DIR__ . '/stock_quantity_helpers.php';

function stockReceiptSourceStatement(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the stock receipt source.');
    return $stmt;
}
function stockReceiptSourceExecute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) throw new RuntimeException('Unable to save the stock receipt source.');
}
function stockReceiptSourceColumns(mysqli $conn, string $table): array
{
    $stmt = stockReceiptSourceStatement($conn, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->bind_param('s', $table);
    stockReceiptSourceExecute($stmt);
    $names = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $stmt->close();
    return $names;
}
function stockReceiptSourceAssertSchema(mysqli $conn): void
{
    foreach (['stock_transactions' => ['source_branch_id','receipt_source_type','source_name_snapshot',
        'receipt_stock_id','receipt_batch_snapshot','receipt_base_unit_snapshot'],
        'inventory_returns' => ['source_receipt_transaction_id','destination_reason']] as $table => $required) {
        if (array_diff($required, stockReceiptSourceColumns($conn, $table))) {
            throw new RuntimeException('Stock receipt source setup is incomplete. Run migrate_stock_receipt_sources.php before using these pages.');
        }
    }
}

/** Save provenance per receipt; aggregated batches may contain several sources. */
function stockReceiptSourceFromForm(mysqli $conn, string $receivingBranch, array $post): array
{
    $type = $post['stock_source_type'] ?? '';
    $branch = $post['stock_source_branch_id'] ?? '';
    $name = $post['stock_source_name'] ?? '';
    if (!is_string($type) || !in_array($type, ['Branch','External'], true)
        || !is_string($branch) || !is_string($name) || preg_match('//u', $name) !== 1
        || preg_match_all('/./us', $name) > 255) throw new RuntimeException('Select a valid stock receipt source.');
    $branch = trim($branch);
    $name = trim($name);
    if ($type !== 'Branch') {
        if ($branch !== '') throw new RuntimeException('A source branch requires the Branch source option.');
        return ['type' => $type, 'branch' => null, 'name' => $type === 'External' ? ($name ?: 'External supplier') : 'Unknown source'];
    }
    if ($branch === '' || strlen($branch) > 10 || $branch === $receivingBranch) {
        throw new RuntimeException('Select a source branch different from the receiving branch.');
    }
    $stmt = stockReceiptSourceStatement($conn, "SELECT branch_id,branch_name FROM branches WHERE branch_id=? AND status='Active'");
    $stmt->bind_param('s', $branch);
    stockReceiptSourceExecute($stmt);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException('The selected source branch is not active.');
    if ((string)$row['branch_id'] === $receivingBranch) throw new RuntimeException('The source branch must differ from the receiving branch.');
    return ['type' => 'Branch', 'branch' => $row['branch_id'], 'name' => $row['branch_name']];
}

/** Caller holds its return transaction. Source receipt and prior returns are locked. */
function stockReceiptSourceReturnRoute(mysqli $conn, string $branch, string $destination,
    array $reference, ?int $receiptId, string $reason): array
{
    $reason = trim($reason);
    if (preg_match('//u', $reason) !== 1 || preg_match_all('/./us', $reason) > 500) throw new RuntimeException('Enter a destination explanation of up to 500 characters.');
    $source = null;
    if ($receiptId !== null) {
        if (!$reference['item_id'] || !$reference['base_quantity']) throw new RuntimeException('Select the inventory item before its original stock receipt.');
        $stmt = stockReceiptSourceStatement($conn, "SELECT * FROM stock_transactions WHERE transaction_id=? AND branch_id=? FOR UPDATE");
        $stmt->bind_param('is', $receiptId, $branch);
        stockReceiptSourceExecute($stmt);
        $source = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$source || !in_array($source['transaction_type'], ['IN','RETURN','TRANSFER_IN'], true)
            || (int)$source['item_id'] !== (int)$reference['item_id']
            || (string)$source['receipt_base_unit_snapshot'] !== (string)$reference['base_unit']
            || substr((string)$source['transaction_date'], 0, 10) > date('Y-m-d')) {
            throw new RuntimeException('Select an original incoming receipt for this branch, item and inventory unit.');
        }
        if ($reference['stock_id'] !== null && (int)($source['receipt_stock_id'] ?? 0) !== $reference['stock_id']) {
            throw new RuntimeException('The original receipt does not belong to the selected sending batch.');
        }
        $received = stockQuantityParse((string)$source['quantity']);
        $returning = stockQuantityParse((string)$reference['base_quantity']);
        $stmt = stockReceiptSourceStatement($conn, 'SELECT inventory_quantity_snapshot, inventory_base_unit_snapshot
            FROM inventory_returns WHERE source_receipt_transaction_id=? FOR UPDATE');
        $stmt->bind_param('i', $receiptId);
        stockReceiptSourceExecute($stmt);
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $prior) {
            if ($prior['inventory_base_unit_snapshot'] !== $reference['base_unit'] || $prior['inventory_quantity_snapshot'] === null) {
                throw new RuntimeException('An earlier return against this receipt needs quantity/unit review.');
            }
            $used = stockQuantityParse((string)$prior['inventory_quantity_snapshot']);
            if ($used > $received) throw new RuntimeException('The original receipt quantity has already been assigned to returns.');
            $received -= $used;
        }
        $stmt->close();
        if ($returning > $received) throw new RuntimeException('This return exceeds the original receipt quantity remaining after earlier returns.');
    } elseif ($reference['item_id'] && $reference['stock_id']) {
        // If this batch has new branch-origin receipts, require an explicit one
        // instead of guessing when several branches supplied the same lot.
        $stmt = stockReceiptSourceStatement($conn, "SELECT transaction_id FROM stock_transactions
            WHERE branch_id=? AND item_id=? AND receipt_stock_id=? AND receipt_source_type='Branch' LIMIT 1");
        $itemId = (int)$reference['item_id']; $stockId = (int)$reference['stock_id'];
        $stmt->bind_param('sii', $branch, $itemId, $stockId);
        stockReceiptSourceExecute($stmt);
        $known = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($known) throw new RuntimeException('Select the original stock receipt for this batch so its source is recorded.');
    }
    $originalBranch = $source && $source['receipt_source_type'] === 'Branch' ? (string)$source['source_branch_id'] : '';
    if (($originalBranch === '' || $destination !== $originalBranch) && $reason === '') {
        throw new RuntimeException('Explain this destination when the original source is unknown, external, or a different branch.');
    }
    return ['receipt_id' => $receiptId, 'reason' => $reason === '' ? null : $reason];
}
