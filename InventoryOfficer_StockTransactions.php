<?php
session_start();
require_once 'sources/db_connect.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 5 
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

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


$transactions = [];

if ($branch_id) {
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
    ";

    $transactionStmt = $conn->prepare($transactionQuery);

    if ($transactionStmt) {
        $transactionStmt->bind_param("i", $branch_id);
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
                
                    $sign = ((int)$row['quantity'] < 0) ? '-' : '+';
                    break;

                default:
                    $displayType = $type ?: 'Unknown';
                    $sign = ((int)$row['quantity'] < 0) ? '-' : '+';
                    break;
            }

            $quantity = abs((int)$row['quantity']);
            $unitName = $row['unit_name'] ?? '';

            $transactions[] = [
                'trx' => 'TRX-' . str_pad((string)$row['transaction_id'], 4, '0', STR_PAD_LEFT),
                'type' => $displayType,
                'item' => $row['item_name'] ?? 'Unknown Item',
                'qty' => $sign . $quantity . ($unitName !== '' ? ' ' . $unitName : ''),
                'date' => date('m/d/Y', strtotime($row['transaction_date'])),
                'by' => $row['username'] ?? 'Unknown User'
            ];
        }

        $transactionStmt->close();
    } else {
        $transactionError = "Unable to retrieve stock transactions.";
    }
}

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

@media(max-width:991px){
.main{
margin-left:90px;
}
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
    <div class="profile">
    <i class="bi bi-person-circle"></i>
    <?php echo htmlspecialchars($username); ?>
    <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Inventory Officer</span>
</div>
</div>

<div class="page-body">

<div class="toolbar">

<div class="search-box">
<i class="bi bi-search"></i>
<input
    type="text"
    id="transactionSearch"
    placeholder="Search transactions..."
    autocomplete="off"
    aria-label="Search transactions"
>
</div>

<select class="filter-select" id="transactionFilter" aria-label="Filter transaction type">
<option value="All Types">All Types</option>
<option value="Stock In">Stock In</option>
<option value="Stock Out">Stock Out</option>
<option value="Adjustment">Adjustment</option>
</select>

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
    No stock transactions found for this branch.
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

<tr id="noFilteredTransactionsRow" style="display:none;">
<td colspan="6" class="text-center text-muted py-4">
    No transactions match your search/filter.
</td>
</tr>

<?php endif; ?>
</tbody>
</table>
</div>



</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('transactionSearch');
    const filterSelect = document.getElementById('transactionFilter');
    const rows = Array.from(document.querySelectorAll('#transactionsBody .transaction-row'));
    const noFilteredTransactionsRow = document.getElementById('noFilteredTransactionsRow');

    if (!searchInput || !filterSelect) {
        return;
    }

    function filterTransactions() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        const selectedType = filterSelect.value;
        let visibleCount = 0;

        rows.forEach(function (row) {
            const transactionText = [
                row.dataset.trx || '',
                row.dataset.type || '',
                row.dataset.item || '',
                row.dataset.qty || '',
                row.dataset.date || '',
                row.dataset.by || ''
            ].join(' ').toLowerCase();

            const matchesSearch =
                searchTerm === '' ||
                transactionText.includes(searchTerm);

            const matchesType =
                selectedType === 'All Types' ||
                row.dataset.type === selectedType;

            const shouldShow = matchesSearch && matchesType;

            row.style.display = shouldShow ? '' : 'none';

            if (shouldShow) {
                visibleCount++;
            }
        });

        if (noFilteredTransactionsRow) {
            noFilteredTransactionsRow.style.display =
                visibleCount === 0 ? '' : 'none';
        }
    }

    searchInput.addEventListener('input', filterTransactions);
    filterSelect.addEventListener('change', filterTransactions);

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            filterTransactions();
        }
    });

    filterTransactions();
});
</script>

</body>
</html>