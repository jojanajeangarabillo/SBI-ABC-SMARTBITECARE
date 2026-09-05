<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 4);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$csrf = workflowCsrfToken();

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
            c.animal_status,a.bite_category,a.treatment_profile,a.active_regimen,a.chart_signed_at,
            r.registry_number,r.erig,r.ats,r.tt,r.remarks
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
                <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
                <li><a class="active" href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
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
            <h3>Registry Queue <small><?php echo workflowH($branchName); ?></small></h3>
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

            <section class="content-card">
                <div class="content-card-header">
                    <div>
                        <h5><span class="section-icon"><i class="bi bi-journal-medical"></i></span>Registry Verification Queue</h5>
                        <p>Charts appear here after the Nurse completes and signs the clinical assessment.</p>
                    </div>
                    <span class="badge rounded-pill text-bg-light border">
                        <?php echo number_format(count($records)); ?> records
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table registry-table align-middle">
                        <thead>
                            <tr>
                                <th>Patient and Case</th>
                                <th>Assessment</th>
                                <th>Status</th>
                                <th>Registry Verification</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$records): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        No Nurse-signed charts are waiting for registry.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($records as $row): ?>
                                <tr>
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
                                        <div class="detail-label mt-2">Treatment Profile</div>
                                        <div><?php echo workflowH($row['treatment_profile']); ?></div>
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
                                    </td>
                                    <td>
                                        <?php if ($row['workflow_status'] === 'For Registry'): ?>
                                            <form method="post" class="registry-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                                <input type="hidden" name="visit_id" value="<?php echo (int)$row['visit_id']; ?>">

                                                <div class="row g-2">
                                                    <div class="col-md-5">
                                                        <label class="form-label">Registry Number</label>
                                                        <input
                                                            class="form-control form-control-sm"
                                                            name="registry_number"
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
                                                            value="<?php echo workflowH($row['active_regimen'] ?? ''); ?>"
                                                            placeholder="Active regimen"
                                                        >
                                                    </div>
                                                    <div class="col-md-5">
                                                        <label class="form-label">Registry Remarks</label>
                                                        <input
                                                            class="form-control form-control-sm"
                                                            name="remarks"
                                                            value="<?php echo workflowH($row['remarks'] ?? ''); ?>"
                                                            placeholder="Registry remarks"
                                                        >
                                                    </div>
                                                    <div class="col-md-2 d-flex align-items-end">
                                                        <button
                                                            type="submit"
                                                            class="btn-verify w-100"
                                                            onclick="return confirm('Verify and save this registry record?');"
                                                        >
                                                            <i class="bi bi-check2-circle"></i> Verify
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="registered-summary">
                                                <div class="detail-label">Registry Number</div>
                                                <strong><?php echo workflowH($row['registry_number'] ?? 'Registered'); ?></strong>
                                                <div class="small text-muted mt-2">
                                                    ERIG: <?php echo workflowH((string)($row['erig'] ?? 0)); ?> mL
                                                    &nbsp;|&nbsp;
                                                    ATS: <?php echo workflowH((string)($row['ats'] ?? 0)); ?> mL
                                                    &nbsp;|&nbsp;
                                                    TT: <?php echo !empty($row['tt']) ? 'Yes' : 'No'; ?>
                                                </div>
                                            </div>
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
</body>
</html>
