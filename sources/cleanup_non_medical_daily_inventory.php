<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Lists (default) or removes (--apply) NON-MEDICAL rows that reached the Nurse
 * Daily Inventory before it was limited to medical supplies:
 *   - daily_inventory_closings rows of non-medical items, and
 *   - training_dataset rows of non-medical items that came from a daily
 *     closing (source_type = 'daily_closing').
 * Imported historical training rows are never touched. Medical items are never
 * touched. Run it yourself AFTER a database backup:
 *   php sources/cleanup_non_medical_daily_inventory.php            (preview, read-only)
 *   php sources/cleanup_non_medical_daily_inventory.php --apply    (delete the listed rows)
 * Optional: --branch=SBI-002 limits both modes to one branch.
 * "Non-medical" uses the shared rule inventoryMedicalSql(): category is not
 * "Medical Supplies".
 */
$options = getopt('', ['apply', 'branch::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php sources/cleanup_non_medical_daily_inventory.php [--branch=BRANCH_ID] [--apply]\nDefault: read-only preview.\n";
    exit;
}
$apply = isset($options['apply']);
$branchId = isset($options['branch']) ? trim((string)$options['branch']) : null;
if ($branchId !== null && !preg_match('/^[A-Za-z0-9_-]{1,10}$/', $branchId)) {
    fwrite(STDERR, "Invalid --branch.\n");
    exit(1);
}
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/inventory_stock_helpers.php';

$nonMedical = 'NOT (' . inventoryMedicalSql('c') . ')';
$branchSql = $branchId !== null ? ' AND x.branch_id = ?' : '';
$queries = [
    'daily_inventory_closings' => "SELECT x.closing_id AS row_id, x.branch_id, x.inventory_date AS row_date, x.item_id,
            i.item_name, c.category_name, x.status AS detail
        FROM daily_inventory_closings x
        JOIN inventory_items i ON i.item_id = x.item_id
        JOIN inventory_categories c ON c.category_id = i.category_id
        WHERE $nonMedical$branchSql ORDER BY x.branch_id, x.inventory_date, x.item_id",
    'training_dataset' => "SELECT x.training_id AS row_id, x.branch_id, x.record_date AS row_date, x.item_id,
            i.item_name, c.category_name, x.source_type AS detail
        FROM training_dataset x
        JOIN inventory_items i ON i.item_id = x.item_id
        JOIN inventory_categories c ON c.category_id = i.category_id
        WHERE x.source_type = 'daily_closing' AND $nonMedical$branchSql ORDER BY x.branch_id, x.record_date, x.item_id",
];
$found = [];
foreach ($queries as $table => $sql) {
    $stmt = $conn->prepare($sql);
    if ($branchId !== null) $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $found[$table] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
echo ($apply ? 'APPLY' : 'PREVIEW – read-only') . ($branchId !== null ? " (branch $branchId)" : '') . "\n";
foreach ($found as $table => $rows) {
    echo "\n$table: " . count($rows) . " non-medical row(s)\n";
    foreach ($rows as $row) {
        printf("  #%-6d %-8s %s  item %-4d %-28s %-24s %s\n", (int)$row['row_id'], $row['branch_id'], $row['row_date'],
            (int)$row['item_id'], mb_substr((string)$row['item_name'], 0, 28), (string)$row['category_name'], (string)$row['detail']);
    }
}
$total = count($found['daily_inventory_closings']) + count($found['training_dataset']);
if (!$apply || $total === 0) {
    echo "\n" . ($total === 0 ? "Nothing to clean up.\n" : "No data was changed. After a backup, add --apply to delete exactly these rows.\n");
    exit;
}
$conn->begin_transaction();
try {
    $deleted = ['training_dataset' => 0, 'daily_inventory_closings' => 0];
    $del = $conn->prepare("DELETE FROM training_dataset WHERE training_id = ? AND source_type = 'daily_closing'");
    foreach ($found['training_dataset'] as $row) {
        $id = (int)$row['row_id'];
        $del->bind_param('i', $id);
        $del->execute();
        $deleted['training_dataset'] += $del->affected_rows;
    }
    $del->close();
    $del = $conn->prepare('DELETE FROM daily_inventory_closings WHERE closing_id = ?');
    foreach ($found['daily_inventory_closings'] as $row) {
        $id = (int)$row['row_id'];
        $del->bind_param('i', $id);
        $del->execute();
        $deleted['daily_inventory_closings'] += $del->affected_rows;
    }
    $del->close();
    $conn->commit();
    echo "\nDeleted: {$deleted['daily_inventory_closings']} daily closing(s), {$deleted['training_dataset']} training row(s) from daily closings. Imported history was not touched.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Cleanup stopped, nothing was deleted: ' . $e->getMessage() . "\n");
    exit(1);
}
