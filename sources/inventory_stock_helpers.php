<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_unit_helpers.php';

/**
 * Shared with forecasting.py. One row per recorded item/branch pair.
 * Use a LEFT JOIN for catalog choices, or an INNER JOIN for assigned-stock
 * dashboards/alerts. No view, runtime DDL, or inventory mutation is required.
 */
function inventoryStockTotalsSql(): string
{
    static $query = null;
    if ($query === null) {
        $content = file_get_contents(__DIR__ . '/inventory_stock_query.json');
        if ($content === false) {
            throw new RuntimeException('The shared inventory stock query file is missing.');
        }
        $definition = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (($definition['version'] ?? null) !== 1
            || !is_string($definition['query'] ?? null)
            || trim($definition['query']) === '') {
            throw new RuntimeException('The shared inventory stock query definition is invalid.');
        }
        $query = $definition['query'];
    }
    return $query;
}

/**
 * THE medical-supply rule: an item is a medical supply when its category is
 * "Medical Supplies" (the rule the Nurse Medical Supplies, Assessment and
 * Vaccination pages already use). Everything else (beds, forms, office and
 * other supplies) is non-medical. $c is the inventory_categories alias.
 */
function inventoryMedicalSql(string $c = 'c'): string
{
    return "$c.category_name = 'Medical Supplies'";
}

/**
 * Stock status of one item, the Inventory Overview rule: usable stock (expired
 * batches excluded) of 0 is Out of Stock; at or below a set minimum is Low
 * Stock. Returns 'out', 'low' or 'ok'.
 */
function inventoryStockStatusKey(float $usableStock, float $minimumStock): string
{
    if ($usableStock <= 0) return 'out';
    if ($minimumStock > 0 && $usableStock <= $minimumStock) return 'low';
    return 'ok';
}

/**
 * Inventory stat cards, one definition for every page (Inventory Overview,
 * Branch Admin Reports and Dashboard, Super Admin, Nurse Medical Supplies).
 * An item belongs to a branch when the branch has a stock record for it (the
 * Inventory Overview list); archived batches live in inventory_stocks_archive
 * and never count.
 *   items / medical_items / non_medical_items: items of the branch (all, medical, non-medical)
 *   low_stock / out_of_stock (+ medical_low_stock / medical_out_of_stock): inventoryStockStatusKey()
 *   expiring_batches: batches with stock that expire within 30 days (not expired yet)
 *   expired_batches:  batches with stock past their expiration date
 * Returns ['branches' => [branch_id => counts], 'total' => counts summed over
 * the branches] for $branchId = null, or the counts of one branch.
 */
function inventoryBranchSummary(mysqli $conn, ?string $branchId = null): array
{
    $empty = ['items' => 0, 'medical_items' => 0, 'non_medical_items' => 0, 'in_stock' => 0,
        'low_stock' => 0, 'out_of_stock' => 0, 'medical_low_stock' => 0, 'medical_out_of_stock' => 0,
        'expiring_batches' => 0, 'expired_batches' => 0];
    $where = $branchId === null ? '' : ' WHERE s.branch_id = ?';
    $medical = inventoryMedicalSql('c');
    $stmt = $conn->prepare(
        "SELECT s.branch_id,
                COUNT(*) AS items,
                SUM($medical) AS medical_items,
                SUM(s.usable_stock > 0) AS in_stock,
                SUM(s.usable_stock > 0 AND i.minimum_stock > 0 AND s.usable_stock <= i.minimum_stock) AS low_stock,
                SUM(s.usable_stock <= 0) AS out_of_stock,
                SUM($medical AND s.usable_stock > 0 AND i.minimum_stock > 0 AND s.usable_stock <= i.minimum_stock) AS medical_low_stock,
                SUM($medical AND s.usable_stock <= 0) AS medical_out_of_stock
         FROM (" . inventoryStockTotalsSql() . ") s
         INNER JOIN inventory_items i ON i.item_id = s.item_id
         INNER JOIN inventory_categories c ON c.category_id = i.category_id
         $where
         GROUP BY s.branch_id"
    );
    if ($branchId !== null) $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $branches = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $counts = $empty;
        foreach ($counts as $key => $_) {
            if (array_key_exists($key, $row)) $counts[$key] = (int)$row[$key];
        }
        $counts['non_medical_items'] = $counts['items'] - $counts['medical_items'];
        $branches[(string)$row['branch_id']] = $counts;
    }
    $stmt->close();
    $stmt = $conn->prepare(
        "SELECT branch_id,
                SUM(quantity_available > 0 AND expiration_date IS NOT NULL
                    AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_batches,
                SUM(quantity_available > 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()) AS expired_batches
         FROM inventory_stocks" . ($branchId === null ? '' : ' WHERE branch_id = ?') . "
         GROUP BY branch_id"
    );
    if ($branchId !== null) $stmt->bind_param('s', $branchId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $id = (string)$row['branch_id'];
        $branches[$id] = $branches[$id] ?? $empty;
        $branches[$id]['expiring_batches'] = (int)$row['expiring_batches'];
        $branches[$id]['expired_batches'] = (int)$row['expired_batches'];
    }
    $stmt->close();
    if ($branchId !== null) return $branches[$branchId] ?? $empty;
    $total = $empty;
    foreach ($branches as $counts) {
        foreach ($total as $key => $_) $total[$key] += $counts[$key];
    }
    ksort($branches);
    return ['branches' => $branches, 'total' => $total];
}
