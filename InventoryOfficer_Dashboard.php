<?php
/*
  InventoryOfficer_Dashboard.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Static/sample values below are placeholders that mirror the wireframe
  numbers and the clinic's real inventory items (Speeda, Erig, HRIG, ATS,
  Toxoid, Mefenamic, syringes) so the layout reads true once wired to
  inventory_items / inventory_stocks / stock_transactions.
*/

// ---- Placeholder data (replace with DB queries later) ----
$currentStocks       = 186;
$lowStocksCount       = 6;
$expiringStocksCount  = 7;
$recentTransactions   = 20;

$lowStockItems = [
    ['id' => 'ITM-0004', 'name' => 'Erig Com',            'category' => 'Immunoglobulin', 'stock' => '7 vials',  'status' => 'Low Stock'],
    ['id' => 'ITM-0009', 'name' => 'HRIG',                'category' => 'Immunoglobulin', 'stock' => '2 units',  'status' => 'Critical'],
    ['id' => 'ITM-0012', 'name' => 'ATS 1,500 IU',        'category' => 'Serum',          'stock' => '6 amps',   'status' => 'Low Stock'],
    ['id' => 'ITM-0015', 'name' => 'Toxoid Bett',         'category' => 'Vaccines',       'stock' => '5 amps',   'status' => 'Low Stock'],
    ['id' => 'ITM-0021', 'name' => 'Mefenamic 500mg',     'category' => 'Medications',    'stock' => '18 pcs',   'status' => 'Low Stock'],
    ['id' => 'ITM-0027', 'name' => 'Insulin Syringe',     'category' => 'Syringes',       'stock' => '47 pcs',   'status' => 'Low Stock'],
];

$recentTransactionsList = [
    ['id' => 'ITM-0001', 'name' => 'Speeda',              'category' => 'Vaccines',       'stock' => '99 vials', 'status' => 'Stock Out'],
    ['id' => 'ITM-0002', 'name' => 'Abhayrab',             'category' => 'Vaccines',       'stock' => '23 vials', 'status' => 'Stock In'],
    ['id' => 'ITM-0018', 'name' => '1cc / 3cc Syringe',    'category' => 'Syringes',       'stock' => '405 pcs',  'status' => 'Stock In'],
    ['id' => 'ITM-0024', 'name' => 'Hepa B Vaccine',       'category' => 'Vaccines',       'stock' => '42 vials', 'status' => 'Stock Out'],
    ['id' => 'ITM-0030', 'name' => 'PPD',                  'category' => 'Vaccines',       'stock' => '1 vial',   'status' => 'Stock Out'],
];

function statusBadgeClass($status) {
    switch ($status) {
        case 'Critical':   return 'badge-critical';
        case 'Low Stock':  return 'badge-low';
        case 'Stock In':   return 'badge-in';
        case 'Stock Out':  return 'badge-out';
        default:           return 'badge-low';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Inventory Officer Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!--REUSABLE SIDEBAR CSS-->
<link rel="stylesheet" href="sidebar.css">

<style>

/*=========================================
  INTERNAL CSS
=========================================*/

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

.dashboard{

padding:35px;

}

.stat-card{

background:#ECEEF7;

border-radius:18px;

padding:22px;

min-height:140px;

display:flex;

flex-direction:column;

justify-content:space-between;

box-shadow:0 3px 8px rgba(0,0,0,.08);

}

.stat-title{

font-weight:600;

color:var(--primary);

font-size:18px;

line-height:1.25;

}

.stat-number{

margin-top:20px;

font-size:clamp(28px,3.4vw,48px);

font-weight:700;

color:var(--primary);

}

.large-card{

background:#ECEEF7;

border-radius:18px;

padding:20px;

margin-top:25px;

box-shadow:0 3px 8px rgba(0,0,0,.08);

}

.section-title{

font-size:20px;

font-weight:700;

color:var(--primary);

margin-bottom:20px;

}

.btn-custom{

background:var(--primary);

color:white;

border-radius:8px;

padding:8px 18px;

border:none;

}

.btn-custom:hover{

background:#1d2863;

}

/* Tables */

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

padding:12px 14px;

white-space:nowrap;

}

.data-table tbody td{

font-size:14px;

color:#333;

padding:12px 14px;

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

.badge-low{

background:#FFEAEA;

color:var(--accent);

}

.badge-critical{

background:var(--accent);

color:white;

}

.badge-in{

background:#E6F4EA;

color:#1E7B34;

}

.badge-out{

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

<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>

<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>

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

<!-- Main Content -->

<div class="main">

<div class="topbar">
<h3>Dashboard</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="dashboard">
<div class="row g-4">

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Current Stocks</div>
<div class="stat-number"><?php echo $currentStocks; ?></div>
</div>

</div>

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Low Stocks</div>
<div class="stat-number"><?php echo $lowStocksCount; ?></div>
</div>

</div>

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Expiring Stocks</div>
<div class="stat-number"><?php echo $expiringStocksCount; ?></div>
</div>

</div>

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Recent Transactions</div>
<div class="stat-number"><?php echo $recentTransactions; ?></div>
</div>

</div>

<div class="col-lg-6">

<div class="large-card">

<div class="section-title">Low Stock Item</div>

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
<?php foreach ($lowStockItems as $item): ?>
<tr>
<td><?php echo htmlspecialchars($item['id']); ?></td>
<td><?php echo htmlspecialchars($item['name']); ?></td>
<td><?php echo htmlspecialchars($item['category']); ?></td>
<td><?php echo htmlspecialchars($item['stock']); ?></td>
<td><span class="badge-status <?php echo statusBadgeClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="text-end mt-3">
<button class="btn btn-custom">
View All Low Stocks
</button>
</div>

</div>

</div>

<div class="col-lg-6">

<div class="large-card">

<div class="section-title">Recent Transactions</div>

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
<?php foreach ($recentTransactionsList as $item): ?>
<tr>
<td><?php echo htmlspecialchars($item['id']); ?></td>
<td><?php echo htmlspecialchars($item['name']); ?></td>
<td><?php echo htmlspecialchars($item['category']); ?></td>
<td><?php echo htmlspecialchars($item['stock']); ?></td>
<td><span class="badge-status <?php echo statusBadgeClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="text-end mt-3">
<button class="btn btn-custom">
View All Transactions
</button>
</div>

</div>

</div>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>