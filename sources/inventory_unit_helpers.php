<?php
declare(strict_types=1);

/**
 * Inventory-unit helpers.
 *
 * Stock and usage quantities are stored in the item's smallest measurable
 * base unit. Examples:
 *   SPEEDA: base=site, display=Vial, conversion_to_base=6
 *   ERIG:   base=mL,   display=Vial, conversion_to_base=<verified vial mL>
 */

function inventoryFormatNumber(float $value, int $decimals = 4): string
{
    $formatted = number_format($value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function inventoryBaseUnitLabel(array $item): string
{
    $label = trim((string)($item['base_unit_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $unit = trim((string)($item['unit_name'] ?? ''));
    return $unit !== '' ? $unit : 'unit';
}

function inventoryDisplayUnitLabel(array $item): string
{
    $label = trim((string)($item['display_unit_label'] ?? ''));
    return $label !== '' ? $label : inventoryBaseUnitLabel($item);
}

function inventoryConversionToBase(array $item): float
{
    $conversion = (float)($item['conversion_to_base'] ?? 1);
    return $conversion > 0 ? $conversion : 1.0;
}

function inventoryIsSiteBased(array $item): bool
{
    return in_array(
        strtolower(inventoryBaseUnitLabel($item)),
        ['site', 'sites'],
        true
    );
}

function inventoryInputStep(array $item): string
{
    return inventoryIsSiteBased($item) ? '1' : '0.0001';
}

function inventoryDisplayToBase(float $displayQuantity, array $item): float
{
    return round($displayQuantity * inventoryConversionToBase($item), 4);
}

function inventoryBaseToDisplay(float $baseQuantity, array $item): float
{
    return round($baseQuantity / inventoryConversionToBase($item), 4);
}

function inventoryStockBreakdown(float $baseQuantity, array $item): string
{
    $baseQuantity = max(0, $baseQuantity);
    $baseUnit = inventoryBaseUnitLabel($item);
    $displayUnit = inventoryDisplayUnitLabel($item);
    $conversion = inventoryConversionToBase($item);

    if ($conversion <= 1.0 || strcasecmp($baseUnit, $displayUnit) === 0) {
        return inventoryFormatNumber($baseQuantity) . ' ' . $baseUnit;
    }

    $fullDisplayUnits = (int)floor(($baseQuantity + 0.0000001) / $conversion);
    $remainder = $baseQuantity - ($fullDisplayUnits * $conversion);
    if (abs($remainder) < 0.00005) {
        $remainder = 0.0;
    }

    $parts = [];
    if ($fullDisplayUnits > 0 || $remainder === 0.0) {
        $parts[] = $fullDisplayUnits . ' ' . $displayUnit;
    }
    if ($remainder > 0) {
        $parts[] = inventoryFormatNumber($remainder) . ' ' . $baseUnit;
    }

    return implode(' + ', $parts)
        . ' (' . inventoryFormatNumber($baseQuantity) . ' ' . $baseUnit . ' total)';
}

function inventoryUsageDescription(float $baseQuantity, array $item): string
{
    $baseUnit = inventoryBaseUnitLabel($item);
    $displayUnit = inventoryDisplayUnitLabel($item);
    $conversion = inventoryConversionToBase($item);
    $description = inventoryFormatNumber($baseQuantity) . ' ' . $baseUnit;

    if ($baseQuantity >= 0
        && $conversion > 1.0
        && strcasecmp($baseUnit, $displayUnit) !== 0) {
        return inventoryStockBreakdown($baseQuantity, $item);
    }

    return $description;
}

/** True when the item is counted in a larger display unit (e.g. vials of 6 sites). */
function inventoryHasDisplayUnit(array $item): bool
{
    return inventoryConversionToBase($item) > 1.0
        && strcasecmp(inventoryBaseUnitLabel($item), inventoryDisplayUnitLabel($item)) !== 0;
}

/** Unit label in the clinic's wording: "vial"/"vials", "site"/"sites", "mL" stays "mL". */
function inventoryUnitWord(string $unit, float $quantity): string
{
    $unit = trim($unit);
    if ($unit === '') {
        return '';
    }
    // Abbreviations such as mL, IU or pcs keep their spelling.
    if (preg_match('/^(ml|l|mg|g|iu|cc|pcs|pc)$/i', $unit)) {
        return strtolower($unit) === 'ml' ? 'mL' : $unit;
    }
    $word = strtolower($unit);
    if (abs($quantity - 1.0) < 0.00005 || str_ends_with($word, 's')) {
        return $word;
    }
    return $word . 's';
}

/**
 * A base-unit quantity in the clinic's format: "67 vials + 3 sites",
 * "12 vials + 3 mL", "4 ampules", "30 vials". Items without a larger display
 * unit show the base unit ("150 pcs"). Negative values (variance) keep the sign.
 */
function inventoryQuantityText(float $baseQuantity, array $item): string
{
    $sign = $baseQuantity < -0.00005 ? '-' : '';
    $baseQuantity = abs($baseQuantity);
    $baseUnit = inventoryBaseUnitLabel($item);
    if (!inventoryHasDisplayUnit($item)) {
        // Display unit with conversion 1 (e.g. ATS: 1 ampule = 1 mL) reads in the display unit.
        $unit = inventoryConversionToBase($item) === 1.0 ? inventoryDisplayUnitLabel($item) : $baseUnit;
        return $sign . inventoryFormatNumber($baseQuantity) . ' ' . inventoryUnitWord($unit, $baseQuantity);
    }
    $conversion = inventoryConversionToBase($item);
    $full = (int)floor(($baseQuantity + 0.0000001) / $conversion);
    $remainder = round($baseQuantity - $full * $conversion, 4);
    if (abs($remainder) < 0.00005) {
        $remainder = 0.0;
    }
    $parts = [];
    if ($full > 0 || $remainder === 0.0) {
        $parts[] = $full . ' ' . inventoryUnitWord(inventoryDisplayUnitLabel($item), (float)$full);
    }
    if ($remainder > 0) {
        $parts[] = inventoryFormatNumber($remainder) . ' ' . inventoryUnitWord($baseUnit, $remainder);
    }
    return $sign . implode(' + ', $parts);
}

/** A base-unit quantity as display units with one decimal: "67.5 vials", "3.0 ampules". */
function inventoryDisplayQuantityText(float $baseQuantity, array $item, int $decimals = 1): string
{
    $unit = inventoryDisplayUnitLabel($item);
    $value = $baseQuantity / inventoryConversionToBase($item);
    return number_format($value, $decimals, '.', ',') . ' ' . inventoryUnitWord($unit, round($value, $decimals));
}
