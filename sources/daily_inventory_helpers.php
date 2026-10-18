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

/** True once sources/migrate_usage_wastage.php labelled inventory_usage_history. */
function dailyInventoryUsageLabelsReady(mysqli $conn): bool
{
    return workflowColumnExists($conn, 'inventory_usage_history', 'usage_type')
        && workflowColumnExists($conn, 'inventory_usage_history', 'usage_source');
}

/** Reasons a nurse can pick for a Consumed adjustment (recorded as wastage). */
function dailyInventoryWastageReasons(): array
{
    return ['Leakage', 'Broken vial/ampule', 'Expired/contaminated', 'Other'];
}

/**
 * Write ONE inventory_usage_history row: the single ledger that Daily Inventory
 * "Consumed" and the training_dataset check both read, so every use is counted
 * exactly once. $source is Assessment, Vaccination, Supplies or Daily Inventory;
 * $usageType is Usage (patient/clinic use) or Wastage. Labels are written only
 * after the migration; quantities are identical either way.
 */
function dailyInventoryInsertUsage(
    mysqli $conn,
    int $itemId,
    string $branchId,
    string $date,
    float $quantity,
    int $patientCount,
    string $source,
    ?int $vaccinationId,
    int $userId,
    string $usageType = 'Usage',
    string $reason = ''
): int {
    if (dailyInventoryUsageLabelsReady($conn)) {
        $stmt = $conn->prepare(
            "INSERT INTO inventory_usage_history
             (item_id, branch_id, usage_date, quantity_used, patient_count,
              usage_type, usage_source, source_vaccination_id, wastage_reason, recorded_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NOW())"
        );
        $stmt->bind_param('issdissisi', $itemId, $branchId, $date, $quantity, $patientCount,
            $usageType, $source, $vaccinationId, $reason, $userId);
    } else {
        if ($usageType === 'Wastage') {
            throw new RuntimeException('Wastage adjustments need the database update sources/migrate_usage_wastage.php. Ask the administrator to apply it.');
        }
        $stmt = $conn->prepare(
            'INSERT INTO inventory_usage_history (item_id, branch_id, usage_date, quantity_used, patient_count)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issdi', $itemId, $branchId, $date, $quantity, $patientCount);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save usage history: ' . $error);
    }
    $usageId = (int)$conn->insert_id;
    $stmt->close();
    return $usageId;
}

/**
 * Where the day's Consumed comes from (base unit, scaled ten-thousandths):
 * assessment, vaccination, supplies (Medical Supplies / earlier consumed
 * entries), older (unlabelled rows recorded before the migration) and wastage.
 * total is the same SUM(quantity_used) used by the closing and training sync.
 */
function dailyInventoryUsageBreakdown(mysqli $conn, string $branchId, int $itemId, string $date): array
{
    $parts = ['assessment' => 0, 'vaccination' => 0, 'supplies' => 0, 'older' => 0, 'wastage' => 0, 'total' => 0];
    $wastage = [];
    if (dailyInventoryUsageLabelsReady($conn)) {
        $stmt = $conn->prepare(
            "SELECT usage_type, usage_source, wastage_reason, quantity_used
             FROM inventory_usage_history WHERE branch_id = ? AND item_id = ? AND usage_date = ?"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT 'Usage' AS usage_type, NULL AS usage_source, NULL AS wastage_reason, quantity_used
             FROM inventory_usage_history WHERE branch_id = ? AND item_id = ? AND usage_date = ?"
        );
    }
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $quantity = stockQuantityParse((string)$row['quantity_used'], true);
        $parts['total'] += $quantity;
        if ($row['usage_type'] === 'Wastage') {
            $parts['wastage'] += $quantity;
            $wastage[] = ['quantity' => $quantity, 'reason' => (string)($row['wastage_reason'] ?? '')];
            continue;
        }
        $key = match ((string)$row['usage_source']) {
            'Assessment' => 'assessment',
            'Vaccination' => 'vaccination',
            'Supplies', 'Daily Inventory' => 'supplies',
            default => 'older',
        };
        $parts[$key] += $quantity;
    }
    $stmt->close();
    $parts['wastage_entries'] = $wastage;
    return $parts;
}

/** Short text such as "Assessment 1 site + Vaccination 4 sites + Medical Supplies 2 sites". */
function dailyInventoryBreakdownText(array $breakdown, array $item): string
{
    $labels = ['assessment' => 'Assessment', 'vaccination' => 'Vaccination', 'supplies' => 'Medical Supplies',
        'older' => 'Earlier records', 'wastage' => 'Wastage'];
    $parts = [];
    foreach ($labels as $key => $label) {
        if (($breakdown[$key] ?? 0) > 0) {
            $parts[] = $label . ' ' . inventoryQuantityText((float)stockQuantityDecimal($breakdown[$key]), $item);
        }
    }
    return $parts ? implode(' + ', $parts) : 'No usage recorded for this date';
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
    $breakdown = dailyInventoryUsageBreakdown($conn, $branchId, $itemId, $date);
    // A closing whose Consumed no longer matches the recorded usage is out of date.
    $outOfDate = $closing !== null && ($closing['status'] === 'Draft'
        || stockQuantityParse((string)$closing['consumed'], true) !== $consumedScaled);
    return [
        'success' => true,
        'inventory_date' => $date,
        'consumed' => $consumed,
        'consumed_breakdown' => dailyInventoryBreakdownText($breakdown, $item),
        'consumed_wastage' => stockQuantityDecimal($breakdown['wastage']),
        'consumed_auto' => stockQuantityDecimal($consumedScaled - $breakdown['wastage']),
        'closing_out_of_date' => $outOfDate,
        'closing_saved_consumed' => $closing !== null ? stockQuantityDecimal(stockQuantityParse((string)$closing['consumed'], true)) : null,
        'closing_saved_actual' => $closing !== null ? (string)$closing['actual_count'] : null,
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
 * Record consumption that has no vaccination record (Medical Supplies usage)
 * or a Daily Inventory wastage adjustment: FEFO stock deduction, ONE
 * inventory_usage_history row, a stock_transactions OUT and the training
 * re-open. Daily Inventory "Consumed" is the day's recorded usage, so this
 * keeps the closing and training_dataset consistent (wastage is real supply
 * consumption and is included once). Wastage is labelled usage_type='Wastage'
 * and its stock transaction starts with "Reason: Lost / Wastage", the same
 * reason the Stock Out page uses. "Expired/contaminated" may take expired
 * batches first. The caller owns the transaction and already holds the branch
 * revision lock (forecastTrainingRevision). Returns usage_id, description, reopened.
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
    int $patientId = 0,
    string $source = 'Supplies',
    string $usageType = 'Usage',
    string $wastageReason = ''
): array {
    $itemId = (int)$item['item_id'];
    if ($quantity <= 0 || !is_finite($quantity)) {
        throw new RuntimeException('Quantity used must be greater than zero.');
    }
    if (inventoryIsSiteBased($item) && abs($quantity - round($quantity)) > 0.00001) {
        throw new RuntimeException('Site-based consumption must be entered in whole sites.');
    }
    // Expired/contaminated wastage may remove expired batches (oldest first).
    $includeExpired = $usageType === 'Wastage' && $wastageReason === 'Expired/contaminated';
    $stock = $conn->prepare(
        "SELECT stock_id,batch_lot_no,quantity_available,expiration_date
         FROM inventory_stocks
         WHERE item_id=? AND branch_id=? AND quantity_available>0
           AND (? = 1 OR expiration_date IS NULL OR expiration_date>=CURDATE())
         ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
                  expiration_date,stock_id FOR UPDATE"
    );
    $expiredFlag = $includeExpired ? 1 : 0;
    $stock->bind_param('isi', $itemId, $branchId, $expiredFlag);
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
    $usageId = dailyInventoryInsertUsage($conn, $itemId, $branchId, $date, $quantity, $patientsServed,
        $source, null, $userId, $usageType, $wastageReason);
    $description = inventoryUsageDescription($quantity, $item);
    if ($usageType === 'Wastage') {
        $movementPrefix = 'Reason: Lost / Wastage | Wastage: ' . $wastageReason . ' | ' . $movementPrefix;
    }
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
 * Validate the posted values of one closing row (no database access).
 * $values holds beginning_stock, delivery, pull_out and actual_count as posted
 * base-unit strings. Consumed is never typed: it is the day's recorded usage
 * (Assessment + Vaccination + Medical Supplies). The only way to adjust it is
 * the "Edit / Adjust" wastage entry: $wastageQuantity (base unit) with a
 * reason from dailyInventoryWastageReasons() ($wastageOther when "Other").
 */
function dailyInventoryParseClosingInput(
    array $values,
    string $remarks,
    string $contextToken,
    string $wastageQuantity = '',
    string $wastageReason = '',
    string $wastageOther = ''
): array {
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
    $wastageQuantity = trim($wastageQuantity);
    $wastageReason = trim($wastageReason);
    $wastageOther = trim($wastageOther);
    $wastageLabel = '';
    if ($wastageQuantity !== '') {
        try {
            $extraScaled = stockQuantityParse($wastageQuantity, true);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Consumed adjustment: ' . $e->getMessage());
        }
    }
    if ($extraScaled > 0) {
        if (!in_array($wastageReason, dailyInventoryWastageReasons(), true)) {
            throw new RuntimeException('Pick a reason for the Consumed adjustment: Leakage, Broken vial/ampule, Expired/contaminated or Other.');
        }
        if ($wastageReason === 'Other' && $wastageOther === '') {
            throw new RuntimeException('Specify the reason for the Consumed adjustment ("Other").');
        }
        if (strlen($wastageOther) > 200) {
            throw new RuntimeException('The adjustment reason must not exceed 200 characters.');
        }
        $wastageLabel = $wastageReason === 'Other' ? 'Other: ' . $wastageOther : $wastageReason;
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/', $contextToken)) {
        throw new RuntimeException('Load the selected item and date before submitting the closing.');
    }
    return [
        'quantities' => $quantities,
        'remarks' => $remarks,
        'context_token' => $contextToken,
        'extra_scaled' => $extraScaled,
        'wastage_reason' => $wastageReason,
        'wastage_label' => $wastageLabel,
    ];
}

/**
 * The checks a closing must pass against the current context (computed ending
 * not negative, a remark for any variance, the form still matching the
 * recorded usage). Returns the first problem, or null. $extraScaled is the
 * wastage about to be added to Consumed.
 */
function dailyInventoryClosingProblem(array $context, array $parsed): ?string
{
    $item = $context['item'];
    if (!hash_equals($context['context_token'], $parsed['context_token'])) {
        return $item['item_name'] . ': usage or the saved closing changed after the page was loaded. The page shows the new Consumed; verify it and enter a fresh physical count.';
    }
    $quantities = $parsed['quantities'];
    $consumedScaled = stockQuantityParse($context['consumed'], true) + $parsed['extra_scaled'];
    $computedScaled = $quantities['beginning_stock'] + $quantities['delivery'] - $consumedScaled - $quantities['pull_out'];
    if ($computedScaled < 0) {
        return $item['item_name'] . ': computed ending cannot be negative. Check beginning stock, delivery, consumed, and pull-out.';
    }
    if ($computedScaled > STOCK_QUANTITY_MAX_SCALED) {
        return $item['item_name'] . ': the resulting stock quantity exceeds the supported range.';
    }
    if ($quantities['actual_count'] !== $computedScaled && $parsed['remarks'] === '') {
        return $item['item_name'] . ': explain the difference between physical and computed stock in Remarks.';
    }
    return null;
}

/**
 * Write one closing: optional wastage (stock deducted once, labelled Wastage,
 * then part of Consumed), the daily_inventory_closings row, the training_dataset
 * sync and the audit entry. The caller owns the transaction and already holds
 * the branch revision lock (forecastTrainingRevision).
 */
function dailyInventoryWriteClosing(
    mysqli $conn,
    string $branchId,
    int $userId,
    int $itemId,
    string $inventoryDate,
    array $parsed
): array {
    $context = dailyInventoryContext($conn, $branchId, $itemId, $inventoryDate, true);
    if (!hash_equals($context['context_token'], $parsed['context_token'])) {
        throw new DailyInventoryStaleContextException('Recorded usage or a closing changed. Reload the selected item and date, verify the opening and movements, and enter a fresh physical count.');
    }
    $problem = dailyInventoryClosingProblem($context, $parsed);
    if ($problem !== null) {
        throw new RuntimeException($problem);
    }
    $quantities = $parsed['quantities'];
    $remarks = $parsed['remarks'];
    $wastageLabel = $parsed['wastage_label'];
    $manualUsage = null;
    if ($parsed['extra_scaled'] > 0) {
        $manualUsage = dailyInventoryRecordUsage(
            $conn, $userId, $branchId, $context['item'], (float)stockQuantityDecimal($parsed['extra_scaled']),
            $inventoryDate, 'Daily inventory adjustment | ', $wastageLabel, 0,
            'Daily Inventory', 'Wastage', $wastageLabel
        );
        // Consumed now includes the wastage just recorded (counted once).
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
        $remarks = trim($remarks . ($remarks !== '' ? ' | ' : '') . 'Wastage: '
            . $manualUsage['description'] . ' - ' . $wastageLabel);
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
            . ($manualUsage !== null ? ' (incl. wastage ' . $manualUsage['description'] . ' - ' . $wastageLabel . ')' : '')
            . ' | Pull-out: ' . inventoryUsageDescription((float)$pullOut, $item)
            . ' | Actual: ' . inventoryUsageDescription((float)$actual, $item),
        'Daily Inventory'
    );
    return ['item' => $item, 'training_synced' => $trainingSynced, 'manual_usage' => $manualUsage];
}

/**
 * Save one daily closing (the single-item correction form). Same validation,
 * daily_inventory_closings write and training_dataset sync as the whole-day
 * closing; everything runs in one transaction under the branch revision lock.
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
    string $wastageQuantity = '',
    string $wastageReason = '',
    string $wastageOther = ''
): array {
    dailyInventoryAssertDate($conn, $inventoryDate);
    $parsed = dailyInventoryParseClosingInput($values, $remarks, $contextToken, $wastageQuantity, $wastageReason, $wastageOther);
    $conn->begin_transaction();
    try {
        forecastTrainingRevision($conn, $branchId, true);
        $result = dailyInventoryWriteClosing($conn, $branchId, $userId, $itemId, $inventoryDate, $parsed);
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Delivery and pull-out recorded in stock_transactions for each item on $date
 * (base unit, scaled ten-thousandths), used to pre-fill the closing:
 *  - Delivery: stock received (IN, TRANSFER_IN, RETURN).
 *  - Pull-out: stock that left the branch without a usage record (Stock Out,
 *    return shipments, expired disposal, TRANSFER_OUT, EXPIRED). Usage
 *    movements (vaccination, assessment, Medical Supplies and wastage) carry a
 *    vaccination_id or a "Usage ID:" and are already in Consumed, so they are
 *    excluded here and never counted twice.
 */
function dailyInventoryDayMovements(mysqli $conn, string $branchId, string $date): array
{
    $stmt = $conn->prepare(
        "SELECT item_id,
                SUM(CASE WHEN transaction_type IN ('IN','TRANSFER_IN','RETURN') THEN quantity ELSE 0 END) AS delivery,
                SUM(CASE WHEN transaction_type IN ('TRANSFER_OUT','EXPIRED')
                          OR (transaction_type = 'OUT' AND vaccination_id IS NULL
                              AND COALESCE(remarks, '') NOT LIKE '%Usage ID: %')
                         THEN quantity ELSE 0 END) AS pull_out
         FROM stock_transactions
         WHERE branch_id = ? AND transaction_date >= ? AND transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
         GROUP BY item_id"
    );
    $stmt->bind_param('sss', $branchId, $date, $date);
    $stmt->execute();
    $movements = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $movements[(int)$row['item_id']] = [
            'delivery' => (int)round(abs((float)$row['delivery']) * STOCK_QUANTITY_SCALE),
            'pull_out' => (int)round(abs((float)$row['pull_out']) * STOCK_QUANTITY_SCALE),
        ];
    }
    $stmt->close();
    return $movements;
}

/**
 * Every consumable item for the whole-day closing of $date, in the clinic
 * sheet order: its context (Beginning, Consumed with the breakdown by source,
 * saved closing), Delivery and Pull-out pre-filled from that day's stock
 * movements when no closing is saved yet, and whether it had activity that
 * day (usage, movements or a saved closing). Items with activity are listed
 * first; the rest are shown with "Show all items".
 */
function dailyInventoryDayRows(mysqli $conn, string $branchId, string $date): array
{
    $stmt = $conn->prepare('SELECT item_id, item_name FROM inventory_items WHERE is_consumable = 1');
    $stmt->execute();
    $items = dailyInventorySortItems($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    $movements = dailyInventoryDayMovements($conn, $branchId, $date);
    $rows = [];
    foreach ($items as $listed) {
        $itemId = (int)$listed['item_id'];
        try {
            $context = dailyInventoryContext($conn, $branchId, $itemId, $date);
        } catch (RuntimeException $e) {
            continue;
        }
        $hasClosing = $context['opening_source'] === 'saved_closing';
        $moved = $movements[$itemId] ?? ['delivery' => 0, 'pull_out' => 0];
        $prefilled = ['delivery' => false, 'pull_out' => false];
        if (!$hasClosing) {
            foreach (['delivery', 'pull_out'] as $field) {
                if ($moved[$field] > 0) {
                    $context['fields'][$field] = stockQuantityDecimal($moved[$field]);
                    $prefilled[$field] = true;
                }
            }
        }
        $consumedScaled = stockQuantityParse($context['consumed'], true);
        $rows[] = [
            'item' => $context['item'],
            'context' => $context,
            'used' => $consumedScaled > 0,
            'active' => $consumedScaled > 0 || $hasClosing || $moved['delivery'] > 0 || $moved['pull_out'] > 0,
            'saved' => $hasClosing && !$context['needs_physical_count'],
            'prefilled' => $prefilled,
            'movements' => $moved,
        ];
    }
    usort($rows, static fn(array $a, array $b): int => (int)$b['active'] <=> (int)$a['active']);
    return $rows;
}

/** Thrown by dailyInventorySaveDay with one message per item that needs attention. */
class DailyInventoryDayException extends RuntimeException
{
    public array $errors;
    public array $staleItems;

    public function __construct(array $errors, array $staleItems = [])
    {
        $this->errors = $errors;
        $this->staleItems = $staleItems;
        parent::__construct(implode(' | ', $errors));
    }
}

/**
 * Save the whole day's closing at once (the "Save Closing" button). $rows is
 * keyed by item_id with the posted beginning_stock, delivery, pull_out,
 * actual_count, remarks, context_token and optional wastage_* fields.
 * - A row with usage that day must have an Actual count; other rows are saved
 *   only when an Actual count is entered.
 * - A saved closing posted back unchanged is skipped (no duplicate audit).
 * - Every row is checked first and ALL problems are reported together. Then
 *   all rows are written in ONE transaction under the branch revision lock,
 *   with the same closing and training_dataset logic as a single item; if any
 *   row fails, nothing is saved.
 * Returns ['saved' => [item names], 'unchanged' => int, 'synced' => int].
 */
function dailyInventorySaveDay(mysqli $conn, string $branchId, int $userId, string $date, array $rows): array
{
    dailyInventoryAssertDate($conn, $date);
    $errors = [];
    $parsedRows = [];
    $unchanged = 0;
    foreach ($rows as $rawItemId => $row) {
        if (!is_array($row)) continue;
        $text = static fn(string $key): string => is_string($row[$key] ?? null) ? trim($row[$key]) : '';
        $name = $text('item_name');
        try {
            $itemId = dailyInventoryItemId((string)$rawItemId);
        } catch (InvalidArgumentException $e) {
            continue;
        }
        if ($text('actual_count') === '') {
            if ($text('wastage_quantity') !== '') {
                $errors[$itemId] = ($name !== '' ? $name . ': ' : '') . 'enter the Actual count to save the Consumed adjustment.';
            }
            continue; // Required rows are checked below against the current usage.
        }
        try {
            $parsedRows[$itemId] = dailyInventoryParseClosingInput(
                [
                    'beginning_stock' => $text('beginning_stock'),
                    'delivery' => $text('delivery') === '' ? '0' : $text('delivery'),
                    'pull_out' => $text('pull_out') === '' ? '0' : $text('pull_out'),
                    'actual_count' => $text('actual_count'),
                ],
                $text('remarks'),
                $text('context_token'),
                $text('wastage_quantity'),
                $text('wastage_reason'),
                $text('wastage_other')
            );
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $errors[$itemId] = ($name !== '' && !str_starts_with($message, $name) ? $name . ': ' : '') . $message;
        }
    }

    $conn->begin_transaction();
    try {
        forecastTrainingRevision($conn, $branchId, true);
        $stale = [];
        // Every item used that day needs a count before the day can be saved.
        foreach (dailyInventoryDayRows($conn, $branchId, $date) as $dayRow) {
            $itemId = (int)$dayRow['item']['item_id'];
            if ($dayRow['used'] && !$dayRow['saved'] && !isset($parsedRows[$itemId]) && !isset($errors[$itemId])) {
                $errors[$itemId] = $dayRow['item']['item_name'] . ': enter the Actual physical count (this item was used today).';
            }
        }
        $toWrite = [];
        foreach ($parsedRows as $itemId => $parsed) {
            $context = dailyInventoryContext($conn, $branchId, $itemId, $date, true);
            if (!hash_equals($context['context_token'], $parsed['context_token'])) {
                $stale[] = $itemId;
            }
            $problem = dailyInventoryClosingProblem($context, $parsed);
            if ($problem !== null) {
                $errors[$itemId] = $problem;
                continue;
            }
            // A saved, current closing posted back unchanged needs no new write.
            if ($context['opening_source'] === 'saved_closing' && !$context['needs_physical_count']
                && $parsed['extra_scaled'] === 0 && $parsed['remarks'] === trim((string)$context['fields']['remarks'])) {
                $same = true;
                foreach (['beginning_stock', 'delivery', 'pull_out', 'actual_count'] as $field) {
                    if ($parsed['quantities'][$field] !== stockQuantityParse((string)$context['fields'][$field], true)) {
                        $same = false;
                    }
                }
                if ($same) {
                    $unchanged++;
                    continue;
                }
            }
            $toWrite[$itemId] = $parsed;
        }
        if ($errors) {
            throw new DailyInventoryDayException(array_values($errors), $stale);
        }
        if (!$toWrite) {
            throw new DailyInventoryDayException(['Nothing new to save. Enter or change the Actual count of at least one item.']);
        }
        $saved = [];
        $synced = 0;
        foreach ($toWrite as $itemId => $parsed) {
            $result = dailyInventoryWriteClosing($conn, $branchId, $userId, $itemId, $date, $parsed);
            $saved[] = (string)$result['item']['item_name'];
            $synced += $result['training_synced'] ? 1 : 0;
        }
        $conn->commit();
        return ['saved' => $saved, 'unchanged' => $unchanged, 'synced' => $synced];
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
