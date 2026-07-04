<?php
/*
  InventoryOfficer_InventoryItems.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Sample rows below mirror the clinic's actual stock (vaccines, sera,
  syringes, medications) so the table reads true once wired to
  inventory_items / inventory_categories / inventory_stocks.
*/

$items = [
    ['category' => 'Medical',         'name' => 'Mefenamic 500mg',   'stock' => '32 pcs',    'status' => 'Low'],
    ['category' => 'Medical',         'name' => 'Amoxicillin 500mg', 'stock' => '21 pcs',    'status' => 'Low'],
    ['category' => 'Medical Supply',  'name' => 'Insulin Syringe',   'stock' => '12 pcs',    'status' => 'Critical'],
    ['category' => 'Medical Supply',  'name' => '1cc / 3cc Syringe', 'stock' => '124 pcs',   'status' => 'In Stock'],
    ['category' => 'Vaccine',         'name' => 'Speeda',            'stock' => '37 vials',  'status' => 'Low'],
    ['category' => 'Vaccine',         'name' => 'Abhayrab',          'stock' => '122 vials', 'status' => 'In Stock'],
    ['category' => 'Vaccine',         'name' => 'Toxoid Bett',       'stock' => '42 amps',   'status' => 'Low'],
];

function itemStatusClass($status) {
    switch ($status) {
        case 'Critical': return 'badge-critical';
        case 'Low':      return 'badge-low';
        case 'In Stock': return 'badge-instock';
        default:          return 'badge-low';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Inventory Items</title>

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

.btn-custom{
background:var(--primary);
color:white;
border-radius:8px;
padding:10px 20px;
border:none;
font-weight:600;
font-size:14px;
white-space:nowrap;
}

.btn-custom:hover{
background:#1d2863;
color:white;
}

.btn-outline-custom{
background:white;
color:var(--primary);
border:1px solid var(--primary);
border-radius:8px;
padding:9px 19px;
font-weight:600;
font-size:14px;
}

.btn-outline-custom:hover{
background:var(--primary);
color:white;
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

.badge-instock{
background:#E6F4EA;
color:#1E7B34;
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
<li><a class="active" href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
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

<div class="main">

<div class="topbar">
<h3>Inventory Items</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<div class="toolbar">

<div class="search-box">
<i class="bi bi-search"></i>
<input type="text" placeholder="Search Items">
</div>

<button class="btn-custom" data-bs-toggle="modal" data-bs-target="#addItemModal">
<i class="bi bi-plus-lg me-1"></i> Add Item
</button>

</div>

<div class="table-wrap">
<table class="table data-table">
<thead>
<tr>
<th>Category</th>
<th>Item Name</th>
<th>Stock</th>
<th>Status</th>
<th class="text-center">Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($items as $item): ?>
<tr>
<td><?php echo htmlspecialchars($item['category']); ?></td>
<td><?php echo htmlspecialchars($item['name']); ?></td>
<td><?php echo htmlspecialchars($item['stock']); ?></td>
<td><span class="badge-status <?php echo itemStatusClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
<td class="text-center">
<button class="action-btn" title="View Item"><i class="bi bi-eye"></i></button>
</td>
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

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content" style="border-radius:16px;">
<div class="modal-header">
<h5 class="modal-title" style="color:var(--primary); font-weight:700;">Add Inventory Item</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form>
<div class="mb-3">
<label class="form-label fw-semibold">Item Name</label>
<input type="text" class="form-control" placeholder="Enter item name">
</div>
<div class="mb-3">
<label class="form-label fw-semibold">Category</label>
<select class="form-select">
<option>Select category...</option>
<option>Vaccine</option>
<option>Medical</option>
<option>Medical Supply</option>
</select>
</div>
<div class="row">
<div class="col-6 mb-3">
<label class="form-label fw-semibold">Unit</label>
<select class="form-select">
<option>Select unit...</option>
<option>Vial</option>
<option>Ampule</option>
<option>Piece</option>
<option>Box</option>
</select>
</div>
<div class="col-6 mb-3">
<label class="form-label fw-semibold">Minimum Stock</label>
<input type="number" class="form-control" placeholder="e.g. 20">
</div>
</div>
<div class="mb-3">
<label class="form-label fw-semibold">Description</label>
<textarea class="form-control" rows="2" placeholder="Optional notes about this item"></textarea>
</div>
<div class="form-check">
<input class="form-check-input" type="checkbox" id="predictableCheck" checked>
<label class="form-check-label" for="predictableCheck">
Include in shortage prediction model
</label>
</div>
</form>
</div>
<div class="modal-footer">
<button class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
<button class="btn-custom">Save Item</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>