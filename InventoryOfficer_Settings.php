<?php
/*
  InventoryOfficer_Settings.php
  --------------------------------
  Frontend-only page. No DB connection yet.
  Fields map to the users table (username, password, branch_id, status)
  plus per-user notification preferences for low stock / expiring stock
  / prediction alerts described in the Notification System.
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Settings</title>

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

.settings-card{
background:#ECEEF7;
border-radius:18px;
padding:28px;
box-shadow:0 3px 8px rgba(0,0,0,.08);
margin-bottom:26px;
}

.section-title{
font-size:18px;
font-weight:700;
color:var(--primary);
margin-bottom:4px;
}

.section-sub{
font-size:13px;
color:#777;
margin-bottom:22px;
}

.settings-card label{
font-weight:600;
color:var(--primary);
font-size:14px;
margin-bottom:6px;
}

.settings-card .form-control,
.settings-card .form-select{
border-radius:10px;
border:1px solid #dcdee8;
padding:10px 14px;
font-size:14px;
background:white;
}

.settings-card .form-control:focus,
.settings-card .form-select:focus{
border-color:var(--primary);
box-shadow:none;
}

.settings-card .form-control[readonly]{
background:#e4e6f2;
color:#666;
}

.btn-custom{
background:var(--primary);
color:white;
border-radius:8px;
padding:10px 24px;
border:none;
font-weight:600;
font-size:14px;
}

.btn-custom:hover{
background:#1d2863;
color:white;
}

.pref-row{
display:flex;
align-items:center;
justify-content:space-between;
padding:14px 0;
border-bottom:1px solid #dcdee8;
}

.pref-row:last-child{
border-bottom:none;
}

.pref-label{
font-weight:600;
color:#333;
font-size:14px;
}

.pref-desc{
font-size:12.5px;
color:#888;
margin-top:2px;
}

.form-check-input:checked{
background-color:var(--primary);
border-color:var(--primary);
}

.form-switch .form-check-input{
width:42px;
height:22px;
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
<li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
<li><a class="active" href="InventoryOfficer_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
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
<h3>Settings</h3>
<div class="profile"> INVENTORY <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="page-body">

<!-- PROFILE INFO -->
<div class="settings-card">
<div class="section-title">Profile Information</div>
<div class="section-sub">Your account details as assigned by the Branch Admin.</div>
<form>
<div class="row g-4">
<div class="col-md-6">
<label>Full Name</label>
<input type="text" class="form-control" value="Juan Dela Cruz">
</div>
<div class="col-md-6">
<label>Username</label>
<input type="text" class="form-control" value="jdelacruz.inventory">
</div>
<div class="col-md-6">
<label>Branch</label>
<input type="text" class="form-control" value="SBI Medical - Cainta Branch" readonly>
</div>
<div class="col-md-6">
<label>Role</label>
<input type="text" class="form-control" value="Inventory Officer" readonly>
</div>
<div class="col-md-6">
<label>Contact Number</label>
<input type="text" class="form-control" placeholder="09XX XXX XXXX">
</div>
<div class="col-md-6">
<label>Email Address</label>
<input type="email" class="form-control" placeholder="name@sbimedical.com">
</div>
</div>
<div class="mt-4">
<button type="button" class="btn-custom">Save Changes</button>
</div>
</form>
</div>

<!-- CHANGE PASSWORD -->
<div class="settings-card">
<div class="section-title">Change Password</div>
<div class="section-sub">Use a strong password you don't use elsewhere.</div>
<form>
<div class="row g-4">
<div class="col-md-4">
<label>Current Password</label>
<input type="password" class="form-control" placeholder="Enter current password">
</div>
<div class="col-md-4">
<label>New Password</label>
<input type="password" class="form-control" placeholder="Enter new password">
</div>
<div class="col-md-4">
<label>Confirm New Password</label>
<input type="password" class="form-control" placeholder="Re-enter new password">
</div>
</div>
<div class="mt-4">
<button type="button" class="btn-custom">Update Password</button>
</div>
</form>
</div>

<!-- NOTIFICATION PREFERENCES -->
<div class="settings-card">
<div class="section-title">Notification Preferences</div>
<div class="section-sub">Choose which inventory alerts you want to receive.</div>

<div class="pref-row">
<div>
<div class="pref-label">Low Stock Alerts</div>
<div class="pref-desc">Get notified when an item falls below its minimum stock level.</div>
</div>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" checked>
</div>
</div>

<div class="pref-row">
<div>
<div class="pref-label">Expiring Stock Alerts</div>
<div class="pref-desc">Get notified when a batch is nearing its expiration date.</div>
</div>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" checked>
</div>
</div>

<div class="pref-row">
<div>
<div class="pref-label">Shortage Prediction Alerts</div>
<div class="pref-desc">Get notified when the predictive analytics module flags a likely shortage.</div>
</div>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox" checked>
</div>
</div>

<div class="pref-row">
<div>
<div class="pref-label">Email Notifications</div>
<div class="pref-desc">Also send the alerts above to your registered email address.</div>
</div>
<div class="form-check form-switch">
<input class="form-check-input" type="checkbox">
</div>
</div>

<div class="mt-4">
<button type="button" class="btn-custom">Save Preferences</button>
</div>

</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>