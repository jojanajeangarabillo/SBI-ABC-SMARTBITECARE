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


        @media(max-width:991px){

            .main{

                margin-left:90px;

            }

        }

        .bi-pencil-square{
            margin-right: 12px;
    }
        .bi-trash3-fill{
            margin-left: 12px;
    }

        /* =========================================
           NEW MAIN CONTENT STYLES
           ========================================= */
        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

    .page-header{
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:25px;
    }

    .header-left{
        display:flex;
        align-items:center;
        gap:15px;
    }

    .btn-readall{

        background:#198754;

        color:#fff;

        border:none;

        border-radius:8px;

        padding:10px 18px;

        font-weight:600;

        transition:.2s;

    }

    .btn-readall:hover{

        background:#157347;

        color:#fff;

    }

    .btn-readall i{

        margin-right:8px;

    }

        /* Search Box */
        .search-box{
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid var(--primary);
            border-radius: 50px;
            padding: 8px 18px;
            width: 300px;
        }

        .search-box i{
            color: var(--primary);
            font-size: 18px;
            margin-right: 10px;
        }

        .search-box input{
            border: none;
            outline: none;
            background: transparent;
            color: black;
            width: 100%;
            font-size: 14px;
        }

        .search-box input::placeholder{
            color: rgba(0, 0, 0, 0.85);
        }

        /*=========================
        EXPORT DROPDOWN
        =========================*/

        .btn-filter{
            background: var(--primary);
            color: #fff;
            border: 2px solid var(--primary);
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            transition: .2s;
        }

        .btn-filter:hover,
        .btn-filter:focus,
        .btn-filter:active,
        .btn-filter.show{
            background: #1f2d6e;
            color: #fff;
            border-color: #1f2d6e;
            box-shadow: none;
        }

        .btn-filter i{
            margin-right: 8px;
        }

        .report-dropdown{
            width:260px;
            border:1px solid #d9dee8;
            border-radius:12px;
            box-shadow:0 10px 30px rgba(0,0,0,.15);
            padding:10px;
        }

        .report-dropdown .dropdown-item{
            padding:12px 15px;
            border-radius:8px;
            font-weight:600;
            display:flex;
            align-items:center;
            gap:12px;
        }

        .report-dropdown .dropdown-item:hover{
            background:#f3f5fb;
        }

        .report-dropdown i{
            color:var(--primary);
            font-size:18px;
        }

        /*=========================
        NOTIFICATIONS
        =========================*/

        .notification-day{
            margin:25px 0 15px;
        }

        .notification-day h5{
            color:var(--primary);
            font-weight:700;
        }
        .notification-section{

            width:100%;

            margin-top:25px;
        }

        .notification-card{

            display:flex;
            align-items:center;
            justify-content:space-between;

            width:100%;

            background:#fff;
            border:1px solid #e8ebf3;
            border-radius:12px;

            padding:20px;

            margin-bottom:18px;}

        .notification-card:hover{

            box-shadow:0 5px 20px rgba(0,0,0,.08);

        }

        .notification-icon{

            width:65px;
            height:65px;

            border-radius:50%;

            display:flex;
            align-items:center;
            justify-content:center;

            font-size:28px;

            margin-right:20px;

            flex-shrink:0;

        }

        .notification-icon.danger{

            background:#fdecec;

            color:#dc3545;

        }

        .notification-icon.warning{

            background:#fff4dd;

            color:#f59e0b;

        }

        .notification-icon.expire{

            background:#fff8e6;

            color:#ff9800;

        }

        .notification-content{

            flex:1;

        }

        .notification-content h6{

            font-size:18px;

            font-weight:700;

            color:#222;

            margin-bottom:6px;

        }

        .notification-content p{

            margin-bottom:6px;

            color:#555;

        }

        .notification-content small{

            color:#999;

        }

        .notification-right{

            display:flex;

            flex-direction:column;

            align-items:flex-end;

            gap:10px;

        }

        .btn-view{

            background:var(--primary);

            color:#fff;

            border:none;

            padding:8px 18px;

            border-radius:8px;

            font-size:14px;

            font-weight:600;

        }

        .btn-view:hover{

            background:#1f2d6e;

            color:#fff;

        }

        .unread{

            border-left:5px solid #dc3545;

        }
    

        /* pagination */
        .pagination-wrap {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination-wrap .pagination {
            margin: 0;
            gap: 2px;
        }

        .pagination-wrap .page-link {
            border: none;
            color: #2d3a7a;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 15px;
            border-radius: 8px;
            background: transparent;
            transition: background 0.15s, color 0.15s;
        }

        .pagination-wrap .page-link:hover {
            background: #eef2ff;
            color: var(--primary);
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
        }

        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
            opacity: 0.6;
        }

        .pagination-wrap .page-item:first-child .page-link,
        .pagination-wrap .page-item:last-child .page-link {
            font-size: 16px;
            padding: 8px 12px;
        }

        /* responsive tweaks */
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 18px 16px 30px 16px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }

            .btn-add-user {
                justify-content: center;
                width: 100%;
            }


            .quick-actions-card {
                padding: 18px 18px;
            }

            .qa-btn-group .btn-qa {
                padding: 8px 16px;
                font-size: 12px;
                flex: 1 0 auto;
            }

            .pagination-wrap .page-link {
                padding: 6px 11px;
                font-size: 13px;
            }
        }

        @media (max-width: 576px) {

            .badge-status {
                font-size: 10px;
                padding: 4px 10px;
            }

            .qa-btn-group {
                flex-direction: column;
            }

            .qa-btn-group .btn-qa {
                justify-content: center;
            }
        }

        /* topbar profile dropdown */
        .admin-profile {
            font-weight: 700;
            color: var(--primary);
            cursor: default;
            font-size: 15px;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .admin-profile i {
            font-size: 12px;
            opacity: 0.7;
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

                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>

                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>

                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>

                <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>

                <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>

                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>

                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>

                <li><a class="active" href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>

                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>

            </ul>

        </nav>

        <div class="logout">
            <a href="landing.php"> <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>

    </div>

    <!-- MAIN CONTENT -->

    <div class="main">

        <!-- Top Header -->

        <div class="topbar">
            <h3>Notifications</h3>
            <div class="profile"> ADMIN <i class="bi bi-caret-down-fill"></i> </div>
        </div>

        <div class="content-wrapper">

           
<div class="page-header">

    <div class="header-left">

        <!-- Search -->
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search Notifications...">
        </div>

        <!-- Filter -->
        <div class="dropdown">
            <button class="btn btn-filter dropdown-toggle"
                    type="button"
                    data-bs-toggle="dropdown">

                <i class="bi bi-funnel"></i>
                Filters

            </button>

            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="#">Unread</a></li>
                <li><a class="dropdown-item" href="#">Read</a></li>
                <li><a class="dropdown-item" href="#">Low Stock</a></li>
                <li><a class="dropdown-item" href="#">Prediction</a></li>
                <li><a class="dropdown-item" href="#">Expiring Vaccine</a></li>
            </ul>
        </div>

    </div>

    <!-- Right Side -->
    <button class="btn btn-readall">
        <i class="bi bi-check2-all"></i>
        Mark All as Read
    </button>

</div>

<!-- Notifications -->

<div class="notification-section">

    <div class="notification-day">
        <h5>Today</h5>
    </div>

    <!-- Notification -->
    <div class="notification-card unread">

        <div class="notification-icon danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>

        <div class="notification-content">
            <h6>Low Stock Alert</h6>
            <p>Rabies Vaccine (Speeda) has only <strong>8 stocks</strong> remaining.</p>

            <small>10:30 AM</small>
        </div>

        <div class="notification-right">

            <span class="badge bg-danger">Unread</span>

            <button class="btn btn-view">
                View Inventory
            </button>

        </div>

    </div>

    <!-- Notification -->
    <div class="notification-card unread">

        <div class="notification-icon warning">
            <i class="bi bi-graph-up-arrow"></i>
        </div>

        <div class="notification-content">
            <h6>Prediction Alert</h6>
            <p>XGBoost predicts vaccine shortage within the next 14 days.</p>

            <small>09:45 AM</small>
        </div>

        <div class="notification-right">

            <span class="badge bg-warning text-dark">Unread</span>

            <button class="btn btn-view">
                View Prediction
            </button>

        </div>

    </div>

    <!-- Notification -->
    <div class="notification-card">

        <div class="notification-icon expire">
            <i class="bi bi-calendar-event-fill"></i>
        </div>

        <div class="notification-content">
            <h6>Expiring Vaccine Alert</h6>
            <p>Verorab Vaccine will expire in <strong>15 days</strong>.</p>

            <small>08:15 AM</small>
        </div>

        <div class="notification-right">

            <span class="badge bg-success">Read</span>

            <button class="btn btn-view">
                View Inventory
            </button>

        </div>

    </div>

    <div class="notification-day mt-4">
        <h5>Yesterday</h5>
    </div>

    <div class="notification-card">

        <div class="notification-icon danger">
            <i class="bi bi-exclamation-circle-fill"></i>
        </div>

        <div class="notification-content">
            <h6>Low Stock Alert</h6>
            <p>Syringes have reached the minimum stock level.</p>

            <small>Yesterday • 3:20 PM</small>
        </div>

        <div class="notification-right">

            <span class="badge bg-success">Read</span>

            <button class="btn btn-view">
                View Inventory
            </button>

        </div>

    </div>

</div>

            <!-- Pagination -->
            <div class="pagination-wrap">
                <nav aria-label="User table pagination">
                    <ul class="pagination">
                        <li class="page-item disabled">
                            <a class="page-link" href="#" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item"><a class="page-link" href="#">4</a></li>
                        <li class="page-item"><a class="page-link" href="#">5</a></li>
                        <li class="page-item"><a class="page-link" href="#">6</a></li>
                        <li class="page-item"><a class="page-link" href="#">7</a></li>
                        <li class="page-item"><a class="page-link" href="#">8</a></li>
                        <li class="page-item">
                            <a class="page-link" href="#" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>


    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>