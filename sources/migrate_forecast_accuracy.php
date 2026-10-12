<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_forecast_accuracy.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/forecast_accuracy_helper.php';
$completed = 0;
try {
    $problems = forecastAccuracySchemaProblems($conn, true);
    if ($problems) throw new RuntimeException('Existing accuracy table definitions need review: ' . implode('; ', $problems));
    $plan = [];
    foreach (forecastAccuracyTableDefinitions() as $table => $definition) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $table); $stmt->execute(); $exists = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        if (!$exists) $plan[$table] = $definition;
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as $table => $definition) {
        echo '- Create ' . $table . "\n";
        if ($apply) { if (!$conn->query($definition)) throw new RuntimeException('Unable to create ' . $table); $completed++; }
    }
    if ($apply) { forecastAccuracyAssertSchema($conn); echo "Forecast accuracy setup is ready. Existing stock, training data and forecast records were retained.\n"; }
    else echo "No data or structure was changed. Add --apply to create the two accuracy tables.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Forecast accuracy setup stopped: ' . $e->getMessage() . "\n" .
        ($completed ? "Some schema steps completed. Correct the problem and rerun; DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
