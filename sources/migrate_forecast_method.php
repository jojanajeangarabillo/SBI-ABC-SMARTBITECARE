<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_forecast_method.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/forecast_status_helper.php';
// Records whether each saved forecast came from XGBoost or the recent 28-day average.
// Forecasting works without this column; the pages then show "Method: Not recorded".
$definition = 'ALTER TABLE forecast_results ADD COLUMN forecast_method VARCHAR(20) NULL';
try {
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (forecastMethodColumnExists($conn)) {
        echo "No migration changes are needed.\n";
        exit;
    }
    echo "- Add forecast_results.forecast_method VARCHAR(20) NULL\n";
    if ($apply) {
        if (!$conn->query($definition)) throw new RuntimeException('Unable to add forecast_results.forecast_method.');
        echo "Forecast method column is ready. Existing forecasts were retained; regenerate them to record their method.\n";
    } else {
        echo "No data or structure was changed. Add --apply to add the column.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Forecast method setup stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
