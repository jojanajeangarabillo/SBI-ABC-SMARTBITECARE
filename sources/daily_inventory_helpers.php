<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_stock_helpers.php';
require_once __DIR__ . '/stock_quantity_helpers.php';
require_once __DIR__ . '/inventory_unit_helpers.php';
require_once __DIR__ . '/forecast_training_helper.php';
require_once __DIR__ . '/workflow_helpers.php';

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
 * loaded for correction. Otherwise the clinic rule fills Beginning (Inv.
 * Beginning) from the previous day: its Actual count, or, when that day has no
 * current actual count (closing reopened because usage changed), its computed
 * Inv. End = Beginning + Delivery - Consumed - Pull-out. The value stays
 * editable and opening_source/opening_message say where it came from. With no
 * closing for the previous day, the opening stays blank.
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
        $message = 'Beginning filled from the Actual count of ' . $previousDate
            . '. You can edit it; confirm it against the opening count, including any stock that expired overnight.';
    } elseif ($previous !== null) {
        // No current actual count for the previous day: use its computed Inv. End
        // with the usage recorded for that day now.
        $previousEnding = stockQuantityParse((string)$previous['beginning_stock'], true)
            + stockQuantityParse((string)$previous['delivery'], true)
            - $previousConsumedScaled
            - stockQuantityParse((string)$previous['pull_out'], true);
        if ($previousEnding >= 0) {
            $source = 'previous_computed';
            $beginning = stockQuantityDecimal($previousEnding);
            $message = 'Beginning filled from the computed Inv. End of ' . $previousDate
                . ' (that day has no current actual count). You can edit it; verify it against the opening count.';
        } else {
            $message = 'The computed Inv. End of ' . $previousDate . ' is negative. Enter the verified opening count for ' . $date . '.';
        }
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

/**
 * Record consumption that has no vaccination record (for example medicines
 * dispensed or sold): FEFO stock deduction, inventory_usage_history,
 * stock_transactions and audit. Daily Inventory "Consumed" is the day's
 * recorded usage, so this keeps the closing and training_dataset consistent.
 * The caller owns the transaction and already holds the branch revision lock
 * (forecastTrainingRevision). Returns usage_id, description and reopened.
 */
function dailyInventoryRecordUsage(
    mysqli $conn,
    int $userId,
    string $branchId,
    array $item,
    float $quantity,
    string $date,
    string $movementPrefix,
    string $remarks,
    int $patientId = 0
): array {
    $itemId = (int)$item['item_id'];
    if ($quantity <= 0 || !is_finite($quantity)) {
        throw new RuntimeException('Quantity used must be greater than zero.');
    }
    if (inventoryIsSiteBased($item) && abs($quantity - round($quantity)) > 0.00001) {
        throw new RuntimeException('Site-based consumption must be entered in whole sites.');
    }
    $stock = $conn->prepare(
        "SELECT stock_id,batch_lot_no,quantity_available,expiration_date
         FROM inventory_stocks
         WHERE item_id=? AND branch_id=? AND quantity_available>0
           AND (expiration_date IS NULL OR expiration_date>=CURDATE())
         ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
                  expiration_date,stock_id FOR UPDATE"
    );
    $stock->bind_param('is', $itemId, $branchId);
    $stock->execute();
    $batches = $stock->get_result()->fetch_all(MYSQLI_ASSOC);
    $stock->close();
    $available = round(array_sum(array_map(fn($batch) => (float)$batch['quantity_available'], $batches)), 4);
    if ($available + 0.00005 < $quantity) {
        throw new RuntimeException('Insufficient usable stock for ' . $item['item_name'] . '. Available: '
            . inventoryUsageDescription($available, $item) . '.');
    }
    $remaining = $quantity;
    $batchDetails = [];
    foreach ($batches as $batch) {
        if ($remaining <= 0.00005) break;
        $take = round(min((float)$batch['quantity_available'], $remaining), 4);
        $stockId = (int)$batch['stock_id'];
        $update = $conn->prepare(
            'UPDATE inventory_stocks SET quantity_available=quantity_available-?,
             last_updated=CURRENT_TIMESTAMP WHERE stock_id=? AND item_id=? AND branch_id=?
             AND quantity_available>=?'
        );
        $update->bind_param('diisd', $take, $stockId, $itemId, $branchId, $take);
        $update->execute();
        if ($update->affected_rows !== 1) throw new RuntimeException('Stock changed. Refresh and try again.');
        $update->close();
        $batchDetails[] = 'Stock #' . $stockId . ' / Lot '
            . (trim((string)$batch['batch_lot_no']) ?: 'N/A') . ': '
            . inventoryUsageDescription($take, $item);
        $remaining = round($remaining - $take, 4);
    }
    if ($remaining > 0.00005) throw new RuntimeException('Unable to allocate the complete supply quantity.');

    $patientsServed = $patientId > 0 ? 1 : 0;
    $usage = $conn->prepare(
        'INSERT INTO inventory_usage_history
         (item_id,branch_id,usage_date,quantity_used,patient_count) VALUES (?,?,?,?,?)'
    );
    $usage->bind_param('issdi', $itemId, $branchId, $date, $quantity, $patientsServed);
    $usage->execute();
    $usageId = (int)$conn->insert_id;
    $usage->close();
    $description = inventoryUsageDescription($quantity, $item);
    $movementRemarks = $movementPrefix . 'Usage ID: ' . $usageId
        . ' | Used: ' . $description . ' | Usage date: ' . $date
        . ($patientId > 0 ? ' | Patient ID: ' . $patientId : '')
        . ' | Batches: ' . implode('; ', $batchDetails)
        . ($remarks !== '' ? ' | Note: ' . $remarks : '');
    $movement = $conn->prepare(
        "INSERT INTO stock_transactions
         (item_id,user_id,vaccination_id,branch_id,transaction_type,quantity,remarks,transaction_date)
         VALUES (?,?,NULL,?,'OUT',?,?,CONCAT(?, ' ', TIME(NOW())))"
    );
    $movement->bind_param('iisdss', $itemId, $userId, $branchId, $quantity, $movementRemarks, $date);
    $movement->execute();
    $movement->close();
    $reopened = forecastTrainingUsageChanged($conn, $branchId, $itemId, $date);
    $staleReason = 'Actual medical-supply usage changed for ' . $date . '.';
    $stale = $conn->prepare('UPDATE forecast_results SET stale_reason=? WHERE branch_id=?');
    $stale->bind_param('ss', $staleReason, $branchId);
    $stale->execute();
    $stale->close();
    return ['usage_id' => $usageId, 'description' => $description, 'reopened' => $reopened];
}

/**
 * Save one daily closing (single-item form and whole-day sheet share it).
 * $values holds beginning_stock, delivery, pull_out and actual_count as posted
 * strings. $extraConsumed (optional, base unit) is usage with no vaccination
 * record; it is recorded as real usage first and then included in Consumed.
 * Everything runs in one transaction under the branch revision lock.
 */
function dailyInventorySaveClosing(
    mysqli $conn,
    string $branchId,
    int $userId,
    int $itemId,
    string $inventoryDate,
    array $values,
    string $remarks,
    string $contextToken,
    string $extraConsumed = '',
    string $extraConsumedRemarks = ''
): array {
    dailyInventoryAssertDate($conn, $inventoryDate);
    $quantities = [];
    foreach (['beginning_stock' => 'Beginning stock', 'delivery' => 'Delivery',
              'pull_out' => 'Pull-out', 'actual_count' => 'Actual physical count'] as $field => $label) {
        try {
            $quantities[$field] = stockQuantityParse((string)($values[$field] ?? ''), true);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($label . ': ' . $e->getMessage());
        }
    }
    $remarks = trim($remarks);
    if (strlen($remarks) > 2000) {
        throw new RuntimeException('The remarks are too long. Keep the note within 500 characters.');
    }
    $extraScaled = 0;
    $extraConsumed = trim($extraConsumed);
    $extraConsumedRemarks = trim($extraConsumedRemarks);
    if ($extraConsumed !== '' && $extraConsumed !== '0') {
        try {
            $extraScaled = stockQuantityParse($extraConsumed, true);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Additional consumed: ' . $e->getMessage());
        }
        if ($extraScaled > 0 && $extraConsumedRemarks === '') {
            throw new RuntimeException('Add a remark for the consumed quantity entered without a vaccination record (for example "Mefenamic dispensed").');
        }
        if (strlen($extraConsumedRemarks) > 500) {
            throw new RuntimeException('The consumed remark must not exceed 500 characters.');
        }
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/', $contextToken)) {
        throw new RuntimeException('Load the selected item and date before submitting the closing.');
    }

    $conn->begin_transaction();
    try {
        forecastTrainingRevision($conn, $branchId, true);
        $context = dailyInventoryContext($conn, $branchId, $itemId, $inventoryDate, true);
        if (!hash_equals($context['context_token'], $contextToken)) {
            throw new DailyInventoryStaleContextException('Recorded usage or a closing changed. Reload the selected item and date, verify the opening and movements, and enter a fresh physical count.');
        }
        $manualUsage = null;
        if ($extraScaled > 0) {
            $manualUsage = dailyInventoryRecordUsage(
                $conn, $userId, $branchId, $context['item'], (float)stockQuantityDecimal($extraScaled),
                $inventoryDate, 'Daily inventory consumed entry | ', $extraConsumedRemarks
            );
            // Consumed now includes the usage just recorded.
            $context = dailyInventoryContext($conn, $branchId, $itemId, $inventoryDate, true);
        }
        $item = $context['item'];
        $consumedScaled = stockQuantityParse($context['consumed'], true);
        $computedScaled = $quantities['beginning_stock'] + $quantities['delivery']
            - $consumedScaled - $quantities['pull_out'];
        if ($computedScaled < 0) {
            throw new RuntimeException($item['item_name'] . ': computed ending cannot be negative. Check beginning stock, delivery, consumed, and pull-out.');
        }
        if ($computedScaled > STOCK_QUANTITY_MAX_SCALED) {
            throw new RuntimeException('The resulting stock quantity exceeds the supported range.');
        }
        $varianceScaled = $quantities['actual_count'] - $computedScaled;
        if ($varianceScaled !== 0 && $remarks === '') {
            throw new RuntimeException($item['item_name'] . ': explain the difference between physical and computed stock in Remarks.');
        }
        if ($manualUsage !== null) {
            $remarks = trim($remarks . ($remarks !== '' ? ' | ' : '') . 'Consumed entry: '
                . $manualUsage['description'] . ' - ' . $extraConsumedRemarks);
        }
        // Counts may contain legacy fractions, including sites; never round them.
        $beginning = stockQuantityDecimal($quantities['beginning_stock']);
        $delivery = stockQuantityDecimal($quantities['delivery']);
        $pullOut = stockQuantityDecimal($quantities['pull_out']);
        $actual = stockQuantityDecimal($quantities['actual_count']);
        $consumed = stockQuantityDecimal($consumedScaled);
        $computed = stockQuantityDecimal($computedScaled);
        $variance = stockQuantityDecimal($varianceScaled);

        $stmt = $conn->prepare(
            "INSERT INTO daily_inventory_closings
             (branch_id, item_id, inventory_date, beginning_stock, delivery, consumed, pull_out,
              computed_ending, actual_count, variance, remarks, status, submitted_by, submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'Submitted',?,NOW())
             ON DUPLICATE KEY UPDATE
                beginning_stock=VALUES(beginning_stock),
                delivery=VALUES(delivery),
                consumed=VALUES(consumed),
                pull_out=VALUES(pull_out),
                computed_ending=VALUES(computed_ending),
                actual_count=VALUES(actual_count),
                variance=VALUES(variance),
                remarks=VALUES(remarks),
                status='Submitted',
                submitted_by=VALUES(submitted_by),
                submitted_at=NOW(),
                reviewed_by=NULL,
                reviewed_at=NULL"
        );
        $stmt->bind_param('sisssssssssi', $branchId, $itemId, $inventoryDate, $beginning, $delivery,
            $consumed, $pullOut, $computed, $actual, $variance, $remarks, $userId);
        $stmt->execute();
        $stmt->close();

        $trainingSynced = forecastTrainingSyncClosing($conn, $branchId, $itemId, $inventoryDate);

        workflowAudit(
            $conn,
            $userId,
            $branchId,
            'Submitted daily inventory for ' . $item['item_name'] . ' on ' . $inventoryDate
                . ' | Beginning: ' . inventoryUsageDescription((float)$beginning, $item)
                . ' (' . $context['opening_source'] . ')'
                . ' | Delivery: ' . inventoryUsageDescription((float)$delivery, $item)
                . ' | Consumed: ' . inventoryUsageDescription((float)$consumed, $item)
                . ($manualUsage !== null ? ' (incl. entered ' . $manualUsage['description'] . ')' : '')
                . ' | Pull-out: ' . inventoryUsageDescription((float)$pullOut, $item)
                . ' | Actual: ' . inventoryUsageDescription((float)$actual, $item),
            'Daily Inventory'
        );
        $conn->commit();
        return ['item' => $item, 'training_synced' => $trainingSynced, 'manual_usage' => $manualUsage];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Thrown when usage or a closing changed after the form was loaded. */
class DailyInventoryStaleContextException extends RuntimeException
{
}

/**
 * Order of the clinic's paper daily inventory sheet (item names normalized
 * to upper case without extra spaces). Items not on the sheet follow,
 * alphabetically.
 */
function dailyInventorySheetOrder(): array
{
    return [
        'SPEEDA', 'ABHAYRAB', 'VAXIRAB', 'CHIRORAB',
        'ERIG CONN', 'ERIG IMP.', 'ERIG (EQUINE RABIES IMMUNOGLOBULIN)', 'ERIG', 'HRIG',
        'ATS 1,500 "IU"', 'ATS 3000 "IU"', 'ATS 5,000 "IU"', 'ATS (ANTI-TETANUS SERUM)', 'HTIG',
        'TOXOID BETT', 'TOXOID ABHAYTOX', 'TT (TETANUS TOXOID)',
        'PPD', 'HEPA B', 'HEPA BIG', 'FLU',
        'AMOXICILLIN 500MG', 'CEFALEXIN 500 MG', 'MEFENAMIC 500 MG',
        'INSULIN', '1CC/3CC', '5CC/10CC',
    ];
}

function dailyInventorySheetKey(string $itemName): string
{
    return strtoupper(preg_replace('/\s+/', ' ', trim($itemName)));
}

/** Sort item rows (with item_name) in the clinic sheet order. */
function dailyInventorySortItems(array $items): array
{
    $order = array_flip(dailyInventorySheetOrder());
    usort($items, static function (array $a, array $b) use ($order): int {
        $ka = dailyInventorySheetKey((string)$a['item_name']);
        $kb = dailyInventorySheetKey((string)$b['item_name']);
        $ra = $order[$ka] ?? PHP_INT_MAX;
        $rb = $order[$kb] ?? PHP_INT_MAX;
        return $ra <=> $rb ?: strcasecmp($ka, $kb);
    });
    return $items;
}
