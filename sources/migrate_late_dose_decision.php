<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_late_dose_decision.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
// Additive only: nullable columns on vaccination_records that store the nurse's
// choice for a dose given more than 7 days late (continue the current schedule
// or restart from Day 0). Old records keep NULL and display exactly as before.
$completed = 0;
try {
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vaccination_records'");
    $stmt->execute();
    $columns = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $stmt->close();
    if (!$columns) throw new RuntimeException('Missing vaccination_records. Complete the existing database setup first.');
    $definitions = [
        'late_decision' => "VARCHAR(20) NULL DEFAULT NULL COMMENT 'continue or restart, chosen by the nurse for a dose more than 7 days late'",
        'late_days' => 'INT NULL DEFAULT NULL',
        'late_decision_remark' => 'VARCHAR(500) NULL DEFAULT NULL',
        'late_decision_by' => 'INT NULL DEFAULT NULL',
        'late_decision_at' => 'DATETIME NULL DEFAULT NULL',
    ];
    $plan = [];
    foreach ($definitions as $field => $definition) {
        if (!in_array($field, $columns, true)) {
            $plan[] = ["Add vaccination_records.$field", "ALTER TABLE vaccination_records ADD COLUMN `$field` $definition"];
        }
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "Late-dose decision columns are ready. Existing vaccination records were retained unchanged.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Late-dose decision migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some schema steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
