<?php
/*
  InventoryOfficer_StockTransactions.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Rows mirror stock_transactions (transaction_type, quantity,
  transaction_date) joined to inventory_items and users.
*/

$transactions = [
    ['trx' => 'TRX-0142', 'type' => 'Stock In',    'item' => 'Speeda',            'qty' => '+50 vials', 'date' => '07/01/2026', 'by' => 'Marc B.'],
    ['trx' => 'TRX-0141', 'type' => 'Stock Out',   'item' => 'Erig Com',           'qty' => '-3 vials',  'date' => '07/01/2026', 'by' => 'Shane C.'],
    ['trx' => 'TRX-0140', 'type' => 'Adjustment',  'item' => 'HRIG',               'qty' => '-1 unit',   'date' => '06/30/2026', 'by' => 'Marc B.'],
    ['trx' => 'TRX-0139', 'type' => 'Stock In',    'item' => '1cc / 3cc Syringe',  'qty' => '+300 pcs',  'date' => '06/30/2026', 'by' => 'Jojana G.'],
    ['trx' => 'TRX-0138', 'type' => 'Stock Out',   'item' => 'Mefenamic 500mg',    'qty' => '-14 pcs',   'date' => '06/29/2026', 'by' => 'Shane C.'],
    ['trx' => 'TRX-0137', 'type' => 'Stock Out',   'item' => 'ATS 1,500 IU',       'qty' => '-2 amps',   'date' => '06/29/2026', 'by' => 'Marc B.'],
    ['trx' => 'TRX-0136', 'type' => 'Stock In',    'item' => 'Toxoid Bett',        'qty' => '+40 amps',  'date' => '06/28/2026', 'by' => 'Jojana G.'],
    ['trx' => 'TRX-0135', 'type' => 'Adjustment',  'item' => 'Insulin Syringe',    'qty' => '+5 pcs',    'date' => '06/28/2026', 'by' => 'Marc B.'],
];

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
background:white;
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
background:#F7F8FC;
font-size:14px;
}

.search-box input:focus{
outline:none;
border-color:var(--primary);
background:white;
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

.pagination-custom{
display:flex;
justify-content:center;
align-items:center;
gap:8px;
margin-top:24px;
}

.pagination-custom a{
color:var(--primary);
font-weight:600;
font-size:14px;
text-decoration:none;
padding:6px 10px;
border-radius:6px;
}

.pagination-custom a.active{
background:var(--accent);
color:white;
}

.pagination-custom a:hover:not(.active){
background:#eef0f7;
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
<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
<li><a class="active" href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
<li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
<li><a href="InventoryOfficer_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
</ul>
</nav>

<div class="logout">
<a href="#"> <i class="bi bi-box-arrow-right"></i>
<span>Logout</span>
</a>
</div>

</div>

<div class="main">

<div class="topbar">
<h3>Stock Transactions</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<div class="toolbar">

<div class="search-box">
<i class="bi bi-search"></i>
<input type="text" placeholder="Search transactions...">
</div>

<select class="filter-select">
<option>All Types</option>
<option>Stock In</option>
<option>Stock Out</option>
<option>Adjustment</option>
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
<tbody>
<?php foreach ($transactions as $t): ?>
<tr>
<td><?php echo htmlspecialchars($t['trx']); ?></td>
<td><span class="badge-status <?php echo trxTypeClass($t['type']); ?>"><?php echo htmlspecialchars($t['type']); ?></span></td>
<td><?php echo htmlspecialchars($t['item']); ?></td>
<td><?php echo htmlspecialchars($t['qty']); ?></td>
<td><?php echo htmlspecialchars($t['date']); ?></td>
<td><?php echo htmlspecialchars($t['by']); ?></td>
</tr>
<?php endforeach; ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>