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
