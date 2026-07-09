<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is Super Admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$username = '';

$userQuery = "SELECT username FROM users WHERE user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
if ($userRow = $userResult->fetch_assoc()) {
    $username = $userRow['username'] ?? 'Admin';
}

// ============================================
// GET FILTER PARAMETERS
// ============================================
$metric = isset($_GET['metric']) ? trim($_GET['metric']) : 'total_cases';
$date_range = isset($_GET['date_range']) ? trim($_GET['date_range']) : 'this_month';
$branch_id = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : '';

// ============================================
// DATE RANGE CALCULATION
// ============================================
$date_condition = "1=1";
$date_label = 'All Time';

switch ($date_range) {
    case 'this_month':
        $date_condition = "DATE(created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $date_label = 'This Month';
        break;
    case 'last_month':
        $date_condition = "DATE(created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01') 
                          AND DATE(created_at) < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $date_label = 'Last Month';
        break;
    case 'this_quarter':
        $date_condition = "DATE(created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL (MONTH(CURDATE()) - 1) % 3 MONTH";
        $date_label = 'This Quarter';
        break;
    case 'this_year':
        $date_condition = "DATE(created_at) >= DATE_FORMAT(CURDATE(), '%Y-01-01')";
        $date_label = 'This Year';
        break;
    case 'last_year':
        $date_condition = "DATE(created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 YEAR), '%Y-01-01')
                          AND DATE(created_at) < DATE_FORMAT(CURDATE(), '%Y-01-01')";
        $date_label = 'Last Year';
        break;
    default:
        $date_condition = "1=1";
        $date_label = 'All Time';
        break;
}

// ============================================
// FETCH BRANCH DATA
// ============================================
// Get all branches
$branches_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// If specific branch selected, filter
$branch_condition = "1=1";
if (!empty($branch_id)) {
    $branch_condition = "branch_id = '$branch_id'";
}

// ============================================
// FETCH PERFORMANCE DATA
// ============================================
$performance_data = [];
$total_patients = 0;
$total_cases = 0;
$total_vaccinations = 0;

foreach ($branches as $branch) {
    $bid = $branch['branch_id'];
    $bname = $branch['branch_name'];
    
    // Skip if branch filter is applied
    if (!empty($branch_id) && $bid != $branch_id) {
        continue;
    }
    
    // Get patient count
    $patient_sql = "SELECT COUNT(*) as count FROM patients WHERE branch_id = '$bid'";
    $patient_result = $conn->query($patient_sql);
    $patient_count = $patient_result->fetch_assoc()['count'] ?? 0;
    
    // Get case count
    $case_sql = "SELECT COUNT(*) as count FROM animal_bite_cases WHERE branch_id = '$bid'";
    $case_result = $conn->query($case_sql);
    $case_count = $case_result->fetch_assoc()['count'] ?? 0;
    
    // Get vaccination count
    $vaccine_sql = "SELECT COUNT(*) as count FROM vaccination_records WHERE branch_id = '$bid'";
    $vaccine_result = $conn->query($vaccine_sql);
    $vaccine_count = $vaccine_result->fetch_assoc()['count'] ?? 0;
    
    // Get staff count
    $staff_sql = "SELECT COUNT(*) as count FROM users WHERE branch_id = '$bid'";
    $staff_result = $conn->query($staff_sql);
    $staff_count = $staff_result->fetch_assoc()['count'] ?? 0;
    
    // Get inventory items count
    $inventory_sql = "SELECT COUNT(*) as count FROM inventory_stocks WHERE branch_id = '$bid'";
    $inventory_result = $conn->query($inventory_sql);
    $inventory_count = $inventory_result->fetch_assoc()['count'] ?? 0;
    
    $performance_data[] = [
        'branch_id' => $bid,
        'branch_name' => $bname,
        'patients' => $patient_count,
        'cases' => $case_count,
        'vaccinations' => $vaccine_count,
        'staff' => $staff_count,
        'inventory' => $inventory_count
    ];
    
    $total_patients += $patient_count;
    $total_cases += $case_count;
    $total_vaccinations += $vaccine_count;
}

// Determine which metric to display
$metric_label = '';
$metric_column = 'cases';
$max_value = 0;

switch ($metric) {
    case 'total_patients':
        $metric_label = 'Total Patients';
        $metric_column = 'patients';
        $max_value = max(array_column($performance_data, 'patients'));
        break;
    case 'total_cases':
        $metric_label = 'Total Cases';
        $metric_column = 'cases';
        $max_value = max(array_column($performance_data, 'cases'));
        break;
    case 'total_vaccinations':
        $metric_label = 'Total Vaccinations';
        $metric_column = 'vaccinations';
        $max_value = max(array_column($performance_data, 'vaccinations'));
        break;
    case 'staff':
        $metric_label = 'Staff Count';
        $metric_column = 'staff';
        $max_value = max(array_column($performance_data, 'staff'));
        break;
    case 'inventory':
        $metric_label = 'Inventory Items';
        $metric_column = 'inventory';
        $max_value = max(array_column($performance_data, 'inventory'));
        break;
    default:
        $metric_label = 'Total Cases';
        $metric_column = 'cases';
        $max_value = max(array_column($performance_data, 'cases'));
        break;
}

// If no data, set max to 1 to avoid division by zero
if ($max_value == 0) {
    $max_value = 1;
}

// Calculate max y-axis label (round up to nearest 100)
$y_max = ceil($max_value / 100) * 100;
if ($y_max == 0) {
    $y_max = 100;
}

// ============================================
// GET METRIC OPTIONS FOR DROPDOWN
// ============================================
$metrics_options = [
    'total_cases' => 'Total Cases',
    'total_patients' => 'Total Patients',
    'total_vaccinations' => 'Total Vaccinations',
    'staff' => 'Staff Count',
    'inventory' => 'Inventory Items'
];

$date_range_options = [
    'all_time' => 'All Time',
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'this_quarter' => 'This Quarter',
    'this_year' => 'This Year',
    'last_year' => 'Last Year'
];

// ============================================
// GET BRANCH OPTIONS FOR DROPDOWN
// ============================================
$branch_options = ['' => 'All Branches'];
foreach ($branches as $branch) {
    $branch_options[$branch['branch_id']] = $branch['branch_name'];
}

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Performance Monitoring') {
    $branch_id = null;
    $user_sql = "SELECT branch_id FROM users WHERE user_id = ?";
    $user_stmt = $conn->prepare($user_sql);
    if ($user_stmt) {
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $branch_id = $user_row['branch_id'];
        }
        $user_stmt->close();
    }
    
    $log_sql = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $log_stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
        $result = $log_stmt->execute();
        $log_stmt->close();
        return $result;
    }
    return false;
}

// Log page view
addAuditLog($conn, $_SESSION['user_id'], 'Viewed Branch Performance Monitoring - Metric: ' . $metric_label . ', Date: ' . $date_label, 'Performance Monitoring');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Branch Performance Monitoring</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS
           ========================================= */
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
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .content {
            padding: 35px 35px 40px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .page-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .page-header .badge-role {
            background: var(--primary);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 30px;
            letter-spacing: 0.3px;
            margin-left: 12px;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px 24px;
            background: white;
            padding: 18px 24px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 28px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 0 1 auto;
            min-width: 150px;
        }
        .filter-group label {
            font-weight: 600;
            color: var(--primary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin: 0;
        }
        .filter-group select,
        .filter-group .form-select {
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 8px 14px;
            font-weight: 500;
            color: #1f2a4a;
            background: white;
            outline: none;
            transition: 0.15s;
            font-size: 14px;
            min-width: 160px;
        }
        .filter-group select:focus,
        .filter-group .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
        }
        .btn-apply {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 28px;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
            cursor: pointer;
            height: 42px;
            align-self: flex-end;
        }
        .btn-apply:hover {
            background: #1d2863;
            color: white;
        }
        .btn-reset {
            background: #f0f3fc;
            color: var(--primary);
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
            cursor: pointer;
            height: 42px;
            align-self: flex-end;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-reset:hover {
            background: #e2e7f2;
            color: var(--primary);
            text-decoration: none;
        }

        .chart-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 28px 30px 30px;
            margin-bottom: 28px;
        }
        .chart-card .chart-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }
        .chart-card .chart-title small {
            font-weight: 400;
            font-size: 14px;
            color: #6c7a9a;
            margin-left: 10px;
        }

        .bar-chart-wrapper {
            display: flex;
            height: 280px;
            align-items: flex-end;
            gap: 18px;
            padding: 0 8px;
            border-bottom: 2px solid #d7def0;
            margin-bottom: 8px;
            position: relative;
        }
        .bar-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            justify-content: flex-end;
        }
        .bar {
            width: 60%;
            max-width: 56px;
            min-height: 8px;
            background: var(--primary);
            border-radius: 6px 6px 0 0;
            transition: 0.3s;
            position: relative;
            cursor: pointer;
        }
        .bar:hover {
            opacity: 0.8;
            transform: scaleY(1.02);
            transform-origin: bottom;
        }
        .bar-label {
            margin-top: 10px;
            font-weight: 600;
            font-size: 13px;
            color: #1f2a4a;
            text-align: center;
            line-height: 1.2;
        }
        .bar-value {
            font-weight: 700;
            font-size: 14px;
            color: var(--primary);
            margin-bottom: 6px;
        }

        .chart-area {
            position: relative;
        }
        .y-axis-labels {
            position: absolute;
            left: -36px;
            top: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 4px 0;
            font-weight: 600;
            font-size: 13px;
            color: #5a6a8c;
        }
        .y-axis-labels span {
            display: block;
        }

        .chart-wrap {
            position: relative;
            padding-left: 40px;
        }

        .legend-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 20px 48px;
            padding-top: 20px;
            border-top: 1px solid #edf1f8;
            margin-top: 8px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .legend-item .legend-label {
            font-weight: 500;
            color: #3a4a6a;
            font-size: 15px;
        }
        .legend-item .legend-value {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #8a96b8;
        }
        .no-data i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
            .sidebar {
                width: 90px;
                padding: 16px 10px;
            }
            .system-name,
            .nav-menu span,
            .logout span {
                display: none;
            }
            .logo-area {
                justify-content: center;
            }
            .nav-menu a {
                justify-content: center;
                padding: 12px 8px;
            }
            .nav-menu a i {
                font-size: 26px;
                margin: 0;
            }
            .logout a {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .filter-group {
                min-width: 100%;
            }
            .filter-group select,
            .filter-group .form-select {
                width: 100%;
                min-width: unset;
            }
            .btn-apply,
            .btn-reset {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .bar-chart-wrapper {
                height: 200px;
                gap: 10px;
            }
            .bar {
                width: 50%;
            }
            .legend-metrics {
                gap: 12px 24px;
            }
            .chart-card {
                padding: 16px;
            }
            .y-axis-labels {
                font-size: 11px;
                left: -28px;
            }
            .chart-wrap {
                padding-left: 28px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Super Admin) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="SuperAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a class="active" href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Branch Performance Monitoring</h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($username); ?>
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Page Header -->
        <div class="page-header">
            <h2>Performance Dashboard</h2>
        </div>

        <!-- Filter Row -->
        <form method="GET" action="" class="filter-row">
            <div class="filter-group">
                <label><i class="bi bi-bar-chart-fill"></i> Metric</label>
                <select name="metric" class="form-select">
                    <?php foreach ($metrics_options as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $metric == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="bi bi-calendar3"></i> Date Range</label>
                <select name="date_range" class="form-select">
                    <?php foreach ($date_range_options as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $date_range == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label><i class="bi bi-building"></i> Branch</label>
                <select name="branch_id" class="form-select">
                    <?php foreach ($branch_options as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $branch_id == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group" style="flex-direction:row; gap:8px; align-items:flex-end;">
                <button type="submit" class="btn-apply">
                    <i class="bi bi-funnel me-1"></i> Apply
                </button>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-reset">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
            </div>
        </form>

        <!-- Chart Card -->
        <div class="chart-card">
            <div class="chart-title">
                <?php echo $metric_label; ?> by Branch
                <small>(<?php echo $date_label; ?>)</small>
                <?php if (!empty($branch_id)): ?>
                    <small class="text-primary">| Filtered: <?php echo htmlspecialchars($branch_options[$branch_id] ?? ''); ?></small>
                <?php endif; ?>
            </div>

            <!-- Chart area with y-axis labels -->
            <div class="chart-wrap">
                <!-- Y-axis labels -->
                <div class="y-axis-labels">
                    <span><?php echo number_format($y_max); ?></span>
                    <span><?php echo number_format($y_max * 0.75); ?></span>
                    <span><?php echo number_format($y_max * 0.5); ?></span>
                    <span><?php echo number_format($y_max * 0.25); ?></span>
                    <span>0</span>
                </div>

                <!-- Bar chart -->
                <div class="bar-chart-wrapper">
                    <?php if (empty($performance_data)): ?>
                        <div class="no-data" style="width:100%;">
                            <i class="bi bi-inbox"></i>
                            <p>No data available for the selected filters.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($performance_data as $data): 
                            $value = $data[$metric_column] ?? 0;
                            $height_percent = ($y_max > 0) ? ($value / $y_max) * 100 : 0;
                            $height_percent = min($height_percent, 100);
                            $height_percent = max($height_percent, 5); // Minimum 5% for visibility
                        ?>
                            <div class="bar-container">
                                <div class="bar-value"><?php echo number_format($value); ?></div>
                                <div class="bar" style="height: <?php echo $height_percent; ?>%;"></div>
                                <div class="bar-label"><?php echo htmlspecialchars($data['branch_name']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Legend / Metrics -->
            <div class="legend-metrics">
                <div class="legend-item">
                    <span class="legend-dot" style="background: #2B3A8C;"></span>
                    <span class="legend-label">Total Patients:</span>
                    <span class="legend-value"><?php echo number_format($total_patients); ?></span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #F21D2F;"></span>
                    <span class="legend-label">Total Cases:</span>
                    <span class="legend-value"><?php echo number_format($total_cases); ?></span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #28a745;"></span>
                    <span class="legend-label">Total Vaccinations:</span>
                    <span class="legend-value"><?php echo number_format($total_vaccinations); ?></span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #fd7e14;"></span>
                    <span class="legend-label">Active Branches:</span>
                    <span class="legend-value"><?php echo count($performance_data); ?></span>
                </div>
            </div>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>