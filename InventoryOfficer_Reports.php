<?php
/*
  InventoryOfficer_Reports.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Chart bars/pie are static CSS visuals standing in for a report
  generated from inventory_usage_history / stock_transactions once
  the backend and charting library (e.g. Chart.js) are wired in.
*/

$barData = [
    ['label' => 'Speeda',        'value' => 90],
    ['label' => 'Erig Com',      'value' => 78],
    ['label' => 'HRIG',          'value' => 60],
    ['label' => 'Toxoid Bett',   'value' => 45],
    ['label' => 'ATS 1,500 IU',  'value' => 25],
    ['label' => 'Mefenamic',     'value' => 12],
];

$pieData = [
    ['label' => 'Vaccines',        'value' => 42, 'color' => '#2B3A8C'],
    ['label' => 'Syringes',        'value' => 26, 'color' => '#5A6BC4'],
    ['label' => 'Serum / ERIG',    'value' => 18, 'color' => '#9AA6E8'],
    ['label' => 'Medications',     'value' => 14, 'color' => '#DCE0F7'],
];

// build conic-gradient string for the CSS pie chart
$gradientParts = [];
$cursor = 0;
foreach ($pieData as $slice) {
    $start = $cursor;
    $cursor += $slice['value'];
    $gradientParts[] = $slice['color'] . ' ' . $start . '% ' . $cursor . '%';
}
$conicGradient = implode(', ', $gradientParts);
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Inventory Reports</title>

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

.filter-card{
display:flex;
align-items:flex-end;
gap:24px;
flex-wrap:wrap;
margin-bottom:26px;
}

.filter-group{
display:flex;
flex-direction:column;
gap:6px;
}

.filter-group label{
font-weight:600;
color:var(--primary);
font-size:14px;
}

.filter-group select,
.filter-group input{
border-radius:10px;
border:1px solid #dcdee8;
padding:10px 14px;
font-size:14px;
min-width:220px;
}

.filter-group select:focus,
.filter-group input:focus{
outline:none;
border-color:var(--primary);
}

.btn-custom{
background:var(--primary);
color:white;
border-radius:8px;
padding:10px 22px;
border:none;
font-weight:600;
font-size:14px;
height:44px;
}

.btn-custom:hover{
background:#1d2863;
color:white;
}

.large-card{
background:#ECEEF7;
border-radius:18px;
padding:24px;
box-shadow:0 3px 8px rgba(0,0,0,.08);
height:100%;
}

.section-title{
font-size:16px;
font-weight:700;
color:var(--primary);
margin-bottom:20px;
}

/* CSS bar chart */
.bar-chart{
display:flex;
align-items:flex-end;
gap:14px;
height:220px;
background:white;
border-radius:12px;
padding:20px 20px 10px;
border:1px solid #dfe1ee;
}

.bar-col{
flex:1;
display:flex;
flex-direction:column;
align-items:center;
justify-content:flex-end;
height:100%;
}

.bar{
width:100%;
max-width:34px;
background:var(--primary);
border-radius:6px 6px 0 0;
opacity:.85;
}

.bar-label{
margin-top:8px;
font-size:11px;
color:#666;
text-align:center;
line-height:1.2;
}

/* CSS pie chart */
.pie-wrap{
display:flex;
align-items:center;
gap:26px;
background:white;
border-radius:12px;
padding:24px;
border:1px solid #dfe1ee;
flex-wrap:wrap;
justify-content:center;
}

.pie{
width:160px;
height:160px;
border-radius:50%;
flex-shrink:0;
}

.pie-legend{
display:flex;
flex-direction:column;
gap:10px;
}

.legend-row{
display:flex;
align-items:center;
gap:10px;
font-size:14px;
color:#333;
}

.legend-dot{
width:12px;
height:12px;
border-radius:3px;
flex-shrink:0;
}

.legend-value{
margin-left:auto;
font-weight:700;
color:var(--primary);
}

.summary-empty{
background:white;
border:1px dashed #c7cbe6;
border-radius:12px;
padding:40px 24px;
text-align:center;
color:#8a8fb0;
}

.summary-empty i{
font-size:30px;
color:var(--primary);
opacity:.5;
margin-bottom:10px;
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
<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a class="active" href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
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
<h3>Inventory Reports</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<div class="filter-card">

<div class="filter-group">
<label>Select Report</label>
<select>
<option>Low Stock Report</option>
<option>Expiring Stock Report</option>
<option>Stock Usage Report</option>
<option>Stock Transaction Summary</option>
<option>Shortage Prediction Report</option>
</select>
</div>

<div class="filter-group">
<label>Date Range</label>
<input type="text" value="05/01/2026 - 05/16/2026" readonly>
</div>

<button class="btn-custom">Generate Report</button>

</div>

<div class="row g-4">

<div class="col-lg-6">
<div class="large-card">
<div class="section-title">Top Supply Usage (Units Consumed)</div>
<div class="bar-chart">
<?php foreach ($barData as $bar): ?>
<div class="bar-col">
<div class="bar" style="height:<?php echo $bar['value']; ?>%;"></div>
<div class="bar-label"><?php echo htmlspecialchars($bar['label']); ?></div>
</div>
<?php endforeach; ?>
</div>
</div>
</div>

<div class="col-lg-6">
<div class="large-card">
<div class="section-title">Consumption Share by Category</div>
<div class="pie-wrap">
<div class="pie" style="background:conic-gradient(<?php echo $conicGradient; ?>);"></div>
<div class="pie-legend">
<?php foreach ($pieData as $slice): ?>
<div class="legend-row">
<span class="legend-dot" style="background:<?php echo $slice['color']; ?>;"></span>
<span><?php echo htmlspecialchars($slice['label']); ?></span>
<span class="legend-value"><?php echo $slice['value']; ?>%</span>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
</div>

<div class="col-12">
<div class="summary-empty">
<i class="bi bi-file-earmark-bar-graph"></i>
Select your filters above and click <strong>Generate Report</strong> to view a detailed line-item summary.
</div>
</div>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>