<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_fit_to_work_document.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
// Additive only: 'Fit-to-Work Certificate' is appended to medical_documents.document_type.
// Certificates generated before this migration were stored as 'Other' with a
// name starting "Fit-to-Work Certificate - "; those rows are re-typed. Nothing else changes.
$completed = 0;
try {
    $stmt = $conn->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medical_documents' AND COLUMN_NAME = 'document_type'");
    $stmt->execute();
    $column = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$column) throw new RuntimeException('Missing medical_documents.document_type. Complete the existing database setup first.');

    $plan = [];
    $type = (string)$column['COLUMN_TYPE'];
    if (!str_starts_with(strtolower($type), 'enum(')) throw new RuntimeException('medical_documents.document_type is not an ENUM. Review it before applying.');
    preg_match_all("/'((?:[^']|'')*)'/", $type, $matches);
    $values = array_map(static fn($v) => str_replace("''", "'", $v), $matches[1]);
    foreach (['Medical Certificate', 'Vaccination Certificate', 'Referral Letter', 'Other'] as $required) {
        if (!in_array($required, $values, true)) throw new RuntimeException("document_type no longer contains '$required'. Review it before applying.");
    }
    $newValue = 'Fit-to-Work Certificate';
    if (!in_array($newValue, $values, true)) {
        $all = array_merge($values, [$newValue]);
        $enum = "ENUM('" . implode("','", array_map(static fn($v) => str_replace("'", "''", $v), $all)) . "')";
        $null = $column['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $column['COLUMN_DEFAULT'] !== null && strtoupper((string)$column['COLUMN_DEFAULT']) !== 'NULL'
            ? " DEFAULT '" . str_replace("'", "''", trim((string)$column['COLUMN_DEFAULT'], "'")) . "'" : '';
        $plan[] = ["Add '$newValue' to medical_documents.document_type", "ALTER TABLE medical_documents MODIFY document_type $enum $null$default"];
    }
    $count = $conn->query("SELECT COUNT(*) AS total FROM medical_documents
        WHERE document_type = 'Other' AND document_name LIKE 'Fit-to-Work Certificate - %'");
    $pending = (int)($count->fetch_assoc()['total'] ?? 0);
    if ($pending > 0) {
        $plan[] = ["Re-type $pending Fit-to-Work certificate(s) saved as 'Other'", "UPDATE medical_documents SET document_type = 'Fit-to-Work Certificate'
            WHERE document_type = 'Other' AND document_name LIKE 'Fit-to-Work Certificate - %'"];
    }

    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "Fit-to-Work Certificate document type is ready. Existing documents were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fit-to-Work document migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
