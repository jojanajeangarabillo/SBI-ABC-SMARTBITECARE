<?php
session_start();
require_once 'sources/db_connect.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 5) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = null;
$branch_name = 'No Branch Assigned';
$username = 'Inventory Officer';

$userQuery = "SELECT u.branch_id, u.username, b.branch_name
              FROM users u
              LEFT JOIN branches b ON u.branch_id = b.branch_id
              WHERE u.user_id = ? AND u.status = 'Active'
              LIMIT 1";

$userStmt = $conn->prepare($userQuery);
if (!$userStmt) {
    die('Database error: Unable to prepare user query.');
}
$userStmt->bind_param('i', $user_id);
if (!$userStmt->execute()) {
    die('Database error: Unable to retrieve user information.');
}
$userResult = $userStmt->get_result();
$userStmt->close();

if ($userResult->num_rows === 0) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}

$userData = $userResult->fetch_assoc();
$branch_id = $userData['branch_id'];
$username = $userData['username'] ?: 'Inventory Officer';
$branch_name = $userData['branch_name'] ?: 'No Branch Assigned';

$stats = [
    'current_stocks' => 0,
    'low_stocks' => 0,
    'expiring_stocks' => 0,
    'recent_transactions' => 0
];
$lowStockItems = [];
$recentTransactionsList = [];

if (!empty($branch_id)) {
    $query = "SELECT COALESCE(SUM(quantity_available), 0) AS total
              FROM inventory_stocks
              WHERE branch_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) die('Database error: Unable to prepare current stock query.');
    $stmt->bind_param('s', $branch_id);
    if (!$stmt->execute()) die('Database error: Unable to retrieve current stocks.');
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['current_stocks'] = (int)($row['total'] ?? 0);
    $stmt->close();

    $query = "SELECT COUNT(DISTINCT s.item_id) AS low_stock_count
              FROM inventory_stocks s
              INNER JOIN inventory_items i ON i.item_id = s.item_id
              WHERE s.branch_id = ?
                AND s.quantity_available < COALESCE(i.minimum_stock, 0)";
    $stmt = $conn->prepare($query);
    if (!$stmt) die('Database error: Unable to prepare low stock query.');
    $stmt->bind_param('s', $branch_id);
    if (!$stmt->execute()) die('Database error: Unable to retrieve low stocks.');
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['low_stocks'] = (int)($row['low_stock_count'] ?? 0);
    $stmt->close();

    $query = "SELECT COUNT(*) AS expiring_count
              FROM inventory_stocks
              WHERE branch_id = ?
                AND expiration_date IS NOT NULL
                AND expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    $stmt = $conn->prepare($query);
    if (!$stmt) die('Database error: Unable to prepare expiration query.');
    $stmt->bind_param('s', $branch_id);
    if (!$stmt->execute()) die('Database error: Unable to retrieve expiring stocks.');
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['expiring_stocks'] = (int)($row['expiring_count'] ?? 0);
    $stmt->close();


    $query = "SELECT COUNT(*) AS recent_count
              FROM stock_transactions
              WHERE branch_id = ?
                AND transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $stmt = $conn->prepare($query);
    if (!$stmt) die('Database error: Unable to prepare transaction count query.');
    $stmt->bind_param('s', $branch_id);
    if (!$stmt->execute()) die('Database error: Unable to retrieve recent transaction count.');
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['recent_transactions'] = (int)($row['recent_count'] ?? 0);
    $stmt->close();

    $query = "SELECT
                  i.item_id,
                  i.item_name,
                  c.category_name,
                  i.minimum_stock,
                  s.quantity_available,
                  s.stock_id,
                  u.unit_name
              FROM inventory_stocks s
              INNER JOIN inventory_items i ON i.item_id = s.item_id
              INNER JOIN inventory_categories c ON c.category_id = i.category_id
              INNER JOIN units u ON u.unit_id = i.unit_id
              WHERE s.branch_id = ?
                AND s.quantity_available < COALESCE(i.minimum_stock, 0)
              ORDER BY s.quantity_available ASC, i.item_name ASC
              LIMIT 10";
    $stmt = $conn->prepare($query);
    if (!$stmt) die('Database error: Unable to prepare low-stock items query.');
    $stmt->bind_param('s', $branch_id);
    if (!$stmt->execute()) die('Database error: Unable to retrieve low-stock items.');
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $quantity = (int)$row['quantity_available'];
        $minimum = (int)$row['minimum_stock'];

        if ($quantity <= 0) {
            $status = 'Critical';
        } else {
            $status = 'Low Stock';
        }

        $row['status'] = $status;
        $row['item_id_formatted'] = 'ITM-' . str_pad((string)$row['item_id'], 4, '0', STR_PAD_LEFT);
        $row['stock_display'] = $quantity . ' ' . $row['unit_name'];
        $lowStockItems[] = $row;
    }
    $stmt->close();

    // Recent Transactions preview mirrors InventoryOfficer_StockTransactions.php exactly.
    // The only difference is LIMIT 10 because this is a dashboard preview.
    $transactionQuery = "
        SELECT
            st.transaction_id,
            st.transaction_type,
            st.quantity,
            st.transaction_date,
            st.remarks,
            ii.item_name,
            u.unit_name,
            usr.username
        FROM stock_transactions st
        INNER JOIN inventory_items ii
            ON st.item_id = ii.item_id
        LEFT JOIN units u
            ON ii.unit_id = u.unit_id
        INNER JOIN users usr
            ON st.user_id = usr.user_id
        WHERE st.branch_id = ?
        ORDER BY st.transaction_date DESC, st.transaction_id DESC
        LIMIT 10
    ";

    $transactionStmt = $conn->prepare($transactionQuery);
    if (!$transactionStmt) die('Database error: Unable to prepare recent transactions query.');
    $transactionStmt->bind_param('s', $branch_id);
    if (!$transactionStmt->execute()) die('Database error: Unable to retrieve recent transactions.');
    $transactionResult = $transactionStmt->get_result();

    while ($row = $transactionResult->fetch_assoc()) {
        $type = $row['transaction_type'];

        switch ($type) {
            case 'IN':
                $displayType = 'Stock In';
                $sign = '+';
                break;

            case 'OUT':
                $displayType = 'Stock Out';
                $sign = '-';
                break;

            case 'ADJUSTMENT':
                $displayType = 'Adjustment';
                $sign = ((int)$row['quantity'] < 0) ? '-' : '+';
                break;

            default:
                $displayType = $type ?: 'Unknown';
                $sign = ((int)$row['quantity'] < 0) ? '-' : '+';
                break;
        }

        $quantity = abs((int)$row['quantity']);
        $unitName = $row['unit_name'] ?? '';

        $recentTransactionsList[] = [
            'trx' => 'TRX-' . str_pad((string)$row['transaction_id'], 4, '0', STR_PAD_LEFT),
            'type' => $displayType,
            'item' => $row['item_name'] ?? 'Unknown Item',
            'qty' => $sign . $quantity . ($unitName !== '' ? ' ' . $unitName : ''),
            'date' => date('m/d/Y', strtotime($row['transaction_date'])),
            'by' => $row['username'] ?? 'Unknown User'
        ];
    }

    $transactionStmt->close();
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

function trxTypeClassDashboard($type) {
    switch ($type) {
        case 'Stock In':   return 'badge-in';
        case 'Stock Out':  return 'badge-out';
        case 'Adjustment': return 'badge-adjust';
        default:           return 'badge-in';
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

<link rel="stylesheet" href="sidebar.css">

<style>
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

.transaction-table-wrap {
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: auto;
}

.transaction-preview-table {
    min-width: 760px;
    width: max-content;
    margin-bottom: 0;
}

.transaction-preview-table th,
.transaction-preview-table td {
    white-space: nowrap;
}

.transaction-preview-table th:nth-child(1),
.transaction-preview-table td:nth-child(1) { min-width: 110px; }
.transaction-preview-table th:nth-child(2),
.transaction-preview-table td:nth-child(2) { min-width: 130px; }
.transaction-preview-table th:nth-child(3),
.transaction-preview-table td:nth-child(3) { min-width: 190px; }
.transaction-preview-table th:nth-child(4),
.transaction-preview-table td:nth-child(4) { min-width: 120px; }
.transaction-preview-table th:nth-child(5),
.transaction-preview-table td:nth-child(5) { min-width: 120px; }
.transaction-preview-table th:nth-child(6),
.transaction-preview-table td:nth-child(6) { min-width: 150px; }

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
        <div class="profile"> 
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($username); ?> 
            <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| Inventory Officer</span>
        
        </div>
        
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
                    <div class="table-wrap transaction-table-wrap">
                        <table class="table data-table transaction-preview-table">
                            <thead>
                                <tr>
                                    <th>Trx No.</th>
                                    <th>Type</th>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Date</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentTransactionsList)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="bi bi-clock-history"></i>
                                            <p>No recent transactions found.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($recentTransactionsList as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['trx']); ?></td>
                                        <td>
                                            <span class="badge-status <?php echo trxTypeClassDashboard($item['type']); ?>">
                                                <?php echo htmlspecialchars($item['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['item']); ?></td>
                                        <td><?php echo htmlspecialchars($item['qty']); ?></td>
                                        <td><?php echo htmlspecialchars($item['date']); ?></td>
                                        <td><?php echo htmlspecialchars($item['by']); ?></td>
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