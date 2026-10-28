<?php
session_start();
require_once 'sources/pagination_helper.php';
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';
require_once 'sources/patient_display_helper.php';
require_once 'sources/registry_lock_helpers.php';

$user = workflowRequireUser($conn, 4);

$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
// Opened from a "Chart Ready for Registry" notification: mark it read and
// highlight that visit in the queue.
$highlightVisitId = (int)($_GET['visit_id'] ?? 0);
if (preg_match('/^db_(\d+)$/', (string)($_GET['notification'] ?? ''), $notificationMatch)) {
    $readStmt = $conn->prepare("UPDATE notifications SET is_read = 1
        WHERE notification_id = ? AND user_id = ? AND notification_type = 'registry'");
    $readNotificationId = (int)$notificationMatch[1];
    $readStmt->bind_param('ii', $readNotificationId, $userId);
    $readStmt->execute();
    $readStmt->close();
}
$notification_count = getAdminStaffNotificationCount($conn, $branchId);
$csrf = workflowCsrfToken();

// Verify again a record corrected after verification (For Re-verification).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverify') {
    try {
        workflowVerifyCsrf();
        workflowFlash('success', registryReverify($conn, $userId, $branchId, (int)($_POST['registry_id'] ?? 0),
            (string)($_POST['verification_remarks'] ?? '')));
    } catch (Throwable $e) {
        workflowFlash('danger', $e->getMessage());
    }
    header('Location: AdminStaff_Registry.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $visitId = (int)($_POST['visit_id'] ?? 0);
        $registryNumber = trim((string)($_POST['registry_number'] ?? ''));
        $erig = (float)($_POST['erig'] ?? 0);
        $ats = (float)($_POST['ats'] ?? 0);
        $tt = isset($_POST['tt']) ? 1 : 0;
        $regimen = trim((string)($_POST['active_regimen'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($visitId < 1 || $registryNumber === '' || $erig < 0 || $ats < 0) {
            throw new RuntimeException('Registry number and valid non-negative treatment values are required.');
        }

        $visitStmt = $conn->prepare(
            "SELECT v.patient_id, v.case_id, v.workflow_status, p.full_name, c.case_number,
                    c.animal_status, a.active_regimen AS assessed_regimen
             FROM patient_visits v INNER JOIN patients p ON p.patient_id=v.patient_id
             INNER JOIN animal_bite_cases c ON c.case_id=v.case_id
             LEFT JOIN clinical_assessments a ON a.visit_id=v.visit_id
             WHERE v.visit_id=? AND v.branch_id=? LIMIT 1"
        );
        $visitStmt->bind_param('is', $visitId, $branchId);
        $visitStmt->execute();
        $visit = $visitStmt->get_result()->fetch_assoc();
        $visitStmt->close();
        if (!$visit || $visit['workflow_status'] !== 'For Registry') {
            throw new RuntimeException('Only a Nurse-signed chart marked For Registry can be registered.');
        }

        $caseId = (int)$visit['case_id'];
        $doseStmt = $conn->prepare(
            "SELECT DISTINCT dose_number FROM vaccination_records
             WHERE case_id=? AND branch_id=? AND vaccination_status='Completed'
               AND date_administered IS NOT NULL AND date_administered<=CURDATE() AND is_archived=0"
        );
        $doseStmt->bind_param('is', $caseId, $branchId);
        $doseStmt->execute();
        $doseResult = $doseStmt->get_result();
        $flags = array_fill(1, 6, 0);
        while ($row = $doseResult->fetch_assoc()) {
            $dose = (int)$row['dose_number'];
            if ($dose >= 1 && $dose <= 6) $flags[$dose] = 1;
        }
        $doseStmt->close();

        $conn->begin_transaction();
        $existingStmt = $conn->prepare('SELECT registry_id FROM registry_records WHERE case_id=? AND is_archived=0 LIMIT 1 FOR UPDATE');
        $existingStmt->bind_param('i', $caseId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        $animalStatus = (string)($visit['animal_status'] ?? '');
        if ($regimen === '') $regimen = (string)($visit['assessed_regimen'] ?? '');
        // A verified record keeps its registry values: verifying the next dose
        // only adds that dose. Other changes go through Request correction.
        $registryLock = registryLockForCase($conn, $caseId);
        if ($registryLock) {
            if ($registryLock['registry_status'] === 'For Re-verification') {
                throw new RuntimeException('This record has a correction waiting for re-verification. Verify the correction first (For Re-verification list above).');
            }
            registryLockAssertUnchanged($conn, $caseId, null, [
                'registry.registry_number' => $registryNumber, 'registry.erig' => (string)$erig, 'registry.ats' => (string)$ats,
                'registry.tt' => (string)$tt, 'registry.active_regimen' => $regimen, 'registry.remarks' => $remarks,
            ]);
        }

        if ($existing) {
            $registryId = (int)$existing['registry_id'];
            $stmt = $conn->prepare(
                "UPDATE registry_records SET visit_id=?, branch_id=?, registry_number=?,
                 status_of_biting_animal=?, erig=?, ats=?, tt=?, active_regimen=?,
                 dose_d0=?, dose_d3=?, dose_d7=?, dose_d14=?, dose_d21=?, dose_d28_30=?,
                 remarks=?, updated_by=?, updated_at=NOW(), registry_status='Registered',
                 registered_by=?, registered_at=NOW()
                 WHERE registry_id=?"
            );
            $stmt->bind_param(
                'isssddisiiiiiisiii',
                $visitId, $branchId, $registryNumber, $animalStatus, $erig, $ats, $tt, $regimen,
                $flags[1], $flags[2], $flags[3], $flags[4], $flags[5], $flags[6],
                $remarks, $userId, $userId, $registryId
            );
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO registry_records
                 (case_id,visit_id,branch_id,created_by,registry_number,status_of_biting_animal,
                  erig,ats,tt,active_regimen,dose_d0,dose_d3,dose_d7,dose_d14,dose_d21,dose_d28_30,
                  remarks,updated_by,updated_at,registry_status,registered_by,registered_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'Registered',?,NOW())"
            );
            $stmt->bind_param(
                'iisissddisiiiiiisii',
                $caseId, $visitId, $branchId, $userId, $registryNumber, $animalStatus,
                $erig, $ats, $tt, $regimen, $flags[1], $flags[2], $flags[3], $flags[4],
                $flags[5], $flags[6], $remarks, $userId, $userId
            );
        }
        $stmt->execute();
        $stmt->close();

        $visitUpdate = $conn->prepare(
            "UPDATE patient_visits SET workflow_status='Registered', registered_at=NOW(), updated_at=NOW()
             WHERE visit_id=? AND branch_id=?"
        );
        $visitUpdate->bind_param('is', $visitId, $branchId);
        $visitUpdate->execute();
        $visitUpdate->close();
        workflowAudit($conn, $userId, $branchId, 'Verified registry for ' . $visit['full_name'] . ' (' . $registryNumber . ')', 'Registry');
        workflowNotifyRole($conn, $branchId, 3, 'Registry Completed', $visit['full_name'] . ' was registered by Administrative Staff.', 'registry');
        $conn->commit();
        workflowFlash('success', 'Registry verified and saved.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        workflowFlash('danger', $e->getMessage());
    }
    header('Location: AdminStaff_Registry.php');
    exit;
}

$queueStmt = $conn->prepare(
    "SELECT v.visit_id,v.visit_date,v.workflow_status,p.full_name,p.contact_number,c.case_id,c.case_number,
            c.animal_status,a.bite_category,a.treatment_profile,a.route,a.active_regimen,a.chart_signed_at,
            r.registry_number,r.erig,r.ats,r.tt,r.remarks,r.registry_status,
            " . patientCaseVaccinationStatusSql('c') . " AS treatment_status
     FROM patient_visits v INNER JOIN patients p ON p.patient_id=v.patient_id
     INNER JOIN animal_bite_cases c ON c.case_id=v.case_id
     INNER JOIN clinical_assessments a ON a.visit_id=v.visit_id
     LEFT JOIN registry_records r ON r.case_id=v.case_id AND r.is_archived=0
     WHERE v.branch_id=? AND v.workflow_status IN ('For Registry','Registered')
     ORDER BY FIELD(v.workflow_status,'For Registry','Registered'),v.sent_for_registry_at"
);
$queueStmt->bind_param('s', $branchId);
$queueStmt->execute();
$records = $queueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$queueStmt->close();
// Server-side pages of the queue (10/25/50). A link to one visit opens the page that holds it.
$registryPage = paginationFromRequest('', 10);
if ($highlightVisitId > 0 && !isset($_GET['page'])) {
    foreach ($records as $index => $queueRow) {
        if ((int)$queueRow['visit_id'] === $highlightVisitId) {
            $registryPage['page'] = intdiv($index, $registryPage['per_page']) + 1;
            break;
        }
    }
}
$queueRecords = paginationSlice($records, $registryPage);

$forRegistryCount = 0;
$registeredCount = 0;
foreach ($records as $record) {
    if ($record['workflow_status'] === 'For Registry') {
        $forRegistryCount++;
    } elseif ($record['workflow_status'] === 'Registered') {
        $registeredCount++;
    }
}

$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Administrative Staff');
$flash = workflowTakeFlash();
$pendingCorrections = registryCorrectionsPending($conn, $branchId);
$correctionsByRegistry = [];
foreach ($pendingCorrections as $correction) $correctionsByRegistry[(int)$correction['registry_id']][] = $correction;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registry Queue - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --accent: #F21D2F;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
            background: #f0f2f5;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 80px;
            padding: 0 35px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .topbar h3 {
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
        }

        .topbar h3 small {
            margin-left: 8px;
            color: #6c757d;
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
            margin-left: 4px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .page-content {
            padding: 30px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
            margin-bottom: 25px;
        }

        .stat-card {
            position: relative;
            padding: 22px 24px;
            overflow: hidden;
            background: #fff;
            border-left: 5px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, .12);
        }

        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.success { border-left-color: var(--success); }

        .stat-card .stat-icon {
            position: absolute;
            top: 18px;
            right: 22px;
            color: var(--primary);
            font-size: 32px;
            opacity: .15;
        }

        .stat-card.warning .stat-icon { color: #d89c00; }
        .stat-card.success .stat-icon { color: var(--success); }

        .stat-card h6 {
            margin: 0 0 8px;
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .4px;
            text-transform: uppercase;
        }

        .stat-card h2 {
            margin: 0;
            color: var(--primary);
            font-size: 38px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-card p {
            margin: 8px 0 0;
            color: #8a93a2;
            font-size: 12px;
        }

        .content-card {
            overflow: hidden;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 20px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, .08);
        }

        .content-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h5 {
            margin: 0;
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
        }

        .content-card-header p {
            margin: 4px 0 0;
            color: #7d8798;
            font-size: 13px;
        }

        .section-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            margin-right: 8px;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
        }

        .registry-table {
            min-width: 1150px;
            margin: 0;
        }

        .registry-table thead th {
            padding: 14px 16px;
            color: #fff;
            background: var(--primary);
            border: 0;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .registry-table tbody td {
            padding: 16px;
            color: #374151;
            border-bottom: 1px solid #edf0f5;
            font-size: 14px;
            vertical-align: top;
        }

        .registry-table tbody tr:hover {
            background: #f8f9ff;
        }

        .patient-name {
            color: #18233f;
            font-size: 15px;
            font-weight: 700;
        }

        .case-number {
            color: var(--primary);
            font-weight: 600;
        }

        .detail-label {
            color: #98a2b3;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .badge-status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pending {
            color: #8a6500;
            background: #fff3cd;
        }

        .status-success {
            color: #1f7a35;
            background: #e5f5e9;
        }

        .registry-form {
            min-width: 600px;
        }

        .registry-form .form-label {
            margin-bottom: 4px;
            color: #596579;
            font-size: 11px;
            font-weight: 600;
        }

        .registry-form .form-control {
            min-height: 36px;
            border: 1px solid #d7dce5;
            border-radius: 8px;
            font-size: 13px;
        }

        .registry-form .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .18rem rgba(43, 58, 140, .12);
        }

                .tt-check {
                    display: flex;
                    align-items: center;
                    min-height: 36px;
                    padding: 6px 10px;
                    background: #f8f9fc;
                    border: 1px solid #d7dce5;
                    border-radius: 8px;
                }

                .tt-check .form-check-input {
                    margin: 0 7px 0 0;
                }

                .tt-check .form-check-label {
                    color: #596579;
                    font-size: 12px;
                    font-weight: 600;
                }

                .btn-verify {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 7px;
                    min-height: 38px;
                    padding: 8px 15px;
                    color: #fff;
                    background: var(--success);
                    border: 1px solid var(--success);
                    border-radius: 8px;
                    font-size: 13px;
                    font-weight: 600;
                }

                .btn-verify:hover {
                    color: #fff;
                    background: #218838;
                    border-color: #218838;
                }

                .registered-summary {
                    min-width: 240px;
                    padding: 12px 14px;
                    background: #f3fbf5;
                    border: 1px solid #d5eedb;
                    border-radius: 10px;
                }

                .registered-summary strong {
                    color: #1f7a35;
                }

                .empty-state {
                    padding: 52px 20px !important;
                    color: #98a2b3 !important;
                    text-align: center;
                }

                .empty-state i {
                    display: block;
                    margin-bottom: 8px;
                    font-size: 36px;
                }

                .alert {
                    border: 0;
                    border-radius: 12px;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
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

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .topbar {
                height: 70px;
                padding: 0 16px;
            }

            .topbar h3 {
                font-size: 20px;
            }

            .topbar h3 small,
            .profile-role {
                display: none;
            }

            .page-content {
                padding: 20px 16px;
            }

            .content-card-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    
        /* =========================================================
           CLIENT-SIDE TABLE PAGINATION
           ========================================================= */
        .table-pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 14px 20px;
            background: #fff;
            border-top: 1px solid #edf0f5;
        }

        .table-pagination-summary {
            color: #6f7b91;
            font-size: 12px;
        }

        .table-pagination-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rows-per-page-control {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #6f7b91;
            font-size: 12px;
            white-space: nowrap;
        }

        .rows-per-page-control select {
            width: 78px;
            min-height: 34px;
            padding: 4px 28px 4px 10px;
            border: 1px solid #dde3ee;
            border-radius: 8px;
            background-color: #fff;
            color: #34405d;
            font-size: 12px;
        }

        .table-page-buttons {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .table-page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dde3ee;
            border-radius: 8px;
            background: #fff;
            color: #2B3A8C;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
        }

        .table-page-btn:hover:not(:disabled),
        .table-page-btn.active {
            color: #fff;
            background: #2B3A8C;
            border-color: #2B3A8C;
        }

        .table-page-btn:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        @media (max-width: 576px) {
            .table-pagination-bar {
                align-items: flex-start;
                flex-direction: column;
            }
        }

</style>
</head>

<body>
    <!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="sidebar">

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

        <nav class="nav-menu">
            <ul>
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
                <li><a class="active" href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
                <li><a href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li>
                    <a href="AdminStaff_Notifications.php" class="notification-link">
                        <i class="bi bi-bell-fill"></i>
                        <span>Notifications</span>

                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge">
                                <?php echo $notification_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <div class="main">
        <div class="topbar">
            <h3>Registry Queue <small><?php echo workflowH($branchName); ?></small></h3>
            <div class="dropdown">
                <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                        type="button" id="adminStaffProfileMenu"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Admin Staff</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                    aria-labelledby="adminStaffProfileMenu">
                    <li><h6 class="dropdown-header">Account options</h6></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                            <i class="bi bi-key-fill me-2"></i>Change Password
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 text-danger" href="#"
                           data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="page-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo workflowH($flash['type']); ?> alert-dismissible fade show" role="alert">
                    <?php echo workflowH($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="stats-container">
                <div class="stat-card">
                    <i class="bi bi-clipboard-data-fill stat-icon"></i>
                    <h6>Total Registry Records</h6>
                    <h2><?php echo number_format(count($records)); ?></h2>
                    <p>Records currently displayed</p>
                </div>
                <div class="stat-card warning">
                    <i class="bi bi-hourglass-split stat-icon"></i>
                    <h6>For Registry</h6>
                    <h2><?php echo number_format($forRegistryCount); ?></h2>
                    <p>Nurse-signed charts awaiting verification</p>
                </div>
                <div class="stat-card success">
                    <i class="bi bi-check-circle-fill stat-icon"></i>
                    <h6>Registered</h6>
                    <h2><?php echo number_format($registeredCount); ?></h2>
                    <p>Registry verification completed</p>
                </div>
            </div>

            <?php if ($correctionsByRegistry): ?>
            <section class="content-card mb-4" id="reverification">
                <div class="content-card-header">
                    <div>
                        <h5><span class="section-icon"><i class="bi bi-arrow-repeat"></i></span>For Re-verification</h5>
                        <p>Verified records that were corrected. Check the old and new values and the reason, then verify again to lock the record.</p>
                    </div>
                    <span class="badge rounded-pill text-bg-warning"><?php echo count($correctionsByRegistry); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table registry-table align-middle">
                        <thead><tr><th>Patient and Case</th><th>Correction</th><th>Changes (old → new)</th><th>Verify again</th></tr></thead>
                        <tbody>
                        <?php foreach ($correctionsByRegistry as $registryId => $corrections): ?>
                            <?php $first = $corrections[0]; ?>
                            <tr>
                                <td>
                                    <div class="patient-name"><?php echo workflowH($first['full_name']); ?></div>
                                    <div class="case-number"><?php echo workflowH($first['case_number']); ?> · Registry <?php echo workflowH((string)$first['registry_number']); ?></div>
                                    <a class="small" href="<?php echo workflowH(registryCorrectionUrl((int)$first['case_id'])); ?>">Open correction page</a>
                                </td>
                                <td class="small">
                                    <?php foreach ($corrections as $c): ?>
                                        <div class="mb-2">#<?php echo (int)$c['correction_id']; ?> by <?php echo workflowH(($c['requester_name'] ?? '') . ' (' . ($c['requester_role'] ?? '') . ')'); ?>,
                                            <?php echo workflowH(date('M d, Y h:i A', strtotime($c['requested_at']))); ?>
                                            <div class="text-muted">Reason: <?php echo workflowH($c['reason']); ?></div></div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="small">
                                    <?php foreach ($corrections as $c): foreach ($c['changes_list'] as $ch): ?>
                                        <div><strong><?php echo workflowH($ch['label']); ?>:</strong> <?php echo workflowH($ch['old'] === '' ? '—' : $ch['old']); ?> → <?php echo workflowH($ch['new'] === '' ? '—' : $ch['new']); ?></div>
                                    <?php endforeach; endforeach; ?>
                                </td>
                                <td style="min-width:220px;">
                                    <form method="post" data-confirm="Verify this corrected record again? It will be locked." data-confirm-title="Verify corrected record" data-confirm-ok="Verify and lock">
                                        <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                        <input type="hidden" name="action" value="reverify">
                                        <input type="hidden" name="registry_id" value="<?php echo (int)$registryId; ?>">
                                        <input class="form-control form-control-sm mb-2" name="verification_remarks" maxlength="500" placeholder="Remark (optional)">
                                        <button type="submit" class="btn-verify w-100"><i class="bi bi-check2-circle"></i> Verify again</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <section class="content-card">
                <div class="content-card-header">
                    <div>
                        <h5><span class="section-icon"><i class="bi bi-journal-medical"></i></span>Registry Verification Queue</h5>
                        <p>Charts appear here after the Nurse completes and signs the clinical assessment.</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="AdminStaff_RegistryLogbook.php">
                            <i class="bi bi-journal-text me-1"></i>Logbook View &amp; Excel Export
                        </a>
                        <span class="badge rounded-pill text-bg-light border">
                            <?php echo number_format(count($records)); ?> records
                        </span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table registry-table align-middle" id="registryQueueTable">
                        <thead>
                            <tr>
                                <th>Patient and Case</th>
                                <th>Assessment</th>
                                <th>Status</th>
                                <th>Registry Verification</th>
                            </tr>
                        </thead>
                        <tbody id="registryQueueBody">
                            <?php if (!$records): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        No Nurse-signed charts are waiting for registry.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($queueRecords as $row): ?>
                                <tr class="registry-queue-row<?= (int)$row['visit_id'] === $highlightVisitId ? ' table-warning' : '' ?>" id="registry-visit-<?= (int)$row['visit_id'] ?>">
                                    <td>
                                        <div class="patient-name"><?php echo workflowH($row['full_name']); ?></div>
                                        <div class="case-number"><?php echo workflowH($row['case_number']); ?></div>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?php echo workflowH(date('M d, Y', strtotime($row['visit_date']))); ?>
                                        </div>
                                        <?php if (!empty($row['contact_number'])): ?>
                                            <div class="text-muted small mt-1">
                                                <i class="bi bi-telephone me-1"></i>
                                                <?php echo workflowH($row['contact_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="detail-label">Bite Category</div>
                                        <div><?php echo workflowH($row['bite_category'] ?: 'Unspecified'); ?></div>
                                        <div class="detail-label mt-2">Vaccination Regimen</div>
                                        <div><?php echo workflowH(workflowProfileLabel($row['treatment_profile'] ?? null, $row['route'] ?? null)); ?></div>
                                        <div class="detail-label mt-2">Chart Signed</div>
                                        <div class="small">
                                            <?php
                                            echo !empty($row['chart_signed_at'])
                                                ? workflowH(date('M d, Y h:i A', strtotime($row['chart_signed_at'])))
                                                : '<span class="text-muted">Not signed</span>';
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($row['workflow_status'] === 'For Registry'): ?>
                                            <span class="badge-status status-pending">For Registry</span>
                                        <?php else: ?>
                                            <span class="badge-status status-success">Registered</span>
                                        <?php endif; ?>
                                        <div class="small text-muted mt-2">Treatment:
                                            <strong><?php echo workflowH((string)$row['treatment_status']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $rowLocked = in_array((string)($row['registry_status'] ?? ''), REGISTRY_LOCKED_STATUSES, true); ?>
                                        <?php if ($row['workflow_status'] === 'For Registry' && $rowLocked): ?>
                                            <div class="small text-info mb-2"><i class="bi bi-lock-fill"></i> This case was verified before: its registry values are locked. Verifying adds this dose only.
                                                <a href="<?php echo workflowH(registryCorrectionUrl((int)$row['case_id'])); ?>">Request correction</a></div>
                                        <?php endif; ?>
                                        <?php if ($row['workflow_status'] === 'For Registry'): ?>
                                            <form method="post" class="registry-form"
                                                  data-patient="<?php echo workflowH($row['full_name']); ?>"
                                                  data-case="<?php echo workflowH($row['case_number']); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                                <input type="hidden" name="visit_id" value="<?php echo (int)$row['visit_id']; ?>">

                                                <div class="row g-2">
                                                    <div class="col-md-5">
                                                        <label class="form-label">Registry Number</label>
                                                        <input
                                                            class="form-control form-control-sm"
                                                            name="registry_number"
                                                            <?php echo $rowLocked ? 'readonly' : ''; ?>
                                                            required
                                                            value="<?php echo workflowH($row['registry_number'] ?: $row['case_number']); ?>"
                                                            placeholder="Registry number"
                                                        >
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">ERIG (mL)</label>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            class="form-control form-control-sm"
                                                            name="erig"
                                                            <?php echo $rowLocked ? 'readonly' : ''; ?>
                                                            value="<?php echo workflowH((string)($row['erig'] ?? 0)); ?>"
                                                        >
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">ATS (mL)</label>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            class="form-control form-control-sm"
                                                            name="ats"
                                                            <?php echo $rowLocked ? 'readonly' : ''; ?>
                                                            value="<?php echo workflowH((string)($row['ats'] ?? 0)); ?>"
                                                        >
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Tetanus Toxoid</label>
                                                        <div class="tt-check">
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                name="tt"
                                                                <?php echo $rowLocked ? 'onclick="return false;" title="Locked: use Request correction"' : ''; ?>
                                                                id="tt-<?php echo (int)$row['visit_id']; ?>"
                                                                <?php echo !empty($row['tt']) ? 'checked' : ''; ?>
                                                            >
                                                            <label class="form-check-label" for="tt-<?php echo (int)$row['visit_id']; ?>">
                                                                TT given
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-5">
                                                        <label class="form-label">Active Regimen</label>
                                                        <input
                                                            class="form-control form-control-sm"
                                                            name="active_regimen"
                                                            <?php echo $rowLocked ? 'readonly' : ''; ?>
                                                            value="<?php echo workflowH($row['active_regimen'] ?? ''); ?>"
                                                            placeholder="Active regimen"
                                                        >
                                                    </div>
                                                    <div class="col-md-5">
                                                        <label class="form-label">Registry Remarks</label>
                                                        <input
                                                            class="form-control form-control-sm"
                                                            name="remarks"
                                                            <?php echo $rowLocked ? 'readonly' : ''; ?>
                                                            value="<?php echo workflowH($row['remarks'] ?? ''); ?>"
                                                            placeholder="Registry remarks"
                                                        >
                                                    </div>
                                                    <div class="col-md-2 d-flex align-items-end">
                                                        <button
                                                            type="submit"
                                                            class="btn-verify w-100"
                                                        >
                                                            <i class="bi bi-check2-circle"></i> Verify
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="registered-summary">
                                                <?php if (($row['registry_status'] ?? '') === 'For Re-verification'): ?>
                                                    <span class="badge text-bg-warning mb-2">For Re-verification</span>
                                                <?php elseif ($rowLocked): ?>
                                                    <span class="badge text-bg-success mb-2"><i class="bi bi-lock-fill"></i> Verified · locked</span>
                                                <?php endif; ?>
                                                <div class="detail-label">Registry Number</div>
                                                <strong><?php echo workflowH($row['registry_number'] ?? 'Registered'); ?></strong>
                                                <div class="small text-muted mt-2">
                                                    ERIG: <?php echo workflowH((string)($row['erig'] ?? 0)); ?> mL
                                                    &nbsp;|&nbsp;
                                                    ATS: <?php echo workflowH((string)($row['ats'] ?? 0)); ?> mL
                                                    &nbsp;|&nbsp;
                                                    TT: <?php echo !empty($row['tt']) ? 'Yes' : 'No'; ?>
                                                </div>
                                                <?php if ($rowLocked): ?>
                                                    <a class="btn btn-sm btn-outline-primary mt-2" href="<?php echo workflowH(registryCorrectionUrl((int)$row['case_id'])); ?>"><i class="bi bi-pencil-square me-1"></i>Request correction</a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php echo paginationRender($registryPage, '', 'records'); ?>
            </section>
        </div>
    </div>

    <div class="modal fade confirm-modal" id="registryConfirmModal" tabindex="-1"
         aria-labelledby="registryConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-icon"><i class="bi bi-journal-check"></i></div>
                    <h2 class="modal-title" id="registryConfirmModalLabel">Verify registry record?</h2>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Please review the registry information before saving this patient record.</p>
                    <div class="confirmation-summary">
                        <strong id="confirmPatientName">Patient</strong>
                        <span id="confirmCaseNumber">Case</span>
                        <span id="confirmRegistryNumber">Registry number</span>
                    </div>
                    <div class="confirmation-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>This marks the visit as Registered and notifies the assigned Nurse. Make sure the registry number and treatment entries are correct.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-confirm" id="confirmRegistryButton">
                        <i class="bi bi-check-circle-fill me-1"></i>Yes, Verify Record
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
         aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-icon logout-icon"><i class="bi bi-box-arrow-right"></i></div>
                    <h2 class="modal-title" id="logoutConfirmModalLabel">Log out of Smart Bite Care?</h2>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Make sure you have saved any unfinished registry entry before leaving your account.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <a href="logout.php" class="btn btn-danger d-flex align-items-center justify-content-center">
                        <i class="bi bi-box-arrow-right me-1"></i>Yes, Log Out
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/sources/confirm_modal.php'; echo sbcConfirmModalScript(); ?>
    <script src="sources/sidebar.js"></script>
    <script>
    // Scroll to the registry entry opened from a notification.
    document.addEventListener('DOMContentLoaded', function () {
        const target = document.getElementById('registry-visit-<?= (int)$highlightVisitId ?>');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
        document.addEventListener('DOMContentLoaded', function () {
            const modalElement = document.getElementById('registryConfirmModal');
            const confirmButton = document.getElementById('confirmRegistryButton');
            const patientOutput = document.getElementById('confirmPatientName');
            const caseOutput = document.getElementById('confirmCaseNumber');
            const registryOutput = document.getElementById('confirmRegistryNumber');
            let pendingForm = null;

            document.querySelectorAll('.registry-form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (form.dataset.confirmed === 'true') {
                        return;
                    }

                    event.preventDefault();
                    pendingForm = form;

                    const registryInput = form.querySelector('[name="registry_number"]');
                    patientOutput.textContent = form.dataset.patient || 'Patient';
                    caseOutput.textContent = 'Case: ' + (form.dataset.case || '—');
                    registryOutput.textContent = 'Registry number: ' + (registryInput ? registryInput.value : '—');

                    bootstrap.Modal.getOrCreateInstance(modalElement).show();
                });
            });

            confirmButton.addEventListener('click', function () {
                if (!pendingForm) {
                    return;
                }

                pendingForm.dataset.confirmed = 'true';
                confirmButton.disabled = true;
                confirmButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving...';
                pendingForm.requestSubmit();
            });

            modalElement.addEventListener('hidden.bs.modal', function () {
                pendingForm = null;
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Yes, Verify Record';
            });
        });
    </script>
</body>
</html>
