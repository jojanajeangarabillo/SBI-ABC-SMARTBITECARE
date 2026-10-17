<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_usage_wastage.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
// Additive only: inventory_usage_history keeps one row per real consumption,
// and Daily Inventory "Consumed" stays SUM(quantity_used). These columns only
// label each row so pages and reports can tell patient use from wastage:
//   usage_type   'Usage' (default, every existing row) or 'Wastage'
//   usage_source Assessment / Vaccination / Supplies / Daily Inventory (NULL = older record)
// Existing quantities, closings and training_dataset rows are not changed.
// Older rows created by Medical Supplies or the earlier Daily Inventory
// "Add Consumed" entry are labelled from their stock transaction (Usage ID).
$completed = 0;
try {
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_usage_history'");
    $stmt->execute();
    $columns = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $stmt->close();
    if (!$columns) throw new RuntimeException('Missing inventory_usage_history. Complete the existing database setup first.');
    $definitions = [
        'usage_type' => "VARCHAR(20) NOT NULL DEFAULT 'Usage'",
        'usage_source' => 'VARCHAR(30) NULL DEFAULT NULL',
        'source_vaccination_id' => 'INT NULL DEFAULT NULL',
        'wastage_reason' => 'VARCHAR(255) NULL DEFAULT NULL',
        'recorded_by' => 'INT NULL DEFAULT NULL',
        'recorded_at' => 'DATETIME NULL DEFAULT NULL',
    ];
    $plan = [];
    foreach ($definitions as $field => $definition) {
        if (!in_array($field, $columns, true)) {
            $plan[] = ["Add inventory_usage_history.$field", "ALTER TABLE inventory_usage_history ADD COLUMN `$field` $definition"];
        }
    }
    $index = $conn->query("SHOW INDEX FROM inventory_usage_history WHERE Key_name = 'idx_usage_branch_date_item'");
    if (!$index->fetch_assoc()) {
        $plan[] = ['Add index idx_usage_branch_date_item',
            'ALTER TABLE inventory_usage_history ADD INDEX idx_usage_branch_date_item (branch_id, usage_date, item_id)'];
    }
    // Label older non-vaccination usage from its stock transaction (exact Usage ID match).
    foreach ([
        'Supplies' => "Supply usage #%",
        'Daily Inventory' => "Daily inventory consumed entry | %",
    ] as $source => $pattern) {
        $countSql = in_array('usage_source', $columns, true)
            ? "SELECT COUNT(*) AS total FROM inventory_usage_history h
               WHERE h.usage_source IS NULL AND EXISTS (SELECT 1 FROM stock_transactions t
                 WHERE t.branch_id = h.branch_id AND t.item_id = h.item_id AND t.remarks LIKE '" . $pattern . "'
                   AND t.remarks LIKE CONCAT('%Usage ID: ', h.usage_id, ' |%'))"
            : "SELECT COUNT(*) AS total FROM inventory_usage_history h
               WHERE EXISTS (SELECT 1 FROM stock_transactions t
                 WHERE t.branch_id = h.branch_id AND t.item_id = h.item_id AND t.remarks LIKE '" . $pattern . "'
                   AND t.remarks LIKE CONCAT('%Usage ID: ', h.usage_id, ' |%'))";
        $pending = (int)($conn->query($countSql)->fetch_assoc()['total'] ?? 0);
        if ($pending > 0) {
            $plan[] = ["Label $pending older usage row(s) as $source",
                "UPDATE inventory_usage_history h SET h.usage_source = '$source'
                 WHERE h.usage_source IS NULL AND EXISTS (SELECT 1 FROM stock_transactions t
                   WHERE t.branch_id = h.branch_id AND t.item_id = h.item_id AND t.remarks LIKE '" . $pattern . "'
                     AND t.remarks LIKE CONCAT('%Usage ID: ', h.usage_id, ' |%'))"];
        }
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "Usage source and wastage labels are ready. Quantities, closings and training data were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Usage/wastage migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
