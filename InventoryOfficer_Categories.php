<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Inventory Categories') {
    // Get user's branch_id
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

// Check if user is logged in and is Inventory Officer (role_id = 5) or Super Admin (role_id = 1)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 5)
) {
    header("Location: login.php");
    exit();
}

// ============================================
// HANDLE CATEGORY CRUD OPERATIONS
// ============================================

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);
    $monitoring_frequency = $_POST['monitoring_frequency'] ?? 'Monthly';
    
    if (empty($category_name)) {
        $error_msg = "Category name is required.";
    } else {
        // Check if category already exists
        $check_sql = "SELECT category_id FROM inventory_categories WHERE category_name = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $category_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Category '$category_name' already exists.";
        } else {
            $sql = "INSERT INTO inventory_categories (category_name, monitoring_frequency) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $category_name, $monitoring_frequency);
            
            if ($stmt->execute()) {
                $category_id = $conn->insert_id;
                addAuditLog($conn, $_SESSION['user_id'], 
                    "Added new inventory category: $category_name (ID: $category_id) with frequency: $monitoring_frequency");
                $success_msg = "Category added successfully!";
            } else {
                $error_msg = "Error adding category: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to add inventory category: $category_name - " . $conn->error);
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $category_id = intval($_POST['category_id']);
    $category_name = trim($_POST['edit_category_name']);
    $monitoring_frequency = $_POST['edit_monitoring_frequency'] ?? 'Monthly';
    
    // Get old data for audit log
    $old_sql = "SELECT category_name, monitoring_frequency FROM inventory_categories WHERE category_id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $category_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    $old_stmt->close();
    
    if (empty($category_name)) {
        $error_msg = "Category name is required.";
    } else {
        // Check if name already exists (excluding current category)
        $check_sql = "SELECT category_id FROM inventory_categories WHERE category_name = ? AND category_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $category_name, $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Category '$category_name' already exists.";
        } else {
            $sql = "UPDATE inventory_categories SET category_name = ?, monitoring_frequency = ? WHERE category_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $category_name, $monitoring_frequency, $category_id);
            
            if ($stmt->execute()) {
                // Build change description
                $changes = [];
                if ($old_data['category_name'] != $category_name) {
                    $changes[] = "Name: '{$old_data['category_name']}' → '$category_name'";
                }
                if ($old_data['monitoring_frequency'] != $monitoring_frequency) {
                    $changes[] = "Frequency: '{$old_data['monitoring_frequency']}' → '$monitoring_frequency'";
                }
                
                $details = !empty($changes) ? "Changes: " . implode(", ", $changes) : "No changes made";
                addAuditLog($conn, $_SESSION['user_id'], "Updated inventory category: $category_name (ID: $category_id) - $details");
                $success_msg = "Category updated successfully!";
            } else {
                $error_msg = "Error updating category: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to update inventory category: $category_name - " . $conn->error);
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle Delete Category
if (isset($_GET['delete_category_id'])) {
    $category_id = intval($_GET['delete_category_id']);
    
    // Get category details before deletion
    $cat_sql = "SELECT category_name FROM inventory_categories WHERE category_id = ?";
    $cat_stmt = $conn->prepare($cat_sql);
    $cat_stmt->bind_param("i", $category_id);
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result();
    $cat_data = $cat_result->fetch_assoc();
    $cat_stmt->close();
    
    if ($cat_data) {
        // Check if category has items
        $check_sql = "SELECT COUNT(*) as count FROM inventory_items WHERE category_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($check_data['count'] > 0) {
            $error_msg = "Cannot delete category: It has " . $check_data['count'] . " items associated with it.";
        } else {
            $delete_sql = "DELETE FROM inventory_categories WHERE category_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $category_id);
            
            if ($delete_stmt->execute()) {
                addAuditLog($conn, $_SESSION['user_id'], "Deleted inventory category: " . $cat_data['category_name'] . " (ID: $category_id)");
                $success_msg = "Category deleted successfully!";
            } else {
                $error_msg = "Error deleting category: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to delete inventory category: " . $cat_data['category_name'] . " - " . $conn->error);
            }
            $delete_stmt->close();
        }
    } else {
        $error_msg = "Category not found.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ============================================
// HANDLE UNIT CRUD OPERATIONS
// ============================================

// Handle Add Unit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_unit'])) {
    $unit_name = trim($_POST['unit_name']);
    
    if (empty($unit_name)) {
        $error_msg = "Unit name is required.";
    } else {
        // Check if unit already exists
        $check_sql = "SELECT unit_id FROM units WHERE unit_name = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $unit_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Unit '$unit_name' already exists.";
        } else {
            $sql = "INSERT INTO units (unit_name) VALUES (?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $unit_name);
            
            if ($stmt->execute()) {
                $unit_id = $conn->insert_id;
                addAuditLog($conn, $_SESSION['user_id'], "Added new unit: $unit_name (ID: $unit_id)");
                $success_msg = "Unit added successfully!";
            } else {
                $error_msg = "Error adding unit: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to add unit: $unit_name - " . $conn->error);
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle Edit Unit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_unit'])) {
    $unit_id = intval($_POST['unit_id']);
    $unit_name = trim($_POST['edit_unit_name']);
    
    // Get old data for audit log
    $old_sql = "SELECT unit_name FROM units WHERE unit_id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $unit_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();
    $old_data = $old_result->fetch_assoc();
    $old_stmt->close();
    
    if (empty($unit_name)) {
        $error_msg = "Unit name is required.";
    } else {
        // Check if name already exists (excluding current unit)
        $check_sql = "SELECT unit_id FROM units WHERE unit_name = ? AND unit_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $unit_name, $unit_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Unit '$unit_name' already exists.";
        } else {
            $sql = "UPDATE units SET unit_name = ? WHERE unit_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $unit_name, $unit_id);
            
            if ($stmt->execute()) {
                addAuditLog($conn, $_SESSION['user_id'], "Updated unit: '{$old_data['unit_name']}' → '$unit_name' (ID: $unit_id)");
                $success_msg = "Unit updated successfully!";
            } else {
                $error_msg = "Error updating unit: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to update unit: $unit_name - " . $conn->error);
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle Delete Unit
if (isset($_GET['delete_unit_id'])) {
    $unit_id = intval($_GET['delete_unit_id']);
    
    // Get unit details before deletion
    $unit_sql = "SELECT unit_name FROM units WHERE unit_id = ?";
    $unit_stmt = $conn->prepare($unit_sql);
    $unit_stmt->bind_param("i", $unit_id);
    $unit_stmt->execute();
    $unit_result = $unit_stmt->get_result();
    $unit_data = $unit_result->fetch_assoc();
    $unit_stmt->close();
    
    if ($unit_data) {
        // Check if unit has items
        $check_sql = "SELECT COUNT(*) as count FROM inventory_items WHERE unit_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $unit_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($check_data['count'] > 0) {
            $error_msg = "Cannot delete unit: It is used by " . $check_data['count'] . " inventory items.";
        } else {
            $delete_sql = "DELETE FROM units WHERE unit_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $unit_id);
            
            if ($delete_stmt->execute()) {
                addAuditLog($conn, $_SESSION['user_id'], "Deleted unit: " . $unit_data['unit_name'] . " (ID: $unit_id)");
                $success_msg = "Unit deleted successfully!";
            } else {
                $error_msg = "Error deleting unit: " . $conn->error;
                addAuditLog($conn, $_SESSION['user_id'], "Failed to delete unit: " . $unit_data['unit_name'] . " - " . $conn->error);
            }
            $delete_stmt->close();
        }
    } else {
        $error_msg = "Unit not found.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ============================================
// GET DATA FROM DATABASE
// ============================================

// Get all categories with item count
$cat_sql = "SELECT 
        c.category_id, 
        c.category_name, 
        c.monitoring_frequency,
        COUNT(i.item_id) as item_count,
        COALESCE(SUM(s.quantity_available), 0) as total_stock
        FROM inventory_categories c
        LEFT JOIN inventory_items i ON c.category_id = i.category_id
        LEFT JOIN inventory_stocks s ON i.item_id = s.item_id
        GROUP BY c.category_id
        ORDER BY c.category_name ASC";

$cat_result = $conn->query($cat_sql);
$categories = [];
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// Get all units with item count
$unit_sql = "SELECT 
        u.unit_id, 
        u.unit_name,
        COUNT(i.item_id) as item_count
        FROM units u
        LEFT JOIN inventory_items i ON u.unit_id = i.unit_id
        GROUP BY u.unit_id
        ORDER BY u.unit_name ASC";

$unit_result = $conn->query($unit_sql);
$units = [];
while ($row = $unit_result->fetch_assoc()) {
    $units[] = $row;
}

// Get category for editing
$edit_category = null;
if (isset($_GET['edit_category_id'])) {
    $edit_id = intval($_GET['edit_category_id']);
    $edit_sql = "SELECT * FROM inventory_categories WHERE category_id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_category = $edit_result->fetch_assoc();
    $edit_stmt->close();
}

// Get unit for editing
$edit_unit = null;
if (isset($_GET['edit_unit_id'])) {
    $edit_id = intval($_GET['edit_unit_id']);
    $edit_sql = "SELECT * FROM units WHERE unit_id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_unit = $edit_result->fetch_assoc();
    $edit_stmt->close();
}

// Get category for viewing
$view_category = null;
if (isset($_GET['view_category_id'])) {
    $view_id = intval($_GET['view_category_id']);
    $view_sql = "SELECT 
                    c.*,
                    COUNT(i.item_id) as item_count,
                    COALESCE(SUM(s.quantity_available), 0) as total_stock
                 FROM inventory_categories c
                 LEFT JOIN inventory_items i ON c.category_id = i.category_id
                 LEFT JOIN inventory_stocks s ON i.item_id = s.item_id
                 WHERE c.category_id = ?
                 GROUP BY c.category_id";
    $view_stmt = $conn->prepare($view_sql);
    $view_stmt->bind_param("i", $view_id);
    $view_stmt->execute();
    $view_result = $view_stmt->get_result();
    $view_category = $view_result->fetch_assoc();
    $view_stmt->close();
}

// Get active tab from URL
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'categories';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Categories & Units</title>
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

        .badge-frequency {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-daily {
            background: #E3F2FD;
            color: #0D47A1;
        }

        .badge-weekly {
            background: #E8F5E9;
            color: #1B5E20;
        }

        .badge-monthly {
            background: #FFF3E0;
            color: #E65100;
        }

        .unit-badge {
            display: inline-block;
            background: #E8EAF6;
            color: var(--primary);
            padding: 4px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
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

        .nav-tabs-custom {
            border-bottom: 2px solid #eef0f7;
            margin-bottom: 24px;
        }

        .nav-tabs-custom .nav-link {
            color: #6c7a9a;
            font-weight: 600;
            padding: 12px 24px;
            border: none;
            border-bottom: 3px solid transparent;
            transition: 0.15s;
        }

        .nav-tabs-custom .nav-link:hover {
            color: var(--primary);
            border-bottom-color: #d7def0;
        }

        .nav-tabs-custom .nav-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: transparent;
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
            .nav-tabs-custom .nav-link {
                padding: 10px 16px;
                font-size: 14px;
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
            <li><a class="active" href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
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
        <h3>Categories & Units</h3>
        <div class="profile">INVENTORY <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="page-body">
        <!-- Tabs -->
        <ul class="nav nav-tabs nav-tabs-custom" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $active_tab == 'categories' ? 'active' : ''; ?>" 
                   href="?tab=categories" role="tab">
                    <i class="bi bi-tags me-2"></i>Categories
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $active_tab == 'units' ? 'active' : ''; ?>" 
                   href="?tab=units" role="tab">
                    <i class="bi bi-rulers me-2"></i>Units
                </a>
            </li>
        </ul>

        <!-- ============================================ -->
        <!-- CATEGORIES TAB -->
        <!-- ============================================ -->
        <?php if ($active_tab == 'categories'): ?>
        <div class="tab-content">
            <div class="toolbar">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchCategories" placeholder="Search categories..." onkeyup="filterTable('categoriesTable', 'searchCategories')">
                </div>
                <button class="btn-custom" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-plus-lg me-1"></i> Add Category
                </button>
            </div>

            <div class="table-wrap">
                <table class="table data-table" id="categoriesTable">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Monitoring Frequency</th>
                            <th>Total Items</th>
                            <th>Total Stock</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($categories) > 0): ?>
                            <?php foreach ($categories as $category): 
                                $freq_class = 'badge-monthly';
                                if ($category['monitoring_frequency'] == 'Daily') $freq_class = 'badge-daily';
                                elseif ($category['monitoring_frequency'] == 'Weekly') $freq_class = 'badge-weekly';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                                <td>
                                    <span class="badge-frequency <?php echo $freq_class; ?>">
                                        <?php echo htmlspecialchars($category['monitoring_frequency']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($category['item_count']); ?></td>
                                <td><?php echo htmlspecialchars($category['total_stock']); ?></td>
                                <td class="text-center">
                                    <a href="?tab=categories&view_category_id=<?php echo $category['category_id']; ?>" class="action-btn" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="?tab=categories&edit_category_id=<?php echo $category['category_id']; ?>" class="action-btn" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($category['item_count'] == 0): ?>
                                        <a href="?delete_category_id=<?php echo $category['category_id']; ?>" class="action-btn text-danger" title="Delete" 
                                           onclick="return confirm('Are you sure you want to delete this category?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="action-btn disabled" style="opacity:0.3;cursor:not-allowed;" title="Cannot delete - has items">
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
                                    No categories found. Click "Add Category" to create one.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- UNITS TAB -->
        <!-- ============================================ -->
        <?php if ($active_tab == 'units'): ?>
        <div class="tab-content">
            <div class="toolbar">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchUnits" placeholder="Search units..." onkeyup="filterTable('unitsTable', 'searchUnits')">
                </div>
                <button class="btn-custom" data-bs-toggle="modal" data-bs-target="#addUnitModal">
                    <i class="bi bi-plus-lg me-1"></i> Add Unit
                </button>
            </div>

            <div class="table-wrap">
                <table class="table data-table" id="unitsTable">
                    <thead>
                        <tr>
                            <th>Unit Name</th>
                            <th>Used By (Items)</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($units) > 0): ?>
                            <?php foreach ($units as $unit): ?>
                            <tr>
                                <td><span class="unit-badge"><?php echo htmlspecialchars($unit['unit_name']); ?></span></td>
                                <td><?php echo htmlspecialchars($unit['item_count']); ?> items</td>
                                <td class="text-center">
                                    <a href="?tab=units&edit_unit_id=<?php echo $unit['unit_id']; ?>" class="action-btn" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($unit['item_count'] == 0): ?>
                                        <a href="?delete_unit_id=<?php echo $unit['unit_id']; ?>" class="action-btn text-danger" title="Delete" 
                                           onclick="return confirm('Are you sure you want to delete this unit?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="action-btn disabled" style="opacity:0.3;cursor:not-allowed;" title="Cannot delete - used by items">
                                            <i class="bi bi-trash"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    No units found. Click "Add Unit" to create one.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========== ADD CATEGORY MODAL ========== -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--primary); font-weight:700;">Add Inventory Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?tab=categories">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category Name *</label>
                        <input type="text" class="form-control" name="category_name" placeholder="Enter category name" required>
                        <div class="form-text">Examples: Appliances, Furniture, Office Supplies, Forms, Equipment</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monitoring Frequency</label>
                        <select class="form-select" name="monitoring_frequency">
                            <option value="Daily">Daily</option>
                            <option value="Weekly">Weekly</option>
                            <option value="Monthly" selected>Monthly</option>
                        </select>
                        <div class="form-text">How often should this category be monitored for stock levels?</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_category" class="btn-custom">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== ADD UNIT MODAL ========== -->
<div class="modal fade" id="addUnitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header">
                <h5 class="modal-title" style="color:var(--primary); font-weight:700;">Add Unit of Measurement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?tab=units">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Unit Name *</label>
                        <input type="text" class="form-control" name="unit_name" placeholder="Enter unit name (e.g., Vial, Box, Piece)" required>
                        <div class="form-text">Examples: Piece, Vial, Ampule, Box, Bottle, Tablet, Capsule, Set, Bundle</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_unit" class="btn-custom">Save Unit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== VIEW CATEGORY MODAL ========== -->
<?php if ($view_category): ?>
<div class="modal fade" id="viewCategoryModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header" style="background:var(--primary);color:white;">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Category Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="fw-bold text-muted small">Category Name</label>
                        <p class="fw-semibold" style="font-size:18px;"><?php echo htmlspecialchars($view_category['category_name']); ?></p>
                    </div>
                    <div class="col-6">
                        <label class="fw-bold text-muted small">Monitoring Frequency</label>
                        <p>
                            <span class="badge-frequency <?php 
                                echo $view_category['monitoring_frequency'] == 'Daily' ? 'badge-daily' : 
                                    ($view_category['monitoring_frequency'] == 'Weekly' ? 'badge-weekly' : 'badge-monthly'); 
                            ?>">
                                <?php echo htmlspecialchars($view_category['monitoring_frequency']); ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-6">
                        <label class="fw-bold text-muted small">Total Items</label>
                        <p><?php echo htmlspecialchars($view_category['item_count']); ?></p>
                    </div>
                    <div class="col-12">
                        <label class="fw-bold text-muted small">Total Stock Across All Items</label>
                        <p class="fw-bold" style="color:var(--primary);font-size:18px;">
                            <?php echo htmlspecialchars($view_category['total_stock']); ?> units
                        </p>
                    </div>
                    <?php if ($view_category['item_count'] > 0): ?>
                    <div class="col-12">
                        <label class="fw-bold text-muted small">Items in this Category</label>
                        <?php
                        $items_sql = "SELECT item_name FROM inventory_items WHERE category_id = ? ORDER BY item_name";
                        $items_stmt = $conn->prepare($items_sql);
                        $items_stmt->bind_param("i", $view_category['category_id']);
                        $items_stmt->execute();
                        $items_result = $items_stmt->get_result();
                        $items_list = [];
                        while ($item = $items_result->fetch_assoc()) {
                            $items_list[] = $item['item_name'];
                        }
                        $items_stmt->close();
                        ?>
                        <div style="max-height:150px;overflow-y:auto;background:#f8f9fa;padding:10px;border-radius:8px;">
                            <?php foreach ($items_list as $item): ?>
                                <span class="badge bg-light text-dark me-1 mb-1" style="padding:6px 12px;">
                                    <?php echo htmlspecialchars($item); ?>
                                </span>
                            <?php endforeach; ?>
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
        var modal = new bootstrap.Modal(document.getElementById('viewCategoryModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<!-- ========== EDIT CATEGORY MODAL ========== -->
<?php if ($edit_category): ?>
<div class="modal fade" id="editCategoryModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header" style="background:var(--primary);color:white;">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?tab=categories">
                <div class="modal-body">
                    <input type="hidden" name="category_id" value="<?php echo $edit_category['category_id']; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category Name *</label>
                        <input type="text" class="form-control" name="edit_category_name" 
                               value="<?php echo htmlspecialchars($edit_category['category_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Monitoring Frequency</label>
                        <select class="form-select" name="edit_monitoring_frequency">
                            <option value="Daily" <?php echo $edit_category['monitoring_frequency'] == 'Daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="Weekly" <?php echo $edit_category['monitoring_frequency'] == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo $edit_category['monitoring_frequency'] == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_category" class="btn-custom">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<!-- ========== EDIT UNIT MODAL ========== -->
<?php if ($edit_unit): ?>
<div class="modal fade" id="editUnitModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header" style="background:var(--primary);color:white;">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Unit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?tab=units">
                <div class="modal-body">
                    <input type="hidden" name="unit_id" value="<?php echo $edit_unit['unit_id']; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Unit Name *</label>
                        <input type="text" class="form-control" name="edit_unit_name" 
                               value="<?php echo htmlspecialchars($edit_unit['unit_name']); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_unit" class="btn-custom">Update Unit</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('editUnitModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filter table rows based on search input
function filterTable(tableId, inputId) {
    const input = document.getElementById(inputId);
    const filter = input.value.toLowerCase();
    const table = document.getElementById(tableId);
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