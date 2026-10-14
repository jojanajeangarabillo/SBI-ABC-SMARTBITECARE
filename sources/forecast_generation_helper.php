<?php
declare(strict_types=1);

require_once __DIR__ . '/forecast_training_helper.php';
require_once __DIR__ . '/forecast_status_helper.php';
require_once __DIR__ . '/forecast_accuracy_helper.php';

/** Capture stock and configuration, locking rows only during capture/save. */
function forecastGenerationSnapshot(mysqli $conn, string $branchId): array
{
    $revision = forecastTrainingRevision($conn, $branchId, true);
    $today = (string)$conn->query('SELECT CURDATE() AS today')->fetch_assoc()['today'];
    // Lock catalog before stock, consistently in both short transactions.
    $items = $conn->query("SELECT i.item_id, i.item_name, i.minimum_stock, i.is_forecastable,
        i.unit_id, i.base_unit_label, i.display_unit_label, i.conversion_to_base,
        COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name, 'unit') AS accuracy_base_unit
        FROM inventory_items i LEFT JOIN units u ON u.unit_id=i.unit_id
        ORDER BY i.item_id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $stmt = forecastTrainingStatement($conn,
        'SELECT stock_id, item_id, branch_id, quantity_available, expiration_date,
                batch_lot_no, manufacturing_date, last_updated
         FROM inventory_stocks WHERE branch_id = ? ORDER BY stock_id FOR UPDATE');
    $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $stocks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $totalsSql = inventoryStockTotalsSql();
    $stmt = forecastTrainingStatement($conn,
        "SELECT item_id, usable_stock FROM ($totalsSql) totals WHERE branch_id = ? ORDER BY item_id");
    $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $totals = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $totals[(int)$row['item_id']] = (string)$row['usable_stock'];
    }
    $stmt->close();
    $catalog = [];
    foreach ($items as $item) $catalog[(int)$item['item_id']] = $item;
    return ['branch_id' => $branchId, 'revision' => $revision, 'database_today' => $today,
        'catalog' => $catalog, 'stock_totals' => $totals, 'policy' => forecastStatusPolicy(),
        'signature' => hash('sha256', json_encode([$today, $items, $stocks, $totalsSql,
            forecastStatusPolicy()], JSON_THROW_ON_ERROR))];
}

function forecastGenerationCapture(mysqli $conn, string $branchId): array
{
    $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction();
    try {
        $snapshot = forecastGenerationSnapshot($conn, $branchId);
        $conn->commit();
        return $snapshot;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function forecastGenerationNumber(array $row, string $key): float
{
    $value = $row[$key] ?? null;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) {
        throw new RuntimeException('The model returned an invalid ' . $key . '.');
    }
    return (float)$value;
}

/** Validate the complete model batch before deleting or replacing cache rows. */
function forecastGenerationValidate(array $result, array $snapshot, int $days): array
{
    if (($result['success'] ?? null) !== true || ($result['branch_id'] ?? null) !== $snapshot['branch_id']
        || ($result['forecast_days'] ?? null) !== $days || !is_array($result['forecasts'] ?? null)
        || !array_is_list($result['forecasts']) || !$result['forecasts']) {
        throw new RuntimeException('The model response does not match the selected branch and horizon.');
    }
    $today = new DateTimeImmutable($snapshot['database_today']);
    $start = $today->modify('+1 day')->format('Y-m-d');
    $end = $today->modify('+' . $days . ' days')->format('Y-m-d');
    $policy = $snapshot['policy'];
    $seen = [];
    $forecasts = [];
    foreach ($result['forecasts'] as $row) {
        if (!is_array($row) || !is_int($row['item_id'] ?? null) || $row['item_id'] <= 0) {
            throw new RuntimeException('The model returned an invalid item ID.');
        }
        $id = $row['item_id'];
        $item = $snapshot['catalog'][$id] ?? null;
        if (isset($seen[$id]) || !$item || !(bool)$item['is_forecastable']
            || !array_key_exists($id, $snapshot['stock_totals'])) {
            throw new RuntimeException('The model returned a duplicate or unavailable forecast item.');
        }
        $seen[$id] = true;
        if (($row['status_rule_version'] ?? '') !== $policy['version']
            || ($row['restocking_lead_days'] ?? null) !== $policy['restocking_lead_days']
            || ($row['safety_buffer_days'] ?? null) !== $policy['safety_buffer_days']
            || ($row['forecast_start'] ?? '') !== $start || ($row['forecast_end'] ?? '') !== $end
            || ($row['item_name'] ?? '') !== trim((string)$item['item_name'])) {
            throw new RuntimeException('Model dates, item settings or status rules changed. Generate a fresh forecast.');
        }
        $stock = forecastGenerationNumber($row, 'current_stock');
        $minimum = forecastGenerationNumber($row, 'minimum_stock');
        $demand = forecastGenerationNumber($row, 'forecasted_consumption');
        $probability = forecastGenerationNumber($row, 'shortage_probability');
        if ($probability > 1 || forecastStatusTicks($stock) !== forecastStatusTicks((float)$snapshot['stock_totals'][$id])
            || forecastStatusTicks($minimum) !== forecastStatusTicks((float)$item['minimum_stock'])) {
            throw new RuntimeException('The model used different stock or minimum settings. Generate a fresh forecast.');
        }
        if (!in_array($row['method'] ?? null, ['xgboost', 'recent_mean'], true)) {
            throw new RuntimeException('The model returned an unknown forecasting method. Install all forecasting files together.');
        }
        $daily = $row['daily_forecasts'] ?? null;
        if (!is_array($daily) || !array_is_list($daily) || count($daily) !== $days) {
            throw new RuntimeException('The model returned an incomplete daily forecast horizon.');
        }
        $dailyTotal = 0.0;
        foreach ($daily as $offset => $day) {
            if (!is_array($day) || ($day['date'] ?? '') !== $today->modify('+' . ($offset + 1) . ' days')->format('Y-m-d')) {
                throw new RuntimeException('Daily forecast dates do not match the selected horizon.');
            }
            $dailyTotal += forecastGenerationNumber($day, 'forecasted_consumption');
        }
        if (abs($dailyTotal - $demand) > ($days + 1) * 0.0001) {
            throw new RuntimeException('Daily forecast quantities differ from the horizon total.');
        }
        $decision = forecastStatusClassify($stock, $minimum, $demand, $days,
            $policy['restocking_lead_days'], $policy['safety_buffer_days']);
        $forecasts[] = array_replace($row, $decision, ['current_stock' => $stock,
            'minimum_stock' => $minimum, 'forecasted_consumption' => $demand,
            'shortage_probability' => $probability]);
    }
    return $forecasts;
}

/** Stock locks remain held through publication; later writes use existing stale triggers. */
function forecastGenerationPublish(mysqli $conn, string $branchId, int $days, int $userId,
    array $snapshot, array $result): int
{
    forecastAccuracyAssertSchema($conn);
    $forecasts = forecastGenerationValidate($result, $snapshot, $days);
    $accuracy = forecastAccuracyValidate($result, $snapshot);
    // Range locks must also block insertion of a new batch during publication.
    $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction();
    try {
        $current = forecastGenerationSnapshot($conn, $branchId);
        if ($current['revision'] !== $snapshot['revision']
            || !hash_equals($snapshot['signature'], $current['signature'])) {
            throw new RuntimeException('Stock, item settings, clinic usage or the inventory date changed while forecasting was running. Reload to generate a fresh forecast.');
        }
        $actor = forecastTrainingStatement($conn,
            "SELECT user_id FROM users WHERE user_id=? AND branch_id=? AND role_id=2 AND status='Active' FOR UPDATE");
        $actor->bind_param('is', $userId, $branchId); forecastTrainingExecute($actor);
        $allowed = (bool)$actor->get_result()->fetch_assoc(); $actor->close();
        if (!$allowed) throw new RuntimeException('Your active branch administrator assignment changed while forecasting.');
        $delete = forecastTrainingStatement($conn,
            'DELETE FROM forecast_results WHERE branch_id = ? AND forecast_days = ?');
        $delete->bind_param('si', $branchId, $days);
        forecastTrainingExecute($delete);
        $delete->close();
        // The method column is optional until sources/migrate_forecast_method.php is applied.
        $storeMethod = forecastMethodColumnExists($conn);
        $insert = forecastTrainingStatement($conn,
            'INSERT INTO forecast_results (item_id, branch_id, forecast_date, forecast_start_date,
             forecast_end_date, current_stock_snapshot, minimum_stock_snapshot, stock_status,
             shortage_probability, forecast_status, recommended_reorder, generated_by,
             forecasted_consumption, forecast_days, is_stale, stale_at, stale_reason,
             status_rule_version, restocking_lead_days, safety_buffer_days'
             . ($storeMethod ? ', forecast_method' : '') . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL, ?, ?, ?'
             . ($storeMethod ? ', ?' : '') . ')');
        foreach ($forecasts as $row) {
            $id = $row['item_id'];
            $date = $snapshot['database_today'];
            $start = $row['forecast_start'];
            $end = $row['forecast_end'];
            $stock = stockQuantityDecimal(forecastStatusTicks($row['current_stock']));
            $minimum = stockQuantityDecimal(forecastStatusTicks($row['minimum_stock']));
            $demand = stockQuantityDecimal(forecastStatusTicks($row['forecasted_consumption']));
            $stockStatus = $row['stock_status'];
            $probability = $row['shortage_probability'];
            $status = $row['forecast_status'];
            $reorder = $row['recommended_reorder'];
            $version = $row['status_rule_version'];
            $lead = $row['restocking_lead_days'];
            $buffer = $row['safety_buffer_days'];
            $method = $row['method'];
            if ($storeMethod) {
                $insert->bind_param('isssssssdsiisisiis', $id, $branchId, $date, $start, $end,
                    $stock, $minimum, $stockStatus, $probability, $status, $reorder, $userId,
                    $demand, $days, $version, $lead, $buffer, $method);
            } else {
                $insert->bind_param('isssssssdsiisisii', $id, $branchId, $date, $start, $end,
                    $stock, $minimum, $stockStatus, $probability, $status, $reorder, $userId,
                    $demand, $days, $version, $lead, $buffer);
            }
            forecastTrainingExecute($insert);
        }
        $insert->close();
        $evaluationId = forecastAccuracySave($conn, $branchId, $days, $userId, $snapshot, $accuracy);
        $action = "Automatically generated {$days}-day forecasts for " . count($forecasts)
            . " items; saved accuracy evaluation #{$evaluationId}";
        $audit = forecastTrainingStatement($conn,
            "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, 'Supply Forecasting')");
        $audit->bind_param('iss', $userId, $branchId, $action);
        forecastTrainingExecute($audit);
        $audit->close();
        $conn->commit();
        return count($forecasts);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
