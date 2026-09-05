<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 4);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Administrative Staff');
$csrf = workflowCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'check_in') {
            $patientId = (int)($_POST['patient_id'] ?? 0);
            $caseId = (int)($_POST['case_id'] ?? 0);
            $visitType = (string)($_POST['visit_type'] ?? '');
            $notes = trim((string)($_POST['notes'] ?? ''));
            $allowedTypes = ['New Patient', 'Follow-up', 'Repeating Patient'];

            if ($patientId < 1 || $caseId < 1 || !in_array($visitType, $allowedTypes, true)) {
                throw new RuntimeException('Choose a valid patient, case, and visit type.');
            }

            $check = $conn->prepare(
                'SELECT p.full_name, c.case_number
                 FROM patients p
                 INNER JOIN animal_bite_cases c ON c.patient_id = p.patient_id
                 WHERE p.patient_id = ?
                   AND p.branch_id = ?
                   AND p.is_archived = 0
                   AND c.case_id = ?
                   AND c.branch_id = ?
                   AND c.is_archived = 0
                 LIMIT 1'
            );
            $check->bind_param('isis', $patientId, $branchId, $caseId, $branchId);
            $check->execute();
            $case = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$case) {
                throw new RuntimeException('Patient/case was not found in your branch.');
            }

            $existing = $conn->prepare(
                "SELECT visit_id, workflow_status
                 FROM patient_visits
                 WHERE case_id = ? AND visit_date = CURDATE()
                 LIMIT 1"
            );
            $existing->bind_param('i', $caseId);
            $existing->execute();
            $existingVisit = $existing->get_result()->fetch_assoc();
            $existing->close();

            if ($existingVisit && $existingVisit['workflow_status'] !== 'Cancelled') {
                throw new RuntimeException('This case is already checked in today.');
            }

            $conn->begin_transaction();

            if ($existingVisit) {
                $visitId = (int)$existingVisit['visit_id'];
                $stmt = $conn->prepare(
                    "UPDATE patient_visits
                     SET visit_type = ?,
                         workflow_status = 'Waiting for Nurse',
                         checked_in_by = ?,
                         assigned_nurse = NULL,
                         check_in_at = NOW(),
                         notes = ?,
                         updated_at = NOW()
                     WHERE visit_id = ? AND branch_id = ?"
                );
                $stmt->bind_param('sisis', $visitType, $userId, $notes, $visitId, $branchId);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO patient_visits
                     (patient_id, case_id, branch_id, visit_type, visit_date,
                      workflow_status, checked_in_by, notes)
                     VALUES (?, ?, ?, ?, CURDATE(), 'Waiting for Nurse', ?, ?)"
                );
                $stmt->bind_param(
                    'iissis',
                    $patientId,
                    $caseId,
                    $branchId,
                    $visitType,
                    $userId,
                    $notes
                );
            }

            $stmt->execute();
            $stmt->close();

            $message = $case['full_name']
                . ' (Case ' . $case['case_number'] . ') is waiting for assessment.';

            workflowNotifyRole(
                $conn,
                $branchId,
                3,
                'Patient Waiting for Nurse',
                $message,
                'patient_queue'
            );
            workflowAudit(
                $conn,
                $userId,
                $branchId,
                'Checked in ' . $case['full_name'] . ' as ' . $visitType,
                'Patient Visit'
            );

            $conn->commit();
            workflowFlash('success', 'Patient checked in and sent to the Nurse queue.');
        } elseif ($action === 'cancel') {
            $visitId = (int)($_POST['visit_id'] ?? 0);

            $stmt = $conn->prepare(
                "UPDATE patient_visits
                 SET workflow_status = 'Cancelled', updated_at = NOW()
                 WHERE visit_id = ?
                   AND branch_id = ?
                   AND workflow_status IN ('Checked In', 'Waiting for Nurse')"
            );
            $stmt->bind_param('is', $visitId, $branchId);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Only a waiting visit can be cancelled.');
            }
            $stmt->close();

            workflowAudit(
                $conn,
                $userId,
                $branchId,
                'Cancelled visit ID ' . $visitId,
                'Patient Visit'
            );
            workflowFlash('success', 'Visit cancelled.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        workflowFlash('danger', $exception->getMessage());
    }

    header('Location: AdminStaff_VisitQueue.php');
    exit;
}

$caseStmt = $conn->prepare(
    "SELECT p.patient_id,
            p.full_name,
            c.case_id,
            c.case_number,
            c.case_status
     FROM patients p
     INNER JOIN animal_bite_cases c ON c.patient_id = p.patient_id
     WHERE p.branch_id = ?
       AND c.branch_id = ?
       AND p.is_archived = 0
       AND c.is_archived = 0
     ORDER BY p.full_name, c.created_at DESC"
);
$caseStmt->bind_param('ss', $branchId, $branchId);
$caseStmt->execute();
$cases = $caseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$caseStmt->close();

$visitStmt = $conn->prepare(
    "SELECT v.*,
            p.full_name,
            c.case_number,
            u.username AS nurse_name
     FROM patient_visits v
     INNER JOIN patients p ON p.patient_id = v.patient_id
     INNER JOIN animal_bite_cases c ON c.case_id = v.case_id
     LEFT JOIN users u ON u.user_id = v.assigned_nurse
     WHERE v.branch_id = ?
       AND v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     ORDER BY v.visit_date DESC, v.check_in_at DESC"
);
$visitStmt->bind_param('s', $branchId);
$visitStmt->execute();
$visits = $visitStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$visitStmt->close();

$statsStmt = $conn->prepare(
    "SELECT COUNT(*) AS today_visits,
            COALESCE(SUM(workflow_status = 'Waiting for Nurse'), 0) AS waiting_count,
            COALESCE(SUM(workflow_status = 'Under Assessment'), 0) AS assessment_count,
            COALESCE(SUM(workflow_status = 'For Registry'), 0) AS registry_count
     FROM patient_visits
     WHERE branch_id = ? AND visit_date = CURDATE()"
);
$statsStmt->bind_param('s', $branchId);
$statsStmt->execute();
$visitStats = $statsStmt->get_result()->fetch_assoc() ?: [];
$statsStmt->close();

$flash = workflowTakeFlash();

$statusClasses = [
    'Checked In' => 'status-info',
    'Waiting for Nurse' => 'status-warning',
    'Under Assessment' => 'status-primary',
    'Treatment Completed' => 'status-success',
    'For Registry' => 'status-info',
    'Registered' => 'status-success',
    'Visit Completed' => 'status-success',
    'Cancelled' => 'status-danger'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visit Check-in - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
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
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
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
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .topbar h3 small {
            color: #6c757d;
            font-size: 16px;
            font-weight: 400;
            margin-left: 8px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-role {
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
            margin-left: 4px;
        }

        .page-content {
            padding: 30px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            position: relative;
            padding: 20px 22px;
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
        .stat-card.info { border-left-color: var(--info); }
        .stat-card.success { border-left-color: var(--success); }

        .stat-card .stat-icon {
            position: absolute;
            top: 18px;
            right: 20px;
            color: var(--primary);
            font-size: 28px;
            opacity: .16;
        }

        .stat-card.warning .stat-icon { color: #d89c00; }
        .stat-card.info .stat-icon { color: var(--info); }
        .stat-card.success .stat-icon { color: var(--success); }

        .stat-card h6 {
            color: #6c757d;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .4px;
            margin: 0 0 8px;
            text-transform: uppercase;
        }

        .stat-card h2 {
            color: var(--primary);
            font-size: 34px;
            font-weight: 700;
            line-height: 1;
            margin: 0;
        }

        .stat-card p {
            color: #8a93a2;
            font-size: 12px;
            margin: 7px 0 0;
        }

        .content-card {
            margin-bottom: 25px;
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
            gap: 15px;
            padding: 20px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h5 {
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .content-card-header p {
            color: #7d8798;
            font-size: 13px;
            margin: 4px 0 0;
        }

        .content-card-body {
            padding: 24px;
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

        .form-label {
            color: #344054;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .required::after {
            color: var(--danger);
            content: ' *';
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border: 1px solid #d7dce5;
            border-radius: 9px;
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(43, 58, 140, .12);
        }

        .btn-primary-custom {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 42px;
            padding: 9px 18px;
            color: #fff;
            background: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-primary-custom:hover {
            color: #fff;
            background: #1f2c70;
            border-color: #1f2c70;
        }

        .btn-secondary-custom {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 42px;
            padding: 9px 18px;
            color: var(--primary);
            background: #fff;
            border: 1px solid var(--primary);
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-secondary-custom:hover {
            color: #fff;
            background: var(--primary);
        }

        .table-responsive {
            border-radius: 0 0 20px 20px;
        }

        .visit-table {
            margin: 0;
        }

        .visit-table thead th {
            padding: 14px 16px;
            color: #fff;
            background: var(--primary);
            border: 0;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .visit-table tbody td {
            padding: 14px 16px;
            color: #374151;
            border-bottom: 1px solid #edf0f5;
            font-size: 14px;
            vertical-align: middle;
        }

        .visit-table tbody tr:hover {
            background: #f8f9ff;
        }

        .patient-name {
            color: #18233f;
            font-weight: 700;
        }

        .case-number {
            color: var(--primary);
            font-weight: 600;
        }

        .badge-status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-primary { color: #24327a; background: #e9ecff; }
        .status-info { color: #0a7080; background: #e1f6fa; }
        .status-warning { color: #8a6500; background: #fff3cd; }
        .status-success { color: #1f7a35; background: #e5f5e9; }
        .status-danger { color: #b02a37; background: #fde7e9; }

        .empty-state {
            padding: 46px 20px !important;
            color: #98a2b3 !important;
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 8px;
            font-size: 34px;
        }

        .alert {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        @media (max-width: 1199px) {
            .stats-container {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
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

            .stats-container {
                grid-template-columns: 1fr;
            }

            .content-card-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .content-card-body {
                padding: 18px;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" style="max-width:50px;height:auto;">
            </div>
            <div class="system-name">Smart Bite Care</div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a class="active" href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
                <li><a href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
                <li><a href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <h3>Visit Check-in <small><?php echo workflowH($branchName); ?></small></h3>
            <div class="profile">
                <i class="bi bi-person-circle"></i>
                <span><?php echo workflowH($username); ?></span>
                <span class="profile-role">| Administrative Staff</span>
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
                    <i class="bi bi-person-check-fill stat-icon"></i>
                    <h6>Today's Check-ins</h6>
                    <h2><?php echo number_format((int)($visitStats['today_visits'] ?? 0)); ?></h2>
                    <p>All visits recorded today</p>
                </div>
                <div class="stat-card warning">
                    <i class="bi bi-hourglass-split stat-icon"></i>
                    <h6>Waiting for Nurse</h6>
                    <h2><?php echo number_format((int)($visitStats['waiting_count'] ?? 0)); ?></h2>
                    <p>Patients awaiting assessment</p>
                </div>
                <div class="stat-card info">
                    <i class="bi bi-clipboard2-pulse-fill stat-icon"></i>
                    <h6>Under Assessment</h6>
                    <h2><?php echo number_format((int)($visitStats['assessment_count'] ?? 0)); ?></h2>
                    <p>Currently handled by a nurse</p>
                </div>
                <div class="stat-card success">
                    <i class="bi bi-journal-check stat-icon"></i>
                    <h6>For Registry</h6>
                    <h2><?php echo number_format((int)($visitStats['registry_count'] ?? 0)); ?></h2>
                    <p>Ready for registry processing</p>
                </div>
            </div>

            <section class="content-card">
                <div class="content-card-header">
                    <div>
                        <h5><span class="section-icon"><i class="bi bi-person-plus-fill"></i></span>Check In a Patient</h5>
                        <p>Select an existing patient case and send the visit to the Nurse queue.</p>
                    </div>
                </div>

                <div class="content-card-body">
                    <form method="post" class="row g-3" id="checkInForm">
                        <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                        <input type="hidden" name="action" value="check_in">

                        <div class="col-lg-5">
                            <label class="form-label required" for="caseSelection">Patient and Case</label>
                            <select class="form-select" name="case_selection" id="caseSelection" required>
                                <option value="">Select a patient and case...</option>
                                <?php foreach ($cases as $case): ?>
                                    <option
                                        value="<?php echo (int)$case['case_id']; ?>"
                                        data-patient="<?php echo (int)$case['patient_id']; ?>"
                                    >
                                        <?php
                                        echo workflowH(
                                            $case['full_name']
                                            . ' - ' . $case['case_number']
                                            . ' (' . $case['case_status'] . ')'
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="patient_id" id="patientId">
                            <input type="hidden" name="case_id" id="caseId">
                        </div>

                        <div class="col-lg-3">
                            <label class="form-label required" for="visitType">Visit Type</label>
                            <select class="form-select" name="visit_type" id="visitType" required>
                                <option value="">Select visit type...</option>
                                <option value="New Patient">New Patient</option>
                                <option value="Follow-up">Follow-up</option>
                                <option value="Repeating Patient">Repeating Patient</option>
                            </select>
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label" for="notes">Intake Notes</label>
                            <input
                                class="form-control"
                                type="text"
                                name="notes"
                                id="notes"
                                maxlength="500"
                                placeholder="Documents received or handoff note"
                            >
                        </div>

                        <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-send-check-fill"></i> Check In and Send to Nurse
                            </button>
                            <a class="btn-secondary-custom" href="AdminStaff_PatientRecord.php">
                                <i class="bi bi-person-plus"></i> Create Patient/Case First
                            </a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="content-card mb-0">
                <div class="content-card-header">
                    <div>
                        <h5><span class="section-icon"><i class="bi bi-clock-history"></i></span>Recent Visits</h5>
                        <p>Check-ins and workflow progress recorded during the last 30 days.</p>
                    </div>
                    <span class="badge rounded-pill text-bg-light border">
                        <?php echo number_format(count($visits)); ?> records
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table visit-table align-middle">
                        <thead>
                            <tr>
                                <th>Date and Time</th>
                                <th>Patient</th>
                                <th>Case</th>
                                <th>Visit Type</th>
                                <th>Status</th>
                                <th>Assigned Nurse</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$visits): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        No visit records yet.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($visits as $visit): ?>
                                <?php
                                $visitStatus = (string)$visit['workflow_status'];
                                $statusClass = $statusClasses[$visitStatus] ?? 'status-info';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo workflowH(date('M d, Y', strtotime($visit['visit_date']))); ?></strong>
                                        <div class="text-muted small">
                                            <?php echo workflowH(date('h:i A', strtotime($visit['check_in_at']))); ?>
                                        </div>
                                    </td>
                                    <td class="patient-name"><?php echo workflowH($visit['full_name']); ?></td>
                                    <td class="case-number"><?php echo workflowH($visit['case_number']); ?></td>
                                    <td><?php echo workflowH($visit['visit_type']); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo workflowH($statusClass); ?>">
                                            <?php echo workflowH($visitStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($visit['nurse_name'])): ?>
                                            <i class="bi bi-person-badge me-1 text-muted"></i>
                                            <?php echo workflowH($visit['nurse_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($visitStatus, ['Checked In', 'Waiting for Nurse'], true)): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this visit?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="visit_id" value="<?php echo (int)$visit['visit_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-x-circle me-1"></i>Cancel
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const caseSelection = document.getElementById('caseSelection');
        const patientIdInput = document.getElementById('patientId');
        const caseIdInput = document.getElementById('caseId');

        if (caseSelection && patientIdInput && caseIdInput) {
            caseSelection.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                patientIdInput.value = selectedOption.dataset.patient || '';
                caseIdInput.value = selectedOption.value || '';
            });
        }
    </script>
</body>
</html>
