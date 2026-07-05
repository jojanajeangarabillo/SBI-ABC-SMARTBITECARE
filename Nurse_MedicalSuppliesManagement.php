<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Medical Supplies') {
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

// Check if user is logged in and is Nurse (role_id = 3) or Super Admin (role_id = 1)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 3)
) {
    header("Location: login.php");
    exit();
}

// Get nurse's branch
$branch_id = $_SESSION['branch_id'] ?? null;

// ============================================
// HANDLE CRUD OPERATIONS
// ============================================

// Handle Record Usage (Vaccination)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_usage'])) {
    $patient_id = intval($_POST['patient_id']);
    $case_id = intval($_POST['case_id']);
    $item_id = intval($_POST['item_id']);
    $quantity_used = intval($_POST['quantity_used']);
    $reason = trim($_POST['reason']);
    $dose_number = intval($_POST['dose_number'] ?? 1);
    $date_administered = $_POST['date_administered'] ?? date('Y-m-d');
    
    if ($patient_id <= 0 || $item_id <= 0 || $quantity_used <= 0) {
        $error_msg = "Please fill in all required fields.";
    } else {
        // Check if enough stock
        $stock_sql = "SELECT quantity_available FROM inventory_stocks WHERE item_id = ? AND branch_id = ?";
        $stock_stmt = $conn->prepare($stock_sql);
        $stock_stmt->bind_param("is", $item_id, $branch_id);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        $stock_data = $stock_result->fetch_assoc();
        $stock_stmt->close();
        
        if (!$stock_data || $stock_data['quantity_available'] < $quantity_used) {
            $error_msg = "Insufficient stock! Available: " . ($stock_data['quantity_available'] ?? 0);
        } else {
            // Get item details for audit log
            $item_sql = "SELECT item_name FROM inventory_items WHERE item_id = ?";
            $item_stmt = $conn->prepare($item_sql);
            $item_stmt->bind_param("i", $item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item_data = $item_result->fetch_assoc();
            $item_stmt->close();
            
            // Get patient name
            $patient_sql = "SELECT full_name FROM patients WHERE patient_id = ?";
            $patient_stmt = $conn->prepare($patient_sql);
            $patient_stmt->bind_param("i", $patient_id);
            $patient_stmt->execute();
            $patient_result = $patient_stmt->get_result();
            $patient_data = $patient_result->fetch_assoc();
            $patient_stmt->close();
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert vaccination record
                $sql = "INSERT INTO vaccination_records 
                        (patient_id, case_id, item_id, branch_id, dose_number, date_administered, vaccination_status, is_final_dose, nurse_id) 
                        VALUES (?, ?, ?, ?, ?, ?, 'Completed', 0, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiisisi", $patient_id, $case_id, $item_id, $branch_id, $dose_number, $date_administered, $_SESSION['user_id']);
                $stmt->execute();
                $vaccination_id = $conn->insert_id;
                $stmt->close();
                
                // Update stock
                $update_sql = "UPDATE inventory_stocks SET quantity_available = quantity_available - ? WHERE item_id = ? AND branch_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("iis", $quantity_used, $item_id, $branch_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Record stock transaction
                $transaction_sql = "INSERT INTO stock_transactions 
                                    (item_id, user_id, branch_id, transaction_type, quantity, remarks, vaccination_id) 
                                    VALUES (?, ?, ?, 'OUT', ?, ?, ?)";
                $transaction_stmt = $conn->prepare($transaction_sql);
                $remarks = "Vaccination - Patient: " . ($patient_data['full_name'] ?? 'Unknown') . " - Dose: $dose_number";
                $transaction_stmt->bind_param("iisisi", $item_id, $_SESSION['user_id'], $branch_id, $quantity_used, $remarks, $vaccination_id);
                $transaction_stmt->execute();
                $transaction_stmt->close();
                
                $conn->commit();
                
                // Log the action
                addAuditLog($conn, $_SESSION['user_id'], 
                    "Recorded usage of $item_data[item_name]: $quantity_used units for patient " . ($patient_data['full_name'] ?? 'Unknown') . " (Dose $dose_number)");
                $success_msg = "Usage recorded successfully!";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Error recording usage: " . $e->getMessage();
                addAuditLog($conn, $_SESSION['user_id'], "Failed to record usage: " . $e->getMessage());
            }
        }
    }
}

// Handle Daily Consumption
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_daily_consumption'])) {
    $consumption_date = $_POST['consumption_date'] ?? date('Y-m-d');
    $patient_count = intval($_POST['patient_count'] ?? 0);
    
    $conn->begin_transaction();
    try {
        foreach ($_POST['consumption'] as $item_id => $quantity) {
            $quantity = intval($quantity);
            if ($quantity > 0) {
                // Insert usage history
                $usage_sql = "INSERT INTO inventory_usage_history 
                              (item_id, branch_id, usage_date, quantity_used, patient_count) 
                              VALUES (?, ?, ?, ?, ?)";
                $usage_stmt = $conn->prepare($usage_sql);
                $usage_stmt->bind_param("issii", $item_id, $branch_id, $consumption_date, $quantity, $patient_count);
                $usage_stmt->execute();
                $usage_stmt->close();
                
                // Update stock
                $update_sql = "UPDATE inventory_stocks SET quantity_available = quantity_available - ? WHERE item_id = ? AND branch_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("iis", $quantity, $item_id, $branch_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }
        $conn->commit();
        
        addAuditLog($conn, $_SESSION['user_id'], "Recorded daily consumption for $consumption_date - $patient_count patients served");
        $success_msg = "Daily consumption saved successfully!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = "Error saving daily consumption: " . $e->getMessage();
        addAuditLog($conn, $_SESSION['user_id'], "Failed to save daily consumption: " . $e->getMessage());
    }
}

// Handle Restock Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_restock'])) {
    $item_id = intval($_POST['item_id']);
    $requested_quantity = intval($_POST['requested_quantity']);
    $reason = trim($_POST['reason']);
    
    if ($item_id <= 0 || $requested_quantity <= 0) {
        $error_msg = "Please select an item and enter quantity.";
    } else {
        // Get item details
        $item_sql = "SELECT item_name FROM inventory_items WHERE item_id = ?";
        $item_stmt = $conn->prepare($item_sql);
        $item_stmt->bind_param("i", $item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $item_data = $item_result->fetch_assoc();
        $item_stmt->close();
        
        // Insert restock request as notification
        $notif_sql = "INSERT INTO notifications (user_id, title, message, notification_type) 
                      VALUES (?, ?, ?, 'restock_request')";
        $title = "Restock Request: $item_data[item_name]";
        $message = "Requested quantity: $requested_quantity units\nReason: $reason\nBranch: " . ($_SESSION['branch_name'] ?? 'N/A');
        $notif_stmt = $conn->prepare($notif_sql);
        
        // Send to Super Admin (role_id = 1)
        $admin_sql = "SELECT user_id FROM users WHERE role_id = 1 LIMIT 1";
        $admin_result = $conn->query($admin_sql);
        $admin_data = $admin_result->fetch_assoc();
        $admin_id = $admin_data['user_id'] ?? 1;
        
        $notif_stmt->bind_param("iss", $admin_id, $title, $message);
        $notif_stmt->execute();
        $notif_stmt->close();
        
        addAuditLog($conn, $_SESSION['user_id'], "Requested restock for $item_data[item_name]: $requested_quantity units");
        $success_msg = "Restock request submitted successfully!";
    }
}

// ============================================
// GET DATA
// ============================================

// Get medical supplies (only items from medical categories)
$items_sql = "SELECT 
                i.item_id, i.item_name, i.minimum_stock,
                c.category_name,
                u.unit_name,
                COALESCE(s.quantity_available, 0) as current_stock,
                s.expiration_date,
                s.last_updated
              FROM inventory_items i
              JOIN inventory_categories c ON i.category_id = c.category_id
              JOIN units u ON i.unit_id = u.unit_id
              LEFT JOIN inventory_stocks s ON i.item_id = s.item_id AND s.branch_id = ?
              WHERE c.category_name IN ('Vaccine', 'Medical', 'Medical Supply')
              ORDER BY i.item_name ASC";

$stmt = $conn->prepare($items_sql);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$items_result = $stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

// Get patients for dropdown
$patients_sql = "SELECT patient_id, full_name FROM patients ORDER BY full_name ASC";
$patients_result = $conn->query($patients_sql);

// Get cases for dropdown (animal bite cases)
$cases_sql = "SELECT case_id, patient_id FROM animal_bite_cases WHERE branch_id = ? AND case_status != 'Completed'";
$cases_stmt = $conn->prepare($cases_sql);
$cases_stmt->bind_param("s", $branch_id);
$cases_stmt->execute();
$cases_result = $cases_stmt->get_result();
$cases = [];
while ($row = $cases_result->fetch_assoc()) {
    $cases[] = $row;
}
$cases_stmt->close();

// Get statistics
$stats_sql = "SELECT 
                COUNT(DISTINCT i.item_id) as total_supplies,
                SUM(CASE WHEN COALESCE(s.quantity_available, 0) <= i.minimum_stock AND COALESCE(s.quantity_available, 0) > 0 THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN COALESCE(s.quantity_available, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(CASE WHEN s.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon
              FROM inventory_items i
              JOIN inventory_categories c ON i.category_id = c.category_id
              LEFT JOIN inventory_stocks s ON i.item_id = s.item_id AND s.branch_id = ?
              WHERE c.category_name IN ('Vaccine', 'Medical', 'Medical Supply')";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("s", $branch_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// Get today's usage
$today_sql = "SELECT SUM(quantity_used) as total_used, SUM(patient_count) as patients_served 
              FROM inventory_usage_history 
              WHERE branch_id = ? AND usage_date = CURDATE()";
$today_stmt = $conn->prepare($today_sql);
$today_stmt->bind_param("s", $branch_id);
$today_stmt->execute();
$today_result = $today_stmt->get_result();
$today_stats = $today_result->fetch_assoc();
$today_stmt->close();

// Get restock requests count
$restock_sql = "SELECT COUNT(*) as requests FROM notifications 
                WHERE notification_type = 'restock_request' AND is_read = 0";
$restock_result = $conn->query($restock_sql);
$restock_data = $restock_result->fetch_assoc();

// Get item ID for consumption modal
$consumption_items = [];
foreach ($items as $item) {
    if ($item['current_stock'] > 0) {
        $consumption_items[] = $item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Medical Supplies Management</title>
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

        .stat-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 18px 20px;
            height: 100px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            letter-spacing: 0.2px;
        }
        .stat-number {
            font-size: 34px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
        }

        .function-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }
        .btn-function {
            background: var(--card-bg);
            color: var(--primary);
            border: none;
            border-radius: 40px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
        }
        .btn-function:hover {
            background: #d7def0;
        }
        .btn-function i {
            margin-right: 6px;
        }

        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: #f0f3fc;
            color: var(--primary);
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

        .status-badge {
            display: inline-block;
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
            letter-spacing: 0.2px;
        }
        .status-badge.normal {
            background: #d4f0d4;
            color: #1a6e1a;
        }
        .status-badge.low {
            background: #fde8b0;
            color: #8a6d00;
        }
        .status-badge.critical {
            background: #fde8e8;
            color: var(--accent);
        }

        .action-icons i {
            font-size: 18px;
            color: var(--primary);
            margin-right: 12px;
            cursor: pointer;
            opacity: 0.7;
            transition: 0.1s;
        }
        .action-icons i:hover {
            opacity: 1;
        }

        .search-wrap {
            position: relative;
            max-width: 380px;
            margin-bottom: 16px;
        }
        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7a85a8;
            font-size: 18px;
        }
        .search-wrap input {
            width: 100%;
            padding: 10px 12px 10px 44px;
            border: 1px solid #d0d7e8;
            border-radius: 40px;
            font-size: 15px;
            background: white;
            outline: none;
            transition: 0.15s;
        }
        .search-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }

        .modal-content {
            border-radius: 18px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            border-bottom: 1px solid #edf1f8;
            padding: 20px 28px;
        }
        .modal-header .modal-title {
            font-weight: 700;
            color: var(--primary);
            font-size: 20px;
        }
        .modal-body {
            padding: 24px 28px;
        }
        .modal-footer {
            border-top: 1px solid #edf1f8;
            padding: 16px 28px;
        }
        .modal .form-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
        }
        .modal .form-control,
        .modal .form-select {
            border-radius: 10px;
            border: 1px solid #d0d7e8;
            padding: 10px 16px;
        }
        .modal .form-control:focus,
        .modal .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .btn-save {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 32px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-save:hover {
            background: #1d2863;
            color: #fff;
        }
        .btn-cancel {
            background: var(--card-bg);
            color: var(--primary);
            border: none;
            border-radius: 40px;
            padding: 10px 28px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-cancel:hover {
            background: #d7def0;
        }

        .consumption-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            padding: 8px 0;
            border-bottom: 1px solid #edf1f8;
        }
        .consumption-row:last-child {
            border-bottom: none;
        }
        .consumption-row .item-label {
            font-weight: 600;
            color: var(--primary);
            min-width: 80px;
        }
        .consumption-row .item-input {
            width: 100px;
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 6px 12px;
            text-align: center;
            outline: none;
        }
        .consumption-row .item-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
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

        .toast-custom .toast-icon {
            font-size: 28px;
            color: #28a745;
        }

        .toast-custom.error .toast-icon {
            color: #dc3545;
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

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
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

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .content {
                padding: 20px 16px;
            }
            .stat-number {
                font-size: 28px;
            }
            .stat-card {
                height: 80px;
                padding: 14px;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .search-wrap {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer"></div>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a class="active" href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notifications.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="topbar">
        <h3>Medical Supplies Management</h3>
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="content">
        <!-- Function Buttons -->
        <div class="function-buttons">
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#recordUsageModal"><i class="bi bi-pencil-square"></i> Record Usage</button>
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#dailyConsumptionModal"><i class="bi bi-clipboard2-check"></i> Record Daily Consumption</button>
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#requestRestockModal"><i class="bi bi-box-arrow-up-right"></i> Request Restock</button>
        </div>

        <!-- Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-title">Total Supplies</div>
                    <div class="stat-number"><?php echo $stats['total_supplies'] ?? 0; ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-title">Low Stocks</div>
                    <div class="stat-number" style="color: #8a6d00;"><?php echo $stats['low_stock'] ?? 0; ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-title">Out of Stock</div>
                    <div class="stat-number" style="color: var(--accent);"><?php echo $stats['out_of_stock'] ?? 0; ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <div class="stat-title">Expiring Soon</div>
                    <div class="stat-number" style="color: #e65100;"><?php echo $stats['expiring_soon'] ?? 0; ?></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-title">Today's Usage</div>
                    <div class="stat-number"><?php echo $today_stats['total_used'] ?? 0; ?></div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-title">Today's Patients</div>
                    <div class="stat-number"><?php echo $today_stats['patients_served'] ?? 0; ?></div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-title">Restock Requests</div>
                    <div class="stat-number" style="color: #0d47a1;"><?php echo $restock_data['requests'] ?? 0; ?></div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search supplies..." onkeyup="filterTable()" />
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table align-middle" id="suppliesTable">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Stock</th>
                        <th>Unit</th>
                        <th>Min Stock</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): 
                            $status = 'Normal';
                            $status_class = 'normal';
                            if ($item['current_stock'] <= 0) {
                                $status = 'Out of Stock';
                                $status_class = 'critical';
                            } elseif ($item['current_stock'] <= $item['minimum_stock']) {
                                $status = 'Low';
                                $status_class = 'low';
                            }
                            
                            $expiry_text = 'N/A';
                            $expiry_class = '';
                            if ($item['expiration_date']) {
                                $expiry = new DateTime($item['expiration_date']);
                                $today = new DateTime();
                                $diff = $today->diff($expiry);
                                $days = $diff->days;
                                if ($diff->invert == 0) {
                                    $expiry_text = $expiry->format('M d, Y');
                                    if ($days <= 30) {
                                        $expiry_text .= " ($days days)";
                                        $expiry_class = 'text-danger fw-bold';
                                    }
                                } else {
                                    $expiry_text = 'Expired';
                                    $expiry_class = 'text-danger fw-bold';
                                }
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['current_stock']); ?></td>
                            <td><?php echo htmlspecialchars($item['unit_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($item['minimum_stock']); ?></td>
                            <td class="<?php echo $expiry_class; ?>"><?php echo $expiry_text; ?></td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $status; ?>
                                </span>
                            </td>
                            <td class="action-icons">
                                <i class="bi bi-pencil-square" data-bs-toggle="modal" data-bs-target="#recordUsageModal" title="Record Usage"></i>
                                <i class="bi bi-box-arrow-up-right" data-bs-toggle="modal" data-bs-target="#requestRestockModal" title="Request Restock"></i>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No medical supplies found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- RECORD USAGE MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="recordUsageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Record Medical Supply Usage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="modal-body">
                    <input type="hidden" name="record_usage" value="1" />
                    
                    <div class="mb-3">
                        <label class="form-label">Patient *</label>
                        <select class="form-select" name="patient_id" required>
                            <option value="">Select Patient...</option>
                            <?php while ($patient = $patients_result->fetch_assoc()): ?>
                                <option value="<?php echo $patient['patient_id']; ?>">
                                    <?php echo htmlspecialchars($patient['full_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Case</label>
                        <select class="form-select" name="case_id">
                            <option value="0">No Case</option>
                            <?php foreach ($cases as $case): ?>
                                <option value="<?php echo $case['case_id']; ?>">
                                    Case #<?php echo $case['case_id']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Supply *</label>
                        <select class="form-select" name="item_id" required>
                            <option value="">Select Supply...</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?php echo $item['item_id']; ?>">
                                    <?php echo htmlspecialchars($item['item_name']); ?> 
                                    (Stock: <?php echo $item['current_stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Dose Number</label>
                        <select class="form-select" name="dose_number">
                            <option value="1">Dose 1</option>
                            <option value="2">Dose 2</option>
                            <option value="3">Dose 3</option>
                            <option value="4">Booster</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quantity Used *</label>
                        <input type="number" class="form-control" name="quantity_used" value="1" min="1" required />
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <select class="form-select" name="reason">
                            <option>Vaccination</option>
                            <option>Wound Care</option>
                            <option>Consultation</option>
                            <option>Emergency</option>
                            <option>Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-0">
                        <label class="form-label">Date Administered</label>
                        <input type="date" class="form-control" name="date_administered" value="<?php echo date('Y-m-d'); ?>" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-save"><i class="bi bi-check-lg me-2"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- DAILY CONSUMPTION MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="dailyConsumptionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard2-check me-2"></i>Today's Consumption Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="modal-body">
                    <input type="hidden" name="save_daily_consumption" value="1" />
                    
                    <div class="mb-3">
                        <label class="form-label">Consumption Date</label>
                        <input type="date" class="form-control" name="consumption_date" value="<?php echo date('Y-m-d'); ?>" />
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Patients Served Today</label>
                        <input type="number" class="form-control" name="patient_count" value="0" min="0" />
                    </div>
                    
                    <hr />
                    <p class="text-muted small">Enter quantity used for each supply today:</p>
                    
                    <?php foreach ($consumption_items as $item): ?>
                        <div class="consumption-row">
                            <span class="item-label"><?php echo htmlspecialchars($item['item_name']); ?></span>
                            <span>Used:</span>
                            <input type="number" class="item-input" name="consumption[<?php echo $item['item_id']; ?>]" value="0" min="0" />
                            <span style="font-size:12px;color:#8a96b8;">(<?php echo $item['current_stock']; ?> available)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-save"><i class="bi bi-check-lg me-2"></i>Save Daily Consumption</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- REQUEST RESTOCK MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="requestRestockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-box-arrow-up-right me-2"></i>Request Restock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="modal-body">
                    <input type="hidden" name="request_restock" value="1" />
                    
                    <div class="mb-3">
                        <label class="form-label">Item *</label>
                        <select class="form-select" name="item_id" required>
                            <option value="">Select Item...</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?php echo $item['item_id']; ?>">
                                    <?php echo htmlspecialchars($item['item_name']); ?> 
                                    (Stock: <?php echo $item['current_stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="text" class="form-control" id="currentStockDisplay" disabled />
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Requested Quantity *</label>
                        <input type="number" class="form-control" name="requested_quantity" value="50" min="1" required />
                    </div>
                    
                    <div class="mb-0">
                        <label class="form-label">Reason</label>
                        <textarea class="form-control" name="reason" rows="2" placeholder="Why do you need this restock?">Low stock due to increasing patient cases.</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-save"><i class="bi bi-send me-2"></i>Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filter table
function filterTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('suppliesTable');
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        let found = false;
        for (let j = 0; j < cells.length - 1; j++) {
            const text = cells[j].textContent.toLowerCase();
            if (text.indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        rows[i].style.display = found ? '' : 'none';
    }
}

// Update current stock display when item selected
document.querySelector('select[name="item_id"]')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const stock = selected.text.match(/\(Stock: (\d+)\)/);
    document.getElementById('currentStockDisplay').value = stock ? stock[1] + ' units' : '';
});

// Toast notification
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast-custom' + (type === 'error' ? ' error' : '');
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
    }, 5000);
}

<?php if (isset($success_msg)): ?>
    showToast('<?php echo addslashes($success_msg); ?>', 'success');
<?php endif; ?>
<?php if (isset($error_msg)): ?>
    showToast('<?php echo addslashes($error_msg); ?>', 'error');
<?php endif; ?>
</script>
</body>
</html>