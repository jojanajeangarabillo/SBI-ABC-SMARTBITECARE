<?php
declare(strict_types=1);

// Command-line only. Defaults to a read-only preview.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['branch:', 'date:', 'apply', 'replace-historical']);
$branchId = trim((string)($options['branch'] ?? ''));
$date = isset($options['date']) ? trim((string)$options['date']) : null;
if ($branchId === '') {
    fwrite(STDERR, "Usage: php scripts/sync_forecast_training.php --branch=SBI-002 [--date=YYYY-MM-DD] [--apply] [--replace-historical]\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/sources/db_connect.php';
require_once dirname(__DIR__) . '/sources/forecast_training_helper.php';

try {
    if ($date !== null) forecastTrainingAssertDate($conn, $date);
    $sql = "SELECT d.item_id, d.inventory_date, d.consumed,
                   t.source_type,
                   (SELECT COALESCE(SUM(h.quantity_used), 0) FROM inventory_usage_history h
                    WHERE h.branch_id = d.branch_id AND h.item_id = d.item_id AND h.usage_date = d.inventory_date) AS recorded_usage
            FROM daily_inventory_closings d
            JOIN inventory_items i ON i.item_id = d.item_id
            LEFT JOIN training_dataset t ON t.branch_id = d.branch_id AND t.item_id = d.item_id AND t.record_date = d.inventory_date
            WHERE d.branch_id = ? AND d.status IN ('Submitted', 'Reviewed')
              AND i.is_forecastable = 1 AND d.inventory_date <= CURDATE()";
    if ($date !== null) $sql .= ' AND d.inventory_date = ?';
    $sql .= ' ORDER BY d.inventory_date, d.item_id';
    $stmt = forecastTrainingStatement($conn, $sql);
    if ($date !== null) $stmt->bind_param('ss', $branchId, $date);
    else $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $eligible = $synced = $skipped = 0;
    foreach ($rows as $row) {
        $itemId = (int)$row['item_id'];
        $recordDate = (string)$row['inventory_date'];
        if (abs((float)$row['consumed'] - (float)$row['recorded_usage']) > 0.00005) {
            echo "SKIP item {$itemId}, {$recordDate}: usage differs from the closing; resubmit that closing.\n";
            $skipped++;
            continue;
        }
        if ($row['source_type'] !== null && $row['source_type'] !== 'daily_closing'
            && !isset($options['replace-historical'])) {
            echo "SKIP item {$itemId}, {$recordDate}: historical training row exists.\n";
            $skipped++;
            continue;
        }
        $eligible++;
        if (!isset($options['apply'])) {
            echo "PREVIEW item {$itemId}, {$recordDate}: would synchronize this closing.\n";
            continue;
        }
        $conn->begin_transaction();
        try {
            forecastTrainingRevision($conn, $branchId, true);
            // Recheck provenance inside the transaction in case the preview changed.
            $check = forecastTrainingStatement($conn,
                'SELECT source_type FROM training_dataset WHERE branch_id = ? AND item_id = ? AND record_date = ? FOR UPDATE');
            $check->bind_param('sis', $branchId, $itemId, $recordDate);
            forecastTrainingExecute($check);
            $existing = $check->get_result()->fetch_assoc();
            $check->close();
            if ($existing && $existing['source_type'] !== 'daily_closing' && !isset($options['replace-historical'])) {
                throw new RuntimeException('A historical record now exists; it was preserved.');
            }
            forecastTrainingSyncClosing($conn, $branchId, $itemId, $recordDate);
            $conn->commit();
            $synced++;
            echo "SYNCED item {$itemId}, {$recordDate}.\n";
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
    echo json_encode(['branch' => $branchId, 'apply' => isset($options['apply']),
        'eligible' => $eligible, 'synced' => $synced, 'skipped' => $skipped], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Synchronization failed: ' . $e->getMessage() . "\n");
    exit(1);
}
