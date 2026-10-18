<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_forecast_auto_evaluation.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/forecast_auto_evaluation.php';
// Additive only: one new table that records the automatic background
// evaluations (which training-data revision each used, running/failed state).
// Saved evaluation runs and their results are never changed or deleted.
$completed = 0;
try {
    $plan = [];
    $exists = $conn->query("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forecast_evaluation_jobs'")->fetch_assoc();
    if ((int)$exists['n'] === 0) {
        $plan[] = ['Create forecast_evaluation_jobs', forecastAutoEvaluationTableSql()];
    } else {
        foreach (forecastAutoEvaluationSchemaProblems($conn) as $problem) {
            throw new RuntimeException($problem . '. Review the table before applying.');
        }
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "Automatic evaluation tracking is ready. Existing evaluation runs were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Automatic evaluation migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some schema steps completed; correct the problem and rerun.\n" : "No migration changes were applied.\n"));
    exit(1);
}
