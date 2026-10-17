<?php
declare(strict_types=1);

function workflowCsrfToken(): string
{
    if (empty($_SESSION['workflow_csrf'])) {
        $_SESSION['workflow_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['workflow_csrf'];
}

function workflowVerifyCsrf(): void
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    if ($posted === '' || !hash_equals(workflowCsrfToken(), $posted)) {
        throw new RuntimeException('Invalid request token. Refresh the page and try again.');
    }
}

function workflowRequireUser(mysqli $conn, int $requiredRole): array
{
    if (empty($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== $requiredRole) {
        header('Location: login.php');
        exit;
    }
    $stmt = $conn->prepare(
        'SELECT u.user_id, u.username, u.branch_id, b.branch_name, r.role_name
         FROM users u
         LEFT JOIN branches b ON b.branch_id = u.branch_id
         LEFT JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ? AND u.status = "Active" LIMIT 1'
    );
    $id = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || empty($user['branch_id'])) {
        throw new RuntimeException('Your account has no active branch assignment.');
    }
    return $user;
}

function workflowAudit(mysqli $conn, int $userId, string $branchId, string $action, string $module): void
{
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $userId, $branchId, $action, $module);
    $stmt->execute();
    $stmt->close();
}

function workflowNotifyRole(mysqli $conn, string $branchId, int $roleId, string $title, string $message, string $type): void
{
    $find = $conn->prepare('SELECT user_id FROM users WHERE branch_id = ? AND role_id = ? AND status = "Active"');
    $find->bind_param('si', $branchId, $roleId);
    $find->execute();
    $users = $find->get_result();
    $insert = $conn->prepare(
        'INSERT INTO notifications (user_id, title, message, notification_type, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())'
    );
    while ($row = $users->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        $insert->bind_param('isss', $uid, $title, $message, $type);
        $insert->execute();
    }
    $insert->close();
    $find->close();
}

/**
 * Clinic rule: a patient may return up to 7 days late for a dose. Within that
 * window the dose is recorded on the actual date and every remaining dose moves
 * forward by the same delay. More than 7 days late starts a new cycle from Day 0
 * (old records are archived, never deleted).
 */
const WORKFLOW_LATE_DOSE_WINDOW_DAYS = 7;

/**
 * Vaccination regimen (stored as treatment_profile) => day offsets from Day 0.
 *
 * The stored dose_number keeps its historical day-slot meaning
 * (1=D0, 2=D3, 3=D7, 4=D14, 5=D21, 6=D28). Registry dose flags, the Nurse
 * vaccination form and older records depend on it, so it is never renumbered.
 * Pages show the patient-facing order with workflowDoseDisplay() instead.
 *
 * PREP and BOOSTER are legacy values without a route; PREP_ID/PREP_IM and
 * BOOSTER_ID/BOOSTER_IM record the route chosen by the nurse.
 */
function workflowScheduleStages(string $profile): array
{
    $pepId = [1 => 0, 2 => 3, 3 => 7, 6 => 28];
    $pepIm = [1 => 0, 2 => 3, 3 => 7, 4 => 14, 6 => 28];
    $prep = [1 => 0, 3 => 7, 5 => 21];
    $booster = [1 => 0, 2 => 3];
    $profiles = [
        'PEP_ID' => $pepId,
        'PEP_IM' => $pepIm,
        'PREP' => $prep,
        'PREP_ID' => $prep,
        'PREP_IM' => $prep,
        'BOOSTER' => $booster,
        'BOOSTER_ID' => $booster,
        'BOOSTER_IM' => $booster,
        'OTHER' => [1 => 0],
    ];
    return $profiles[$profile] ?? [];
}

/** Regimens a nurse can choose for a new anti-rabies schedule. */
function workflowRegimenOptions(): array
{
    return [
        'PEP_ID' => 'PEP - Intradermal (ID): D0, D3, D7, D28',
        'PEP_IM' => 'PEP - Intramuscular (IM): D0, D3, D7, D14, D28',
        'PREP_ID' => 'PrEP - Intradermal (ID): D0, D7, D21',
        'PREP_IM' => 'PrEP - Intramuscular (IM): D0, D7, D21',
        'BOOSTER_ID' => 'Booster - Intradermal (ID): D0, D3',
        'BOOSTER_IM' => 'Booster - Intramuscular (IM): D0, D3',
    ];
}

/** Display name for any stored regimen, including legacy values. */
function workflowProfileLabel(?string $profile, ?string $storedRoute = null): string
{
    $profile = strtoupper(trim((string)$profile));
    $labels = [
        'PEP_ID' => 'PEP - Intradermal (ID)',
        'PEP_IM' => 'PEP - Intramuscular (IM)',
        'PREP_ID' => 'PrEP - Intradermal (ID)',
        'PREP_IM' => 'PrEP - Intramuscular (IM)',
        'BOOSTER_ID' => 'Booster - Intradermal (ID)',
        'BOOSTER_IM' => 'Booster - Intramuscular (IM)',
        'OTHER' => 'Not anti-rabies',
    ];
    if (isset($labels[$profile])) {
        return $labels[$profile];
    }
    if ($profile === 'PREP' || $profile === 'BOOSTER') {
        $route = workflowProfileRoute($profile, $storedRoute);
        $name = $profile === 'PREP' ? 'PrEP' : 'Booster';
        return $route === 'ID' ? $name . ' - Intradermal (ID)'
            : ($route === 'IM' ? $name . ' - Intramuscular (IM)' : $name);
    }
    return $profile !== '' ? $profile : 'Not set';
}

/** Route (ID/IM) implied by the regimen; legacy PREP/BOOSTER fall back to the stored route. */
function workflowProfileRoute(?string $profile, ?string $storedRoute = null): string
{
    $profile = strtoupper(trim((string)$profile));
    if (str_ends_with($profile, '_ID')) return 'ID';
    if (str_ends_with($profile, '_IM')) return 'IM';
    $route = strtoupper(trim((string)$storedRoute));
    if (str_starts_with($route, 'ID') || str_contains($route, 'INTRADERMAL')) return 'ID';
    if (str_starts_with($route, 'IM') || str_contains($route, 'INTRAMUSCULAR')) return 'IM';
    return '';
}

function workflowIsBoosterProfile(?string $profile): bool
{
    return str_starts_with(strtoupper(trim((string)$profile)), 'BOOSTER');
}

/**
 * Default anti-rabies sites per session for the regimen: ID regular = 2 sites
 * (both arms), Booster ID = 1 site, IM = 0 meaning one full vial. The nurse can
 * still change it to the amount actually given.
 */
function workflowProfileDefaultSites(?string $profile, ?string $storedRoute = null): int
{
    $route = workflowProfileRoute($profile, $storedRoute);
    if ($route === 'IM') return 0;
    if ($route === 'ID') return workflowIsBoosterProfile($profile) ? 1 : 2;
    return 2;
}

function workflowDoseLabel(int $doseNumber): string
{
    return [1 => 'D0', 2 => 'D3', 3 => 'D7', 4 => 'D14', 5 => 'D21', 6 => 'D28'][$doseNumber] ?? ('Dose ' . $doseNumber);
}

/**
 * Map stored dose numbers to the order the patient receives them (1, 2, 3...).
 * Uses the regimen's stages plus any dose numbers that actually exist for the
 * case, so older records without a regimen still number in order.
 */
function workflowDoseSequenceMap(?string $profile, array $doseNumbers = []): array
{
    $numbers = array_keys(workflowScheduleStages(strtoupper(trim((string)$profile))));
    if (strtoupper(trim((string)$profile)) === 'OTHER') {
        $numbers = [];
    }
    foreach ($doseNumbers as $number) {
        if ((int)$number >= 1) $numbers[] = (int)$number;
    }
    $numbers = array_values(array_unique(array_map('intval', $numbers)));
    sort($numbers);
    $map = [];
    foreach ($numbers as $index => $number) {
        $map[$number] = $index + 1;
    }
    return $map;
}

/** Patient-facing dose label, e.g. "Dose 4 - D28". dose_number 0 is a one-time product. */
function workflowDoseDisplay(int $doseNumber, array $sequenceMap): string
{
    if ($doseNumber <= 0) return 'One-time';
    $sequence = $sequenceMap[$doseNumber] ?? $doseNumber;
    return 'Dose ' . $sequence . ' - ' . workflowDoseLabel($doseNumber);
}

/** Latest nurse-confirmed regimen for a case: ['profile' => ?string, 'route' => ?string]. */
function workflowCaseProfile(mysqli $conn, int $caseId): array
{
    $stmt = $conn->prepare(
        'SELECT treatment_profile, route FROM clinical_assessments
         WHERE case_id = ? ORDER BY updated_at DESC, assessment_id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $caseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'profile' => $row ? (string)$row['treatment_profile'] : null,
        'route' => $row ? (string)($row['route'] ?? '') : null,
    ];
}

/** Add dose_sequence and dose_display to vaccination rows of ONE case. */
function workflowAnnotateCaseDoses(array $rows, ?string $profile): array
{
    $numbers = [];
    foreach ($rows as $row) $numbers[] = (int)($row['dose_number'] ?? 0);
    $map = workflowDoseSequenceMap($profile, $numbers);
    foreach ($rows as &$row) {
        $dose = (int)($row['dose_number'] ?? 0);
        $row['dose_sequence'] = $dose > 0 ? ($map[$dose] ?? $dose) : 0;
        $row['dose_day_label'] = $dose > 0 ? workflowDoseLabel($dose) : 'One-time';
        $row['dose_display'] = workflowDoseDisplay($dose, $map);
    }
    unset($row);
    return $rows;
}

/** Cached information_schema check, so pages degrade safely before a migration is applied. */
function workflowColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
        $stmt->close();
    }
    return $cache[$key];
}

/** True when an ENUM (or non-ENUM) column accepts the value. */
function workflowEnumAllows(mysqli $conn, string $table, string $column, string $value): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = $conn->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = (string)($stmt->get_result()->fetch_assoc()['COLUMN_TYPE'] ?? '');
        $stmt->close();
    }
    $type = $cache[$key];
    if (!str_starts_with(strtolower($type), 'enum(')) return $type !== '';
    return str_contains($type, "'" . str_replace("'", "''", $value) . "'");
}

/**
 * Regimen value to store in clinical_assessments.treatment_profile. Before
 * sources/migrate_clinical_assessment_fields.php extends the ENUM, PREP_ID/BOOSTER_IM etc.
 * are saved as the legacy PREP/BOOSTER and the route column keeps ID/IM.
 */
function workflowStoredProfile(mysqli $conn, string $profile): string
{
    if (workflowEnumAllows($conn, 'clinical_assessments', 'treatment_profile', $profile)) {
        return $profile;
    }
    if (str_starts_with($profile, 'PREP_')) return 'PREP';
    if (str_starts_with($profile, 'BOOSTER_')) return 'BOOSTER';
    return $profile;
}

/**
 * Classify a dose that is $daysLate days past its scheduled date.
 * 'on_time' (0 or early), 'catchup' (1-7 days), 'restart' (more than 7 days).
 */
function workflowLateDoseStatus(int $daysLate): array
{
    $window = WORKFLOW_LATE_DOSE_WINDOW_DAYS;
    if ($daysLate <= 0) {
        return ['classification' => 'on_time', 'label' => 'On schedule',
            'description' => 'The dose is due today or later.'];
    }
    if ($daysLate <= $window) {
        return ['classification' => 'catchup', 'label' => 'Missed - Catch-up Eligible',
            'description' => $daysLate . ' day' . ($daysLate === 1 ? '' : 's') . ' late. Record the dose on the actual date; '
                . 'the remaining doses move forward by the same number of days (allowed up to ' . $window . ' days late).'];
    }
    return ['classification' => 'restart', 'label' => 'New Cycle Required (Day 0)',
        'description' => $daysLate . ' days late, more than the ' . $window . '-day limit. Re-assess the patient: '
            . 'a new vaccination cycle starts from Day 0 and the old records are archived for history.'];
}

/** Scheduled dates that have passed become Missed. Completed records are not touched. */
function workflowMarkMissedSchedules(mysqli $conn, string $branchId, int $userId): int
{
    $stmt = $conn->prepare(
        "UPDATE vaccination_records
         SET vaccination_status = 'Missed'
         WHERE branch_id = ?
           AND is_archived = 0
           AND vaccination_status = 'Scheduled'
           AND scheduled_date IS NOT NULL
           AND scheduled_date < CURDATE()"
    );
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $marked = (int)$stmt->affected_rows;
    $stmt->close();
    if ($marked > 0) {
        workflowAudit($conn, $userId, $branchId,
            'Automatically marked ' . $marked . ' overdue vaccination schedule(s) as Missed', 'Vaccination Follow-up');
    }
    return $marked;
}

/**
 * Move every remaining (not yet given) dose of a case forward by $delayDays
 * after a dose scheduled on $originalDate was given late. Shifted dates that
 * are still in the past stay Missed; the others become Scheduled again.
 * Returns the number of dose rows moved.
 */
function workflowShiftRemainingSchedule(
    mysqli $conn,
    int $patientId,
    int $caseId,
    string $branchId,
    string $originalDate,
    int $delayDays,
    int $userId
): int {
    if ($delayDays < 1 || $delayDays > WORKFLOW_LATE_DOSE_WINDOW_DAYS) {
        return 0;
    }
    $note = 'Moved ' . $delayDays . ' day' . ($delayDays === 1 ? '' : 's') . ' later because the dose due '
        . $originalDate . ' was given late.';
    // vaccination_status is assigned first so it is computed from the old date.
    $stmt = $conn->prepare(
        "UPDATE vaccination_records
         SET vaccination_status = CASE
                 WHEN DATE_ADD(scheduled_date, INTERVAL ? DAY) >= CURDATE() THEN 'Scheduled'
                 ELSE 'Missed'
             END,
             scheduled_date = DATE_ADD(scheduled_date, INTERVAL ? DAY),
             remarks = CONCAT_WS(' | ', NULLIF(remarks, ''), ?)
         WHERE patient_id = ?
           AND case_id = ?
           AND branch_id = ?
           AND is_archived = 0
           AND item_id IS NULL
           AND vaccination_status IN ('Scheduled', 'Missed')
           AND scheduled_date > ?"
    );
    $stmt->bind_param('iisiiss', $delayDays, $delayDays, $note, $patientId, $caseId, $branchId, $originalDate);
    $stmt->execute();
    $moved = (int)$stmt->affected_rows;
    $stmt->close();
    if ($moved > 0) {
        workflowAudit($conn, $userId, $branchId,
            'Moved ' . $moved . ' remaining dose(s) of case ' . $caseId . ' forward by ' . $delayDays
                . ' day(s) after a late dose (due ' . $originalDate . ')', 'Vaccination Follow-up');
    }
    return $moved;
}

function workflowCreateSchedule(
    mysqli $conn,
    int $visitId,
    int $patientId,
    int $caseId,
    string $branchId,
    string $profile,
    string $d0Date,
    int $nurseId
): void {
    $stages = workflowScheduleStages($profile);
    if (!$stages) {
        throw new RuntimeException('Unsupported vaccination regimen.');
    }

    // Active rows of the current cycle for this case.
    $existingStmt = $conn->prepare(
        "SELECT vaccination_id, dose_number, scheduled_date, vaccination_status, item_id, treatment_profile
         FROM vaccination_records
         WHERE case_id = ? AND branch_id = ? AND is_archived = 0 AND dose_number >= 1"
    );
    $existingStmt->bind_param('is', $caseId, $branchId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $existingStmt->close();

    /*
     * Re-saving the assessment on a follow-up visit with the same regimen and
     * Day 0 keeps the current cycle, so dates moved by a late dose are not
     * reset. A changed regimen or Day 0 replaces the unfinished placeholders.
     * Completed doses are never scheduled again.
     */
    $sameCycle = false;
    foreach ($existing as $row) {
        if ((int)$row['dose_number'] === 1 && (string)$row['scheduled_date'] === $d0Date) {
            $sameCycle = true;
        }
    }
    foreach ($existing as $row) {
        $rowProfile = (string)($row['treatment_profile'] ?? '');
        if ($row['item_id'] === null && $rowProfile !== '' && $rowProfile !== $profile) {
            $sameCycle = false;
        }
    }

    $covered = [];
    foreach ($existing as $row) {
        $dose = (int)$row['dose_number'];
        if ($row['vaccination_status'] === 'Completed' || $sameCycle) {
            $covered[$dose] = true;
        }
    }

    if (!$sameCycle) {
        // Preserve completed administrations. Replace only unfinished placeholders.
        $archive = $conn->prepare(
            "UPDATE vaccination_records
             SET is_archived = 1, archived_at = NOW(), archived_by = ?
             WHERE case_id = ? AND branch_id = ? AND is_archived = 0
               AND (vaccination_status = 'Scheduled'
                    OR (vaccination_status = 'Missed' AND item_id IS NULL))"
        );
        $archive->bind_param('iis', $nurseId, $caseId, $branchId);
        $archive->execute();
        $archive->close();
    }

    $insert = $conn->prepare(
        "INSERT INTO vaccination_records
         (patient_id, case_id, visit_id, item_id, vaccine_name, unit_id, quantity_used,
          branch_id, dose_number, treatment_profile, scheduled_date, vaccination_status,
          scheduled_by, is_final_dose, remarks, nurse_id)
         VALUES (?, ?, ?, NULL, NULL, NULL, 0, ?, ?, ?, ?, 'Scheduled', ?, ?, ?, NULL)"
    );

    $lastDose = (int)array_key_last($stages);
    foreach ($stages as $doseNumber => $offset) {
        if (isset($covered[(int)$doseNumber])) {
            continue;
        }
        $scheduledDate = date('Y-m-d', strtotime($d0Date . ' +' . $offset . ' days'));
        $isFinal = ((int)$doseNumber === $lastDose) ? 1 : 0;
        $remarks = 'Schedule created from nurse-confirmed ' . $profile . ' regimen.';
        $dose = (int)$doseNumber;
        $insert->bind_param(
            'iiisissiis',
            $patientId,
            $caseId,
            $visitId,
            $branchId,
            $dose,
            $profile,
            $scheduledDate,
            $nurseId,
            $isFinal,
            $remarks
        );
        $insert->execute();
    }
    $insert->close();
}

/**
 * Record doses the patient already received at another clinic (for example
 * "D0 & D3 @ other clinic"). The matching schedule rows created from the
 * original Day 0 become Completed outside doses: no product, no quantity and no
 * stock movement. Only the remaining doses stay Scheduled.
 *
 * $outsideDoses maps stored dose_number => administration date (Y-m-d).
 */
function workflowRecordOutsideDoses(
    mysqli $conn,
    int $patientId,
    int $caseId,
    string $branchId,
    array $outsideDoses,
    string $clinicName,
    int $nurseId
): int {
    $clinicName = trim($clinicName);
    if ($clinicName === '') {
        throw new RuntimeException('Enter the name of the clinic where the earlier doses were given.');
    }
    $recorded = 0;
    $update = $conn->prepare(
        "UPDATE vaccination_records
         SET vaccination_status = 'Completed',
             date_administered = ?,
             vaccine_name = ?,
             quantity_used = 0,
             remarks = ?,
             nurse_id = ?
         WHERE patient_id = ? AND case_id = ? AND branch_id = ? AND dose_number = ?
           AND is_archived = 0 AND item_id IS NULL AND vaccination_status IN ('Scheduled', 'Missed')
         ORDER BY vaccination_id ASC
         LIMIT 1"
    );
    foreach ($outsideDoses as $doseNumber => $date) {
        $doseNumber = (int)$doseNumber;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            throw new RuntimeException('Enter a valid past date for ' . workflowDoseLabel($doseNumber) . ' given at the other clinic.');
        }
        $vaccineName = 'Given at other clinic';
        $remarks = 'Outside dose: ' . workflowDoseLabel($doseNumber) . ' given at ' . $clinicName
            . ' on ' . $date . '. No stock used at this branch.';
        $update->bind_param('sssiiisi', $date, $vaccineName, $remarks, $nurseId, $patientId, $caseId, $branchId, $doseNumber);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException(workflowDoseLabel($doseNumber) . ' is not part of the selected regimen.');
        }
        $recorded++;
    }
    $update->close();
    return $recorded;
}

function workflowFlash(string $type, string $message): void
{
    $_SESSION['workflow_flash'] = ['type' => $type, 'message' => $message];
}

function workflowTakeFlash(): ?array
{
    $flash = $_SESSION['workflow_flash'] ?? null;
    unset($_SESSION['workflow_flash']);
    return is_array($flash) ? $flash : null;
}

function workflowH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
