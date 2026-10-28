<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    echo "Usage: php sources/migrate_registry_lock.php [--apply]\nDefault: read-only preview.\n";
    exit;
}
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
/*
 * Verified registry records are read-only; a change is an explicit
 * correction (reason, old and new values) that sends the record back
 * For Re-verification. Additive only:
 *   - registry_records.registry_status gains 'For Re-verification'
 *     (existing values Draft / For Verification / Registered are kept)
 *   - new table registry_corrections: one row per correction request
 * No registry, patient, case or vaccination data is changed.
 */
$completed = 0;
try {
    $columnType = static function (string $table, string $column) use ($conn): ?string {
        $stmt = $conn->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['COLUMN_TYPE'] : null;
    };
    $statusType = $columnType('registry_records', 'registry_status');
    if ($statusType === null) throw new RuntimeException('registry_records.registry_status is missing. Complete the existing database setup first.');
    $plan = [];
    if (!str_contains(strtolower($statusType), "'for re-verification'")) {
        $plan[] = ["Allow registry_records.registry_status 'For Re-verification' (existing values kept)",
            "ALTER TABLE registry_records MODIFY COLUMN registry_status
             ENUM('Draft','For Verification','Registered','For Re-verification') NOT NULL DEFAULT 'Draft'"];
    }
    if ($columnType('registry_corrections', 'correction_id') === null) {
        $plan[] = ['Create table registry_corrections', "CREATE TABLE registry_corrections (
            correction_id INT NOT NULL AUTO_INCREMENT,
            registry_id INT NOT NULL,
            case_id INT NOT NULL,
            patient_id INT NOT NULL,
            branch_id VARCHAR(10) NOT NULL,
            requested_by INT NOT NULL,
            requested_at DATETIME NOT NULL,
            reason TEXT NOT NULL,
            changes TEXT NOT NULL,
            status ENUM('For Re-verification','Verified') NOT NULL DEFAULT 'For Re-verification',
            verified_by INT NULL DEFAULT NULL,
            verified_at DATETIME NULL DEFAULT NULL,
            verification_remarks TEXT NULL,
            PRIMARY KEY (correction_id),
            KEY idx_registry_corrections_registry (registry_id, status),
            KEY idx_registry_corrections_branch (branch_id, status, requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) {
            if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label . ': ' . $conn->error);
            $completed++;
        }
    }
    if ($apply) echo "Registry lock is ready. Existing registry records were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Registry lock migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
