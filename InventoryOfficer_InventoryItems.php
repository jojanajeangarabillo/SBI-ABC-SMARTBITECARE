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

function addAuditLog($conn, $user_id, $action, $module = 'Inventory Items') {
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
// HANDLE ITEM CRUD OPERATIONS
// ============================================

// Handle Add Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $category_id = intval($_POST['category_id']);
    $unit_id = intval($_POST['unit_id']);
    $item_name = trim($_POST['item_name']);
    $minimum_stock = intval($_POST['minimum_stock'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_predictable = isset($_POST['is_predictable']) ? 1 : 0;
    
    if (empty($item_name) || $category_id <= 0 || $unit_id <= 0) {
        $error_msg = "Item name, category, and unit are required.";
    } else {
        // Check if item already exists
        $check_sql = "SELECT item_id FROM inventory_items WHERE item_name = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $item_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Item '$item_name' already exists.";
        } else {
            $sql = "INSERT INTO inventory_items (category_id, unit_id, item_name, minimum_stock, description, is_predictable) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisisi", $category_id, $unit_id, $item_name, $minimum_stock, $description, $is_predictable);
            
            if ($stmt->execute()) {
                $item_id = $conn->insert_id;
                addAuditLog($conn, $_SESSION['user_id'], 
                    "Added new inventory item: $item_name (ID: $item_id), Category: $category_id, Unit: $unit_id");
                $success_msg = "Item added successfully!";
            } else {
                $error_msg = "Error adding item: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to add inventory item: $item_name - " . $conn->error);
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $item_id = intval($_POST['item_id']);
    $category_id = intval($_POST['edit_category_id']);
    $unit_id = intval($_POST['edit_unit_id']);
    $item_name = trim($_POST['edit_item_name']);
    $minimum_stock = intval($_POST['edit_minimum_stock'] ?? 0);
    $description = trim($_POST['edit_description'] ?? '');
    $is_predictable = isset($_POST['edit_is_predictable']) ? 1 : 0;
    
    // Get old data for audit log
    $old_sql = "SELECT item_name, category_id, unit_id, minimum_stock, is_predictable FROM inventory_items WHERE item_id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $item_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    $old_stmt->close();
    
    if (empty($item_name) || $category_id <= 0 || $unit_id <= 0) {
        $error_msg = "Item name, category, and unit are required.";
    } else {
        // Check if name already exists (excluding current item)
        $check_sql = "SELECT item_id FROM inventory_items WHERE item_name = ? AND item_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $item_name, $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Item '$item_name' already exists.";
        } else {
            $sql = "UPDATE inventory_items 
                    SET category_id = ?, unit_id = ?, item_name = ?, minimum_stock = ?, description = ?, is_predictable = ? 
                    WHERE item_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisissi", $category_id, $unit_id, $item_name, $minimum_stock, $description, $is_predictable, $item_id);
            
            if ($stmt->execute()) {
                // Build change description
                $changes = [];
                if ($old_data['item_name'] != $item_name) {
                    $changes[] = "Name: '{$old_data['item_name']}' → '$item_name'";
                }
                if ($old_data['category_id'] != $category_id) {
                    $changes[] = "Category: {$old_data['category_id']} → $category_id";
                }
                if ($old_data['unit_id'] != $unit_id) {
                    $changes[] = "Unit: {$old_data['unit_id']} → $unit_id";
                }
                if ($old_data['minimum_stock'] != $minimum_stock) {
                    $changes[] = "Min Stock: {$old_data['minimum_stock']} → $minimum_stock";
                }
                if ($old_data['is_predictable'] != $is_predictable) {
                    $changes[] = "Predictable: " . ($old_data['is_predictable'] ? 'Yes' : 'No') . " → " . ($is_predictable ? 'Yes' : 'No');
                }
                
                $details = !empty($changes) ? "Changes: " . implode(", ", $changes) : "No changes made";
                addAuditLog($conn, $_SESSION['user_id'], "Updated inventory item: $item_name (ID: $item_id) - $details");
                $success_msg = "Item updated successfully!";
            } else {
                $error_msg = "Error updating item: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to update inventory item: $item_name - " . $conn->error);
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle Delete Item
if (isset($_GET['delete_item_id'])) {
    $item_id = intval($_GET['delete_item_id']);
    
    // Get item details before deletion
    $item_sql = "SELECT item_name FROM inventory_items WHERE item_id = ?";
    $item_stmt = $conn->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $item_data = $item_result->fetch_assoc();
    $item_stmt->close();
    
    if ($item_data) {
        // Check if item has stock
        $check_sql = "SELECT COUNT(*) as count FROM inventory_stocks WHERE item_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($check_data['count'] > 0) {
            $error_msg = "Cannot delete item: It has " . $check_data['count'] . " stock entries.";
        } else {
            $delete_sql = "DELETE FROM inventory_items WHERE item_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $item_id);
            
            if ($delete_stmt->execute()) {
                addAuditLog($conn, $_SESSION['user_id'], "Deleted inventory item: " . $item_data['item_name'] . " (ID: $item_id)");
                $success_msg = "Item deleted successfully!";
            } else {
                $error_msg = "Error deleting item: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to delete inventory item: " . $item_data['item_name'] . " - " . $conn->error);
            }
            $delete_stmt->close();
        }
    } else {
        $error_msg = "Item not found.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ============================================
// GET DATA FROM DATABASE
// ============================================

// Get all items with their categories, units, and stock (branch-specific)
$items_sql = "SELECT 
                i.item_id, 
                i.item_name, 
                i.minimum_stock, 
                i.description, 
                i.is_predictable,
                c.category_id,
                c.category_name,
                u.unit_id,
                u.unit_name,
                COALESCE(SUM(s.quantity_available), 0) as total_stock
              FROM inventory_items i
              LEFT JOIN inventory_categories c ON i.category_id = c.category_id
              LEFT JOIN units u ON i.unit_id = u.unit_id
              LEFT JOIN inventory_stocks s ON i.item_id = s.item_id AND s.branch_id = ? AND s.is_active = 1
              GROUP BY i.item_id
              ORDER BY i.item_name ASC";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("s", $branch_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

$items = [];
while ($row = $items_result->fetch_assoc()) {
    $total_stock = $row['total_stock'];
    
    // Determine status based on stock level
    if ($total_stock <= 0) {
        $status = 'Critical';
        $status_class = 'badge-critical';
    } elseif ($total_stock <= $row['minimum_stock']) {
        $status = 'Low';
        $status_class = 'badge-low';
    } else {
        $status = 'In Stock';
        $status_class = 'badge-instock';
    }
    
    $items[] = [
        'item_id' => $row['item_id'],
        'category' => $row['category_name'],
        'category_id' => $row['category_id'],
        'item_name' => $row['item_name'],
        'unit' => $row['unit_name'],
        'unit_id' => $row['unit_id'],
        'stock' => $total_stock . ' ' . $row['unit_name'],
        'minimum_stock' => $row['minimum_stock'],
        'is_predictable' => $row['is_predictable'],
        'status' => $status,
        'status_class' => $status_class
    ];
}
$items_stmt->close();

// Get all categories for dropdown
$cat_sql = "SELECT category_id, category_name FROM inventory_categories ORDER BY category_name";
$cat_result = $conn->query($cat_sql);
$categories = [];
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// Get all units for dropdown
$unit_sql = "SELECT unit_id, unit_name FROM units ORDER BY unit_name";
$unit_result = $conn->query($unit_sql);
$units = [];
while ($row = $unit_result->fetch_assoc()) {
    $units[] = $row;
}

// Get item for editing
$edit_item = null;
if (isset($_GET['edit_item_id'])) {
    $edit_id = intval($_GET['edit_item_id']);
    $edit_sql = "SELECT * FROM inventory_items WHERE item_id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_item = $edit_result->fetch_assoc();
    $edit_stmt->close();
}

// Get item for viewing
$view_item = null;
if (isset($_GET['view_item_id'])) {
    $view_id = intval($_GET['view_item_id']);
    $view_sql = "SELECT 
                    i.*,
                    c.category_name,
                    u.unit_name,
                    COALESCE(SUM(s.quantity_available), 0) as total_stock,
                    COUNT(s.stock_id) as batch_count
                 FROM inventory_items i
                 LEFT JOIN inventory_categories c ON i.category_id = c.category_id
                 LEFT JOIN units u ON i.unit_id = u.unit_id
                 LEFT JOIN inventory_stocks s ON i.item_id = s.item_id AND s.branch_id = ? AND s.is_active = 1
                 WHERE i.item_id = ?
                 GROUP BY i.item_id";
    $view_stmt = $conn->prepare($view_sql);
    $view_stmt->bind_param("si", $branch_id, $view_id);
    $view_stmt->execute();
    $view_result = $view_stmt->get_result();
    $view_item = $view_result->fetch_assoc();
    $view_stmt->close();
    
    // Get detailed stock batches for this item
    if ($view_item) {
        $batch_sql = "SELECT stock_id, batch_number, quantity_available, expiration_date, received_date, supplier, unit_cost, remark
                      FROM inventory_stocks 
                      WHERE item_id = ? AND branch_id = ? AND is_active = 1
                      ORDER BY expiration_date ASC";
        $batch_stmt = $conn->prepare($batch_sql);
        $batch_stmt->bind_param("is", $view_id, $branch_id);
        $batch_stmt->execute();
        $batch_result = $batch_stmt->get_result();
        $stock_batches = [];
        while ($batch = $batch_result->fetch_assoc()) {
            $stock_batches[] = $batch;
        }
        $batch_stmt->close();
        $view_item['stock_batches'] = $stock_batches;
    }
}

// ============================================
// UPDATE STOCK STATUS (Batch update for stock levels)
// ============================================

// This function recalculates stock statuses and updates item status
function updateItemStockStatus($conn, $item_id, $branch_id) {
    // Get total stock for the item in this branch
    $stock_sql = "SELECT COALESCE(SUM(quantity_available), 0) as total_stock 
                  FROM inventory_stocks 
                  WHERE item_id = ? AND branch_id = ? AND is_active = 1";
    $stock_stmt = $conn->prepare($stock_sql);
    $stock_stmt->bind_param("is", $item_id, $branch_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_data = $stock_result->fetch_assoc();
    $stock_stmt->close();
    
    // Get minimum stock
    $min_sql = "SELECT minimum_stock FROM inventory_items WHERE item_id = ?";
    $min_stmt = $conn->prepare($min_sql);
    $min_stmt->bind_param("i", $item_id);
    $min_stmt->execute();
    $min_result = $min_stmt->get_result();
    $min_data = $min_result->fetch_assoc();
    $min_stmt->close();
    
    // Determine status
    $total = $stock_data['total_stock'] ?? 0;
    $min = $min_data['minimum_stock'] ?? 0;
    
    if ($total <= 0) {
        return 'Critical';
    } elseif ($total <= $min) {
        return 'Low';
    } else {
        return 'In Stock';
    }
}

// Function to get status class
function getStatusClass($status) {
    switch ($status) {
        case 'Critical': return 'badge-critical';
        case 'Low': return 'badge-low';
        case 'In Stock': return 'badge-instock';
        default: return 'badge-low';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Items</title>
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

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 340px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa0c3;
        }

        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #dcdee8;
            background: #F7F8FC;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
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
            text-decoration: none;
            margin: 0 2px;
        }

        .action-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .action-btn.text-danger:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }

        .pagination-custom {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }

        .pagination-custom a {
            color: var(--primary);
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .pagination-custom a.active {
            background: var(--accent);
            color: white;
        }

        .pagination-custom a:hover:not(.active) {
            background: #eef0f7;
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
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-box {
                max-width: 100%;
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
            <li><a class="active" href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
            <li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
            <li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
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
        <h3>Inventory Items</h3>
        <div class="profile">INVENTORY <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="page-body">
        <div class="toolbar">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="searchItems" placeholder="Search Items" onkeyup="filterItems()">
            </div>
            <button class="btn-custom" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-lg me-1"></i> Add Item
            </button>
        </div>

        <div class="table-wrap">
            <table class="table data-table" id="itemsTable">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Item Name</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                <?php if ($item['is_predictable']): ?>
                                    <span class="badge bg-info text-white ms-1" style="font-size:10px;">Predictable</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['stock']); ?></td>
                            <td><span class="badge-status <?php echo $item['status_class']; ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
                            <td class="text-center">
                                <a href="?view_item_id=<?php echo $item['item_id']; ?>" class="action-btn" title="View Item">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="?edit_item_id=<?php echo $item['item_id']; ?>" class="action-btn" title="Edit Item">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($item['stock'] == 0 || strpos($item['stock'], '0') === 0): ?>
                                    <a href="?delete_item_id=<?php echo $item['item_id']; ?>" class="action-btn text-danger" title="Delete" 
                                       onclick="return confirm('Are you sure you want to delete this item?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="action-btn disabled" style="opacity:0.3;cursor:not-allowed;" title="Cannot delete - has stock">
                                        <i class="bi bi-trash"></i>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No inventory items found. Click "Add Item" to create one.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-custom">
            <a href="#"><i class="bi bi-chevron-left"></i></a>
            <a href="#" class="active">1</a>
            <a href="#">2</a>
            <a href="#">3</a>
            <a href="#">4</a>
            <a href="#">...</a>
            <a href="#">7</a>
            <a href="#">8</a>
            <a href="#"><i class="bi bi-chevron-right"></i></a>
        </div>
    </div>
</div>

<!-- ========== ADD ITEM MODAL ========== -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--primary); font-weight:700;">Add Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Item Name *</label>
                        <input type="text" class="form-control" name="item_name" placeholder="Enter item name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category *</label>
                        <select class="form-select" name="category_id" required>
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Unit *</label>
                            <select class="form-select" name="unit_id" required>
                                <option value="">Select unit...</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo $unit['unit_id']; ?>"><?php echo htmlspecialchars($unit['unit_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Minimum Stock</label>
                            <input type="number" class="form-control" name="minimum_stock" placeholder="e.g. 20" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Optional notes about this item"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_predictable" id="predictableCheck" value="1" checked>
                        <label class="form-check-label" for="predictableCheck">
                            Include in shortage prediction model
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_item" class="btn-custom">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== VIEW ITEM MODAL ========== -->
<?php if ($view_item): ?>
<div class="modal fade" id="viewItemModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header" style="background:var(--primary);color:white;">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Item Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small">Item Name</label>
                        <p class="fw-semibold" style="font-size:18px;"><?php echo htmlspecialchars($view_item['item_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small">Category</label>
                        <p><?php echo htmlspecialchars($view_item['category_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small">Unit</label>
                        <p><?php echo htmlspecialchars($view_item['unit_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small">Minimum Stock</label>
                        <p><?php echo htmlspecialchars($view_item['minimum_stock']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small">Total Stock (All Batches)</label>
                        <p class="fw-bold" style="color:var(--primary);font-size:18px;">
                            <?php echo htmlspecialchars($view_item['total_stock']); ?> <?php echo htmlspecialchars($view_item['unit_name']); ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-muted small">Batches</label>
                        <p><?php echo htmlspecialchars($view_item['batch_count']); ?> batches</p>
                    </div>
                    <div class="col-12">
                        <label class="fw-bold text-muted small">Predictable</label>
                        <p><?php echo $view_item['is_predictable'] ? 'Yes' : 'No'; ?></p>
                    </div>
                    <?php if (!empty($view_item['description'])): ?>
                    <div class="col-12">
                        <label class="fw-bold text-muted small">Description</label>
                        <p><?php echo nl2br(htmlspecialchars($view_item['description'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Stock Batches -->
                    <?php if (isset($view_item['stock_batches']) && count($view_item['stock_batches']) > 0): ?>
                    <div class="col-12">
                        <hr>
                        <h6 class="fw-bold" style="color:var(--primary);">Stock Batches</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Qty</th>
                                        <th>Expiry Date</th>
                                        <th>Supplier</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($view_item['stock_batches'] as $batch): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($batch['batch_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($batch['quantity_available']); ?></td>
                                        <td><?php echo htmlspecialchars($batch['expiration_date'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($batch['supplier'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-custom" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('viewItemModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<!-- ========== EDIT ITEM MODAL ========== -->
<?php if ($edit_item): ?>
<div class="modal fade" id="editItemModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header" style="background:var(--primary);color:white;">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="modal-body">
                    <input type="hidden" name="item_id" value="<?php echo $edit_item['item_id']; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Item Name *</label>
                        <input type="text" class="form-control" name="edit_item_name" 
                               value="<?php echo htmlspecialchars($edit_item['item_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category *</label>
                        <select class="form-select" name="edit_category_id" required>
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>" 
                                    <?php echo $cat['category_id'] == $edit_item['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Unit *</label>
                            <select class="form-select" name="edit_unit_id" required>
                                <option value="">Select unit...</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo $unit['unit_id']; ?>"
                                        <?php echo $unit['unit_id'] == $edit_item['unit_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($unit['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Minimum Stock</label>
                            <input type="number" class="form-control" name="edit_minimum_stock" 
                                   value="<?php echo htmlspecialchars($edit_item['minimum_stock']); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea class="form-control" name="edit_description" rows="2"><?php echo htmlspecialchars($edit_item['description']); ?></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="edit_is_predictable" id="editPredictableCheck" value="1"
                            <?php echo $edit_item['is_predictable'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="editPredictableCheck">
                            Include in shortage prediction model
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_item" class="btn-custom">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('editItemModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filter table rows based on search input
function filterItems() {
    const input = document.getElementById('searchItems');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('itemsTable');
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