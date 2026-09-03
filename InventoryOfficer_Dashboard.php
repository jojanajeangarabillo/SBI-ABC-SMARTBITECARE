<?php
session_start();
require_once 'sources/db_connect.php';

/* =========================================================
   ACCESS CONTROL
   Inventory Officer = role_id 5
   ========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    (int)$_SESSION['role_id'] !== 5
) {
    header('Location: login.php');
    exit();
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $tableName)
{
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function statusBadgeClass($status)
{
    switch ($status) {
        case 'Out of Stock': return 'badge-critical';
        case 'Low Stock': return 'badge-low';
        case 'Expired': return 'badge-critical';
        case 'Expires Today': return 'badge-today';
        case 'Expiring Soon': return 'badge-expiring';
        case 'Stock In': return 'badge-in';
        case 'Stock Out': return 'badge-out';
        case 'Adjustment': return 'badge-adjustment';
        default: return 'badge-neutral';
    }
}

function transactionDisplayType($type, $quantity)
{
    switch ($type) {
        case 'IN':
            return ['Stock In', '+'];
        case 'OUT':
            return ['Stock Out', '-'];
        case 'ADJUSTMENT':
            return ['Adjustment', ((int)$quantity < 0 ? '-' : '+')];
        default:
            return [$type ?: 'Unknown', ((int)$quantity < 0 ? '-' : '+')];
    }
}

function expiryInfo($date)
{
    if (!$date) {
        return ['No Expiration', null, 'badge-neutral'];
    }

    try {
        $today = new DateTime('today');
        $expiry = new DateTime($date);
        $days = (int)$today->diff($expiry)->format('%r%a');
    } catch (Throwable $e) {
        return ['Invalid Date', null, 'badge-critical'];
    }

    if ($days < 0) {
        return ['Expired', $days, 'badge-critical'];
    }

    if ($days === 0) {
        return ['Expires Today', 0, 'badge-today'];
    }

    return ['Expiring Soon', $days, 'badge-expiring'];
}

/* =========================================================
   CURRENT USER / BRANCH
   ========================================================= */
$user_id = (int)$_SESSION['user_id'];
$branch_id = null;
$branch_name = 'No Branch Assigned';
$username = 'Inventory Officer';

$userQuery = "SELECT
                    u.branch_id,
                    u.username,
                    b.branch_name
              FROM users u
              LEFT JOIN branches b
                ON u.branch_id = b.branch_id
              WHERE u.user_id = ?
                AND u.status = 'Active'
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
$userData = $userResult->fetch_assoc();
$userStmt->close();

if (!$userData) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}

$branch_id = $userData['branch_id'] ?? null;
$username = $userData['username'] ?: 'Inventory Officer';
$branch_name = $userData['branch_name'] ?: 'No Branch Assigned';

/* =========================================================
   DASHBOARD DATA

   IMPORTANT INVENTORY RULES:
   - inventory_items = master item
   - inventory_stocks = branch/batch stock
   - stock quantities are aggregated at ITEM level
   - expired stock is NOT usable stock
   - expired batches with remaining quantity are disposal alerts
   ========================================================= */
$stats = [
    'inventory_items' => 0,
    'low_stocks' => 0,
    'out_of_stock' => 0,
    'attention_items' => 0,
    'expiring_stocks' => 0,
    'expired_stocks' => 0,
    'archived_stocks' => 0,
    'recent_transactions' => 0
];

$lowStockItems = [];
$expirationAlerts = [];
$recentTransactionsList = [];

/* Total master inventory items. */
$itemCountResult = $conn->query("SELECT COUNT(*) AS total FROM inventory_items");
if ($itemCountResult) {
    $stats['inventory_items'] = (int)($itemCountResult->fetch_assoc()['total'] ?? 0);
}

if (!empty($branch_id)) {

    /* ---------------------------------------------------------
       LOW / OUT OF STOCK COUNTS
       Uses TOTAL USABLE stock per item, not individual batches.
       --------------------------------------------------------- */
    $stockAlertSql = "
        SELECT
            COALESCE(SUM(CASE
                WHEN x.usable_stock <= 0 THEN 1
                ELSE 0
            END), 0) AS out_of_stock,

            COALESCE(SUM(CASE
                WHEN x.usable_stock > 0
                 AND x.usable_stock <= x.minimum_stock
                THEN 1
                ELSE 0
            END), 0) AS low_stock

        FROM (
            SELECT
                i.item_id,
                i.minimum_stock,
                COALESCE(
                    SUM(
                        CASE
                            WHEN s.quantity_available > 0
                             AND (
                                    s.expiration_date IS NULL
                                    OR s.expiration_date >= CURDATE()
                                 )
                            THEN s.quantity_available
                            ELSE 0
                        END
                    ),
                    0
                ) AS usable_stock

            FROM inventory_items i

            LEFT JOIN inventory_stocks s
              ON s.item_id = i.item_id
             AND s.branch_id = ?

            GROUP BY
                i.item_id,
                i.minimum_stock
        ) x
    ";

    $stmt = $conn->prepare($stockAlertSql);
    if (!$stmt) {
        die('Database error: Unable to prepare stock alert query.');
    }
    $stmt->bind_param('s', $branch_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stats['out_of_stock'] = (int)($row['out_of_stock'] ?? 0);
    $stats['low_stocks'] = (int)($row['low_stock'] ?? 0);
    $stats['attention_items'] = $stats['out_of_stock'] + $stats['low_stocks'];

    /* ---------------------------------------------------------
       EXPIRING SOON - active batches with remaining quantity.
       --------------------------------------------------------- */
    $query = "SELECT COUNT(*) AS expiring_count
              FROM inventory_stocks
              WHERE branch_id = ?
                AND quantity_available > 0
                AND expiration_date IS NOT NULL
                AND expiration_date >= CURDATE()
                AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        die('Database error: Unable to prepare expiration query.');
    }
    $stmt->bind_param('s', $branch_id);
    $stmt->execute();
    $stats['expiring_stocks'] = (int)($stmt->get_result()->fetch_assoc()['expiring_count'] ?? 0);
    $stmt->close();

    /* ---------------------------------------------------------
       EXPIRED FOR DISPOSAL - still-active expired batches.
       --------------------------------------------------------- */
    $query = "SELECT COUNT(*) AS expired_count
              FROM inventory_stocks
              WHERE branch_id = ?
                AND quantity_available > 0
                AND expiration_date IS NOT NULL
                AND expiration_date < CURDATE()";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        die('Database error: Unable to prepare expired stock query.');
    }
    $stmt->bind_param('s', $branch_id);
    $stmt->execute();
    $stats['expired_stocks'] = (int)($stmt->get_result()->fetch_assoc()['expired_count'] ?? 0);
    $stmt->close();

    /* Archived stock count is optional for older databases. */
    if (tableExists($conn, 'inventory_stocks_archive')) {
        $query = "SELECT COUNT(*) AS archived_count
                  FROM inventory_stocks_archive
                  WHERE branch_id = ?";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param('s', $branch_id);
            $stmt->execute();
            $stats['archived_stocks'] = (int)($stmt->get_result()->fetch_assoc()['archived_count'] ?? 0);
            $stmt->close();
        }
    }

    /* ---------------------------------------------------------
       RECENT TRANSACTION COUNT - last 30 days.
       --------------------------------------------------------- */
    $query = "SELECT COUNT(*) AS recent_count
              FROM stock_transactions
              WHERE branch_id = ?
                AND transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        die('Database error: Unable to prepare transaction count query.');
    }
    $stmt->bind_param('s', $branch_id);
    $stmt->execute();
    $stats['recent_transactions'] = (int)($stmt->get_result()->fetch_assoc()['recent_count'] ?? 0);
    $stmt->close();

    /* ---------------------------------------------------------
       LOW / OUT OF STOCK ITEM LIST
       One row per item. Uses non-expired usable stock only.
       --------------------------------------------------------- */
    $lowStockQuery = "
        SELECT
            x.item_id,
            x.item_name,
            x.minimum_stock,
            x.category_name,
            x.unit_name,
            x.usable_stock,
            x.expired_stock
        FROM (
            SELECT
                i.item_id,
                i.item_name,
                i.minimum_stock,
                c.category_name,
                u.unit_name,

                COALESCE(
                    SUM(
                        CASE
                            WHEN s.quantity_available > 0
                             AND (
                                    s.expiration_date IS NULL
                                    OR s.expiration_date >= CURDATE()
                                 )
                            THEN s.quantity_available
                            ELSE 0
                        END
                    ),
                    0
                ) AS usable_stock,

                COALESCE(
                    SUM(
                        CASE
                            WHEN s.quantity_available > 0
                             AND s.expiration_date IS NOT NULL
                             AND s.expiration_date < CURDATE()
                            THEN s.quantity_available
                            ELSE 0
                        END
                    ),
                    0
                ) AS expired_stock

            FROM inventory_items i

            INNER JOIN inventory_categories c
              ON c.category_id = i.category_id

            INNER JOIN units u
              ON u.unit_id = i.unit_id

            LEFT JOIN inventory_stocks s
              ON s.item_id = i.item_id
             AND s.branch_id = ?

            GROUP BY
                i.item_id,
                i.item_name,
                i.minimum_stock,
                c.category_name,
                u.unit_name
        ) AS x

        WHERE
            x.usable_stock <= 0
            OR (
                x.usable_stock > 0
                AND x.usable_stock <= x.minimum_stock
            )

        ORDER BY
            CASE WHEN x.usable_stock <= 0 THEN 0 ELSE 1 END ASC,
            x.usable_stock ASC,
            x.item_name ASC

        LIMIT 10
    ";

    $stmt = $conn->prepare($lowStockQuery);
    if (!$stmt) {
        die('Database error: Unable to prepare low-stock item query.');
    }
    $stmt->bind_param('s', $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $usable = (int)$row['usable_stock'];
        $minimum = (int)$row['minimum_stock'];
        $expired = (int)$row['expired_stock'];

        $status = $usable <= 0 ? 'Out of Stock' : 'Low Stock';

        $row['status'] = $status;
        $row['item_id_formatted'] = 'ITM-' . str_pad((string)$row['item_id'], 4, '0', STR_PAD_LEFT);
        $row['stock_display'] = $usable . ' ' . $row['unit_name'];
        $row['minimum_display'] = $minimum . ' ' . $row['unit_name'];
        $row['expired_display'] = $expired > 0 ? $expired . ' ' . $row['unit_name'] : '';

        $lowStockItems[] = $row;
    }
    $stmt->close();

    /* ---------------------------------------------------------
       EXPIRATION ALERTS
       Shows expired and next-30-day batches with stock remaining.
       --------------------------------------------------------- */
    $expirationQuery = "
        SELECT
            s.stock_id,
            s.item_id,
            s.batch_lot_no,
            s.quantity_available,
            s.expiration_date,
            i.item_name,
            u.unit_name
        FROM inventory_stocks s
        INNER JOIN inventory_items i
          ON i.item_id = s.item_id
        INNER JOIN units u
          ON u.unit_id = i.unit_id
        WHERE s.branch_id = ?
          AND s.quantity_available > 0
          AND s.expiration_date IS NOT NULL
          AND s.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY
            CASE WHEN s.expiration_date < CURDATE() THEN 0 ELSE 1 END ASC,
            s.expiration_date ASC,
            i.item_name ASC
        LIMIT 10
    ";

    $stmt = $conn->prepare($expirationQuery);
    if (!$stmt) {
        die('Database error: Unable to prepare expiration alert query.');
    }
    $stmt->bind_param('s', $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        [$status, $days, $badgeClass] = expiryInfo($row['expiration_date']);
        $row['status'] = $status;
        $row['days'] = $days;
        $row['badge_class'] = $badgeClass;
        $expirationAlerts[] = $row;
    }
    $stmt->close();

    /* ---------------------------------------------------------
       RECENT TRANSACTIONS - last 10.
       --------------------------------------------------------- */
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
        ORDER BY
            st.transaction_date DESC,
            st.transaction_id DESC
        LIMIT 10
    ";

    $transactionStmt = $conn->prepare($transactionQuery);
    if (!$transactionStmt) {
        die('Database error: Unable to prepare recent transactions query.');
    }
    $transactionStmt->bind_param('s', $branch_id);
    $transactionStmt->execute();
    $transactionResult = $transactionStmt->get_result();

    while ($row = $transactionResult->fetch_assoc()) {
        [$displayType, $sign] = transactionDisplayType(
            $row['transaction_type'],
            $row['quantity']
        );

        $quantity = abs((int)$row['quantity']);
        $unitName = $row['unit_name'] ?? '';

        $recentTransactionsList[] = [
            'transaction_id' => (int)$row['transaction_id'],
            'trx' => 'TRX-' . str_pad((string)$row['transaction_id'], 4, '0', STR_PAD_LEFT),
            'type' => $displayType,
            'item' => $row['item_name'] ?? 'Unknown Item',
            'qty' => $sign . $quantity . ($unitName !== '' ? ' ' . $unitName : ''),
            'date' => date('M d, Y', strtotime($row['transaction_date'])),
            'date_full' => date('M d, Y h:i A', strtotime($row['transaction_date'])),
            'by' => $row['username'] ?? 'Unknown User',
            'remarks' => trim((string)($row['remarks'] ?? ''))
        ];
    }

    $transactionStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Officer Dashboard - <?php echo h($branch_name); ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="sidebar.css">

<style>
:root{
    --primary:#2B3A8C;
    --primary-dark:#1d2863;
    --accent:#F21D2F;
    --bg:#f0f2f5;
    --card-shadow:0 3px 12px rgba(0,0,0,.06);
    --success:#28a745;
    --warning:#f0ad00;
    --danger:#dc3545;
    --info:#17a2b8;
}

*{box-sizing:border-box;}

body{
    background:var(--bg);
    font-family:'Segoe UI','Inter',sans-serif;
    color:#1f2937;
}

.main{
    margin-left:260px;
    min-height:100vh;
    background:#f9faff;
}

.topbar{
    background:white;
    height:80px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 35px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    border-bottom:1px solid #e9edf5;
}

.topbar h3{
    font-size:28px;
    font-weight:700;
    color:var(--primary);
    margin:0;
}

.topbar h3 small{
    font-size:15px;
    font-weight:400;
    color:#6c757d;
    margin-left:10px;
}

.profile{
    display:flex;
    align-items:center;
    gap:6px;
    font-weight:600;
    color:var(--primary);
}

.role-label{
    font-size:12px;
    color:#adb5bd;
    font-weight:400;
    margin-left:4px;
}

.dashboard{
    padding:32px 35px 40px;
}

.branch-warning{
    background:#fff8e5;
    border:1px solid #ffe39a;
    border-left:5px solid var(--warning);
    border-radius:12px;
    padding:14px 16px;
    margin-bottom:22px;
    color:#715600;
}

/* QUICK ACTIONS */
.quick-actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:24px;
}

.quick-action{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:9px 16px;
    border-radius:999px;
    background:#eceef7;
    color:var(--primary);
    border:1px solid transparent;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    transition:.15s ease;
}

.quick-action:hover{
    background:#dfe3f5;
    color:var(--primary-dark);
    transform:translateY(-1px);
}

/* STAT CARDS */
.stat-card-link{
    display:block;
    height:100%;
    text-decoration:none;
    color:inherit;
}

.stat-card{
    background:#fff;
    border-radius:16px;
    padding:18px 20px;
    min-height:120px;
    height:100%;
    display:grid;
    grid-template-columns:46px 1fr;
    grid-template-rows:auto auto auto;
    column-gap:14px;
    align-items:center;
    box-shadow:var(--card-shadow);
    border-left:5px solid #ccc;
    transition:transform .18s ease, box-shadow .18s ease;
}

.stat-card:hover{
    transform:translateY(-3px);
    box-shadow:0 7px 18px rgba(0,0,0,.09);
}

.stat-icon{
    grid-column:1;
    grid-row:1 / 4;
    font-size:31px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.stat-title{
    grid-column:2;
    font-size:13px;
    font-weight:600;
    color:#536174;
    margin:0;
}

.stat-number{
    grid-column:2;
    font-size:29px;
    font-weight:750;
    color:#111827;
    line-height:1.1;
}

.stat-subtitle{
    grid-column:2;
    font-size:12px;
    color:#7a8494;
    line-height:1.35;
}

.stat-card-primary{border-left-color:var(--primary);}
.stat-card-primary .stat-icon{color:var(--primary);}
.stat-card-danger{border-left-color:var(--danger);}
.stat-card-danger .stat-icon{color:var(--danger);}
.stat-card-warning{border-left-color:var(--warning);}
.stat-card-warning .stat-icon{color:var(--warning);}
.stat-card-info{border-left-color:var(--info);}
.stat-card-info .stat-icon{color:var(--info);}

/* CONTENT CARDS */
.large-card{
    background:#fff;
    border-radius:16px;
    padding:20px;
    box-shadow:var(--card-shadow);
    height:100%;
}

.card-header-custom{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding-bottom:13px;
    border-bottom:1px solid #edf0f6;
    margin-bottom:16px;
}

.section-title{
    font-size:18px;
    font-weight:700;
    color:var(--primary);
    margin:0;
    display:flex;
    align-items:center;
    gap:8px;
}

.header-count{
    border-radius:999px;
    padding:5px 10px;
    font-size:11px;
    font-weight:700;
}

/* TABLES */
.table-wrap{
    border-radius:11px;
    overflow-x:auto;
    border:1px solid #eef0f5;
}

.data-table{
    margin:0;
    font-size:14px;
    min-width:650px;
}

.data-table thead th{
    background:var(--primary);
    color:white;
    font-weight:600;
    font-size:12px;
    border:none;
    padding:13px 14px;
    white-space:nowrap;
    text-transform:uppercase;
    letter-spacing:.3px;
}

.data-table tbody td{
    font-size:13px;
    color:#333;
    padding:12px 14px;
    vertical-align:middle;
    border-bottom:1px solid #f0f2f7;
}

.data-table tbody tr:last-child td{border-bottom:none;}
.data-table tbody tr:hover{background:#fafbff;}

.item-name{
    font-weight:650;
    color:#1f2a4a;
}

.subtext{
    display:block;
    color:#8791a3;
    font-size:11px;
    margin-top:2px;
}

.text-expired{
    color:var(--danger);
    font-weight:600;
}

/* BADGES */
.badge-status{
    display:inline-block;
    padding:5px 11px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    white-space:nowrap;
}

.badge-critical{background:#fde7e9;color:#c82333;}
.badge-low{background:#fff0c7;color:#8a6500;}
.badge-expiring{background:#e1f5fa;color:#087d97;}
.badge-today{background:#fff0c7;color:#8a6500;}
.badge-in{background:#e6f4ea;color:#1e7b34;}
.badge-out{background:#edeffa;color:var(--primary);}
.badge-adjustment{background:#fff3cd;color:#856404;}
.badge-neutral{background:#eef0f4;color:#5f6877;}

/* BUTTONS */
.btn-custom{
    background:var(--primary);
    color:white;
    border-radius:8px;
    padding:8px 20px;
    border:none;
    font-weight:600;
    font-size:13px;
}

.btn-custom:hover{
    background:var(--primary-dark);
    color:white;
}

.action-btn{
    border:1px solid #dfe3ee;
    background:white;
    color:var(--primary);
    width:32px;
    height:32px;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    transition:.15s ease;
}

.action-btn:hover{
    background:var(--primary);
    color:white;
    border-color:var(--primary);
}

.action-btn.danger:hover{
    background:var(--danger);
    border-color:var(--danger);
}

/* EMPTY STATE */
.empty-state{
    text-align:center;
    padding:30px 10px;
    color:#8992a2;
}

.empty-state i{
    font-size:30px;
    margin-bottom:8px;
    display:block;
}

.empty-state p{margin:0;}

/* TRANSACTION MODAL */
.modal-content{
    border:none;
    border-radius:16px;
    box-shadow:0 14px 45px rgba(0,0,0,.18);
}

.modal-header{
    background:var(--primary);
    color:white;
    border-radius:16px 16px 0 0;
    border-bottom:none;
}

.detail-label{
    color:#7a8494;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.3px;
    margin-bottom:3px;
}

.detail-value{
    color:#1f2a4a;
    font-weight:600;
    word-break:break-word;
}

.remarks-box{
    background:#f5f7fc;
    border:1px solid #e4e8f2;
    border-radius:10px;
    padding:12px;
    color:#526079;
    min-height:48px;
    white-space:pre-wrap;
}

@media(max-width:991px){
    .main{margin-left:90px;}
    .dashboard{padding:24px 18px 35px;}
    .topbar{padding:0 18px;}
}

@media(max-width:576px){
    .topbar{height:70px;padding:0 14px;}
    .topbar h3{font-size:21px;}
    .topbar h3 small{display:block;margin-left:0;font-size:11px;margin-top:2px;}
    .role-label{display:none;}
    .dashboard{padding:18px 14px 30px;}
    .stat-card{min-height:108px;}
    .quick-action{flex:1 1 calc(50% - 8px);justify-content:center;}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

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
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">

    <div class="topbar">
        <h3>
            Dashboard
            <small><?php echo h($branch_name); ?></small>
        </h3>

        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <?php echo h($username); ?>
            <span class="role-label">| Inventory Officer</span>
        </div>
    </div>

    <div class="dashboard">

        <?php if (empty($branch_id)): ?>
            <div class="branch-warning">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Your account has no branch assigned. Branch inventory statistics cannot be loaded.
            </div>
        <?php endif; ?>

      

        <!-- TOP STAT CARDS -->
        <div class="row g-4">

            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="InventoryOfficer_InventoryItems.php">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
                        <div class="stat-title">Inventory Items</div>
                        <div class="stat-number"><?php echo number_format($stats['inventory_items']); ?></div>
                        <div class="stat-subtitle">Master items available</div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="#stockAlerts">
                    <div class="stat-card stat-card-danger">
                        <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                        <div class="stat-title">Low / Out of Stock</div>
                        <div class="stat-number"><?php echo number_format($stats['attention_items']); ?></div>
                        <div class="stat-subtitle">
                            <?php echo number_format($stats['low_stocks']); ?> low ·
                            <?php echo number_format($stats['out_of_stock']); ?> out
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="InventoryOfficer_StockManagement.php?panel=expiration">
                    <div class="stat-card stat-card-info">
                        <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                        <div class="stat-title">Expiring Soon</div>
                        <div class="stat-number"><?php echo number_format($stats['expiring_stocks']); ?></div>
                        <div class="stat-subtitle">Active batches within 30 days</div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="InventoryOfficer_StockManagement.php?panel=expiration">
                    <div class="stat-card stat-card-warning">
                        <div class="stat-icon"><i class="bi bi-archive-fill"></i></div>
                        <div class="stat-title">Expired for Disposal</div>
                        <div class="stat-number"><?php echo number_format($stats['expired_stocks']); ?></div>
                        <div class="stat-subtitle">
                            <?php echo number_format($stats['archived_stocks']); ?> archived record<?php echo $stats['archived_stocks'] === 1 ? '' : 's'; ?>
                        </div>
                    </div>
                </a>
            </div>

        </div>

        <!-- ALERT TABLES -->
        <div class="row g-4 mt-1">

            <!-- LOW / OUT OF STOCK -->
            <div class="col-xl-6" id="stockAlerts">
                <div class="large-card">
                    <div class="card-header-custom">
                        <h5 class="section-title">
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                            Stock Alerts
                        </h5>
                        <span class="header-count bg-danger text-white">
                            <?php echo number_format($stats['attention_items']); ?> item<?php echo $stats['attention_items'] === 1 ? '' : 's'; ?>
                        </span>
                    </div>

                    <div class="table-wrap">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Usable Stock</th>
                                    <th>Min</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($lowStockItems)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class="bi bi-check-circle text-success"></i>
                                            <p>No low or out-of-stock items.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lowStockItems as $item): ?>
                                    <tr>
                                        <td>
                                            <span class="item-name"><?php echo h($item['item_name']); ?></span>
                                            <span class="subtext"><?php echo h($item['item_id_formatted']); ?></span>
                                        </td>
                                        <td><?php echo h($item['category_name']); ?></td>
                                        <td>
                                            <strong><?php echo h($item['stock_display']); ?></strong>
                                            <?php if (!empty($item['expired_display'])): ?>
                                                <span class="subtext text-expired">
                                                    <?php echo h($item['expired_display']); ?> expired
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h($item['minimum_display']); ?></td>
                                        <td>
                                            <span class="badge-status <?php echo h(statusBadgeClass($item['status'])); ?>">
                                                <?php echo h($item['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-end mt-3">
                        <a class="btn btn-custom" href="InventoryOfficer_InventoryItems.php">
                            View Inventory Items
                        </a>
                    </div>
                </div>
            </div>

            <!-- EXPIRATION ALERTS -->
            <div class="col-xl-6">
                <div class="large-card">
                    <div class="card-header-custom">
                        <h5 class="section-title">
                            <i class="bi bi-hourglass-split text-warning"></i>
                            Expiration Alerts
                        </h5>
                        <span class="header-count bg-warning text-dark">
                            <?php echo number_format($stats['expired_stocks'] + $stats['expiring_stocks']); ?> batch<?php echo ($stats['expired_stocks'] + $stats['expiring_stocks']) === 1 ? '' : 'es'; ?>
                        </span>
                    </div>

                    <div class="table-wrap">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Batch/Lot</th>
                                    <th>Stock</th>
                                    <th>Expiration</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($expirationAlerts)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="bi bi-check-circle text-success"></i>
                                            <p>No expired or near-expiry batches.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expirationAlerts as $alert): ?>
                                    <tr>
                                        <td><span class="item-name"><?php echo h($alert['item_name']); ?></span></td>
                                        <td><?php echo h($alert['batch_lot_no'] ?: 'N/A'); ?></td>
                                        <td><?php echo h($alert['quantity_available'] . ' ' . $alert['unit_name']); ?></td>
                                        <td>
                                            <?php echo h(date('M d, Y', strtotime($alert['expiration_date']))); ?>
                                            <span class="subtext">
                                                <?php
                                                if ($alert['days'] !== null) {
                                                    if ($alert['days'] < 0) {
                                                        echo h(abs($alert['days']) . ' day' . (abs($alert['days']) === 1 ? '' : 's') . ' ago');
                                                    } elseif ($alert['days'] === 0) {
                                                        echo 'Today';
                                                    } else {
                                                        echo h($alert['days'] . ' day' . ($alert['days'] === 1 ? '' : 's') . ' remaining');
                                                    }
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo h($alert['badge_class']); ?>">
                                                <?php echo h($alert['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a
                                                class="action-btn <?php echo $alert['status'] === 'Expired' ? 'danger' : ''; ?>"
                                                href="InventoryOfficer_StockManagement.php?panel=expiration&view_stock_id=<?php echo (int)$alert['stock_id']; ?>"
                                                title="Open in Expiration Monitoring"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-end mt-3">
                        <a class="btn btn-custom" href="InventoryOfficer_StockManagement.php?panel=expiration">
                            Open Expiration Monitoring
                        </a>
                    </div>
                </div>
            </div>

        </div>

        <!-- RECENT TRANSACTIONS -->
        <div class="row g-4 mt-1">
            <div class="col-12">
                <div class="large-card">
                    <div class="card-header-custom">
                        <h5 class="section-title">
                            <i class="bi bi-arrow-left-right"></i>
                            Recent Transactions
                        </h5>
                        <span class="header-count bg-secondary text-white">
                            <?php echo number_format($stats['recent_transactions']); ?> in last 30 days
                        </span>
                    </div>

                    <div class="table-wrap">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Trx No.</th>
                                    <th>Type</th>
                                    <th>Item</th>
                                    <th>Qty</th>
                                    <th>Date</th>
                                    <th>By</th>
                                    <th class="text-center">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recentTransactionsList)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="bi bi-clock-history"></i>
                                            <p>No recent transactions found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentTransactionsList as $trx): ?>
                                    <tr>
                                        <td><strong><?php echo h($trx['trx']); ?></strong></td>
                                        <td>
                                            <span class="badge-status <?php echo h(statusBadgeClass($trx['type'])); ?>">
                                                <?php echo h($trx['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo h($trx['item']); ?></td>
                                        <td><strong><?php echo h($trx['qty']); ?></strong></td>
                                        <td><?php echo h($trx['date']); ?></td>
                                        <td><?php echo h($trx['by']); ?></td>
                                        <td class="text-center">
                                            <button
                                                type="button"
                                                class="action-btn"
                                                title="View Transaction Details"
                                                onclick='showTransactionDetails(<?php
                                                    echo json_encode(
                                                        $trx,
                                                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                                    );
                                                ?>)'
                                            >
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-end mt-3">
                        <a class="btn btn-custom" href="InventoryOfficer_StockTransactions.php">
                            View All Transactions
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- TRANSACTION DETAILS MODAL -->
<div class="modal fade" id="transactionDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>
                    Transaction Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="detail-label">Transaction No.</div>
                        <div class="detail-value" id="modalTrxNo">—</div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-label">Type</div>
                        <div class="detail-value" id="modalTrxType">—</div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-label">Item</div>
                        <div class="detail-value" id="modalTrxItem">—</div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-label">Quantity</div>
                        <div class="detail-value" id="modalTrxQty">—</div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-label">Date</div>
                        <div class="detail-value" id="modalTrxDate">—</div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-label">Recorded By</div>
                        <div class="detail-value" id="modalTrxBy">—</div>
                    </div>
                    <div class="col-12">
                        <div class="detail-label">Remarks</div>
                        <div class="remarks-box" id="modalTrxRemarks">No remarks provided.</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="InventoryOfficer_StockTransactions.php" class="btn btn-custom">Open Transactions</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showTransactionDetails(data)
{
    document.getElementById('modalTrxNo').textContent = data.trx || '—';
    document.getElementById('modalTrxType').textContent = data.type || '—';
    document.getElementById('modalTrxItem').textContent = data.item || '—';
    document.getElementById('modalTrxQty').textContent = data.qty || '—';
    document.getElementById('modalTrxDate').textContent = data.date_full || data.date || '—';
    document.getElementById('modalTrxBy').textContent = data.by || '—';
    document.getElementById('modalTrxRemarks').textContent = data.remarks || 'No remarks provided.';

    const modal = bootstrap.Modal.getOrCreateInstance(
        document.getElementById('transactionDetailsModal')
    );
    modal.show();
}
</script>
</body>
</html>
