<?php
declare(strict_types=1);

/**
 * Daily closing is the only operational writer of training_dataset.
 * Call mutation functions inside the caller's transaction. The branch revision
 * row serializes this patch's closing/vaccination writes and detects forecasts
 * generated concurrently with a change. No stock is deducted by this helper.
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
    $today = (string)$result->fetch_assoc()['today'];
    if ($date > $today) {
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

/** Synchronize one saved closing. Returns true for a forecastable item. */
function forecastTrainingSyncClosing(mysqli $conn, string $branchId, int $itemId, string $date): bool
{
    forecastTrainingAssertDate($conn, $date);
    forecastTrainingRevision($conn, $branchId, true);
    $stmt = forecastTrainingStatement($conn,
        "SELECT d.*, i.is_forecastable, i.minimum_stock,
                COALESCE(NULLIF(i.base_unit_label, ''), u.unit_name) AS base_unit
         FROM daily_inventory_closings d
         JOIN inventory_items i ON i.item_id = d.item_id
         JOIN units u ON u.unit_id = i.unit_id
         WHERE d.branch_id = ? AND d.item_id = ? AND d.inventory_date = ? FOR UPDATE");
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $closing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$closing) throw new RuntimeException('The daily closing was not found in your branch.');
    if (!in_array($closing['status'], ['Submitted', 'Reviewed'], true)) {
        throw new RuntimeException('Resubmit the daily closing before synchronizing it for forecasting.');
    }
    if (!(bool)$closing['is_forecastable']) {
        // Only remove a previously generated record; never delete imported history.
        $stmt = forecastTrainingStatement($conn,
            "DELETE FROM training_dataset WHERE branch_id = ? AND item_id = ? AND record_date = ? AND source_type = 'daily_closing'");
        $stmt->bind_param('sis', $branchId, $itemId, $date);
        forecastTrainingExecute($stmt);
        $stmt->close();
        forecastTrainingInvalidateForecasts($conn, $branchId, 'Daily closing changed for a non-forecastable item.');
        return false;
    }
    foreach (['beginning_stock', 'delivery', 'consumed', 'actual_count'] as $field) {
        if (!is_finite((float)$closing[$field]) || (float)$closing[$field] < 0) {
            throw new RuntimeException('Daily closing quantities must be finite and non-negative.');
        }
    }
    $stmt = forecastTrainingStatement($conn,
        'SELECT COALESCE(SUM(quantity_used), 0) AS consumed FROM inventory_usage_history WHERE branch_id = ? AND item_id = ? AND usage_date = ?');
    $stmt->bind_param('sis', $branchId, $itemId, $date);
    forecastTrainingExecute($stmt);
    $recordedUsage = (float)$stmt->get_result()->fetch_assoc()['consumed'];
    $stmt->close();
    if (abs($recordedUsage - (float)$closing['consumed']) > 0.00005) {
        throw new RuntimeException('Usage changed after this closing. Resubmit the daily inventory before synchronizing training data.');
    }

    // Patient volume is a distinct daily check-in tally, not a sum of products.
    $stmt = forecastTrainingStatement($conn,
        "SELECT COUNT(DISTINCT patient_id) AS patient_count,
                COUNT(DISTINCT case_id) AS animal_bite_cases
         FROM patient_visits WHERE branch_id = ? AND visit_date = ? AND workflow_status <> 'Cancelled'");
    $stmt->bind_param('ss', $branchId, $date);
    forecastTrainingExecute($stmt);
    $counts = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $patientCount = (int)$counts['patient_count'];
    // Cases attending the clinic that day, not cases whose bite occurred that day.
    $caseCount = (int)$counts['animal_bite_cases'];
    $stmt = forecastTrainingStatement($conn,
        "SELECT COUNT(DISTINCT patient_id, case_id, dose_number) AS doses
         FROM vaccination_records WHERE branch_id = ? AND date_administered = ?
         AND vaccination_status = 'Completed' AND is_archived = 0");
    $stmt->bind_param('ss', $branchId, $date);
    forecastTrainingExecute($stmt);
    $doseCount = (int)$stmt->get_result()->fetch_assoc()['doses'];
    $stmt->close();

    $beginning = (float)$closing['beginning_stock'];
    $consumed = (float)$closing['consumed'];
    $received = (float)$closing['delivery'];
    $ending = (float)$closing['actual_count'];
    $minimum = max(0.0, (float)$closing['minimum_stock']);
    $lowStock = $ending <= $minimum ? 1 : 0;
    $closingId = (int)$closing['closing_id'];
    $baseUnit = (string)$closing['base_unit'];
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
    $stmt->bind_param('sisiddddiidiis', $branchId, $itemId, $date, $patientCount,
        $beginning, $consumed, $received, $ending, $caseCount, $doseCount,
        $minimum, $lowStock, $closingId, $baseUnit);
    forecastTrainingExecute($stmt);
    $stmt->close();
    forecastTrainingInvalidateForecasts($conn, $branchId, 'Daily inventory closing synchronized for ' . $date . '.');
    return true;
}

/** Usage after a closing requires a new physical closing, not incremental training additions. */
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
    forecastTrainingInvalidateForecasts($conn, $branchId, 'Actual vaccination usage changed for ' . $date . '.');
    return $reopened;
}
