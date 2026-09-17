<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';

$user = workflowRequireUser($conn, 2);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Branch Admin');
$notification_count = getUnreadNotificationCount($conn, $userId);

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

// Only categories represented by inventory records in the logged-in branch
// become tabs. Global master categories belonging only to another branch are
// therefore not shown.
$categoriesStmt = $conn->prepare(
    "SELECT DISTINCT
        c.category_id,
        c.category_name,
        c.monitoring_frequency
     FROM inventory_categories c
     INNER JOIN inventory_items i ON i.category_id = c.category_id
     INNER JOIN inventory_stocks s ON s.item_id = i.item_id
     WHERE s.branch_id = ?
     ORDER BY c.category_name"
);
$categoriesStmt->bind_param('s', $branchId);
$categoriesStmt->execute();
$categories = $categoriesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$categoriesStmt->close();

// Read-only branch inventory snapshot. Starting from the branch's stock rows
// prevents global master items that were never assigned to this branch from
// appearing as zero-stock records.
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
    INNER JOIN inventory_stocks s
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

/* =========================================================
   GLOBAL LOGOUT CONFIRMATION MODAL 
   ========================================================= */

.confirm-modal .modal-dialog {
    max-width: 500px !important;
    width: calc(100% - 30px);
    margin: 1.75rem auto;
}

.confirm-modal .modal-content {
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 20px !important;
    background: #fff !important;
    box-shadow: 0 20px 55px rgba(31, 45, 110, 0.20) !important;
}

.confirm-modal .modal-header {
    display: block !important;
    padding: 26px 24px 6px !important;
    border: 0 !important;
    background: #fff !important;
    text-align: center !important;
}

.confirm-modal .modal-icon {
    width: 64px !important;
    height: 64px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 14px !important;

    color: #fff !important;
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    border-radius: 50% !important;

    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
    font-size: 27px !important;
}

.confirm-modal .modal-icon.logout-icon {
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
}

.confirm-modal .modal-title {
    margin: 0 !important;
    color: #283a7a !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    line-height: 1.3 !important;
}

.confirm-modal .modal-body {
    padding: 6px 30px 22px !important;
    background: #fff !important;
    color: #7a879e !important;
    text-align: center !important;
}

.confirm-modal .modal-body p {
    margin: 0 !important;
    color: #7a879e !important;
    font-size: 17px !important;
    line-height: 1.45 !important;
}

.confirm-modal .modal-footer {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    padding: 0 24px 26px !important;
    border: 0 !important;
    background: #fff !important;
}

.confirm-modal .modal-footer .btn {
    min-height: 50px !important;
    margin: 0 !important;
    border-radius: 10px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.confirm-modal .btn-light,
.confirm-modal .btn-cancel {
    color: #111 !important;
    background: #f8f9fa !important;
    border: 1px solid #d9dfe8 !important;
}

.confirm-modal .btn-light:hover,
.confirm-modal .btn-cancel:hover {
    background: #eef0f3 !important;
}

.confirm-modal .btn-danger,
.confirm-modal .btn-logout {
    color: #fff !important;
    background: #e83445 !important;
    border: 1px solid #e83445 !important;
    text-decoration: none !important;
}

.confirm-modal .btn-danger:hover,
.confirm-modal .btn-logout:hover {
    color: #fff !important;
    background: #d92839 !important;
    border-color: #d92839 !important;
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
    
        /* =========================================================
           CLIENT-SIDE TABLE PAGINATION
           ========================================================= */
        .table-pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 14px 20px;
            background: #fff;
            border-top: 1px solid #edf0f5;
        }

        .table-pagination-summary {
            color: #6f7b91;
            font-size: 12px;
        }

        .table-pagination-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rows-per-page-control {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #6f7b91;
            font-size: 12px;
            white-space: nowrap;
        }

        .rows-per-page-control select {
            width: 78px;
            min-height: 34px;
            padding: 4px 28px 4px 10px;
            border: 1px solid #dde3ee;
            border-radius: 8px;
            background-color: #fff;
            color: #34405d;
            font-size: 12px;
        }

        .table-page-buttons {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .table-page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dde3ee;
            border-radius: 8px;
            background: #fff;
            color: #2B3A8C;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
        }

        .table-page-btn:hover:not(:disabled),
        .table-page-btn.active {
            color: #fff;
            background: #2B3A8C;
            border-color: #2B3A8C;
        }

        .table-page-btn:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        @media (max-width: 576px) {
            .table-pagination-bar {
                align-items: flex-start;
                flex-direction: column;
            }
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
                <li><a href="BranchAdmin_Notifications.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>
</div>

    <div class="main">
        <div class="topbar">
            <h3>Inventory Overview <small><?php echo workflowH($branchName); ?></small></h3>
           <div class="dropdown">
                <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                        type="button" id="branchAdminProfileMenu"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="profile-role">| Branch Admin</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                    aria-labelledby="branchAdminProfileMenu">
                    <li><h6 class="dropdown-header">Account options</h6></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                            <i class="bi bi-key-fill me-2"></i>Change Password
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                         <a class="dropdown-item rounded-2 py-2 text-danger"
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#logoutConfirmModal">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
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
                    <div class="result-count"><span id="visibleCount"><?php echo number_format($totalItems); ?></span>&nbsp;items found</div>
                </div>

                <div class="table-responsive">
                    <table class="table inventory-table align-middle" id="inventoryOverviewTable">
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

                <div class="table-pagination-bar" id="inventoryPaginationBar">
                    <div class="table-pagination-summary" id="inventoryPaginationSummary">Showing inventory items</div>
                    <div class="table-pagination-actions">
                        <label class="rows-per-page-control" for="inventoryRowsPerPage">
                            Rows
                            <select id="inventoryRowsPerPage">
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                        <div class="table-page-buttons" id="inventoryPageButtons" aria-label="Inventory table pages"></div>
                    </div>
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

    <div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2 class="modal-title" id="logoutConfirmModalLabel">
                    Log out of Smart Bite Care?
                </h2>
            </div>

            <div class="modal-body">
                <p class="mb-0">
                    Make sure you have saved any unfinished work before leaving your account.
                </p>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="logout.php"
                    class="btn btn-danger d-flex align-items-center justify-content-center"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>
            </div>

        </div>
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

        const inventoryRowsPerPage = document.getElementById('inventoryRowsPerPage');
        const inventoryPageButtons = document.getElementById('inventoryPageButtons');
        const inventoryPaginationSummary = document.getElementById('inventoryPaginationSummary');
        let inventoryCurrentPage = 1;

        function renderInventoryPagination(totalPages) {
            inventoryPageButtons.innerHTML = '';

            if (totalPages <= 1) {
                return;
            }

            const addButton = (label, page, disabled = false, active = false) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'table-page-btn' + (active ? ' active' : '');
                button.innerHTML = label;
                button.disabled = disabled;

                if (!disabled && !active) {
                    button.addEventListener('click', () => {
                        inventoryCurrentPage = page;
                        applyInventoryFilters(false);
                    });
                }

                inventoryPageButtons.appendChild(button);
            };

            addButton('<i class="bi bi-chevron-left"></i>', inventoryCurrentPage - 1, inventoryCurrentPage === 1);

            let startPage = Math.max(1, inventoryCurrentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            startPage = Math.max(1, endPage - 4);

            for (let page = startPage; page <= endPage; page++) {
                addButton(String(page), page, false, page === inventoryCurrentPage);
            }

            addButton('<i class="bi bi-chevron-right"></i>', inventoryCurrentPage + 1, inventoryCurrentPage === totalPages);
        }

        function applyInventoryFilters(resetPage = true) {
            if (resetPage) {
                inventoryCurrentPage = 1;
            }

            const searchValue = searchInput.value.trim().toLowerCase();
            const selectedStatus = statusFilter.value;

            const matchingRows = rows.filter((row) => {
                const matchesCategory = selectedCategory === 'all' || row.dataset.category === selectedCategory;
                const matchesSearch = searchValue === '' || row.dataset.search.includes(searchValue);
                const statuses = row.dataset.status.split(' ');
                const matchesStatus = selectedStatus === 'all' || statuses.includes(selectedStatus);

                return matchesCategory && matchesSearch && matchesStatus;
            });

            const rowsPerPage = Math.max(1, parseInt(inventoryRowsPerPage.value, 10) || 10);
            const totalPages = Math.max(1, Math.ceil(matchingRows.length / rowsPerPage));

            if (inventoryCurrentPage > totalPages) {
                inventoryCurrentPage = totalPages;
            }

            rows.forEach((row) => {
                row.style.display = 'none';
            });

            const startIndex = (inventoryCurrentPage - 1) * rowsPerPage;
            const endIndex = Math.min(startIndex + rowsPerPage, matchingRows.length);

            matchingRows.slice(startIndex, endIndex).forEach((row) => {
                row.style.display = '';
            });

            visibleCount.textContent = matchingRows.length.toLocaleString();
            filteredEmpty.style.display = rows.length > 0 && matchingRows.length === 0 ? '' : 'none';

            if (matchingRows.length === 0) {
                inventoryPaginationSummary.textContent = 'No inventory items match the selected filters.';
                inventoryPageButtons.innerHTML = '';
            } else {
                inventoryPaginationSummary.textContent =
                    `Showing ${startIndex + 1}–${endIndex} of ${matchingRows.length} item${matchingRows.length === 1 ? '' : 's'}`;
                renderInventoryPagination(totalPages);
            }
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
                applyInventoryFilters(true);
            });
        });

        searchInput.addEventListener('input', () => applyInventoryFilters(true));
        statusFilter.addEventListener('change', () => applyInventoryFilters(true));
        inventoryRowsPerPage.addEventListener('change', () => applyInventoryFilters(true));

        applyInventoryFilters(true);
    </script>
</body>
</html>
