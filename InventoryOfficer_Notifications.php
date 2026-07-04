<?php
/*
  InventoryOfficer_Notifications.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Grounded in the Notification System described in Chapter 1 (alerts and
  reminders for low inventory levels) and the notifications table
  (title, message, notification_type, is_read, created_at) plus
  prediction_results for shortage alerts.
*/

$notifications = [
    ['type' => 'low_stock',  'icon' => 'bi-exclamation-triangle-fill', 'title' => 'Low Stock Alert',        'message' => 'Erig Com is running low — 7 vials remaining.',                     'time' => '2 hours ago', 'unread' => true],
    ['type' => 'expiring',   'icon' => 'bi-hourglass-split',           'title' => 'Expiring Stock Alert',   'message' => 'HRIG (Batch LOT-2603) expires in 5 days — 2 units remaining.',      'time' => '1 day ago',   'unread' => true],
    ['type' => 'prediction', 'icon' => 'bi-graph-up-arrow',            'title' => 'Shortage Prediction Alert', 'message' => 'High risk of shortage: Anti-Rabies Vaccine in 25 days.',        'time' => '2 days ago',  'unread' => false],
    ['type' => 'stock_in',   'icon' => 'bi-box-arrow-in-down',         'title' => 'Stock In Confirmed',     'message' => '50 vials of Speeda were added to inventory by Marc B.',            'time' => '3 days ago',  'unread' => false],
    ['type' => 'prediction', 'icon' => 'bi-graph-up-arrow',            'title' => 'Shortage Prediction Alert', 'message' => 'High risk of shortage: Insulin Syringe (2ml) in 20 days.',      'time' => '3 days ago',  'unread' => false],
    ['type' => 'low_stock',  'icon' => 'bi-exclamation-triangle-fill', 'title' => 'Low Stock Alert',        'message' => 'Mefenamic 500mg is below minimum stock — 18 pcs remaining.',        'time' => '4 days ago',  'unread' => false],
];

function notifIconClass($type) {
    switch ($type) {
        case 'low_stock':  return 'icon-low';
        case 'expiring':   return 'icon-expiring';
        case 'prediction': return 'icon-prediction';
        case 'stock_in':   return 'icon-in';
        default:           return 'icon-low';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Notifications</title>

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

.filter-tabs{
display:flex;
gap:10px;
flex-wrap:wrap;
}

.filter-tab{
background:white;
border:1px solid #dcdee8;
color:var(--primary);
font-weight:600;
font-size:13px;
padding:8px 16px;
border-radius:20px;
}

.filter-tab.active{
background:var(--primary);
color:white;
border-color:var(--primary);
}

.mark-read{
background:none;
border:none;
color:var(--primary);
font-weight:600;
font-size:14px;
}

.mark-read:hover{
text-decoration:underline;
}

.notif-list{
background:white;
border-radius:12px;
border:1px solid #dfe1ee;
overflow:hidden;
}

.notif-item{
display:flex;
gap:16px;
padding:18px 22px;
border-bottom:1px solid #eef0f7;
position:relative;
}

.notif-item:last-child{
border-bottom:none;
}

.notif-item.unread{
background:#F7F8FF;
}

.notif-icon{
width:44px;
height:44px;
border-radius:12px;
display:flex;
align-items:center;
justify-content:center;
font-size:18px;
flex-shrink:0;
}

.icon-low{
background:#FFEAEA;
color:var(--accent);
}

.icon-expiring{
background:#FFF4E5;
color:#C97A1A;
}

.icon-prediction{
background:#EDEFFA;
color:var(--primary);
}

.icon-in{
background:#E6F4EA;
color:#1E7B34;
}

.notif-body{
flex:1;
}

.notif-title{
font-weight:700;
color:var(--primary);
font-size:15px;
margin-bottom:2px;
}

.notif-message{
font-size:14px;
color:#555;
margin-bottom:4px;
}

.notif-time{
font-size:12px;
color:#9aa0c3;
}

.unread-dot{
width:9px;
height:9px;
border-radius:50%;
background:var(--accent);
flex-shrink:0;
margin-top:6px;
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
<li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
<li><a class="active" href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
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
<h3>Notifications</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<div class="toolbar">

<div class="filter-tabs">
<button class="filter-tab active">All</button>
<button class="filter-tab">Unread</button>
<button class="filter-tab">Low Stock</button>
<button class="filter-tab">Expiring</button>
<button class="filter-tab">Predictions</button>
</div>

<button class="mark-read"><i class="bi bi-check2-all me-1"></i>Mark all as read</button>

</div>

<div class="notif-list">
<?php foreach ($notifications as $n): ?>
<div class="notif-item <?php echo $n['unread'] ? 'unread' : ''; ?>">
<div class="notif-icon <?php echo notifIconClass($n['type']); ?>">
<i class="bi <?php echo $n['icon']; ?>"></i>
</div>
<div class="notif-body">
<div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
<div class="notif-message"><?php echo htmlspecialchars($n['message']); ?></div>
<div class="notif-time"><?php echo htmlspecialchars($n['time']); ?></div>
</div>
<?php if ($n['unread']): ?>
<div class="unread-dot"></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>