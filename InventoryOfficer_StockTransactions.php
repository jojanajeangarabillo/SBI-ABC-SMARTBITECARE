<?php
session_start();
require_once __DIR__ . '/sources/db_connect.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 5 
) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';
$transactionError = '';

function bindTransactionParams($statement, string $types, array &$parameters): void
{
    if ($types === '' || !$parameters) {
        return;
    }

    $references = [];
    foreach ($parameters as $key => &$value) {
        $references[$key] = &$value;
    }
    unset($value);

    $statement->bind_param($types, ...$references);
}

function transactionPageUrl(int $page, string $search, string $type): string
{
    $parameters = ['page' => max(1, $page)];

    if ($search !== '') {
        $parameters['search'] = $search;
    }

    if ($type !== '') {
        $parameters['type'] = $type;
    }

    return '?' . http_build_query($parameters);
}

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

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

$search = trim((string)($_GET['search'] ?? ''));
$search = substr($search, 0, 100);
$selectedType = strtoupper(trim((string)($_GET['type'] ?? '')));
$allowedTypes = ['IN', 'OUT', 'ADJUSTMENT'];

if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$totalTransactions = 0;
$totalPages = 1;
$offset = 0;
$transactions = [];

if ($branch_id) {
    $whereClauses = ['st.branch_id = ?'];
    $parameterTypes = 's';
    $queryParameters = [$branch_id];

    if ($search !== '') {
        $likeSearch = '%' . $search . '%';
        $whereClauses[] = "(
            CAST(st.transaction_id AS CHAR) LIKE ?
            OR st.transaction_type LIKE ?
            OR ii.item_name LIKE ?
            OR COALESCE(u.unit_name, '') LIKE ?
            OR usr.username LIKE ?
            OR COALESCE(st.remarks, '') LIKE ?
        )";
        $parameterTypes .= 'ssssss';
        array_push(
            $queryParameters,
            $likeSearch,
            $likeSearch,
            $likeSearch,
            $likeSearch,
            $likeSearch,
            $likeSearch
        );
    }

    if ($selectedType !== '') {
        $whereClauses[] = 'st.transaction_type = ?';
        $parameterTypes .= 's';
        $queryParameters[] = $selectedType;
    }

    $whereSql = implode(' AND ', $whereClauses);

    $countQuery = "
        SELECT COUNT(*) AS total
        FROM stock_transactions st
        INNER JOIN inventory_items ii ON st.item_id = ii.item_id
        LEFT JOIN units u ON ii.unit_id = u.unit_id
        INNER JOIN users usr ON st.user_id = usr.user_id
        WHERE $whereSql
    ";

    $countStmt = $conn->prepare($countQuery);

    if ($countStmt) {
        $countParameters = $queryParameters;
        bindTransactionParams($countStmt, $parameterTypes, $countParameters);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $totalTransactions = (int)($countResult['total'] ?? 0);
        $countStmt->close();

        $totalPages = max(1, (int)ceil($totalTransactions / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
    } else {
        $transactionError = 'Unable to count stock transactions.';
    }

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
        WHERE $whereSql
        ORDER BY st.transaction_date DESC, st.transaction_id DESC
        LIMIT ? OFFSET ?
    ";

    $transactionStmt = $transactionError === ''
        ? $conn->prepare($transactionQuery)
        : false;

    if ($transactionStmt) {
        $dataParameters = $queryParameters;
        $dataParameters[] = $perPage;
        $dataParameters[] = $offset;
        $dataParameterTypes = $parameterTypes . 'ii';
        bindTransactionParams($transactionStmt, $dataParameterTypes, $dataParameters);
        $transactionStmt->execute();
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
                
                    $sign = ((float)$row['quantity'] < 0) ? '-' : '+';
                    break;

                default:
                    $displayType = $type ?: 'Unknown';
                    $sign = ((float)$row['quantity'] < 0) ? '-' : '+';
                    break;
            }

            $quantity = abs((float)$row['quantity']);
            $quantityText = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
            $unitName = $row['unit_name'] ?? '';

            $transactions[] = [
                'trx' => 'TRX-' . str_pad((string)$row['transaction_id'], 4, '0', STR_PAD_LEFT),
                'type' => $displayType,
                'item' => $row['item_name'] ?? 'Unknown Item',
                'qty' => $sign . $quantityText . ($unitName !== '' ? ' ' . $unitName : ''),
                'date' => date('m/d/Y', strtotime($row['transaction_date'])),
                'by' => $row['username'] ?? 'Unknown User'
            ];
        }

        $transactionStmt->close();
    } elseif ($transactionError === '') {
        $transactionError = 'Unable to retrieve stock transactions.';
    }
}

$showingFrom = $totalTransactions > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $perPage, $totalTransactions);
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);

function trxTypeClass($type) {
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

<title>Stock Transactions</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="sidebar.css">

<style>

:root{
--primary:#2B3A8C;
--accent:#F21D2F;
--bg:#F2F2F2;
}

body{
background:#f0f2f5;
font-family:'Segoe UI',sans-serif;
}

.main{
margin-left:260px;
min-height:100vh;
}

.topbar{
background:white;
height:80px;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 35px;
box-shadow:0 2px 8px rgba(0,0,0,.08);
}

.topbar h3{
font-size:28px;
font-weight:700;
color:var(--primary);
margin:0;
}
.topbar h3 small {
font-size: 15px;
font-weight: 400;
color: #6c757d;
margin-left: 10px;
}

.profile{
font-weight:600;
color:var(--primary);
cursor:pointer;
display:flex;
align-items:center;
gap:7px;
}

.page-body{
padding:35px;
}

.toolbar{
display:flex;
align-items:center;
justify-content:space-between;
gap:16px;
margin-bottom:22px;
flex-wrap:wrap;
}

.search-box{
position:relative;
flex:1;
max-width:340px;
}

.search-box i{
position:absolute;
left:14px;
top:50%;
transform:translateY(-50%);
color:#9aa0c3;
}

.search-box input{
width:100%;
padding:10px 14px 10px 38px;
border-radius:10px;
border:1px solid #dcdee8;
background:white;
font-size:14px;
}

.search-box input:focus{
border-color: var(--primary);
box-shadow: 0 0 0 3px rgba(43,58,140,0.12);
outline: none;
}

.filter-select{
max-width:200px;
border-radius:10px;
border:1px solid #dcdee8;
font-size:14px;
padding:10px 14px;
}

.filter-actions{
display:flex;
align-items:center;
gap:10px;
flex-wrap:wrap;
}

.btn-filter{
height:42px;
display:inline-flex;
align-items:center;
gap:7px;
padding:0 17px;
border:0;
border-radius:10px;
background:var(--primary);
color:#fff;
font-size:14px;
font-weight:600;
}

.btn-filter:hover{
background:#202d72;
color:#fff;
}

.btn-clear{
height:42px;
display:inline-flex;
align-items:center;
padding:0 14px;
border:1px solid #dcdee8;
border-radius:10px;
background:#fff;
color:#616b83;
font-size:14px;
font-weight:600;
text-decoration:none;
}

.btn-clear:hover{
border-color:var(--primary);
color:var(--primary);
}

.table-wrap{
background:white;
border-radius:12px;
border:1px solid #dfe1ee;
overflow:hidden;
}

.data-table{
margin:0;
}

.data-table thead th{
background:var(--primary);
color:white;
font-weight:600;
font-size:13px;
border:none;
padding:14px;
white-space:nowrap;
}

.data-table tbody td{
font-size:14px;
color:#333;
padding:13px 14px;
vertical-align:middle;
border-bottom:1px solid #eef0f7;
}

.data-table tbody tr:last-child td{
border-bottom:none;
}

.data-table tbody tr:hover{
background:#f7f8fc;
}

.badge-status{
display:inline-block;
padding:5px 12px;
border-radius:20px;
font-size:12px;
font-weight:600;
}

.badge-in{
background:#E6F4EA;
color:#1E7B34;
}

.badge-out{
background:#FFEAEA;
color:var(--accent);
}

.badge-adjust{
background:#EDEFFA;
color:var(--primary);
}

.table-footer{
display:flex;
align-items:center;
justify-content:space-between;
gap:16px;
padding:18px 4px 0;
flex-wrap:wrap;
}

.transaction-summary{
margin:0;
color:#6f7890;
font-size:13px;
}

.pagination{
margin:0;
gap:4px;
}

.pagination .page-link{
min-width:38px;
height:38px;
display:grid;
place-items:center;
padding:0 10px;
border:1px solid #dfe3ee;
border-radius:9px !important;
color:var(--primary);
font-weight:600;
box-shadow:none;
}

.pagination .page-link:hover{
border-color:var(--primary);
background:#eef0fa;
color:var(--primary);
}

.pagination .page-item.active .page-link{
border-color:var(--primary);
background:var(--primary);
color:#fff;
}

.pagination .page-item.disabled .page-link{
background:#f4f5f8;
color:#a8afc0;
}

@media(max-width:991px){
.main{
margin-left:90px;
}
}

@media(max-width:650px){
.page-body{padding:22px 16px;}
.topbar{height:auto;min-height:76px;padding:14px 18px;gap:10px;}
.topbar h3{font-size:22px;}
.topbar h3 small{display:block;margin-left:0;margin-top:3px;font-size:12px;}
.toolbar{align-items:stretch;}
.search-box{max-width:none;flex-basis:100%;}
.filter-actions{width:100%;}
.filter-select{max-width:none;flex:1;}
.table-footer{justify-content:center;text-align:center;}
.transaction-summary{width:100%;}
}

</style>

</head>


<body>

<div class="sidebar">

<div class="logo-area">
    <div class="logo-frame">
        <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
    </div>
    <div class="system-name">
        Smart Bite Care
    </div>
</div>

<nav class="nav-menu">
<ul>
<li><a href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
<li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
<li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
<li><a class="active" href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a href="InventoryOfficer_ReturnManagement.php"><i class="bi bi-arrow-return-left"></i><span>Return Management</span></a></li>
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

<div class="main">

<div class="topbar">
<h3>Stock Transactions<small><?php echo htmlspecialchars($branch_name); ?></small></h3>
<div class="dropdown">
    <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
            type="button" id="inventoryOfficerProfileMenu"
            data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-person-circle"></i>
        <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
        <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Inventory Officer</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
        aria-labelledby="inventoryOfficerProfileMenu">
        <li><h6 class="dropdown-header">Account options</h6></li>
        <li>
            <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                <i class="bi bi-key-fill me-2"></i>Change Password
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item rounded-2 py-2 text-danger" href="logout.php">
                <i class="bi bi-box-arrow-right me-2"></i>Logout
            </a>
        </li>
    </ul>
</div>
</div>

<div class="page-body">

<form method="get" class="toolbar" id="transactionFilterForm">

<div class="search-box">
<i class="bi bi-search"></i>
<input
    type="text"
    id="transactionSearch"
    name="search"
    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
    placeholder="Search transactions..."
    autocomplete="off"
    aria-label="Search transactions"
>
</div>

<div class="filter-actions">
    <select class="filter-select" id="transactionFilter" name="type" aria-label="Filter transaction type">
        <option value="">All Types</option>
        <option value="IN" <?php echo $selectedType === 'IN' ? 'selected' : ''; ?>>Stock In</option>
        <option value="OUT" <?php echo $selectedType === 'OUT' ? 'selected' : ''; ?>>Stock Out</option>
        <option value="ADJUSTMENT" <?php echo $selectedType === 'ADJUSTMENT' ? 'selected' : ''; ?>>Adjustment</option>
    </select>
    <button class="btn-filter" type="submit">
        <i class="bi bi-search"></i>Search
    </button>
    <?php if ($search !== '' || $selectedType !== ''): ?>
        <a class="btn-clear" href="InventoryOfficer_StockTransactions.php">Clear</a>
    <?php endif; ?>
</div>

</form>

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
</tr>
</thead>
<tbody id="transactionsBody">
<?php if (!empty($transactionError)): ?>
<tr>
<td colspan="6" class="text-center text-danger py-4">
    <?php echo htmlspecialchars($transactionError); ?>
</td>
</tr>

<?php elseif (empty($transactions)): ?>
<tr id="noTransactionsRow">
<td colspan="6" class="text-center text-muted py-4">
    <?php if ($search !== '' || $selectedType !== ''): ?>
        No transactions match the selected search or type.
    <?php else: ?>
        No stock transactions found for this branch.
    <?php endif; ?>
</td>
</tr>

<?php else: ?>

<?php foreach ($transactions as $t): ?>
<tr
    class="transaction-row"
    data-trx="<?php echo htmlspecialchars($t['trx'], ENT_QUOTES, 'UTF-8'); ?>"
    data-type="<?php echo htmlspecialchars($t['type'], ENT_QUOTES, 'UTF-8'); ?>"
    data-item="<?php echo htmlspecialchars($t['item'], ENT_QUOTES, 'UTF-8'); ?>"
    data-qty="<?php echo htmlspecialchars($t['qty'], ENT_QUOTES, 'UTF-8'); ?>"
    data-date="<?php echo htmlspecialchars($t['date'], ENT_QUOTES, 'UTF-8'); ?>"
    data-by="<?php echo htmlspecialchars($t['by'], ENT_QUOTES, 'UTF-8'); ?>"
>
<td><?php echo htmlspecialchars($t['trx']); ?></td>
<td>
    <span class="badge-status <?php echo trxTypeClass($t['type']); ?>">
        <?php echo htmlspecialchars($t['type']); ?>
    </span>
</td>
<td><?php echo htmlspecialchars($t['item']); ?></td>
<td><?php echo htmlspecialchars($t['qty']); ?></td>
<td><?php echo htmlspecialchars($t['date']); ?></td>
<td><?php echo htmlspecialchars($t['by']); ?></td>
</tr>
<?php endforeach; ?>

<?php endif; ?>
</tbody>
</table>
</div>

<div class="table-footer">
    <p class="transaction-summary">
        Showing <strong><?php echo $showingFrom; ?></strong>–<strong><?php echo $showingTo; ?></strong>
        of <strong><?php echo $totalTransactions; ?></strong> transaction<?php echo $totalTransactions === 1 ? '' : 's'; ?>
    </p>

    <?php if ($totalPages > 1): ?>
        <nav aria-label="Stock transaction pages">
            <ul class="pagination">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $page <= 1 ? '#' : htmlspecialchars(transactionPageUrl($page - 1, $search, $selectedType), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>

                <?php if ($paginationStart > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars(transactionPageUrl(1, $search, $selectedType), ENT_QUOTES, 'UTF-8'); ?>">1</a></li>
                    <?php if ($paginationStart > 2): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($pageNumber = $paginationStart; $pageNumber <= $paginationEnd; $pageNumber++): ?>
                    <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars(transactionPageUrl($pageNumber, $search, $selectedType), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo $pageNumber; ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($paginationEnd < $totalPages): ?>
                    <?php if ($paginationEnd < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars(transactionPageUrl($totalPages, $search, $selectedType), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>

                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : htmlspecialchars(transactionPageUrl($page + 1, $search, $selectedType), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>


</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('transactionFilterForm');
    const filterSelect = document.getElementById('transactionFilter');

    if (filterForm && filterSelect) {
        filterSelect.addEventListener('change', function () {
            filterForm.submit();
        });
    }
});
</script>

</body>
</html>
