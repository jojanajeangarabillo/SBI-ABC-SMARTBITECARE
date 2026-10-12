<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_stock_receipt_sources.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/stock_receipt_source_helper.php';
$completed = 0;
try {
    $definitions = [
        'stock_transactions' => [
            'source_branch_id' => 'VARCHAR(10) DEFAULT NULL',
            'receipt_source_type' => "ENUM('Branch','External','Unknown') NOT NULL DEFAULT 'Unknown'",
            'source_name_snapshot' => 'VARCHAR(255) DEFAULT NULL',
            'receipt_stock_id' => 'INT DEFAULT NULL',
            'receipt_batch_snapshot' => 'VARCHAR(100) DEFAULT NULL',
            'receipt_base_unit_snapshot' => 'VARCHAR(100) DEFAULT NULL',
        ],
        'inventory_returns' => [
            'source_receipt_transaction_id' => 'INT DEFAULT NULL',
            'destination_reason' => 'VARCHAR(500) DEFAULT NULL',
        ],
    ];
    $plan = [];
    foreach ($definitions as $table => $fields) {
        $columns = stockReceiptSourceColumns($conn, $table);
        if (!$columns) throw new RuntimeException('Missing ' . $table . '. Complete the existing database setup and Step 10 migration first.');
        foreach ($fields as $field => $type) if (!in_array($field, $columns, true)) {
            $plan[] = ["Add $table.$field", "ALTER TABLE `$table` ADD COLUMN `$field` $type"];
        }
    }
    foreach (['stock_transactions' => ['idx_receipt_source_stock' => ['branch_id','item_id','receipt_stock_id']],
              'inventory_returns' => ['idx_return_source_receipt' => ['source_receipt_transaction_id']]] as $table => $indexes) {
        foreach ($indexes as $index => $fields) {
            $stmt = stockReceiptSourceStatement($conn, 'SELECT COLUMN_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');
            $stmt->bind_param('ss', $table, $index); stockReceiptSourceExecute($stmt);
            $existing = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME'); $stmt->close();
            if ($existing && $existing !== $fields) throw new RuntimeException('An existing index named ' . $index . ' differs. Review it first.');
            if (!$existing) $plan[] = ["Add $table.$index", "ALTER TABLE `$table` ADD KEY `$index` (`" . implode('`,`', $fields) . '`)'];
        }
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) { stockReceiptSourceAssertSchema($conn); echo "Stock receipt source setup is ready. Existing origins were not guessed and stock quantities were retained.\n"; }
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Source migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some schema steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
