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
