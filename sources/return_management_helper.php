<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_quantity_helpers.php';
require_once __DIR__ . '/stock_receipt_source_helper.php';

function returnManagementStatement(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the return record.');
    return $stmt;
}

function returnManagementExecute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) throw new RuntimeException('Unable to save the return record.');
}

/** Read-only schema inspection; page requests never create/alter/drop tables. */
function returnManagementColumns(mysqli $conn): array
{
    $result = $conn->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_returns'");
    if (!$result) throw new RuntimeException('Unable to check the returns database setup.');
    $columns = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $column) $columns[$column['COLUMN_NAME']] = $column;
    return $columns;
}

function returnManagementUniqueIndex(mysqli $conn, string $column): bool
{
    $stmt = returnManagementStatement($conn, "SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_returns' AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = ?");
    $stmt->bind_param('s', $column);
    returnManagementExecute($stmt);
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function returnManagementAssertSchema(mysqli $conn): void
{
    $columns = returnManagementColumns($conn);
    $required = ['return_id','return_number','branch_id','destination_branch_id','item_name','unit_id',
        'quantity','return_reason','remarks','transportation_details','status','created_by','sent_at',
        'received_at','processed_by','created_at','updated_at','item_id','stock_id','stock_transaction_id',
        'inventory_quantity_snapshot','inventory_base_unit_snapshot','source_batch_snapshot','legacy_status'];
    foreach ($required as $name) {
        if (!isset($columns[$name])) throw new RuntimeException('Return Management setup is incomplete. Run the Step 10 returns migration before opening this page.');
    }
    if (strtolower($columns['quantity']['COLUMN_TYPE']) !== 'decimal(12,2)'
        || strtolower($columns['inventory_quantity_snapshot']['COLUMN_TYPE']) !== 'decimal(12,4)'
        || strpos($columns['status']['COLUMN_TYPE'], "'In Transit'") === false
        || strpos($columns['status']['COLUMN_TYPE'], "'Received'") === false
        || !returnManagementUniqueIndex($conn, 'return_number')
        || !returnManagementUniqueIndex($conn, 'stock_transaction_id')) {
        throw new RuntimeException('Return Management setup is incomplete. Run the Step 10 returns migration before opening this page.');
    }
}

function returnManagementRequireActor(mysqli $conn, int $userId, string $branchId): void
{
    $stmt = returnManagementStatement($conn,
        "SELECT user_id FROM users WHERE user_id = ? AND branch_id = ? AND role_id = 5 AND status = 'Active'");
    $stmt->bind_param('is', $userId, $branchId);
    returnManagementExecute($stmt);
    $valid = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    if (!$valid) throw new RuntimeException('Your Inventory Officer account or assigned branch changed. Sign in again.');
}

/** Preserve the delivery form's DECIMAL(12,2) quantities without silently rounding. */
function returnManagementQuantity(string $raw): string
{
    $raw = trim($raw);
    if (!preg_match('/\A(?:[0-9]+(?:\.[0-9]{1,2})?|\.[0-9]{1,2})\z/D', $raw)) {
        throw new InvalidArgumentException('Return quantity must be positive, with at most two decimal places.');
    }
    $parts = explode('.', $raw, 2);
    $whole = ltrim($parts[0], '0');
    $fraction = str_pad($parts[1] ?? '', 2, '0');
    if (strlen($whole) > 10) throw new InvalidArgumentException('Return quantity exceeds the supported range.');
    $cents = (int)($whole ?: '0') * 100 + (int)$fraction;
    if ($cents === 0) throw new InvalidArgumentException('Return quantity must be greater than zero.');
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function returnManagementOptionalId(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/D', $value)
        || strlen($value) > 10 || (int)$value > 2147483647) {
        throw new InvalidArgumentException('Select a valid inventory reference.');
    }
    return (int)$value;
}

function returnManagementPostedText(mixed $value, int $limit, string $label): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    $value = trim($value);
    if (preg_match_all('/./us', $value) > $limit) throw new InvalidArgumentException(ucfirst($label) . ' is too long.');
    return $value;
}

function returnManagementSameQuantity(mixed $a, mixed $b): bool
{
    try { return stockQuantityParse((string)$a) === stockQuantityParse((string)$b); }
    catch (InvalidArgumentException $e) { return false; }
}

/** Optional source references; selecting a stock batch never deducts it. */
function returnManagementSourceReference(mysqli $conn, string $branchId, string $name, int $unitId,
    string $quantity, ?int $itemId, ?int $stockId): array
{
    $reference = ['item_id' => null, 'stock_id' => null, 'base_quantity' => null,
        'base_unit' => null, 'batch' => null];
    if ($itemId === null) {
        if ($stockId !== null) throw new RuntimeException('Select the inventory item before selecting a batch.');
        return $reference;
    }
    $stmt = returnManagementStatement($conn,
        'SELECT i.*, u.unit_name FROM inventory_items i JOIN units u ON u.unit_id = i.unit_id WHERE i.item_id = ? FOR UPDATE');
    $stmt->bind_param('i', $itemId);
    returnManagementExecute($stmt);
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item || (int)$item['unit_id'] !== $unitId || strcasecmp(trim($item['item_name']), $name) !== 0) {
        throw new RuntimeException('The selected inventory item must match the item name and return unit.');
    }
    $cents = (int)str_replace('.', '', $quantity);
    $factor = stockQuantityParse((string)$item['conversion_to_base']);
    if ($cents > intdiv(STOCK_QUANTITY_MAX_SCALED * 100, $factor)) {
        throw new RuntimeException('The linked inventory quantity exceeds the supported stock range.');
    }
    $product = $cents * $factor;
    if ($product % 100 !== 0) throw new RuntimeException('This return quantity converts to more than four decimal places in inventory.');
    $base = intdiv($product, 100);
    stockQuantityValidateUnit($base, $item);
    $reference['item_id'] = $itemId;
    $reference['base_quantity'] = stockQuantityDecimal($base);
    $reference['base_unit'] = inventoryBaseUnitLabel($item);
    if ($stockId !== null) {
        $stmt = returnManagementStatement($conn,
            'SELECT batch_lot_no FROM inventory_stocks WHERE stock_id = ? AND item_id = ? AND branch_id = ? FOR UPDATE');
        $stmt->bind_param('iis', $stockId, $itemId, $branchId);
        returnManagementExecute($stmt);
        $stock = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$stock) throw new RuntimeException('The selected batch does not belong to this item and sending branch.');
        $reference['stock_id'] = $stockId;
        $reference['batch'] = trim((string)($stock['batch_lot_no'] ?? '')) ?: 'No lot number';
    }
    return $reference;
}

/** Reference an already recorded incoming movement; do not perform another Stock In. */
function returnManagementValidateReceipt(mysqli $conn, array $return, string $branchId, int $transactionId): void
{
    if ((string)$return['destination_branch_id'] !== $branchId || empty($return['item_id'])
        || $return['inventory_quantity_snapshot'] === null || !$return['inventory_base_unit_snapshot']) {
        throw new RuntimeException('This delivery has no verified inventory item and quantity snapshot. Keep it as a delivery record.');
    }
    $stmt = returnManagementStatement($conn,
        "SELECT t.*, i.base_unit_label, u.unit_name FROM stock_transactions t
         JOIN inventory_items i ON i.item_id = t.item_id JOIN units u ON u.unit_id = i.unit_id
         WHERE t.transaction_id = ? AND t.branch_id = ? FOR UPDATE");
    $stmt->bind_param('is', $transactionId, $branchId);
    returnManagementExecute($stmt);
    $transaction = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$transaction || !in_array($transaction['transaction_type'], ['IN','RETURN','TRANSFER_IN'], true)
        || (int)$transaction['item_id'] !== (int)$return['item_id']
        || ($transaction['receipt_source_type'] === 'Branch' && (string)$transaction['source_branch_id'] !== (string)$return['branch_id'])
        || !returnManagementSameQuantity($transaction['quantity'], $return['inventory_quantity_snapshot'])
        || inventoryBaseUnitLabel($transaction) !== (string)$return['inventory_base_unit_snapshot']) {
        throw new RuntimeException('Choose an incoming stock transaction for this branch, item and exact returned quantity.');
    }
    $date = substr((string)$transaction['transaction_date'], 0, 10);
    $sent = substr((string)($return['sent_at'] ?: $return['created_at']), 0, 10);
    if ($date < $sent || $date > date('Y-m-d')) throw new RuntimeException('The stock receipt date must be on or after the shipment date and not in the future.');
    $stmt = returnManagementStatement($conn,
        'SELECT return_id FROM inventory_returns WHERE stock_transaction_id = ? AND return_id <> ? LIMIT 1');
    $returnId = (int)$return['return_id'];
    $stmt->bind_param('ii', $transactionId, $returnId);
    returnManagementExecute($stmt);
    $used = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($used) throw new RuntimeException('That stock receipt is already linked to another return.');
}

/** Read-only description for old references; no matching/backfill by item name. */
function returnManagementReferenceLabel(array $row): string
{
    if (empty($row['item_id'])) return 'Delivery record; no inventory item selected';
    if (empty($row['catalog_item_id'])) return 'Legacy inventory reference; item no longer available';
    if ($row['stock_id'] !== null && (empty($row['source_stock_id'])
        || (int)$row['source_stock_item_id'] !== (int)$row['item_id']
        || (string)$row['source_stock_branch_id'] !== (string)$row['branch_id'])) {
        return 'Legacy inventory reference; source batch needs review';
    }
    if ($row['inventory_quantity_snapshot'] === null) return 'Legacy inventory reference; quantity was not verified by Step 10';
    if ((string)$row['current_base_unit'] !== (string)$row['inventory_base_unit_snapshot']) return 'Inventory unit changed; receipt reference needs review';
    if ($row['stock_transaction_id'] !== null) {
        if (empty($row['receipt_transaction_id']) || (int)$row['receipt_item_id'] !== (int)$row['item_id']
            || (string)$row['receipt_branch_id'] !== (string)$row['destination_branch_id']
            || ($row['receipt_source_type'] === 'Branch' && (string)$row['receipt_source_branch'] !== (string)$row['branch_id'])
            || !in_array($row['receipt_type'], ['IN','RETURN','TRANSFER_IN'], true)
            || !returnManagementSameQuantity($row['receipt_quantity'], $row['inventory_quantity_snapshot'])) {
            return 'Recorded stock receipt reference needs review';
        }
        return 'Linked to stock receipt #' . (int)$row['stock_transaction_id'];
    }
    return 'Inventory item referenced; stock receipt recorded separately';
}
