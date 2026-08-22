<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an inventory officer
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 5 // Assuming role_id 4 is for Inventory Officer
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name 
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
    $username = $userData['username'] ?? 'Inventory Officer';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Fetch statistics for the inventory officer's branch
$stats = [];

// Current stocks (total quantity available)
$currentStocksQuery = "SELECT SUM(quantity_available) as total 
                       FROM inventory_stocks 
                       WHERE branch_id = ?";
$stmt = $conn->prepare($currentStocksQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$currentStocksResult = $stmt->get_result();
$stats['current_stocks'] = $currentStocksResult->fetch_assoc()['total'] ?? 0;

// Low stocks (items where quantity_available < minimum_stock)
$lowStocksQuery = "SELECT COUNT(*) as low_stock_count 
                   FROM inventory_stocks s 
                   JOIN inventory_items i ON s.item_id = i.item_id 
                   WHERE s.branch_id = ? 
                   AND s.quantity_available < i.minimum_stock";
$stmt = $conn->prepare($lowStocksQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStocksResult = $stmt->get_result();
$stats['low_stocks'] = $lowStocksResult->fetch_assoc()['low_stock_count'] ?? 0;

// Expiring stocks (within 30 days)
$expiringStocksQuery = "SELECT COUNT(*) as expiring_count 
                        FROM inventory_stocks 
                        WHERE branch_id = ? 
                        AND expiration_date IS NOT NULL 
                        AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
                        AND expiration_date >= CURDATE()";
$stmt = $conn->prepare($expiringStocksQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$expiringStocksResult = $stmt->get_result();
$stats['expiring_stocks'] = $expiringStocksResult->fetch_assoc()['expiring_count'] ?? 0;

// Recent transactions (last 30 days)
$recentTransactionsQuery = "SELECT COUNT(*) as recent_count 
                            FROM stock_transactions 
                            WHERE branch_id = ? 
                            AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $conn->prepare($recentTransactionsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentTransactionsResult = $stmt->get_result();
$stats['recent_transactions'] = $recentTransactionsResult->fetch_assoc()['recent_count'] ?? 0;

// Fetch low stock items with details
$lowStockItemsQuery = "SELECT i.item_id, i.item_name, c.category_name, 
                       s.quantity_available, s.stock_id,
                       u.unit_name
                       FROM inventory_stocks s
                       JOIN inventory_items i ON s.item_id = i.item_id
                       JOIN inventory_categories c ON i.category_id = c.category_id
                       JOIN units u ON i.unit_id = u.unit_id
                       WHERE s.branch_id = ? 
                       AND s.quantity_available < i.minimum_stock
                       ORDER BY s.quantity_available ASC
                       LIMIT 10";
$stmt = $conn->prepare($lowStockItemsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockItemsResult = $stmt->get_result();
$lowStockItems = [];
while ($row = $lowStockItemsResult->fetch_assoc()) {
    // Determine status based on quantity
    if ($row['quantity_available'] <= 0) {
        $status = 'Critical';
    } elseif ($row['quantity_available'] < 5) {
        $status = 'Critical';
    } else {
        $status = 'Low Stock';
    }
    $row['status'] = $status;
    $row['item_id_formatted'] = 'ITM-' . str_pad($row['item_id'], 4, '0', STR_PAD_LEFT);
    $row['stock_display'] = $row['quantity_available'] . ' ' . $row['unit_name'];
    $lowStockItems[] = $row;
}

// Fetch recent transactions
$recentTransactionsListQuery = "SELECT t.*, i.item_name, c.category_name, 
                               u.unit_name,
                               t.transaction_type
                               FROM stock_transactions t
                               JOIN inventory_items i ON t.item_id = i.item_id
                               JOIN inventory_categories c ON i.category_id = c.category_id
                               JOIN units u ON i.unit_id = u.unit_id
                               WHERE t.branch_id = ? 
                               ORDER BY t.transaction_date DESC
                               LIMIT 10";
$stmt = $conn->prepare($recentTransactionsListQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentTransactionsListResult = $stmt->get_result();
$recentTransactionsList = [];
while ($row = $recentTransactionsListResult->fetch_assoc()) {
    $row['item_id_formatted'] = 'ITM-' . str_pad($row['item_id'], 4, '0', STR_PAD_LEFT);
    $row['stock_display'] = $row['quantity'] . ' ' . $row['unit_name'];
    $row['status'] = $row['transaction_type'] === 'IN' ? 'Stock In' : ($row['transaction_type'] === 'OUT' ? 'Stock Out' : 'Adjustment');
    $recentTransactionsList[] = $row;
}

function statusBadgeClass($status) {
    switch ($status) {
        case 'Critical':   return 'badge-critical';
        case 'Low Stock':  return 'badge-low';
        case 'Stock In':   return 'badge-in';
        case 'Stock Out':  return 'badge-out';
        case 'Adjustment': return 'badge-adjustment';
        default:           return 'badge-low';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Inventory Officer Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!--REUSABLE SIDEBAR CSS-->
<link rel="stylesheet" href="sidebar.css">

<style>

/*=========================================
  INTERNAL CSS – Refreshed UI
=========================================*/

:root{
    --primary: #2B3A8C;
    --accent: #F21D2F;
    --bg: #f0f2f5;
    --card-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

body{
    background: var(--bg);
    font-family: 'Segoe UI', 'Inter', sans-serif;
}

.main{
    margin-left: 260px;
    min-height: 100vh;
}

.topbar{
    background: white;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 35px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

.topbar h3{
    font-size: 26px;
    font-weight: 700;
    color: var(--primary);
    margin: 0;
}

.topbar h3 small {
    font-size: 15px;
    font-weight: 400;
    color: #6c757d;
    margin-left: 10px;
}

.profile{
    font-weight: 600;
    color: var(--primary);
    cursor: pointer;
}

.dashboard{
    padding: 30px 35px;
}

/* --- Stat Cards --- */
.stat-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 20px 20px 20px 22px;
    box-shadow: var(--card-shadow);
    display: flex;
    align-items: center;
    border-left: 5px solid #ccc;
    transition: transform 0.15s;
    height: 100%;
    min-height: 120px;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.08);
}

.stat-icon {
    font-size: 32px;
    margin-right: 18px;
    color: #6c757d;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-title {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6c757d;
    margin: 0 0 2px 0;
}

.stat-number {
    font-size: 32px;
    font-weight: 700;
    color: #212529;
    line-height: 1.2;
}

.stat-subtitle {
    font-size: 13px;
    color: #6c757d;
    margin-top: 4px;
}

/* Color variants */
.stat-card-green { border-left-color: #28a745; }
.stat-card-red    { border-left-color: #dc3545; }
.stat-card-teal   { border-left-color: #17a2b8; }
.stat-card-yellow { border-left-color: #ffc107; }

.stat-card-green .stat-icon { color: #28a745; }
.stat-card-red .stat-icon    { color: #dc3545; }
.stat-card-teal .stat-icon   { color: #17a2b8; }
.stat-card-yellow .stat-icon { color: #ffc107; }

/* --- Large Cards (Tables) --- */
.large-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 20px 20px 15px 20px;
    box-shadow: var(--card-shadow);
    margin-top: 30px;
}

.card-header-custom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 12px;
    border-bottom: 2px solid #f0f2f7;
    margin-bottom: 16px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #212529;
    margin: 0;
}

.section-title i {
    margin-right: 8px;
}

/* --- Table styling --- */
.table-wrap {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #eef0f5;
}

.data-table {
    margin: 0;
    font-size: 14px;
}

.data-table thead th {
    background: var(--primary);
    color: #eeeeee;
    font-weight: 600;
    font-size: 13px;
    border: none;
    padding: 12px 16px;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.data-table tbody td {
    font-size: 14px;
    color: #333;
    padding: 12px 16px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f2f7;
}

.data-table tbody tr:last-child td {
    border-bottom: none;
}

.data-table tbody tr:hover {
    background: #fafbff;
}

/* --- Badges --- */
.badge-status {
    display: inline-block;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.3px;
}

.badge-low {
    background: #FFEAEA;
    color: #dc3545;
}

.badge-critical {
    background: #dc3545;
    color: white;
}

.badge-in {
    background: #E6F4EA;
    color: #1E7B34;
}

.badge-out {
    background: #EDEFFA;
    color: var(--primary);
}

.badge-adjustment {
    background: #FFF3CD;
    color: #856404;
}

/* --- Empty state --- */
.empty-state {
    text-align: center;
    padding: 30px 10px;
    color: #999;
}
.empty-state i {
    font-size: 32px;
    margin-bottom: 10px;
    display: block;
}

/* --- Custom Button --- */
.btn-custom {
    background: var(--primary);
    color: white;
    border-radius: 8px;
    padding: 8px 22px;
    border: none;
    font-weight: 500;
    transition: background 0.15s;
}
.btn-custom:hover {
    background: #1d2863;
    color: white;
}

/* --- Responsive --- */
@media (max-width: 991px) {
    .main {
        margin-left: 90px;
    }
    .dashboard {
        padding: 20px 15px;
    }
    .topbar {
        padding: 0 15px;
    }
    .stat-card {
        min-height: 100px;
        padding: 16px;
    }
    .stat-number {
        font-size: 28px;
    }
}

@media (max-width: 576px) {
    .stat-card {
        flex-direction: column;
        align-items: flex-start;
        border-left: none;
        border-top: 5px solid #ccc;
        padding: 18px;
    }
    .stat-card .stat-icon {
        margin-bottom: 8px;
        margin-right: 0;
    }
    .stat-card-green { border-top-color: #28a745; border-left-color: transparent; }
    .stat-card-red    { border-top-color: #dc3545; border-left-color: transparent; }
    .stat-card-teal   { border-top-color: #17a2b8; border-left-color: transparent; }
    .stat-card-yellow { border-top-color: #ffc107; border-left-color: transparent; }
}

</style>

</head>

<body>
<!-- SIDEBAR LOGO-->
<div class="sidebar">

    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">
            Smart Bite Care
        </div>
    </div>

    <!-- SIDEBAR NAVIGATION -->
    <nav class="nav-menu">
        <ul>
            <li><a class="active" href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
            <li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
            <li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
            <li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
            <li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
            <li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"> <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
        </a>
    </div>

</div>

<!-- Main Content -->
<div class="main">

    <div class="topbar">
        <h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile"> <?php echo htmlspecialchars($username); ?> </div>
    </div>

    <div class="dashboard">
        <div class="row g-4">

            <!-- Current Stocks -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-card-green">
                    <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Current Stocks</div>
                        <div class="stat-number"><?php echo number_format($stats['current_stocks']); ?></div>
                        <div class="stat-subtitle">Total items in stock</div>
                    </div>
                </div>
            </div>

            <!-- Low Stocks -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-card-red">
                    <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Low Stocks</div>
                        <div class="stat-number"><?php echo number_format($stats['low_stocks']); ?></div>
                        <div class="stat-subtitle">Items below minimum</div>
                    </div>
                </div>
            </div>

            <!-- Expiring Stocks -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-card-teal">
                    <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Expiring Stocks</div>
                        <div class="stat-number"><?php echo number_format($stats['expiring_stocks']); ?></div>
                        <div class="stat-subtitle">Within 30 days</div>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-card-yellow">
                    <div class="stat-icon"><i class="bi bi-arrow-left-right"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Recent Transactions</div>
                        <div class="stat-number"><?php echo number_format($stats['recent_transactions']); ?></div>
                        <div class="stat-subtitle">Last 30 days</div>
                    </div>
                </div>
            </div>

        </div><!-- row -->

        <!-- Lower Row: Two Tables -->
        <div class="row g-4 mt-1">

            <!-- Low Stock Items -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="card-header-custom">
                        <h5 class="section-title"><i class="bi bi-exclamation-triangle-fill text-danger"></i> <span style="color: var(--primary); font-weight: bold;">Low Stock Items</span></h5>
                        <span class="badge bg-danger">Alert</span> 
                    </div>
                    <div class="table-wrap">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Item ID</th>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($lowStockItems)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="bi bi-check-circle"></i>
                                            <p>No low stock items found.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($lowStockItems as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['item_id_formatted']); ?></td>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['stock_display']); ?></td>
                                        <td><span class="badge-status <?php echo statusBadgeClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <button class="btn btn-custom" onclick="window.location.href='InventoryOfficer_StockManagement.php'">
                            View All Low Stocks
                        </button>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="card-header-custom">
                        <h5 class="section-title"><i class="bi bi-arrow-left-right text-primary"></i> <span style="color: var(--primary); font-weight: bold;">Recent Transactions</span></h5>
                        <span class="badge bg-secondary">Last 10</span>
                    </div>
                    <div class="table-wrap">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Item ID</th>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentTransactionsList)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="bi bi-clock-history"></i>
                                            <p>No recent transactions found.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($recentTransactionsList as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['item_id_formatted']); ?></td>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['stock_display']); ?></td>
                                        <td><span class="badge-status <?php echo statusBadgeClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <button class="btn btn-custom" onclick="window.location.href='InventoryOfficer_StockTransactions.php'">
                            View All Transactions
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- row -->
    </div><!-- dashboard -->
</div><!-- main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>