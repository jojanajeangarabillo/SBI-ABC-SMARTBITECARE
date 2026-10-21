<?php
declare(strict_types=1);

// CLI only. A normal invocation previews changes without writing anything.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function syncForecastTrainingUsage(): string
{
    return "Usage: php sources/sync_forecast_training.php --branch=BRANCH_ID [--date=YYYY-MM-DD] [--apply]\n"
        . "Default: read-only preview. --apply synchronizes valid submitted/reviewed closings.\n"
        . "Imported history, drafts and inconsistent closings are preserved.\n";
}

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            echo syncForecastTrainingUsage();
            exit(0);
        }
        if ($argument === '--apply') {
            if (isset($options['apply'])) throw new RuntimeException('Specify --apply only once.');
            $options['apply'] = true;
        } elseif (preg_match('/\A--(branch|date)=(.+)\z/D', $argument, $match)) {
            if (isset($options[$match[1]])) throw new RuntimeException('Specify each option only once.');
            $options[$match[1]] = trim($match[2]);
        } else {
            throw new RuntimeException('Unknown or incomplete option. Use --branch=BRANCH_ID and optionally --date=YYYY-MM-DD or --apply.');
        }
    }
    $branchId = (string)($options['branch'] ?? '');
    if (!preg_match('/\A[A-Za-z0-9_-]{1,10}\z/D', $branchId)) {
        throw new RuntimeException('Supply a valid branch ID with --branch=BRANCH_ID.');
    }
    $date = $options['date'] ?? null;
    $apply = isset($options['apply']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . syncForecastTrainingUsage());
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = null;
$inTransaction = false;
try {
    require_once __DIR__ . '/db_connect.php';
    require_once __DIR__ . '/forecast_training_helper.php';
    require_once __DIR__ . '/inventory_stock_helpers.php';
    if ($date !== null) forecastTrainingAssertDate($conn, $date);

    // Read-only schema checks: this tool never creates or alters database tables.
    foreach ([
        'SELECT branch_id, revision, updated_at FROM forecast_training_branch_state LIMIT 0',
        'SELECT source_type, source_closing_id, base_unit_label_snapshot, synced_at FROM training_dataset LIMIT 0',
        'SELECT is_stale, stale_at, stale_reason FROM forecast_results LIMIT 0',
    ] as $query) {
        $conn->query($query);
    }
    $indexes = $conn->query("SHOW INDEX FROM training_dataset WHERE Non_unique = 0");
    $uniqueKeys = [];
    while ($index = $indexes->fetch_assoc()) {
        if ($index['Sub_part'] === null) $uniqueKeys[$index['Key_name']][] = $index['Column_name'];
    }
    $hasDailyKey = false;
    foreach ($uniqueKeys as $columns) {
        sort($columns);
        if ($columns === ['branch_id', 'item_id', 'record_date']) $hasDailyKey = true;
    }
    if (!$hasDailyKey) {
        throw new RuntimeException('The unique training key for branch, item and date is missing. Apply the existing synchronization migration before running this tool.');
    }
    $check = forecastTrainingStatement($conn, 'SELECT branch_id FROM branches WHERE branch_id = ?');
    $check->bind_param('s', $branchId);
    forecastTrainingExecute($check);
    $branchExists = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$branchExists) throw new RuntimeException('The selected branch does not exist in the connected database.');

    // The preview uses one read-only snapshot; it cannot insert a revision row.
    if (!$apply) {
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
        $inTransaction = true;
    }
    $sql = "SELECT d.item_id, d.inventory_date
            FROM daily_inventory_closings d
            JOIN inventory_items i ON i.item_id = d.item_id
            JOIN inventory_categories c ON c.category_id = i.category_id
            WHERE d.branch_id = ? AND d.status IN ('Submitted', 'Reviewed')
              AND i.is_forecastable = 1 AND d.inventory_date <= CURDATE()
              AND " . inventoryMedicalSql('c');  // non-medical items never reach training_dataset
    if ($date !== null) $sql .= ' AND d.inventory_date = ?';
    $sql .= ' ORDER BY d.inventory_date, d.item_id';
    $stmt = forecastTrainingStatement($conn, $sql);
    if ($date !== null) $stmt->bind_param('ss', $branchId, $date);
    else $stmt->bind_param('s', $branchId);
    forecastTrainingExecute($stmt);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summary = ['branch' => $branchId, 'apply' => $apply, 'eligible' => 0,
        'would_sync' => 0, 'synced' => 0, 'unchanged' => 0, 'skipped' => 0];
    foreach ($rows as $row) {
        $itemId = (int)$row['item_id'];
        $recordDate = (string)$row['inventory_date'];
        $label = "item {$itemId}, {$recordDate}";
        if ($apply) {
            $conn->begin_transaction();
            $inTransaction = true;
            // Recheck the closing, provenance and counts under the shared lock.
            forecastTrainingRevision($conn, $branchId, true);
        }
        try {
            $data = forecastTrainingClosingData($conn, $branchId, $itemId, $recordDate, $apply);
            if (!$data['is_forecastable']) throw new RuntimeException('This item is no longer enabled for forecasting.');
            $existing = forecastTrainingExistingRecord($conn, $branchId, $itemId, $recordDate, $apply);
            if ($existing && $existing['source_type'] !== 'daily_closing') {
                throw new RuntimeException('Imported historical training data exists for this item/date and was preserved.');
            }
        } catch (RuntimeException | InvalidArgumentException $e) {
            if ($apply) {
                $conn->rollback();
                $inTransaction = false;
            }
            $summary['skipped']++;
            echo "SKIP {$label}: " . $e->getMessage() . "\n";
            continue;
        }
        $summary['eligible']++;
        if ($existing && forecastTrainingRecordMatches($existing, $data)) {
            if ($apply) {
                $conn->commit();
                $inTransaction = false;
            }
            $summary['unchanged']++;
            echo "UNCHANGED {$label}: already synchronized.\n";
            continue;
        }
        $summary['would_sync']++;
        $change = $existing ? 'update the operational training row' : 'add the missing operational training row';
        if (!$apply) {
            echo "PREVIEW {$label}: would {$change}; consumption {$data['quantity_used']}.\n";
            continue;
        }
        forecastTrainingSyncClosing($conn, $branchId, $itemId, $recordDate);
        $actor = $data['submitted_by'];
        $action = 'Synchronized training from daily closing ' . $data['source_closing_id']
            . ' | Item ID: ' . $itemId . ' | Date: ' . $recordDate;
        $audit = forecastTrainingStatement($conn,
            "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, 'Forecasting')");
        $audit->bind_param('iss', $actor, $branchId, $action);
        forecastTrainingExecute($audit);
        $audit->close();
        $conn->commit();
        $inTransaction = false;
        $summary['synced']++;
        echo "SYNCED {$label}: training row saved.\n";
    }
    if ($inTransaction) {
        $conn->commit();
        $inTransaction = false;
    }
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (!$apply) echo "Preview only: no records were changed. Review SKIP messages before using --apply.\n";
    $conn->close();
} catch (Throwable $e) {
    if ($conn instanceof mysqli && $inTransaction) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
    }
    fwrite(STDERR, 'Synchronization failed: ' . $e->getMessage()
        . "\nCheck the connected database and the existing synchronization schema."
        . " No stock is adjusted by this tool. Earlier SYNCED rows remain committed; a retry skips unchanged rows.\n");
    exit(1);
}
