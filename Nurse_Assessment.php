<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/inventory_stock_helpers.php';
$stockTotalsSql = inventoryStockTotalsSql();
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';
require_once 'sources/inventory_unit_helpers.php';
require_once 'sources/forecast_training_helper.php';
require_once 'sources/daily_inventory_helpers.php';
require_once 'sources/patient_display_helper.php';

// ============================================================
// AUTHENTICATED NURSE
// ============================================================
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Get unread notification count.
$notification_count = getUnreadNotificationCount($conn, $user_id);

// Require Nurse role (role_id = 3).
$user = workflowRequireUser($conn, 3);

$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$csrf = workflowCsrfToken();

/*
 * ============================================================
 * CLINIC MISSED-DOSE RULE (see WORKFLOW_LATE_DOSE_WINDOW_DAYS)
 * ============================================================
 *
 * 1-7 days late:
 *      The dose is recorded on the actual date in Vaccination and every
 *      remaining scheduled dose moves forward by the same number of days.
 *
 * More than 7 days late:
 *      The old schedule is not continued. When the Nurse re-assesses the
 *      patient, a new treatment cycle starts from Day 0.
 *
 * Previous vaccination records are archived for history, auditability and
 * traceability. They are never deleted.
 */
$catchUpWindowDays = WORKFLOW_LATE_DOSE_WINDOW_DAYS;

/**
 * Load one Medical Supplies item from the database.
 * The browser is never trusted for unit/conversion metadata.
 */
function assessmentGetMedicalSupplyItem(
    mysqli $conn,
    int $itemId
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            i.item_id,
            i.item_name,
            i.unit_id,
            u.unit_name,
            c.category_name,
            COALESCE(NULLIF(i.base_unit_label, ''), u.unit_name) AS base_unit_label,
            COALESCE(NULLIF(i.display_unit_label, ''), u.unit_name) AS display_unit_label,
            COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base
         FROM inventory_items i
         INNER JOIN units u
            ON u.unit_id = i.unit_id
         INNER JOIN inventory_categories c
            ON c.category_id = i.category_id
         WHERE i.item_id = ?
           AND c.category_name = 'Medical Supplies'
           AND i.item_name NOT LIKE '%Default%'
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare the medical supply lookup.'
        );
    }

    $stmt->bind_param('i', $itemId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Deduct actual administered quantity using FEFO.
 *
 * Quantities are always stored in the item's base unit, such as:
 * - mL for configured liquid vial products
 * - site for SPEEDA
 * - Ampule / Piece / Dose when that is the base unit
 *
 * This function records what the nurse actually entered. It does not
 * determine or recommend a clinical dose.
 */
function assessmentDeductStockFEFO(
    mysqli $conn,
    int $itemId,
    string $branchId,
    float $quantityNeeded
): array {
    if ($quantityNeeded <= 0) {
        throw new RuntimeException(
            'Actual quantity administered must be greater than zero.'
        );
    }

    $stmt = $conn->prepare(
        "SELECT
            stock_id,
            batch_lot_no,
            quantity_available,
            expiration_date
         FROM inventory_stocks
         WHERE item_id = ?
           AND branch_id = ?
           AND quantity_available > 0
           AND (
                expiration_date IS NULL
                OR expiration_date >= CURDATE()
           )
         ORDER BY
            CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END ASC,
            expiration_date ASC,
            stock_id ASC
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare stock deduction.'
        );
    }

    $stmt->bind_param(
        'is',
        $itemId,
        $branchId
    );

    $stmt->execute();
    $result = $stmt->get_result();

    $stocks = [];
    $totalAvailable = 0.0;

    while ($row = $result->fetch_assoc()) {
        $stocks[] = $row;
        $totalAvailable += (float)$row['quantity_available'];
    }

    $stmt->close();

    if (($totalAvailable + 0.00005) < $quantityNeeded) {
        throw new RuntimeException(
            'Insufficient non-expired stock. Available base quantity: '
            . inventoryFormatNumber($totalAvailable)
            . '.'
        );
    }

    $remaining = round($quantityNeeded, 4);
    $usedBatches = [];

    foreach ($stocks as $stock) {
        if ($remaining <= 0.00005) {
            break;
        }

        $available = (float)$stock['quantity_available'];
        $take = round(min($available, $remaining), 4);
        $stockId = (int)$stock['stock_id'];

        $update = $conn->prepare(
            "UPDATE inventory_stocks
             SET
                quantity_available = quantity_available - ?,
                last_updated = CURRENT_TIMESTAMP
             WHERE stock_id = ?"
        );

        if (!$update) {
            throw new RuntimeException(
                'Unable to prepare stock update.'
            );
        }

        $update->bind_param(
            'di',
            $take,
            $stockId
        );

        if (!$update->execute()) {
            $error = $update->error;
            $update->close();

            throw new RuntimeException(
                'Unable to deduct administered stock: ' . $error
            );
        }

        $update->close();

        $usedBatches[] = [
            'batch_lot_no' =>
                trim((string)($stock['batch_lot_no'] ?? '')) !== ''
                    ? (string)$stock['batch_lot_no']
                    : 'N/A',
            'quantity' => $take,
            'expiration_date' => $stock['expiration_date'] ?? null
        ];

        $remaining = round($remaining - $take, 4);
    }

    return $usedBatches;
}

function assessmentBatchSummary(
    array $batches,
    array $item
): string {
    $parts = [];
    $baseUnit = inventoryBaseUnitLabel($item);

    foreach ($batches as $batch) {
        $text =
            (string)$batch['batch_lot_no']
            . ': '
            . inventoryFormatNumber(
                (float)$batch['quantity']
            )
            . ' '
            . $baseUnit;

        if (!empty($batch['expiration_date'])) {
            $text .=
                ' (exp '
                . (string)$batch['expiration_date']
                . ')';
        }

        $parts[] = $text;
    }

    return implode(', ', $parts);
}

/** Fixed choices shared by the form and server-side validation. */
const ASSESSMENT_ANIMAL_OPTIONS = ['Dog', 'Cat', 'Monkey', 'Cow', 'Carabao', 'Bat', 'Pig', 'Rat', 'Not Applicable'];
const ASSESSMENT_ANIMAL_STATUS_OPTIONS = ['Alive/Healthy', 'Sick', 'Died', 'Unknown', 'Not Applicable'];
const ASSESSMENT_WOUND_TYPES = ['Abrasion', 'Punctured', 'Scratch', 'Avulsion'];
const ASSESSMENT_BITE_TYPES = ['Bite', 'Non-Bite'];
const ASSESSMENT_ACTIVE_REGIMENS = ['PVRV TRC SPEEDA', 'PVRV TRC ABHAYRAB'];

/**
 * Validate body-map coordinates independently of the browser. The nurse only
 * clicks the location; the written site stays in Site of Bite, so a marker
 * label is optional (older markers keep their saved labels).
 */
function assessmentBiteMarkers($raw): string {
    if (!is_string($raw) || strlen($raw) > 12000) {
        throw new RuntimeException('Invalid bite-location markers.');
    }
    $markers = json_decode($raw, true);
    if (!is_array($markers) || !array_is_list($markers) || count($markers) > 30) {
        throw new RuntimeException('Use no more than 30 bite-location markers.');
    }
    $clean = [];
    foreach ($markers as $marker) {
        $label = $marker['label'] ?? '';
        if (!is_array($marker)
            || !isset($marker['x'], $marker['y'])
            || !is_numeric($marker['x']) || !is_numeric($marker['y'])
            || !is_string($label)
            || strlen($label) > 120
            || !is_finite((float)$marker['x']) || !is_finite((float)$marker['y'])
            || (float)$marker['x'] < 0 || (float)$marker['x'] > 100
            || (float)$marker['y'] < 0 || (float)$marker['y'] > 100) {
            throw new RuntimeException('Invalid bite-location marker.');
        }
        $view = $marker['view'] ?? 'front';
        if (!in_array($view, ['front', 'back'], true)) {
            throw new RuntimeException('Invalid bite-location marker view.');
        }
        $clean[] = [
            'x' => round((float)$marker['x'], 4),
            'y' => round((float)$marker['y'], 4),
            'view' => $view,
            'label' => trim($label)
        ];
    }
    return json_encode($clean, JSON_THROW_ON_ERROR);
}

/** A select with an "Other (specify)" text box: return the chosen or typed value. */
function assessmentChoice(array $payload, string $field, array $allowed, string $otherField): string
{
    $value = trim((string)($payload[$field] ?? ''));
    if ($value === 'Others' || $value === 'Other') {
        $value = trim((string)($payload[$otherField] ?? ''));
        if ($value === '') {
            throw new RuntimeException('Specify the value for the "Other" choice.');
        }
        return mb_substr($value, 0, 100);
    }
    if ($value !== '' && !in_array($value, $allowed, true)) {
        // Older records may hold free text; keep it when it is resubmitted unchanged.
        return mb_substr($value, 0, 100);
    }
    return $value;
}

function assessmentValidDate(string $date, string $message): void
{
    if ($date !== '' && DateTime::createFromFormat('Y-m-d', $date)?->format('Y-m-d') !== $date) {
        throw new RuntimeException($message);
    }
}

/**
 * Read and validate the exposure fields shared by the anti-rabies and the
 * one-time (non-ARV) save paths. Animal fields are optional, so non-bite and
 * non-rabies services (Hepa B, Flu, PPD, rusty-object punctures) save without them.
 */
function assessmentCollectExposure(array $payload): array
{
    $history = trim((string)($payload['exposure_history'] ?? ''));
    if ($history === '') {
        throw new RuntimeException('History of incident/exposure is required.');
    }
    $exposureDate = trim((string)($payload['date_of_exposure'] ?? ''));
    assessmentValidDate($exposureDate, 'Enter a valid exposure date.');

    $biteType = trim((string)($payload['bite_type'] ?? ''));
    if ($biteType !== '' && !in_array($biteType, ASSESSMENT_BITE_TYPES, true)) {
        throw new RuntimeException('Select a valid Type of Bite.');
    }
    $woundType = trim((string)($payload['wound_type'] ?? ''));
    if ($woundType !== '' && !in_array($woundType, ASSESSMENT_WOUND_TYPES, true)) {
        throw new RuntimeException('Select a valid Type of Wound.');
    }
    $regimen = trim((string)($payload['active_regimen'] ?? ''));
    if ($regimen === 'Other') {
        $regimen = trim((string)($payload['active_regimen_other'] ?? ''));
        if ($regimen === '') throw new RuntimeException('Specify the Active Regimen.');
    }

    return [
        'exposure_history' => $history,
        'date_of_exposure' => $exposureDate,
        'exposure_site' => mb_substr(trim((string)($payload['exposure_site'] ?? '')), 0, 255),
        'bite_markers' => assessmentBiteMarkers($payload['bite_markers'] ?? '[]'),
        'animal_type' => assessmentChoice($payload, 'animal_type', ASSESSMENT_ANIMAL_OPTIONS, 'animal_type_other'),
        'animal_status' => assessmentChoice($payload, 'animal_status', ASSESSMENT_ANIMAL_STATUS_OPTIONS, 'animal_status_other'),
        'bite_category' => trim((string)($payload['bite_category'] ?? '')),
        'bite_type' => $biteType,
        'wound_type' => $woundType,
        'active_regimen' => mb_substr($regimen, 0, 100),
        // Hidden in the form (redundant fields); existing values are posted back unchanged.
        'important_concerns' => trim((string)($payload['important_concerns'] ?? '')),
        'instructions_given' => trim((string)($payload['instructions_given'] ?? '')),
        'chart_notes' => trim((string)($payload['chart_notes'] ?? '')),
    ];
}

/**
 * Insert or update the visit's clinical assessment. Columns added by
 * sources/migrate_clinical_assessment_fields.php are written only when they exist.
 */
function assessmentUpsert(mysqli $conn, array $data): void
{
    $optional = ['previous_vaccination_date', 'outside_clinic_name', 'wound_type', 'bite_type'];
    $columns = ['visit_id' => 'i', 'patient_id' => 'i', 'case_id' => 'i', 'branch_id' => 's', 'nurse_id' => 'i',
        'exposure_history' => 's', 'date_of_exposure' => 's', 'exposure_site' => 's', 'animal_type' => 's',
        'animal_status' => 's', 'bite_category' => 's', 'treatment_profile' => 's', 'route' => 's',
        'active_regimen' => 's', 'important_concerns' => 's', 'instructions_given' => 's', 'chart_notes' => 's',
        'd0_date' => 's', 'bite_markers' => 's'];
    foreach ($optional as $column) {
        if (workflowColumnExists($conn, 'clinical_assessments', $column)) $columns[$column] = 's';
    }
    $nullable = ['date_of_exposure', 'previous_vaccination_date', 'outside_clinic_name', 'wound_type', 'bite_type'];
    $placeholders = [];
    $updates = [];
    $values = [];
    foreach ($columns as $column => $type) {
        $placeholders[] = in_array($column, $nullable, true) ? "NULLIF(?, '')" : '?';
        if (!in_array($column, ['visit_id', 'patient_id', 'case_id', 'branch_id'], true)) {
            $updates[] = "$column = VALUES($column)";
        }
        $values[] = $type === 'i' ? (int)($data[$column] ?? 0) : (string)($data[$column] ?? '');
    }
    $sql = 'INSERT INTO clinical_assessments (' . implode(', ', array_keys($columns)) . ')
            VALUES (' . implode(', ', $placeholders) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', updated_at = NOW()';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the clinical assessment.');
    }
    $stmt->bind_param(implode('', $columns), ...$values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save the clinical assessment: ' . $error);
    }
    $stmt->close();
}

/** Copy the nurse-confirmed exposure details to the case and mark the visit Under Assessment. */
function assessmentUpdateCaseAndVisit(mysqli $conn, array $data, int $visitId, int $userId, string $branchId): void
{
    $caseUpdate = $conn->prepare(
        "UPDATE animal_bite_cases
         SET
            animal_type = NULLIF(?, ''),
            bite_location = NULLIF(?, ''),
            bite_category = NULLIF(?, ''),
            animal_status = NULLIF(?, ''),
            date_of_bite = NULLIF(?, ''),
            remarks = NULLIF(?, '')
         WHERE case_id = ?
           AND branch_id = ?"
    );
    if (!$caseUpdate) {
        throw new RuntimeException('Unable to prepare the case update.');
    }
    $caseId = (int)$data['case_id'];
    $caseUpdate->bind_param(
        'ssssssis',
        $data['animal_type'],
        $data['exposure_site'],
        $data['bite_category'],
        $data['animal_status'],
        $data['date_of_exposure'],
        $data['chart_notes'],
        $caseId,
        $branchId
    );
    $caseUpdate->execute();
    $caseUpdate->close();

    $visitUpdate = $conn->prepare(
        "UPDATE patient_visits
         SET
            workflow_status = 'Under Assessment',
            assigned_nurse = ?,
            assessment_started_at = COALESCE(assessment_started_at, NOW()),
            updated_at = NOW()
         WHERE visit_id = ?
           AND branch_id = ?"
    );
    if (!$visitUpdate) {
        throw new RuntimeException('Unable to prepare the visit update.');
    }
    $visitUpdate->bind_param('iis', $userId, $visitId, $branchId);
    $visitUpdate->execute();
    $visitUpdate->close();
}

/** Existing d0_date for a visit, or today as the technical placeholder (d0_date is NOT NULL). */
function assessmentExistingD0(mysqli $conn, int $visitId): string
{
    $existing = $conn->prepare('SELECT d0_date FROM clinical_assessments WHERE visit_id = ? LIMIT 1');
    $existing->bind_param('i', $visitId);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    $existing->close();
    return !empty($row['d0_date']) ? (string)$row['d0_date'] : date('Y-m-d');
}

/** Retire the still-Scheduled anti-rabies rows of THIS visit. Completed/Missed history is kept. */
function assessmentArchiveVisitSchedule(mysqli $conn, int $visitId, int $userId, string $branchId): void
{
    $archive = $conn->prepare(
        "UPDATE vaccination_records
         SET is_archived = 1, archived_at = NOW(), archived_by = ?
         WHERE visit_id = ? AND branch_id = ? AND vaccination_status = 'Scheduled' AND is_archived = 0"
    );
    if ($archive) {
        $archive->bind_param('iis', $userId, $visitId, $branchId);
        $archive->execute();
        $archive->close();
    }
}

/**
 * Persist an assessment explicitly marked as NOT an anti-rabies schedule.
 *
 * clinical_assessments already supports treatment_profile='OTHER', so no
 * schedule is generated from it. d0_date is NOT NULL in the existing schema;
 * the stored value is kept or today's date is used only as a placeholder.
 */
function assessmentSaveNonArvAssessment(
    mysqli $conn,
    array $visit,
    int $visitId,
    int $userId,
    string $branchId,
    array $payload
): void {
    $data = assessmentCollectExposure($payload);
    assessmentArchiveVisitSchedule($conn, $visitId, $userId, $branchId);
    $data += [
        'visit_id' => $visitId,
        'patient_id' => (int)$visit['patient_id'],
        'case_id' => (int)$visit['case_id'],
        'branch_id' => $branchId,
        'nurse_id' => $userId,
        'treatment_profile' => 'OTHER',
        'route' => '',
        'd0_date' => assessmentExistingD0($conn, $visitId),
    ];
    assessmentUpsert($conn, $data);
    assessmentUpdateCaseAndVisit($conn, $data, $visitId, $userId, $branchId);
}

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitId = 0;

    try {
        workflowVerifyCsrf();

        $action = (string)($_POST['action'] ?? '');

        /*
         * Defensive fallback for the one-time/non-ARV Administer button.
         * A disabled submit button is not included in the browser's POST
         * payload. If the client-side loading state disables that button
         * before serialization, recover the intended action from the
         * non-ARV fields instead of falling through to "Unknown action".
         */
        if (
            $action === ''
            && (string)($_POST['not_anti_rabies'] ?? '') === '1'
        ) {
            $fallbackItemIds = $_POST['non_rabies_item_id'] ?? [];
            $fallbackQuantities = $_POST['non_rabies_quantity_base'] ?? [];

            if (!is_array($fallbackItemIds)) {
                $fallbackItemIds = [$fallbackItemIds];
            }
            if (!is_array($fallbackQuantities)) {
                $fallbackQuantities = [$fallbackQuantities];
            }

            foreach ($fallbackItemIds as $index => $fallbackItemId) {
                if (
                    (int)$fallbackItemId > 0
                    && (float)($fallbackQuantities[$index] ?? 0) > 0
                ) {
                    $action = 'administer_non_rabies';
                    break;
                }
            }
        }

        $visitId = (int)($_POST['visit_id'] ?? 0);

        if ($visitId <= 0) {
            throw new RuntimeException('Select a valid patient visit.');
        }

        $visitCheck = $conn->prepare(
            "SELECT
                v.patient_id,
                v.case_id,
                v.workflow_status,
                p.full_name,
                c.case_number
             FROM patient_visits v
             INNER JOIN patients p
                ON p.patient_id = v.patient_id
             INNER JOIN animal_bite_cases c
                ON c.case_id = v.case_id
             WHERE v.visit_id = ?
               AND v.branch_id = ?
             LIMIT 1"
        );

        $visitCheck->bind_param('is', $visitId, $branchId);
        $visitCheck->execute();

        $visit = $visitCheck->get_result()->fetch_assoc();
        $visitCheck->close();

        if (!$visit) {
            throw new RuntimeException('Visit was not found in your branch.');
        }

        // ========================================================
        // SAVE NURSE ASSESSMENT
        // ========================================================
        if ($action === 'save_assessment') {
            if (!in_array(
                $visit['workflow_status'],
                ['Waiting for Nurse', 'Under Assessment', 'Treatment Completed'],
                true
            )) {
                throw new RuntimeException(
                    'This visit is no longer available for assessment.'
                );
            }

            $data = assessmentCollectExposure($_POST);
            $isNonRabies = isset($_POST['not_anti_rabies'])
                && (string)$_POST['not_anti_rabies'] === '1';

            $profile = strtoupper(trim((string)($_POST['treatment_profile'] ?? '')));
            $d0Date = (string)($_POST['d0_date'] ?? '');
            $patientId = (int)$visit['patient_id'];
            $caseId = (int)$visit['case_id'];
            $warnings = [];
            $outsideDoses = [];
            $outsideClinic = '';
            $previousVaccinationDate = '';

            // The route is defined by the Vaccination Regimen (item 6). Legacy
            // PREP/BOOSTER records without a route keep their stored route.
            $existingCaseProfile = workflowCaseProfile($conn, $caseId);
            $legacyProfiles = [];
            if (in_array((string)$existingCaseProfile['profile'], ['PREP', 'BOOSTER'], true)) {
                $legacyProfiles[] = (string)$existingCaseProfile['profile'];
            }

            if ($isNonRabies) {
                /*
                 * OTHER is already supported by clinical_assessments.
                 * It is used here only as a workflow marker so this assessment
                 * does NOT generate the anti-rabies D0/D3/... schedule.
                 */
                $profile = 'OTHER';
                $route = '';
                $d0Date = assessmentExistingD0($conn, $visitId);
            } else {
                if (
                    $d0Date === ''
                    || (!array_key_exists($profile, workflowRegimenOptions()) && !in_array($profile, $legacyProfiles, true))
                ) {
                    throw new RuntimeException(
                        'Vaccination Regimen and D0 date are required for an anti-rabies schedule.'
                    );
                }
                assessmentValidDate($d0Date, 'Enter a valid D0 date.');
                $route = workflowProfileRoute($profile, (string)($existingCaseProfile['route'] ?? ''));

                // Booster eligibility (item 8): previous anti-rabies vaccination within 5 years.
                if (workflowIsBoosterProfile($profile)) {
                    $previousVaccinationDate = trim((string)($_POST['previous_vaccination_date'] ?? ''));
                    $previousUnknown = (string)($_POST['previous_vaccination_unknown'] ?? '') === '1';
                    assessmentValidDate($previousVaccinationDate, 'Enter a valid previous vaccination date.');
                    if ($previousVaccinationDate !== '' && $previousVaccinationDate > date('Y-m-d')) {
                        throw new RuntimeException('The previous anti-rabies vaccination date cannot be in the future.');
                    }
                    if ($previousUnknown || $previousVaccinationDate === '') {
                        $previousVaccinationDate = '';
                        $warnings[] = 'Booster eligibility: the previous anti-rabies vaccination date is unknown. A full PEP is needed instead of a booster.';
                    } elseif ($previousVaccinationDate < date('Y-m-d', strtotime($d0Date . ' -5 years'))) {
                        $warnings[] = 'Booster eligibility: the previous anti-rabies vaccination (' . $previousVaccinationDate
                            . ') was more than 5 years ago. A full PEP is needed instead of a booster.';
                    }
                }

                // Follow-up from another clinic (item 9): doses already given elsewhere.
                if ((string)($_POST['outside_follow_up'] ?? '') === '1') {
                    $outsideClinic = mb_substr(trim((string)($_POST['outside_clinic_name'] ?? '')), 0, 255);
                    if ($outsideClinic === '') {
                        throw new RuntimeException('Enter the name of the clinic where the earlier doses were given.');
                    }
                    $stages = workflowScheduleStages($profile);
                    $postedOutside = is_array($_POST['outside_dose'] ?? null) ? $_POST['outside_dose'] : [];
                    $postedDates = is_array($_POST['outside_dose_date'] ?? null) ? $_POST['outside_dose_date'] : [];
                    foreach ($postedOutside as $doseNumber => $flag) {
                        $doseNumber = (int)$doseNumber;
                        if ((string)$flag !== '1' || !isset($stages[$doseNumber])) continue;
                        $outsideDoses[$doseNumber] = $doseNumber === 1
                            ? $d0Date
                            : trim((string)($postedDates[$doseNumber] ?? ''));
                    }
                    if (!isset($outsideDoses[1])) {
                        throw new RuntimeException('For a follow-up from another clinic, mark D0 as given there. The original Day 0 is the base date for the remaining doses.');
                    }
                    if (count($outsideDoses) >= count($stages)) {
                        throw new RuntimeException('Every dose of this regimen is marked as given elsewhere. Leave at least one remaining dose to schedule.');
                    }
                    ksort($outsideDoses);
                    $previousDate = '';
                    foreach ($outsideDoses as $doseNumber => $date) {
                        assessmentValidDate($date, 'Enter the date ' . workflowDoseLabel($doseNumber) . ' was given at the other clinic.');
                        if ($date === '' || $date > date('Y-m-d') || ($previousDate !== '' && $date < $previousDate)) {
                            throw new RuntimeException('Enter past dates, in order, for the doses given at the other clinic (' . workflowDoseLabel($doseNumber) . ').');
                        }
                        $previousDate = $date;
                    }
                }
            }

            $conn->begin_transaction();

            /*
             * ========================================================
             * RESTART OLD VACCINATION CYCLE WHEN CLINIC RULE REQUIRES
             * ========================================================
             *
             * Admin Staff check-in only creates a new patient visit.
             * The clinical restart happens here, when the Nurse actually
             * reassesses the patient and confirms the new D0 date.
             *
             * If a dose of the case is still not given and is more than
             * 7 days overdue, retire the entire PREVIOUS vaccination cycle
             * before a new schedule is generated from Day 0.
             *
             * We archive instead of deleting so the previous cycle stays
             * available for history/audit.
             */
            $restartRequired = false;
            $previousCycleRows = 0;
            $lateDecision = '';

            if (!$isNonRabies) {
                /*
                 * A dose more than 7 days overdue is NEVER restarted automatically.
                 * The nurse chooses on this form: restart from Day 0 (archive the
                 * old cycle) or continue the current schedule. A "continue" already
                 * chosen on the Vaccination page is kept without asking again.
                 */
                $lateDoses = workflowCaseLateDoses($conn, $patientId, $caseId, $branchId);
                if ($lateDoses['rows']) {
                    $postedDecision = (string)($_POST['late_decision'] ?? '');
                    $lateRemark = mb_substr(trim((string)($_POST['late_decision_remark'] ?? '')), 0, 500);
                    if (!in_array($postedDecision, ['continue', 'restart'], true)) {
                        // Same shared function as the Late Dose Decision box on this page.
                        if (workflowLateDoseDecisionNeeded($lateDoses)) {
                            throw new RuntimeException(
                                'This patient has a dose ' . $lateDoses['max_days'] . ' days overdue (more than '
                                . $catchUpWindowDays . ' days). Choose "Continue current schedule" or '
                                . '"Restart from Day 0" in the Late Dose Decision box at the top of the form before saving.'
                            );
                        }
                        $postedDecision = 'continue';
                    }
                    $lateDecision = $postedDecision;
                    if ($lateDecision === 'restart') {
                        $latestOverdue = (string)end($lateDoses['rows'])['scheduled_date'];
                        if ($d0Date <= $latestOverdue) {
                            throw new RuntimeException('Restart from Day 0: enter the new Day 0 date (the date of this re-assessment), not the old cycle\'s date.');
                        }
                    }
                    $nurseName = (string)($user['username'] ?? '');
                    foreach ($lateDoses['rows'] as $lateRow) {
                        // Record the choice once per overdue dose (a choice already saved is not repeated).
                        if ((string)$lateRow['late_decision'] === $lateDecision) continue;
                        workflowRecordLateDecision($conn, (int)$lateRow['vaccination_id'], $lateDecision,
                            (int)$lateRow['days_late'],
                            $lateRemark !== '' ? $lateRemark : ($lateDecision === 'continue'
                                ? 'Continued per nurse/physician decision (Assessment).' : 'Restart chosen on re-assessment.'),
                            $userId, $branchId, [
                                'patient_name' => $visit['full_name'], 'patient_id' => $patientId,
                                'case_number' => $visit['case_number'], 'case_id' => $caseId,
                                'dose_label' => workflowDoseLabel((int)$lateRow['dose_number']), 'nurse_name' => $nurseName,
                            ]);
                    }
                }

                if ($lateDecision === 'restart') {
                    $restartRequired = true;

                    /*
                     * Retire ALL active rows from the old vaccination cycle,
                     * including Completed, Missed and Scheduled rows. This is
                     * what makes the new cycle start again from D0 without
                     * deleting the patient's old vaccination history.
                     */
                    $archivePreviousCycle = $conn->prepare(
                        "UPDATE vaccination_records
                         SET is_archived = 1, archived_at = NOW(), archived_by = ?
                         WHERE patient_id = ? AND case_id = ? AND branch_id = ? AND is_archived = 0"
                    );
                    $archivePreviousCycle->bind_param('iiis', $userId, $patientId, $caseId, $branchId);
                    $archivePreviousCycle->execute();
                    $previousCycleRows = (int)$archivePreviousCycle->affected_rows;
                    $archivePreviousCycle->close();

                    // Re-open the case for the new active treatment cycle.
                    $reopenCase = $conn->prepare(
                        "UPDATE animal_bite_cases SET case_status = 'Ongoing' WHERE case_id = ? AND branch_id = ?"
                    );
                    $reopenCase->bind_param('is', $caseId, $branchId);
                    $reopenCase->execute();
                    $reopenCase->close();
                }
            } else {
                /*
                 * Non-anti-rabies assessments must not leave a newly generated
                 * anti-rabies schedule for this visit.
                 */
                assessmentArchiveVisitSchedule($conn, $visitId, $userId, $branchId);
            }

            $data += [
                'visit_id' => $visitId,
                'patient_id' => $patientId,
                'case_id' => $caseId,
                'branch_id' => $branchId,
                'nurse_id' => $userId,
                'treatment_profile' => $isNonRabies ? 'OTHER' : workflowStoredProfile($conn, $profile),
                'route' => $route,
                'd0_date' => $d0Date,
                'previous_vaccination_date' => $previousVaccinationDate,
                'outside_clinic_name' => $outsideClinic,
            ];
            assessmentUpsert($conn, $data);
            assessmentUpdateCaseAndVisit($conn, $data, $visitId, $userId, $branchId);

            $outsideRecorded = 0;
            if (!$isNonRabies) {
                /*
                 * Anti-rabies workflow only:
                 * generate the regimen's D0/D3/... schedule from Day 0.
                 */
                workflowCreateSchedule(
                    $conn,
                    $visitId,
                    $patientId,
                    $caseId,
                    $branchId,
                    $profile,
                    $d0Date,
                    $userId
                );

                if ($outsideDoses) {
                    $outsideRecorded = workflowRecordOutsideDoses(
                        $conn, $patientId, $caseId, $branchId, $outsideDoses, $outsideClinic, $userId
                    );
                    workflowAudit(
                        $conn,
                        $userId,
                        $branchId,
                        'Recorded ' . $outsideRecorded . ' dose(s) given at ' . $outsideClinic
                            . ' for case ' . $caseId . ' (no stock used); remaining doses scheduled from original D0 ' . $d0Date,
                        'Clinical Assessment'
                    );
                }

                if ($restartRequired) {
                    workflowAudit(
                        $conn,
                        $userId,
                        $branchId,
                        'Restarted vaccination cycle for case '
                            . $caseId
                            . ' on visit '
                            . $visitId
                            . '; archived '
                            . $previousCycleRows
                            . ' previous vaccination record(s) and generated a new schedule from D0 '
                            . $d0Date,
                        'Vaccination Restart'
                    );
                } else {
                    workflowAudit(
                        $conn,
                        $userId,
                        $branchId,
                        'Saved nurse assessment and anti-rabies schedule for visit '
                            . $visitId,
                        'Clinical Assessment'
                    );
                }
                foreach ($warnings as $warning) {
                    workflowAudit($conn, $userId, $branchId, $warning . ' (visit ' . $visitId . ')', 'Clinical Assessment');
                }
            } else {
                workflowAudit(
                    $conn,
                    $userId,
                    $branchId,
                    'Saved non-anti-rabies assessment for visit '
                        . $visitId
                        . '; no anti-rabies schedule generated',
                    'Clinical Assessment'
                );
            }

            $conn->commit();

            if ($isNonRabies) {
                $message = 'Assessment saved as Not Anti-Rabies Vaccine. No anti-rabies schedule was generated.';
            } elseif ($restartRequired) {
                $message = 'Reassessment saved. As you chose, the previous vaccination cycle was archived and a new schedule was generated from Day 0.';
            } elseif ($lateDecision === 'continue') {
                $message = 'Assessment saved. As you chose, the current schedule continues (no restart). Record the late dose in Vaccination; the remaining doses move forward by the delay.';
            } else {
                $message = 'Assessment saved. The anti-rabies schedule was generated from the confirmed D0 date.';
            }
            if ($outsideRecorded > 0) {
                $message .= ' ' . $outsideRecorded . ' dose(s) given at ' . $outsideClinic
                    . ' were recorded as completed (no stock used); only the remaining doses were scheduled.';
            }
            if ($warnings) {
                workflowFlash('warning', $message . ' ' . implode(' ', $warnings));
            } else {
                workflowFlash('success', $message);
            }

        // ========================================================
        // ADMINISTER A ONE-TIME / NON-ANTI-RABIES PRODUCT
        // ========================================================
        } elseif ($action === 'administer_non_rabies') {
            if (!in_array(
                $visit['workflow_status'],
                ['Waiting for Nurse', 'Under Assessment', 'Treatment Completed'],
                true
            )) {
                throw new RuntimeException(
                    'This visit is no longer available for administration.'
                );
            }

            $isNonRabies = isset($_POST['not_anti_rabies'])
                && (string)$_POST['not_anti_rabies'] === '1';

            if (!$isNonRabies) {
                throw new RuntimeException(
                    'Check "Not Anti-Rabies Vaccine" before using the one-time administration workflow.'
                );
            }

            $itemIds = $_POST['non_rabies_item_id'] ?? [];
            $quantities = $_POST['non_rabies_quantity_base'] ?? [];
            $remarksList = $_POST['non_rabies_remarks'] ?? [];

            if (!is_array($itemIds)) $itemIds = [$itemIds];
            if (!is_array($quantities)) $quantities = [$quantities];
            if (!is_array($remarksList)) $remarksList = [$remarksList];

            $administeredDate = trim(
                (string)($_POST['non_rabies_date_administered'] ?? '')
            );

            if (
                DateTime::createFromFormat('Y-m-d', $administeredDate)?->format('Y-m-d')
                !== $administeredDate
            ) {
                throw new RuntimeException('Enter a valid administration date.');
            }

            if ($administeredDate > date('Y-m-d')) {
                throw new RuntimeException(
                    'An administered product cannot have a future administration date.'
                );
            }

            $entries = [];
            $seenItems = [];

            foreach ($itemIds as $index => $rawItemId) {
                $itemId = (int)$rawItemId;
                $quantityBase = (float)($quantities[$index] ?? 0);
                $administrationRemarks = trim((string)($remarksList[$index] ?? ''));

                if ($itemId <= 0 && $quantityBase <= 0 && $administrationRemarks === '') {
                    continue;
                }
                if ($itemId <= 0) {
                    throw new RuntimeException(
                        'Select a product for every administration row.'
                    );
                }
                if ($quantityBase <= 0) {
                    throw new RuntimeException(
                        'Enter the actual quantity used for every selected product.'
                    );
                }
                if (isset($seenItems[$itemId])) {
                    throw new RuntimeException(
                        'The same product is listed more than once. Use one row and enter the total actual quantity used.'
                    );
                }
                $seenItems[$itemId] = true;

                $item = assessmentGetMedicalSupplyItem($conn, $itemId);
                if (!$item) {
                    throw new RuntimeException(
                        'One of the selected products was not found under Medical Supplies.'
                    );
                }
                if (
                    inventoryIsSiteBased($item)
                    && abs($quantityBase - round($quantityBase)) > 0.00001
                ) {
                    throw new RuntimeException(
                        $item['item_name'] . ': site-based usage must be a whole number of sites.'
                    );
                }

                $entries[] = [
                    'item_id' => $itemId,
                    'quantity_base' => $quantityBase,
                    'remarks' => $administrationRemarks,
                    'item' => $item,
                ];
            }

            if (!$entries) {
                throw new RuntimeException('Add at least one product to administer.');
            }

            $patientId = (int)$visit['patient_id'];
            $caseId = (int)$visit['case_id'];

            $conn->begin_transaction();
            // Use the same lock order as Vaccination, supply usage and Daily Inventory.
            forecastTrainingRevision($conn, $branchId, true);

            assessmentSaveNonArvAssessment(
                $conn,
                $visit,
                $visitId,
                $userId,
                $branchId,
                $_POST
            );

            $administeredSummaries = [];
            $reopenedClosings = 0;

            foreach ($entries as $entry) {
                $itemId = (int)$entry['item_id'];
                $quantityBase = (float)$entry['quantity_base'];
                $administrationRemarks = (string)$entry['remarks'];
                $item = $entry['item'];

                $vaccineName = (string)$item['item_name'];
                $unitId = (int)$item['unit_id'];
                $baseUnit = inventoryBaseUnitLabel($item);
                $displayUnit = inventoryDisplayUnitLabel($item);
                $conversion = inventoryConversionToBase($item);

                $recordRemarks = 'Non-anti-rabies / one-time administration';
                if ($administrationRemarks !== '') {
                    $recordRemarks .= ' | ' . $administrationRemarks;
                }

                $insertVaccination = $conn->prepare(
                    "INSERT INTO vaccination_records
                     (
                        patient_id, case_id, visit_id, item_id, vaccine_name,
                        unit_id, quantity_used, quantity_unit_label,
                        display_unit_label_snapshot, conversion_to_base_snapshot,
                        branch_id, dose_number, date_administered,
                        administered_datetime, scheduled_date, vaccination_status,
                        is_final_dose, remarks, nurse_id
                     )
                     VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        0, ?, NOW(), NULL, 'Completed', 1, ?, ?
                     )"
                );
                if (!$insertVaccination) {
                    throw new RuntimeException('Unable to prepare the administration record.');
                }
                $insertVaccination->bind_param(
                    'iiiisidssdsssi',
                    $patientId, $caseId, $visitId, $itemId, $vaccineName,
                    $unitId, $quantityBase, $baseUnit, $displayUnit, $conversion,
                    $branchId, $administeredDate, $recordRemarks, $userId
                );
                if (!$insertVaccination->execute()) {
                    $error = $insertVaccination->error;
                    $insertVaccination->close();
                    throw new RuntimeException(
                        'Unable to save ' . $vaccineName . ': ' . $error
                    );
                }
                $vaccinationId = (int)$conn->insert_id;
                $insertVaccination->close();

                $usedBatches = assessmentDeductStockFEFO(
                    $conn, $itemId, $branchId, $quantityBase
                );
                $batchSummary = assessmentBatchSummary($usedBatches, $item);
                $usageDescription = inventoryUsageDescription($quantityBase, $item);

                $transactionRemarks =
                    'Non-anti-rabies administration'
                    . ' | Patient ID: ' . $patientId
                    . ' | Case No.: ' . patientCaseNumberById($conn, (int)$caseId)
                    . ' | Product: ' . $vaccineName
                    . ' | Used: ' . $usageDescription
                    . ' | Batch(es): ' . $batchSummary
                    . ' | Date: ' . $administeredDate;
                if ($administrationRemarks !== '') {
                    $transactionRemarks .= ' | Remarks: ' . $administrationRemarks;
                }

                $transactionDate = $administeredDate . ' ' . date('H:i:s');
                $stockTransaction = $conn->prepare(
                    "INSERT INTO stock_transactions
                     (item_id, user_id, vaccination_id, branch_id,
                      transaction_type, quantity, remarks, transaction_date)
                     VALUES (?, ?, ?, ?, 'OUT', ?, ?, ?)"
                );
                if (!$stockTransaction) {
                    throw new RuntimeException('Unable to prepare the stock transaction.');
                }
                $stockTransaction->bind_param(
                    'iiisdss',
                    $itemId, $userId, $vaccinationId, $branchId,
                    $quantityBase, $transactionRemarks, $transactionDate
                );
                if (!$stockTransaction->execute()) {
                    $error = $stockTransaction->error;
                    $stockTransaction->close();
                    throw new RuntimeException(
                        'Unable to save inventory transaction for '
                        . $vaccineName . ': ' . $error
                    );
                }
                $stockTransaction->close();

                // One usage-ledger row per product given during assessment.
                dailyInventoryInsertUsage(
                    $conn, $itemId, $branchId, $administeredDate, $quantityBase,
                    1, 'Assessment', $vaccinationId, $userId
                );

                // Usage is included once when the nurse submits the daily closing.
                // A previously submitted count must be verified again after new usage.
                $reopenedClosings += forecastTrainingUsageChanged(
                    $conn, $branchId, $itemId, $administeredDate
                );

                $administeredSummaries[] =
                    $vaccineName . ' (' . $usageDescription . ')';
            }

            $visitComplete = $conn->prepare(
                "UPDATE patient_visits
                 SET workflow_status = 'Treatment Completed',
                     assigned_nurse = ?,
                     assessment_started_at = COALESCE(assessment_started_at, NOW()),
                     treatment_completed_at = COALESCE(treatment_completed_at, NOW()),
                     updated_at = NOW()
                 WHERE visit_id = ? AND branch_id = ?"
            );
            if (!$visitComplete) {
                throw new RuntimeException('Unable to prepare visit completion.');
            }
            $visitComplete->bind_param('iis', $userId, $visitId, $branchId);
            if (!$visitComplete->execute()) {
                $error = $visitComplete->error;
                $visitComplete->close();
                throw new RuntimeException('Unable to complete the visit: ' . $error);
            }
            $visitComplete->close();

            // Non-ARV one-time treatment has no future ARV schedule.
            // After all selected products are administered, complete the case.
            $caseComplete = $conn->prepare(
                "UPDATE animal_bite_cases
                 SET case_status = 'Completed'
                 WHERE case_id = ? AND branch_id = ? AND is_archived = 0"
            );
            if (!$caseComplete) {
                throw new RuntimeException('Unable to prepare case completion.');
            }
            $caseComplete->bind_param('is', $caseId, $branchId);
            if (!$caseComplete->execute()) {
                $error = $caseComplete->error;
                $caseComplete->close();
                throw new RuntimeException('Unable to complete the case: ' . $error);
            }
            $caseComplete->close();

            workflowAudit(
                $conn,
                $userId,
                $branchId,
                'Completed non-anti-rabies administration for visit '
                    . $visitId . ' with ' . count($entries) . ' product(s): '
                    . implode('; ', $administeredSummaries)
                    . '; case marked Completed; no anti-rabies schedule generated',
                'Clinical Assessment'
            );

            $conn->commit();

            workflowFlash(
                'success',
                count($entries)
                    . ' non-anti-rabies product(s) administered successfully. '
                    . 'The visit and case were marked Completed, inventory usage was recorded, '
                    . 'and no anti-rabies schedule was generated.'
                    . ($reopenedClosings > 0
                        ? ' Resubmit Daily Inventory for the affected item(s) and administration date.'
                        : ' Submit Daily Inventory for the affected item(s) and administration date to include this usage in forecasting.')
            );

        // ========================================================
        // COMPLETE CHART + SEND TO REGISTRY
        // ========================================================
        } elseif ($action === 'send_registry' && in_array($visit['workflow_status'], ['For Registry', 'Registered'], true)) {
            // Second click or resubmitted form: nothing is sent again.
            workflowFlash('info', 'This chart was already sent to Administrative Staff for registry.');

        } elseif ($action === 'send_registry') {
            if (!in_array(
                $visit['workflow_status'],
                ['Under Assessment', 'Treatment Completed'],
                true
            )) {
                throw new RuntimeException(
                    'Save the Nurse assessment before sending the chart for registry.'
                );
            }

            $hasAssessment = $conn->prepare(
                'SELECT assessment_id
                 FROM clinical_assessments
                 WHERE visit_id = ?
                 LIMIT 1'
            );

            $hasAssessment->bind_param('i', $visitId);
            $hasAssessment->execute();

            $assessmentRow = $hasAssessment
                ->get_result()
                ->fetch_assoc();

            $hasAssessment->close();

            if (!$assessmentRow) {
                throw new RuntimeException('Assessment is incomplete.');
            }

            $conn->begin_transaction();

            $sign = $conn->prepare(
                'UPDATE clinical_assessments
                 SET
                    chart_signed_at = NOW(),
                    nurse_id = ?
                 WHERE visit_id = ?'
            );

            $sign->bind_param('ii', $userId, $visitId);
            $sign->execute();
            $sign->close();

            $update = $conn->prepare(
                "UPDATE patient_visits
                 SET
                    workflow_status = 'For Registry',
                    assigned_nurse = ?,
                    treatment_completed_at = COALESCE(treatment_completed_at, NOW()),
                    sent_for_registry_at = NOW()
                 WHERE visit_id = ?
                   AND branch_id = ?"
            );

            $update->bind_param(
                'iis',
                $userId,
                $visitId,
                $branchId
            );

            $update->execute();
            $update->close();

            // One notification per active Admin Staff of this branch; the
            // source key makes a double click or a resubmitted form harmless.
            $visitDateStmt = $conn->prepare('SELECT visit_date FROM patient_visits WHERE visit_id = ?');
            $visitDateStmt->bind_param('i', $visitId);
            $visitDateStmt->execute();
            $visitDate = (string)($visitDateStmt->get_result()->fetch_assoc()['visit_date'] ?? date('Y-m-d'));
            $visitDateStmt->close();
            $doseLabel = workflowVisitDoseLabel($conn, (int)$visit['case_id'], $branchId, $visitDate);
            $nurseName = (string)($user['username'] ?? 'Nurse');
            $message = 'Chart ready for registry: ' . $visit['full_name']
                . ' – Case ' . ($visit['case_number'] ?: 'N/A')
                . ' – Dose ' . preg_replace('/^Dose\s+/', '', $doseLabel)
                . ', sent by Nurse ' . $nurseName . '.';
            $notified = workflowNotifyRoleOnce(
                $conn,
                $branchId,
                4,
                'Chart Ready for Registry',
                $message,
                'registry',
                'registry_visit_' . $visitId
            );

            workflowAudit(
                $conn,
                $userId,
                $branchId,
                'Signed chart and sent visit ' . $visitId . ' for registry | Patient: ' . $visit['full_name']
                    . ' | Case: ' . ($visit['case_number'] ?: 'N/A') . ' | Dose: ' . $doseLabel
                    . ' | Admin Staff notified: ' . $notified,
                'Clinical Assessment'
            );

            $conn->commit();

            workflowFlash(
                'success',
                'Chart signed and sent to Administrative Staff for registry.'
            );

        } else {
            throw new RuntimeException('Unknown action.');
        }

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        workflowFlash('danger', $e->getMessage());
    }

    header(
        'Location: Nurse_Assessment.php?visit_id='
        . max(0, $visitId)
    );
    exit;
}

// ============================================================
// AUTOMATIC MISSED-SCHEDULE SYNCHRONIZATION
// ============================================================
/*
 * Once a scheduled vaccination date has already passed, the record
 * should no longer remain "Scheduled". It becomes "Missed".
 *
 * Completed/administered records are not touched.
 */
try {
    workflowMarkMissedSchedules($conn, $branchId, $userId);
} catch (Throwable $e) {
    /*
     * Do not prevent the Nurse Assessment page from loading just because
     * automatic missed-schedule synchronization failed.
     */
    error_log(
        'Nurse_Assessment missed-schedule synchronization failed: '
        . $e->getMessage()
    );
}

// ============================================================
// ACTIVE TAB + PAGINATION STATE
// ============================================================
$selectedId = (int)($_GET['visit_id'] ?? 0);

$activeTab = (string)($_GET['tab'] ?? 'queue');
if (!in_array($activeTab, ['queue', 'followups'], true)) {
    $activeTab = 'queue';
}

// Opening a visit always returns the Nurse to Current Queue.
if ($selectedId > 0) {
    $activeTab = 'queue';
}

$queuePerPage = 10;
$queuePage = filter_var(
    $_GET['queue_page'] ?? 1,
    FILTER_VALIDATE_INT
);
if ($queuePage === false || $queuePage < 1) {
    $queuePage = 1;
}

$followupPerPage = 10;
$followupPage = filter_var(
    $_GET['followup_page'] ?? 1,
    FILTER_VALIDATE_INT
);
if ($followupPage === false || $followupPage < 1) {
    $followupPage = 1;
}

// ============================================================
// ACTIVE NURSE ASSESSMENT QUEUE — COUNT
// ============================================================
$queueCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM patient_visits v
     WHERE v.branch_id = ?
       AND v.workflow_status IN (
            'Waiting for Nurse',
            'Under Assessment',
            'Treatment Completed'
       )"
);

$queueCountStmt->bind_param('s', $branchId);
$queueCountStmt->execute();

$queueTotal = (int)(
    $queueCountStmt
        ->get_result()
        ->fetch_assoc()['total']
    ?? 0
);

$queueCountStmt->close();

$queueTotalPages = max(
    1,
    (int)ceil($queueTotal / $queuePerPage)
);

if ($queuePage > $queueTotalPages) {
    $queuePage = $queueTotalPages;
}

$queueOffset = ($queuePage - 1) * $queuePerPage;

// ============================================================
// ACTIVE NURSE ASSESSMENT QUEUE — PAGINATED LIST
// ============================================================
$queueStmt = $conn->prepare(
    "SELECT
        v.*,
        p.full_name,
        p.contact_number,
        c.case_number
     FROM patient_visits v
     INNER JOIN patients p
        ON p.patient_id = v.patient_id
     INNER JOIN animal_bite_cases c
        ON c.case_id = v.case_id
     WHERE v.branch_id = ?
       AND v.workflow_status IN (
            'Waiting for Nurse',
            'Under Assessment',
            'Treatment Completed'
       )
     ORDER BY
        FIELD(
            v.workflow_status,
            'Waiting for Nurse',
            'Under Assessment',
            'Treatment Completed'
        ),
        v.check_in_at
     LIMIT ?
     OFFSET ?"
);

$queueStmt->bind_param(
    'sii',
    $branchId,
    $queuePerPage,
    $queueOffset
);

$queueStmt->execute();

$queue = $queueStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$queueStmt->close();
// Follow-up visits checked in for a scheduled dose go straight to vaccination
// (shared rule: workflowReadyVaccinationVisits), keyed by case_id.
$readyVisits = workflowReadyVaccinationVisits($conn, $branchId);

// ============================================================
// MISSED / OVERDUE FOLLOW-UP — COUNT
// ============================================================
// Shared missed-dose rule (workflowMissedDoseCondition): past due, not given,
// and never for an archived patient, case or vaccination record, so archived
// patients leave this tab and come back when restored.
$followupCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM (
        SELECT vr.patient_id, vr.case_id
        " . workflowMissedDoseFromSql() . "
        WHERE vr.branch_id = ?
          AND " . workflowMissedDoseCondition('vr', 'c', 'p') . "
        GROUP BY vr.patient_id, vr.case_id
     ) AS missed_cases"
);

$followupCountStmt->bind_param('s', $branchId);
$followupCountStmt->execute();

$followupTotal = (int)(
    $followupCountStmt
        ->get_result()
        ->fetch_assoc()['total']
    ?? 0
);

$followupCountStmt->close();

$followupTotalPages = max(
    1,
    (int)ceil($followupTotal / $followupPerPage)
);

if ($followupPage > $followupTotalPages) {
    $followupPage = $followupTotalPages;
}

$followupOffset =
    ($followupPage - 1) * $followupPerPage;

// ============================================================
// MISSED / OVERDUE FOLLOW-UP — PAGINATED LIST
// ============================================================
/*
 * The oldest missed scheduled date for the case determines the
 * displayed overdue age.
 *
 * 1-7 days overdue:
 *      Missed - Catch-up Eligible (record late; remaining doses shift)
 *
 * More than 7 days overdue:
 *      Nurse decision: continue the schedule or restart from Day 0
 *
 * See workflowLateDoseStatus(). Historical vaccination records are preserved.
 */
$overdueStmt = $conn->prepare(
    "SELECT
        vr.patient_id,
        vr.case_id,
        MAX(p.full_name) AS full_name,
        MAX(c.case_number) AS case_number,
        MIN(vr.scheduled_date) AS oldest_due,
        DATEDIFF(CURDATE(), MIN(vr.scheduled_date)) AS days_overdue,
        COUNT(DISTINCT vr.dose_number) AS missed_dose_count
     " . workflowMissedDoseFromSql() . "
     WHERE vr.branch_id = ?
       AND " . workflowMissedDoseCondition('vr', 'c', 'p') . "
     GROUP BY vr.patient_id, vr.case_id
     ORDER BY days_overdue DESC, full_name ASC
     LIMIT ?
     OFFSET ?"
);

$overdueStmt->bind_param(
    'sii',
    $branchId,
    $followupPerPage,
    $followupOffset
);

$overdueStmt->execute();

$overdueCases = $overdueStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$overdueStmt->close();

foreach ($overdueCases as &$overdue) {
    $lateStatus = workflowLateDoseStatus((int)$overdue['days_overdue']);
    $overdue['workflow_classification'] =
        $lateStatus['classification'] === 'nurse_decision' ? 'restart_required' : 'catchup';
    $overdue['workflow_label'] = $lateStatus['label'];
    $overdue['workflow_description'] = $lateStatus['description'];
}
unset($overdue);

// SELECTED VISIT / ASSESSMENT
// ============================================================
$selected = null;

if ($selectedId > 0) {
    /*
     * a.* comes first so a visit without an assessment yet does not overwrite
     * the visit columns or the Admin Staff intake values with NULL. Intake
     * details (biting animal, animal status, site and date of bite) fill the
     * form until the Nurse saves her own assessment.
     */
    $stmt = $conn->prepare(
        "SELECT
            a.*,
            a.updated_at AS assessment_updated_at,
            v.*,
            p.full_name,
            c.case_number,
            c.bite_location,
            c.date_of_bite,
            COALESCE(NULLIF(a.animal_type, ''), c.animal_type) AS animal_type,
            COALESCE(NULLIF(a.animal_status, ''), c.animal_status) AS animal_status,
            COALESCE(NULLIF(a.bite_category, ''), c.bite_category) AS bite_category,
            (a.assessment_id IS NOT NULL) AS has_assessment
         FROM patient_visits v
         INNER JOIN patients p
            ON p.patient_id = v.patient_id
         INNER JOIN animal_bite_cases c
            ON c.case_id = v.case_id
         LEFT JOIN clinical_assessments a
            ON a.visit_id = v.visit_id
         WHERE v.visit_id = ?
           AND v.branch_id = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        'is',
        $selectedId,
        $branchId
    );

    $stmt->execute();

    $selected = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    /*
     * A follow-up visit of the same case has no assessment of its own yet.
     * Start from the case's latest assessment so the regimen, Day 0 and other
     * clinical details are not retyped (and the schedule is not reset).
     */
    if ($selected && !(int)$selected['has_assessment']) {
        $previousStmt = $conn->prepare(
            "SELECT * FROM clinical_assessments
             WHERE case_id = ? AND branch_id = ?
             ORDER BY updated_at DESC, assessment_id DESC
             LIMIT 1"
        );
        $caseForPrevious = (int)$selected['case_id'];
        $previousStmt->bind_param('is', $caseForPrevious, $branchId);
        $previousStmt->execute();
        $previousAssessment = $previousStmt->get_result()->fetch_assoc();
        $previousStmt->close();
        if ($previousAssessment) {
            foreach (['exposure_history', 'date_of_exposure', 'exposure_site', 'animal_type', 'animal_status',
                      'bite_category', 'treatment_profile', 'route', 'active_regimen', 'important_concerns',
                      'instructions_given', 'd0_date', 'bite_markers', 'previous_vaccination_date',
                      'outside_clinic_name', 'wound_type', 'bite_type'] as $field) {
                if (array_key_exists($field, $previousAssessment)
                    && ($selected[$field] ?? null) === null
                    && $previousAssessment[$field] !== null) {
                    $selected[$field] = $previousAssessment[$field];
                }
            }
            $selected['prefilled_from_previous'] = true;
        }
    }
}

$selectedNonRabies =
    $selected
    && (string)($selected['treatment_profile'] ?? '') === 'OTHER';

// Doses of this case more than 7 days overdue: the form asks the nurse to choose.
// Same function as the save check, and independent of the "not anti-rabies"
// flag, so whenever the save can ask for a choice the box is on the page.
$selectedLateDoses = null;
if ($selected) {
    $selectedLateDoses = workflowCaseLateDoses(
        $conn, (int)$selected['patient_id'], (int)$selected['case_id'], $branchId
    );
}

/*
 * "Record Administered Products": opens Vaccination with this patient, case
 * and the dose due (workflowFirstOpenDose), plus visit_id so the page fills
 * in the regimen's product, sites and date and returns here after saving.
 * The schedule exists only once THIS visit's assessment is saved, so until
 * then the button is disabled with the reason (the page script also disables
 * it while the form has unsaved changes). Not Anti-Rabies Vaccine uses the
 * one-time product form on this page instead (no anti-rabies dose).
 */
$recordProducts = ['url' => '', 'block' => '', 'dose' => ''];
if ($selected) {
    $savedProfile = strtoupper(trim((string)($selected['treatment_profile'] ?? '')));
    if (!(int)$selected['has_assessment']) {
        $recordProducts['block'] = 'Save the assessment first: the vaccination schedule exists only after Save Assessment and Generate Schedule.';
    } elseif ($savedProfile === 'OTHER') {
        $recordProducts['block'] = 'This assessment is saved as Not Anti-Rabies Vaccine. Save it as an anti-rabies assessment first to record an anti-rabies dose.';
    } else {
        $openDose = workflowFirstOpenDose($conn, (int)$selected['case_id'], $branchId);
        if ($openDose === null) {
            $recordProducts['block'] = 'Every dose of this vaccination cycle is already recorded.';
        } elseif (!$openDose['is_due']) {
            $recordProducts['block'] = 'No dose is due today. Next: ' . $openDose['dose_display'] . ' on '
                . date('M d, Y', strtotime((string)$openDose['scheduled_date'])) . '.';
        } else {
            $recordProducts['url'] = workflowVaccinateUrl(
                (int)$selected['patient_id'],
                (int)$selected['case_id'],
                (int)$openDose['dose_number'],
                $selectedId
            );
            $recordProducts['dose'] = (string)$openDose['dose_display'];
        }
    }
}

$nonRabiesItems = [];
$nonRabiesAdministrations = [];

if ($selected) {
    /*
     * Load all Medical Supplies items with branch usable stock.
     * The nurse chooses the clinic-approved one-time/non-ARV product.
     * The system does not infer or recommend a clinical product/dose.
     */
    $itemsStmt = $conn->prepare(
        "SELECT
            i.item_id,
            i.item_name,
            i.unit_id,
            u.unit_name,
            COALESCE(NULLIF(i.base_unit_label, ''), u.unit_name) AS base_unit_label,
            COALESCE(NULLIF(i.display_unit_label, ''), u.unit_name) AS display_unit_label,
            COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base,
            COALESCE(s.usable_stock,0) AS usable_stock
         FROM inventory_items i
         INNER JOIN units u
            ON u.unit_id = i.unit_id
         INNER JOIN inventory_categories c
            ON c.category_id = i.category_id
         LEFT JOIN ($stockTotalsSql) s ON s.item_id=i.item_id AND s.branch_id=?
         WHERE c.category_name = 'Medical Supplies'
           AND i.item_name NOT LIKE '%Default%'
         ORDER BY i.item_name ASC"
    );

    if ($itemsStmt) {
        $itemsStmt->bind_param(
            's',
            $branchId
        );

        $itemsStmt->execute();

        $nonRabiesItems =
            $itemsStmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

        $itemsStmt->close();

        foreach ($nonRabiesItems as &$itemRow) {
            $itemRow['usable_stock_display'] =
                inventoryStockBreakdown(
                    (float)$itemRow['usable_stock'],
                    $itemRow
                );

            $itemRow['input_step'] =
                inventoryInputStep($itemRow);
        }

        unset($itemRow);
    }

    /*
     * dose_number=0 is reserved by this page for one-time/non-ARV
     * administrations so they never become D0/D3/etc schedule stages.
     */
    $adminHistory = $conn->prepare(
        "SELECT
            vr.vaccination_id,
            vr.item_id,
            COALESCE(i.item_name, vr.vaccine_name) AS item_name,
            vr.quantity_used,
            COALESCE(
                NULLIF(vr.quantity_unit_label, ''),
                u.unit_name,
                'unit'
            ) AS quantity_unit_label,
            vr.date_administered,
            vr.remarks,
            vr.created_at
         FROM vaccination_records vr
         LEFT JOIN inventory_items i
            ON i.item_id = vr.item_id
         LEFT JOIN units u
            ON u.unit_id = vr.unit_id
         WHERE vr.visit_id = ?
           AND vr.branch_id = ?
           AND vr.dose_number = 0
           AND vr.vaccination_status = 'Completed'
           AND vr.is_archived = 0
         ORDER BY
            vr.administered_datetime DESC,
            vr.vaccination_id DESC"
    );

    if ($adminHistory) {
        $adminHistory->bind_param(
            'is',
            $selectedId,
            $branchId
        );

        $adminHistory->execute();

        $nonRabiesAdministrations =
            $adminHistory
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

        $adminHistory->close();
    }
}

$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Nurse Assessment - Smart Bite Care</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="notif-num.css">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --success: #28a745;
            --danger: #dc3545;
            --text: #1f2a44;
            --muted: #6f7b91;
            --border: #e6eaf2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f9faff;
            color: var(--text);
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            height: 80px;
            padding: 0 35px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border-bottom: 1px solid #e9edf5;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        }

        .topbar h3 {
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -.3px;
        }

        .topbar h3 small {
            margin-left: 10px;
            color: #666;
            font-size: 16px;
            font-weight: 400;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-role {
            margin-left: 3px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .content {
            padding: 35px 35px 40px;
        }

        .alert {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        .content-card {
            overflow: hidden;
            height: 100%;
            background: #fff;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, .08);
        }

        .content-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 19px 22px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h2 {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            color: var(--primary);
            font-size: 19px;
            font-weight: 700;
        }

        .content-card-header p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .section-icon {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
        }

        .content-card-body {
            padding: 22px;
        }

        /* =========================================================
           ASSESSMENT / FOLLOW-UP TABS
           ========================================================= */
        .module-tabs {
            border-bottom: 1px solid #dfe5f0;
            margin-bottom: 24px;
            gap: 8px;
        }

        .module-tabs .nav-link {
            border: none;
            border-radius: 12px 12px 0 0;
            color: #66728d;
            background: transparent;
            padding: 12px 20px;
            font-weight: 700;
            font-size: 15px;
            transition: 0.15s ease;
        }

        .module-tabs .nav-link:hover {
            color: var(--primary);
            background: #eef1fa;
        }

        .module-tabs .nav-link.active {
            background: var(--primary);
            color: #fff;
        }

        .module-tabs .nav-link .tab-count {
            margin-left: 7px;
            font-size: 11px;
            padding: 3px 7px;
            border-radius: 20px;
            background: #e8ecf7;
            color: var(--primary);
        }

        .module-tabs .nav-link.active .tab-count {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }

        /* =========================================================
           PAGINATION — SAME DESIGN AS NURSE VACCINATION
           ========================================================= */
        .pagination-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }

        .pagination-wrap .page-link {
            color: var(--primary);
            border-radius: 8px;
            padding: 8px 14px;
            font-weight: 500;
            border: 1px solid #e2e7f2;
        }

        .pagination-wrap .page-link:hover {
            background: #f0f3fc;
            border-color: var(--primary);
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
        }

        .pagination-info {
            text-align: center;
            color: #7a85a8;
            font-size: 14px;
            margin-top: 12px;
        }

        /* =========================================================
           MISSED / OVERDUE FOLLOW-UP PANEL
           ========================================================= */
        .followup-card {
            margin-bottom: 24px;
        }

        .followup-policy {
            background: #f7f9ff;
            color: #40506f;
            border: 1px solid #e2e8f5;
            box-shadow: none;
        }

        .followup-table {
            margin-bottom: 0;
        }

        .followup-table thead th {
            padding: 12px 14px;
            background: #f8f9fc;
            color: #5d6981;
            border-bottom: 1px solid #e5e9f1;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .25px;
            white-space: nowrap;
        }

        .followup-table tbody td {
            padding: 14px;
            vertical-align: middle;
            color: #35415c;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        .followup-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .followup-table tbody tr:hover {
            background: #fbfcff;
        }

        .followup-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .followup-status.catchup {
            color: #735800;
            background: #fff3cd;
        }

        .followup-status.restart {
            color: #a8202e;
            background: #fde2e5;
        }

        .followup-note {
            margin-top: 5px;
            color: #8b94a6;
            font-size: 11px;
            line-height: 1.4;
            max-width: 340px;
        }

        .days-overdue.catchup {
            color: #b78103;
        }

        .days-overdue.restart {
            color: #dc3545;
        }

        /* =========================================================
           ASSESSMENT QUEUE
           ========================================================= */
        .queue-card {
            position: sticky;
            top: 20px;
            height: auto;
            max-height: calc(100vh - 120px);
        }

        .queue-list {
            max-height: calc(100vh - 245px);
            overflow-y: auto;
        }

        .queue-item {
            padding: 14px 16px;
            color: #2e3b59;
            border: 0;
            border-bottom: 1px solid #edf0f5;
        }

        .queue-item:last-child {
            border-bottom: 0;
        }

        .queue-item:hover {
            background: #f6f7fd;
            color: var(--primary);
        }

        .queue-item.active {
            color: #fff;
            background: var(--primary);
            border-color: var(--primary);
        }

        .queue-name {
            display: block;
            margin-bottom: 3px;
            font-size: 14px;
            font-weight: 700;
        }

        .queue-detail {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            opacity: .8;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            color: #3b4a6b;
            background: #edf0f7;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .queue-item.active .status-pill {
            color: var(--primary);
            background: #fff;
        }

        /* Follow-up checked in for its scheduled dose: goes straight to vaccination. */
        .queue-item.queue-ready { border-left: 4px solid #198754; }
        .status-pill.status-ready { color: #fff; background: #198754; }

        .empty-state {
            display: flex;
            min-height: 230px;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 35px 20px;
            color: #8a94a6;
            text-align: center;
        }

        .empty-state i {
            margin-bottom: 10px;
            color: #b1b8ca;
            font-size: 42px;
        }

        .empty-state strong {
            color: var(--primary);
            font-size: 16px;
        }

        /* =========================================================
           ASSESSMENT FORM
           ========================================================= */
        .patient-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .patient-heading .case-badge {
            padding: 5px 10px;
            color: var(--primary);
            background: #edf1ff;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .form-section {
            margin-top: 6px;
            padding-top: 20px;
            border-top: 1px solid #edf0f5;
        }

        .form-section:first-of-type {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .form-section-title {
            margin: 0 0 15px;
            color: var(--primary);
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .form-label {
            margin-bottom: 6px;
            color: #48546f;
            font-size: 13px;
            font-weight: 650;
        }

        .required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-color: #d9dfeb;
            border-radius: 9px;
        }

        textarea.form-control {
            min-height: auto;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(43, 58, 140, .12);
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            font-weight: 650;
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
            font-weight: 650;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            border-color: var(--primary);
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
        }

        .registry-panel {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-top: 22px;
            padding: 17px 18px;
            background: #f7f9fc;
            border: 1px solid #e7ebf2;
            border-radius: 12px;
        }

        .registry-panel strong {
            display: block;
            color: #26345f;
            font-size: 14px;
        }

        .registry-panel small {
            color: var(--muted);
        }

        /* =========================================================
           NON-ANTI-RABIES / ONE-TIME ADMINISTRATION
           ========================================================= */
        .non-arv-toggle {
            padding: 16px 17px;
            background: #f7f9ff;
            border: 1px solid #dfe6f5;
            border-radius: 12px;
        }

        .non-arv-toggle .form-check-input {
            width: 1.15rem;
            height: 1.15rem;
            margin-top: .15rem;
        }

        .non-arv-toggle .form-check-label {
            color: #26345f;
            font-weight: 700;
        }

        .non-arv-toggle small {
            display: block;
            margin-top: 5px;
            margin-left: 29px;
            color: var(--muted);
            line-height: 1.45;
        }

        .non-arv-panel {
            padding: 18px;
            background: #fbfcff;
            border: 1px solid #dfe6f5;
            border-radius: 14px;
        }

        .non-arv-panel-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            color: var(--primary);
            font-weight: 800;
            font-size: 15px;
        }

        .non-arv-panel-note {
            margin-bottom: 16px;
            color: #6f7b91;
            font-size: 12px;
            line-height: 1.5;
        }

        .non-arv-stock-note {
            margin-top: 5px;
            color: #7b879d;
            font-size: 11px;
        }

        .non-arv-entry {
            padding: 16px;
            margin-bottom: 12px;
            background: #fff;
            border: 1px solid #e1e7f2;
            border-radius: 12px;
        }

        .non-arv-entry-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .non-arv-entry-title {
            color: #33416f;
            font-size: 13px;
            font-weight: 750;
        }

        .non-arv-remove {
            border: 0;
            background: transparent;
            color: #dc3545;
            font-size: 13px;
            font-weight: 700;
        }

        .non-arv-add-button {
            border: 1px dashed #9aabe0;
            background: #f7f9ff;
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
        }

        .non-arv-history {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #e7ebf3;
        }

        .non-arv-history-title {
            margin-bottom: 10px;
            color: #3c4966;
            font-size: 13px;
            font-weight: 750;
        }

        .non-arv-history-row {
            display: grid;
            grid-template-columns: minmax(150px, 1.6fr) 1fr 1fr;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid #edf0f5;
            color: #52607b;
            font-size: 12px;
        }

        .non-arv-history-row:last-child {
            border-bottom: 0;
        }

        .non-arv-history-row strong {
            color: #26345f;
        }

        /* =========================================================
           GLOBAL LOGOUT CONFIRMATION MODAL
           ========================================================= */
        .confirm-modal .modal-dialog {
            max-width: 500px !important;
            width: calc(100% - 30px);
            margin: 1.75rem auto;
        }

        .confirm-modal .modal-content {
            overflow: hidden !important;
            border: 0 !important;
            border-radius: 20px !important;
            background: #fff !important;
            box-shadow: 0 20px 55px rgba(31, 45, 110, 0.20) !important;
        }

        .confirm-modal .modal-header {
            display: block !important;
            padding: 26px 24px 6px !important;
            border: 0 !important;
            background: #fff !important;
            text-align: center !important;
        }

        .confirm-modal .modal-icon {
            width: 64px !important;
            height: 64px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 auto 14px !important;

            color: #fff !important;
            background: linear-gradient(135deg, #ef3340, #f05b68) !important;
            border-radius: 50% !important;

            box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
            font-size: 27px !important;
        }

        .confirm-modal .modal-icon.logout-icon {
            background: linear-gradient(135deg, #ef3340, #f05b68) !important;
            box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
        }

        .confirm-modal .modal-title {
            margin: 0 !important;
            color: #283a7a !important;
            font-size: 24px !important;
            font-weight: 700 !important;
            line-height: 1.3 !important;
        }

        .confirm-modal .modal-body {
            padding: 6px 30px 22px !important;
            background: #fff !important;
            color: #7a879e !important;
            text-align: center !important;
        }

        .confirm-modal .modal-body p {
            margin: 0 !important;
            color: #7a879e !important;
            font-size: 17px !important;
            line-height: 1.45 !important;
        }

        .confirm-modal .modal-footer {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 10px !important;
            padding: 0 24px 26px !important;
            border: 0 !important;
            background: #fff !important;
        }

        .confirm-modal .modal-footer .btn {
            min-height: 50px !important;
            margin: 0 !important;
            border-radius: 10px !important;
            font-size: 16px !important;
            font-weight: 700 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .confirm-modal .btn-light,
        .confirm-modal .btn-cancel {
            color: #111 !important;
            background: #f8f9fa !important;
            border: 1px solid #d9dfe8 !important;
        }

        .confirm-modal .btn-light:hover,
        .confirm-modal .btn-cancel:hover {
            background: #eef0f3 !important;
        }

        .confirm-modal .btn-danger,
        .confirm-modal .btn-logout {
            color: #fff !important;
            background: #e83445 !important;
            border: 1px solid #e83445 !important;
            text-decoration: none !important;
        }

        .confirm-modal .btn-danger:hover,
        .confirm-modal .btn-logout:hover {
            color: #fff !important;
            background: #d92839 !important;
            border-color: #d92839 !important;
        }

        /* Hide elements that are not part of the logout confirmation. */
        #logoutConfirmModal .confirmation-summary,
        #logoutConfirmModal .confirmation-warning {
            display: none !important;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }

            .topbar {
                padding: 0 22px;
            }

            .content {
                padding: 28px 22px 35px;
            }

            .topbar h3 small,
            .profile-role {
                display: none;
            }

            .queue-card {
                position: static;
                max-height: none;
            }

            .queue-list {
                max-height: 370px;
            }
        }

        @media (max-width: 767px) {
            .assessment-tabs {
                width: 100%;
                overflow-x: auto;
            }

            .assessment-tab {
                flex: 1 0 auto;
                justify-content: center;
            }

            .topbar {
                height: 70px;
                padding: 0 16px;
            }

            .topbar h3 {
                font-size: 20px;
            }

            .content {
                padding: 20px 14px 30px;
            }

            .content-card-header,
            .content-card-body {
                padding: 17px;
            }

            .registry-panel {
                align-items: stretch;
                flex-direction: column;
            }

            .registry-panel form,
            .registry-panel button {
                width: 100%;
            }

            .followup-table {
                min-width: 820px;
            }
        }

        @media (max-width: 576px) {
            .confirm-modal .modal-dialog {
                width: calc(100% - 20px);
                margin: 10px auto;
            }

            .confirm-modal .modal-header {
                padding: 22px 18px 6px !important;
            }

            .confirm-modal .modal-icon {
                width: 58px !important;
                height: 58px !important;
                margin-bottom: 12px !important;
                font-size: 24px !important;
            }

            .confirm-modal .modal-title {
                font-size: 21px !important;
            }

            .confirm-modal .modal-body {
                padding: 6px 20px 18px !important;
            }

            .confirm-modal .modal-body p {
                font-size: 15px !important;
            }

            .confirm-modal .modal-footer {
                padding: 0 18px 20px !important;
            }

            .confirm-modal .modal-footer .btn {
                min-height: 46px !important;
                font-size: 15px !important;
            }
        }

        @media (max-width: 520px) {
            .profile span {
                display: none;
            }

            .form-actions .btn {
                width: 100%;
            }
        }
    
        .bite-map-panel { border: 1px solid #d6e1e8; border-radius: 12px; padding: 18px; background: #f8fafc; }
        .bite-map-stage { position: relative; width: min(100%, 340px); margin: 12px auto; background: #526778; border-radius: 10px; overflow: hidden; }
        .bite-map-stage img { display: block; width: 100%; height: auto; cursor: crosshair; user-select: none; }
        .bite-map-stage img[hidden] { display: none; }
        .bite-map-stage img { -webkit-user-select: none; -webkit-touch-callout: none; touch-action: manipulation; }
        .bite-map-marker { position: absolute; transform: translate(-50%, -50%); width: 28px; height: 28px; padding: 0; border: 2px solid white; border-radius: 50%; background: #b91c1c; color: white; font-size: 12px; font-weight: bold; box-shadow: 0 1px 4px #0008; cursor: pointer; }
        .bite-map-marker:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
        .bite-map-list { max-height: 250px; overflow-y: auto; padding-left: 24px; }
        .bite-map-list li { margin-bottom: 8px; }
        .bite-map-views .btn { font-weight: 600; }
        .bite-map-view-label { position: absolute; top: 8px; left: 10px; z-index: 2; padding: 2px 8px; border-radius: 6px; background: rgba(255,255,255,.85); color: #2b3a8c; font-size: 11px; font-weight: 800; letter-spacing: .06em; pointer-events: none; }
        .bite-map-list li.view-back::marker { color: #8a4b00; font-weight: 700; }
        .bite-map-list li.view-front::marker { color: #2b3a8c; font-weight: 700; }

    </style>
    <script src="sources/manila_date.js"></script>
</head>

<body>

<!-- ========== SIDEBAR ========== -->
<aside class="sidebar" id="sidebar">

    <button type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            aria-expanded="true">
        <i class="bi bi-chevron-left"></i>
    </button>

    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>

        <div class="system-name">
            Smart Bite Care
        </div>
    </div>

    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li>
                <a href="Nurse_Dashboard.php">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Calendar.php">
                    <i class="bi bi-calendar3"></i>
                    <span>Calendar</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Patients.php">
                    <i class="bi bi-heart-pulse-fill"></i>
                    <span>Patients</span>
                </a>
            </li>

            <li>
                <a
                    class="active"
                    href="Nurse_Assessment.php"
                    aria-current="page"
                >
                    <i class="bi bi-clipboard2-pulse-fill"></i>
                    <span>Assessment Queue</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Vaccination.php">
                    <i class="bi bi-shield-plus"></i>
                    <span>Vaccination</span>
                </a>
            </li>

            <li>
                <a href="Nurse_DailyInventory.php">
                    <i class="bi bi-clipboard-data-fill"></i>
                    <span>Daily Inventory</span>
                </a>
            </li>

            <li>
                <a href="Nurse_MedicalSuppliesManagement.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Medical Supplies Management</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Supplyforecasting.php">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Supply Forecasting</span>
                </a>
            </li>

            <li><a  href="Nurse_Notification.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a></li>
        </ul>
    </nav>

</aside>

<!-- ============================================================
     MAIN
     ============================================================ -->
<main class="main">

    <!-- ========================================================
         TOPBAR
         ======================================================== -->
    <div class="topbar">

        <h3>
            Nurse Assessment
            <small>
                <?= workflowH((string)($user['branch_name'] ?? $branchId)) ?>
            </small>
        </h3>

        <div class="dropdown">

            <button
                class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                type="button"
                id="nurseProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-person-circle"></i>

                <span>
                    <?php
                    echo htmlspecialchars(
                        $user['username'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                </span>

                <span
                    style="
                        font-size:12px;
                        color:#adb5bd;
                        font-weight:400;
                        margin-left:4px;
                    "
                >
                    | Nurse
                </span>
            </button>

            <ul
                class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                aria-labelledby="nurseProfileMenu"
            >
                <li>
                    <h6 class="dropdown-header">
                        Account options
                    </h6>
                </li>

                <li>
                    <a
                        class="dropdown-item rounded-2 py-2"
                        href="Account_ChangePassword.php"
                    >
                        <i class="bi bi-key-fill me-2"></i>
                        Change Password
                    </a>
                </li>

                <li>
                    <hr class="dropdown-divider">
                </li>

                <li>
                    <a
                        class="dropdown-item rounded-2 py-2 text-danger"
                        href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#logoutConfirmModal"
                    >
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Logout
                    </a>
                </li>
            </ul>

        </div>

    </div>

    <!-- ========================================================
         CONTENT
         ======================================================== -->
    <div class="content">

        <!-- Flash Message -->
        <?php if ($flash): ?>

            <div
                class="alert alert-<?= workflowH((string)$flash['type']) ?> alert-dismissible fade show"
                role="alert"
            >
                <?= workflowH((string)$flash['message']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"
                ></button>
            </div>

        <?php endif; ?>

        <!-- ====================================================
             CURRENT QUEUE / MISSED FOLLOW-UP TABS
             ==================================================== -->
        <ul
            class="nav nav-tabs module-tabs"
            id="assessmentModuleTabs"
            role="tablist"
        >
            <li class="nav-item" role="presentation">
                <a
                    class="nav-link <?= $activeTab === 'queue' ? 'active' : '' ?>"
                    href="Nurse_Assessment.php?tab=queue"
                    role="tab"
                    aria-selected="<?= $activeTab === 'queue' ? 'true' : 'false' ?>"
                >
                    <i class="bi bi-people-fill me-1"></i>
                    Current Queue

                    <span class="tab-count">
                        <?= (int)$queueTotal ?>
                    </span>
                </a>
            </li>

            <li class="nav-item" role="presentation">
                <a
                    class="nav-link <?= $activeTab === 'followups' ? 'active' : '' ?>"
                    href="Nurse_Assessment.php?tab=followups"
                    role="tab"
                    aria-selected="<?= $activeTab === 'followups' ? 'true' : 'false' ?>"
                >
                    <i class="bi bi-calendar-x-fill me-1"></i>
                    Missed / Follow-ups

                    <span class="tab-count">
                        <?= (int)$followupTotal ?>
                    </span>
                </a>
            </li>
        </ul>

        <?php if ($activeTab === 'followups'): ?>

            <!-- ================================================
                 MISSED / FOLLOW-UP TAB
                 ================================================ -->
            <div class="content-card followup-card">

                <div class="content-card-header">

                    <div>
                        <h2>
                            <span class="section-icon">
                                <i class="bi bi-calendar-x-fill"></i>
                            </span>

                            Missed Vaccination Follow-ups
                        </h2>

                        <p>
                            Review patients who missed a scheduled vaccination.
                        </p>
                    </div>

                    <span class="badge bg-danger rounded-pill">
                        <?= $followupTotal ?>
                    </span>

                </div>

                <div class="content-card-body">

                    <div class="alert followup-policy small mb-3">

                        <i
                            class="bi bi-info-circle-fill me-1"
                            style="color:var(--primary);"
                        ></i>

                        <strong>Clinic rule:</strong>

                        a patient up to

                        <strong>
                            <?= (int)$catchUpWindowDays ?> days
                        </strong>

                        late gets the dose on the actual date in Vaccination, and
                        every remaining dose moves forward by the same number of days.

                        More than

                        <strong>
                            <?= (int)$catchUpWindowDays ?> days
                        </strong>

                        late: the nurse chooses to continue the current schedule (remaining
                        doses move forward) or re-assess and restart from Day 0. The system
                        never restarts a cycle by itself.

                        Previous vaccination records are archived, not deleted.

                    </div>

                    <?php if (!$overdueCases): ?>

                        <div class="empty-state">

                            <i class="bi bi-calendar-check"></i>

                            <strong>
                                No missed follow-ups
                            </strong>

                            <span>
                                There are currently no overdue vaccination
                                schedules requiring follow-up.
                            </span>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table followup-table align-middle">

                                <thead>
                                    <tr>
                                        <th>Patient</th>
                                        <th>Case</th>
                                        <th>Oldest Missed Date</th>
                                        <th>Missed Doses</th>
                                        <th>Days Overdue</th>
                                        <th>Workflow Status</th>
                                    </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($overdueCases as $overdue): ?>

                                    <?php
                                    $daysOverdue =
                                        (int)$overdue['days_overdue'];

                                    $restartRequired =
                                        $overdue['workflow_classification']
                                        === 'restart_required';
                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= workflowH(
                                                    (string)$overdue['full_name']
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= workflowH(
                                                (string)$overdue['case_number']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php
                                            if (!empty($overdue['oldest_due'])) {
                                                echo workflowH(
                                                    date(
                                                        'M d, Y',
                                                        strtotime(
                                                            (string)$overdue['oldest_due']
                                                        )
                                                    )
                                                );
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>

                                        <td>
                                            <?= (int)$overdue['missed_dose_count'] ?>
                                        </td>

                                        <td>

                                            <strong
                                                class="days-overdue <?= $restartRequired
                                                    ? 'restart'
                                                    : 'catchup'
                                                ?>"
                                            >
                                                <?= $daysOverdue ?>

                                                day<?= $daysOverdue === 1
                                                    ? ''
                                                    : 's'
                                                ?>
                                            </strong>

                                        </td>

                                        <td>

                                            <?php if ($restartRequired): ?>

                                                <span class="followup-status restart">
                                                    <i class="bi bi-signpost-split"></i>
                                                    Over <?= (int)$catchUpWindowDays ?> Days - Nurse Decision
                                                </span>

                                            <?php else: ?>

                                                <span class="followup-status catchup">
                                                    <i class="bi bi-clock-history"></i>
                                                    Missed - Catch-up Eligible
                                                </span>

                                            <?php endif; ?>

                                            <div class="followup-note">
                                                <?= workflowH(
                                                    (string)$overdue['workflow_description']
                                                ) ?>
                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <?php if ($followupTotalPages > 1): ?>
                            <div class="pagination-wrap">
                                <nav aria-label="Missed follow-up pagination">
                                    <ul class="pagination mb-0">

                                        <li class="page-item <?php echo ($followupPage <= 1) ? 'disabled' : ''; ?>">
                                            <a
                                                class="page-link"
                                                href="?tab=followups&followup_page=<?php echo max(1, $followupPage - 1); ?>"
                                                aria-label="Previous"
                                            >
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>

                                        <?php for ($i = 1; $i <= $followupTotalPages; $i++): ?>
                                            <li class="page-item <?php echo ($i === $followupPage) ? 'active' : ''; ?>">
                                                <a
                                                    class="page-link"
                                                    href="?tab=followups&followup_page=<?php echo $i; ?>"
                                                >
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo ($followupPage >= $followupTotalPages) ? 'disabled' : ''; ?>">
                                            <a
                                                class="page-link"
                                                href="?tab=followups&followup_page=<?php echo min($followupTotalPages, $followupPage + 1); ?>"
                                                aria-label="Next"
                                            >
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>

                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                        <div class="pagination-info">
                            <?php if ($followupTotal > 0): ?>
                                Showing
                                <?php echo $followupOffset + 1; ?>
                                -
                                <?php echo min($followupOffset + $followupPerPage, $followupTotal); ?>
                                of
                                <?php echo $followupTotal; ?>
                                follow-up case<?php echo ($followupTotal === 1) ? '' : 's'; ?>
                            <?php else: ?>
                                0 follow-up cases
                            <?php endif; ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php else: ?>

        <!-- ====================================================
             NURSE ASSESSMENT QUEUE + ASSESSMENT FORM
             ==================================================== -->
        <div class="row g-4">

            <!-- Assessment Queue -->
            <section class="col-xl-4 col-lg-5">

                <div class="content-card queue-card">

                    <div class="content-card-header">

                        <div>
                            <h2>
                                <span class="section-icon">
                                    <i class="bi bi-people-fill"></i>
                                </span>

                                Assessment Queue
                            </h2>

                            <p>
                                <?= $queueTotal ?>

                                patient<?= $queueTotal === 1 ? '' : 's' ?>

                                awaiting nurse action
                            </p>
                        </div>

                    </div>

                    <?php if (!$queue): ?>

                        <div class="empty-state">

                            <i class="bi bi-person-check"></i>

                            <strong>
                                No patients waiting
                            </strong>

                            <span>
                                The queue is currently clear.
                            </span>

                        </div>

                    <?php else: ?>

                        <div class="list-group list-group-flush queue-list">

                            <?php foreach ($queue as $row): ?>
                                <?php $queueReady = $readyVisits[(int)$row['case_id']] ?? null;
                                if ($queueReady && (int)$queueReady['visit_id'] === (int)$row['visit_id']): ?>
                                    <div class="list-group-item queue-item queue-ready">
                                        <a class="d-block text-decoration-none text-reset" href="<?= workflowH($queueReady['vaccinate_url']) ?>" title="Scheduled follow-up dose: no new assessment needed">
                                            <span class="queue-name"><?= workflowH((string)$row['full_name']) ?></span>
                                            <span class="queue-detail"><?= workflowH($row['case_number'] . ' | ' . $row['visit_type'] . ' | ' . $queueReady['dose']['dose_display']) ?></span>
                                            <span class="status-pill status-ready"><i class="bi bi-check-circle-fill me-1"></i>Checked in – Ready: Vaccinate</span>
                                        </a>
                                        <a class="small text-muted" href="Nurse_Assessment.php?tab=queue&visit_id=<?= (int)$row['visit_id'] ?>">Re-assess instead</a>
                                    </div>
                                    <?php continue; ?>
                                <?php endif; ?>

                                <a
                                    class="
                                        list-group-item
                                        list-group-item-action
                                        queue-item
                                        <?= $selectedId === (int)$row['visit_id']
                                            ? 'active'
                                            : ''
                                        ?>
                                    "
                                    href="Nurse_Assessment.php?tab=queue&visit_id=<?= (int)$row['visit_id'] ?>"
                                >

                                    <span class="queue-name">
                                        <?= workflowH(
                                            (string)$row['full_name']
                                        ) ?>
                                    </span>

                                    <span class="queue-detail">
                                        <?= workflowH(
                                            $row['case_number']
                                            . ' | '
                                            . $row['visit_type']
                                        ) ?>
                                    </span>

                                    <span class="status-pill">
                                        <?= workflowH(
                                            (string)$row['workflow_status']
                                        ) ?>
                                    </span>

                                </a>

                            <?php endforeach; ?>

                        </div>

                        <?php if ($queueTotalPages > 1): ?>
                            <div class="pagination-wrap">
                                <nav aria-label="Assessment queue pagination">
                                    <ul class="pagination mb-0">

                                        <li class="page-item <?php echo ($queuePage <= 1) ? 'disabled' : ''; ?>">
                                            <a
                                                class="page-link"
                                                href="?tab=queue&queue_page=<?php echo max(1, $queuePage - 1); ?>"
                                                aria-label="Previous"
                                            >
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>

                                        <?php for ($i = 1; $i <= $queueTotalPages; $i++): ?>
                                            <li class="page-item <?php echo ($i === $queuePage) ? 'active' : ''; ?>">
                                                <a
                                                    class="page-link"
                                                    href="?tab=queue&queue_page=<?php echo $i; ?>"
                                                >
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo ($queuePage >= $queueTotalPages) ? 'disabled' : ''; ?>">
                                            <a
                                                class="page-link"
                                                href="?tab=queue&queue_page=<?php echo min($queueTotalPages, $queuePage + 1); ?>"
                                                aria-label="Next"
                                            >
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>

                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                        <div class="pagination-info">
                            <?php if ($queueTotal > 0): ?>
                                Showing
                                <?php echo $queueOffset + 1; ?>
                                -
                                <?php echo min($queueOffset + $queuePerPage, $queueTotal); ?>
                                of
                                <?php echo $queueTotal; ?>
                                patient<?php echo ($queueTotal === 1) ? '' : 's'; ?>
                            <?php else: ?>
                                0 patients
                            <?php endif; ?>
                        </div>

                    <?php endif; ?>

                </div>

            </section>

            <!-- Selected Patient Assessment -->
            <section class="col-xl-8 col-lg-7">

                <?php if (!$selected): ?>

                    <div class="content-card">

                        <div class="empty-state">

                            <i class="bi bi-clipboard2-pulse"></i>

                            <strong>
                                Select a patient
                            </strong>

                            <span>
                                Choose a waiting patient from the assessment
                                queue to begin charting.
                            </span>

                        </div>

                    </div>

                <?php else: ?>

                    <div class="content-card">

                        <div class="content-card-header patient-heading">

                            <div>

                                <h2>

                                    <span class="section-icon">
                                        <i class="bi bi-person-vcard-fill"></i>
                                    </span>

                                    <?= workflowH(
                                        (string)$selected['full_name']
                                    ) ?>

                                </h2>

                                <p>
                                    Clinical assessment and treatment schedule
                                </p>

                            </div>

                            <span class="case-badge">
                                <?= workflowH(
                                    (string)$selected['case_number']
                                ) ?>
                            </span>

                        </div>

                        <div class="content-card-body">

                            <form method="post" class="row g-3" id="assessmentForm">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= workflowH($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="visit_id"
                                    value="<?= $selectedId ?>"
                                >

                                <?php if ($selectedLateDoses && $selectedLateDoses['rows']):
                                    $lateChosen = (string)($_GET['late_decision'] ?? '');
                                    $storedChoices = array_unique(array_map(static fn($r) => (string)$r['late_decision'], $selectedLateDoses['rows']));
                                    if (!in_array($lateChosen, ['continue', 'restart'], true)) {
                                        $lateChosen = count($storedChoices) === 1 ? (string)reset($storedChoices) : '';
                                    }
                                ?>
                                <!-- Late dose (> 7 days): the nurse decides, never automatic.
                                     Top of the form, right below the page alert; never hidden by script. -->
                                <div class="col-12" id="lateDecisionPanel" data-late-decision-panel="1"
                                     data-all-continue="<?= workflowLateDoseDecisionNeeded($selectedLateDoses) ? '0' : '1' ?>">
                                    <div class="alert alert-warning mb-0">
                                        <strong><i class="bi bi-signpost-split me-1"></i>Late Dose Decision</strong>
                                        <p class="small mb-2">
                                            <?php foreach ($selectedLateDoses['rows'] as $lateRow): ?>
                                                <?= workflowH(workflowDoseLabel((int)$lateRow['dose_number'])) ?> was due
                                                <?= workflowH(date('M d, Y', strtotime((string)$lateRow['scheduled_date']))) ?>
                                                (<?= (int)$lateRow['days_late'] ?> days late<?= $lateRow['late_decision'] ? ', ' . workflowH(workflowLateDecisionLabel((string)$lateRow['late_decision'])) . ' chosen' : '' ?>).
                                            <?php endforeach; ?>
                                            This is more than <?= (int)$catchUpWindowDays ?> days. Choose how to proceed before saving the anti-rabies assessment:
                                        </p>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="late_decision" id="lateDecisionContinue" value="continue" <?= $lateChosen === 'continue' ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="lateDecisionContinue">
                                                <strong>Continue current schedule</strong> - keep the same Day 0; record the late dose in Vaccination and the remaining doses move forward by the delay.
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="late_decision" id="lateDecisionRestart" value="restart" <?= $lateChosen === 'restart' ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="lateDecisionRestart">
                                                <strong>Restart from Day 0</strong> - set Actual D0 Date to today's re-assessment; the old cycle's records are archived (never deleted).
                                            </label>
                                        </div>
                                        <input type="text" class="form-control form-control-sm mt-2" name="late_decision_remark" maxlength="500"
                                               placeholder="Remark (optional), e.g. Continued per nurse/physician decision.">
                                    </div>
                                </div>
                                <?php endif; ?>


                                <!-- Exposure Information -->
                                <div class="col-12 form-section">

                                    <h3 class="form-section-title">
                                        <i class="bi bi-journal-medical me-1"></i>
                                        Exposure Information
                                    </h3>

                                    <?php if (!empty($selected['prefilled_from_previous'])): ?>
                                        <p class="small text-muted mb-0">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Follow-up visit: details were filled in from this case's latest assessment. Review them before saving.
                                        </p>
                                    <?php endif; ?>

                                </div>

                                <div class="col-12">

                                    <label
                                        class="form-label required"
                                        for="exposure_history"
                                    >
                                        History of Incident/Exposure
                                    </label>

                                    <textarea
                                        class="form-control"
                                        id="exposure_history"
                                        name="exposure_history"
                                        rows="3"
                                        required
                                    ><?= workflowH(
                                        (string)($selected['exposure_history'] ?? '')
                                    ) ?></textarea>

                                </div>

                                <?php
                                $biteTypeValue = (string)($selected['bite_type'] ?? '');
                                $woundTypeValue = (string)($selected['wound_type'] ?? '');
                                ?>

                                <div class="col-md-4">

                                    <label class="form-label" for="bite_type">
                                        Type of Bite
                                    </label>

                                    <select class="form-select" id="bite_type" name="bite_type">
                                        <option value="">Select type</option>
                                        <?php foreach (ASSESSMENT_BITE_TYPES as $option): ?>
                                            <option value="<?= workflowH($option) ?>" <?= $biteTypeValue === $option ? 'selected' : '' ?>>
                                                <?= workflowH($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <small class="text-muted d-block mt-1">
                                        Non-Bite: e.g. puncture from a rusty object, or Hepa B, Flu and PPD services. Animal fields are optional.
                                    </small>

                                </div>

                                <div class="col-md-4">

                                    <label
                                        class="form-label"
                                        for="date_of_exposure"
                                    >
                                        Exposure Date
                                    </label>

                                    <input
                                        type="date"
                                        class="form-control"
                                        id="date_of_exposure"
                                        name="date_of_exposure"
                                        value="<?= workflowH(
                                            (string)(
                                                $selected['date_of_exposure']
                                                ?? $selected['date_of_bite']
                                                ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label" for="wound_type">
                                        Type of Wound
                                    </label>

                                    <select class="form-select" id="wound_type" name="wound_type">
                                        <option value="">Select type</option>
                                        <?php foreach (ASSESSMENT_WOUND_TYPES as $option): ?>
                                            <option value="<?= workflowH($option) ?>" <?= $woundTypeValue === $option ? 'selected' : '' ?>>
                                                <?= workflowH($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                </div>

                                <div class="col-md-8">

                                    <label
                                        class="form-label"
                                        for="exposure_site"
                                    >
                                        Site of Bite
                                    </label>

                                    <input
                                        class="form-control"
                                        id="exposure_site"
                                        name="exposure_site"
                                        maxlength="255"
                                        placeholder="e.g. Left forearm, front"
                                        value="<?= workflowH(
                                            (string)(
                                                $selected['exposure_site']
                                                ?? $selected['bite_location']
                                                ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label
                                        class="form-label"
                                        for="bite_category"
                                    >
                                        Bite Category
                                    </label>

                                    <select
                                        class="form-select"
                                        id="bite_category"
                                        name="bite_category"
                                    >

                                        <option value="">
                                            Select category
                                        </option>

                                        <?php
                                        foreach (
                                            [
                                                'I',
                                                'II',
                                                'III',
                                                'Not Applicable'
                                            ]
                                            as $category
                                        ):
                                        ?>

                                            <option
                                                value="<?= workflowH($category) ?>"
                                                <?= ($selected['bite_category'] ?? '') === $category
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >
                                                <?= workflowH($category) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-12">
                                    <div class="bite-map-panel" id="biteMapPanel">
                                        <h6 class="mb-2">Bite Location Diagram</h6>
                                        <p class="small text-muted mb-3">Choose Front or Back, then click the body to place a dot on each bite location (both views can be marked in one assessment). Each dot is named after the body part, e.g. "Back – Left calf", and Site of Bite above is filled from the dots (you can still edit it). Click a numbered dot or its Remove button to delete it.</p>
                                        <input type="hidden" id="bite_markers" name="bite_markers" value="<?= workflowH((string)($selected['bite_markers'] ?? '[]')) ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="btn-group bite-map-views w-100" role="group" aria-label="Body view">
                                                    <button type="button" class="btn btn-outline-primary active" data-view="front" aria-pressed="true">Front <span class="badge text-bg-light ms-1" data-view-count="front">0</span></button>
                                                    <button type="button" class="btn btn-outline-primary" data-view="back" aria-pressed="false">Back <span class="badge text-bg-light ms-1" data-view-count="back">0</span></button>
                                                </div>
                                                <div class="bite-map-stage" id="biteMapStage" data-view="front">
                                                    <span class="bite-map-view-label" id="biteMapViewLabel">FRONT</span>
                                                    <img id="biteMapImage" data-view="front" src="images/human-figure.png" alt="Front view of the body for marking bite locations" draggable="false">
                                                    <img id="biteMapImageBack" data-view="back" src="images/human-figure-back.png" alt="Back view of the body for marking bite locations" draggable="false" hidden>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div id="biteMapStatus" role="status" aria-live="polite" class="small mb-2"></div>
                                                <ol id="biteMapList" class="bite-map-list"></ol>
                                                <button type="button" id="biteMapClear" class="btn btn-outline-danger btn-sm">Clear all markers</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $animalValue = trim((string)($selected['animal_type'] ?? ''));
                                $animalIsOther = $animalValue !== '' && !in_array($animalValue, ASSESSMENT_ANIMAL_OPTIONS, true);
                                $animalStatusValue = trim((string)($selected['animal_status'] ?? ''));
                                $animalStatusIsOther = $animalStatusValue !== '' && !in_array($animalStatusValue, ASSESSMENT_ANIMAL_STATUS_OPTIONS, true);
                                ?>

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="animal_type"
                                    >
                                        Biting Animal
                                    </label>

                                    <select class="form-select" id="animal_type" name="animal_type" data-other-target="animal_type_other">
                                        <option value="">Select animal</option>
                                        <?php foreach (ASSESSMENT_ANIMAL_OPTIONS as $option): ?>
                                            <option value="<?= workflowH($option) ?>" <?= $animalValue === $option ? 'selected' : '' ?>>
                                                <?= workflowH($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="Others" <?= $animalIsOther ? 'selected' : '' ?>>Others (specify)</option>
                                    </select>

                                    <input
                                        class="form-control mt-2"
                                        id="animal_type_other"
                                        name="animal_type_other"
                                        maxlength="100"
                                        placeholder="Specify the animal or exposure source"
                                        value="<?= workflowH($animalIsOther ? $animalValue : '') ?>"
                                        <?= $animalIsOther ? '' : 'style="display:none;"' ?>
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="animal_status"
                                    >
                                        Status of the Biting Animal
                                    </label>

                                    <select class="form-select" id="animal_status" name="animal_status" data-other-target="animal_status_other">
                                        <option value="">Select status</option>
                                        <?php foreach (ASSESSMENT_ANIMAL_STATUS_OPTIONS as $option): ?>
                                            <option value="<?= workflowH($option) ?>" <?= $animalStatusValue === $option ? 'selected' : '' ?>>
                                                <?= workflowH($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="Others" <?= $animalStatusIsOther ? 'selected' : '' ?>>Others (specify)</option>
                                    </select>

                                    <input
                                        class="form-control mt-2"
                                        id="animal_status_other"
                                        name="animal_status_other"
                                        maxlength="100"
                                        placeholder="Specify the animal status"
                                        value="<?= workflowH($animalStatusIsOther ? $animalStatusValue : '') ?>"
                                        <?= $animalStatusIsOther ? '' : 'style="display:none;"' ?>
                                    >

                                </div>

                                <!-- Treatment Plan -->
                                <div class="col-12 form-section">

                                    <h3 class="form-section-title">
                                        <i class="bi bi-shield-plus me-1"></i>
                                        Treatment Plan
                                    </h3>

                                </div>

                                <div class="col-12">
                                    <div class="non-arv-toggle">
                                        <div class="form-check mb-0">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                id="not_anti_rabies"
                                                name="not_anti_rabies"
                                                value="1"
                                                <?= $selectedNonRabies
                                                    ? 'checked'
                                                    : ''
                                                ?>
                                            >

                                            <label
                                                class="form-check-label"
                                                for="not_anti_rabies"
                                            >
                                                Not Anti-Rabies Vaccine
                                            </label>
                                        </div>

                                        <small>
                                            Check this for a clinic-designated
                                            one-time/non-anti-rabies administration.
                                            The PEP/PrEP/Booster profile and D0 schedule
                                            fields will be disabled, and no anti-rabies
                                            schedule will be generated.
                                        </small>
                                    </div>
                                </div>

                                <?php
                                $currentProfile = strtoupper(trim((string)($selected['treatment_profile'] ?? '')));
                                $regimenChoices = workflowRegimenOptions();
                                if (in_array($currentProfile, ['PREP', 'BOOSTER'], true)) {
                                    // Legacy regimen without a recorded route stays selectable for this record.
                                    $regimenChoices = [$currentProfile => workflowProfileLabel($currentProfile, (string)($selected['route'] ?? '')) . ' (saved earlier)'] + $regimenChoices;
                                }
                                $regimenStages = [];
                                foreach (array_keys($regimenChoices) as $choice) {
                                    $stageList = [];
                                    $sequence = workflowDoseSequenceMap($choice);
                                    foreach (workflowScheduleStages($choice) as $doseNumber => $offset) {
                                        $stageList[] = ['dose' => (int)$doseNumber, 'offset' => (int)$offset,
                                            'label' => workflowDoseDisplay((int)$doseNumber, $sequence)];
                                    }
                                    $regimenStages[$choice] = $stageList;
                                }
                                $activeRegimenValue = trim((string)($selected['active_regimen'] ?? ''));
                                $activeRegimenIsOther = $activeRegimenValue !== '' && !in_array($activeRegimenValue, ASSESSMENT_ACTIVE_REGIMENS, true);
                                $previousVaccinationValue = (string)($selected['previous_vaccination_date'] ?? '');
                                $outsideClinicValue = (string)($selected['outside_clinic_name'] ?? '');
                                ?>

                                <div class="col-md-6">

                                    <label
                                        class="form-label required"
                                        for="treatment_profile"
                                    >
                                        Vaccination Regimen
                                    </label>

                                    <select
                                        class="form-select"
                                        id="treatment_profile"
                                        name="treatment_profile"
                                        <?= $selectedNonRabies
                                            ? 'disabled'
                                            : 'required'
                                        ?>
                                    >

                                        <option value="">
                                            Select anti-rabies regimen
                                        </option>

                                        <?php foreach ($regimenChoices as $value => $label): ?>

                                            <option
                                                value="<?= workflowH($value) ?>"
                                                <?= $currentProfile === $value
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >
                                                <?= workflowH($label) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <!-- The route (ID/IM) comes from the selected regimen. -->
                                    <small class="text-muted d-block mt-1" id="regimenRouteHint">
                                        Route: <?= workflowH(workflowProfileRoute($currentProfile, (string)($selected['route'] ?? '')) ?: 'set by the regimen') ?>.
                                        ID uses 2 sites per regular session (Booster ID: 1 site); IM uses a full vial each session.
                                    </small>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        class="form-label required"
                                        for="d0_date"
                                        id="d0_date_label"
                                    >
                                        Actual D0 Date
                                    </label>

                                    <input
                                        type="date"
                                        class="form-control"
                                        id="d0_date"
                                        name="d0_date"
                                        max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
                                        value="<?= $selectedNonRabies
                                            ? ''
                                            : workflowH(
                                                (string)(
                                                    $selected['d0_date']
                                                    ?? date('Y-m-d')
                                                )
                                            )
                                        ?>"
                                        <?= $selectedNonRabies
                                            ? 'disabled'
                                            : 'required'
                                        ?>
                                    >

                                </div>

                                <!-- Booster eligibility -->
                                <div class="col-12" id="boosterEligibilityPanel" style="display:none;">
                                    <div class="non-arv-toggle">
                                        <div class="row g-2 align-items-end w-100">
                                            <div class="col-md-5">
                                                <label class="form-label" for="previous_vaccination_date">
                                                    Date of previous anti-rabies vaccination
                                                </label>
                                                <input
                                                    type="date"
                                                    class="form-control"
                                                    id="previous_vaccination_date"
                                                    name="previous_vaccination_date"
                                                    max="<?= date('Y-m-d') ?>"
                                                    value="<?= workflowH($previousVaccinationValue) ?>"
                                                >
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" id="previous_vaccination_unknown" name="previous_vaccination_unknown" value="1">
                                                    <label class="form-check-label" for="previous_vaccination_unknown">Unknown</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="alert alert-warning py-2 mb-0" id="boosterWarning" role="alert" style="display:none;"></div>
                                                <small class="text-muted" id="boosterHint">A booster is only valid if the previous anti-rabies vaccination was within the last 5 years.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Follow-up from another clinic -->
                                <div class="col-12" id="outsideFollowUpPanel">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="outside_follow_up"
                                            name="outside_follow_up"
                                            value="1"
                                            <?= $outsideClinicValue !== '' ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label" for="outside_follow_up">
                                            Follow-up from another clinic (vaccination started elsewhere)
                                        </label>
                                    </div>
                                    <div id="outsideFollowUpFields" class="mt-2" style="display:none;">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label" for="outside_clinic_name">Clinic where the earlier doses were given</label>
                                                <input class="form-control" id="outside_clinic_name" name="outside_clinic_name" maxlength="255" value="<?= workflowH($outsideClinicValue) ?>" placeholder="e.g. City Health Office ABTC">
                                            </div>
                                            <div class="col-12">
                                                <small class="text-muted d-block mb-1">
                                                    Tick the doses already given at the other clinic. "Actual D0 Date" above must be the original Day 0 there;
                                                    only the remaining doses are scheduled here and no stock is used for outside doses.
                                                </small>
                                                <div id="outsideDoseRows" class="d-grid gap-1"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="active_regimen"
                                    >
                                        Active Regimen
                                    </label>

                                    <select class="form-select" id="active_regimen" name="active_regimen" data-other-target="active_regimen_other">
                                        <option value="">Select active regimen</option>
                                        <?php foreach (ASSESSMENT_ACTIVE_REGIMENS as $option): ?>
                                            <option value="<?= workflowH($option) ?>" <?= $activeRegimenValue === $option ? 'selected' : '' ?>>
                                                <?= workflowH($option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="Other" <?= $activeRegimenIsOther ? 'selected' : '' ?>>Other (specify)</option>
                                    </select>

                                    <input
                                        class="form-control mt-2"
                                        id="active_regimen_other"
                                        name="active_regimen_other"
                                        maxlength="100"
                                        placeholder="Specify the product plan"
                                        value="<?= workflowH($activeRegimenIsOther ? $activeRegimenValue : '') ?>"
                                        <?= $activeRegimenIsOther ? '' : 'style="display:none;"' ?>
                                    >

                                </div>

                                <script>
                                    window.SBC_REGIMEN_STAGES = <?= json_encode($regimenStages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                                </script>

                                <!-- Non-Anti-Rabies / One-Time Administration -->
                                <div
                                    class="col-12"
                                    id="nonArvAdministrationPanel"
                                    <?= $selectedNonRabies
                                        ? ''
                                        : 'style="display:none;"'
                                    ?>
                                >
                                    <div class="non-arv-panel">

                                        <div class="non-arv-panel-title">
                                            <i class="bi bi-capsule-pill"></i>
                                            Administer One-Time / Non-Anti-Rabies Product
                                        </div>

                                        <div class="non-arv-panel-note">
                                            Choose the clinic-approved product that was actually
                                            administered and enter the actual quantity used.
                                            SmartBiteCare records the nurse's entry and inventory
                                            movement; it does not choose or recommend a dose.
                                        </div>

                                        <div class="row g-3">

                                            <div class="col-md-5">
                                                <label class="form-label" for="non_rabies_date_administered">
                                                    Administration Date
                                                </label>
                                                <input
                                                    type="date"
                                                    class="form-control"
                                                    id="non_rabies_date_administered"
                                                    name="non_rabies_date_administered"
                                                    value="<?= date('Y-m-d') ?>"
                                                    max="<?= date('Y-m-d') ?>"
                                                >
                                            </div>

                                            <div class="col-12">
                                                <div id="nonArvEntries" class="d-grid gap-2">
                                                    <div class="non-arv-entry">
                                                        <div class="non-arv-entry-header">
                                                            <div class="non-arv-entry-title">Product 1</div>
                                                            <button
                                                                type="button"
                                                                class="non-arv-remove"
                                                                style="display:none;"
                                                            >
                                                                <i class="bi bi-trash3 me-1"></i>Remove
                                                            </button>
                                                        </div>

                                                        <div class="row g-3">
                                                            <div class="col-lg-5">
                                                                <label class="form-label">Vaccine / Product</label>
                                                                <select
                                                                    class="form-select non-arv-product-select"
                                                                    name="non_rabies_item_id[]"
                                                                >
                                                                    <option value="">Select administered product</option>
                                                                    <?php foreach ($nonRabiesItems as $item): ?>
                                                                        <?php
                                                                        $usable = (float)$item['usable_stock'];
                                                                        $baseLabel = inventoryBaseUnitLabel($item);
                                                                        $displayLabel = inventoryDisplayUnitLabel($item);
                                                                        $conversion = inventoryConversionToBase($item);
                                                                        ?>
                                                                        <option
                                                                            value="<?= (int)$item['item_id'] ?>"
                                                                            data-base-unit="<?= workflowH($baseLabel) ?>"
                                                                            data-display-unit="<?= workflowH($displayLabel) ?>"
                                                                            data-conversion="<?= workflowH((string)$conversion) ?>"
                                                                            data-step="<?= workflowH((string)$item['input_step']) ?>"
                                                                            data-stock="<?= workflowH((string)$usable) ?>"
                                                                            <?= $usable <= 0 ? 'disabled' : '' ?>
                                                                        >
                                                                            <?= workflowH((string)$item['item_name']) ?>
                                                                            — <?= workflowH((string)$item['usable_stock_display']) ?>
                                                                            <?= $usable <= 0 ? ' (No usable stock)' : '' ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div class="non-arv-stock-note">
                                                                    Select a product to see the inventory unit and available stock.
                                                                </div>
                                                            </div>

                                                            <div class="col-lg-3">
                                                                <label class="form-label">Actual Quantity Used</label>
                                                                <div class="input-group">
                                                                    <input
                                                                        type="number"
                                                                        class="form-control non-arv-quantity-input"
                                                                        name="non_rabies_quantity_base[]"
                                                                        min="0"
                                                                        step="0.0001"
                                                                        placeholder="0"
                                                                    >
                                                                    <span class="input-group-text non-arv-quantity-unit">unit</span>
                                                                </div>
                                                            </div>

                                                            <div class="col-lg-4">
                                                                <label class="form-label">Remarks</label>
                                                                <input
                                                                    type="text"
                                                                    class="form-control"
                                                                    name="non_rabies_remarks[]"
                                                                    placeholder="Optional remarks"
                                                                >
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-12 d-flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    class="btn non-arv-add-button"
                                                    id="addNonArvProductButton"
                                                >
                                                    <i class="bi bi-plus-circle me-1"></i>Add Another Product
                                                </button>

                                                <button
                                                    class="btn btn-success"
                                                    type="submit"
                                                    name="action"
                                                    value="administer_non_rabies"
                                                    id="administerNonArvButton"
                                                >
                                                    <i class="bi bi-check2-circle me-1"></i>Administer All
                                                </button>

                                                <span class="text-muted align-self-center" style="font-size:12px;">
                                                    All listed products are saved together. Actual branch stock is deducted per product using FEFO. No D0/D3/... anti-rabies schedule is generated.
                                                </span>
                                            </div>

                                        </div>

                                        <?php if (!empty($nonRabiesAdministrations)): ?>

                                            <div class="non-arv-history">

                                                <div class="non-arv-history-title">
                                                    Recorded one-time administrations for this visit
                                                </div>

                                                <?php foreach ($nonRabiesAdministrations as $adminRow): ?>

                                                    <div class="non-arv-history-row">

                                                        <strong>
                                                            <?= workflowH(
                                                                (string)$adminRow['item_name']
                                                            ) ?>
                                                        </strong>

                                                        <span>
                                                            <?= workflowH(
                                                                inventoryFormatNumber(
                                                                    (float)$adminRow['quantity_used']
                                                                )
                                                            ) ?>
                                                            <?= workflowH(
                                                                (string)$adminRow['quantity_unit_label']
                                                            ) ?>
                                                        </span>

                                                        <span>
                                                            <?= workflowH(
                                                                (string)$adminRow['date_administered']
                                                            ) ?>
                                                        </span>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>
                                </div>

                                <!-- Notes and Instructions -->
                                <div class="col-12 form-section">

                                    <h3 class="form-section-title">
                                        <i
                                            class="bi bi-clipboard2-check-fill me-1"
                                        ></i>

                                        Notes
                                    </h3>

                                </div>

                                <!--
                                    "Important Concerns/Escalation" and "Instruction Given" are hidden
                                    (redundant for the clinic). The columns are kept and existing values
                                    are posted back unchanged so older charts are not cleared.
                                -->
                                <input type="hidden" name="important_concerns" value="<?= workflowH((string)($selected['important_concerns'] ?? '')) ?>">
                                <input type="hidden" name="instructions_given" value="<?= workflowH((string)($selected['instructions_given'] ?? '')) ?>">

                                <div class="col-12">

                                    <label
                                        class="form-label"
                                        for="chart_notes"
                                    >
                                        Chart Notes
                                    </label>

                                    <textarea
                                        class="form-control"
                                        id="chart_notes"
                                        name="chart_notes"
                                        rows="2"
                                    ><?= workflowH(
                                        (string)(
                                            $selected['chart_notes']
                                            ?? ''
                                        )
                                    ) ?></textarea>

                                </div>

                                <div class="col-12 form-actions">

                                    <button
                                        class="btn btn-primary"
                                        type="submit"
                                        name="action"
                                        value="save_assessment"
                                        id="saveAssessmentButton"
                                    >
                                        <i class="bi bi-save-fill me-1"></i>

                                        <span id="saveAssessmentButtonText">
                                            <?= $selectedNonRabies
                                                ? 'Save Assessment - No ARV Schedule'
                                                : 'Save Assessment and Generate Schedule'
                                            ?>
                                        </span>
                                    </button>

                                    <a
                                        class="btn btn-outline-primary<?= $recordProducts['url'] === '' ? ' disabled' : '' ?>"
                                        id="recordProductsButton"
                                        href="<?= workflowH($recordProducts['url'] !== '' ? $recordProducts['url'] : '#recordProductsHint') ?>"
                                        aria-disabled="<?= $recordProducts['url'] === '' ? 'true' : 'false' ?>"
                                        aria-describedby="recordProductsHint"
                                        data-vaccinate-url="<?= workflowH($recordProducts['url']) ?>"
                                        data-block-reason="<?= workflowH($recordProducts['block']) ?>"
                                        data-dose="<?= workflowH($recordProducts['dose']) ?>"
                                    >
                                        <i class="bi bi-shield-check me-1"></i>

                                        Record Administered Products
                                    </a>

                                    <small class="text-muted align-self-center" id="recordProductsHint">
                                        <?= workflowH($recordProducts['url'] !== ''
                                            ? 'Opens Vaccination with this patient, case and ' . $recordProducts['dose'] . ' filled in.'
                                            : $recordProducts['block']) ?>
                                    </small>

                                </div>

                            </form>

                            <div class="registry-panel">

                                <div>
                                    <strong>
                                        Complete the patient chart
                                    </strong>

                                    <small>
                                        Sign the assessment and send it to
                                        Administrative Staff for registry
                                        verification.
                                    </small>
                                </div>

                                <button
                                    class="btn btn-success"
                                    type="button"
                                    data-bs-toggle="modal"
                                    data-bs-target="#registryConfirmModal"
                                >
                                    <i class="bi bi-send-check-fill me-1"></i>

                                    Complete and Send for Registry
                                </button>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>

            </section>

        </div>

        <?php endif; ?>

    </div>

</main>

<!-- ============================================================
     REGISTRY CONFIRMATION MODAL
     ============================================================ -->
<?php if ($selected): ?>

<div
    class="modal fade confirm-modal"
    id="registryConfirmModal"
    tabindex="-1"
    aria-labelledby="registryConfirmModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div class="modal-icon">
                    <i class="bi bi-clipboard2-check-fill"></i>
                </div>

                <h2
                    class="modal-title"
                    id="registryConfirmModalLabel"
                >
                    Complete patient chart?
                </h2>

            </div>

            <div class="modal-body">

                <p class="mb-0">
                    Please confirm that the assessment is complete and
                    ready for registry verification.
                </p>

                <div class="confirmation-summary">

                    <strong>
                        <?= workflowH(
                            (string)$selected['full_name']
                        ) ?>
                    </strong>

                    <span>
                        Case
                        <?= workflowH(
                            (string)$selected['case_number']
                        ) ?>
                    </span>

                </div>

                <div class="confirmation-warning">

                    <i class="bi bi-exclamation-triangle-fill"></i>

                    <span>
                        This will sign the chart and send it to
                        Administrative Staff. Review all information
                        before continuing.
                    </span>

                </div>

            </div>

            <form
                method="post"
                id="registryConfirmationForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= workflowH($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="send_registry"
                >

                <input
                    type="hidden"
                    name="visit_id"
                    value="<?= $selectedId ?>"
                >

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light border"
                        data-bs-dismiss="modal"
                    >
                        <i class="bi bi-x-circle me-1"></i>
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-confirm"
                        id="confirmRegistryButton"
                    >
                        <i class="bi bi-check-circle-fill me-1"></i>
                        Yes, Complete Chart
                    </button>

                </div>

            </form>

        </div>

    </div>
</div>

<?php endif; ?>

<!-- ============================================================
     LOGOUT CONFIRMATION MODAL
     ============================================================ -->
<div
    class="modal fade confirm-modal"
    id="logoutConfirmModal"
    tabindex="-1"
    aria-labelledby="logoutConfirmModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2
                    class="modal-title"
                    id="logoutConfirmModalLabel"
                >
                    Log out of Smart Bite Care?
                </h2>

            </div>

            <div class="modal-body">

                <p class="mb-0">
                    Make sure you have saved any unfinished work
                    before leaving your account.
                </p>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="logout.php"
                    class="
                        btn
                        btn-danger
                        d-flex
                        align-items-center
                        justify-content-center
                    "
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>

            </div>

        </div>

    </div>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script src="sources/sidebar.js"></script>
<?php if ($selected): ?>
<script>
/*
 * Assessment draft auto-save (per nurse and per visit, in this browser's
 * localStorage) and clinical form behaviour. Registered before the other
 * page scripts so a restored draft is in place before they read the form.
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('assessmentForm');
    if (!form) return;

    const draftKey = 'sbc_assessment_draft_v1_<?= (int)$userId ?>_<?= (int)$selectedId ?>';
    const pendingKey = 'sbc_assessment_pending_<?= (int)$userId ?>_<?= (int)$selectedId ?>';
    const serverSavedAt = <?= !empty($selected['assessment_updated_at']) ? (int)strtotime((string)$selected['assessment_updated_at']) * 1000 : 0 ?>;
    const lastSaveSucceeded = <?= ($flash && in_array($flash['type'], ['success', 'warning'], true)) ? 'true' : 'false' ?>;
    // The late-dose choice is never drafted: it must be made explicitly each time,
    // and a restored draft can never uncheck, change or hide it.
    const skipped = new Set(['csrf_token', 'visit_id', 'action', 'late_decision', 'late_decision_remark']);

    function storage(action, key, value) {
        try {
            if (action === 'get') return window.localStorage.getItem(key);
            if (action === 'set') window.localStorage.setItem(key, value);
            if (action === 'remove') window.localStorage.removeItem(key);
        } catch (_) {}
        return null;
    }

    function collect() {
        const values = {};
        Array.from(form.elements).forEach(function (el) {
            if (!el.name || skipped.has(el.name) || el.name.startsWith('non_rabies_') || el.type === 'submit' || el.type === 'button') return;
            if (el.type === 'radio') {
                if (el.checked) values[el.name] = el.value;
                return;
            }
            values[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        });
        return values;
    }

    function apply(values) {
        Array.from(form.elements).forEach(function (el) {
            if (!el.name || skipped.has(el.name) || !(el.name in values) || el.name.startsWith('outside_dose')) return;
            if (el.type === 'radio') { el.checked = el.value === values[el.name]; return; }
            if (el.type === 'checkbox') el.checked = Boolean(values[el.name]);
            else el.value = values[el.name];
        });
    }

    // A successful save clears the draft for this visit.
    let pending = null;
    try { pending = window.sessionStorage.getItem(pendingKey); window.sessionStorage.removeItem(pendingKey); } catch (_) {}
    if (pending && lastSaveSucceeded) storage('remove', draftKey);

    let restored = null;
    const rawDraft = storage('get', draftKey);
    if (rawDraft) {
        try {
            const draft = JSON.parse(rawDraft);
            if (draft && draft.values && Number(draft.savedAt || 0) > serverSavedAt) {
                apply(draft.values);
                restored = draft;
            } else {
                storage('remove', draftKey);
            }
        } catch (_) {
            storage('remove', draftKey);
        }
    }

    window.SBC_OUTSIDE_INITIAL = restored ? restored.values : null;
    // A restored draft is not saved yet: Record Administered Products waits for a save.
    window.SBC_ASSESSMENT_DRAFT_RESTORED = Boolean(restored);
    sbcInitClinicalForm(form);

    if (restored) {
        const banner = document.createElement('div');
        banner.className = 'alert alert-info d-flex flex-wrap align-items-center gap-2 col-12';
        banner.setAttribute('role', 'status');
        const time = new Date(Number(restored.savedAt)).toLocaleString();
        banner.innerHTML = '<i class="bi bi-cloud-check-fill"></i><span>Your unsaved draft from ' + time
            + ' was restored. Review it, then save the assessment.</span>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="discardAssessmentDraft">Discard draft</button>';
        form.prepend(banner);
        document.getElementById('discardAssessmentDraft').addEventListener('click', function () {
            storage('remove', draftKey);
            window.location.reload();
        });
    }

    let lastSerialized = '';
    function saveDraft() {
        const values = collect();
        const serialized = JSON.stringify(values);
        if (serialized === lastSerialized) return;
        lastSerialized = serialized;
        storage('set', draftKey, JSON.stringify({ savedAt: Date.now(), values: values }));
    }
    lastSerialized = JSON.stringify(collect());
    let timer = null;
    function scheduleSave() {
        clearTimeout(timer);
        timer = setTimeout(saveDraft, 400);
    }
    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', scheduleSave);
    // Bite markers are written by script without input events.
    setInterval(saveDraft, 5000);
    window.addEventListener('pagehide', saveDraft);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') saveDraft();
    });
    form.addEventListener('submit', function () {
        saveDraft();
        try { window.sessionStorage.setItem(pendingKey, '1'); } catch (_) {}
    });
});

// Late Dose Decision: an anti-rabies save needs a choice; show the box instead of submitting.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('assessmentForm');
    const panel = document.getElementById('lateDecisionPanel');
    if (!form || !panel) return;
    panel.hidden = false;
    panel.style.removeProperty('display');
    form.addEventListener('submit', function (event) {
        const submitter = event.submitter;
        if (submitter && submitter.value && submitter.value !== 'save_assessment') return;
        const nonArv = document.getElementById('not_anti_rabies');
        if (nonArv && nonArv.checked) return;
        if (form.querySelector('input[name="late_decision"]:checked')) return;
        if (panel.dataset.allContinue === '1') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        panel.querySelector('.alert').classList.add('border', 'border-danger', 'border-2');
    }, true);
});

/*
 * Record Administered Products. Anti-rabies: opens Vaccination prefilled from
 * the SAVED assessment, so it is disabled while the form has unsaved changes
 * (or a restored draft) and the hint says to save first. Not Anti-Rabies
 * Vaccine checked: opens the one-time product form below for this patient and
 * case (no anti-rabies dose); that form saves the assessment with the products.
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('assessmentForm');
    const button = document.getElementById('recordProductsButton');
    const hint = document.getElementById('recordProductsHint');
    const nonArv = document.getElementById('not_anti_rabies');
    const panel = document.getElementById('nonArvAdministrationPanel');
    if (!form || !button || !hint) return;
    const url = button.dataset.vaccinateUrl || '';
    const block = button.dataset.blockReason || '';
    const ignored = new Set(['csrf_token', 'visit_id', 'action']);

    function serialize() {
        return JSON.stringify(Array.from(form.elements)
            .filter(el => el.name && !ignored.has(el.name) && !el.name.startsWith('non_rabies_')
                && el.type !== 'submit' && el.type !== 'button')
            .map(el => [el.name, el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value]));
    }

    function setState(enabled, href, text) {
        button.classList.toggle('disabled', !enabled);
        button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        button.setAttribute('href', enabled ? href : '#recordProductsHint');
        hint.textContent = text;
    }

    let snapshot = null;
    function sync() {
        if (nonArv && nonArv.checked) {
            setState(true, '#nonArvAdministrationPanel',
                'Not Anti-Rabies Vaccine: opens the one-time product form below for this patient and case (no anti-rabies dose).');
        } else if (block) {
            setState(false, '', block);
        } else if (window.SBC_ASSESSMENT_DRAFT_RESTORED || snapshot === null || serialize() !== snapshot) {
            setState(false, '', 'You have unsaved changes. Save the assessment first, then record the products.');
        } else {
            setState(true, url, 'Opens Vaccination with this patient, case and ' + (button.dataset.dose || 'the dose due') + ' filled in.');
        }
    }

    button.addEventListener('click', function (event) {
        sync();
        if (button.classList.contains('disabled')) {
            event.preventDefault();
            return;
        }
        if (nonArv && nonArv.checked && panel) {
            event.preventDefault();
            panel.style.display = '';
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const firstProduct = panel.querySelector('.non-arv-product-select');
            if (firstProduct) firstProduct.focus({ preventScroll: true });
        }
    });
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    // Bite markers change the form without input events.
    form.addEventListener('click', function () { setTimeout(sync, 0); });

    // After every page script has set the form up (draft, regimen, Not Anti-Rabies).
    setTimeout(function () {
        snapshot = serialize();
        sync();
    }, 0);
});

// Late Dose Decision: "Restart from Day 0" uses today's re-assessment as the new D0.
document.addEventListener('DOMContentLoaded', function () {
    const restart = document.getElementById('lateDecisionRestart');
    const d0 = document.getElementById('d0_date');
    if (!restart || !d0) return;
    const originalD0 = d0.value;
    const today = sbcManilaToday(); // clinic date (Asia/Manila), same as the server
    function sync() {
        if (restart.checked && (!d0.value || d0.value < today)) d0.value = today;
        if (!restart.checked && d0.value === today && originalD0) d0.value = originalD0;
    }
    document.querySelectorAll('input[name="late_decision"]').forEach(function (radio) {
        radio.addEventListener('change', sync);
    });
    sync();
});

function sbcInitClinicalForm(form) {
    const stagesByRegimen = window.SBC_REGIMEN_STAGES || {};
    const regimen = document.getElementById('treatment_profile');
    const d0 = document.getElementById('d0_date');
    const d0Label = document.getElementById('d0_date_label');
    const routeHint = document.getElementById('regimenRouteHint');
    const nonArv = document.getElementById('not_anti_rabies');
    const boosterPanel = document.getElementById('boosterEligibilityPanel');
    const previousDate = document.getElementById('previous_vaccination_date');
    const previousUnknown = document.getElementById('previous_vaccination_unknown');
    const boosterWarning = document.getElementById('boosterWarning');
    const outsidePanel = document.getElementById('outsideFollowUpPanel');
    const outsideToggle = document.getElementById('outside_follow_up');
    const outsideFields = document.getElementById('outsideFollowUpFields');
    const outsideRows = document.getElementById('outsideDoseRows');
    const biteType = document.getElementById('bite_type');
    let outsideValues = window.SBC_OUTSIDE_INITIAL || {};

    // "Others (specify)" text boxes for dropdowns.
    form.querySelectorAll('select[data-other-target]').forEach(function (select) {
        const target = document.getElementById(select.dataset.otherTarget);
        if (!target) return;
        const sync = function () {
            const isOther = select.value === 'Others' || select.value === 'Other';
            target.style.display = isOther ? '' : 'none';
            target.required = isOther;
        };
        select.addEventListener('change', sync);
        sync();
    });

    // Non-bite and non-rabies services do not need animal details.
    if (biteType) {
        biteType.addEventListener('change', function () {
            if (biteType.value !== 'Non-Bite') return;
            [['animal_type', 'Not Applicable'], ['animal_status', 'Not Applicable'], ['bite_category', 'Not Applicable']]
                .forEach(function (pair) {
                    const field = document.getElementById(pair[0]);
                    if (field && field.value === '') {
                        field.value = pair[1];
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
        });
    }

    function routeOf(value) {
        if (/_ID$/.test(value)) return 'Intradermal (ID)';
        if (/_IM$/.test(value)) return 'Intramuscular (IM)';
        return '';
    }

    function routeText(value) {
        const route = routeOf(value);
        if (!route) return 'Route: set by the regimen.';
        if (route.indexOf('IM') !== -1) return 'Route: ' + route + '. IM uses a full vial each session.';
        return 'Route: ' + route + '. ' + (/^BOOSTER/.test(value) ? 'Booster ID uses 1 site per session.' : 'Regular ID uses 2 sites per session (both arms).');
    }

    function rememberOutside() {
        if (!outsideRows) return;
        outsideRows.querySelectorAll('input').forEach(function (input) {
            outsideValues[input.name] = input.type === 'checkbox' ? input.checked : input.value;
        });
    }

    function buildOutsideRows() {
        if (!outsideRows || !regimen) return;
        rememberOutside();
        const stages = stagesByRegimen[regimen.value] || [];
        outsideRows.replaceChildren();
        if (!stages.length) {
            outsideRows.innerHTML = '<span class="text-muted small">Select a Vaccination Regimen first.</span>';
            return;
        }
        stages.forEach(function (stage) {
            const row = document.createElement('div');
            row.className = 'd-flex flex-wrap align-items-center gap-2';
            const checkName = 'outside_dose[' + stage.dose + ']';
            const dateName = 'outside_dose_date[' + stage.dose + ']';
            const checkId = 'outside_dose_' + stage.dose;
            const isD0 = stage.dose === 1;
            row.innerHTML = '<div class="form-check mb-0" style="min-width:210px">'
                + '<input class="form-check-input" type="checkbox" value="1" id="' + checkId + '" name="' + checkName + '">'
                + '<label class="form-check-label" for="' + checkId + '">' + stage.label + ' given at other clinic</label></div>'
                + '<input type="date" class="form-control form-control-sm" style="max-width:180px" name="' + dateName + '"'
                + (isD0 ? ' disabled title="Uses the original Day 0 above"' : '') + ' max="' + sbcManilaToday() + '">';
            const check = row.querySelector('input[type="checkbox"]');
            const date = row.querySelector('input[type="date"]');
            check.checked = Boolean(outsideValues[checkName]);
            date.value = isD0 ? (d0 ? d0.value : '') : (outsideValues[dateName] || '');
            outsideRows.appendChild(row);
        });
    }

    function syncBoosterWarning() {
        if (!boosterWarning || !previousDate || !previousUnknown) return;
        let message = '';
        if (previousUnknown.checked || previousDate.value === '') {
            message = 'Previous anti-rabies vaccination date unknown. A full PEP is needed instead of a booster.';
        } else if (d0 && d0.value) {
            const base = new Date(d0.value + 'T00:00:00');
            const limit = new Date(base);
            limit.setFullYear(base.getFullYear() - 5);
            if (new Date(previousDate.value + 'T00:00:00') < limit) {
                message = 'The previous anti-rabies vaccination was more than 5 years ago. A full PEP is needed instead of a booster.';
            }
        }
        previousDate.disabled = previousUnknown.checked;
        boosterWarning.textContent = message;
        boosterWarning.style.display = message ? '' : 'none';
    }

    function syncRegimen() {
        const antiRabies = !(nonArv && nonArv.checked);
        const value = regimen ? regimen.value : '';
        if (routeHint) routeHint.textContent = routeText(value);
        if (boosterPanel) boosterPanel.style.display = antiRabies && /^BOOSTER/.test(value) ? '' : 'none';
        if (outsidePanel) outsidePanel.style.display = antiRabies ? '' : 'none';
        const outsideOn = antiRabies && outsideToggle && outsideToggle.checked;
        if (outsideFields) outsideFields.style.display = outsideOn ? '' : 'none';
        if (d0Label) d0Label.textContent = outsideOn ? 'Original Day 0 (first dose at the other clinic)' : 'Actual D0 Date';
        buildOutsideRows();
        syncBoosterWarning();
    }

    [regimen, nonArv, outsideToggle].forEach(function (el) {
        // Deferred: the Not Anti-Rabies handler updates the regimen after this listener.
        if (el) el.addEventListener('change', function () { setTimeout(syncRegimen, 0); });
    });
    if (d0) d0.addEventListener('change', function () {
        const d0Row = outsideRows ? outsideRows.querySelector('input[name="outside_dose_date[1]"]') : null;
        if (d0Row) d0Row.value = d0.value;
        syncBoosterWarning();
    });
    [previousDate, previousUnknown].forEach(function (el) {
        if (el) el.addEventListener('change', syncBoosterWarning);
    });
    syncRegimen();
}
</script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const nonArvCheckbox =
        document.getElementById('not_anti_rabies');

    const treatmentProfile =
        document.getElementById('treatment_profile');

    const d0Date =
        document.getElementById('d0_date');

    const nonArvPanel =
        document.getElementById('nonArvAdministrationPanel');

    const saveButtonText =
        document.getElementById('saveAssessmentButtonText');

    const nonArvEntries =
        document.getElementById('nonArvEntries');

    const addNonArvProductButton =
        document.getElementById('addNonArvProductButton');

    let lastAntiRabiesProfile =
        treatmentProfile
            ? treatmentProfile.value
            : '';

    let lastD0Date =
        d0Date
            ? d0Date.value
            : '';

    function syncNonArvMode() {
        if (
            !nonArvCheckbox
            || !treatmentProfile
            || !d0Date
        ) {
            return;
        }

        const checked =
            nonArvCheckbox.checked;

        if (checked) {
            if (treatmentProfile.value !== '') {
                lastAntiRabiesProfile =
                    treatmentProfile.value;
            }

            if (d0Date.value !== '') {
                lastD0Date =
                    d0Date.value;
            }

            treatmentProfile.value = '';
            treatmentProfile.disabled = true;
            treatmentProfile.required = false;

            d0Date.value = '';
            d0Date.disabled = true;
            d0Date.required = false;

            if (nonArvPanel) {
                nonArvPanel.style.display = '';
            }

            if (saveButtonText) {
                saveButtonText.textContent =
                    'Save Assessment - No ARV Schedule';
            }
        } else {
            treatmentProfile.disabled = false;
            treatmentProfile.required = true;

            if (treatmentProfile.value === '') {
                treatmentProfile.value =
                    lastAntiRabiesProfile || 'PEP_ID';
            }

            d0Date.disabled = false;
            d0Date.required = true;

            if (d0Date.value === '') {
                d0Date.value =
                    lastD0Date
                    || sbcManilaToday();
            }

            if (nonArvPanel) {
                nonArvPanel.style.display = 'none';
            }

            if (saveButtonText) {
                saveButtonText.textContent =
                    'Save Assessment and Generate Schedule';
            }
        }
    }

    function syncNonArvEntry(entry) {
        if (!entry) return;

        const productSelect = entry.querySelector('.non-arv-product-select');
        const quantityInput = entry.querySelector('.non-arv-quantity-input');
        const quantityUnit = entry.querySelector('.non-arv-quantity-unit');
        const stockNote = entry.querySelector('.non-arv-stock-note');

        if (!productSelect || !quantityInput || !quantityUnit || !stockNote) return;

        const option = productSelect.options[productSelect.selectedIndex];

        if (!option || option.value === '') {
            quantityUnit.textContent = 'unit';
            quantityInput.step = '0.0001';
            quantityInput.removeAttribute('max');
            stockNote.textContent =
                'Select a product to see the inventory unit and available stock.';
            return;
        }

        const baseUnit = option.dataset.baseUnit || 'unit';
        const displayUnit = option.dataset.displayUnit || baseUnit;
        const conversion = Number(option.dataset.conversion || 1);
        const stock = Number(option.dataset.stock || 0);
        const step = option.dataset.step || '0.0001';

        quantityUnit.textContent = baseUnit;
        quantityInput.step = step;
        quantityInput.max = String(stock);

        let note = `Usable stock: ${stock} ${baseUnit}.`;
        if (conversion > 1 && displayUnit.toLowerCase() !== baseUnit.toLowerCase()) {
            note += ` 1 ${displayUnit} = ${conversion} ${baseUnit}.`;
        }
        stockNote.textContent = note;
    }

    function refreshNonArvEntries() {
        if (!nonArvEntries) return;
        const entries = nonArvEntries.querySelectorAll('.non-arv-entry');
        entries.forEach(function(entry, index) {
            const title = entry.querySelector('.non-arv-entry-title');
            const remove = entry.querySelector('.non-arv-remove');
            if (title) title.textContent = `Product ${index + 1}`;
            if (remove) remove.style.display = entries.length > 1 ? '' : 'none';
        });
    }

    function addNonArvEntry() {
        if (!nonArvEntries) return;
        const source = nonArvEntries.querySelector('.non-arv-entry');
        if (!source) return;

        const clone = source.cloneNode(true);
        clone.querySelectorAll('input, select, textarea').forEach(function(control) {
            if (control.tagName === 'SELECT') control.selectedIndex = 0;
            else control.value = '';
        });
        nonArvEntries.appendChild(clone);
        syncNonArvEntry(clone);
        refreshNonArvEntries();
    }

    if (nonArvEntries) {
        nonArvEntries.addEventListener('change', function(event) {
            if (event.target.matches('.non-arv-product-select')) {
                syncNonArvEntry(event.target.closest('.non-arv-entry'));
            }
        });

        nonArvEntries.addEventListener('click', function(event) {
            const remove = event.target.closest('.non-arv-remove');
            if (!remove) return;
            const entries = nonArvEntries.querySelectorAll('.non-arv-entry');
            if (entries.length <= 1) return;
            remove.closest('.non-arv-entry')?.remove();
            refreshNonArvEntries();
        });

        nonArvEntries.querySelectorAll('.non-arv-entry').forEach(syncNonArvEntry);
        refreshNonArvEntries();
    }

    if (addNonArvProductButton) {
        addNonArvProductButton.addEventListener('click', addNonArvEntry);
    }

    if (nonArvCheckbox) {
        nonArvCheckbox.addEventListener(
            'change',
            syncNonArvMode
        );

        syncNonArvMode();
    }

    const assessmentForm =
        nonArvCheckbox
            ? nonArvCheckbox.closest('form')
            : null;

    if (assessmentForm) {
        assessmentForm.addEventListener(
            'submit',
            function (event) {
                const submitter =
                    event.submitter;

                if (
                    submitter
                    && submitter.value === 'administer_non_rabies'
                ) {
                    if (!nonArvCheckbox.checked) {
                        event.preventDefault();

                        window.alert(
                            'Check "Not Anti-Rabies Vaccine" before using Administer.'
                        );

                        return;
                    }

                    const entries = nonArvEntries
                        ? Array.from(nonArvEntries.querySelectorAll('.non-arv-entry'))
                        : [];

                    const selectedProducts = new Set();
                    let validEntries = 0;

                    for (const entry of entries) {
                        const select = entry.querySelector('.non-arv-product-select');
                        const quantity = entry.querySelector('.non-arv-quantity-input');
                        const itemId = select ? select.value : '';
                        const quantityValue = quantity ? Number(quantity.value) : 0;
                        const hasAnyInput = itemId !== '' || (quantity && quantity.value !== '');

                        if (!hasAnyInput) continue;

                        if (itemId === '') {
                            event.preventDefault();
                            window.alert('Select a product for every administration row.');
                            select?.focus();
                            return;
                        }

                        if (quantityValue <= 0) {
                            event.preventDefault();
                            window.alert('Enter the actual quantity used for every selected product.');
                            quantity?.focus();
                            return;
                        }

                        if (selectedProducts.has(itemId)) {
                            event.preventDefault();
                            window.alert('The same product is listed more than once. Use one row and enter the total actual quantity.');
                            select?.focus();
                            return;
                        }

                        selectedProducts.add(itemId);
                        validEntries++;
                    }

                    if (validEntries === 0) {
                        event.preventDefault();
                        window.alert('Add at least one product to administer.');
                        return;
                    }

                    /*
                     * IMPORTANT:
                     * Once a submit button is disabled it is no longer a
                     * "successful control", so its name/value pair is omitted
                     * from the POST payload. The Administer button itself owns
                     * name="action" value="administer_non_rabies".
                     *
                     * Preserve that action in a hidden input BEFORE disabling
                     * the button for the loading state.
                     */
                    let administerAction =
                        assessmentForm.querySelector(
                            'input[data-administer-action="1"]'
                        );

                    if (!administerAction) {
                        administerAction =
                            document.createElement('input');

                        administerAction.type = 'hidden';
                        administerAction.name = 'action';
                        administerAction.dataset.administerAction = '1';

                        assessmentForm.appendChild(administerAction);
                    }

                    administerAction.value =
                        'administer_non_rabies';

                    submitter.disabled = true;
                    submitter.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2" '
                        + 'aria-hidden="true"></span>Saving...';
                }
            }
        );
    }

    const form =
        document.getElementById('registryConfirmationForm');

    const button =
        document.getElementById('confirmRegistryButton');

    if (form && button) {

        form.addEventListener('submit', function () {

            button.disabled = true;

            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" '
                + 'aria-hidden="true"></span>Sending...';

        });
    }
});
</script>


<script>
// Bite location diagram: Front and Back views. Each dot is saved as
// {x, y, view, label} in bite_markers (x/y = % of the figure image). The label
// names the body part with its view ("Back – Left calf") and Site of Bite is
// filled from the labels (still editable). Old dots without a view are Front.
const BITE_VIEW_TITLE = { front: 'Front', back: 'Back' };

/** Body part at x/y (% of the figure). Front: the patient's right is on the
 *  viewer's left; Back (figure seen from behind): the patient's left is on the left. */
function biteRegionName(view, x, y) {
    const back = view === 'back';
    const side = x < 50 ? (back ? 'Left' : 'Right') : (back ? 'Right' : 'Left');
    const sided = name => side + ' ' + name;
    const central = Math.abs(x - 50) < 2.5;
    if (y < 19) return back ? 'Back of the head' : (y < 11 ? 'Head' : 'Face');
    if (y < 22.5) return back ? 'Nape' : 'Neck';
    if ((x < 38 || x > 62) && y < 58) {                     // arms
        if (y < 27) return sided('shoulder');
        if (y < 38) return sided(back ? 'back of the upper arm' : 'upper arm');
        if (y < 44) return sided(back ? 'elbow' : 'inner elbow');
        if (y < 49.5) return sided(back ? 'back of the forearm' : 'forearm');
        return sided(back ? 'back of the hand' : 'hand / palm');
    }
    if (y < 47) {                                           // trunk
        if (y < 27 && Math.abs(x - 50) > 8) return sided('shoulder');
        if (back) return (central ? '' : side + ' ') + (y < 37 ? 'upper back' : 'lower back');
        return central ? (y < 37 ? 'Chest (center)' : 'Abdomen (center)') : sided(y < 37 ? 'chest' : 'abdomen');
    }
    if (y < 52) return back ? (central ? 'Buttocks (center)' : sided('buttock')) : (central ? 'Groin' : sided('hip'));
    if (y < 70) return sided(back ? 'back of the thigh' : 'thigh');
    if (y < 76) return sided(back ? 'back of the knee' : 'knee');
    if (y < 88) return sided(back ? 'calf' : 'shin');
    return sided(back ? 'heel' : 'foot / ankle');
}

function biteMarkerLabel(marker, index) {
    const label = String(marker.label || '').trim();
    return label !== '' ? label : (BITE_VIEW_TITLE[marker.view || 'front'] + ' – bite location ' + (index + 1));
}

document.addEventListener('DOMContentLoaded', function () {
    const field = document.getElementById('bite_markers');
    if (!field) return;
    const stage = document.getElementById('biteMapStage');
    const images = { front: document.getElementById('biteMapImage'), back: document.getElementById('biteMapImageBack') };
    const viewLabel = document.getElementById('biteMapViewLabel');
    const viewButtons = Array.from(document.querySelectorAll('.bite-map-views [data-view]'));
    const list = document.getElementById('biteMapList');
    const status = document.getElementById('biteMapStatus');
    const clear = document.getElementById('biteMapClear');
    const siteField = document.getElementById('exposure_site');
    let markers;
    try {
        markers = JSON.parse(field.value || '[]');
        if (!Array.isArray(markers) || markers.length > 30 || markers.some(m =>
            !m || typeof m.x !== 'number' || typeof m.y !== 'number' ||
            !Number.isFinite(m.x) || !Number.isFinite(m.y) ||
            m.x < 0 || m.x > 100 || m.y < 0 || m.y > 100 ||
            (m.label !== undefined && typeof m.label !== 'string') ||
            (m.view !== undefined && m.view !== 'front' && m.view !== 'back')
        )) throw new Error('Invalid saved markers');
    } catch (_) {
        status.textContent = 'Saved markers could not be read. Reload before saving this assessment.';
        field.form.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = true);
        return;
    }
    markers.forEach(m => { if (m.view !== 'back') m.view = 'front'; });
    const pixels = { front: null, back: null };
    let view = markers.length && markers.every(m => m.view === 'back') ? 'back' : 'front';

    // Site of Bite follows the dots until the nurse types their own text.
    const autoSite = () => Array.from(new Set(markers.map((m, i) => biteMarkerLabel(m, i)))).join('; ').slice(0, 255);
    let lastAutoSite = autoSite();
    const siteFollowsDots = () => siteField && (siteField.value.trim() === '' || siteField.value === lastAutoSite);
    function syncSite() {
        if (!siteField) return;
        const next = autoSite();
        if (siteFollowsDots() && markers.length > 0) siteField.value = next;
        lastAutoSite = next;
    }

    function render(message) {
        field.value = JSON.stringify(markers);
        stage.querySelectorAll('.bite-map-marker').forEach(el => el.remove());
        list.replaceChildren();
        markers.forEach((marker, index) => {
            const name = biteMarkerLabel(marker, index);
            const remove = () => {
                const keep = siteFollowsDots();
                markers.splice(index, 1);
                if (keep && siteField) { siteField.value = autoSite(); }
                lastAutoSite = autoSite();
                render('Marker removed.');
            };
            if (marker.view === view) {
                const pin = document.createElement('button');
                pin.type = 'button';
                pin.className = 'bite-map-marker';
                pin.style.left = marker.x + '%';
                pin.style.top = marker.y + '%';
                pin.textContent = String(index + 1);
                pin.setAttribute('aria-label', 'Remove bite marker ' + (index + 1) + ': ' + name);
                pin.title = pin.getAttribute('aria-label');
                pin.addEventListener('click', remove);
                stage.appendChild(pin);
            }
            const item = document.createElement('li');
            item.className = 'view-' + marker.view;
            const text = document.createElement('span');
            text.textContent = name + ' ';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-danger btn-sm ms-2';
            button.textContent = 'Remove';
            button.setAttribute('aria-label', 'Remove bite marker ' + (index + 1) + ': ' + name);
            button.addEventListener('click', remove);
            item.append(text, button);
            list.appendChild(item);
        });
        for (const v of ['front', 'back']) {
            const badge = document.querySelector('[data-view-count="' + v + '"]');
            if (badge) badge.textContent = String(markers.filter(m => m.view === v).length);
        }
        clear.disabled = markers.length === 0;
        status.textContent = (message ? message + ' ' : '') + markers.length + ' bite marker(s) ('
            + markers.filter(m => m.view === 'front').length + ' front, ' + markers.filter(m => m.view === 'back').length
            + ' back). Save the assessment to keep changes.';
    }

    function showView(next) {
        view = next;
        stage.dataset.view = view;
        images.front.hidden = view !== 'front';
        images.back.hidden = view !== 'back';
        viewLabel.textContent = view === 'back' ? 'BACK' : 'FRONT';
        viewButtons.forEach(btn => {
            const on = btn.dataset.view === view;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        render();
    }
    viewButtons.forEach(btn => btn.addEventListener('click', () => showView(btn.dataset.view)));

    function loadImage(v) {
        const image = images[v];
        if (!image || !image.naturalWidth) return;
        try {
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth;
            canvas.height = image.naturalHeight;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.drawImage(image, 0, 0);
            pixels[v] = context.getImageData(0, 0, canvas.width, canvas.height);
            render();
        } catch (_) {
            status.textContent = 'The body image could not be read. Use images/human-figure.png and images/human-figure-back.png from this project.';
        }
    }
    for (const v of ['front', 'back']) {
        const image = images[v];
        image.addEventListener('load', () => loadImage(v));
        image.addEventListener('error', () => {
            pixels[v] = null;
            status.textContent = 'Body image missing (' + (v === 'back' ? 'images/human-figure-back.png' : 'images/human-figure.png') + '). Existing markers are preserved.';
        });
        image.addEventListener('click', event => {
            const data = pixels[v];
            if (!data) return;
            if (markers.length >= 30) {
                status.textContent = 'Maximum 30 markers. Remove a marker before adding another.';
                return;
            }
            const rect = image.getBoundingClientRect();
            let x = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
            let y = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height));
            let px = Math.min(data.width - 1, Math.floor(x * data.width));
            let py = Math.min(data.height - 1, Math.floor(y * data.height));
            const onBody = (qx, qy) => qx >= 0 && qy >= 0 && qx < data.width && qy < data.height
                && data.data[(qy * data.width + qx) * 4 + 3] >= 128;
            if (!onBody(px, py)) {
                // A finger tap just outside the outline (tablets) snaps to the nearest point on the body.
                const radius = Math.round(data.width * 0.025);
                let best = null;
                for (let dy = -radius; dy <= radius; dy += 2) {
                    for (let dx = -radius; dx <= radius; dx += 2) {
                        const d = dx * dx + dy * dy;
                        if (d <= radius * radius && (!best || d < best.d) && onBody(px + dx, py + dy)) best = { d, dx, dy };
                    }
                }
                if (!best) {
                    status.textContent = 'Click on the body to place the marker.';
                    return;
                }
                px += best.dx;
                py += best.dy;
                x = (px + 0.5) / data.width;
                y = (py + 0.5) / data.height;
            }
            const xp = Number((x * 100).toFixed(4));
            const yp = Number((y * 100).toFixed(4));
            const region = biteRegionName(v, xp, yp);
            markers.push({ x: xp, y: yp, view: v, label: BITE_VIEW_TITLE[v] + ' – ' + region.charAt(0).toUpperCase() + region.slice(1) });
            syncSite();
            render('Marker added: ' + markers[markers.length - 1].label + '.');
        });
        if (image.complete && image.naturalWidth) loadImage(v);
    }
    clear.addEventListener('click', () => {
        const keep = siteFollowsDots();
        markers = [];
        if (keep && siteField) siteField.value = '';
        lastAutoSite = '';
        render('All markers cleared.');
    });
    showView(view);
});
</script>

</body>
</html>
