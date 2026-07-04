<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Branch Admin Dashboard</title>

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

height:140px;

box-shadow:0 3px 8px rgba(0,0,0,.08);

}

.stat-title{

font-weight:600;

color:var(--primary);

font-size:18px;

}

.stat-number{

margin-top:20px;

font-size:48px;

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

.placeholder-box{

height:220px;

background:#ddd;

display:flex;

align-items:center;

justify-content:center;

font-size:34px;

color:#999;

border-radius:12px;

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

.activity{

margin-bottom:14px;

}

.activity i{

color:var(--accent);

margin-right:10px;

}

.alert-item{

margin-bottom:14px;

}

.alert-item i{

color:var(--accent);

margin-right:10px;

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

<li><a class="active" href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>

<li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>

<li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>

<li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>

<li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>

<li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>

<li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>

<li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>

<li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>

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
<div class="profile"> ADMIN <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="dashboard">
<div class="row g-4">

<div class="col-lg-4">

<div class="stat-card">
<div class="stat-title">Total Patients</div>
<div class="stat-number">1,250</div>
</div>

</div>

<div class="col-lg-4">

<div class="stat-card">
<div class="stat-title">Ongoing Cases</div>
<div class="stat-number">86</div>
</div>

</div>

<div class="col-lg-4">

<div class="stat-card">
<div class="stat-title">Completed Cases</div>
<div class="stat-number">1,064</div>
</div>

</div>

<div class="col-lg-6">

<div class="stat-card">
<div class="stat-title">Low Stocks</div>
<div class="stat-number">45</div>
</div>

</div>

<div class="col-lg-6">

<div class="stat-card">
<div class="stat-title">Expiring Stocks</div>
<div class="stat-number">45</div>
</div>

</div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Patient Trend <small class="text-muted">(This Month)</small>
                </div>

                <div class="placeholder-box">
                    PLACEHOLDER
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Supply Usage Trend
                </div>

                <div class="placeholder-box">
                    PLACEHOLDER
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Prediction Alerts
                </div>

                <div class="alert-item">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    High risk of shortage. Anti-rabies Vaccine in 25 days
                </div>

                <div class="alert-item">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    High risk of shortage. Syringe 2ml in 20 days
                </div>

                <div class="text-end mt-4">
                    <button class="btn btn-custom">
                        View All Alerts
                    </button>
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Recent Activities
                </div>

                <div class="activity">
                    <i class="bi bi-square-fill"></i>
                    Juan Dela Cruz record updated
                </div>

                <div class="activity">
                    <i class="bi bi-square-fill"></i>
                    Inventory restock added
                </div>

                <div class="activity">
                    <i class="bi bi-square-fill"></i>
                    New nurse registered: Nurse Ara
                </div>

                <div class="text-end mt-4">
                    <button class="btn btn-custom">
                        View All
                    </button>
                </div>

            </div>

        </div>

    </div>

</div>

</div>

<script src="https://cdn.jsdelivr.nBranch Officer Interface et/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>