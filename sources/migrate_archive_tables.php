<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_archive_tables.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
/*
 * Archiving a patient copies the case rows into the *_archive tables. Two
 * schema gaps made that copy fail:
 *  - a column is NOT NULL in the archive table while the source column allows
 *    NULL (e.g. vaccination_records_archive.item_id: scheduled doses that were
 *    not given yet have no vaccine), so strict mode rejected the copy;
 *  - archive_id without AUTO_INCREMENT.
 * Additive only: such columns become nullable and archive_id gets
 * AUTO_INCREMENT. No column is dropped or renamed and no row is changed.
 */
$tables = [
    'patients_archive' => 'patients',
    'animal_bite_cases_archive' => 'animal_bite_cases',
    'vaccination_records_archive' => 'vaccination_records',
    'registry_records_archive' => 'registry_records',
    'registry_patients_archive' => 'registry_patients',
    'registry_vaccination_doses_archive' => 'registry_vaccination_doses',
    'philhealth_records_archive' => 'philhealth_records',
];
// Archive columns filled from a differently named source column.
$sourceAlias = ['vaccination_records_archive' => ['administered_at' => 'administered_datetime']];
$completed = 0;

function migrateArchiveColumns(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY,
            CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $columns = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $columns[$row['COLUMN_NAME']] = $row;
    $stmt->close();
    return $columns;
}

try {
    $plan = [];
    foreach ($tables as $archiveTable => $sourceTable) {
        $archive = migrateArchiveColumns($conn, $archiveTable);
        if (!$archive) { echo "- Skip $archiveTable: table not found.\n"; continue; }
        $source = migrateArchiveColumns($conn, $sourceTable);

        $id = $archive['archive_id'] ?? null;
        if ($id && $id['COLUMN_KEY'] === 'PRI' && stripos((string)$id['EXTRA'], 'auto_increment') === false) {
            // A row stored with archive_id 0 would collide when AUTO_INCREMENT renumbers it.
            $zero = (int)$conn->query("SELECT COUNT(*) AS n FROM `$archiveTable` WHERE archive_id = 0")->fetch_assoc()['n'];
            if ($zero > 0) {
                $plan[] = ["Move the archive_id 0 row of $archiveTable to the next free id",
                    "UPDATE `$archiveTable` SET archive_id = (SELECT next_id FROM (SELECT COALESCE(MAX(archive_id), 0) + 1 AS next_id FROM `$archiveTable`) t) WHERE archive_id = 0"];
            }
            $plan[] = ["Add AUTO_INCREMENT to $archiveTable.archive_id",
                "ALTER TABLE `$archiveTable` MODIFY archive_id {$id['COLUMN_TYPE']} NOT NULL AUTO_INCREMENT"];
        }

        foreach ($archive as $name => $column) {
            if ($column['IS_NULLABLE'] === 'YES' || $column['COLUMN_KEY'] === 'PRI') continue;
            // The archive's own columns are always filled by the archive itself.
            if (in_array($name, ['archived_at', 'archived_by', 'archive_reason'], true) || str_starts_with($name, 'original_')) continue;
            // A column with a default never fails the copy; leave it unchanged.
            if ($column['COLUMN_DEFAULT'] !== null) continue;
            $sourceName = $sourceAlias[$archiveTable][$name] ?? $name;
            if (!isset($source[$sourceName]) || $source[$sourceName]['IS_NULLABLE'] !== 'YES') continue;
            $definition = $column['COLUMN_TYPE'];
            if ($column['CHARACTER_SET_NAME']) $definition .= ' CHARACTER SET ' . $column['CHARACTER_SET_NAME'] . ' COLLATE ' . $column['COLLATION_NAME'];
            $definition .= ' NULL DEFAULT NULL';
            if ((string)$column['COLUMN_COMMENT'] !== '') $definition .= " COMMENT '" . $conn->real_escape_string($column['COLUMN_COMMENT']) . "'";
            $plan[] = ["Allow NULL in $archiveTable.$name (the source $sourceTable.$sourceName allows NULL)",
                "ALTER TABLE `$archiveTable` MODIFY `$name` $definition"];
        }
    }

    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label); $completed++; }
    }
    if ($apply) echo "Archive tables are ready. Existing archived records were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Archive tables migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some schema steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
