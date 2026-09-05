<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 2);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Branch Admin');
$csrf = workflowCsrfToken();
$mainStatuses = ['Returned for Correction','Submitted to PhilHealth','Denied','Reimbursed','Completed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $recordId = (int)($_POST['record_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($recordId < 1 || !in_array($newStatus, $mainStatuses, true)) {
            throw new RuntimeException('Choose a valid main-branch processing status.');
        }

        $find = $conn->prepare(
            "SELECT ph.status, p.full_name, c.case_number, c.branch_id
             FROM philhealth_records ph
             INNER JOIN animal_bite_cases c ON c.case_id=ph.case_id
             INNER JOIN patients p ON p.patient_id=c.patient_id
             WHERE ph.philhealth_record_id=? AND ph.has_philhealth='Yes'
               AND ph.is_archived=0 LIMIT 1"
        );
        $find->bind_param('i', $recordId);
        $find->execute();
        $record = $find->get_result()->fetch_assoc();
        $find->close();
        if (!$record) {
            throw new RuntimeException('PhilHealth record was not found.');
        }

        $oldStatus = (string)($record['status'] ?? '');
        if (!in_array($oldStatus, [
            'Ready for Main Branch','Sent to Main Branch','Returned for Correction',
            'Submitted to PhilHealth','Denied','Reimbursed','Completed'
        ], true)) {
            throw new RuntimeException('The originating branch has not sent this record for main-branch processing.');
        }

        $submitted = $newStatus === 'Submitted to PhilHealth' ? date('Y-m-d') : null;
        $returned = $newStatus === 'Returned for Correction' ? date('Y-m-d') : null;
        $resolved = in_array($newStatus, ['Denied','Reimbursed','Completed'], true) ? date('Y-m-d') : null;

        $conn->begin_transaction();
        $update = $conn->prepare(
            "UPDATE philhealth_records
             SET status=?, remarks=?,
                 date_submitted_philhealth=COALESCE(?,date_submitted_philhealth),
                 date_returned=COALESCE(?,date_returned),
                 date_resolved=COALESCE(?,date_resolved),
                 updated_by=?, updated_at=NOW()
             WHERE philhealth_record_id=?"
        );
        $update->bind_param('sssssii', $newStatus, $remarks, $submitted, $returned, $resolved, $userId, $recordId);
        $update->execute();
        $update->close();

        $history = $conn->prepare(
            'INSERT INTO philhealth_status_history
             (philhealth_record_id,old_status,new_status,remarks,changed_by)
             VALUES (?,?,?,?,?)'
        );
        $history->bind_param('isssi', $recordId, $oldStatus, $newStatus, $remarks, $userId);
        $history->execute();
        $history->close();

        $originBranch = (string)$record['branch_id'];
        workflowNotifyRole(
            $conn,
            $originBranch,
            4,
            'PhilHealth Status Updated',
            $record['full_name'] . ' (Case ' . $record['case_number'] . ') is now ' . $newStatus . '.',
            'philhealth'
        );
        workflowAudit($conn, $userId, $branchId, 'Main-branch PhilHealth update: record ' . $recordId . ' to ' . $newStatus, 'PhilHealth');
        $conn->commit();
        workflowFlash('success', 'PhilHealth status updated and the originating branch was notified.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        workflowFlash('danger', $e->getMessage());
    }
    header('Location: BranchAdmin_PhilhealthWorkflow.php');
    exit;
}

$records = $conn->query(
    "SELECT ph.*, p.full_name, c.case_number, c.branch_id, b.branch_name
     FROM philhealth_records ph
     INNER JOIN animal_bite_cases c ON c.case_id=ph.case_id
     INNER JOIN patients p ON p.patient_id=c.patient_id
     INNER JOIN branches b ON b.branch_id=c.branch_id
     WHERE ph.has_philhealth='Yes' AND ph.is_archived=0
       AND ph.status IN ('Ready for Main Branch','Sent to Main Branch','Returned for Correction',
                         'Submitted to PhilHealth','Denied','Reimbursed','Completed')
     ORDER BY FIELD(ph.status,'Sent to Main Branch','Ready for Main Branch','Returned for Correction',
                              'Submitted to PhilHealth','Denied','Reimbursed','Completed'),
              ph.updated_at DESC"
)->fetch_all(MYSQLI_ASSOC);

$awaitingCount = 0;
$submittedCount = 0;
$returnedCount = 0;
$resolvedCount = 0;

foreach ($records as $record) {
    $status = (string)($record['status'] ?? '');
    if (in_array($status, ['Ready for Main Branch', 'Sent to Main Branch'], true)) {
        $awaitingCount++;
    } elseif ($status === 'Submitted to PhilHealth') {
        $submittedCount++;
    } elseif ($status === 'Returned for Correction') {
        $returnedCount++;
    } elseif (in_array($status, ['Denied', 'Reimbursed', 'Completed'], true)) {
        $resolvedCount++;
    }
}

function mainPhilhealthStatusClass(string $status): string
{
    $classes = [
        'Ready for Main Branch' => 'status-primary',
        'Sent to Main Branch' => 'status-info',
        'Returned for Correction' => 'status-danger',
        'Submitted to PhilHealth' => 'status-warning',
        'Denied' => 'status-danger',
        'Reimbursed' => 'status-success',
        'Completed' => 'status-success'
    ];

    return $classes[$status] ?? 'status-secondary';
}

$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Main-Branch PhilHealth Processing - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --success: #28a745;
            --warning: #ffb800;
            --danger: #dc3545;
            --info: #12a8c0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f9faff;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
            background: #f9faff;
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
            margin-left: 4px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .page-content {
            padding: 35px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            position: relative;
            display: flex;
            align-items: center;
            gap: 18px;
            min-height: 130px;
            overflow: hidden;
            padding: 22px 24px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, .06);
        }

        .stat-card::before {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 5px;
            background: var(--primary);
            content: '';
        }

        .stat-card.info::before { background: var(--info); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.danger::before { background: var(--danger); }
        .stat-card.success::before { background: var(--success); }

        .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            color: var(--primary);
            font-size: 30px;
            flex-shrink: 0;
        }

        .stat-card.info .stat-icon { color: var(--info); }
        .stat-card.warning .stat-icon { color: #d89b00; }
        .stat-card.danger .stat-icon { color: var(--danger); }
        .stat-card.success .stat-icon { color: var(--success); }

        .stat-label {
            margin-bottom: 3px;
            color: #71809d;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .25px;
            text-transform: uppercase;
        }

        .stat-number {
            color: #111827;
            font-size: 32px;
            font-weight: 700;
            line-height: 1.1;
        }

        .stat-description {
            margin-top: 5px;
            color: #8a94a6;
            font-size: 11px;
        }

        .scope-notice {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 25px;
            padding: 16px 18px;
            color: #34526f;
            background: #eaf6fb;
            border: 1px solid #cceaf4;
            border-radius: 12px;
        }

        .scope-notice > i {
            color: var(--info);
            font-size: 21px;
        }

        .scope-notice strong {
            display: block;
            margin-bottom: 3px;
            color: #234664;
        }

        .scope-notice p {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
        }

        .alert {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        .content-card {
            overflow: hidden;
            background: #fff;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, .08);
        }

        .content-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h5 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: var(--primary);
            font-size: 19px;
            font-weight: 700;
        }

        .content-card-header p {
            margin: 6px 0 0;
            color: #8b95a7;
            font-size: 13px;
        }

        .section-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
        }

        .philhealth-table {
            min-width: 1180px;
            margin: 0;
        }

        .philhealth-table thead th {
            padding: 14px 18px;
            color: #667085;
            background: #f8f9fc;
            border-bottom: 1px solid #e6eaf0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .25px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .philhealth-table tbody td {
            padding: 18px;
            color: #4c566a;
            border-color: #edf0f4;
            font-size: 13px;
            vertical-align: top;
        }

        .philhealth-table tbody tr:hover {
            background: #fbfcff;
        }

        .origin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            color: #33437f;
            background: #eef1ff;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .branch-id {
            margin-top: 5px;
            color: #98a2b3;
            font-size: 11px;
        }

        .patient-name {
            color: #25324b;
            font-size: 14px;
            font-weight: 700;
        }

        .case-number {
            display: inline-block;
            margin-top: 4px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-primary { color: #26377f; background: #e4e8fb; }
        .status-info { color: #0c6876; background: #d7f3f7; }
        .status-warning { color: #8a6300; background: #fff3cd; }
        .status-danger { color: #a22632; background: #fde2e5; }
        .status-success { color: #1f7a35; background: #ddf3e3; }
        .status-secondary { color: #5e6878; background: #edf0f4; }

        .status-remarks {
            max-width: 220px;
            margin-top: 8px;
            color: #7d8798;
            font-size: 12px;
            line-height: 1.4;
        }

        .detail-label {
            display: block;
            margin-bottom: 3px;
            color: #98a2b3;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .date-list {
            min-width: 165px;
        }

        .date-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 5px 0;
            border-bottom: 1px dashed #e4e7ec;
            font-size: 11px;
        }

        .date-row:last-child {
            border-bottom: 0;
        }

        .date-row span {
            color: #8b95a7;
        }

        .date-row strong {
            color: #4c566a;
            font-weight: 600;
            white-space: nowrap;
        }

        .workflow-form {
            min-width: 440px;
            padding: 12px;
            background: #f8f9fc;
            border: 1px solid #e8ebf2;
            border-radius: 10px;
        }

        .workflow-form .form-label {
            margin-bottom: 5px;
            color: #596579;
            font-size: 11px;
            font-weight: 700;
        }

        .workflow-form .form-select,
        .workflow-form .form-control {
            min-height: 38px;
            border-color: #dfe3eb;
            border-radius: 8px;
            font-size: 12px;
        }

        .workflow-form .form-select:focus,
        .workflow-form .form-control:focus {
            border-color: #8c98d1;
            box-shadow: 0 0 0 .2rem rgba(43, 58, 140, .1);
        }

        .btn-save {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 38px;
            padding: 8px 13px;
            color: #fff;
            background: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .btn-save:hover {
            color: #fff;
            background: #1d2863;
            border-color: #1d2863;
        }

        .empty-state {
            padding: 55px 20px !important;
            color: #98a2b3 !important;
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 8px;
            font-size: 38px;
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
                <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
            </div>
            <div class="system-name">Smart Bite Care</div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a class="active" href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
                <li><a href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
                <li><a href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <h3>PhilHealth Processing <small><?php echo workflowH($branchName); ?></small></h3>
            <div class="profile">
                <i class="bi bi-person-circle"></i>
                <span><?php echo workflowH($username); ?></span>
                <span class="profile-role">| Branch Admin</span>
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
                    <div class="stat-icon"><i class="bi bi-inboxes-fill"></i></div>
                    <div><div class="stat-label">Total Handoffs</div><div class="stat-number"><?php echo number_format(count($records)); ?></div><div class="stat-description">Records received from branches</div></div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div><div class="stat-label">Awaiting Action</div><div class="stat-number"><?php echo number_format($awaitingCount); ?></div><div class="stat-description">Ready or sent to main branch</div></div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="bi bi-send-check-fill"></i></div>
                    <div><div class="stat-label">Submitted Claims</div><div class="stat-number"><?php echo number_format($submittedCount); ?></div><div class="stat-description">Submitted to PhilHealth</div></div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="bi bi-arrow-counterclockwise"></i></div>
                    <div><div class="stat-label">Returned</div><div class="stat-number"><?php echo number_format($returnedCount); ?></div><div class="stat-description">Sent back for correction</div></div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div><div class="stat-label">Resolved Outcomes</div><div class="stat-number"><?php echo number_format($resolvedCount); ?></div><div class="stat-description">Denied, reimbursed, or completed</div></div>
                </div>
            </div>

            <div class="scope-notice">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    <strong>Main-branch processing scope</strong>
                    <p>Review branch handoffs, submit eligible claims to PhilHealth, record claim outcomes, and return incomplete records to their originating branch. Every update is saved in the status history and the originating Administrative Staff is notified.</p>
                </div>
            </div>

            <section class="content-card">
                <div class="content-card-header">
                    <div>
                        <h5><span class="section-icon"><i class="bi bi-file-earmark-medical-fill"></i></span>Main-Branch PhilHealth Queue</h5>
                        <p>Receive branch handoffs, submit claims, and record final processing outcomes.</p>
                    </div>
                    <span class="badge rounded-pill text-bg-light border"><?php echo number_format(count($records)); ?> records</span>
                </div>

                <div class="table-responsive">
                    <table class="table philhealth-table align-middle">
                        <thead>
                            <tr>
                                <th>Origin Branch</th>
                                <th>Patient and Case</th>
                                <th>Current Status</th>
                                <th>Processing Dates</th>
                                <th>Main-Branch Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$records): ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        No PhilHealth records have been handed to the main branch.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($records as $row): ?>
                                <?php
                                $currentStatus = (string)($row['status'] ?? 'Ready for Main Branch');
                                $currentIsMainStatus = in_array($currentStatus, $mainStatuses, true);
                                ?>
                                <tr>
                                    <td>
                                        <div class="origin-badge"><i class="bi bi-building"></i><?php echo workflowH($row['branch_name']); ?></div>
                                        <div class="branch-id"><?php echo workflowH($row['branch_id']); ?></div>
                                    </td>
                                    <td>
                                        <div class="patient-name"><?php echo workflowH($row['full_name']); ?></div>
                                        <div class="case-number"><?php echo workflowH($row['case_number']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo workflowH(mainPhilhealthStatusClass($currentStatus)); ?>"><?php echo workflowH($currentStatus); ?></span>
                                        <?php if (!empty($row['remarks'])): ?>
                                            <div class="status-remarks"><span class="detail-label">Latest Remarks</span><?php echo workflowH($row['remarks']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="date-list">
                                            <div class="date-row"><span>Sent</span><strong><?php echo !empty($row['date_sent_to_main']) ? workflowH(date('M d, Y', strtotime($row['date_sent_to_main']))) : '—'; ?></strong></div>
                                            <div class="date-row"><span>Submitted</span><strong><?php echo !empty($row['date_submitted_philhealth']) ? workflowH(date('M d, Y', strtotime($row['date_submitted_philhealth']))) : '—'; ?></strong></div>
                                            <div class="date-row"><span>Returned</span><strong><?php echo !empty($row['date_returned']) ? workflowH(date('M d, Y', strtotime($row['date_returned']))) : '—'; ?></strong></div>
                                            <div class="date-row"><span>Resolved</span><strong><?php echo !empty($row['date_resolved']) ? workflowH(date('M d, Y', strtotime($row['date_resolved']))) : '—'; ?></strong></div>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="post" class="workflow-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                            <input type="hidden" name="record_id" value="<?php echo (int)$row['philhealth_record_id']; ?>">
                                            <div class="row g-2">
                                                <div class="col-lg-5">
                                                    <label class="form-label" for="status-<?php echo (int)$row['philhealth_record_id']; ?>">New Processing Status</label>
                                                    <select class="form-select form-select-sm" id="status-<?php echo (int)$row['philhealth_record_id']; ?>" name="status" required>
                                                        <option value="" disabled <?php echo !$currentIsMainStatus ? 'selected' : ''; ?>>Select next status</option>
                                                        <?php foreach ($mainStatuses as $status): ?>
                                                            <option value="<?php echo workflowH($status); ?>" <?php echo $currentStatus === $status ? 'selected' : ''; ?>><?php echo workflowH($status); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-5">
                                                    <label class="form-label" for="remarks-<?php echo (int)$row['philhealth_record_id']; ?>">Processing Remarks</label>
                                                    <input class="form-control form-control-sm" id="remarks-<?php echo (int)$row['philhealth_record_id']; ?>" name="remarks" maxlength="500" value="<?php echo workflowH($row['remarks'] ?? ''); ?>" placeholder="Add processing remarks">
                                                </div>
                                                <div class="col-lg-2 d-flex align-items-end">
                                                    <button type="submit" class="btn-save w-100" onclick="return confirm('Save this main-branch PhilHealth update?');"><i class="bi bi-check2-circle"></i>Save</button>
                                                </div>
                                            </div>
                                        </form>
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
