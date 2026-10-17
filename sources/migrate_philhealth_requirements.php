<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_philhealth_requirements.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/philhealth_requirements_helper.php';
// Additive only: one 0/1 column per requirement received at intake. The status
// flow and the 6-month eligibility columns are not touched.
$completed = 0;
try {
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'philhealth_records'");
    $stmt->execute();
    $columns = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $stmt->close();
    if (!$columns) throw new RuntimeException('Missing philhealth_records. Complete the existing database setup first.');
    $plan = [];
    foreach (array_keys(philhealthRequirementFields()) as $field) {
        if (!in_array($field, $columns, true)) {
            $plan[] = ["Add philhealth_records.$field", "ALTER TABLE philhealth_records ADD COLUMN `$field` TINYINT(1) NOT NULL DEFAULT 0"];
        }
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "PhilHealth requirements checklist is ready. Existing PhilHealth records were retained (no requirement ticked yet).\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'PhilHealth requirements migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some schema steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
