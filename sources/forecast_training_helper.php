<?php
declare(strict_types=1);

require_once __DIR__ . '/stock_quantity_helpers.php';

/**
 * Submitted daily closings are the operational writer of training_dataset.
 * Mutations must run inside the caller's transaction, taking the branch revision
 * lock before clinical, closing or stock locks. No stock is deducted here.
 */
function forecastTrainingStatement(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Forecast training database error. Run the forecasting synchronization migration first.');
    }
    return $stmt;
}

function forecastTrainingExecute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save forecasting synchronization data.');
    }
}

function forecastTrainingAssertDate(mysqli $conn, string $date): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException('Select a valid inventory date.');
    }
    $result = $conn->query('SELECT CURDATE() AS today');
    if (!$result) throw new RuntimeException('Unable to validate the inventory date.');
    if ($date > (string)$result->fetch_assoc()['today']) {
        throw new RuntimeException('Actual inventory and usage cannot be submitted for a future date.');
    }
}

function forecastTrainingRevision(mysqli $conn, string $branchId, bool $lock = false): int
{
    if ($branchId === '') throw new RuntimeException('A branch is required for forecasting synchronization.');
    $stmt = forecastTrainingStatement($conn,
        'INSERT IGNORE INTO forecast_training_branch_state (branch_id) VALUES (?)');
    $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $stmt->close();
    $stmt = forecastTrainingStatement($conn,
        'SELECT revision FROM forecast_training_branch_state WHERE branch_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException('Unable to load the branch forecasting revision.');
    return (int)$row['revision'];
}

function forecastTrainingInvalidateForecasts(mysqli $conn, string $branchId, string $reason): void
{
    forecastTrainingRevision($conn, $branchId, true);
    $reason = substr($reason, 0, 255);
    $stmt = forecastTrainingStatement($conn,
        'UPDATE forecast_results SET is_stale = 1, stale_at = NOW(), stale_reason = ? WHERE branch_id = ?');
    $stmt->bind_param('ss', $reason, $branchId);
    forecastTrainingExecute($stmt);
    $stmt->close();
    $stmt = forecastTrainingStatement($conn,
        'UPDATE forecast_training_branch_state SET revision = revision + 1, updated_at = NOW() WHERE branch_id = ?');
    $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $stmt->close();
}

/**
 * The day's activity counts written to training_dataset by the daily closing
 * sync, and shown read-only on the Nurse Daily Inventory (so the number shown
 * is exactly the number saved). Same queries as before (moved here, unchanged):
 *   patient_count             distinct patients with a visit (check-in) that day, Cancelled excluded
 *   animal_bite_cases         distinct cases with a visit that day (new cases and follow-ups)
 *   vaccinations_administered distinct doses (patient, case, dose) completed that day, archived excluded
 */
function forecastTrainingDayCounts(mysqli $conn, string $branchId, string $date): array
{
    // Count each patient and case once, even when several products were used.
    $stmt = forecastTrainingStatement($conn,
        "SELECT COUNT(DISTINCT patient_id) AS patient_count,
                COUNT(DISTINCT case_id) AS animal_bite_cases
         FROM patient_visits WHERE branch_id = ? AND visit_date = ? AND workflow_status <> 'Cancelled'");
    $stmt->bind_param('ss', $branchId, $date);
    forecastTrainingExecute($stmt);
    $counts = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $result = [
        'patient_count' => (int)$counts['patient_count'],
        'animal_bite_cases' => (int)$counts['animal_bite_cases'],
    ];
    $stmt = forecastTrainingStatement($conn,
        "SELECT COUNT(DISTINCT patient_id, case_id, dose_number) AS doses
         FROM vaccination_records WHERE branch_id = ? AND date_administered = ?
         AND vaccination_status = 'Completed' AND is_archived = 0");
    $stmt->bind_param('ss', $branchId, $date);
    forecastTrainingExecute($stmt);
    $result['vaccinations_administered'] = (int)$stmt->get_result()->fetch_assoc()['doses'];
    $stmt->close();
    return $result;
}

/** Read the authoritative closing and daily counts; read-only when $lock is false. */
function forecastTrainingClosingData(mysqli $conn, string $branchId, int $itemId, string $date, bool $lock = false): array
{
    forecastTrainingAssertDate($conn, $date);
    $stmt = forecastTrainingStatement($conn,
        "SELECT d.*, i.is_forecastable, i.minimum_stock,
                COALESCE(NULLIF(i.base_unit_label, ''), u.unit_name) AS base_unit
         FROM daily_inventory_closings d
         JOIN inventory_items i ON i.item_id = d.item_id
         JOIN units u ON u.unit_id = i.unit_id
         WHERE d.branch_id = ? AND d.item_id = ? AND d.inventory_date = ?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $closing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$closing) throw new RuntimeException('The daily closing was not found in this branch.');
    if (!in_array($closing['status'], ['Submitted', 'Reviewed'], true)) {
        throw new RuntimeException('Resubmit the daily closing before synchronizing it for forecasting.');
    }
    $data = [
        'branch_id' => $branchId,
        'item_id' => $itemId,
        'record_date' => $date,
        'is_forecastable' => (bool)$closing['is_forecastable'],
        'source_type' => 'daily_closing',
        'source_closing_id' => (int)$closing['closing_id'],
        'submitted_by' => (int)$closing['submitted_by'],
        'base_unit_label_snapshot' => (string)$closing['base_unit'],
    ];
    if (!$data['is_forecastable']) return $data;

    // Preserve all four decimal places when reading, comparing and binding data.
    foreach (['beginning_stock' => 'beginning_stock', 'consumed' => 'quantity_used',
              'delivery' => 'stock_received', 'actual_count' => 'ending_stock'] as $from => $to) {
        $data[$to] = stockQuantityDecimal(stockQuantityParse((string)$closing[$from], true));
    }
    $computed = stockQuantityParse($data['beginning_stock'], true)
        + stockQuantityParse($data['stock_received'], true)
        - stockQuantityParse($data['quantity_used'], true)
        - stockQuantityParse((string)$closing['pull_out'], true);
    if ($computed < 0 || $computed > STOCK_QUANTITY_MAX_SCALED
        || stockQuantityParse((string)$closing['computed_ending'], true) !== $computed
        || (string)$closing['variance'] !== stockQuantityDecimal(stockQuantityParse($data['ending_stock'], true) - $computed)) {
        throw new RuntimeException('This closing has inconsistent stock calculations. Verify the opening, movements and physical count, then resubmit Daily Inventory.');
    }
    $stmt = forecastTrainingStatement($conn,
        'SELECT COALESCE(SUM(quantity_used), 0) AS consumed FROM inventory_usage_history WHERE branch_id = ? AND item_id = ? AND usage_date = ?');
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $recordedUsage = stockQuantityParse((string)$stmt->get_result()->fetch_assoc()['consumed'], true);
    $stmt->close();
    if ($recordedUsage !== stockQuantityParse($data['quantity_used'], true)) {
        throw new RuntimeException('Usage changed after this closing. Resubmit the daily inventory before synchronizing training data.');
    }

    $data = array_merge($data, forecastTrainingDayCounts($conn, $branchId, $date));
    $data['minimum_stock_level'] = stockQuantityDecimal(stockQuantityParse((string)$closing['minimum_stock'], true));
    $data['low_stock_target'] = stockQuantityParse($data['ending_stock'], true)
        <= stockQuantityParse($data['minimum_stock_level'], true) ? 1 : 0;
    return $data;
}

function forecastTrainingExistingRecord(mysqli $conn, string $branchId, int $itemId, string $date, bool $lock = false): ?array
{
    $stmt = forecastTrainingStatement($conn,
        'SELECT * FROM training_dataset WHERE branch_id = ? AND item_id = ? AND record_date = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $existing ?: null;
}

/** An unchanged row needs no write, timestamp change or forecast invalidation. */
function forecastTrainingRecordMatches(array $existing, array $data): bool
{
    foreach (['source_type', 'source_closing_id', 'base_unit_label_snapshot', 'patient_count',
              'animal_bite_cases', 'vaccinations_administered', 'low_stock_target'] as $field) {
        if (!isset($existing[$field]) || (string)$existing[$field] !== (string)$data[$field]) return false;
    }
    foreach (['beginning_stock', 'quantity_used', 'stock_received', 'ending_stock', 'minimum_stock_level'] as $field) {
        try {
            if (stockQuantityParse((string)($existing[$field] ?? ''), true) !== stockQuantityParse($data[$field], true)) return false;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }
    return !empty($existing['synced_at']);
}

/** Synchronize one closing. Imported history is never overwritten. */
function forecastTrainingSyncClosing(mysqli $conn, string $branchId, int $itemId, string $date): bool
{
    forecastTrainingRevision($conn, $branchId, true);
    $data = forecastTrainingClosingData($conn, $branchId, $itemId, $date, true);
    $existing = forecastTrainingExistingRecord($conn, $branchId, $itemId, $date, true);
    if (!$data['is_forecastable']) {
        if ($existing && $existing['source_type'] === 'daily_closing') {
            $stmt = forecastTrainingStatement($conn,
                "DELETE FROM training_dataset WHERE branch_id = ? AND item_id = ? AND record_date = ? AND source_type = 'daily_closing'");
            $stmt->bind_param('sis', $branchId, $itemId, $date);
            forecastTrainingExecute($stmt);
            $stmt->close();
            forecastTrainingInvalidateForecasts($conn, $branchId, 'Daily closing changed for a non-forecastable item.');
        }
        return false;
    }
    if ($existing && $existing['source_type'] !== 'daily_closing') {
        throw new RuntimeException('An imported historical training record already exists for this item and date. It was preserved. Review this date conflict before submitting the closing.');
    }
    if ($existing && forecastTrainingRecordMatches($existing, $data)) return true;

    $stmt = forecastTrainingStatement($conn,
        "INSERT INTO training_dataset
         (branch_id, item_id, record_date, patient_count, beginning_stock, quantity_used,
          stock_received, ending_stock, animal_bite_cases, vaccinations_administered,
          minimum_stock_level, low_stock_target, source_type, source_closing_id,
          base_unit_label_snapshot, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'daily_closing', ?, ?, NOW())
         ON DUPLICATE KEY UPDATE patient_count = VALUES(patient_count),
          beginning_stock = VALUES(beginning_stock), quantity_used = VALUES(quantity_used),
          stock_received = VALUES(stock_received), ending_stock = VALUES(ending_stock),
          animal_bite_cases = VALUES(animal_bite_cases), vaccinations_administered = VALUES(vaccinations_administered),
          minimum_stock_level = VALUES(minimum_stock_level), low_stock_target = VALUES(low_stock_target),
          source_type = VALUES(source_type), source_closing_id = VALUES(source_closing_id),
          base_unit_label_snapshot = VALUES(base_unit_label_snapshot), synced_at = NOW()");
    $stmt->bind_param('sisissssiisiis', $branchId, $itemId, $date, $data['patient_count'],
        $data['beginning_stock'], $data['quantity_used'], $data['stock_received'], $data['ending_stock'],
        $data['animal_bite_cases'], $data['vaccinations_administered'], $data['minimum_stock_level'],
        $data['low_stock_target'], $data['source_closing_id'], $data['base_unit_label_snapshot']);
    forecastTrainingExecute($stmt);
    $stmt->close();
    forecastTrainingInvalidateForecasts($conn, $branchId, 'Daily inventory closing synchronized for ' . $date . '.');
    return true;
}

/** New usage reopens the physical closing; it is never added to training twice. */
function forecastTrainingUsageChanged(mysqli $conn, string $branchId, int $itemId, string $date): int
{
    forecastTrainingAssertDate($conn, $date);
    forecastTrainingRevision($conn, $branchId, true);
    $stmt = forecastTrainingStatement($conn,
        "UPDATE daily_inventory_closings SET status = 'Draft', reviewed_by = NULL, reviewed_at = NULL
         WHERE branch_id = ? AND item_id = ? AND inventory_date = ? AND status IN ('Submitted', 'Reviewed')");
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $reopened = $stmt->affected_rows;
    $stmt->close();
    $stmt = forecastTrainingStatement($conn,
        "DELETE FROM training_dataset WHERE branch_id = ? AND item_id = ? AND record_date = ? AND source_type = 'daily_closing'");
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $stmt->close();
    forecastTrainingInvalidateForecasts($conn, $branchId, 'Actual clinic usage changed for ' . $date . '.');
    return $reopened;
}
