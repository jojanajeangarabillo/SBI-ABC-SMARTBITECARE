<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

// Check if user is logged in and is super admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}


$user_id = (int)$_SESSION['user_id'];
$notification_count = getUnreadNotificationCount($conn, $user_id);
// ============================================
// REPORTS: the shared report center (sources/report_center.php) with the
// shared builders (sources/report_standard.php). Branch: one or all.
// Each report gives a preview, a PDF and a Print of that PDF from the same data.
// ============================================
require_once 'sources/report_standard.php';
require_once 'sources/report_center.php';
$branchChoices = [];
$branchResult = $conn->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name");
while ($branchRow = $branchResult->fetch_assoc()) $branchChoices[(string)$branchRow['branch_id']] = (string)$branchRow['branch_name'];
$userBranchStmt = $conn->prepare('SELECT branch_id FROM users WHERE user_id = ?');
$userBranchStmt->bind_param('i', $user_id);
$userBranchStmt->execute();
$superAdminBranch = (string)($userBranchStmt->get_result()->fetch_assoc()['branch_id'] ?? '');
$userBranchStmt->close();
$reportEntries = [
    'user' => ['title' => 'User Report', 'icon' => 'bi-people', 'dates' => true, 'horizon' => false, 'all_branches' => true,
        'description' => 'Accounts created in the period with role, branch, status and last login.',
        'build' => static fn(mysqli $c, array $p): array => reportStdUsers($c, $p)],
    'branch_admin' => ['title' => 'Branch Admin Report', 'icon' => 'bi-person-badge', 'dates' => true, 'horizon' => false, 'all_branches' => true,
        'description' => 'Branch Admin accounts with branch, address, contact, status and last login.',
        'build' => static fn(mysqli $c, array $p): array => reportStdUsers($c, $p, true)],
    'branch_performance' => ['title' => 'Branch Performance Report', 'icon' => 'bi-bar-chart-line', 'dates' => true, 'horizon' => false, 'all_branches' => true,
        'description' => 'Cases, patients, vaccinations given and activity per branch in the period, with totals.',
        'build' => static fn(mysqli $c, array $p): array => reportStdBranchPerformance($c, $p)],
    'forecast_accuracy' => ['title' => 'Forecast Accuracy Report', 'icon' => 'bi-bullseye', 'dates' => false, 'horizon' => false, 'all_branches' => false,
        'description' => 'Scores of the branch\'s latest saved forecast evaluation (MAE, RMSE, SMAPE, review band). Choose a branch. Read-only.',
        'build' => static fn(mysqli $c, array $p): array => reportStdForecastAccuracy($c, $p)],
] + reportCenterCatalogEntries(1);
$reportCenter = reportCenterRun($conn, $reportEntries, [
    'user_id' => $user_id, 'role_id' => 1, 'branch_id' => $superAdminBranch,
    'branches' => $branchChoices, 'page' => 'SuperAdmin_Reports.php', 'module' => 'Reports',
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: white;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f9faff;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-bottom: 1px solid #e9edf5;
        }
        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
            letter-spacing: -0.3px;
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

/* Hide elements that are not part of the logout confirmation */
#logoutConfirmModal .confirmation-summary,
#logoutConfirmModal .confirmation-warning {
    display: none !important;
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
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .profile-button {
            border: 0;
            background: transparent;
            padding: 10px 12px;
            border-radius: 10px;
            transition: background-color 0.2s ease;
        }

        .profile-button::after {
            margin-left: 4px;
        }

        .profile-role {
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
            margin-left: 4px;
        }

        .profile-menu {
            min-width: 220px;
            padding: 8px;
            margin-top: 10px !important;
            border: 1px solid #e4e8f1;
            border-radius: 12px;
            box-shadow: 0 10px 28px rgba(32, 45, 110, 0.14);
        }

        .profile-menu .dropdown-header {
            padding: 8px 12px 10px;
            color: #6c757d;
            font-size: 12px;
        }

        .profile-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #24315f;
            font-weight: 500;
        }

        .profile-menu .dropdown-item:hover,
        .profile-menu .dropdown-item:focus {
            color: var(--primary);
            background: #f1f3fb;
        }

        .profile-menu .dropdown-item.text-danger:hover,
        .profile-menu .dropdown-item.text-danger:focus {
            color: #b42332 !important;
            background: #fff0f2;
        }

        .content {
            padding: 35px 35px 40px;
        }

        .report-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 28px 30px 30px;
            margin-bottom: 28px;
        }
        .report-card .form-select-custom {
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 500;
            color: #1f2a4a;
            background: white;
            width: 100%;
            max-width: 280px;
            outline: none;
            transition: 0.15s;
        }
        .report-card .form-select-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .report-card .date-input {
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 500;
            color: #1f2a4a;
            background: white;
            outline: none;
            transition: 0.15s;
            width: 160px;
        }
        .report-card .date-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        
        /* Equal sized buttons */
        .btn-generate {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            white-space: nowrap;
            min-width: 180px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-generate:hover {
            background: #1d2863;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43, 58, 140, 0.3);
        }
        .btn-generate:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-export-csv {
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            white-space: nowrap;
            min-width: 180px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-export-csv:hover {
            background: #117a8b;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
        }
        .btn-export-csv:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-group-export {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .report-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 20px 40px;
        }
        .report-row .field-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .report-row .field-group label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            letter-spacing: 0.2px;
        }
        .date-range-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .date-range-group span {
            font-weight: 500;
            color: #4a5a8c;
        }

        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 15px;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e7f2;
            letter-spacing: 0.3px;
        }
        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #edf1f8;
            color: #1f2a4a;
            font-weight: 500;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr:hover {
            background: #f8f9ff;
        }
        .action-icons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action-icons .btn-download-pdf {
            background: var(--primary);
            border: none;
            color: white;
            padding: 8px 24px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.15s;
            min-width: 80px;
            text-align: center;
        }
        .action-icons .btn-download-pdf:hover {
            background: #1d2863;
            transform: scale(1.05);
        }
        .action-icons .btn-download-csv {
            background: #17a2b8;
            border: none;
            color: white;
            padding: 8px 24px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.15s;
            min-width: 80px;
            text-align: center;
        }
        .action-icons .btn-download-csv:hover {
            background: #117a8b;
            transform: scale(1.05);
        }
        
        .report-name {
            font-weight: 600;
            color: var(--primary);
        }
        .report-name i {
            font-size: 18px;
        }
        .report-desc {
            color: #6c7a9a;
            font-weight: 400;
            font-size: 14px;
        }

        .loader {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .loader i {
            font-size: 30px;
            color: var(--primary);
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .topbar h3 {
                font-size: 22px;
            }
            .content {
                padding: 20px 16px;
            }
            .report-card {
                padding: 18px;
            }
            .report-row {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }
            .report-row .field-group {
                width: 100%;
            }
            .report-card .form-select-custom {
                max-width: 100%;
            }
            .date-range-group {
                flex-wrap: wrap;
            }
            .date-range-group .date-input {
                width: 100%;
            }
            .btn-generate, .btn-export-csv {
                width: 100%;
                justify-content: center;
                min-width: unset;
            }
            .btn-group-export {
                flex-direction: column;
                width: 100%;
            }
            .btn-group-export .btn-generate,
            .btn-group-export .btn-export-csv {
                width: 100%;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .action-icons {
                flex-direction: column;
                width: 100%;
            }
            .action-icons .btn-download-pdf,
            .action-icons .btn-download-csv {
                width: 100%;
                text-align: center;
            }
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
            <li><a href="SuperAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_CombinedManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a class="active" href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php" class="notification-link">
                <i class="bi bi-bell-fill"></i><span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a></li>
        </ul>
    </nav>

</div>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="topbar">
        <h3>Reports</h3>
        <div class="dropdown">
            <button
                class="profile profile-button dropdown-toggle"
                type="button"
                id="superAdminProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-person-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'SUPER ADMIN', ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="profile-role">| Super Admin</span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end profile-menu" aria-labelledby="superAdminProfileMenu">
                <li>
                    <div class="dropdown-header">Account options</div>
                </li>
                <li>
                    <a class="dropdown-item" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill"></i>
                        <span>Change Password</span>
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

    <div class="content">
        <?php echo reportCenterRender($reportCenter, 'Generate a report'); ?>
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
<script src="sources/sidebar.js"></script>
</body>
</html>