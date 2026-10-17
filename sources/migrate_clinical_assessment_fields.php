<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_clinical_assessment_fields.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
// Additive only: new ENUM values are appended, new columns are nullable, and
// existing assessments keep their stored values (PEP_ID, PEP_IM, PREP, BOOSTER, OTHER).
$completed = 0;
try {
    $table = 'clinical_assessments';
    $stmt = $conn->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table); $stmt->execute();
    $columns = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $columns[$row['COLUMN_NAME']] = $row;
    $stmt->close();
    if (!$columns) throw new RuntimeException('Missing clinical_assessments. Complete the existing database setup first.');
    if (!isset($columns['treatment_profile'])) throw new RuntimeException('clinical_assessments.treatment_profile is missing. Review the table first.');

    $plan = [];
    // Vaccination regimen routes for PrEP and Booster (item 5).
    $type = (string)$columns['treatment_profile']['COLUMN_TYPE'];
    if (str_starts_with(strtolower($type), 'enum(')) {
        preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);
        $values = array_map(static fn($v) => str_replace("''", "'", $v), $matches[1]);
        foreach (['PEP_ID', 'PEP_IM', 'PREP', 'BOOSTER', 'OTHER'] as $required) {
            if (!in_array($required, $values, true)) throw new RuntimeException("treatment_profile no longer contains '$required'. Review it before applying.");
        }
        $missing = array_values(array_diff(['PREP_ID', 'PREP_IM', 'BOOSTER_ID', 'BOOSTER_IM'], $values));
        if ($missing) {
            $all = array_merge($values, $missing);
            $enum = "ENUM('" . implode("','", array_map(static fn($v) => str_replace("'", "''", $v), $all)) . "')";
            $null = $columns['treatment_profile']['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
            $plan[] = ['Add regimen values ' . implode(', ', $missing) . ' to clinical_assessments.treatment_profile',
                "ALTER TABLE clinical_assessments MODIFY treatment_profile $enum $null"];
        }
    }
    $fields = [
        // Booster eligibility (item 8): date of the previous anti-rabies vaccination.
        'previous_vaccination_date' => 'DATE DEFAULT NULL',
        // Follow-up from another clinic (item 9).
        'outside_clinic_name' => 'VARCHAR(255) DEFAULT NULL',
        // Type of Wound (item 13) and Type of Bite (item 14).
        'wound_type' => 'VARCHAR(30) DEFAULT NULL',
        'bite_type' => 'VARCHAR(20) DEFAULT NULL',
    ];
    foreach ($fields as $field => $definition) {
        if (!isset($columns[$field])) $plan[] = ["Add clinical_assessments.$field", "ALTER TABLE clinical_assessments ADD COLUMN `$field` $definition"];
    }

    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "Clinical assessment fields are ready. Existing assessments and vaccination records were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Clinical assessment migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some schema steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
