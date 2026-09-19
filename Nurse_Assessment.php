<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';
require_once 'sources/inventory_unit_helpers.php';

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
 * CLINIC-CONFIGURED MISSED-SCHEDULE RULE
 * ============================================================
 *
 * This is a SmartBiteCare workflow/business rule and should match
 * the clinic supervisor's approved protocol.
 *
 * 1-30 days overdue:
 *      The scheduled vaccination is marked Missed and the patient
 *      remains eligible for nurse-managed catch-up/rescheduling.
 *
 * More than 30 days overdue:
 *      The old schedule must NOT be automatically continued.
 *      Nurse reassessment/new treatment cycle is required.
 *
 * IMPORTANT:
 *      Previous vaccination records are preserved for history,
 *      auditability, and traceability. They are never deleted just
 *      because a new treatment cycle may be required.
 */
$catchUpWindowDays = 30;

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

/**
 * Persist an assessment explicitly marked as NOT an anti-rabies schedule.
 *
 * clinical_assessments already supports treatment_profile='OTHER', so no
 * database migration is required. d0_date is NOT NULL in the existing
 * schema; for an OTHER assessment we retain the existing stored value or
 * use today's date only as a technical placeholder. No vaccination schedule
 * is generated from it.
 */
function assessmentSaveNonArvAssessment(
    mysqli $conn,
    array $visit,
    int $visitId,
    int $userId,
    string $branchId,
    array $payload
): void {
    $history = trim((string)($payload['exposure_history'] ?? ''));
    $exposureDate = trim((string)($payload['date_of_exposure'] ?? ''));
    $site = trim((string)($payload['exposure_site'] ?? ''));
    $animal = trim((string)($payload['animal_type'] ?? ''));
    $animalStatus = trim((string)($payload['animal_status'] ?? ''));
    $category = trim((string)($payload['bite_category'] ?? ''));
    $route = trim((string)($payload['route'] ?? ''));
    $regimen = trim((string)($payload['active_regimen'] ?? ''));
    $concerns = trim((string)($payload['important_concerns'] ?? ''));
    $instructions = trim((string)($payload['instructions_given'] ?? ''));
    $notes = trim((string)($payload['chart_notes'] ?? ''));

    if ($history === '') {
        throw new RuntimeException(
            'History of incident/exposure is required.'
        );
    }

    if (
        $exposureDate !== ''
        && DateTime::createFromFormat(
            'Y-m-d',
            $exposureDate
        )?->format('Y-m-d') !== $exposureDate
    ) {
        throw new RuntimeException(
            'Enter a valid exposure date.'
        );
    }

    $patientId = (int)$visit['patient_id'];
    $caseId = (int)$visit['case_id'];

    $d0Date = date('Y-m-d');

    $existing = $conn->prepare(
        "SELECT d0_date
         FROM clinical_assessments
         WHERE visit_id = ?
         LIMIT 1"
    );

    if ($existing) {
        $existing->bind_param(
            'i',
            $visitId
        );

        $existing->execute();

        $existingRow =
            $existing
                ->get_result()
                ->fetch_assoc();

        $existing->close();

        if (
            $existingRow
            && !empty($existingRow['d0_date'])
        ) {
            $d0Date =
                (string)$existingRow['d0_date'];
        }
    }

    /*
     * If this visit previously generated anti-rabies schedule placeholders,
     * retire only the still-Scheduled rows for THIS visit. Completed/Missed
     * records are historical clinical records and are never deleted here.
     */
    $archiveSchedule = $conn->prepare(
        "UPDATE vaccination_records
         SET
            is_archived = 1,
            archived_at = NOW(),
            archived_by = ?
         WHERE visit_id = ?
           AND branch_id = ?
           AND vaccination_status = 'Scheduled'
           AND is_archived = 0"
    );

    if ($archiveSchedule) {
        $archiveSchedule->bind_param(
            'iis',
            $userId,
            $visitId,
            $branchId
        );

        $archiveSchedule->execute();
        $archiveSchedule->close();
    }

    $profile = 'OTHER';

    $assessment = $conn->prepare(
        "INSERT INTO clinical_assessments
         (
            visit_id,
            patient_id,
            case_id,
            branch_id,
            nurse_id,
            exposure_history,
            date_of_exposure,
            exposure_site,
            animal_type,
            animal_status,
            bite_category,
            treatment_profile,
            route,
            active_regimen,
            important_concerns,
            instructions_given,
            chart_notes,
            d0_date
         )
         VALUES (
            ?, ?, ?, ?, ?, ?, NULLIF(?, ''),
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
         )
         ON DUPLICATE KEY UPDATE
            nurse_id = VALUES(nurse_id),
            exposure_history = VALUES(exposure_history),
            date_of_exposure = VALUES(date_of_exposure),
            exposure_site = VALUES(exposure_site),
            animal_type = VALUES(animal_type),
            animal_status = VALUES(animal_status),
            bite_category = VALUES(bite_category),
            treatment_profile = VALUES(treatment_profile),
            route = VALUES(route),
            active_regimen = VALUES(active_regimen),
            important_concerns = VALUES(important_concerns),
            instructions_given = VALUES(instructions_given),
            chart_notes = VALUES(chart_notes),
            d0_date = VALUES(d0_date),
            updated_at = NOW()"
    );

    if (!$assessment) {
        throw new RuntimeException(
            'Unable to prepare the non-ARV assessment.'
        );
    }

    $assessment->bind_param(
        'iiisisssssssssssss',
        $visitId,
        $patientId,
        $caseId,
        $branchId,
        $userId,
        $history,
        $exposureDate,
        $site,
        $animal,
        $animalStatus,
        $category,
        $profile,
        $route,
        $regimen,
        $concerns,
        $instructions,
        $notes,
        $d0Date
    );

    if (!$assessment->execute()) {
        $error = $assessment->error;
        $assessment->close();

        throw new RuntimeException(
            'Unable to save the non-ARV assessment: '
            . $error
        );
    }

    $assessment->close();

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
        throw new RuntimeException(
            'Unable to prepare the case update.'
        );
    }

    $caseUpdate->bind_param(
        'ssssssis',
        $animal,
        $site,
        $category,
        $animalStatus,
        $exposureDate,
        $notes,
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
            assessment_started_at =
                COALESCE(assessment_started_at, NOW()),
            updated_at = NOW()
         WHERE visit_id = ?
           AND branch_id = ?"
    );

    if (!$visitUpdate) {
        throw new RuntimeException(
            'Unable to prepare the visit update.'
        );
    }

    $visitUpdate->bind_param(
        'iis',
        $userId,
        $visitId,
        $branchId
    );

    $visitUpdate->execute();
    $visitUpdate->close();
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

            $history = trim((string)($_POST['exposure_history'] ?? ''));
            $exposureDate = (string)($_POST['date_of_exposure'] ?? '');
            $site = trim((string)($_POST['exposure_site'] ?? ''));
            $animal = trim((string)($_POST['animal_type'] ?? ''));
            $animalStatus = trim((string)($_POST['animal_status'] ?? ''));
            $category = trim((string)($_POST['bite_category'] ?? ''));
            $isNonRabies = isset($_POST['not_anti_rabies'])
                && (string)$_POST['not_anti_rabies'] === '1';

            $profile = (string)($_POST['treatment_profile'] ?? '');
            $route = trim((string)($_POST['route'] ?? ''));
            $regimen = trim((string)($_POST['active_regimen'] ?? ''));
            $concerns = trim((string)($_POST['important_concerns'] ?? ''));
            $instructions = trim((string)($_POST['instructions_given'] ?? ''));
            $notes = trim((string)($_POST['chart_notes'] ?? ''));
            $d0Date = (string)($_POST['d0_date'] ?? '');

            $profiles = ['PEP_ID', 'PEP_IM', 'PREP', 'BOOSTER'];

            if ($history === '') {
                throw new RuntimeException(
                    'History of incident/exposure is required.'
                );
            }

            if ($isNonRabies) {
                /*
                 * OTHER is already supported by clinical_assessments.
                 * It is used here only as a workflow marker so this assessment
                 * does NOT generate the anti-rabies D0/D3/... schedule.
                 */
                $profile = 'OTHER';

                // d0_date is NOT NULL in the current schema. Preserve the
                // existing technical value when present; otherwise use today.
                $existingD0 = $conn->prepare(
                    "SELECT d0_date
                     FROM clinical_assessments
                     WHERE visit_id = ?
                     LIMIT 1"
                );

                if ($existingD0) {
                    $existingD0->bind_param(
                        'i',
                        $visitId
                    );

                    $existingD0->execute();

                    $existingD0Row =
                        $existingD0
                            ->get_result()
                            ->fetch_assoc();

                    $existingD0->close();

                    $d0Date =
                        !empty($existingD0Row['d0_date'])
                            ? (string)$existingD0Row['d0_date']
                            : date('Y-m-d');
                } else {
                    $d0Date = date('Y-m-d');
                }
            } elseif (
                $d0Date === ''
                || !in_array($profile, $profiles, true)
            ) {
                throw new RuntimeException(
                    'Treatment profile and D0 date are required for an anti-rabies schedule.'
                );
            }

            $datesToValidate = [$exposureDate];

            if (!$isNonRabies) {
                $datesToValidate[] = $d0Date;
            }

            foreach ($datesToValidate as $date) {
                if (
                    $date !== ''
                    && DateTime::createFromFormat(
                        'Y-m-d',
                        $date
                    )?->format('Y-m-d') !== $date
                ) {
                    throw new RuntimeException(
                        'Enter valid dates.'
                    );
                }
            }

            $patientId = (int)$visit['patient_id'];
            $caseId = (int)$visit['case_id'];

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
             * If the case still has an active Missed schedule that is
             * more than the configured catch-up window overdue, retire
             * the entire PREVIOUS active vaccination cycle before a new
             * schedule is generated.
             *
             * We archive instead of deleting so the previous cycle stays
             * available for history/audit.
             */
            $restartRequired = false;
            $previousCycleRows = 0;

            if (!$isNonRabies) {

            $restartCheck = $conn->prepare(
                "SELECT
                    MIN(scheduled_date) AS oldest_missed_date,
                    MAX(DATEDIFF(CURDATE(), scheduled_date)) AS max_days_overdue
                 FROM vaccination_records
                 WHERE patient_id = ?
                   AND case_id = ?
                   AND branch_id = ?
                   AND is_archived = 0
                   AND vaccination_status = 'Missed'
                   AND scheduled_date IS NOT NULL
                   AND scheduled_date < CURDATE()"
            );

            $restartCheck->bind_param(
                'iis',
                $patientId,
                $caseId,
                $branchId
            );

            $restartCheck->execute();

            $restartRow = $restartCheck
                ->get_result()
                ->fetch_assoc();

            $restartCheck->close();

            $maxDaysOverdue = (int)($restartRow['max_days_overdue'] ?? 0);

            if ($maxDaysOverdue > $catchUpWindowDays) {
                $restartRequired = true;

                /*
                 * Retire ALL active rows from the old vaccination cycle,
                 * including Completed, Missed and Scheduled rows.
                 *
                 * This is what makes the new cycle behave as "back to D0"
                 * without deleting the patient's old vaccination history.
                 */
                $archivePreviousCycle = $conn->prepare(
                    "UPDATE vaccination_records
                     SET
                        is_archived = 1,
                        archived_at = NOW(),
                        archived_by = ?
                     WHERE patient_id = ?
                       AND case_id = ?
                       AND branch_id = ?
                       AND is_archived = 0"
                );

                $archivePreviousCycle->bind_param(
                    'iiis',
                    $userId,
                    $patientId,
                    $caseId,
                    $branchId
                );

                $archivePreviousCycle->execute();
                $previousCycleRows = (int)$archivePreviousCycle->affected_rows;
                $archivePreviousCycle->close();

                /*
                 * Re-open the case for the new active treatment cycle.
                 */
                $reopenCase = $conn->prepare(
                    "UPDATE animal_bite_cases
                     SET case_status = 'Ongoing'
                     WHERE case_id = ?
                       AND branch_id = ?"
                );

                $reopenCase->bind_param(
                    'is',
                    $caseId,
                    $branchId
                );

                $reopenCase->execute();
                $reopenCase->close();
            }

            } else {
                /*
                 * Non-anti-rabies assessments must not leave a newly generated
                 * anti-rabies schedule for this visit. Retire only the active
                 * Scheduled placeholders tied to the current visit.
                 */
                $archiveCurrentVisitSchedule = $conn->prepare(
                    "UPDATE vaccination_records
                     SET
                        is_archived = 1,
                        archived_at = NOW(),
                        archived_by = ?
                     WHERE visit_id = ?
                       AND branch_id = ?
                       AND vaccination_status = 'Scheduled'
                       AND is_archived = 0"
                );

                if ($archiveCurrentVisitSchedule) {
                    $archiveCurrentVisitSchedule->bind_param(
                        'iis',
                        $userId,
                        $visitId,
                        $branchId
                    );

                    $archiveCurrentVisitSchedule->execute();
                    $archiveCurrentVisitSchedule->close();
                }
            }

            $assessment = $conn->prepare(
                "INSERT INTO clinical_assessments
                 (
                    visit_id,
                    patient_id,
                    case_id,
                    branch_id,
                    nurse_id,
                    exposure_history,
                    date_of_exposure,
                    exposure_site,
                    animal_type,
                    animal_status,
                    bite_category,
                    treatment_profile,
                    route,
                    active_regimen,
                    important_concerns,
                    instructions_given,
                    chart_notes,
                    d0_date
                 )
                 VALUES (
                    ?, ?, ?, ?, ?, ?, NULLIF(?, ''),
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                 )
                 ON DUPLICATE KEY UPDATE
                    nurse_id = VALUES(nurse_id),
                    exposure_history = VALUES(exposure_history),
                    date_of_exposure = VALUES(date_of_exposure),
                    exposure_site = VALUES(exposure_site),
                    animal_type = VALUES(animal_type),
                    animal_status = VALUES(animal_status),
                    bite_category = VALUES(bite_category),
                    treatment_profile = VALUES(treatment_profile),
                    route = VALUES(route),
                    active_regimen = VALUES(active_regimen),
                    important_concerns = VALUES(important_concerns),
                    instructions_given = VALUES(instructions_given),
                    chart_notes = VALUES(chart_notes),
                    d0_date = VALUES(d0_date),
                    updated_at = NOW()"
            );

            $assessment->bind_param(
                'iiisisssssssssssss',
                $visitId,
                $patientId,
                $caseId,
                $branchId,
                $userId,
                $history,
                $exposureDate,
                $site,
                $animal,
                $animalStatus,
                $category,
                $profile,
                $route,
                $regimen,
                $concerns,
                $instructions,
                $notes,
                $d0Date
            );

            $assessment->execute();
            $assessment->close();

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

            $caseUpdate->bind_param(
                'ssssssis',
                $animal,
                $site,
                $category,
                $animalStatus,
                $exposureDate,
                $notes,
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

            $visitUpdate->bind_param(
                'iis',
                $userId,
                $visitId,
                $branchId
            );

            $visitUpdate->execute();
            $visitUpdate->close();

            if (!$isNonRabies) {
                /*
                 * Anti-rabies workflow only:
                 * generate the configured D0/D3/... schedule.
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
                workflowFlash(
                    'success',
                    'Assessment saved as Not Anti-Rabies Vaccine. No anti-rabies schedule was generated.'
                );
            } elseif ($restartRequired) {
                workflowFlash(
                    'success',
                    'Reassessment saved. The previous vaccination cycle was archived and a new schedule was generated from the confirmed D0 date.'
                );
            } else {
                workflowFlash(
                    'success',
                    'Assessment saved. The anti-rabies schedule was generated from the confirmed D0 date.'
                );
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

            assessmentSaveNonArvAssessment(
                $conn,
                $visit,
                $visitId,
                $userId,
                $branchId,
                $_POST
            );

            $administeredSummaries = [];

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
                    . ' | Case ID: ' . $caseId
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

                $usage = $conn->prepare(
                    "INSERT INTO inventory_usage_history
                     (item_id, branch_id, usage_date, quantity_used, patient_count)
                     VALUES (?, ?, ?, ?, 1)"
                );
                if (!$usage) {
                    throw new RuntimeException('Unable to prepare inventory usage.');
                }
                $usage->bind_param(
                    'issd', $itemId, $branchId, $administeredDate, $quantityBase
                );
                if (!$usage->execute()) {
                    $error = $usage->error;
                    $usage->close();
                    throw new RuntimeException(
                        'Unable to save inventory usage for '
                        . $vaccineName . ': ' . $error
                    );
                }
                $usage->close();

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
            );

        // ========================================================
        // COMPLETE CHART + SEND TO REGISTRY
        // ========================================================
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

            $message =
                $visit['full_name']
                . ' (Case '
                . $visit['case_number']
                . ') is ready for registry verification.';

            workflowNotifyRole(
                $conn,
                $branchId,
                4,
                'Chart Ready for Registry',
                $message,
                'registry'
            );

            workflowAudit(
                $conn,
                $userId,
                $branchId,
                'Signed chart and sent visit ' . $visitId . ' for registry',
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
    $markMissedStmt = $conn->prepare(
        "UPDATE vaccination_records
         SET vaccination_status = 'Missed'
         WHERE branch_id = ?
           AND is_archived = 0
           AND vaccination_status = 'Scheduled'
           AND scheduled_date IS NOT NULL
           AND scheduled_date < CURDATE()"
    );

    $markMissedStmt->bind_param('s', $branchId);
    $markMissedStmt->execute();

    $newlyMarkedMissed = (int)$markMissedStmt->affected_rows;

    $markMissedStmt->close();

    if ($newlyMarkedMissed > 0) {
        workflowAudit(
            $conn,
            $userId,
            $branchId,
            'Automatically marked '
                . $newlyMarkedMissed
                . ' overdue vaccination schedule(s) as Missed',
            'Vaccination Follow-up'
        );
    }
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

// ============================================================
// MISSED / OVERDUE FOLLOW-UP — COUNT
// ============================================================
$followupCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM (
        SELECT
            vr.patient_id,
            vr.case_id
        FROM vaccination_records vr
        WHERE vr.branch_id = ?
          AND vr.is_archived = 0
          AND vr.vaccination_status = 'Missed'
          AND vr.scheduled_date IS NOT NULL
          AND vr.scheduled_date < CURDATE()
        GROUP BY
            vr.patient_id,
            vr.case_id
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
 * 1-30 days overdue:
 *      Missed - Catch-up Eligible
 *
 * More than 30 days overdue:
 *      Restart Assessment Required
 *
 * This 30-day cutoff is a clinic-configured SmartBiteCare workflow
 * rule and should match the clinic supervisor's approved protocol.
 * Historical vaccination records are preserved.
 */
$overdueStmt = $conn->prepare(
    "SELECT
        vr.patient_id,
        vr.case_id,
        p.full_name,
        c.case_number,
        MIN(vr.scheduled_date) AS oldest_due,
        DATEDIFF(
            CURDATE(),
            MIN(vr.scheduled_date)
        ) AS days_overdue,
        COUNT(*) AS missed_dose_count
     FROM vaccination_records vr
     INNER JOIN patients p
        ON p.patient_id = vr.patient_id
     INNER JOIN animal_bite_cases c
        ON c.case_id = vr.case_id
     WHERE vr.branch_id = ?
       AND vr.is_archived = 0
       AND vr.vaccination_status = 'Missed'
       AND vr.scheduled_date IS NOT NULL
       AND vr.scheduled_date < CURDATE()
     GROUP BY
        vr.patient_id,
        vr.case_id,
        p.full_name,
        c.case_number
     ORDER BY
        days_overdue DESC,
        p.full_name ASC
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
    $daysOverdue = (int)$overdue['days_overdue'];

    if ($daysOverdue > $catchUpWindowDays) {
        $overdue['workflow_classification'] = 'restart_required';
        $overdue['workflow_label'] = 'Restart Assessment Required';
        $overdue['workflow_description'] =
            'More than '
            . $catchUpWindowDays
            . ' days overdue. Do not automatically continue the old schedule. '
            . 'Nurse reassessment and a new treatment cycle are required '
            . 'according to the clinic-approved workflow.';
    } else {
        $overdue['workflow_classification'] = 'catchup';
        $overdue['workflow_label'] = 'Missed - Catch-up Eligible';
        $overdue['workflow_description'] =
            'The scheduled vaccination was missed, but the case is still '
            . 'within the '
            . $catchUpWindowDays
            . '-day clinic catch-up window.';
    }
}
unset($overdue);

// SELECTED VISIT / ASSESSMENT
// ============================================================
$selected = null;

if ($selectedId > 0) {
    $stmt = $conn->prepare(
        "SELECT
            v.*,
            p.full_name,
            c.case_number,
            c.animal_type,
            c.bite_location,
            c.bite_category,
            c.animal_status,
            c.date_of_bite,
            a.*
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
}

$selectedNonRabies =
    $selected
    && (string)($selected['treatment_profile'] ?? '') === 'OTHER';

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
            COALESCE(
                SUM(
                    CASE
                        WHEN s.quantity_available > 0
                         AND (
                            s.expiration_date IS NULL
                            OR s.expiration_date >= CURDATE()
                         )
                        THEN s.quantity_available
                        ELSE 0
                    END
                ),
                0
            ) AS usable_stock
         FROM inventory_items i
         INNER JOIN units u
            ON u.unit_id = i.unit_id
         INNER JOIN inventory_categories c
            ON c.category_id = i.category_id
         LEFT JOIN inventory_stocks s
            ON s.item_id = i.item_id
           AND s.branch_id = ?
         WHERE c.category_name = 'Medical Supplies'
           AND i.item_name NOT LIKE '%Default%'
         GROUP BY
            i.item_id,
            i.item_name,
            i.unit_id,
            u.unit_name,
            i.base_unit_label,
            i.display_unit_label,
            i.conversion_to_base
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
    </style>
</head>

<body>

<!-- ============================================================
     SIDEBAR
     ============================================================ -->
<aside class="sidebar">

    <div class="logo-area">
        <div class="logo-frame">
            <img
                src="logo.png"
                alt="Smart Bite Care Logo"
                class="logo"
            >
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
                    <i class="bi bi-box-seam"></i>
                    <span>Supply Forecasting</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Notification.php">
                    <i class="bi bi-bell-fill"></i>

                    <span class="notification-label">
                        Notifications

                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge">
                                <?php echo $notification_count; ?>
                            </span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
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

                        <strong>Clinic-configured workflow:</strong>

                        missed schedules within

                        <strong>
                            <?= (int)$catchUpWindowDays ?> days
                        </strong>

                        remain in the catch-up workflow.

                        Cases overdue for more than

                        <strong>
                            <?= (int)$catchUpWindowDays ?> days
                        </strong>

                        require nurse reassessment before a new schedule
                        is created.

                        Previous vaccination history is preserved.

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
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                    Restart Assessment Required
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

                            <form method="post" class="row g-3">

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

                                <!-- Exposure Information -->
                                <div class="col-12 form-section">

                                    <h3 class="form-section-title">
                                        <i class="bi bi-journal-medical me-1"></i>
                                        Exposure Information
                                    </h3>

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

                                    <label
                                        class="form-label"
                                        for="exposure_site"
                                    >
                                        Exposure Site
                                    </label>

                                    <input
                                        class="form-control"
                                        id="exposure_site"
                                        name="exposure_site"
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

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="animal_type"
                                    >
                                        Animal/Exposure Source
                                    </label>

                                    <input
                                        class="form-control"
                                        id="animal_type"
                                        name="animal_type"
                                        value="<?= workflowH(
                                            (string)(
                                                $selected['animal_type']
                                                ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="animal_status"
                                    >
                                        Animal Status
                                    </label>

                                    <input
                                        class="form-control"
                                        id="animal_status"
                                        name="animal_status"
                                        value="<?= workflowH(
                                            (string)(
                                                $selected['animal_status']
                                                ?? ''
                                            )
                                        ) ?>"
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

                                <div class="col-md-4">

                                    <label
                                        class="form-label required"
                                        for="treatment_profile"
                                    >
                                        Treatment Profile
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
                                            Select anti-rabies profile
                                        </option>

                                        <?php
                                        foreach (
                                            [
                                                'PEP_ID' => 'PEP - Intradermal',
                                                'PEP_IM' => 'PEP - Intramuscular',
                                                'PREP' => 'PrEP',
                                                'BOOSTER' => 'Booster'
                                            ]
                                            as $value => $label
                                        ):
                                        ?>

                                            <option
                                                value="<?= workflowH($value) ?>"
                                                <?= ($selected['treatment_profile'] ?? '') === $value
                                                    ? 'selected'
                                                    : ''
                                                ?>
                                            >
                                                <?= workflowH($label) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label
                                        class="form-label"
                                        for="route"
                                    >
                                        Route
                                    </label>

                                    <input
                                        class="form-control"
                                        id="route"
                                        name="route"
                                        value="<?= workflowH(
                                            (string)($selected['route'] ?? '')
                                        ) ?>"
                                        placeholder="ID, IM or N/A"
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label
                                        class="form-label required"
                                        for="d0_date"
                                    >
                                        Actual D0 Date
                                    </label>

                                    <input
                                        type="date"
                                        class="form-control"
                                        id="d0_date"
                                        name="d0_date"
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

                                <div class="col-12">

                                    <label
                                        class="form-label"
                                        for="active_regimen"
                                    >
                                        Active Regimen/Product Plan
                                    </label>

                                    <input
                                        class="form-control"
                                        id="active_regimen"
                                        name="active_regimen"
                                        value="<?= workflowH(
                                            (string)(
                                                $selected['active_regimen']
                                                ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

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

                                        Notes and Instructions
                                    </h3>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="important_concerns"
                                    >
                                        Important Concerns/Escalation
                                    </label>

                                    <textarea
                                        class="form-control"
                                        id="important_concerns"
                                        name="important_concerns"
                                        rows="2"
                                    ><?= workflowH(
                                        (string)(
                                            $selected['important_concerns']
                                            ?? ''
                                        )
                                    ) ?></textarea>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        class="form-label"
                                        for="instructions_given"
                                    >
                                        Instructions Given
                                    </label>

                                    <textarea
                                        class="form-control"
                                        id="instructions_given"
                                        name="instructions_given"
                                        rows="2"
                                    ><?= workflowH(
                                        (string)(
                                            $selected['instructions_given']
                                            ?? ''
                                        )
                                    ) ?></textarea>

                                </div>

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
                                        class="btn btn-outline-primary"
                                        href="Nurse_Vaccination.php"
                                    >
                                        <i class="bi bi-shield-check me-1"></i>

                                        Record Administered Products
                                    </a>

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
                    || new Date().toISOString().slice(0, 10);
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

</body>
</html>
