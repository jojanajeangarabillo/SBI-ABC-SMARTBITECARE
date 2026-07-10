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

function addAuditLog($conn, $user_id, $action, $module = 'Prediction Module') {
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
// HANDLE TRAINING DATASET IMPORT
// ============================================

$import_success = false;
$import_message = '';
$imported_count = 0;
$error_count = 0;
$error_details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_dataset'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if ($file_ext !== 'csv') {
            $import_message = 'Please upload a CSV file.';
        } else {
            $handle = fopen($file_tmp, 'r');
            if ($handle === false) {
                $import_message = 'Failed to open file.';
            } else {
                // Read header
                $header = fgetcsv($handle);
                if ($header === false) {
                    $import_message = 'Empty file or invalid CSV format.';
                } else {
                    // Expected columns
                    $expected_headers = ['record_date', 'branch_name', 'total_patient_tally', 'item_name', 'beginning_stock', 'quantity_used', 'stock_received', 'ending_stock'];
                    
                    // Normalize header (trim, lower case)
                    $normalized_header = array_map(function($h) {
                        return strtolower(trim(str_replace(' ', '_', $h)));
                    }, $header);
                    
                    // Check if headers match expected
                    $missing_headers = array_diff($expected_headers, $normalized_header);
                    if (!empty($missing_headers)) {
                        $import_message = 'Missing required columns: ' . implode(', ', $missing_headers);
                    } else {
                        // Map column indices
                        $col_map = [];
                        foreach ($expected_headers as $col) {
                            $col_map[$col] = array_search($col, $normalized_header);
                        }
                        
                        // Delete existing training data for this branch
                        $del_stmt = $conn->prepare("DELETE FROM training_dataset WHERE branch_id = ?");
                        $del_stmt->bind_param("s", $branch_id);
                        $del_stmt->execute();
                        $del_stmt->close();
                        
                        // Prepare insert statement
                        $insert_sql = "INSERT INTO training_dataset 
                                       (item_id, branch_id, record_date, current_stock, quantity_used, stock_received, patient_count, low_stock_target) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        
                        if (!$insert_stmt) {
                            $import_message = 'Database prepare error: ' . $conn->error;
                        } else {
                            $conn->begin_transaction();
                            
                            while (($row = fgetcsv($handle)) !== false) {
                                // Skip empty rows
                                if (empty(array_filter($row))) continue;
                                
                                // Get values
                                $record_date = trim($row[$col_map['record_date']] ?? '');
                                $csv_branch_name = trim($row[$col_map['branch_name']] ?? '');
                                $patient_count = (int)($row[$col_map['total_patient_tally']] ?? 0);
                                $item_name = trim($row[$col_map['item_name']] ?? '');
                                $beginning_stock = (float)($row[$col_map['beginning_stock']] ?? 0);
                                $quantity_used = (float)($row[$col_map['quantity_used']] ?? 0);
                                $stock_received = (float)($row[$col_map['stock_received']] ?? 0);
                                $ending_stock = (float)($row[$col_map['ending_stock']] ?? 0);
                                
                                // Validate required fields
                                if (empty($record_date) || empty($item_name) || $patient_count <= 0) {
                                    $error_count++;
                                    $error_details[] = "Skipped row: Missing required data";
                                    continue;
                                }
                                
                                // Convert date format (dd/mm/yyyy to yyyy-mm-dd)
                                $date_parts = explode('/', $record_date);
                                if (count($date_parts) === 3) {
                                    $record_date_formatted = $date_parts[2] . '-' . str_pad($date_parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($date_parts[0], 2, '0', STR_PAD_LEFT);
                                } else {
                                    $record_date_formatted = date('Y-m-d', strtotime($record_date));
                                    if (!$record_date_formatted) {
                                        $error_count++;
                                        $error_details[] = "Invalid date format: $record_date";
                                        continue;
                                    }
                                }
                                
                                // Find item_id by item_name
                                $item_sql = "SELECT item_id FROM inventory_items WHERE item_name = ?";
                                $item_stmt = $conn->prepare($item_sql);
                                $item_stmt->bind_param("s", $item_name);
                                $item_stmt->execute();
                                $item_result = $item_stmt->get_result();
                                $item_row = $item_result->fetch_assoc();
                                $item_stmt->close();
                                
                                if (!$item_row) {
                                    $error_count++;
                                    $error_details[] = "Item '$item_name' not found in inventory";
                                    continue;
                                }
                                
                                $item_id = (int)$item_row['item_id'];
                                
                                // Check if item is predictable
                                $predict_check = "SELECT is_predictable FROM inventory_items WHERE item_id = ?";
                                $predict_stmt = $conn->prepare($predict_check);
                                $predict_stmt->bind_param("i", $item_id);
                                $predict_stmt->execute();
                                $predict_result = $predict_stmt->get_result();
                                $predict_row = $predict_result->fetch_assoc();
                                $predict_stmt->close();
                                
                                if (!$predict_row || $predict_row['is_predictable'] != 1) {
                                    // Skip non-predictable items
                                    continue;
                                }
                                
                                // Calculate low_stock_target
                                $low_stock_target = 0;
                                if ($beginning_stock > 0) {
                                    $threshold = $beginning_stock * 0.2;
                                    if ($ending_stock <= $threshold) {
                                        $low_stock_target = 1;
                                    }
                                }
                                
                                // FIXED: Store values in variables before binding
                                $record_date_val = $record_date_formatted;
                                $current_stock_val = (int)$beginning_stock;
                                $quantity_used_val = (int)$quantity_used;
                                $stock_received_val = (int)$stock_received;
                                $patient_count_val = $patient_count;
                                $low_stock_target_val = $low_stock_target;
                                
                                $insert_stmt->bind_param(
                                    "isssiiii",
                                    $item_id,
                                    $branch_id,
                                    $record_date_val,
                                    $current_stock_val,
                                    $quantity_used_val,
                                    $stock_received_val,
                                    $patient_count_val,
                                    $low_stock_target_val
                                );
                                
                                if ($insert_stmt->execute()) {
                                    $imported_count++;
                                } else {
                                    $error_count++;
                                    $error_details[] = "DB Error for $item_name: " . $insert_stmt->error;
                                }
                            }
                            
                            $insert_stmt->close();
                            fclose($handle);
                            
                            if ($error_count > 0 && $imported_count == 0) {
                                $conn->rollback();
                                $import_message = "Import failed! " . implode("; ", array_slice($error_details, 0, 5));
                            } else {
                                $conn->commit();
                                $import_success = true;
                                $import_message = "Dataset imported successfully! $imported_count records inserted.";
                                if ($error_count > 0) {
                                    $import_message .= " ($error_count rows skipped due to errors)";
                                }
                                addAuditLog($conn, $user_id, "Imported training dataset: $imported_count records", 'Prediction Module');
                            }
                        }
                    }
                }
            }
        }
    } else {
        $import_message = 'Please select a CSV file to upload.';
    }
}

// ============================================
// HANDLE PREDICTION GENERATION
// ============================================

$prediction_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_prediction'])) {
    $forecast_days = (int)$_POST['forecast_days'];
    $confidence_threshold = (float)$_POST['confidence_threshold'];
    
    // Check if training data exists
    $check_training = "SELECT COUNT(*) as count FROM training_dataset WHERE branch_id = ?";
    $check_stmt = $conn->prepare($check_training);
    $check_stmt->bind_param("s", $branch_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($check_data['count'] < 10) {
        $prediction_message = "Not enough training data. Need at least 10 records per item. Currently have {$check_data['count']}.";
    } else {
        // Call Python prediction script
        $python_script = __DIR__ . '/predict.py';
        $python_command = "python3 " . escapeshellarg($python_script) . " " . escapeshellarg($branch_id) . " " . escapeshellarg($forecast_days) . " 2>&1";
        
        $output = shell_exec($python_command);
        $result = json_decode($output, true);
        
        if ($result && isset($result['success']) && $result['success']) {
            // Save prediction results to database
            $conn->begin_transaction();
            
            // Delete old predictions for this branch
            $del_pred = $conn->prepare("DELETE FROM prediction_results WHERE branch_id = ?");
            $del_pred->bind_param("s", $branch_id);
            $del_pred->execute();
            $del_pred->close();
            
            $insert_pred = $conn->prepare("
                INSERT INTO prediction_results 
                (item_id, branch_id, prediction_date, probability_score, prediction_status, recommended_reorder, generated_by, predicted_consumption, forecast_days) 
                VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)
            ");
            
            $prediction_count = 0;
            foreach ($result['predictions'] as $pred) {
                // Get item_id by name
                $item_sql = "SELECT item_id FROM inventory_items WHERE item_name = ? AND is_predictable = 1";
                $item_stmt = $conn->prepare($item_sql);
                $item_stmt->bind_param("s", $pred['item_name']);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item_row = $item_result->fetch_assoc();
                $item_stmt->close();
                
                if ($item_row) {
                    $item_id = (int)$item_row['item_id'];
                    $status = $pred['probability_score'] >= $confidence_threshold ? 'Likely Shortage' : 'Sufficient Stock';
                    $recommended_reorder = $pred['probability_score'] >= $confidence_threshold ? (int)$pred['predicted_consumption'] : 0;
                    
                    // FIXED: Store all values in variables before binding
                    $prob_score = (float)$pred['probability_score'];
                    $pred_status = $status;
                    $reorder_qty = $recommended_reorder;
                    $gen_by = $user_id;
                    $pred_consumption = (int)$pred['predicted_consumption'];
                    $forecast = $forecast_days;
                    
                    $insert_pred->bind_param(
                        "isdiissi",
                        $item_id,
                        $branch_id,
                        $prob_score,
                        $pred_status,
                        $reorder_qty,
                        $gen_by,
                        $pred_consumption,
                        $forecast
                    );
                    $insert_pred->execute();
                    $prediction_count++;
                }
            }
            $insert_pred->close();
            
            $conn->commit();
            addAuditLog($conn, $user_id, "Generated predictions for $prediction_count items (Forecast: $forecast_days days)", 'Prediction Module');
            $prediction_message = "Predictions generated successfully for $prediction_count items!";
        } else {
            $prediction_message = "Error generating predictions: " . ($result['error'] ?? $output);
        }
    }
}

// ============================================
// GET PREDICTION RESULTS
// ============================================

$predictions = [];
$pred_sql = "SELECT 
                p.*,
                i.item_name,
                i.minimum_stock,
                u.unit_name
             FROM prediction_results p
             JOIN inventory_items i ON p.item_id = i.item_id
             LEFT JOIN units u ON i.unit_id = u.unit_id
             WHERE p.branch_id = ?
             ORDER BY p.probability_score DESC";

$pred_stmt = $conn->prepare($pred_sql);
$pred_stmt->bind_param("s", $branch_id);
$pred_stmt->execute();
$pred_result = $pred_stmt->get_result();

while ($row = $pred_result->fetch_assoc()) {
    // Determine status color
    if ($row['probability_score'] >= 0.8) {
        $status_color = 'danger';
    } elseif ($row['probability_score'] >= 0.6) {
        $status_color = 'warning';
    } else {
        $status_color = 'success';
    }
    
    $predictions[] = [
        'item_name' => $row['item_name'],
        'unit_name' => $row['unit_name'],
        'probability_score' => (float)$row['probability_score'],
        'prediction_status' => $row['prediction_status'],
        'status_color' => $status_color,
        'recommended_reorder' => (int)$row['recommended_reorder'],
        'predicted_consumption' => (int)$row['predicted_consumption'],
        'forecast_days' => (int)$row['forecast_days'],
        'minimum_stock' => (int)$row['minimum_stock'],
        'prediction_date' => date('m/d/Y', strtotime($row['prediction_date']))
    ];
}
$pred_stmt->close();

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
// GET PREDICTABLE ITEMS
// ============================================

$predictable_items = [];
$items_sql = "SELECT i.item_id, i.item_name, u.unit_name, i.minimum_stock
              FROM inventory_items i
              LEFT JOIN units u ON i.unit_id = u.unit_id
              WHERE i.is_predictable = 1
              ORDER BY i.item_name";
$items_result = $conn->query($items_sql);
while ($row = $items_result->fetch_assoc()) {
    $predictable_items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prediction Module - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
        }

        body {
            background: white;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }

        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
        }

        .page-body {
            padding: 35px;
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

        .card-stats {
            background: white;
            border-radius: 12px;
            border: 1px solid #dfe1ee;
            padding: 20px;
            height: 100%;
        }

        .card-stats .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
        }

        .card-stats .stat-label {
            font-size: 13px;
            color: #6c7a9a;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .btn-custom {
            background: var(--primary);
            color: white;
            border-radius: 8px;
            padding: 10px 20px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
            transition: 0.15s;
        }

        .btn-custom:hover {
            background: #1d2863;
            color: white;
        }

        .btn-outline-custom {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 8px;
            padding: 9px 19px;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
        }

        .btn-outline-custom:hover {
            background: var(--primary);
            color: white;
        }

        .btn-accent {
            background: var(--accent);
            color: white;
            border-radius: 8px;
            padding: 10px 20px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
        }

        .btn-accent:hover {
            background: #c41828;
            color: white;
        }

        .table-wrap {
            background: white;
            border-radius: 12px;
            border: 1px solid #dfe1ee;
            overflow: hidden;
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

        .upload-area {
            border: 2px dashed #dcdee8;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            transition: 0.2s;
            background: #fafbfc;
            cursor: pointer;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: #f7f8fc;
        }

        .upload-area.dragover {
            border-color: var(--primary);
            background: #eef2ff;
        }

        .upload-area i {
            font-size: 48px;
            color: #9aa0c3;
            margin-bottom: 12px;
        }

        .upload-area .upload-text {
            font-weight: 600;
            color: #1f2a4a;
        }

        .upload-area .upload-subtext {
            font-size: 13px;
            color: #6c7a9a;
        }

        .file-info {
            display: none;
            background: #f8f9fc;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
        }

        .file-info.show {
            display: block;
        }

        .file-info .file-name {
            font-weight: 600;
            color: var(--primary);
        }

        .file-info .file-size {
            color: #6c7a9a;
            font-size: 13px;
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
            <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>
            <li><a class="active" href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>
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
        <h3>Prediction Module</h3>
        <div class="profile">
            <?php echo htmlspecialchars($username); ?> 
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

    <div class="page-body">
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card-stats">
                    <div class="stat-number"><?php echo number_format($training_stats['total_records'] ?? 0); ?></div>
                    <div class="stat-label">Total Training Records</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card-stats">
                    <div class="stat-number"><?php echo number_format($training_stats['item_count'] ?? 0); ?></div>
                    <div class="stat-label">Items with Data</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card-stats">
                    <div class="stat-number"><?php echo count($predictions); ?></div>
                    <div class="stat-label">Current Predictions</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card-stats">
                    <div class="stat-number"><?php echo count($predictable_items); ?></div>
                    <div class="stat-label">Predictable Items</div>
                </div>
            </div>
        </div>

        <!-- Upload Dataset Section -->
        <div class="card mb-4" style="border-radius:12px;border:1px solid #dfe1ee;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                    <h5 class="fw-bold mb-0" style="color:var(--primary);">
                        <i class="bi bi-upload me-2"></i>Upload Training Dataset
                    </h5>
                    <div>
                        <a href="#" class="btn btn-sm btn-outline-secondary me-2" onclick="downloadSampleCSV(); return false;">
                            <i class="bi bi-download"></i> Download Sample CSV
                        </a>
                        <span class="text-muted" style="font-size:12px;">
                            <i class="bi bi-info-circle"></i> Only predictable items will be imported
                        </span>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="upload-area" id="dropZone">
                        <i class="bi bi-cloud-upload"></i>
                        <div class="upload-text">Drop your CSV file here or click to browse</div>
                        <div class="upload-subtext">Supported format: .csv</div>
                        <input type="file" name="csv_file" id="csvFile" accept=".csv" style="display:none;">
                    </div>
                    <div class="file-info" id="fileInfo">
                        <div>
                            <i class="bi bi-file-earmark-text me-2"></i>
                            <span class="file-name" id="fileName">No file selected</span>
                            <span class="file-size" id="fileSize"></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="removeFileBtn">
                            <i class="bi bi-x"></i> Remove
                        </button>
                    </div>
                    <div class="mt-3">
                        <button type="submit" name="import_dataset" class="btn-custom" id="uploadBtn">
                            <i class="bi bi-upload"></i> Import Dataset
                        </button>
                        <span class="text-muted ms-2" style="font-size:12px;">
                            <i class="bi bi-exclamation-triangle"></i> This will replace all existing training data
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Generate Predictions Section -->
        <div class="card mb-4" style="border-radius:12px;border:1px solid #dfe1ee;">
            <div class="card-body">
                <h5 class="fw-bold" style="color:var(--primary);">
                    <i class="bi bi-magic me-2"></i>Generate Predictions
                </h5>
                <p class="text-muted mb-3" style="font-size:14px;">
                    Uses machine learning to predict which critical vaccines/medicines may face shortages.
                    Requires at least 10 training records per item.
                </p>
                
                <form method="POST" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="fw-semibold" style="font-size:13px;">Forecast Days</label>
                        <select class="form-select" name="forecast_days">
                            <option value="7">7 Days</option>
                            <option value="14">14 Days</option>
                            <option value="30" selected>30 Days</option>
                            <option value="60">60 Days</option>
                            <option value="90">90 Days</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-semibold" style="font-size:13px;">Confidence Threshold</label>
                        <select class="form-select" name="confidence_threshold">
                            <option value="0.5">50%</option>
                            <option value="0.6" selected>60%</option>
                            <option value="0.7">70%</option>
                            <option value="0.8">80%</option>
                            <option value="0.9">90%</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" name="generate_prediction" class="btn-accent w-100">
                            <i class="bi bi-graph-up-arrow"></i> Generate Predictions
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Predictions Table -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0" style="color:var(--primary);">
                <i class="bi bi-clipboard-data me-2"></i>Prediction Results
            </h5>
            <span class="text-muted" style="font-size:13px;">
                <?php echo count($predictions); ?> predictions found
            </span>
        </div>

        <div class="table-wrap">
            <table class="table data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th>Min Stock</th>
                        <th>Predicted Consumption</th>
                        <th>Probability Score</th>
                        <th>Status</th>
                        <th>Recommendation</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($predictions) > 0): ?>
                        <?php foreach ($predictions as $pred): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($pred['item_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($pred['unit_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pred['minimum_stock']); ?></td>
                                <td><?php echo htmlspecialchars($pred['predicted_consumption'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php echo number_format($pred['probability_score'] * 100, 1); ?>%
                                    <div class="probability-bar">
                                        <div class="fill <?php 
                                            echo $pred['probability_score'] >= 0.7 ? 'fill-high' : 
                                                ($pred['probability_score'] >= 0.5 ? 'fill-medium' : 'fill-low'); 
                                        ?>" 
                                             style="width: <?php echo $pred['probability_score'] * 100; ?>%;">
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-status <?php 
                                        echo $pred['status_color'] == 'danger' ? 'badge-danger' : 
                                            ($pred['status_color'] == 'warning' ? 'badge-warning' : 'badge-success'); 
                                    ?>">
                                        <?php echo htmlspecialchars($pred['prediction_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($pred['recommended_reorder'] > 0): ?>
                                        <span class="badge bg-warning text-dark">
                                            Reorder <?php echo htmlspecialchars($pred['recommended_reorder']); ?> units
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">No action needed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($pred['prediction_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No predictions available. Upload training data and generate predictions.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Predictable Items List -->
        <div class="mt-4">
            <h6 class="fw-bold text-muted" style="font-size:13px;text-transform:uppercase;letter-spacing:0.3px;">
                <i class="bi bi-list-check me-2"></i>Predictable Items in Inventory
            </h6>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($predictable_items as $item): ?>
                    <span class="badge bg-light text-dark border" style="padding:6px 14px;font-weight:600;">
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <span class="text-muted ms-1" style="font-weight:400;">
                            (<?php echo htmlspecialchars($item['unit_name'] ?? 'N/A'); ?>)
                        </span>
                    </span>
                <?php endforeach; ?>
                <?php if (empty($predictable_items)): ?>
                    <span class="text-muted">No predictable items found. Add items with is_predictable=1.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================
// FILE UPLOAD HANDLING
// ============================================

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('csvFile');
const fileInfo = document.getElementById('fileInfo');
const fileName = document.getElementById('fileName');
const fileSize = document.getElementById('fileSize');
const removeFileBtn = document.getElementById('removeFileBtn');

// Click to browse
dropZone.addEventListener('click', () => fileInput.click());

// File selected
fileInput.addEventListener('change', function() {
    if (this.files.length > 0) {
        const file = this.files[0];
        fileName.textContent = file.name;
        fileSize.textContent = `(${(file.size / 1024).toFixed(1)} KB)`;
        fileInfo.classList.add('show');
    }
});

// Remove file
removeFileBtn.addEventListener('click', function() {
    fileInput.value = '';
    fileInfo.classList.remove('show');
});

// Drag and drop
dropZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

dropZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        const file = files[0];
        if (file.name.endsWith('.csv')) {
            fileInput.files = files;
            fileName.textContent = file.name;
            fileSize.textContent = `(${(file.size / 1024).toFixed(1)} KB)`;
            fileInfo.classList.add('show');
        } else {
            showToast('Please upload a CSV file.', 'error');
        }
    }
});

// ============================================
// DOWNLOAD SAMPLE CSV
// ============================================

function downloadSampleCSV() {
    const headers = ['record_date', 'branch_name', 'total_patient_tally', 'item_name', 'beginning_stock', 'quantity_used', 'stock_received', 'ending_stock'];
    const sampleData = [
        ['14/11/2025', 'Cainta', '86', 'SPEEDA', '50', '13.4', '161.2', '197.8'],
        ['15/11/2025', 'Cainta', '17', 'SPEEDA', '197.8', '2.6', '0', '195.2'],
        ['16/11/2025', 'Cainta', '24', 'SPEEDA', '195.2', '4.1', '0', '191.1'],
        ['14/11/2025', 'Cainta', '86', 'ERIG', '45', '3.4', '0', '41.6'],
        ['15/11/2025', 'Cainta', '17', 'ERIG', '41.6', '0.7', '0', '40.9'],
        ['16/11/2025', 'Cainta', '24', 'ERIG', '40.9', '1', '0', '39.9'],
        ['14/11/2025', 'Cainta', '86', 'ATS', '10', '1.7', '0', '8.3'],
        ['15/11/2025', 'Cainta', '17', 'ATS', '8.3', '0.3', '0', '8'],
        ['16/11/2025', 'Cainta', '24', 'ATS', '8', '0.5', '0', '7.5']
    ];
    
    let csvContent = headers.join(',') + '\n';
    sampleData.forEach(row => {
        csvContent += row.join(',') + '\n';
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sample_training_data.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================

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
    }, 8000);
}

// Auto-show toast for PHP messages
<?php if (isset($import_success) && $import_success): ?>
    showToast('<?php echo addslashes($import_message); ?>', 'success');
<?php elseif (isset($import_message) && !empty($import_message)): ?>
    showToast('<?php echo addslashes($import_message); ?>', 'error');
<?php endif; ?>

<?php if (isset($prediction_message) && !empty($prediction_message)): ?>
    <?php if (strpos($prediction_message, 'success') !== false || strpos($prediction_message, 'successfully') !== false): ?>
        showToast('<?php echo addslashes($prediction_message); ?>', 'success');
    <?php else: ?>
        showToast('<?php echo addslashes($prediction_message); ?>', 'error');
    <?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>