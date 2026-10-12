<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_stock_helpers.php';
require_once __DIR__ . '/stock_quantity_helpers.php';

function dailyInventoryItemId($value): int
{
    if (!is_string($value) || !preg_match('/\A[0-9]{1,10}\z/', $value)
        || (int)$value < 1 || (int)$value > 2147483647) {
        throw new InvalidArgumentException('Select a valid consumable item.');
    }
    return (int)$value;
}

function dailyInventoryAssertDate(mysqli $conn, string $date): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Select a valid inventory date.');
    }
    $today = (string)$conn->query('SELECT CURDATE() AS today')->fetch_assoc()['today'];
    if ($date > $today) {
        throw new InvalidArgumentException('Actual inventory cannot be submitted for a future date.');
    }
}

/**
 * Current stock is a reference, never an opening balance. A saved closing is
 * loaded for correction; otherwise only the immediately preceding day's
 * submitted/reviewed physical count is suggested. Unknown openings stay blank.
 * A caller saving a closing holds the existing branch revision lock first.
 */
function dailyInventoryContext(
    mysqli $conn,
    string $branchId,
    int $itemId,
    string $date,
    bool $lock = false
): array {
    $stockSql = inventoryStockTotalsSql();
    $stmt = $conn->prepare(
        "SELECT i.item_id,i.item_name,u.unit_name,
                COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label,
                COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
                COALESCE(s.usable_stock,0) AS current_stock
         FROM inventory_items i
         JOIN units u ON u.unit_id=i.unit_id
         LEFT JOIN ($stockSql) s ON s.item_id=i.item_id AND s.branch_id=?
         WHERE i.item_id=? AND i.is_consumable=1 LIMIT 1"
    );
    $stmt->bind_param('si', $branchId, $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$item) {
        throw new RuntimeException('Consumable item not found.');
    }
    $previousDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date)->modify('-1 day')->format('Y-m-d');
    $stmt = $conn->prepare(
        'SELECT closing_id,inventory_date,beginning_stock,delivery,consumed,pull_out,
                computed_ending,actual_count,variance,remarks,status,submitted_by,submitted_at,
                reviewed_by,reviewed_at
         FROM daily_inventory_closings
         WHERE branch_id=? AND item_id=? AND inventory_date IN (?,?)'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('siss', $branchId, $itemId, $date, $previousDate);
    $stmt->execute();
    $closing = null;
    $previous = null;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if ($row['inventory_date'] === $date) {
            $closing = $row;
        } else {
            $previous = $row;
        }
    }
    $stmt->close();
    $stmt = $conn->prepare(
        'SELECT usage_date, SUM(quantity_used) AS consumed
         FROM inventory_usage_history WHERE item_id=? AND branch_id=? AND usage_date IN (?,?)
         GROUP BY usage_date'
    );
    $stmt->bind_param('isss', $itemId, $branchId, $date, $previousDate);
    $stmt->execute();
    $consumedScaled = 0;
    $previousConsumedScaled = 0;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $usage) {
        $quantity = stockQuantityParse((string)$usage['consumed'], true);
        if ($usage['usage_date'] === $date) {
            $consumedScaled = $quantity;
        } else {
            $previousConsumedScaled = $quantity;
        }
    }
    $stmt->close();
    $consumed = stockQuantityDecimal($consumedScaled);
    $needsCount = $closing !== null && ($closing['status'] === 'Draft'
        || stockQuantityParse((string)$closing['consumed'], true) !== $consumedScaled);
    $source = 'manual';
    $beginning = '';
    if ($closing !== null) {
        $source = 'saved_closing';
        $beginning = (string)$closing['beginning_stock'];
        $message = 'Saved closing loaded for ' . $date . '. Verify the opening, movements and physical count before correcting it.';
        if ($needsCount) {
            $message .= ' Usage changed or the closing requires resubmission. Enter a fresh physical count.';
        }
    } elseif ($previous !== null && in_array($previous['status'], ['Submitted', 'Reviewed'], true)
        && stockQuantityParse((string)$previous['consumed'], true) === $previousConsumedScaled) {
        $source = 'previous_day';
        $beginning = (string)$previous['actual_count'];
        $message = 'Suggested beginning: the physical count recorded on ' . $previousDate
            . '. Confirm it against the opening count, including any stock that expired overnight or movements after that count.';
    } else {
        $message = 'No current submitted closing is available for the previous day. Enter the verified opening count for ' . $date
            . '. Current remaining stock is not the opening balance.';
    }
    // A snapshot fingerprint detects usage changes and overlapping corrections.
    // Authorization and quantity validation remain entirely server-side.
    $token = hash('sha256', json_encode([$branchId, $itemId, $date, $consumed, $closing, $previous, $previousConsumedScaled], JSON_THROW_ON_ERROR));
    return [
        'success' => true,
        'inventory_date' => $date,
        'consumed' => $consumed,
        'base_unit' => inventoryBaseUnitLabel($item),
        'display' => inventoryUsageDescription((float)$consumed, $item),
        'current_stock' => (string)$item['current_stock'],
        'current_stock_display' => inventoryStockBreakdown((float)$item['current_stock'], $item),
        'opening_source' => $source,
        'opening_message' => $message,
        'needs_physical_count' => $needsCount,
        'context_token' => $token,
        'fields' => [
            'beginning_stock' => $beginning,
            'delivery' => $closing !== null ? (string)$closing['delivery'] : '0',
            'pull_out' => $closing !== null ? (string)$closing['pull_out'] : '0',
            'actual_count' => $closing !== null && !$needsCount ? (string)$closing['actual_count'] : '',
            'remarks' => $closing !== null ? (string)($closing['remarks'] ?? '') : ''
        ],
        'item' => $item
    ];
}
