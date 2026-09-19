<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';

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

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitId = 0;

    try {
        workflowVerifyCsrf();

        $action = (string)($_POST['action'] ?? '');
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
            $profile = (string)($_POST['treatment_profile'] ?? '');
            $route = trim((string)($_POST['route'] ?? ''));
            $regimen = trim((string)($_POST['active_regimen'] ?? ''));
            $concerns = trim((string)($_POST['important_concerns'] ?? ''));
            $instructions = trim((string)($_POST['instructions_given'] ?? ''));
            $notes = trim((string)($_POST['chart_notes'] ?? ''));
            $d0Date = (string)($_POST['d0_date'] ?? '');

            $profiles = ['PEP_ID', 'PEP_IM', 'PREP', 'BOOSTER'];

            if (
                $history === '' ||
                $d0Date === '' ||
                !in_array($profile, $profiles, true)
            ) {
                throw new RuntimeException(
                    'History, treatment profile, and D0 date are required.'
                );
            }

            foreach ([$d0Date, $exposureDate] as $date) {
                if (
                    $date !== '' &&
                    DateTime::createFromFormat('Y-m-d', $date)?->format('Y-m-d') !== $date
                ) {
                    throw new RuntimeException('Enter valid dates.');
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

            /*
             * Generate the active schedule only AFTER an old restart-required
             * cycle has been retired. This prevents old Missed rows from
             * remaining in Missed / Follow-ups and prevents Nurse Vaccination
             * from reusing an old schedule placeholder.
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
                    'Saved nurse assessment and schedule for visit ' . $visitId,
                    'Clinical Assessment'
                );
            }

            $conn->commit();

            if ($restartRequired) {
                workflowFlash(
                    'success',
                    'Reassessment saved. The previous vaccination cycle was archived and a new schedule was generated from the confirmed D0 date.'
                );
            } else {
                workflowFlash(
                    'success',
                    'Assessment saved. The schedule was generated from the confirmed D0 date.'
                );
            }

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
                                    name="action"
                                    value="save_assessment"
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
                                        required
                                    >

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
                                        value="<?= workflowH(
                                            (string)(
                                                $selected['d0_date']
                                                ?? date('Y-m-d')
                                            )
                                        ) ?>"
                                        required
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
                                    >
                                        <i class="bi bi-save-fill me-1"></i>

                                        Save Assessment and Generate Schedule
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
