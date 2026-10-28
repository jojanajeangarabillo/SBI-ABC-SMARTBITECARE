<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';
require_once 'sources/inventory_approval_helpers.php';
require_once 'sources/pagination_helper.php';

/*
 * PENDING APPROVALS (Branch Admin). Requests from the Inventory Officer and
 * the Nurse (stock out / pull-out, expired disposal, adjustment, restock,
 * return or transfer, item settings, item delete) are approved or rejected
 * here, and daily closings with a variance or wastage are reviewed.
 * Every decision: active Branch Admin of the same branch, CSRF token,
 * transaction with a row lock (no double approval), never the requester.
 */
$user = workflowRequireUser($conn, 2);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Branch Admin');
$csrf = workflowCsrfToken();

$tab = (string)($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'history', 'closings'], true)) $tab = 'pending';
$closingFilter = inventoryApprovalClosingFilter((string)($_GET['closings'] ?? 'needs'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    // Back to the same tab, filter and page after a decision.
    $back = 'BranchAdmin_Approvals.php?' . http_build_query(array_filter([
        'tab' => (string)($_POST['tab'] ?? 'pending'),
        'closings' => in_array($_POST['closings'] ?? '', INVENTORY_CLOSING_FILTERS, true) && $_POST['closings'] !== 'needs' ? (string)$_POST['closings'] : '',
        'page' => (int)($_POST['page'] ?? 0) > 1 ? (int)$_POST['page'] : '',
        'per_page' => in_array((int)($_POST['per_page'] ?? 0), PAGINATION_SIZES, true) ? (int)$_POST['per_page'] : '',
    ], static fn($v) => $v !== ''));
    try {
        workflowVerifyCsrf();
        if ($action === 'decide') {
            $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
            if (!$requestId) throw new RuntimeException('Select a request.');
            $decision = (string)($_POST['decision'] ?? '');
            $approvedQuantity = isset($_POST['approved_quantity']) ? trim((string)$_POST['approved_quantity']) : null;
            $message = inventoryApprovalDecide($conn, (int)$requestId, $userId, $decision,
                (string)($_POST['decision_remarks'] ?? ''), $approvedQuantity !== '' ? $approvedQuantity : null);
            workflowFlash('success', $message);
        } elseif ($action === 'review_closing') {
            $closingId = filter_input(INPUT_POST, 'closing_id', FILTER_VALIDATE_INT);
            if (!$closingId) throw new RuntimeException('Select a closing.');
            workflowFlash('success', inventoryApprovalReviewClosing($conn, (int)$closingId, $userId,
                (string)($_POST['decision'] ?? ''), (string)($_POST['review_remarks'] ?? '')));
        } elseif ($action === 'bulk_review_closings') {
            // Mark all / Mark selected as Reviewed: one transaction, one audit entry per closing.
            $ids = array_map('intval', (array)($_POST['closing_ids'] ?? []));
            $result = inventoryApprovalReviewClosingsBulk($conn, $ids, $userId, (string)($_POST['bulk_remark'] ?? ''));
            $reasonText = $result['reasons']
                ? ' (' . implode(', ', array_map(static fn($k, $v) => $v . ' ' . $k, array_keys($result['reasons']), $result['reasons'])) . ')'
                : '';
            workflowFlash($result['reviewed'] > 0 ? 'success' : 'danger',
                $result['reviewed'] . ' reviewed, ' . $result['skipped'] . ' skipped' . $reasonText . '.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        workflowFlash('danger', $e->getMessage());
    }
    header('Location: ' . $back);
    exit;
}

// Decision History export (PDF or CSV): every decided request of the branch,
// optionally limited to the requested dates, with the Approval report's columns.
if ($tab === 'history' && in_array($_GET['export'] ?? '', ['pdf', 'csv'], true) && inventoryApprovalReady($conn)) {
    require_once 'sources/report_center.php';
    $exportFrom = reportCenterDate($_GET['from'] ?? '');
    $exportTo = reportCenterDate($_GET['to'] ?? '');
    $exportReport = reportApprovalControl($conn, $branchId, $exportFrom ?? '2000-01-01', $exportTo ?? date('Y-m-d'),
        ['Approved', 'Rejected', 'Cancelled', 'Fulfilled']);
    $exportReport['title'] = 'Approval Decision History';
    $exportReport['orientation'] = 'L';
    $exportPeriod = ($exportFrom || $exportTo) ? reportPeriodText($exportFrom ?? $exportTo, $exportTo ?? $exportFrom) : 'All dates';
    workflowAudit($conn, $userId, $branchId, 'Exported approval decision history (' . strtoupper((string)$_GET['export']) . ') - ' . $exportPeriod, 'Approvals');
    reportSend($exportReport, reportMeta($conn, $userId, $branchName, $exportPeriod, 'Status: Approved, Rejected, Cancelled, Fulfilled'),
        (string)$_GET['export'], 'Approval_Decision_History_' . $branchId . '_' . date('Ymd_His'));
}

$flash = workflowTakeFlash();
$notification_count = getUnreadNotificationCount($conn, $userId);
$ready = inventoryApprovalReady($conn);
// Server-side pagination (sources/pagination_helper.php): badges and group
// counts count every matching row; only the table shows one page.
$pendingFilter = ['branch_id' => $branchId, 'statuses' => ['Pending']];
$historyFilter = ['branch_id' => $branchId, 'statuses' => ['Approved', 'Rejected', 'Cancelled', 'Fulfilled']];
$pendingTotal = $ready ? inventoryApprovalCount($conn, $pendingFilter) : 0;
$pendingByType = $ready ? inventoryApprovalCountByType($conn, $pendingFilter) : [];
$pg = paginationFromRequest('', $tab === 'pending' ? 10 : 25);
$pending = [];
$history = [];
$closingRows = [];
if ($ready && $tab === 'pending') {
    $pg = paginationApply($pg, $pendingTotal);
    $pending = inventoryApprovalList($conn, $pendingFilter + ['order' => 'type', 'limit' => $pg['per_page'], 'offset' => $pg['offset']]);
} elseif ($ready && $tab === 'history') {
    $pg = paginationApply($pg, inventoryApprovalCount($conn, $historyFilter));
    $history = inventoryApprovalList($conn, $historyFilter + ['limit' => $pg['per_page'], 'offset' => $pg['offset']]);
} elseif ($tab === 'closings') {
    $pg = paginationApply($pg, inventoryApprovalClosingCount($conn, $branchId, $closingFilter));
    $closingRows = inventoryApprovalClosingList($conn, $branchId, $closingFilter, 60, $pg['per_page'], $pg['offset']);
}
// Every Submitted closing in the filter, on all pages (Mark all / Mark selected).
$bulkCandidates = [];
if ($tab === 'closings') {
    foreach (inventoryApprovalClosingsSubmittedInFilter($conn, $branchId, $closingFilter) as $candidate) {
        $bulkCandidates[(int)$candidate['closing_id']] = [
            'id' => (int)$candidate['closing_id'],
            'date' => date('m/d/Y', strtotime((string)$candidate['inventory_date'])),
            'item' => (string)$candidate['item_name'],
            'variance' => (float)$candidate['variance'] != 0 ? inventoryQuantityText((float)$candidate['variance'], $candidate) : '0',
            'wastage' => (float)$candidate['wastage'] > 0 ? inventoryQuantityText((float)$candidate['wastage'], $candidate) : '—',
            'flagged' => (bool)(int)$candidate['flagged'],
            'own' => (int)$candidate['submitted_by'] === $userId,
        ];
    }
}
$bulkReviewable = array_filter($bulkCandidates, static fn($c) => !$c['own']);
$bulkFlagged = count(array_filter($bulkReviewable, static fn($c) => $c['flagged']));
$closingsToReview = inventoryApprovalClosingsToReview($conn, $branchId);

// Pending requests of this page grouped by type, in the order of INVENTORY_APPROVAL_TYPES.
$grouped = [];
foreach (array_keys(INVENTORY_APPROVAL_TYPES) as $type) $grouped[$type] = [];
foreach ($pending as $row) $grouped[$row['request_type']][] = $row;
$grouped = array_filter($grouped);

// Current usable stock of each item in a pending request (decision support).
$currentStock = [];
foreach ($pending as $row) {
    if ($row['item_id'] && !isset($currentStock[(int)$row['item_id']])) {
        try {
            $item = inventoryApprovalItem($conn, (int)$row['item_id']);
            $currentStock[(int)$row['item_id']] = inventoryQuantityText(
                (float)stockQuantityDecimal(inventoryOpsAvailableScaled($conn, (int)$row['item_id'], $branchId, false)), $item);
        } catch (Throwable $e) {
            $currentStock[(int)$row['item_id']] = '—';
        }
    }
}

/** Requested quantity of a restock in the item's counting unit (vials/ampules), for the approve form. */
function approvalsRestockDisplayQuantity(array $row): string
{
    $p = $row['payload_data'] ?? [];
    if (isset($p['display_quantity']) && $p['display_quantity'] !== '') {
        return rtrim(rtrim(number_format((float)$p['display_quantity'], 4, '.', ''), '0'), '.');
    }
    return (string)inventoryReorderUnits((float)$row['quantity'], $row);
}

function approvalsDate(?string $value): string
{
    return $value ? date('m/d/Y h:i A', strtotime($value)) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending Approvals - SmartBiteCare</title>
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

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f9faff;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
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

        /* Notification sidebar badge */
.notification-link {
    display: flex !important;
    align-items: center;
    width: 100%;
}

.notification-badge {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    margin-left: auto;
    border-radius: 999px;
    background: #F21D2F;
    color: #fff !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    line-height: 1;
    white-space: nowrap;
    flex: 0 0 auto;
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

        .page-content { padding: 35px; }

        .tab-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
        .tab-link {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
            border-radius: 999px; background: #fff; color: var(--primary); font-weight: 600;
            text-decoration: none; box-shadow: 0 2px 6px rgba(0,0,0,.06); border: 1px solid #e3e7f1;
        }
        .tab-link.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .tab-link .badge { font-size: 11px; }
        .content-card { overflow: hidden; margin-bottom: 25px; background: #fff; border-radius: 18px; box-shadow: 0 3px 8px rgba(0,0,0,.08); }
        .content-card-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 18px 24px; border-bottom: 1px solid #edf0f5; flex-wrap: wrap; }
        .content-card-header h5 { margin: 0; color: var(--primary); font-size: 18px; font-weight: 700; display: flex; gap: 10px; align-items: center; }
        .content-card-header p { margin: 4px 0 0; color: #8b95a7; font-size: 13px; }
        .approval-table { margin: 0; }
        .approval-table th { background: #f6f8fc; color: #5d6782; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
        .approval-table td { vertical-align: top; font-size: 14px; }
        .approval-table .muted { color: #8b95a7; font-size: 12px; }
        .decision-form textarea { font-size: 13px; min-width: 220px; }
        .decision-form .btn { font-size: 13px; font-weight: 600; }
        .empty-state { padding: 42px 20px; color: #98a2b3; text-align: center; }
        .empty-state i { display: block; margin-bottom: 8px; font-size: 32px; }
        .info-note { background: #eef2ff; border: 1px solid #d5dcf7; color: #2B3A8C; border-radius: 12px; padding: 12px 16px; font-size: 13px; margin-bottom: 20px; }
        @media (max-width: 991px) { .main { margin-left: 90px; } }
        @media (max-width: 767px) {
            .topbar { height: 70px; padding: 0 16px; }
            .topbar h3 { font-size: 20px; }
            .topbar h3 small, .profile-role { display: none; }
            .page-content { padding: 20px 16px; }
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
                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <?php echo inventoryApprovalSidebarLink($conn, $userId, true); ?>
                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
                <li><a href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
                <li><a href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>
</div>

    <div class="main">
        <div class="topbar">
            <h3>Pending Approvals <small><?php echo workflowH($branchName); ?></small></h3>
           <div class="dropdown">
                <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                        type="button" id="branchAdminProfileMenu"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="profile-role">| Branch Admin</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                    aria-labelledby="branchAdminProfileMenu">
                    <li><h6 class="dropdown-header">Account options</h6></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                            <i class="bi bi-key-fill me-2"></i>Change Password
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                         <a class="dropdown-item rounded-2 py-2 text-danger"
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#logoutConfirmModal">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="page-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                    <?php echo workflowH((string)$flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (!$ready): ?>
                <div class="alert alert-warning">Inventory approvals need the database update. Run <code>php sources/migrate_inventory_approvals.php --apply</code>.</div>
            <?php endif; ?>

            <div class="tab-row" role="tablist">
                <a class="tab-link <?php echo $tab === 'pending' ? 'active' : ''; ?>" href="BranchAdmin_Approvals.php?tab=pending">
                    <i class="bi bi-hourglass-split"></i> Pending Requests
                    <?php if ($pendingTotal): ?><span class="badge rounded-pill text-bg-danger"><?php echo (int)$pendingTotal; ?></span><?php endif; ?>
                </a>
                <a class="tab-link <?php echo $tab === 'closings' ? 'active' : ''; ?>" href="BranchAdmin_Approvals.php?tab=closings">
                    <i class="bi bi-clipboard-check"></i> Closing Review
                    <?php if ($closingsToReview): ?><span class="badge rounded-pill text-bg-danger"><?php echo $closingsToReview; ?></span><?php endif; ?>
                </a>
                <a class="tab-link <?php echo $tab === 'history' ? 'active' : ''; ?>" href="BranchAdmin_Approvals.php?tab=history">
                    <i class="bi bi-clock-history"></i> Decision History
                </a>
            </div>

            <?php if ($tab === 'pending'): ?>
                <div class="info-note"><i class="bi bi-shield-check me-1"></i>
                    A pending request has not changed anything yet. Approving it applies the change once, through the normal stock functions; rejecting it changes nothing and needs a remark. You cannot decide your own requests.
                </div>
                <?php if (!$grouped): ?>
                    <div class="content-card"><div class="empty-state"><i class="bi bi-check2-circle"></i>No pending requests. Everything is decided.</div></div>
                <?php endif; ?>
                <?php foreach ($grouped as $type => $rows): ?>
                    <section class="content-card">
                        <div class="content-card-header">
                            <div><h5><?php echo workflowH(inventoryApprovalTypeLabel($type)); ?> <span class="badge rounded-pill text-bg-warning" title="Pending requests of this type (all pages)"><?php echo (int)($pendingByType[$type] ?? count($rows)); ?></span></h5>
                                <?php if (($pendingByType[$type] ?? 0) > count($rows)): ?><p class="muted mb-0"><?php echo count($rows); ?> on this page</p><?php endif; ?></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table approval-table">
                                <thead><tr><th>Request</th><th>Details</th><th>Quantity</th><th>Reason / Remarks</th><th>Requested By</th><th>Decision</th></tr></thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $own = (int)$row['requested_by'] === $userId; ?>
                                    <tr>
                                        <td><strong>#<?php echo (int)$row['request_id']; ?></strong><div class="muted"><?php echo workflowH(approvalsDate($row['requested_at'])); ?></div></td>
                                        <td><?php echo workflowH(ucfirst($row['summary'])); ?>
                                            <?php if ($row['item_id'] && isset($currentStock[(int)$row['item_id']])): ?>
                                                <div class="muted">Usable stock now: <?php echo workflowH($currentStock[(int)$row['item_id']]); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php
                                            if ($type === 'stock_adjustment') {
                                                $p = $row['payload_data'];
                                                $delta = (float)($p['counted'] ?? 0) - (float)($p['system_at_count'] ?? 0);
                                                echo workflowH(($delta > 0 ? '+' : '-') . inventoryQuantityText(abs($delta), $row));
                                            } elseif ($type === 'return_transfer') {
                                                echo workflowH(trim(($row['payload_data']['quantity'] ?? '') . ' ' . ($row['payload_data']['unit_name'] ?? '')));
                                            } else {
                                                echo workflowH(inventoryApprovalQuantityText($row['quantity'], $row) ?: '—');
                                            }
                                        ?></td>
                                        <td><?php echo workflowH((string)($row['reason'] ?? '')); ?>
                                            <?php if (trim((string)($row['remarks'] ?? '')) !== ''): ?><div class="muted"><?php echo workflowH((string)$row['remarks']); ?></div><?php endif; ?>
                                        </td>
                                        <td><?php echo workflowH((string)($row['requester_name'] ?? 'Unknown')); ?><div class="muted"><?php echo workflowH((string)($row['requester_role'] ?? '')); ?></div></td>
                                        <td style="min-width:260px;">
                                            <?php if ($own): ?>
                                                <span class="muted">Your own request. Another Branch Admin of this branch must decide it.</span>
                                            <?php else: ?>
                                                <form method="POST" action="BranchAdmin_Approvals.php" class="decision-form" data-request="<?php echo (int)$row['request_id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                                    <input type="hidden" name="action" value="decide">
                                                    <input type="hidden" name="tab" value="pending">
                                                    <input type="hidden" name="page" value="<?php echo (int)$pg['page']; ?>">
                                                    <input type="hidden" name="per_page" value="<?php echo (int)$pg['per_page']; ?>">
                                                    <input type="hidden" name="request_id" value="<?php echo (int)$row['request_id']; ?>">
                                                    <?php if ($type === 'restock'): ?>
                                                        <label class="muted mb-1" for="aq<?php echo (int)$row['request_id']; ?>">Approved quantity (<?php echo workflowH(inventoryUnitWord(inventoryDisplayUnitLabel($row), 2)); ?>)</label>
                                                        <input id="aq<?php echo (int)$row['request_id']; ?>" type="number" name="approved_quantity" class="form-control form-control-sm mb-2" min="1" step="1" value="<?php echo workflowH(approvalsRestockDisplayQuantity($row)); ?>">
                                                    <?php endif; ?>
                                                    <textarea name="decision_remarks" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Remark (required to reject)"></textarea>
                                                    <div class="d-flex gap-2">
                                                        <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Approve</button>
                                                        <button type="submit" name="decision" value="reject" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> Reject</button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
                <?php if ($pendingTotal > 0): ?>
                    <div class="content-card"><?php echo paginationRender($pg, '', 'pending requests'); ?></div>
                <?php endif; ?>

            <?php elseif ($tab === 'closings'): ?>
                <div class="info-note"><i class="bi bi-info-circle me-1"></i>
                    Closings with a variance or wastage are listed first. <strong>Reviewed</strong> accepts the closing (optional remark). <strong>Return for recount</strong> needs a remark and notifies the Nurse, who must count the item again and save the closing; its forecasting training row is kept until then and the re-save updates that same row.
                </div>
                <section class="content-card">
                    <div class="content-card-header">
                        <div><h5>Daily Closing Review</h5><p><?php echo $closingFilter === 'all' ? 'All closings of the last 60 days.' : ($closingFilter === 'submitted' ? 'Every submitted closing that is not reviewed yet.' : 'Closings that need your review, and closings returned for a recount.'); ?></p></div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Closing filter">
                            <a class="btn <?php echo $closingFilter === 'needs' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="BranchAdmin_Approvals.php?tab=closings">Needs review</a>
                            <a class="btn <?php echo $closingFilter === 'submitted' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="BranchAdmin_Approvals.php?tab=closings&amp;closings=submitted">All submitted</a>
                            <a class="btn <?php echo $closingFilter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="BranchAdmin_Approvals.php?tab=closings&amp;closings=all">Show all (60 days)</a>
                        </div>
                    </div>
                    <?php if ($bulkCandidates): ?>
                        <div class="bulk-bar d-flex flex-wrap align-items-center gap-2 px-4 py-3 border-bottom" style="background:#f8f9fc">
                            <div class="me-auto small text-muted">
                                <strong class="text-dark"><?php echo count($bulkReviewable); ?></strong> submitted closing<?php echo count($bulkReviewable) === 1 ? '' : 's'; ?> to review in this filter (all pages)<?php if ($bulkFlagged): ?>, <strong class="text-danger"><?php echo $bulkFlagged; ?></strong> with a variance or wastage<?php endif; ?>.
                                <?php if (count($bulkCandidates) > count($bulkReviewable)): ?><br><?php echo count($bulkCandidates) - count($bulkReviewable); ?> submitted by you cannot be reviewed by you.<?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="markSelectedBtn" disabled><i class="bi bi-check2-square me-1"></i>Mark selected as Reviewed <span id="selectedCount"></span></button>
                            <button type="button" class="btn btn-sm btn-success" id="markAllBtn"<?php echo $bulkReviewable ? '' : ' disabled'; ?>><i class="bi bi-check2-all me-1"></i>Mark all as Reviewed (<?php echo count($bulkReviewable); ?>)</button>
                            <form method="POST" action="BranchAdmin_Approvals.php" id="bulkReviewForm" class="d-none">
                                <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                <input type="hidden" name="action" value="bulk_review_closings">
                                <input type="hidden" name="tab" value="closings">
                                <input type="hidden" name="closings" value="<?php echo workflowH($closingFilter); ?>">
                                <input type="hidden" name="page" value="<?php echo (int)$pg['page']; ?>">
                                <input type="hidden" name="per_page" value="<?php echo (int)$pg['per_page']; ?>">
                                <input type="hidden" name="bulk_remark" value="">
                            </form>
                        </div>
                        <script type="application/json" id="bulkClosingData"><?php echo json_encode(array_values($bulkCandidates), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table approval-table">
                            <thead><tr><th style="width:34px"><input type="checkbox" class="form-check-input" id="selectAllPage" title="Select all on this page" aria-label="Select all on this page"<?php echo array_filter($closingRows, static fn($c) => isset($bulkReviewable[(int)$c['closing_id']])) ? '' : ' disabled'; ?>></th><th>Date</th><th>Item</th><th>Beginning</th><th>Delivery</th><th>Consumed</th><th>Pull-out</th><th>Computed</th><th>Actual</th><th>Variance</th><th>Wastage</th><th>Nurse</th><th>Status</th><th>Review</th></tr></thead>
                            <tbody>
                            <?php if (!$closingRows): ?>
                                <tr><td colspan="14"><div class="empty-state"><i class="bi bi-clipboard-check"></i>No closings to review.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($closingRows as $c): ?>
                                <?php $q = static fn($v): string => inventoryQuantityText((float)$v, $c); ?>
                                <tr class="<?php echo (int)$c['flagged'] && $c['status'] === 'Submitted' ? 'table-warning' : ''; ?>">
                                    <td><?php if (isset($bulkReviewable[(int)$c['closing_id']])): ?><input type="checkbox" class="form-check-input closing-select" value="<?php echo (int)$c['closing_id']; ?>" aria-label="Select this closing"><?php elseif (isset($bulkCandidates[(int)$c['closing_id']])): ?><input type="checkbox" class="form-check-input" disabled title="You submitted this closing; another Branch Admin must review it."><?php endif; ?></td>
                                    <td><?php echo workflowH(date('m/d/Y', strtotime($c['inventory_date']))); ?></td>
                                    <td><?php echo workflowH($c['item_name']); ?>
                                        <?php if (trim((string)($c['remarks'] ?? '')) !== ''): ?><div class="muted"><?php echo workflowH((string)$c['remarks']); ?></div><?php endif; ?></td>
                                    <td><?php echo workflowH($q($c['beginning_stock'])); ?></td>
                                    <td><?php echo workflowH($q($c['delivery'])); ?></td>
                                    <td><?php echo workflowH($q($c['consumed'])); ?></td>
                                    <td><?php echo workflowH($q($c['pull_out'])); ?></td>
                                    <td><?php echo workflowH($q($c['computed_ending'])); ?></td>
                                    <td><?php echo workflowH($q($c['actual_count'])); ?></td>
                                    <td><?php echo (float)$c['variance'] != 0 ? '<span class="text-danger fw-semibold">' . workflowH($q($c['variance'])) . '</span>' : '0'; ?></td>
                                    <td><?php echo (float)$c['wastage'] > 0 ? '<span class="text-danger">' . workflowH($q($c['wastage'])) . '</span>' : '—'; ?></td>
                                    <td><?php echo workflowH((string)($c['submitter_name'] ?? '')); ?><div class="muted"><?php echo workflowH(approvalsDate($c['submitted_at'])); ?></div></td>
                                    <td>
                                        <?php $statusClass = ['Submitted' => 'text-bg-warning', 'Reviewed' => 'text-bg-success', 'Returned' => 'text-bg-danger', 'Draft' => 'text-bg-secondary'][$c['status']] ?? 'text-bg-secondary'; ?>
                                        <span class="badge <?php echo $statusClass; ?>"><?php echo workflowH($c['status'] === 'Returned' ? 'Returned for recount' : ($c['status'] === 'Draft' ? 'Needs resubmission' : $c['status'])); ?></span>
                                        <?php if ($c['reviewed_at']): ?><div class="muted"><?php echo workflowH(($c['reviewer_name'] ?? '') . ', ' . approvalsDate($c['reviewed_at'])); ?></div><?php endif; ?>
                                        <?php if (trim((string)($c['review_remarks'] ?? '')) !== '' && in_array($c['status'], ['Reviewed', 'Returned'], true)): ?><div class="muted"><?php echo workflowH((string)$c['review_remarks']); ?></div><?php endif; ?>
                                    </td>
                                    <td style="min-width:230px;">
                                        <?php if ($c['status'] === 'Submitted'): ?>
                                            <form method="POST" action="BranchAdmin_Approvals.php" class="decision-form closing-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                                <input type="hidden" name="action" value="review_closing">
                                                <input type="hidden" name="tab" value="closings">
                                                <input type="hidden" name="closings" value="<?php echo workflowH($closingFilter); ?>">
                                                <input type="hidden" name="page" value="<?php echo (int)$pg['page']; ?>">
                                                <input type="hidden" name="per_page" value="<?php echo (int)$pg['per_page']; ?>">
                                                <input type="hidden" name="closing_id" value="<?php echo (int)$c['closing_id']; ?>">
                                                <textarea name="review_remarks" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Remark (required to return)"></textarea>
                                                <div class="d-flex gap-2">
                                                    <button type="submit" name="decision" value="reviewed" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Reviewed</button>
                                                    <button type="submit" name="decision" value="return" class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Return for recount</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted"><?php echo $c['status'] === 'Returned' ? 'Waiting for the Nurse\'s recount.' : '—'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php echo paginationRender($pg, '', 'closings'); ?>
                </section>

            <?php else: ?>
                <section class="content-card">
                    <div class="content-card-header"><div><h5>Decision History</h5><p>Approved, rejected, cancelled and fulfilled requests of the branch (latest 300 on screen; the exports have every request).</p></div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-sm btn-outline-primary" href="BranchAdmin_Approvals.php?tab=history&amp;export=pdf"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>
                            <a class="btn btn-sm btn-outline-success" href="BranchAdmin_Approvals.php?tab=history&amp;export=csv"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
                        </div></div>
                    <div class="table-responsive">
                        <table class="table approval-table">
                            <thead><tr><th>Request</th><th>Type</th><th>Details</th><th>Reason / Remarks</th><th>Requested By</th><th>Status</th><th>Decided By</th><th>Result</th></tr></thead>
                            <tbody>
                            <?php if (!$history): ?>
                                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-clock-history"></i>No decided requests yet.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$row['request_id']; ?></strong><div class="muted"><?php echo workflowH(approvalsDate($row['requested_at'])); ?></div></td>
                                    <td><?php echo workflowH(inventoryApprovalTypeLabel($row['request_type'])); ?><?php if ($row['source']): ?><div class="muted"><?php echo workflowH($row['source']); ?></div><?php endif; ?></td>
                                    <td><?php echo workflowH(ucfirst($row['summary'])); ?></td>
                                    <td><?php echo workflowH((string)($row['reason'] ?? '')); ?><?php if (trim((string)($row['remarks'] ?? '')) !== ''): ?><div class="muted"><?php echo workflowH((string)$row['remarks']); ?></div><?php endif; ?></td>
                                    <td><?php echo workflowH((string)($row['requester_name'] ?? '')); ?><div class="muted"><?php echo workflowH((string)($row['requester_role'] ?? '')); ?></div></td>
                                    <td><span class="badge <?php echo workflowH(inventoryApprovalStatusClass($row['status'])); ?>"><?php echo workflowH($row['status']); ?></span>
                                        <?php if ($row['request_type'] === 'restock' && in_array($row['status'], ['Approved', 'Fulfilled'], true)): ?>
                                            <div class="muted">Received <?php echo workflowH(inventoryApprovalQuantityText($row['fulfilled_quantity'], $row)); ?> of <?php echo workflowH(inventoryApprovalQuantityText($row['approved_quantity'] ?? $row['quantity'], $row)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php if ($row['decided_at']): ?><?php echo workflowH((string)($row['decider_name'] ?? '')); ?><div class="muted"><?php echo workflowH(approvalsDate($row['decided_at'])); ?></div><?php if ($row['decision_remarks']): ?><div class="muted"><?php echo workflowH((string)$row['decision_remarks']); ?></div><?php endif; ?><?php elseif ($row['status'] === 'Cancelled'): ?><span class="muted">Cancelled by requester <?php echo workflowH(approvalsDate($row['cancelled_at'])); ?></span><?php else: ?>—<?php endif; ?></td>
                                    <td><?php
                                        $result = [];
                                        if ($row['result_transaction_id']) $result[] = 'Stock transaction #' . (int)$row['result_transaction_id'];
                                        if ($row['result_reference']) $result[] = (string)$row['result_reference'];
                                        echo $result ? workflowH(implode(', ', $result)) : '—';
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php echo paginationRender($pg, '', 'decided requests'); ?>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2 class="modal-title" id="logoutConfirmModalLabel">
                    Log out of Smart Bite Care?
                </h2>
            </div>

            <div class="modal-body">
                <p class="mb-0">
                    Make sure you have saved any unfinished work before leaving your account.
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
                    class="btn btn-danger d-flex align-items-center justify-content-center"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>
            </div>

        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/sources/confirm_modal.php'; echo sbcConfirmModalScript(); ?>
    <script src="sources/sidebar.js"></script>
    <script>
        // Reject / Return need a remark; every decision asks for confirmation in the
        // styled dialog (sources/confirm_modal.js); one submit only (no double click).
        var decisionTexts = {
            approve: ['Approve request', 'Approve this request? The change is applied immediately.', 'primary', 'Approve'],
            reject: ['Reject request', 'Reject this request? Nothing will be changed.', 'danger', 'Reject'],
            'return': ['Return for recount', 'Return this closing to the Nurse for a recount?', 'danger', 'Return for recount'],
            reviewed: ['Mark as Reviewed', 'Mark this closing as Reviewed?', 'primary', 'Mark as Reviewed']
        };
        document.querySelectorAll('.decision-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (form.dataset.sent === '1') return;
                var button = event.submitter;
                var value = button ? button.value : '';
                var remark = form.querySelector('textarea');
                if ((value === 'reject' || value === 'return') && remark && remark.value.trim() === '') {
                    remark.classList.add('is-invalid');
                    remark.focus();
                    return;
                }
                var t = decisionTexts[value] || decisionTexts.reviewed;
                SbcDialog.confirm({ title: t[0], message: t[1], variant: t[2], confirmText: t[3] }).then(function (ok) {
                    if (!ok || form.dataset.sent === '1') return;
                    form.dataset.sent = '1';
                    if (button && button.name) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden'; hidden.name = button.name; hidden.value = button.value;
                        form.appendChild(hidden);
                    }
                    form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
                    form.submit();
                });
            });
        });

        // Bulk closing review: "Mark all as Reviewed" (every submitted closing in
        // the filter, all pages) and "Mark selected as Reviewed" (checked rows).
        (function () {
            var dataEl = document.getElementById('bulkClosingData');
            var form = document.getElementById('bulkReviewForm');
            if (!dataEl || !form) return;
            var all = JSON.parse(dataEl.textContent || '[]');
            var byId = {};
            all.forEach(function (c) { byId[c.id] = c; });
            var boxes = Array.prototype.slice.call(document.querySelectorAll('.closing-select'));
            var selectAll = document.getElementById('selectAllPage');
            var markSelected = document.getElementById('markSelectedBtn');
            var markAll = document.getElementById('markAllBtn');
            var countEl = document.getElementById('selectedCount');

            function selectedIds() {
                return boxes.filter(function (b) { return b.checked; }).map(function (b) { return parseInt(b.value, 10); });
            }
            function refresh() {
                var n = selectedIds().length;
                markSelected.disabled = n === 0;
                countEl.textContent = n ? '(' + n + ')' : '';
                if (selectAll) {
                    selectAll.checked = boxes.length > 0 && n === boxes.length;
                    selectAll.indeterminate = n > 0 && n < boxes.length;
                }
            }
            boxes.forEach(function (b) { b.addEventListener('change', refresh); });
            if (selectAll) selectAll.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = selectAll.checked; });
                refresh();
            });

            function review(ids, scope) {
                var rows = ids.map(function (id) { return byId[id]; }).filter(function (c) { return c && !c.own; });
                if (!rows.length || form.dataset.sent === '1') return;
                var flagged = rows.filter(function (c) { return c.flagged; });
                var message = 'Mark ' + rows.length + ' closing' + (rows.length === 1 ? '' : 's') + ' as Reviewed?'
                    + (scope === 'all' ? ' This includes the closings on every page of this filter.' : '')
                    + (flagged.length ? ' ' + flagged.length + ' of them ' + (flagged.length === 1 ? 'has' : 'have') + ' a variance or wastage, listed below; a shared remark is required.' : ' None of them has a variance or wastage.');
                SbcDialog.confirmRemark({
                    title: scope === 'all' ? 'Mark all as Reviewed' : 'Mark selected as Reviewed',
                    message: message,
                    confirmText: 'Mark ' + rows.length + ' as Reviewed',
                    variant: flagged.length ? 'warning' : 'primary',
                    details: flagged.length ? { columns: ['Date', 'Item', 'Variance', 'Wastage'], rows: flagged.map(function (c) { return [c.date, c.item, c.variance, c.wastage]; }) } : null,
                    remark: flagged.length
                        ? { required: true, minLength: 5, label: 'Shared remark (required)', placeholder: 'Variances checked with the Nurse.', requiredMessage: 'Enter a short remark (at least 5 characters) for the closings with a variance or wastage.' }
                        : { required: false, label: 'Shared remark (optional)', placeholder: 'Optional remark saved with each closing' }
                }).then(function (remark) {
                    if (remark === null || form.dataset.sent === '1') return;
                    form.dataset.sent = '1';
                    form.querySelectorAll('input[name="closing_ids[]"]').forEach(function (i) { i.remove(); });
                    rows.forEach(function (c) {
                        var input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'closing_ids[]'; input.value = c.id;
                        form.appendChild(input);
                    });
                    form.querySelector('input[name="bulk_remark"]').value = remark;
                    markAll.disabled = true; markSelected.disabled = true;
                    form.submit();
                });
            }
            markAll.addEventListener('click', function () { review(all.filter(function (c) { return !c.own; }).map(function (c) { return c.id; }), 'all'); });
            markSelected.addEventListener('click', function () { review(selectedIds(), 'selected'); });
            refresh();
        })();
    </script>
</body>
</html>
