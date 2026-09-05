<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 2);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Branch Admin');

function inventoryNumber($value): string
{
    $number = (float)$value;
    if (abs($number - round($number)) < 0.001) {
        return number_format((int)round($number));
    }
    return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
}

function inventoryStockStatus(float $stock, float $minimum): array
{
    if ($stock <= 0) {
        return ['Out of Stock', 'status-out', 'out-of-stock'];
    }
    if ($minimum > 0 && $stock <= $minimum) {
        return ['Low Stock', 'status-low', 'low-stock'];
    }
    return ['In Stock', 'status-good', 'in-stock'];
}

function inventoryExpiryStatus(?string $expiration): ?array
{
    if (!$expiration) {
        return null;
    }

    $today = strtotime(date('Y-m-d'));
    $expiry = strtotime($expiration);
    if ($expiry === false) {
        return null;
    }
    if ($expiry < $today) {
        return ['Expired', 'status-expired', 'expired'];
    }
    if ($expiry <= strtotime('+30 days', $today)) {
        return ['Expiring Soon', 'status-expiring', 'expiring'];
    }
    return null;
}

function inventoryTransactionClass(string $type): string
{
    $type = strtoupper($type);
    if (in_array($type, ['IN', 'TRANSFER_IN', 'RETURN'], true)) {
        return 'movement-in';
    }
    if (in_array($type, ['OUT', 'TRANSFER_OUT', 'EXPIRED'], true)) {
        return 'movement-out';
    }
    return 'movement-adjustment';
}

function inventoryTransactionQuantity(string $type, $quantity): string
{
    $type = strtoupper($type);
    $number = (float)$quantity;
    if (in_array($type, ['OUT', 'TRANSFER_OUT', 'EXPIRED'], true)) {
        return '-' . inventoryNumber(abs($number));
    }
    if (in_array($type, ['IN', 'TRANSFER_IN', 'RETURN'], true)) {
        return '+' . inventoryNumber(abs($number));
    }
    return ($number > 0 ? '+' : '') . inventoryNumber($number);
}

// Categories are loaded dynamically so new categories automatically become tabs.
$categoriesResult = $conn->query(
    "SELECT category_id, category_name, monitoring_frequency
     FROM inventory_categories
     ORDER BY category_name"
);
$categories = $categoriesResult->fetch_all(MYSQLI_ASSOC);

// Read-only, branch-specific inventory snapshot. Items with no stock row are included as zero stock.
$inventorySql = "
    SELECT
        i.item_id,
        i.item_name,
        i.description,
        i.minimum_stock,
        i.is_forecastable,
        c.category_id,
        c.category_name,
        c.monitoring_frequency,
        u.unit_name,
        COALESCE(SUM(s.quantity_available), 0) AS total_stock,
        COUNT(CASE WHEN s.quantity_available > 0 THEN 1 END) AS active_batches,
        MIN(CASE
                WHEN s.quantity_available > 0 AND s.expiration_date IS NOT NULL
                THEN s.expiration_date
            END) AS nearest_expiration,
        MAX(s.last_updated) AS last_updated
    FROM inventory_items i
    INNER JOIN inventory_categories c ON c.category_id = i.category_id
    INNER JOIN units u ON u.unit_id = i.unit_id
    LEFT JOIN inventory_stocks s
        ON s.item_id = i.item_id
       AND s.branch_id = ?
    GROUP BY
        i.item_id,
        i.item_name,
        i.description,
        i.minimum_stock,
        i.is_forecastable,
        c.category_id,
        c.category_name,
        c.monitoring_frequency,
        u.unit_name
    ORDER BY c.category_name, i.item_name
";
$inventoryStmt = $conn->prepare($inventorySql);
$inventoryStmt->bind_param('s', $branchId);
$inventoryStmt->execute();
$items = $inventoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$inventoryStmt->close();

$totalItems = count($items);
$stockedItems = 0;
$lowStockItems = 0;
$outOfStockItems = 0;
$categoryCounts = [];

foreach ($items as $item) {
    $stock = (float)$item['total_stock'];
    $minimum = (float)$item['minimum_stock'];
    $categoryId = (int)$item['category_id'];
    $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;

    if ($stock > 0) {
        $stockedItems++;
        if ($minimum > 0 && $stock <= $minimum) {
            $lowStockItems++;
        }
    } else {
        $outOfStockItems++;
    }
}

// Batches already expired or expiring within 30 days and still carrying stock.
$expiryStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM inventory_stocks
     WHERE branch_id = ?
       AND quantity_available > 0
       AND expiration_date IS NOT NULL
       AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
);
$expiryStmt->bind_param('s', $branchId);
$expiryStmt->execute();
$expiryAttentionCount = (int)($expiryStmt->get_result()->fetch_assoc()['total'] ?? 0);
$expiryStmt->close();

// Number of recorded stock movements this month, not a sum of mixed inventory units.
$movementStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM stock_transactions
     WHERE branch_id = ?
       AND transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
       AND transaction_date < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)"
);
$movementStmt->bind_param('s', $branchId);
$movementStmt->execute();
$monthlyMovementCount = (int)($movementStmt->get_result()->fetch_assoc()['total'] ?? 0);
$movementStmt->close();

// Recent movements provide decision context while remaining read-only.
$recentSql = "
    SELECT
        st.transaction_id,
        st.transaction_type,
        st.quantity,
        st.remarks,
        st.transaction_date,
        i.item_name,
        un.unit_name,
        COALESCE(u.username, 'System') AS performed_by
    FROM stock_transactions st
    INNER JOIN inventory_items i ON i.item_id = st.item_id
    INNER JOIN units un ON un.unit_id = i.unit_id
    LEFT JOIN users u ON u.user_id = st.user_id
    WHERE st.branch_id = ?
    ORDER BY st.transaction_date DESC, st.transaction_id DESC
    LIMIT 10
";
$recentStmt = $conn->prepare($recentSql);
$recentStmt->bind_param('s', $branchId);
$recentStmt->execute();
$recentMovements = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recentStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Overview - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --success: #28a745;
            --warning: #ffb800;
            --danger: #dc3545;
            --info: #12a8c0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f9faff;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 80px;
            padding: 0 35px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .topbar h3 {
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
        }

        .topbar h3 small {
            margin-left: 10px;
            color: #666;
            font-size: 16px;
            font-weight: 400;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-role {
            margin-left: 4px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .page-content { padding: 35px; }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(185px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            position: relative;
            display: flex;
            align-items: center;
            gap: 17px;
            min-height: 125px;
            overflow: hidden;
            padding: 21px 23px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, .06);
        }

        .stat-card::before {
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: var(--primary);
            content: '';
        }

        .stat-card.success::before { background: var(--success); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.danger::before { background: var(--danger); }
        .stat-card.info::before { background: var(--info); }

        .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
            color: var(--primary);
            font-size: 28px;
            flex-shrink: 0;
        }

        .stat-card.success .stat-icon { color: var(--success); }
        .stat-card.warning .stat-icon { color: #d59600; }
        .stat-card.danger .stat-icon { color: var(--danger); }
        .stat-card.info .stat-icon { color: var(--info); }

        .stat-label {
            margin-bottom: 3px;
            color: #71809d;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .25px;
            text-transform: uppercase;
        }

        .stat-number {
            color: #111827;
            font-size: 31px;
            font-weight: 700;
            line-height: 1.1;
        }

        .stat-description {
            margin-top: 5px;
            color: #8a94a6;
            font-size: 11px;
        }

        .decision-notice {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
            padding: 16px 18px;
            background: #eef1ff;
            border: 1px solid #dbe1ff;
            border-radius: 13px;
        }

        .notice-copy {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            color: #485574;
        }

        .notice-copy > i {
            color: var(--primary);
            font-size: 21px;
        }

        .notice-copy strong {
            display: block;
            margin-bottom: 2px;
            color: #26366f;
        }

        .notice-copy p {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
        }

        .forecast-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            flex-shrink: 0;
            padding: 10px 16px;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .forecast-btn:hover { color: #fff; background: #1d2863; }

        .content-card {
            overflow: hidden;
            margin-bottom: 25px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, .08);
        }

        .content-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h5 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            color: var(--primary);
            font-size: 19px;
            font-weight: 700;
        }

        .content-card-header p {
            margin: 6px 0 0;
            color: #8b95a7;
            font-size: 13px;
        }

        .section-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
        }

        .category-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 16px 24px 10px;
            scrollbar-width: thin;
        }

        .category-tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            flex-shrink: 0;
            padding: 9px 14px;
            color: #5d687b;
            background: #f4f6fa;
            border: 1px solid #e5e8ee;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .category-tab:hover { color: var(--primary); background: #edf0ff; }
        .category-tab.active { color: #fff; background: var(--primary); border-color: var(--primary); }

        .tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            background: rgba(255, 255, 255, .2);
            border-radius: 999px;
            font-size: 10px;
        }

        .category-tab:not(.active) .tab-count { background: #e5e8ee; }

        .filters {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) 210px auto;
            gap: 12px;
            padding: 10px 24px 18px;
        }

        .search-box { position: relative; }
        .search-box i {
            position: absolute;
            top: 50%;
            left: 14px;
            color: #98a2b3;
            transform: translateY(-50%);
        }

        .search-box .form-control { padding-left: 40px; }
        .filters .form-control, .filters .form-select {
            min-height: 42px;
            border-color: #dfe3eb;
            border-radius: 9px;
            font-size: 13px;
        }

        .result-count {
            display: flex;
            align-items: center;
            color: #7d8798;
            font-size: 12px;
            white-space: nowrap;
        }

        .inventory-table {
            min-width: 1100px;
            margin: 0;
        }

        .inventory-table thead th,
        .movement-table thead th {
            padding: 14px 18px;
            color: #667085;
            background: #f8f9fc;
            border-bottom: 1px solid #e6eaf0;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .25px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .inventory-table tbody td,
        .movement-table tbody td {
            padding: 16px 18px;
            color: #4c566a;
            border-color: #edf0f4;
            font-size: 13px;
            vertical-align: middle;
        }

        .inventory-table tbody tr:hover,
        .movement-table tbody tr:hover { background: #fbfcff; }

        .item-name { color: #25324b; font-size: 14px; font-weight: 700; }
        .item-description {
            max-width: 260px;
            margin-top: 3px;
            overflow: hidden;
            color: #8a94a6;
            font-size: 11px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .category-label {
            display: inline-block;
            padding: 5px 9px;
            color: #34447e;
            background: #eef1ff;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .monitoring-frequency { margin-top: 5px; color: #98a2b3; font-size: 10px; }
        .stock-number { color: #25324b; font-size: 18px; font-weight: 700; }
        .stock-unit { color: #8a94a6; font-size: 11px; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-good { color: #1f7a35; background: #ddf3e3; }
        .status-low { color: #8a6300; background: #fff3cd; }
        .status-out, .status-expired { color: #a22632; background: #fde2e5; }
        .status-expiring { color: #a65a00; background: #ffead2; }
        .status-muted { color: #5e6878; background: #edf0f4; }

        .expiry-date { color: #4c566a; font-weight: 600; }
        .expiry-badge { margin-top: 5px; }

        .decision-action {
            max-width: 180px;
            color: #667085;
            font-size: 11px;
            line-height: 1.4;
        }

        .forecast-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 7px;
            color: var(--primary);
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        .forecast-link:hover { text-decoration: underline; }

        .movement-table { min-width: 900px; margin: 0; }
        .movement-type {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 7px;
            font-size: 10px;
            font-weight: 700;
        }

        .movement-in { color: #1f7a35; background: #ddf3e3; }
        .movement-out { color: #a22632; background: #fde2e5; }
        .movement-adjustment { color: #8a6300; background: #fff3cd; }
        .quantity-change { color: #25324b; font-weight: 700; }
        .remarks-cell {
            max-width: 360px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .empty-state {
            padding: 48px 20px !important;
            color: #98a2b3 !important;
            text-align: center;
        }

        .empty-state i { display: block; margin-bottom: 8px; font-size: 35px; }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
        }

        @media (max-width: 767px) {
            .topbar { height: 70px; padding: 0 16px; }
            .topbar h3 { font-size: 20px; }
            .topbar h3 small, .profile-role { display: none; }
            .page-content { padding: 20px 16px; }
            .decision-notice { align-items: flex-start; flex-direction: column; }
            .forecast-btn { width: 100%; }
            .content-card-header { align-items: flex-start; flex-direction: column; }
            .filters { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo"></div>
            <div class="system-name">Smart Bite Care</div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
                <li><a class="active" href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
                <li><a href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>

        <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
    </div>

    <div class="main">
        <div class="topbar">
            <h3>Inventory Overview <small><?php echo workflowH($branchName); ?></small></h3>
            <div class="profile"><i class="bi bi-person-circle"></i><span><?php echo workflowH($username); ?></span><span class="profile-role">| Branch Admin</span></div>
        </div>

        <div class="page-content">
            <div class="stats-container">
                <div class="stat-card"><div class="stat-icon"><i class="bi bi-boxes"></i></div><div><div class="stat-label">Catalog Items</div><div class="stat-number"><?php echo number_format($totalItems); ?></div><div class="stat-description">All monitored inventory items</div></div></div>
                <div class="stat-card success"><div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div><div><div class="stat-label">Items With Stock</div><div class="stat-number"><?php echo number_format($stockedItems); ?></div><div class="stat-description">Available in this branch</div></div></div>
                <div class="stat-card warning"><div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div><div class="stat-label">Low-Stock Items</div><div class="stat-number"><?php echo number_format($lowStockItems); ?></div><div class="stat-description">At or below minimum level</div></div></div>
                <div class="stat-card danger"><div class="stat-icon"><i class="bi bi-x-octagon-fill"></i></div><div><div class="stat-label">Out of Stock</div><div class="stat-number"><?php echo number_format($outOfStockItems); ?></div><div class="stat-description">No available quantity</div></div></div>
                <div class="stat-card danger"><div class="stat-icon"><i class="bi bi-calendar-x-fill"></i></div><div><div class="stat-label">Expiry Attention</div><div class="stat-number"><?php echo number_format($expiryAttentionCount); ?></div><div class="stat-description">Expired or due within 30 days</div></div></div>
                <div class="stat-card info"><div class="stat-icon"><i class="bi bi-arrow-left-right"></i></div><div><div class="stat-label">Monthly Movements</div><div class="stat-number"><?php echo number_format($monthlyMovementCount); ?></div><div class="stat-description">Transactions recorded this month</div></div></div>
            </div>

            <div class="decision-notice">
                <div class="notice-copy"><i class="bi bi-info-circle-fill"></i><div><strong>Read-only decision support</strong><p>This page shows the current inventory condition for management review. Stock receiving, deductions, adjustments, and item maintenance remain under the Inventory Officer and authorized clinical workflow.</p></div></div>
                <a href="BranchAdmin_Forecasting.php" class="forecast-btn"><i class="bi bi-graph-up-arrow"></i>Open Supply Forecasting</a>
            </div>

            <section class="content-card">
                <div class="content-card-header">
                    <div><h5><span class="section-icon"><i class="bi bi-clipboard-data-fill"></i></span>Branch Inventory</h5><p>Review all categories, current stock, batches, expiry dates, and recommended management attention.</p></div>
                    <span class="badge rounded-pill text-bg-light border"><?php echo number_format($totalItems); ?> items</span>
                </div>

                <div class="category-tabs" role="tablist" aria-label="Inventory categories">
                    <button type="button" class="category-tab active" data-category="all" aria-selected="true">All Inventory <span class="tab-count"><?php echo number_format($totalItems); ?></span></button>
                    <?php foreach ($categories as $category): ?>
                        <button type="button" class="category-tab" data-category="<?php echo (int)$category['category_id']; ?>" aria-selected="false">
                            <?php echo workflowH($category['category_name']); ?>
                            <span class="tab-count"><?php echo number_format($categoryCounts[(int)$category['category_id']] ?? 0); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="filters">
                    <div class="search-box"><i class="bi bi-search"></i><input type="search" id="inventorySearch" class="form-control" placeholder="Search item, category, or unit"></div>
                    <select id="statusFilter" class="form-select" aria-label="Filter inventory status">
                        <option value="all">All statuses</option>
                        <option value="in-stock">In Stock</option>
                        <option value="low-stock">Low Stock</option>
                        <option value="out-of-stock">Out of Stock</option>
                        <option value="expiring">Expiring Soon</option>
                        <option value="expired">Expired</option>
                    </select>
                    <div class="result-count"><span id="visibleCount"><?php echo number_format($totalItems); ?></span>&nbsp;items shown</div>
                </div>

                <div class="table-responsive">
                    <table class="table inventory-table align-middle">
                        <thead><tr><th>Item</th><th>Category</th><th>Current Stock</th><th>Minimum</th><th>Batches</th><th>Nearest Expiry</th><th>Status</th><th>Decision Support</th></tr></thead>
                        <tbody id="inventoryTableBody">
                            <?php if (!$items): ?><tr class="initial-empty"><td colspan="8" class="empty-state"><i class="bi bi-inbox"></i>No inventory items are configured.</td></tr><?php endif; ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $stock = (float)$item['total_stock'];
                                $minimum = (float)$item['minimum_stock'];
                                [$stockLabel, $stockClass, $stockFilter] = inventoryStockStatus($stock, $minimum);
                                $expiryStatus = inventoryExpiryStatus($item['nearest_expiration'] ?? null);
                                $filterStatuses = $stockFilter . ($expiryStatus ? ' ' . $expiryStatus[2] : '');

                                if ($stock <= 0) {
                                    $decisionText = 'Coordinate replenishment with the Inventory Officer.';
                                } elseif ($expiryStatus && $expiryStatus[2] === 'expired') {
                                    $decisionText = 'Review the expired batch for proper inventory action.';
                                } elseif ($minimum > 0 && $stock <= $minimum) {
                                    $decisionText = 'Review this item for possible reorder.';
                                } elseif ($expiryStatus) {
                                    $decisionText = 'Review near-expiry stock for utilization planning.';
                                } else {
                                    $decisionText = 'No immediate inventory action indicated.';
                                }
                                $searchText = strtolower($item['item_name'] . ' ' . $item['category_name'] . ' ' . $item['unit_name']);
                                ?>
                                <tr class="inventory-row" data-category="<?php echo (int)$item['category_id']; ?>" data-status="<?php echo workflowH($filterStatuses); ?>" data-search="<?php echo workflowH($searchText); ?>">
                                    <td><div class="item-name"><?php echo workflowH($item['item_name']); ?></div><?php if (!empty($item['description'])): ?><div class="item-description" title="<?php echo workflowH($item['description']); ?>"><?php echo workflowH($item['description']); ?></div><?php endif; ?></td>
                                    <td><span class="category-label"><?php echo workflowH($item['category_name']); ?></span><div class="monitoring-frequency"><?php echo workflowH($item['monitoring_frequency'] ?? 'Not set'); ?> monitoring</div></td>
                                    <td><div class="stock-number"><?php echo inventoryNumber($stock); ?></div><div class="stock-unit"><?php echo workflowH($item['unit_name']); ?></div></td>
                                    <td><strong><?php echo inventoryNumber($minimum); ?></strong> <span class="stock-unit"><?php echo workflowH($item['unit_name']); ?></span></td>
                                    <td><?php echo number_format((int)$item['active_batches']); ?><div class="stock-unit">with available stock</div></td>
                                    <td>
                                        <?php if (!empty($item['nearest_expiration'])): ?>
                                            <div class="expiry-date"><?php echo workflowH(date('M d, Y', strtotime($item['nearest_expiration']))); ?></div>
                                            <?php if ($expiryStatus): ?><div class="expiry-badge"><span class="status-badge <?php echo workflowH($expiryStatus[1]); ?>"><?php echo workflowH($expiryStatus[0]); ?></span></div><?php endif; ?>
                                        <?php else: ?><span class="text-muted">Not applicable / none</span><?php endif; ?>
                                    </td>
                                    <td><span class="status-badge <?php echo workflowH($stockClass); ?>"><?php echo workflowH($stockLabel); ?></span><?php if (!empty($item['last_updated'])): ?><div class="monitoring-frequency">Updated <?php echo workflowH(date('M d, Y', strtotime($item['last_updated']))); ?></div><?php endif; ?></td>
                                    <td><div class="decision-action"><?php echo workflowH($decisionText); ?></div><?php if ((int)$item['is_forecastable'] === 1): ?><a href="BranchAdmin_Forecasting.php?item_id=<?php echo (int)$item['item_id']; ?>" class="forecast-link"><i class="bi bi-graph-up-arrow"></i>View forecast</a><?php else: ?><span class="status-badge status-muted mt-2">Not forecastable</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="filteredEmpty" style="display:none;"><td colspan="8" class="empty-state"><i class="bi bi-search"></i>No inventory items match the selected filters.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="content-card">
                <div class="content-card-header">
                    <div><h5><span class="section-icon"><i class="bi bi-clock-history"></i></span>Recent Inventory Movements</h5><p>Latest branch stock activity recorded by authorized system users.</p></div>
                    <span class="badge rounded-pill text-bg-light border">Last 10</span>
                </div>
                <div class="table-responsive">
                    <table class="table movement-table align-middle">
                        <thead><tr><th>Date and Time</th><th>Item</th><th>Type</th><th>Quantity</th><th>Recorded By</th><th>Remarks</th></tr></thead>
                        <tbody>
                            <?php if (!$recentMovements): ?><tr><td colspan="6" class="empty-state"><i class="bi bi-clock-history"></i>No stock movements have been recorded for this branch.</td></tr><?php endif; ?>
                            <?php foreach ($recentMovements as $movement): ?>
                                <tr>
                                    <td><?php echo workflowH(date('M d, Y', strtotime($movement['transaction_date']))); ?><div class="stock-unit"><?php echo workflowH(date('h:i A', strtotime($movement['transaction_date']))); ?></div></td>
                                    <td><div class="item-name"><?php echo workflowH($movement['item_name']); ?></div><div class="stock-unit"><?php echo workflowH($movement['unit_name']); ?></div></td>
                                    <td><span class="movement-type <?php echo workflowH(inventoryTransactionClass($movement['transaction_type'])); ?>"><?php echo workflowH(str_replace('_', ' ', $movement['transaction_type'])); ?></span></td>
                                    <td><span class="quantity-change"><?php echo workflowH(inventoryTransactionQuantity($movement['transaction_type'], $movement['quantity'])); ?></span> <span class="stock-unit"><?php echo workflowH($movement['unit_name']); ?></span></td>
                                    <td><?php echo workflowH($movement['performed_by']); ?></td>
                                    <td><div class="remarks-cell" title="<?php echo workflowH($movement['remarks'] ?? ''); ?>"><?php echo workflowH($movement['remarks'] ?: 'No remarks'); ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const tabs = document.querySelectorAll('.category-tab');
        const rows = Array.from(document.querySelectorAll('.inventory-row'));
        const searchInput = document.getElementById('inventorySearch');
        const statusFilter = document.getElementById('statusFilter');
        const visibleCount = document.getElementById('visibleCount');
        const filteredEmpty = document.getElementById('filteredEmpty');
        let selectedCategory = 'all';

        function applyInventoryFilters() {
            const searchValue = searchInput.value.trim().toLowerCase();
            const selectedStatus = statusFilter.value;
            let shown = 0;

            rows.forEach((row) => {
                const matchesCategory = selectedCategory === 'all' || row.dataset.category === selectedCategory;
                const matchesSearch = searchValue === '' || row.dataset.search.includes(searchValue);
                const statuses = row.dataset.status.split(' ');
                const matchesStatus = selectedStatus === 'all' || statuses.includes(selectedStatus);
                const display = matchesCategory && matchesSearch && matchesStatus;
                row.style.display = display ? '' : 'none';
                if (display) shown++;
            });

            visibleCount.textContent = shown.toLocaleString();
            filteredEmpty.style.display = rows.length > 0 && shown === 0 ? '' : 'none';
        }

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((other) => {
                    other.classList.remove('active');
                    other.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
                selectedCategory = tab.dataset.category;
                applyInventoryFilters();
            });
        });

        searchInput.addEventListener('input', applyInventoryFilters);
        statusFilter.addEventListener('change', applyInventoryFilters);
    </script>
</body>
</html>
