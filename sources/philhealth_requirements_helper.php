<?php
declare(strict_types=1);

/** PhilHealth requirements Admin Staff tick at intake: column => label. */
function philhealthRequirementFields(): array
{
    return [
        'req_philhealth_id' => 'PhilHealth ID',
        'req_mdr' => 'MDR',
        'req_birth_certificate' => 'Birth Certificate',
        'req_valid_id' => 'Any valid ID',
    ];
}

/** True once sources/migrate_philhealth_requirements.php has been applied. */
function philhealthRequirementsReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $fields = array_keys(philhealthRequirementFields());
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'philhealth_records' AND COLUMN_NAME IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($fields)), ...$fields);
        $stmt->execute();
        $ready = (int)$stmt->get_result()->fetch_assoc()['total'] === count($fields);
        $stmt->close();
    }
    return $ready;
}

/** Labels of the requirements received, from a philhealth_records row. */
function philhealthRequirementsReceived(array $row): array
{
    $received = [];
    foreach (philhealthRequirementFields() as $field => $label) {
        if (!empty($row[$field])) $received[] = $label;
    }
    return $received;
}
