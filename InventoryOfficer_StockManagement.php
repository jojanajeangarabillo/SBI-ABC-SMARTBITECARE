<?php
session_start();

// ============================================
// CONFIGURATION & SECURITY
// ============================================

require_once 'sources/db_connect.php';

// Check if user is logged in and is Inventory Officer (role_id = 5) or Super Admin (role_id = 1)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 5)
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$branch_id = $_SESSION['branch_id'] ?? null;

// If branch_id is not set for Inventory Officer, redirect
if ($role_id == 5 && empty($branch_id)) {
    header("Location: login.php?error=no_branch");
    exit();
}

// ============================================
// AUDIT LOG FUNCTION
// ============================================

function addAuditLog($conn, $user_id, $action, $module = 'Stock Management') {
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
// LOW STOCK CHECK FUNCTION
// ============================================

function checkLowStock($conn, $item_id, $branch_id, $user_id) {
    // Get total stock for the item
    $stock_sql = "SELECT COALESCE(SUM(quantity_available), 0) as total FROM inventory_stocks 
                  WHERE item_id = ? AND branch_id = ? AND is_active = 1";
    $stock_stmt = $conn->prepare($stock_sql);
    $stock_stmt->bind_param("is", $item_id, $branch_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_data = $stock_result->fetch_assoc();
    $stock_stmt->close();
    
    // Get minimum stock threshold
    $min_sql = "SELECT item_name, minimum_stock FROM inventory_items WHERE item_id = ?";
    $min_stmt = $conn->prepare($min_sql);
    $min_stmt->bind_param("i", $item_id);
    $min_stmt->execute();
    $min_result = $min_stmt->get_result();
    $min_data = $min_result->fetch_assoc();
    $min_stmt->close();
    
    if (!$min_data) {
        return;
    }
    
    $total = $stock_data['total'] ?? 0;
    $min = $min_data['minimum_stock'] ?? 0;
    
    // If stock is low, create notification
    if ($total > 0 && $total <= $min) {
        // Check if notification already exists for today
        $notif_check = "SELECT notification_id FROM notifications 
                        WHERE title LIKE '%Low Stock%' AND user_id = ? 
                        AND created_at >= CURDATE()";
        $notif_stmt = $conn->prepare($notif_check);
        $notif_stmt->bind_param("i", $user_id);
        $notif_stmt->execute();
        $notif_result = $notif_stmt->get_result();
        $exists = $notif_result->num_rows > 0;
        $notif_stmt->close();
        
        if (!$exists) {
            $title = "Low Stock Alert: {$min_data['item_name']}";
            $message = "Stock level for {$min_data['item_name']} is at {$total} units. Minimum threshold is {$min} units. Please reorder.";
            
            $notif_sql = "INSERT INTO notifications (user_id, title, message, notification_type, is_read) 
                          VALUES (?, ?, ?, 'inventory', 0)";
            $notif_stmt = $conn->prepare($notif_sql);
            $notif_stmt->bind_param("iss", $user_id, $title, $message);
            $notif_stmt->execute();
            $notif_stmt->close();
        }
    }
}

// ============================================
// FIFO STOCK DEDUCTION FUNCTION
// ============================================

function deductFIFO($conn, $item_id, $branch_id, $quantity, $transaction_type, $remarks = '', $reference_id = null) {
    global $user_id;
    
    // Check if quantity is valid
    if ($quantity <= 0) {
        return ['success' => false, 'error' => 'Quantity must be greater than 0.'];
    }
    
    // Get total available stock
    $total_sql = "SELECT COALESCE(SUM(quantity_available), 0) as total FROM inventory_stocks 
                  WHERE item_id = ? AND branch_id = ? AND is_active = 1 AND quantity_available > 0";
    $total_stmt = $conn->prepare($total_sql);
    $total_stmt->bind_param("is", $item_id, $branch_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_data = $total_result->fetch_assoc();
    $total_stmt->close();
    
    if ($total_data['total'] < $quantity) {
        return ['success' => false, 'error' => 'Insufficient stock. Available: ' . $total_data['total'] . ', Requested: ' . $quantity];
    }
    
    // Get batches ordered by expiration date (FIFO)
    $batch_sql = "SELECT stock_id, quantity_available, expiration_date, batch_number 
                  FROM inventory_stocks 
                  WHERE item_id = ? AND branch_id = ? AND is_active = 1 AND quantity_available > 0
                  ORDER BY expiration_date ASC, stock_id ASC";
    $batch_stmt = $conn->prepare($batch_sql);
    $batch_stmt->bind_param("is", $item_id, $branch_id);
    $batch_stmt->execute();
    $batch_result = $batch_stmt->get_result();
    
    $remaining = $quantity;
    $deducted_batches = [];
    $last_stock_id = null;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        while ($batch = $batch_result->fetch_assoc()) {
            if ($remaining <= 0) break;
            
            $stock_id = $batch['stock_id'];
            $available = $batch['quantity_available'];
            
            if ($available <= 0) continue;
            
            $deduct_amount = min($remaining, $available);
            $new_quantity = $available - $deduct_amount;
            
            // Update stock
            $update_sql = "UPDATE inventory_stocks SET quantity_available = ? WHERE stock_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $new_quantity, $stock_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $deducted_batches[] = [
                'stock_id' => $stock_id,
                'batch_number' => $batch['batch_number'],
                'deducted' => $deduct_amount,
                'new_quantity' => $new_quantity
            ];
            
            $last_stock_id = $stock_id;
            $remaining -= $deduct_amount;
        }
        
        if ($remaining > 0) {
            throw new Exception('Could not deduct full quantity. Remaining: ' . $remaining);
        }
        
        // Create stock transaction
        $trx_sql = "INSERT INTO stock_transactions (item_id, stock_id, user_id, branch_id, transaction_type, quantity, remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
        $trx_stmt = $conn->prepare($trx_sql);
        $trx_stmt->bind_param("iiissss", $item_id, $last_stock_id, $user_id, $branch_id, $transaction_type, $quantity, $remarks);
        $trx_stmt->execute();
        $trx_stmt->close();
        
        // Record in usage history
        $usage_sql = "INSERT INTO inventory_usage_history (item_id, branch_id, usage_date, quantity_used, patient_count) 
                      VALUES (?, ?, CURDATE(), ?, 0)";
        $usage_stmt = $conn->prepare($usage_sql);
        $usage_stmt->bind_param("isi", $item_id, $branch_id, $quantity);
        $usage_stmt->execute();
        $usage_stmt->close();
        
        // Log the action
        $batch_details = array_map(function($b) {
            return "Batch " . ($b['batch_number'] ?? 'N/A') . ": -" . $b['deducted'];
        }, $deducted_batches);
        $action = "Stock OUT: Item ID $item_id, Quantity $quantity, " . implode(', ', $batch_details);
        if ($remarks) $action .= " - Remarks: $remarks";
        addAuditLog($conn, $user_id, $action, 'Stock Management');
        
        $conn->commit();
        return ['success' => true, 'deducted_batches' => $deducted_batches];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// HANDLE STOCK IN
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock_in'])) {
    $item_id = intval($_POST['item_id']);
    $quantity = intval($_POST['quantity']);
    $batch_number = trim($_POST['batch_number'] ?? '');
    $expiration_date = $_POST['expiration_date'] ?? null;
    $received_date = $_POST['received_date'] ?? date('Y-m-d');
    $supplier = trim($_POST['supplier'] ?? '');
    $unit_cost = floatval($_POST['unit_cost'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    
    if ($quantity <= 0 || $item_id <= 0) {
        $error_msg = "Item and quantity are required.";
    } else {
        // Check if item exists
        $item_check = "SELECT item_name FROM inventory_items WHERE item_id = ?";
        $item_stmt = $conn->prepare($item_check);
        $item_stmt->bind_param("i", $item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $item_data = $item_result->fetch_assoc();
        $item_stmt->close();
        
        if (!$item_data) {
            $error_msg = "Item not found.";
        } else {
            // Generate batch number if not provided
            if (empty($batch_number)) {
                $batch_number = 'BATCH-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            
            // Check if batch number already exists for this item and branch
            $check_sql = "SELECT stock_id FROM inventory_stocks 
                          WHERE item_id = ? AND branch_id = ? AND batch_number = ? AND is_active = 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("iss", $item_id, $branch_id, $batch_number);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $exists = $check_result->num_rows > 0;
            $check_stmt->close();
            
            if ($exists) {
                $error_msg = "Batch number '$batch_number' already exists for this item.";
            } else {
                $sql = "INSERT INTO inventory_stocks 
                        (item_id, branch_id, batch_number, quantity_available, expiration_date, received_date, supplier, unit_cost, received_by, remark) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issisdssis", $item_id, $branch_id, $batch_number, $quantity, 
                                 $expiration_date, $received_date, $supplier, $unit_cost, $user_id, $remarks);
                
                if ($stmt->execute()) {
                    $stock_id = $conn->insert_id;
                    
                    // Create stock transaction
                    $trx_sql = "INSERT INTO stock_transactions (item_id, stock_id, user_id, branch_id, transaction_type, quantity, remarks) 
                                VALUES (?, ?, ?, ?, 'IN', ?, ?)";
                    $trx_stmt = $conn->prepare($trx_sql);
                    $trx_stmt->bind_param("iiisss", $item_id, $stock_id, $user_id, $branch_id, $quantity, $remarks);
                    $trx_stmt->execute();
                    $trx_stmt->close();
                    
                    addAuditLog($conn, $user_id, 
                        "Stock IN: Item: {$item_data['item_name']} (ID: $item_id), Batch: $batch_number, Qty: $quantity, Supplier: $supplier");
                    
                    $success_msg = "Stock added successfully! Batch: $batch_number, Qty: $quantity";
                } else {
                    $error_msg = "Error adding stock: " . $conn->error;
                    addAuditLog($conn, $user_id, "Failed Stock IN: " . $conn->error);
                }
                $stmt->close();
            }
        }
    }
}

// ============================================
// HANDLE STOCK OUT
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stock_out'])) {
    $item_id = intval($_POST['item_id']);
    $quantity = intval($_POST['quantity']);
    $reason = trim($_POST['reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    
    if ($quantity <= 0 || $item_id <= 0) {
        $error_msg = "Item and quantity are required.";
    } else {
        $item_check = "SELECT item_name FROM inventory_items WHERE item_id = ?";
        $item_stmt = $conn->prepare($item_check);
        $item_stmt->bind_param("i", $item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $item_data = $item_result->fetch_assoc();
        $item_stmt->close();
        
        if (!$item_data) {
            $error_msg = "Item not found.";
        } else {
            $full_remarks = $reason . ($remarks ? " - $remarks" : "");
            $result = deductFIFO($conn, $item_id, $branch_id, $quantity, 'OUT', $full_remarks);
            
            if ($result['success']) {
                $success_msg = "Stock deducted successfully! Qty: $quantity";
                // Notify if stock is low
                checkLowStock($conn, $item_id, $branch_id, $user_id);
            } else {
                $error_msg = $result['error'];
            }
        }
    }
}

// ============================================
// HANDLE ADJUSTMENT
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjustment'])) {
    $item_id = intval($_POST['item_id']);
    $new_quantity = intval($_POST['new_quantity']);
    $reason = trim($_POST['adjustment_reason'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    
    if ($new_quantity < 0 || $item_id <= 0) {
        $error_msg = "Item and valid quantity are required.";
    } else {
        // Get current total stock
        $current_sql = "SELECT COALESCE(SUM(quantity_available), 0) as current FROM inventory_stocks 
                        WHERE item_id = ? AND branch_id = ? AND is_active = 1";
        $current_stmt = $conn->prepare($current_sql);
        $current_stmt->bind_param("is", $item_id, $branch_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        $current_data = $current_result->fetch_assoc();
        $current_stmt->close();
        
        $current_stock = $current_data['current'];
        $difference = $new_quantity - $current_stock;
        
        if ($difference == 0) {
            $error_msg = "New quantity is the same as current stock. No adjustment needed.";
        } else {
            $item_check = "SELECT item_name FROM inventory_items WHERE item_id = ?";
            $item_stmt = $conn->prepare($item_check);
            $item_stmt->bind_param("i", $item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item_data = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if (!$item_data) {
                $error_msg = "Item not found.";
            } else {
                if ($difference > 0) {
                    // Positive adjustment - add stock to oldest batch or create new
                    $batch_number = 'ADJ-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    
                    $sql = "INSERT INTO inventory_stocks 
                            (item_id, branch_id, batch_number, quantity_available, received_date, received_by, remark) 
                            VALUES (?, ?, ?, ?, CURDATE(), ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("issiis", $item_id, $branch_id, $batch_number, $difference, $user_id, $remarks);
                    
                    if ($stmt->execute()) {
                        $stock_id = $conn->insert_id;
                        
                        $trx_sql = "INSERT INTO stock_transactions (item_id, stock_id, user_id, branch_id, transaction_type, quantity, remarks) 
                                    VALUES (?, ?, ?, ?, 'ADJUSTMENT', ?, ?)";
                        $trx_stmt = $conn->prepare($trx_sql);
                        $trx_stmt->bind_param("iiisis", $item_id, $stock_id, $user_id, $branch_id, $difference, $remarks);
                        $trx_stmt->execute();
                        $trx_stmt->close();
                        
                        addAuditLog($conn, $user_id, 
                            "Adjustment (+{$difference}): Item: {$item_data['item_name']} (ID: $item_id), Reason: $reason");
                        $success_msg = "Stock adjusted successfully! Added +{$difference} units.";
                    } else {
                        $error_msg = "Error adjusting stock: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    // Negative adjustment - deduct FIFO
                    $deduct_amount = abs($difference);
                    $full_remarks = "Adjustment: $reason" . ($remarks ? " - $remarks" : "");
                    $result = deductFIFO($conn, $item_id, $branch_id, $deduct_amount, 'ADJUSTMENT', $full_remarks);
                    
                    if ($result['success']) {
                        $success_msg = "Stock adjusted successfully! Deducted $deduct_amount units. Reason: $reason";
                    } else {
                        $error_msg = $result['error'];
                    }
                }
            }
        }
    }
}

// ============================================
// GET EXPIRING STOCK
// ============================================

$expiry_filter = isset($_GET['expiry_filter']) ? $_GET['expiry_filter'] : '30';
$expiring_stock = [];

// Calculate date range
$today = date('Y-m-d');
if ($expiry_filter == 'expired') {
    $expiry_condition = "expiration_date < CURDATE()";
    $status_label = 'Expired';
} else {
    $days = intval($expiry_filter);
    $expiry_condition = "expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)";
    $status_label = 'Expiring Soon';
}

$expiry_sql = "SELECT 
                s.stock_id,
                s.batch_number,
                s.quantity_available,
                s.expiration_date,
                s.supplier,
                i.item_name,
                i.minimum_stock,
                DATEDIFF(s.expiration_date, CURDATE()) as days_remaining
              FROM inventory_stocks s
              JOIN inventory_items i ON s.item_id = i.item_id
              WHERE s.branch_id = ? 
                AND s.is_active = 1 
                AND s.quantity_available > 0
                AND " . $expiry_condition . "
              ORDER BY s.expiration_date ASC";

$expiry_stmt = $conn->prepare($expiry_sql);
$expiry_stmt->bind_param("s", $branch_id);
$expiry_stmt->execute();
$expiry_result = $expiry_stmt->get_result();

while ($row = $expiry_result->fetch_assoc()) {
    $days = $row['days_remaining'];
    if ($days < 0) {
        $status_text = 'Expired';
        $status_class = 'badge-critical';
    } elseif ($days <= 7) {
        $status_text = 'Expiring Soon (7 days)';
        $status_class = 'badge-critical';
    } elseif ($days <= 30) {
        $status_text = 'Expiring Soon';
        $status_class = 'badge-low';
    } else {
        $status_text = 'OK';
        $status_class = 'badge-instock';
    }
    
    $expiring_stock[] = [
        'item' => $row['item_name'],
        'batch' => $row['batch_number'] ?? 'N/A',
        'stock' => $row['quantity_available'] . ' units',
        'expiry' => date('m/d/Y', strtotime($row['expiration_date'])),
        'days' => $days < 0 ? 'Expired' : ($days . ' days'),
        'status' => $status_text,
        'status_class' => $status_class,
        'stock_id' => $row['stock_id']
    ];
}
$expiry_stmt->close();

// ============================================
// GET ITEMS FOR DROPDOWN
// ============================================

$items_dropdown_sql = "SELECT i.item_id, i.item_name, u.unit_name
                       FROM inventory_items i
                       JOIN units u ON i.unit_id = u.unit_id
                       ORDER BY i.item_name ASC";
$items_dropdown_result = $conn->query($items_dropdown_sql);
$dropdown_items = [];
while ($row = $items_dropdown_result->fetch_assoc()) {
    $dropdown_items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock Management</title>
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

        .tab-row {
            display: flex;
            gap: 12px;
            margin-bottom: 26px;
            flex-wrap: wrap;
        }

        .tab-btn {
            background: white;
            border: 1px solid var(--primary);
            color: var(--primary);
            font-weight: 600;
            font-size: 14px;
            padding: 10px 22px;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.15s;
        }

        .tab-btn:hover {
            background: #eef0f7;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
        }

        .form-card {
            background: #ECEEF7;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 3px 8px rgba(0,0,0,.08);
        }

        .form-card label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            margin-bottom: 6px;
        }

        .form-card .form-control,
        .form-card .form-select {
            border-radius: 10px;
            border: 1px solid #dcdee8;
            padding: 10px 14px;
            font-size: 14px;
            background: white;
        }

        .form-card .form-control:focus,
        .form-card .form-select:focus {
            border-color: var(--primary);
            box-shadow: none;
        }

        .form-card .form-control[readonly] {
            background: #eef0f7;
            color: #666;
        }

        .btn-custom {
            background: var(--primary);
            color: white;
            border-radius: 8px;
            padding: 12px 20px;
            border: none;
            font-weight: 600;
            font-size: 15px;
            width: 100%;
            transition: 0.15s;
        }

        .btn-custom:hover {
            background: #1d2863;
            color: white;
        }

        .btn-custom-sm {
            padding: 8px 16px;
            font-size: 13px;
            width: auto;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 22px;
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

        .badge-low {
            background: #FFEAEA;
            color: var(--accent);
        }

        .badge-critical {
            background: var(--accent);
            color: white;
        }

        .badge-instock {
            background: #E6F4EA;
            color: #1E7B34;
        }

        .action-btn {
            border: 1px solid #dcdee8;
            background: white;
            color: var(--primary);
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.15s;
        }

        .action-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .filter-select {
            max-width: 260px;
            border-radius: 10px;
            border: 1px solid #dcdee8;
            font-size: 14px;
            padding: 10px 14px;
        }

        .panel {
            display: none;
        }

        .panel.active {
            display: block;
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
            .tab-btn {
                padding: 8px 14px;
                font-size: 12px;
            }
            .form-card {
                padding: 20px;
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
            <li><a href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
            <li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
            <li><a class="active" href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
            <li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
            <li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
            <li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">
    <div class="topbar">
        <h3>Stock Management</h3>
        <div class="profile">INVENTORY <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="page-body">
        <div class="tab-row">
            <button class="tab-btn active" onclick="showPanel('stockIn', this)">Stock In</button>
            <button class="tab-btn" onclick="showPanel('stockOut', this)">Stock Out</button>
            <button class="tab-btn" onclick="showPanel('adjustment', this)">Adjustment</button>
            <button class="tab-btn" onclick="showPanel('expiration', this)">Expiration Monitoring</button>
        </div>

        <!-- ============================================ -->
        <!-- STOCK IN -->
        <!-- ============================================ -->
        <div class="panel active" id="stockIn">
            <div class="form-card">
                <div class="section-title">Record Stock In</div>
                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label>Item *</label>
                            <select class="form-select" name="item_id" required>
                                <option value="">Select item...</option>
                                <?php foreach ($dropdown_items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Quantity *</label>
                            <input type="number" class="form-control" name="quantity" placeholder="Enter quantity..." required min="1">
                        </div>
                        <div class="col-md-6">
                            <label>Batch / Lot Number</label>
                            <input type="text" class="form-control" name="batch_number" placeholder="Enter batch number (auto-generated if empty)">
                        </div>
                        <div class="col-md-6">
                            <label>Expiration Date</label>
                            <input type="date" class="form-control" name="expiration_date">
                        </div>
                        <div class="col-md-6">
                            <label>Received Date</label>
                            <input type="date" class="form-control" name="received_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label>Supplier</label>
                            <input type="text" class="form-control" name="supplier" placeholder="Enter supplier name">
                        </div>
                        <div class="col-md-6">
                            <label>Unit Cost</label>
                            <input type="number" step="0.01" class="form-control" name="unit_cost" placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label>Remarks</label>
                            <textarea class="form-control" name="remarks" rows="1" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" name="stock_in" class="btn-custom">Save Stock In</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- STOCK OUT -->
        <!-- ============================================ -->
        <div class="panel" id="stockOut">
            <div class="form-card">
                <div class="section-title">Record Stock Out</div>
                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label>Item *</label>
                            <select class="form-select" name="item_id" required>
                                <option value="">Select item...</option>
                                <?php foreach ($dropdown_items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Quantity *</label>
                            <input type="number" class="form-control" name="quantity" placeholder="Enter quantity..." required min="1">
                        </div>
                        <div class="col-md-6">
                            <label>Reason</label>
                            <select class="form-select" name="reason">
                                <option value="Dispensed to Patient">Dispensed to Patient</option>
                                <option value="Damaged">Damaged</option>
                                <option value="Expired">Expired</option>
                                <option value="Lost / Wastage">Lost / Wastage</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Date</label>
                            <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-12">
                            <label>Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" name="stock_out" class="btn-custom">Save Stock Out</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- ADJUSTMENT -->
        <!-- ============================================ -->
        <div class="panel" id="adjustment">
            <div class="form-card">
                <div class="section-title">Record Stock Adjustment</div>
                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label>Item *</label>
                            <select class="form-select" name="item_id" required id="adjustmentItem" onchange="updateCurrentStock()">
                                <option value="">Select item...</option>
                                <?php foreach ($dropdown_items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Current Stock</label>
                            <input type="text" class="form-control" id="currentStockDisplay" value="0" readonly>
                        </div>
                        <div class="col-md-6">
                            <label>New Quantity *</label>
                            <input type="number" class="form-control" name="new_quantity" placeholder="Enter new quantity..." required min="0">
                            <div class="form-text">Enter the total quantity you want the stock to be after adjustment.</div>
                        </div>
                        <div class="col-md-6">
                            <label>Reason for Adjustment</label>
                            <select class="form-select" name="adjustment_reason">
                                <option value="Miscount / Physical Count Correction">Miscount / Physical Count Correction</option>
                                <option value="Damaged">Damaged</option>
                                <option value="Expired">Expired</option>
                                <option value="System Correction">System Correction</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Date</label>
                            <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label>Remarks</label>
                            <textarea class="form-control" name="remarks" rows="1" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" name="adjustment" class="btn-custom">Save Adjustment</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- EXPIRATION MONITORING -->
        <!-- ============================================ -->
        <div class="panel" id="expiration">
            <div class="form-card" style="background:transparent; padding:0; box-shadow:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div class="section-title mb-0">Expiration Monitoring</div>
                    <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="d-flex gap-2">
                        <select class="form-select filter-select" name="expiry_filter" onchange="this.form.submit()">
                            <option value="7" <?php echo $expiry_filter == '7' ? 'selected' : ''; ?>>Expiring within 7 days</option>
                            <option value="30" <?php echo $expiry_filter == '30' ? 'selected' : ''; ?>>Expiring within 30 days</option>
                            <option value="60" <?php echo $expiry_filter == '60' ? 'selected' : ''; ?>>Expiring within 60 days</option>
                            <option value="expired" <?php echo $expiry_filter == 'expired' ? 'selected' : ''; ?>>Already Expired</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table class="table data-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch / Lot No.</th>
                                <th>Stock</th>
                                <th>Expiration Date</th>
                                <th>Days Remaining</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($expiring_stock) > 0): ?>
                                <?php foreach ($expiring_stock as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['item']); ?></td>
                                    <td><?php echo htmlspecialchars($row['batch']); ?></td>
                                    <td><?php echo htmlspecialchars($row['stock']); ?></td>
                                    <td><?php echo htmlspecialchars($row['expiry']); ?></td>
                                    <td><?php echo htmlspecialchars($row['days']); ?></td>
                                    <td><span class="badge-status <?php echo $row['status_class']; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-check-circle fs-2 d-block mb-2" style="color:#28a745;"></i>
                                        No expiring or expired stock found for the selected filter.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tab switching
function showPanel(id, btn) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}

// Get current stock for adjustment
function updateCurrentStock() {
    const itemSelect = document.getElementById('adjustmentItem');
    const itemId = itemSelect.value;
    const display = document.getElementById('currentStockDisplay');
    
    if (!itemId) {
        display.value = '0';
        return;
    }
    
    fetch('get_stock.php?item_id=' + itemId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                display.value = data.stock + ' units';
            } else {
                display.value = 'Error loading stock';
            }
        })
        .catch(() => {
            display.value = 'Error loading stock';
        });
}

// Toast notification function
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

// Auto-show toast for PHP messages
<?php if (isset($success_msg)): ?>
    showToast('<?php echo addslashes($success_msg); ?>', 'success');
<?php endif; ?>
<?php if (isset($error_msg)): ?>
    showToast('<?php echo addslashes($error_msg); ?>', 'error');
<?php endif; ?>
</script>
</body>
</html>