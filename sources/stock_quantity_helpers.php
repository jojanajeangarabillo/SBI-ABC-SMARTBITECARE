<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_unit_helpers.php';

// DECIMAL(12,4): use ten-thousandths for exact stock arithmetic.
const STOCK_QUANTITY_SCALE = 10000;
const STOCK_QUANTITY_MAX_SCALED = 999999999999;

function stockQuantityParse($value, bool $allowZero = false): int
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('Enter a valid quantity.');
    }
    $value = trim($value);
    if (!preg_match('/\A(?:[0-9]+(?:\.[0-9]{1,4})?|\.[0-9]{1,4})\z/', $value)) {
        throw new InvalidArgumentException('Quantity must be a number with at most four decimal places.');
    }
    $parts = explode('.', $value, 2);
    $whole = ltrim($parts[0], '0');
    $fraction = str_pad($parts[1] ?? '', 4, '0');
    if (strlen($whole) > 8) {
        throw new InvalidArgumentException('Quantity cannot exceed 99,999,999.9999.');
    }
    $scaled = (int)($whole === '' ? '0' : $whole) * STOCK_QUANTITY_SCALE + (int)$fraction;
    if ($scaled === 0 && !$allowZero) {
        throw new InvalidArgumentException('Quantity must be greater than zero.');
    }
    return $scaled;
}

function stockQuantityDecimal(int $scaled): string
{
    if (abs($scaled) > STOCK_QUANTITY_MAX_SCALED) {
        throw new InvalidArgumentException('The resulting stock quantity exceeds the supported range.');
    }
    $magnitude = abs($scaled);
    return ($scaled < 0 ? '-' : '')
        . intdiv($magnitude, STOCK_QUANTITY_SCALE)
        . '.' . str_pad((string)($magnitude % STOCK_QUANTITY_SCALE), 4, '0', STR_PAD_LEFT);
}

function stockQuantityText(int $scaled): string
{
    return rtrim(rtrim(stockQuantityDecimal($scaled), '0'), '.');
}

function stockQuantityValidateUnit(int $scaled, array $item): void
{
    if (inventoryIsSiteBased($item) && $scaled % STOCK_QUANTITY_SCALE !== 0) {
        throw new InvalidArgumentException('Items measured in sites require a whole-number quantity.');
    }
}
