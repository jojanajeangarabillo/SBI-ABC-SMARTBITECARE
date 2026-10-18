<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_unit_helpers.php';

/*
 * Forecast Accuracy shows errors in each item's counting unit (vials,
 * ampules). Saved evaluation rows stay exactly as stored, in base units
 * (sites, mL); only the page divides MAE, RMSE and quantity totals by the
 * item's conversion_to_base. SMAPE and the review bands do not depend on the
 * unit and are never converted. "Base units (as stored)" shows the saved
 * numbers unchanged, e.g. to match a run quoted in the paper.
 */

/** 'display' (vials/ampules, default) or 'base' (as stored). */
function forecastAccuracyUnitMode(): string
{
    return ($_GET['units'] ?? 'display') === 'base' ? 'base' : 'display';
}

/** item_id => unit settings of every inventory item (current catalog). */
function forecastAccuracyUnitMap(mysqli $conn): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $result = $conn->query("SELECT i.item_id, i.base_unit_label, i.display_unit_label, i.conversion_to_base, u.unit_name
            FROM inventory_items i LEFT JOIN units u ON u.unit_id = i.unit_id");
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $map[(int)$row['item_id']] = $row;
        }
    }
    return $map;
}

/** Divisor for one item's base-unit errors in the selected mode. */
function forecastAccuracyScale(array $map, int $itemId, string $mode): float
{
    if ($mode !== 'display' || !isset($map[$itemId])) {
        return 1.0;
    }
    return inventoryConversionToBase($map[$itemId]);
}

/** Unit label for one item in the selected mode ("vials", "ampules", or the stored base unit). */
function forecastAccuracyUnitLabel(array $map, int $itemId, string $storedUnit, string $mode): string
{
    if ($mode !== 'display' || !isset($map[$itemId])) {
        return $storedUnit !== '' ? $storedUnit : 'Unverified unit';
    }
    return inventoryUnitWord(inventoryCountingUnit($map[$itemId]), 2);
}

/** A stored base-unit value converted for display; null stays null. */
function forecastAccuracyConvert($value, float $scale)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return $value;
    }
    return (float)$value / $scale;
}

/**
 * Copy of a saved evaluation/window row with MAE, RMSE and quantity totals in
 * the selected unit and base_unit replaced by that unit's label. SMAPE fields
 * are left untouched.
 */
function forecastAccuracyConvertRow(array $row, array $map, string $mode, bool $rolling): array
{
    $itemId = (int)($row['item_id'] ?? 0);
    $scale = forecastAccuracyScale($map, $itemId, $mode);
    $fields = $rolling
        ? ['xgb_mae', 'xgb_rmse', 'last_mae', 'last_rmse', 'mean_mae', 'mean_rmse',
           'actual_total', 'xgb_forecast_total', 'last_forecast_total', 'mean_forecast_total']
        : ['mae', 'rmse', 'actual_total', 'forecast_total', 'total_absolute_error'];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $row[$field] = forecastAccuracyConvert($row[$field], $scale);
        }
    }
    $row['stored_base_unit'] = (string)($row['base_unit'] ?? '');
    $row['base_unit'] = forecastAccuracyUnitLabel($map, $itemId, (string)($row['base_unit'] ?? ''), $mode);
    $row['unit_scale'] = $scale;
    return $row;
}
