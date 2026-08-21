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

// UI Logic: Group notifications by relative date without altering the data strings
$groupedItems = [];
foreach ($notifications as $n) {
    $timeString = $n['time'];
    if (strpos($timeString, 'hours') !== false) {
        $groupKey = 'Today';
    } elseif (strpos($timeString, 'day') !== false) {
        $groupKey = 'Yesterday';
    } else {
        $groupKey = 'Earlier';
    }
    $groupedItems[$groupKey][] = $n;
}
$orderedGroups = [];
if (isset($groupedItems['Today'])) $orderedGroups['Today'] = $groupedItems['Today'];
if (isset($groupedItems['Yesterday'])) $orderedGroups['Yesterday'] = $groupedItems['Yesterday'];
if (isset($groupedItems['Earlier'])) $orderedGroups['Earlier'] = $groupedItems['Earlier'];

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
background:#f0f2f5; 
font-family:'Segoe UI', Roboto, system-ui, sans-serif;
color:#1e293b;
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
box-shadow:0 2px 12px rgba(0,0,0,0.04);
}

.topbar h3{
font-size:26px;
font-weight:700;
color:var(--primary);
margin:0;
}

.profile{
font-weight:600;
color:var(--primary);
cursor:pointer;
display:flex;
align-items:center;
gap:4px;
}

.page-body{
padding:35px;
}

/* --- UPDATED TOOLBAR (Matching Reference Image) --- */
.toolbar{
display:flex;
align-items:center;
justify-content:space-between;
margin-bottom:28px;
flex-wrap:wrap;
gap:12px;
}

.toolbar-left{
display:flex;
align-items:center;
gap:14px;
flex-wrap:wrap;
}

.search-box{
display:flex;
align-items:center;
background:white;
border:1.5px solid #64748b; /* Darker border matching the image */
border-radius:30px;
padding:0 16px;
height:42px;
width:320px;
transition:border 0.2s;
}

.search-box:focus-within{
border:1.5px solid var(--primary);
box-shadow:0 0 0 2px rgba(43,58,140,0.1);
}

.search-box i{
color:#94a3b8;
font-size:1.1rem;
margin-right:8px;
}

.search-box input{
border:none;
outline:none;
width:100%;
font-size:14px;
background:transparent;
color:#334155;
}

.search-box input::placeholder{
color:#94a3b8;
font-weight:500;
}

.btn-filter{
background:var(--primary);
color:white;
border:none;
padding:0 18px;
height:42px;
border-radius:8px;
display:flex;
align-items:center;
gap:8px;
font-weight:600;
font-size:14px;
}

.btn-filter i{
font-size:0.9rem;
}

.branch-text{
color:#64748b;
font-size:14px;
font-weight:500;
}

.btn-mark-all{
background:#22c55e; /* Reference Green */
color:white;
border:none;
padding:0 22px;
height:42px;
border-radius:8px;
display:flex;
align-items:center;
gap:8px;
font-weight:600;
font-size:14px;
transition:background 0.2s;
}

.btn-mark-all:hover{
background:#16a34a;
}

/* --- DATE GROUP HEADER --- */
.date-header{
color:var(--primary);
font-size:18px;
font-weight:700;
margin:24px 0 16px 0;
}
.date-header:first-of-type{
margin-top:0;
}

/* --- NOTIFICATION CARD (Exact Layout) --- */
.notif-item{
display:flex;
background:white;
border-radius:12px;
margin-bottom:18px;
padding:18px 20px;
border:1px solid #e2e8f0;
position:relative;
box-shadow:0 1px 2px rgba(0,0,0,0.04);
transition:box-shadow 0.2s, border-color 0.2s;
}

.notif-item:hover{
box-shadow:0 4px 12px rgba(0,0,0,0.05);
}

/* Left Accent Borders Based on Type */
.border-low_stock{ border-left: 6px solid #ef4444; }
.border-expiring{ border-left: 6px solid #eab308; }
.border-prediction{ border-left: 6px solid #3b82f6; }
.border-stock_in{ border-left: 6px solid #22c55e; }

.notif-icon-wrap{
display:flex;
align-items:flex-start;
padding-right:16px;
}

.notif-icon{
width:48px;
height:48px;
border-radius:12px; /* Rounded box */
display:flex;
align-items:center;
justify-content:center;
font-size:1.4rem;
flex-shrink:0;
}

.icon-low{ background:#fee2e2; color:#dc2626; }
.icon-expiring{ background:#fef9c3; color:#ca8a04; }
.icon-prediction{ background:#dbeafe; color:#2563eb; }
.icon-in{ background:#dcfce7; color:#16a34a; }

.notif-content{
flex:1;
padding:2px 0;
display:flex;
flex-direction:column;
justify-content:center;
}

.notif-title{
font-weight:700;
color:#0f172a;
font-size:15px;
margin-bottom:4px;
}

.notif-message{
font-size:14px;
color:#475569;
margin-bottom:6px;
line-height:1.4;
}

.notif-time{
font-size:12px;
color:#94a3b8;
}

.notif-actions{
display:flex;
flex-direction:column;
align-items:flex-end;
justify-content:center;
padding-left:16px;
gap:8px;
}

/* Badge Pill */
.badge-status-pill{
padding:4px 14px;
border-radius:30px;
font-size:12px;
font-weight:600;
display:inline-block;
}
.badge-low_stock{ background:#fee2e2; color:#dc2626; }
.badge-expiring{ background:#fef9c3; color:#ca8a04; }
.badge-prediction{ background:#dbeafe; color:#2563eb; }
.badge-stock_in{ background:#dcfce7; color:#16a34a; }

/* Action Button */
.notif-action-btn{
background:var(--primary);
color:white;
border:none;
border-radius:8px;
padding:6px 16px;
font-size:13px;
font-weight:500;
transition:background 0.2s;
}
.notif-action-btn:hover{
background:#1d2863;
}

/* Mark Read Toggle */
.mark-read-row{
display:flex;
align-items:center;
gap:6px;
font-size:13px;
color:#64748b;
cursor:pointer;
margin-top:2px;
}

.mark-read-row .circle-icon{
width:14px;
height:14px;
border-radius:50%;
border:2px solid #cbd5e1;
display:inline-block;
transition:border-color 0.2s;
}

.mark-read-row:hover .circle-icon{
border-color:var(--primary);
}

@media(max-width:991px){
.main{ margin-left:90px; }
.search-box{ width:100%; max-width:300px; }
.toolbar-left{ width:100%; }
.btn-mark-all{ width:100%; justify-content:center; }
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
<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
<li><a class="active" href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
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
<h3>Notifications</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<!-- Enhanced Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search Notifications...">
        </div>
        <button class="btn-filter">
            <i class="bi bi-funnel-fill"></i> Filters <i class="bi bi-caret-down-fill" style="font-size: 10px;"></i>
        </button>
        <span class="branch-text">Cainta Branch</span>
    </div>
    <button class="btn-mark-all">
        <i class="bi bi-check-lg"></i> Mark All as Read
    </button>
</div>

<!-- Grouped Notifications -->
<?php foreach ($orderedGroups as $groupLabel => $items): ?>
    <div class="date-header"><?php echo htmlspecialchars($groupLabel); ?></div>
    
    <?php foreach ($items as $n): 
        // Mapping type to badge text (UI only)
        $badgeText = match($n['type']) {
            'low_stock' => 'Low Stock',
            'expiring' => 'Expiring',
            'prediction' => 'Alert',
            'stock_in' => 'Restocked',
            default => 'Update',
        };
    ?>
    <div class="notif-item border-<?php echo $n['type']; ?>">
        <div class="notif-icon-wrap">
            <div class="notif-icon <?php echo notifIconClass($n['type']); ?>">
                <i class="bi <?php echo $n['icon']; ?>"></i>
            </div>
        </div>
        
        <div class="notif-content">
            <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
            <div class="notif-message"><?php echo htmlspecialchars($n['message']); ?></div>
            <div class="notif-time"><?php echo htmlspecialchars($n['time']); ?></div>
        </div>
        
        <div class="notif-actions">
            <span class="badge-status-pill badge-<?php echo $n['type']; ?>"><?php echo htmlspecialchars($badgeText); ?></span>
            <button class="notif-action-btn">View Details</button>
            <div class="mark-read-row">
                <span class="circle-icon"></span> Mark Read
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endforeach; ?>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>