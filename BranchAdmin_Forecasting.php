<?php
session_start();

// ============================================
// CONFIGURATION & SECURITY
// ============================================

require_once 'sources/db_connect.php';

// Check if user is logged in and is Branch Admin (role_id = 2)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2
) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = $_SESSION['branch_id'] ?? null;

// If branch_id is not set for Branch Admin, redirect
if (empty($branch_id)) {
    header("Location: login.php?error=no_branch");
    exit();
}

// Get user info
$user_sql = "SELECT u.username, b.branch_name 
             FROM users u 
             LEFT JOIN branches b ON u.branch_id = b.branch_id 
             WHERE u.user_id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_stmt->close();

$username = $user_data['username'] ?? 'Branch Admin';
$branch_name = $user_data['branch_name'] ?? 'Unknown Branch';

// ============================================
// AUDIT LOG FUNCTION
// ============================================

function addAuditLog($conn, $user_id, $action, $module = 'Supply Forecasting') {
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

// ============================================
// AUTOMATIC DAILY FORECAST GENERATION
// ============================================

$forecast_days = 30;
$minimum_records_per_item = 15;
$forecast_message = '';
$forecast_message_type = 'info';

// Count forecastable items that have enough historical records.
$eligible_sql = "SELECT COUNT(*) AS eligible_count
                 FROM (
                     SELECT td.item_id
                     FROM training_dataset td
                     JOIN inventory_items i ON i.item_id = td.item_id
                     WHERE td.branch_id = ?
                       AND i.is_forecastable = 1
                     GROUP BY td.item_id
                     HAVING COUNT(*) >= ?
                 ) AS eligible_items";
$eligible_stmt = $conn->prepare($eligible_sql);
$eligible_stmt->bind_param("si", $branch_id, $minimum_records_per_item);
$eligible_stmt->execute();
$eligible_data = $eligible_stmt->get_result()->fetch_assoc();
$eligible_item_count = (int)($eligible_data['eligible_count'] ?? 0);
$eligible_stmt->close();

// Results are refreshed automatically on the first page visit of each day.
$latest_forecast_sql = "SELECT MAX(forecast_date) AS latest_forecast_date,
                               CURDATE() AS database_today,
                               COALESCE(SUM(
                                   CASE
                                       WHEN forecast_date = CURDATE() AND forecast_days = ? THEN 1
                                       ELSE 0
                                   END
                               ), 0) AS today_forecast_count
                        FROM forecast_results
                        WHERE branch_id = ?";
$latest_forecast_stmt = $conn->prepare($latest_forecast_sql);
$latest_forecast_stmt->bind_param("is", $forecast_days, $branch_id);
$latest_forecast_stmt->execute();
$latest_forecast_data = $latest_forecast_stmt->get_result()->fetch_assoc();
$latest_forecast_date = $latest_forecast_data['latest_forecast_date'] ?? null;
$today = $latest_forecast_data['database_today'] ?? date('Y-m-d');
$today_forecast_count = (int)($latest_forecast_data['today_forecast_count'] ?? 0);
$latest_forecast_stmt->close();

$forecast_is_due = $eligible_item_count > 0 && $today_forecast_count === 0;

if ($forecast_is_due) {
    $python_script = __DIR__ . '/forecasting.py';

    if (!is_file($python_script)) {
        $forecast_message = 'Automatic forecasting could not start because forecasting.py was not found.';
        $forecast_message_type = 'error';
    } else {
        $configured_python = getenv('SMARTBITECARE_PYTHON');
        $python_executable = $configured_python !== false && trim($configured_python) !== ''
            ? trim($configured_python)
            : (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');

        $python_command = escapeshellarg($python_executable)
            . ' ' . escapeshellarg($python_script)
            . ' ' . escapeshellarg($branch_id)
            . ' ' . escapeshellarg((string)$forecast_days)
            . ' 2>&1';

        $output = shell_exec($python_command);
        $result = is_string($output) ? json_decode(trim($output), true) : null;

        if ($result && !empty($result['success']) && !empty($result['forecasts']) && is_array($result['forecasts'])) {
            try {
                $conn->begin_transaction();

                // Keep the old results until the Python process has returned valid new forecasts.
                $delete_forecasts_stmt = $conn->prepare("DELETE FROM forecast_results WHERE branch_id = ?");
                $delete_forecasts_stmt->bind_param("s", $branch_id);
                $delete_forecasts_stmt->execute();
                $delete_forecasts_stmt->close();

                $insert_forecast_stmt = $conn->prepare("
                    INSERT INTO forecast_results
                    (item_id, branch_id, forecast_date, shortage_probability,
                     forecast_status, recommended_reorder, generated_by,
                     forecasted_consumption, forecast_days)
                    VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)
                ");

                $forecast_count = 0;
                foreach ($result['forecasts'] as $forecast_row) {
                    $item_id = (int)($forecast_row['item_id'] ?? 0);
                    if ($item_id <= 0) {
                        continue;
                    }

                    $item_check_stmt = $conn->prepare(
                        "SELECT item_id
                         FROM inventory_items
                         WHERE item_id = ? AND is_forecastable = 1"
                    );
                    $item_check_stmt->bind_param("i", $item_id);
                    $item_check_stmt->execute();
                    $valid_item = $item_check_stmt->get_result()->fetch_assoc();
                    $item_check_stmt->close();

                    if (!$valid_item) {
                        continue;
                    }

                    $shortage_probability = max(
                        0.0,
                        min(1.0, (float)($forecast_row['shortage_probability'] ?? 0))
                    );
                    $forecast_status = (string)($forecast_row['forecast_status'] ?? 'Sufficient');
                    $recommended_reorder = max(0, (int)($forecast_row['recommended_reorder'] ?? 0));
                    $generated_by = $user_id;
                    $forecasted_consumption = max(
                        0.0,
                        (float)($forecast_row['forecasted_consumption'] ?? 0)
                    );

                    $insert_forecast_stmt->bind_param(
                        "isdsiidi",
                        $item_id,
                        $branch_id,
                        $shortage_probability,
                        $forecast_status,
                        $recommended_reorder,
                        $generated_by,
                        $forecasted_consumption,
                        $forecast_days
                    );
                    $insert_forecast_stmt->execute();
                    $forecast_count++;
                }
                $insert_forecast_stmt->close();

                if ($forecast_count === 0) {
                    throw new RuntimeException('The model did not return any valid forecastable items.');
                }

                $conn->commit();
                $latest_forecast_date = $today;
                $forecast_message = "Today's 30-day forecasts were updated automatically for $forecast_count items.";
                $forecast_message_type = 'success';
                addAuditLog(
                    $conn,
                    $user_id,
                    "Automatically generated 30-day forecasts for $forecast_count items",
                    'Supply Forecasting'
                );
            } catch (Throwable $exception) {
                $conn->rollback();
                $forecast_message = 'Automatic forecasting failed: ' . $exception->getMessage();
                $forecast_message_type = 'error';
            }
        } else {
            $forecast_message = 'Automatic forecasting failed: '
                . (is_array($result) ? ($result['error'] ?? 'Invalid model response.') : 'Invalid model response.');
            $forecast_message_type = 'error';
        }
    }
} elseif ($eligible_item_count === 0) {
    $forecast_message = "Automatic forecasting is waiting for at least $minimum_records_per_item records per forecastable item.";
    $forecast_message_type = 'warning';
}

// ============================================
// GET FORECAST RESULTS
// ============================================

$forecasts = [];
$forecast_sql = "SELECT 
                p.*,
                i.item_name,
                i.minimum_stock,
                u.unit_name
             FROM forecast_results p
             JOIN inventory_items i ON p.item_id = i.item_id
             LEFT JOIN units u ON i.unit_id = u.unit_id
             WHERE p.branch_id = ?
             ORDER BY p.shortage_probability DESC";

$forecast_stmt = $conn->prepare($forecast_sql);
$forecast_stmt->bind_param("s", $branch_id);
$forecast_stmt->execute();
$forecast_result = $forecast_stmt->get_result();

while ($row = $forecast_result->fetch_assoc()) {
    // Determine status color
    if ($row['shortage_probability'] >= 0.8) {
        $status_color = 'danger';
    } elseif ($row['shortage_probability'] >= 0.6) {
        $status_color = 'warning';
    } else {
        $status_color = 'success';
    }
    
    $forecasts[] = [
        'item_name' => $row['item_name'],
        'unit_name' => $row['unit_name'],
        'shortage_probability' => (float)$row['shortage_probability'],
        'forecast_status' => $row['forecast_status'],
        'status_color' => $status_color,
        'recommended_reorder' => (int)$row['recommended_reorder'],
        'forecasted_consumption' => (int)$row['forecasted_consumption'],
        'forecast_days' => (int)$row['forecast_days'],
        'minimum_stock' => (int)$row['minimum_stock'],
        'forecast_date' => date('m/d/Y', strtotime($row['forecast_date']))
    ];
}
$forecast_stmt->close();

// ============================================
// GET TRAINING DATA SUMMARY
// ============================================

$training_stats = [];
$stats_sql = "SELECT 
                COUNT(DISTINCT item_id) as item_count,
                COUNT(*) as total_records,
                MIN(record_date) as earliest_date,
                MAX(record_date) as latest_date,
                AVG(quantity_used) as avg_usage
             FROM training_dataset 
             WHERE branch_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("s", $branch_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$training_stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// ============================================
// GET FORECASTABLE ITEMS
// ============================================

$forecastable_items = [];
$items_sql = "SELECT i.item_id, i.item_name, u.unit_name, i.minimum_stock
              FROM inventory_items i
              LEFT JOIN units u ON i.unit_id = u.unit_id
              WHERE i.is_forecastable = 1
              ORDER BY i.item_name";
$items_result = $conn->query($items_sql);
while ($row = $items_result->fetch_assoc()) {
    $forecastable_items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supply Forecasting - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }

        body {
            background: #f0f2f5;
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
            border-bottom: 1px solid #e9edf5;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }

        .topbar h3 small {
            color: #666;
            font-size: 16px;
            font-weight: 400;
            margin-left: 10px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 7px;
            font-weight: 600;
            color: var(--primary);
            cursor: default;
        }

        .profile i {
            font-size: 19px;
        }

        .profile .profile-role {
            font-weight: 600;
        }

        .page-body {
            padding: 35px 35px 40px;
        }

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 16px 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 6px solid #28a745;
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 320px;
            animation: slideIn 0.4s ease;
            margin-bottom: 10px;
        }

        .toast-custom.error {
            border-left-color: #dc3545;
        }

        .toast-custom.warning {
            border-left-color: #ffc107;
        }

        .toast-custom .toast-icon {
            font-size: 28px;
            color: #28a745;
        }

        .toast-custom.error .toast-icon {
            color: #dc3545;
        }

        .toast-custom.warning .toast-icon {
            color: #d99b00;
        }

        .toast-custom .toast-msg {
            font-weight: 500;
            color: #1f2a4a;
            flex: 1;
        }

        .toast-custom .toast-close {
            background: none;
            border: none;
            font-size: 22px;
            color: #999;
            cursor: pointer;
            padding: 0 4px;
        }

        .toast-custom .toast-close:hover {
            color: #333;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .stat-card {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            column-gap: 12px;
            align-items: center;
            height: 120px;
            padding: 18px 22px;
            overflow: hidden;
            background: #fff;
            border: 0;
            border-left: 5px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,.10);
        }

        .stat-card .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 30px;
        }

        .stat-card .stat-content {
            min-width: 0;
        }

        .stat-card .stat-label {
            overflow: hidden;
            color: #2f3b4d;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.2;
            margin-bottom: 2px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stat-card .stat-number {
            color: #111827;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.05;
        }

        .stat-card .stat-description {
            overflow: hidden;
            color: #71809d;
            font-size: 12px;
            line-height: 1.2;
            margin-top: 3px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stat-primary { border-left-color: var(--primary); }
        .stat-success { border-left-color: var(--success); }
        .stat-info { border-left-color: var(--info); }
        .stat-warning { border-left-color: var(--warning); }
        .stat-primary .stat-icon { color: var(--primary); }
        .stat-success .stat-icon { color: var(--success); }
        .stat-info .stat-icon { color: var(--info); }
        .stat-warning .stat-icon { color: #d99b00; }

        .forecast-status-card {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding: 18px 22px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }

        .forecast-status-card .status-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            color: var(--primary);
            background: #eef1ff;
            border-radius: 12px;
            font-size: 22px;
        }

        .forecast-status-card h5 {
            color: #18233f;
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 3px;
        }

        .forecast-status-card p {
            color: #71809d;
            font-size: 13px;
            margin: 0;
        }

        .table-wrap {
            background: white;
            border-radius: 16px;
            border: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            overflow: hidden;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f5;
        }

        .table-header h5 {
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .data-table {
            margin: 0;
        }

        .data-table thead th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            font-size: 13px;
            border: none;
            padding: 14px;
            white-space: nowrap;
        }

        .data-table tbody td {
            font-size: 14px;
            color: #333;
            padding: 13px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #eef0f7;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #f7f8fc;
        }

        .badge-status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #E6F4EA;
            color: #1E7B34;
        }

        .badge-warning {
            background: #FFF3CD;
            color: #856404;
        }

        .badge-danger {
            background: #FFEAEA;
            color: var(--accent);
        }

        .probability-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 4px;
        }

        .probability-bar .fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }

        .fill-high {
            background: var(--accent);
        }

        .fill-medium {
            background: #ffc107;
        }

        .fill-low {
            background: #28a745;
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
                font-size: 20px;
            }
            .page-body {
                padding: 20px 16px;
            }
            .profile .profile-role {
                display: none;
            }
            .forecast-status-card {
                align-items: flex-start;
            }
            .table-wrap {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<!-- ========== TOAST CONTAINER ========== -->
<div class="toast-container" id="toastContainer"></div>

<!-- ========== SIDEBAR ========== -->
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
            <li><a href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
            <li><a href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
            <li><a class="active" href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
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

<!-- ========== MAIN CONTENT ========== -->
<div class="main">
    <div class="topbar">
        <h3>Supply Forecasting <small class="text-muted fs-6"><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <span><?php echo htmlspecialchars($username); ?></span>
            <span class="profile-role">| Branch Administrator</span>
        </div>
    </div>

    <div class="page-body">
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-primary">
                    <div class="stat-icon"><i class="bi bi-database-check"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Training Records</div>
                        <div class="stat-number"><?php echo number_format($training_stats['total_records'] ?? 0); ?></div>
                        <div class="stat-description">Stored historical records</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-info">
                    <div class="stat-icon"><i class="bi bi-boxes"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Items with Data</div>
                        <div class="stat-number"><?php echo number_format($training_stats['item_count'] ?? 0); ?></div>
                        <div class="stat-description">Items in the training set</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-success">
                    <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Current Forecasts</div>
                        <div class="stat-number"><?php echo count($forecasts); ?></div>
                        <div class="stat-description">Automatic 30-day results</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-warning">
                    <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Last Updated</div>
                        <div class="stat-number" style="font-size:22px;">
                            <?php echo $latest_forecast_date ? date('M d, Y', strtotime($latest_forecast_date)) : 'Pending'; ?>
                        </div>
                        <div class="stat-description">Refreshed once each day</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Automatic Forecast Information -->
        <div class="forecast-status-card">
            <div class="status-icon"><i class="bi bi-cpu-fill"></i></div>
            <div>
                <h5>Automatic XGBoost Forecasting</h5>
                <p>
                    The system automatically refreshes the 30-day supply forecast on the first page visit each day.
                    SMAPE is used for model evaluation, and only items with at least
                    <?php echo $minimum_records_per_item; ?> historical records are included.
                </p>
            </div>
        </div>

        <!-- Forecast Results -->
        <div class="table-wrap">
            <div class="table-header">
                <h5><i class="bi bi-clipboard-data me-2"></i>Forecast Results</h5>
                <span class="text-muted" style="font-size:13px;">
                    <?php echo count($forecasts); ?> forecasts found
                </span>
            </div>
            <table class="table data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th>Min Stock</th>
                        <th>Forecasted Consumption</th>
                        <th>Shortage Probability</th>
                        <th>Status</th>
                        <th>Recommendation</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($forecasts) > 0): ?>
                        <?php foreach ($forecasts as $forecast): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($forecast['item_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($forecast['unit_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($forecast['minimum_stock']); ?></td>
                                <td><?php echo htmlspecialchars($forecast['forecasted_consumption'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php echo number_format($forecast['shortage_probability'] * 100, 1); ?>%
                                    <div class="probability-bar">
                                        <div class="fill <?php 
                                            echo $forecast['shortage_probability'] >= 0.8 ? 'fill-high' : 
                                                ($forecast['shortage_probability'] >= 0.6 ? 'fill-medium' : 'fill-low'); 
                                        ?>" 
                                             style="width: <?php echo $forecast['shortage_probability'] * 100; ?>%;">
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-status <?php 
                                        echo $forecast['status_color'] == 'danger' ? 'badge-danger' : 
                                            ($forecast['status_color'] == 'warning' ? 'badge-warning' : 'badge-success'); 
                                    ?>">
                                        <?php echo htmlspecialchars($forecast['forecast_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($forecast['recommended_reorder'] > 0): ?>
                                        <span class="badge bg-warning text-dark">
                                            Reorder <?php echo htmlspecialchars($forecast['recommended_reorder']); ?> units
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">No action needed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($forecast['forecast_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No automatic forecast is available yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Forecastable Items List -->
        <div class="mt-4">
            <h6 class="fw-bold text-muted" style="font-size:13px;text-transform:uppercase;letter-spacing:0.3px;">
                <i class="bi bi-list-check me-2"></i>Forecastable Items in Inventory
            </h6>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($forecastable_items as $item): ?>
                    <span class="badge bg-light text-dark border" style="padding:6px 14px;font-weight:600;">
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <span class="text-muted ms-1" style="font-weight:400;">
                            (<?php echo htmlspecialchars($item['unit_name'] ?? 'N/A'); ?>)
                        </span>
                    </span>
                <?php endforeach; ?>
                <?php if (empty($forecastable_items)): ?>
                    <span class="text-muted">No forecastable items found. Add items with is_forecastable=1.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================
// TOAST NOTIFICATIONS
// ============================================

function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast-custom ${type}`;
    const iconMap = {
        'success': 'bi-check-circle-fill',
        'error': 'bi-x-circle-fill',
        'warning': 'bi-exclamation-triangle-fill'
    };
    const icon = iconMap[type] || 'bi-info-circle-fill';
    toast.innerHTML = `
        <span class="toast-icon"><i class="bi ${icon}"></i></span>
        <span class="toast-msg">${message}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    container.appendChild(toast);
    setTimeout(() => {
        if (toast.parentElement) toast.remove();
    }, 8000);
}

<?php if (isset($forecast_message) && !empty($forecast_message)): ?>
    showToast(
        <?php echo json_encode($forecast_message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        <?php echo json_encode($forecast_message_type); ?>
    );
<?php endif; ?>
</script>
</body>
</html>
