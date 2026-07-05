<?php
/*
  InventoryOfficer_StockManagement.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Four tabs mirror stock_transactions (stock in / stock out / adjustment)
  and inventory_stocks.expiration_date for expiration monitoring.
*/

$expiringStock = [
    ['item' => 'HRIG',            'batch' => 'LOT-2603',  'stock' => '2 units',  'expiry' => '07/18/2026', 'days' => '15 days', 'status' => 'Expiring Soon'],
    ['item' => 'PPD',             'batch' => 'LOT-2588',  'stock' => '1 vial',   'expiry' => '07/09/2026', 'days' => '6 days',  'status' => 'Expiring Soon'],
    ['item' => 'ATS 1,500 IU',    'batch' => 'LOT-2571',  'stock' => '6 amps',   'expiry' => '08/02/2026', 'days' => '30 days', 'status' => 'Expiring Soon'],
    ['item' => 'Hepa B Vaccine',  'batch' => 'LOT-2544',  'stock' => '3 vials',  'expiry' => '06/28/2026', 'days' => 'Expired', 'status' => 'Expired'],
];

function expiryStatusClass($status) {
    return $status === 'Expired' ? 'badge-critical' : 'badge-low';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Stock Management</title>

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

.tab-row{
display:flex;
gap:12px;
margin-bottom:26px;
flex-wrap:wrap;
}

.tab-btn{
background:white;
border:1px solid var(--primary);
color:var(--primary);
font-weight:600;
font-size:14px;
padding:10px 22px;
border-radius:8px;
}

.tab-btn.active{
background:var(--primary);
color:white;
}

.form-card{
background:#ECEEF7;
border-radius:18px;
padding:30px;
box-shadow:0 3px 8px rgba(0,0,0,.08);
}

.form-card label{
font-weight:600;
color:var(--primary);
font-size:14px;
margin-bottom:6px;
}

.form-card .form-control,
.form-card .form-select{
border-radius:10px;
border:1px solid #dcdee8;
padding:10px 14px;
font-size:14px;
background:white;
}

.form-card .form-control:focus,
.form-card .form-select:focus{
border-color:var(--primary);
box-shadow:none;
}

.form-card .form-control[readonly]{
background:#eef0f7;
color:#666;
}

.btn-custom{
background:var(--primary);
color:white;
border-radius:8px;
padding:12px 20px;
border:none;
font-weight:600;
font-size:15px;
width:100%;
}

.btn-custom:hover{
background:#1d2863;
color:white;
}

.section-title{
font-size:18px;
font-weight:700;
color:var(--primary);
margin-bottom:22px;
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

.badge-low{
background:#FFEAEA;
color:var(--accent);
}

.badge-critical{
background:var(--accent);
color:white;
}

.action-btn{
border:1px solid #dcdee8;
background:white;
color:var(--primary);
width:34px;
height:34px;
border-radius:8px;
display:inline-flex;
align-items:center;
justify-content:center;
}

.action-btn:hover{
background:var(--primary);
color:white;
border-color:var(--primary);
}

.filter-select{
max-width:260px;
}

.panel{
display:none;
}

.panel.active{
display:block;
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
<li><a class="active" href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
<li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
<li><a href="InventoryOfficer_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
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
<h3>Stock Management</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<div class="tab-row">
<button class="tab-btn active" onclick="showPanel('stockIn', this)">Stock In</button>
<button class="tab-btn" onclick="showPanel('stockOut', this)">Stock Out</button>
<button class="tab-btn" onclick="showPanel('adjustment', this)">Adjustment</button>
<button class="tab-btn" onclick="showPanel('expiration', this)">Expiration Monitoring</button>
</div>

<!-- STOCK IN -->
<div class="panel active" id="stockIn">
<div class="form-card">
<div class="section-title">Record Stock In</div>
<form>
<div class="row g-4">
<div class="col-md-6">
<label>Item</label>
<select class="form-select">
<option>Select item...</option>
<option>Speeda</option>
<option>Abhayrab</option>
<option>Erig Com</option>
<option>HRIG</option>
<option>ATS 1,500 IU</option>
<option>Toxoid Bett</option>
<option>Mefenamic 500mg</option>
<option>Insulin Syringe</option>
</select>
</div>
<div class="col-md-6">
<label>Quantity</label>
<input type="number" class="form-control" placeholder="Enter quantity...">
</div>
<div class="col-md-6">
<label>Supplier</label>
<select class="form-select">
<option>Select supplier...</option>
<option>SBI Medical Main Branch</option>
<option>DOH Regional Supply</option>
<option>Local Pharmaceutical Distributor</option>
</select>
</div>
<div class="col-md-6">
<label>Date</label>
<input type="date" class="form-control">
</div>
<div class="col-md-6">
<label>Reference / QR No.</label>
<input type="text" class="form-control" placeholder="Enter reference number">
</div>
<div class="col-md-6">
<label>Remarks</label>
<textarea class="form-control" rows="1" placeholder="Enter remarks here (Optional)"></textarea>
</div>
</div>
<div class="mt-4">
<button type="button" class="btn-custom">Save Stock In</button>
</div>
</form>
</div>
</div>

<!-- STOCK OUT -->
<div class="panel" id="stockOut">
<div class="form-card">
<div class="section-title">Record Stock Out</div>
<form>
<div class="row g-4">
<div class="col-md-6">
<label>Item</label>
<select class="form-select">
<option>Select item...</option>
<option>Speeda</option>
<option>Abhayrab</option>
<option>Erig Com</option>
<option>HRIG</option>
<option>ATS 1,500 IU</option>
<option>Toxoid Bett</option>
<option>Mefenamic 500mg</option>
<option>Insulin Syringe</option>
</select>
</div>
<div class="col-md-6">
<label>Quantity</label>
<input type="number" class="form-control" placeholder="Enter quantity...">
</div>
<div class="col-md-6">
<label>Reason</label>
<select class="form-select">
<option>Select reason...</option>
<option>Dispensed to Patient</option>
<option>Damaged</option>
<option>Expired</option>
<option>Lost / Wastage</option>
<option>Other</option>
</select>
</div>
<div class="col-md-6">
<label>Date</label>
<input type="date" class="form-control">
</div>
<div class="col-12">
<label>Remarks</label>
<textarea class="form-control" rows="2" placeholder="Enter remarks here (Optional)"></textarea>
</div>
</div>
<div class="mt-4">
<button type="button" class="btn-custom">Save Stock Out</button>
</div>
</form>
</div>
</div>

<!-- ADJUSTMENT -->
<div class="panel" id="adjustment">
<div class="form-card">
<div class="section-title">Record Stock Adjustment</div>
<form>
<div class="row g-4">
<div class="col-md-6">
<label>Item</label>
<select class="form-select">
<option>Select item...</option>
<option>Speeda</option>
<option>Abhayrab</option>
<option>Erig Com</option>
<option>HRIG</option>
<option>ATS 1,500 IU</option>
<option>Toxoid Bett</option>
</select>
</div>
<div class="col-md-6">
<label>Current Stock</label>
<input type="text" class="form-control" value="37 vials" readonly>
</div>
<div class="col-md-6">
<label>Adjusted Quantity</label>
<input type="number" class="form-control" placeholder="Enter new quantity...">
</div>
<div class="col-md-6">
<label>Reason for Adjustment</label>
<select class="form-select">
<option>Select reason...</option>
<option>Miscount / Physical Count Correction</option>
<option>Damaged</option>
<option>Expired</option>
<option>System Correction</option>
<option>Other</option>
</select>
</div>
<div class="col-md-6">
<label>Date</label>
<input type="date" class="form-control">
</div>
<div class="col-md-6">
<label>Remarks</label>
<textarea class="form-control" rows="1" placeholder="Enter remarks here (Optional)"></textarea>
</div>
</div>
<div class="mt-4">
<button type="button" class="btn-custom">Save Adjustment</button>
</div>
</form>
</div>
</div>

<!-- EXPIRATION MONITORING -->
<div class="panel" id="expiration">
<div class="form-card" style="background:transparent; padding:0; box-shadow:none;">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
<div class="section-title mb-0">Expiration Monitoring</div>
<select class="form-select filter-select">
<option>Expiring within 30 days</option>
<option>Expiring within 60 days</option>
<option>Already Expired</option>
</select>
</div>

<div class="table-wrap">
<table class="table data-table">
<thead>
<tr>
<th>Item</th>
<th>Batch / Lot No.</th>
<th>Stock</th>
<th>Expiration Date</th>
<th>Days Remaining</th>
<th>Status</th>
<th class="text-center">Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($expiringStock as $row): ?>
<tr>
<td><?php echo htmlspecialchars($row['item']); ?></td>
<td><?php echo htmlspecialchars($row['batch']); ?></td>
<td><?php echo htmlspecialchars($row['stock']); ?></td>
<td><?php echo htmlspecialchars($row['expiry']); ?></td>
<td><?php echo htmlspecialchars($row['days']); ?></td>
<td><span class="badge-status <?php echo expiryStatusClass($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
<td class="text-center">
<button class="action-btn" title="View Item"><i class="bi bi-eye"></i></button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>
</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showPanel(id, btn){
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}
</script>

</body>
</html>