<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/inventory_stock_helpers.php';
$stockTotalsSql = inventoryStockTotalsSql();
require_once 'sources/notification_helper.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/patient_display_helper.php';
require_once 'sources/dashboard_patient_metrics.php';

// Check if user is logged in and is branch admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2
) {
    header("Location: login.php");
    exit();
}

// Get user branch information
$user_id = $_SESSION['user_id'];
$notification_count = getUnreadNotificationCount($conn, $user_id);
$branch_id = null;
$branch_name = '';
$username = '';

$userQuery = "SELECT u.branch_id, b.branch_name, u.username 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Admin';
}

if (!$branch_id) {
    $error_message = "No branch assigned to this user account.";
}

// ============================================
// REPORTS: the shared report center (sources/report_center.php).
// The four existing reports use the shared builders in
// sources/report_standard.php; the control reports come from the catalog.
// Each report gives a preview, a PDF and a Print of that PDF from the same data.
// ============================================
require_once 'sources/report_standard.php';
require_once 'sources/report_center.php';
$reportEntries = [
    'patient' => ['title' => 'Patient Report', 'icon' => 'bi-people', 'dates' => true, 'horizon' => false,
        'description' => 'Patients registered in the period with contact, cases and vaccinations given.',
        'build' => static fn(mysqli $c, array $p): array => reportStdPatients($c, $p)],
    'case' => ['title' => 'Case Report', 'icon' => 'bi-clipboard2-pulse', 'dates' => true, 'horizon' => false,
        'description' => 'Animal bite cases admitted in the period with bite details, category and status.',
        'build' => static fn(mysqli $c, array $p): array => reportStdCases($c, $p)],
    'inventory' => ['title' => 'Inventory Report', 'icon' => 'bi-box-seam', 'dates' => true, 'horizon' => false,
        'description' => 'Current stock of every item, status, nearest expiry and usage in the period.',
        'build' => static fn(mysqli $c, array $p): array => reportStdInventory($c, $p)],
    'vaccination' => ['title' => 'Vaccination Report', 'icon' => 'bi-eyedropper', 'dates' => true, 'horizon' => false,
        'description' => 'Every dose administered or scheduled in the period, with dose label and status.',
        'build' => static fn(mysqli $c, array $p): array => reportStdVaccinations($c, $p)],
    'forecast_accuracy' => ['title' => 'Forecast Accuracy Report', 'icon' => 'bi-bullseye', 'dates' => false, 'horizon' => false,
        'description' => 'Scores of the latest saved forecast evaluation (MAE, RMSE, SMAPE, review band) per item. Read-only; no evaluation is run.',
        'build' => static fn(mysqli $c, array $p): array => reportStdForecastAccuracy($c, $p)],
] + reportCenterCatalogEntries(2, null, [
    'data_quality' => ['description' => 'Patients and cases with missing or invalid required fields, with a count per problem. Links open the patient in Patient Monitoring; Admin Staff correct the record.'],
]);
$reportCenter = reportCenterRun($conn, $reportEntries, [
    'user_id' => (int)$user_id, 'role_id' => 2, 'branch_id' => (string)$branch_id,
    'page' => 'BranchAdmin_Reports.php', 'module' => 'Reports',
    'link_base' => 'BranchAdmin_PatientMonitoring.php?search={patient}',
]);

// Report stat cards. Each uses the shared definition of the page it must match:
//  - patients / cases: dashboardPatientMetrics() (Branch Admin Dashboard; archived records excluded)
//  - vaccinations given: dashboardVaccinationsGiven() (Branch Admin Dashboard "Completed Vaccinations")
//  - inventory: inventoryBranchSummary() (Inventory Overview / Inventory Officer pages)
$patientMetrics = dashboardPatientMetrics($conn, (string)$branch_id);
$inventorySummary = inventoryBranchSummary($conn, (string)$branch_id);
$completedVaccinations = dashboardVaccinationsGiven($conn, (string)$branch_id);
$stats = [
    'total_patients' => (int)$patientMetrics['total_patients'],
    'total_cases' => (int)$patientMetrics['total_cases'],
    'total_vaccinations' => $completedVaccinations,
    'total_inventory' => $inventorySummary['items'],
    'medical_items' => $inventorySummary['medical_items'],
    'non_medical_items' => $inventorySummary['non_medical_items'],
    'low_stock' => $inventorySummary['low_stock'],
    'out_of_stock' => $inventorySummary['out_of_stock'],
    'expiring_batches' => $inventorySummary['expiring_batches'],
    'expired_batches' => $inventorySummary['expired_batches'],
];
?>

<!-- HTML PAGE (unchanged) -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Branch Admin - Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="sidebar.css" />
    <link rel="stylesheet" href="notif-num.css"/>
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
        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
        }
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 6px;
        }
          .profile-role {
            margin-left: 4px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .content {
            padding: 35px 35px 40px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

/* =====================================================
   STATISTICS CARDS
   ===================================================== */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 25px;
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

/* =====================================================
   STAT CARD
   ===================================================== */

.stat-card {
    background: #ffffff;

    border-radius: 14px;

    min-height: 110px;

    padding: 18px 20px;

    display: flex;
    align-items: center;

    gap: 15px;

    /* Default left border */
    border-left: 5px solid var(--primary);

    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}


/* =====================================================
   HOVER EFFECT
   ===================================================== */

.stat-card:hover {
    transform: translateY(-3px);

    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.10);
}


/* =====================================================
   ICON
   ===================================================== */

.stat-icon {
    width: 42px;
    min-width: 42px;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 24px;

    /* No background */
    background: transparent;
}


/* =====================================================
   CONTENT
   ===================================================== */

.stat-content {
    flex: 1;
    min-width: 0;
}


/* =====================================================
   NUMBER
   ===================================================== */

.stat-card .number {
    font-size: 30px;

    font-weight: 700;

    line-height: 1.1;

    margin-bottom: 5px;

    /* BLACK NUMBER */
    color: #222222;
}


/* =====================================================
   LABEL
   ===================================================== */

.stat-card .stat-note {
    font-size: 11px;
    color: #6b7699;
    margin-top: 2px;
    line-height: 1.3;
}

.stat-card .label {
    font-size: 13px;

    color: #666666;

    font-weight: 600;

    line-height: 1.3;
}


/* =====================================================
   PRIMARY / BLUE
   ===================================================== */

.stat-primary {
    border-left-color: #2B3A8C;
}

.stat-primary .stat-icon {
    color: #2B3A8C;
}


/* =====================================================
   INFO / LIGHT BLUE
   ===================================================== */

.stat-info {
    border-left-color: #2196F3;
}

.stat-info .stat-icon {
    color: #2196F3;
}


/* =====================================================
   SUCCESS / GREEN
   ===================================================== */

.stat-success {
    border-left-color: #28A745;
}

.stat-success .stat-icon {
    color: #28A745;
}


/* =====================================================
   DANGER / RED
   ===================================================== */

.stat-danger {
    border-left-color: #DC3545;
}

.stat-danger .stat-icon {
    color: #DC3545;
}


/* =====================================================
   RESPONSIVE - TABLET
   ===================================================== */

@media (max-width: 1100px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

}


/* =====================================================
   RESPONSIVE - MOBILE
   ===================================================== */

@media (max-width: 576px) {

    .stats-grid {
        grid-template-columns: 1fr;

        gap: 14px;
    }

    .stat-card {
        min-height: 100px;

        padding: 16px 18px;

        gap: 12px;
    }

    .stat-card .number {
        font-size: 26px;
    }

    .stat-card .label {
        font-size: 12px;
    }

    .stat-icon {
        width: 38px;
        min-width: 38px;

        font-size: 22px;
    }

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
        .btn-generate {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            white-space: nowrap;
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
            background:var(--primary);
            color: #fff;
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
        .action-icons .btn-download {
            background: var(--primary);
            border: none;
            color: white;
            padding: 6px 20px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.15s;
        }
        .action-icons .btn-download:hover {
            background: #1d2863;
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
            .btn-generate {
                width: 100%;
                justify-content: center;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .table-wrap {
                overflow-x: auto;
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
                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <?php require_once __DIR__ . '/sources/inventory_approval_helpers.php'; echo inventoryApprovalSidebarLink($conn, (int)($_SESSION['user_id'] ?? 0)); ?>
                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
                <li><a href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
                <li><a href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
                <li><a class="active" href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
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

<!-- MAIN CONTENT -->
<div class="main">
    <div class="topbar">
        <h3>Reports <small><?php echo htmlspecialchars($branch_name); ?></small></h3>

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

    <div class="content">
 <!-- Statistics Cards (shared definitions: see the PHP block above) -->
<div class="stats-grid">
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['total_patients']); ?></div>
            <div class="label">Patients</div>
            <div class="stat-note">Active patient records (archived excluded) – same as the Branch Admin Dashboard</div>
        </div>
    </div>
    <div class="stat-card stat-primary">
        <div class="stat-icon"><i class="bi bi-clipboard2-pulse-fill"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['total_cases']); ?></div>
            <div class="label">Cases</div>
            <div class="stat-note">Active case records – same as the Branch Admin Dashboard</div>
        </div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-icon"><i class="bi bi-eyedropper"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['total_vaccinations']); ?></div>
            <div class="label">Vaccinations given</div>
            <div class="stat-note">Completed doses of active records – same as the Branch Admin Dashboard</div>
        </div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-icon"><i class="bi bi-box-seam-fill"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['total_inventory']); ?></div>
            <div class="label">Inventory items (all)</div>
            <div class="stat-note">Items with stock records in this branch, medical and non-medical – same as Inventory Overview</div>
        </div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-icon"><i class="bi bi-capsule"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['medical_items']); ?></div>
            <div class="label">Medical supplies</div>
            <div class="stat-note">Items in the Medical Supplies category – same as Medical Supplies Management</div>
        </div>
    </div>
    <div class="stat-card <?php echo $stats['low_stock'] > 0 ? 'stat-danger' : 'stat-success'; ?>">
        <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['low_stock']); ?></div>
            <div class="label">Low stock</div>
            <div class="stat-note">At or below the minimum, above zero (expired batches excluded) – same as Inventory Overview</div>
        </div>
    </div>
    <div class="stat-card <?php echo $stats['out_of_stock'] > 0 ? 'stat-danger' : 'stat-success'; ?>">
        <div class="stat-icon"><i class="bi bi-x-octagon-fill"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['out_of_stock']); ?></div>
            <div class="label">Out of stock</div>
            <div class="stat-note">No usable stock left – same as Inventory Overview</div>
        </div>
    </div>
    <div class="stat-card <?php echo ($stats['expiring_batches'] + $stats['expired_batches']) > 0 ? 'stat-danger' : 'stat-success'; ?>">
        <div class="stat-icon"><i class="bi bi-calendar-x-fill"></i></div>
        <div class="stat-content">
            <div class="number"><?php echo number_format($stats['expiring_batches'] + $stats['expired_batches']); ?></div>
            <div class="label">Expiry attention (batches)</div>
            <div class="stat-note"><?php echo number_format($stats['expiring_batches']); ?> expiring within 30 days · <?php echo number_format($stats['expired_batches']); ?> expired – same as Inventory Overview / Inventory Officer Dashboard</div>
        </div>
    </div>
</div>
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
<script src= "sources/sidebar.js"></script>
</body>
</html>