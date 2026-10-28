<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_helpers.php';
require_once __DIR__ . '/patient_display_helper.php';

/*
 * LOCKED (VERIFIED) REGISTRY RECORDS
 * ------------------------------------------------------------------
 * When Admin Staff verify a registry entry (AdminStaff_Registry.php,
 * registry_status 'Registered'), its clinical and registry fields become
 * read-only for everyone: the patient's registry details, the bite case
 * and the registry treatment values (REGISTRY_LOCK_FIELDS). The Nurse's
 * chart of a verified visit was already closed (the Assessment page only
 * saves visits that are not yet For Registry / Registered).
 *
 * A change is an explicit correction (Registry_Correction.php): the user
 * gives a reason and the new values; the change is saved with the old and
 * new values in registry_corrections and the audit log, and the record goes
 * back to 'For Re-verification' until Admin Staff verify it again. While
 * For Re-verification the fields stay locked (another correction is
 * possible). Pages that edit these fields call registryLockAssertUnchanged()
 * before saving, so nothing bypasses the lock.
 *
 * Not locked: dose progression (a newly completed dose sets its registry
 * dose flag), case_status, PhilHealth workflow, archiving and restarted
 * cycles, and fields of cases that were never verified.
 * sources/migrate_registry_lock.php adds the status value and the table.
 */

/** field key => [label, table alias, column]. */
const REGISTRY_LOCK_FIELDS = [
    'patient.full_name' => ['Name', 'p', 'full_name'],
    'patient.birthday' => ['Date of birth', 'p', 'birthday'],
    'patient.gender' => ['Gender', 'p', 'gender'],
    'patient.address' => ['Address', 'p', 'address'],
    'patient.contact_number' => ['Contact No.', 'p', 'contact_number'],
    'case.case_number' => ['Case No.', 'c', 'case_number'],
    'case.admission_date' => ['Date (admission)', 'c', 'admission_date'],
    'case.date_of_bite' => ['Date of Bite', 'c', 'date_of_bite'],
    'case.bite_location' => ['Site of Bite', 'c', 'bite_location'],
    'case.animal_type' => ['Biting Animal', 'c', 'animal_type'],
    'case.animal_status' => ['Status of Animal', 'c', 'animal_status'],
    'case.bite_category' => ['Category', 'c', 'bite_category'],
    'registry.registry_number' => ['Registry No.', 'r', 'registry_number'],
    'registry.erig' => ['ERIG (mL)', 'r', 'erig'],
    'registry.ats' => ['ATS (mL)', 'r', 'ats'],
    'registry.tt' => ['TT', 'r', 'tt'],
    'registry.active_regimen' => ['Active Regimen', 'r', 'active_regimen'],
    'registry.remarks' => ['Registry Remarks', 'r', 'remarks'],
];

/*
 * The pages show the bite details from the latest Nurse assessment, with the
 * intake case as fallback (patient_display_helper.php). The lock compares the
 * same values, and a correction updates both the case and that assessment.
 * case field => clinical_assessments column.
 */
const REGISTRY_LOCK_ASSESSMENT_COLUMNS = [
    'case.date_of_bite' => 'date_of_exposure',
    'case.bite_location' => 'exposure_site',
    'case.animal_type' => 'animal_type',
    'case.animal_status' => 'animal_status',
    'case.bite_category' => 'bite_category',
];

const REGISTRY_LOCKED_STATUSES = ['Registered', 'For Re-verification'];

function registryLockReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = workflowColumnExists($conn, 'registry_corrections', 'correction_id');
    }
    return $ready;
}

/**
 * Lock of one case: the non-archived registry record that Admin Staff
 * verified (Registered or For Re-verification), or null.
 */
function registryLockForCase(mysqli $conn, int $caseId): ?array
{
    if ($caseId < 1) return null;
    $stmt = $conn->prepare(
        "SELECT r.registry_id, r.case_id, r.registry_status, r.registered_at, r.registry_number, c.patient_id, c.branch_id,
                u.username AS registered_by_name
         FROM registry_records r
         JOIN animal_bite_cases c ON c.case_id = r.case_id
         LEFT JOIN users u ON u.user_id = r.registered_by
         WHERE r.case_id = ? AND r.is_archived = 0 AND c.is_archived = 0
           AND r.registry_status IN ('Registered', 'For Re-verification')
         ORDER BY r.registry_id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** First lock among the non-archived cases of a patient (patient fields are shared by all cases). */
function registryLockForPatient(mysqli $conn, int $patientId): ?array
{
    if ($patientId < 1) return null;
    $stmt = $conn->prepare(
        "SELECT c.case_id FROM registry_records r
         JOIN animal_bite_cases c ON c.case_id = r.case_id
         WHERE c.patient_id = ? AND r.is_archived = 0 AND c.is_archived = 0
           AND r.registry_status IN ('Registered', 'For Re-verification')
         ORDER BY r.registered_at DESC, r.registry_id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? registryLockForCase($conn, (int)$row['case_id']) : null;
}

/** Locked case ids of a list of cases (for list pages). */
function registryLockedCaseIds(mysqli $conn, string $branchId): array
{
    $stmt = $conn->prepare(
        "SELECT r.case_id, r.registry_status FROM registry_records r
         JOIN animal_bite_cases c ON c.case_id = r.case_id
         WHERE c.branch_id = ? AND r.is_archived = 0 AND c.is_archived = 0
           AND r.registry_status IN ('Registered', 'For Re-verification')"
    );
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $ids = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $ids[(int)$row['case_id']] = (string)$row['registry_status'];
    $stmt->close();
    return $ids;
}

/** Current values of every locked field of a case (raw database values, normalized). */
function registryLockSnapshot(mysqli $conn, int $caseId, bool $forUpdate = false): array
{
    $stmt = $conn->prepare(
        "SELECT p.patient_id, p.full_name, p.birthday, p.gender, p.address, p.contact_number,
                c.case_id, c.branch_id, c.case_number, DATE(c.created_at) AS admission_date,
                " . patientClinicalSelectSql($conn, 'c', 'la') . ", la.assessment_id AS latest_assessment_id,
                r.registry_id, r.registry_status, r.registry_number, r.erig, r.ats, r.tt, r.active_regimen, r.remarks
         FROM animal_bite_cases c
         JOIN patients p ON p.patient_id = c.patient_id
         " . patientLatestAssessmentJoinSql('c', 'la') . "
         LEFT JOIN registry_records r ON r.case_id = c.case_id AND r.is_archived = 0
         WHERE c.case_id = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException('The case was not found.');
    $values = [];
    foreach (REGISTRY_LOCK_FIELDS as $key => [, , $column]) {
        $values[$key] = registryLockNormalize($key, $row[$column] ?? null);
    }
    return ['row' => $row, 'values' => $values];
}

/** Comparable form of a field value (trimmed text, Y-m-d dates, 2-decimal numbers, 0/1 flags). */
function registryLockNormalize(string $key, $value): string
{
    if ($value === null) return '';
    $text = trim((string)$value);
    if (in_array($key, ['patient.birthday', 'case.admission_date', 'case.date_of_bite'], true)) {
        return $text === '' || str_starts_with($text, '0000') ? '' : substr($text, 0, 10);
    }
    if (in_array($key, ['registry.erig', 'registry.ats'], true)) {
        return $text === '' ? '0.00' : number_format((float)$text, 2, '.', '');
    }
    if ($key === 'registry.tt') return ((int)$text !== 0 || strtolower($text) === 'yes') ? '1' : '0';
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

/** Changed locked fields: key => [label, old, new]. $posted holds only the keys a page saves. */
function registryLockChanges(array $snapshotValues, array $posted): array
{
    $changes = [];
    foreach ($posted as $key => $value) {
        if (!isset(REGISTRY_LOCK_FIELDS[$key])) continue;
        $new = registryLockNormalize($key, $value);
        $old = $snapshotValues[$key] ?? '';
        if ($new !== $old) $changes[$key] = [REGISTRY_LOCK_FIELDS[$key][0], $old, $new];
    }
    return $changes;
}

/** Text of a lock for messages: "verified by X on Y". */
function registryLockLabel(array $lock): string
{
    $status = (string)$lock['registry_status'] === 'For Re-verification' ? 'corrected and waiting for re-verification' : 'verified';
    return $status . (!empty($lock['registered_by_name']) ? ' by ' . $lock['registered_by_name'] : '')
        . (!empty($lock['registered_at']) ? ' on ' . date('M d, Y', strtotime((string)$lock['registered_at'])) : '');
}

/**
 * Refuse a save that would change a locked field. $posted: field key => new
 * value (only the fields the page writes). Throws with the field names.
 */
function registryLockAssertUnchanged(mysqli $conn, int $caseId, ?int $patientId, array $posted): void
{
    $caseLock = registryLockForCase($conn, $caseId);
    $patientLock = $patientId ? registryLockForPatient($conn, $patientId) : null;
    if (!$caseLock && !$patientLock) return;
    $snapshot = registryLockSnapshot($conn, $caseLock ? $caseId : (int)$patientLock['case_id']);
    $check = [];
    foreach ($posted as $key => $value) {
        $isPatientField = str_starts_with($key, 'patient.');
        if (($isPatientField && ($patientLock || $caseLock)) || (!$isPatientField && $caseLock)) $check[$key] = $value;
    }
    $changes = registryLockChanges($snapshot['values'], $check);
    if (!$changes) return;
    $lock = $caseLock ?: $patientLock;
    $labels = implode(', ', array_map(static fn($c) => $c[0], $changes));
    throw new RuntimeException('This registry record was ' . registryLockLabel($lock) . ' and its clinical and registry fields are read-only ('
        . $labels . '). Use "Request correction" and give a reason; the record then goes back for re-verification.');
}

/** URL of the correction page of a case. */
function registryCorrectionUrl(int $caseId): string
{
    return 'Registry_Correction.php?case_id=' . $caseId;
}

/** Small HTML notice for a locked case, with the Request correction link. */
function registryLockNoticeHtml(array $lock, bool $canCorrect = true): string
{
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return '<div class="alert alert-info d-flex flex-wrap gap-2 align-items-center registry-lock-notice" role="status">'
        . '<i class="bi bi-lock-fill"></i><span>Registry record ' . $h($lock['registry_number'] ?? '') . ' was ' . $h(registryLockLabel($lock))
        . '. Its clinical and registry fields are read-only.</span>'
        . ($canCorrect ? '<a class="btn btn-sm btn-outline-primary ms-auto" href="' . $h(registryCorrectionUrl((int)$lock['case_id']))
            . '"><i class="bi bi-pencil-square me-1"></i>Request correction</a>' : '')
        . '</div>';
}

/** Validate one corrected value; returns the value to store. */
function registryCorrectionValidate(string $key, string $value): ?string
{
    $value = trim($value);
    switch ($key) {
        case 'patient.full_name':
        case 'case.case_number':
            if ($value === '') throw new RuntimeException(REGISTRY_LOCK_FIELDS[$key][0] . ' is required.');
            if (mb_strlen($value) > 100 && $key === 'case.case_number') throw new RuntimeException('Case No. is too long.');
            if (mb_strlen($value) > 255) throw new RuntimeException(REGISTRY_LOCK_FIELDS[$key][0] . ' is too long.');
            return $value;
        case 'patient.birthday':
        case 'case.admission_date':
        case 'case.date_of_bite':
            if ($value === '') return null;
            $d = DateTime::createFromFormat('!Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) throw new RuntimeException(REGISTRY_LOCK_FIELDS[$key][0] . ' must be a valid date.');
            if ($value > date('Y-m-d')) throw new RuntimeException(REGISTRY_LOCK_FIELDS[$key][0] . ' cannot be in the future.');
            return $value;
        case 'patient.gender':
            if (!in_array($value, ['Male', 'Female', ''], true)) throw new RuntimeException('Select Male or Female.');
            return $value === '' ? null : $value;
        case 'patient.contact_number':
            if ($value !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $value)) throw new RuntimeException('Enter a valid contact number.');
            return $value === '' ? null : $value;
        case 'registry.erig':
        case 'registry.ats':
            if ($value === '') return '0';
            if (!is_numeric($value) || (float)$value < 0 || (float)$value > 9999) throw new RuntimeException(REGISTRY_LOCK_FIELDS[$key][0] . ' must be zero or more.');
            return number_format((float)$value, 2, '.', '');
        case 'registry.tt':
            return in_array($value, ['1', 'Yes', 'yes', 'on'], true) ? '1' : '0';
        case 'case.bite_category':
            if (!in_array($value, ['', 'I', 'II', 'III', 'Category I', 'Category II', 'Category III'], true)) throw new RuntimeException('Select category I, II or III.');
            return $value === '' ? null : $value;
        default:
            if (mb_strlen($value) > 1000) throw new RuntimeException(REGISTRY_LOCK_FIELDS[$key][0] . ' is too long.');
            return $value === '' ? null : $value;
    }
}

/**
 * Apply a correction to a locked record: new values (only changed fields
 * are stored), the reason, registry_corrections row with old and new
 * values, audit entry, status For Re-verification, notification to the
 * branch's Admin Staff. One transaction with the registry row locked.
 */
function registryCorrectionApply(mysqli $conn, int $userId, string $branchId, int $caseId, array $newValues, string $reason): string
{
    if (!registryLockReady($conn)) throw new RuntimeException('Registry corrections need the database update: php sources/migrate_registry_lock.php --apply');
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) throw new RuntimeException('Enter the reason for the correction (at least 5 characters).');
    if (mb_strlen($reason) > 1000) throw new RuntimeException('Keep the reason within 1,000 characters.');
    $conn->begin_transaction();
    try {
        $lock = registryLockForCase($conn, $caseId);
        if (!$lock || (string)$lock['branch_id'] !== $branchId) throw new RuntimeException('Only a verified registry record of your branch can be corrected here.');
        $stmt = $conn->prepare('SELECT registry_id FROM registry_records WHERE registry_id = ? FOR UPDATE');
        $registryId = (int)$lock['registry_id'];
        $stmt->bind_param('i', $registryId);
        $stmt->execute();
        $stmt->close();
        $snapshot = registryLockSnapshot($conn, $caseId, true);
        $row = $snapshot['row'];
        // Only changed fields are validated and stored (unchanged legacy values are kept as they are).
        $clean = [];
        foreach (registryLockChanges($snapshot['values'], array_map('strval', $newValues)) as $key => $ignored) {
            $clean[$key] = registryCorrectionValidate($key, (string)$newValues[$key]);
        }
        $changes = registryLockChanges($snapshot['values'], array_map(static fn($v) => $v ?? '', $clean));
        if (!$changes) throw new RuntimeException('No value was changed. Edit at least one field to request a correction.');
        if (isset($changes['case.case_number'])) {
            $dup = $conn->prepare('SELECT case_id FROM animal_bite_cases WHERE case_number = ? AND branch_id = ? AND case_id <> ? LIMIT 1');
            $newCase = (string)$clean['case.case_number'];
            $dup->bind_param('ssi', $newCase, $branchId, $caseId);
            $dup->execute();
            $exists = $dup->get_result()->num_rows > 0;
            $dup->close();
            if ($exists) throw new RuntimeException('Case number ' . $newCase . ' already exists in this branch.');
        }
        $patientId = (int)$row['patient_id'];
        $tables = ['p' => ['patients', 'patient_id', $patientId], 'c' => ['animal_bite_cases', 'case_id', $caseId], 'r' => ['registry_records', 'registry_id', $registryId]];
        foreach ($tables as $alias => [$table, $pk, $id]) {
            $sets = [];
            $types = '';
            $params = [];
            foreach ($changes as $key => $change) {
                [, $fieldAlias, $column] = REGISTRY_LOCK_FIELDS[$key];
                if ($fieldAlias !== $alias) continue;
                if ($column === 'admission_date') {
                    $sets[] = "created_at = CONCAT(?, ' ', TIME(created_at))";
                } else {
                    $sets[] = "$column = ?";
                }
                $types .= 's';
                $params[] = $clean[$key];
            }
            // The registry copy of the contact number follows the patient's.
            if ($alias === 'r' && isset($changes['patient.contact_number'])) {
                $sets[] = 'contact_number = ?';
                $types .= 's';
                $params[] = $clean['patient.contact_number'];
            }
            if ($alias === 'r') {
                $sets[] = "registry_status = 'For Re-verification'";
                $sets[] = 'updated_by = ?';
                $sets[] = 'updated_at = NOW()';
                $types .= 'i';
                $params[] = $userId;
            }
            if (!$sets) continue;
            $types .= 'i';
            $params[] = $id;
            $stmt = $conn->prepare("UPDATE $table SET " . implode(', ', $sets) . " WHERE $pk = ?");
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new RuntimeException('Unable to save the correction: ' . $stmt->error);
            $stmt->close();
        }
        // The latest Nurse assessment shows these bite details on every page: correct it too.
        $assessmentId = (int)($row['latest_assessment_id'] ?? 0);
        if ($assessmentId > 0) {
            $sets = [];
            $params = [];
            foreach (REGISTRY_LOCK_ASSESSMENT_COLUMNS as $key => $column) {
                if (!isset($changes[$key])) continue;
                $sets[] = "$column = ?";
                $params[] = $clean[$key];
            }
            if ($sets) {
                $params[] = $assessmentId;
                $stmt = $conn->prepare('UPDATE clinical_assessments SET ' . implode(', ', $sets) . ' WHERE assessment_id = ?');
                $stmt->bind_param(str_repeat('s', count($params) - 1) . 'i', ...$params);
                if (!$stmt->execute()) throw new RuntimeException('Unable to correct the Nurse assessment: ' . $stmt->error);
                $stmt->close();
            }
        }
        $changeList = [];
        foreach ($changes as $key => [$label, $old, $new]) $changeList[] = ['field' => $key, 'label' => $label, 'old' => $old, 'new' => $new];
        $json = json_encode($changeList, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare("INSERT INTO registry_corrections (registry_id, case_id, patient_id, branch_id, requested_by, requested_at, reason, changes)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
        $stmt->bind_param('iiisiss', $registryId, $caseId, $patientId, $branchId, $userId, $reason, $json);
        $stmt->execute();
        $correctionId = (int)$conn->insert_id;
        $stmt->close();
        $diff = implode('; ', array_map(static fn($c) => $c['label'] . ': "' . $c['old'] . '" -> "' . $c['new'] . '"', $changeList));
        workflowAudit($conn, $userId, $branchId, 'Registry correction #' . $correctionId . ' for ' . $row['full_name']
            . ' (case ' . $row['case_number'] . ', registry ' . ($row['registry_number'] ?? '') . ') | Reason: ' . $reason
            . ' | ' . $diff . ' | Status: For Re-verification', 'Registry');
        workflowNotifyRole($conn, $branchId, 4, 'Registry correction needs re-verification',
            $row['full_name'] . ' (case ' . $row['case_number'] . '): ' . $diff . '. Reason: ' . $reason
            . '. Open the Registry Queue to verify it again.', 'registry');
        $conn->commit();
        return 'Correction #' . $correctionId . ' was saved (' . count($changeList) . ' field' . (count($changeList) === 1 ? '' : 's')
            . '). The record is now For Re-verification by Admin Staff.';
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Corrections of a branch waiting for re-verification, with the change list. */
function registryCorrectionsPending(mysqli $conn, string $branchId): array
{
    if (!registryLockReady($conn)) return [];
    $stmt = $conn->prepare(
        "SELECT rc.*, u.username AS requester_name, rl.role_name AS requester_role, p.full_name, c.case_number, r.registry_number, r.registry_status
         FROM registry_corrections rc
         JOIN registry_records r ON r.registry_id = rc.registry_id
         JOIN animal_bite_cases c ON c.case_id = rc.case_id
         JOIN patients p ON p.patient_id = rc.patient_id
         LEFT JOIN users u ON u.user_id = rc.requested_by
         LEFT JOIN roles rl ON rl.role_id = u.role_id
         WHERE rc.branch_id = ? AND rc.status = 'For Re-verification'
         ORDER BY rc.requested_at, rc.correction_id"
    );
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) $row['changes_list'] = json_decode((string)$row['changes'], true) ?: [];
    unset($row);
    return $rows;
}

/** Corrections of one case (history on the correction page). */
function registryCorrectionsForCase(mysqli $conn, int $caseId): array
{
    if (!registryLockReady($conn)) return [];
    $stmt = $conn->prepare(
        "SELECT rc.*, u.username AS requester_name, v.username AS verifier_name
         FROM registry_corrections rc LEFT JOIN users u ON u.user_id = rc.requested_by LEFT JOIN users v ON v.user_id = rc.verified_by
         WHERE rc.case_id = ? ORDER BY rc.requested_at DESC, rc.correction_id DESC"
    );
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) $row['changes_list'] = json_decode((string)$row['changes'], true) ?: [];
    unset($row);
    return $rows;
}

/**
 * Admin Staff verify a corrected record again: status back to Registered
 * (locked), the pending corrections marked Verified, audit, notification
 * to the requesters. The requester of a correction may not verify it.
 */
function registryReverify(mysqli $conn, int $adminStaffId, string $branchId, int $registryId, string $remarks): string
{
    if (!registryLockReady($conn)) throw new RuntimeException('Registry corrections need the database update.');
    $remarks = trim($remarks);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT r.registry_id, r.registry_status, r.case_id, r.registry_number, c.branch_id, p.full_name
            FROM registry_records r JOIN animal_bite_cases c ON c.case_id = r.case_id JOIN patients p ON p.patient_id = c.patient_id
            WHERE r.registry_id = ? AND r.is_archived = 0 FOR UPDATE");
        $stmt->bind_param('i', $registryId);
        $stmt->execute();
        $registry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$registry || (string)$registry['branch_id'] !== $branchId) throw new RuntimeException('Registry record not found in your branch.');
        if ($registry['registry_status'] !== 'For Re-verification') {
            throw new RuntimeException('This registry record is ' . $registry['registry_status'] . ', not waiting for re-verification.');
        }
        $stmt = $conn->prepare("SELECT correction_id, requested_by FROM registry_corrections WHERE registry_id = ? AND status = 'For Re-verification' FOR UPDATE");
        $stmt->bind_param('i', $registryId);
        $stmt->execute();
        $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($pending as $p) {
            if ((int)$p['requested_by'] === $adminStaffId) {
                throw new RuntimeException('You requested a correction on this record, so another Admin Staff member must verify it.');
            }
        }
        $stmt = $conn->prepare("UPDATE registry_records SET registry_status = 'Registered', registered_by = ?, registered_at = NOW(),
            updated_by = ?, updated_at = NOW() WHERE registry_id = ? AND registry_status = 'For Re-verification'");
        $stmt->bind_param('iii', $adminStaffId, $adminStaffId, $registryId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('The record changed. Refresh and try again.');
        $stmt->close();
        $stmt = $conn->prepare("UPDATE registry_corrections SET status = 'Verified', verified_by = ?, verified_at = NOW(), verification_remarks = NULLIF(?, '')
            WHERE registry_id = ? AND status = 'For Re-verification'");
        $stmt->bind_param('isi', $adminStaffId, $remarks, $registryId);
        $stmt->execute();
        $stmt->close();
        workflowAudit($conn, $adminStaffId, $branchId, 'Re-verified registry ' . $registry['registry_number'] . ' for ' . $registry['full_name']
            . ' after correction #' . implode(', #', array_column($pending, 'correction_id')) . ($remarks !== '' ? ' | Remark: ' . $remarks : ''), 'Registry');
        $notify = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, is_read, created_at) VALUES (?, ?, ?, 'registry', 0, NOW())");
        $title = 'Registry correction verified';
        $message = 'The correction of ' . $registry['full_name'] . ' (registry ' . $registry['registry_number'] . ') was verified again. The record is locked.';
        foreach (array_unique(array_map('intval', array_column($pending, 'requested_by'))) as $requester) {
            $notify->bind_param('iss', $requester, $title, $message);
            $notify->execute();
        }
        $notify->close();
        $conn->commit();
        return 'Registry ' . $registry['registry_number'] . ' of ' . $registry['full_name'] . ' was verified again and is locked.';
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
