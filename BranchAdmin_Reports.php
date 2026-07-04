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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 20px;
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
        REPORT DROPDOWN
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

        /* Inventory submenu */
        .dropdown-submenu{
            position: relative;
        }

        .submenu{
            position: absolute;

            top: 0;
            right: 100%;
            left: auto;

            min-width: 240px;

            display: none;

            list-style: none;

            background: #fff;

            padding: 10px;

            border-radius: 12px;

            box-shadow: 0 10px 30px rgba(0,0,0,.15);

            z-index: 1055;
        }

        .dropdown-submenu:hover .submenu{
            display: block;
        }

    /*=================================
        ACTION ICONS
    ==================================*/

        .action-buttons{
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 18px;
        }

        .action-icon{
            font-size: 20px;
            color: #5b6bb2;
            cursor: pointer;
            transition: color .2s ease,
            transform .2s ease;
        }

        .action-icon:hover{
            color: #2B3A8C;
            transform: scale(1.15);
        }

        /* Table Card */
        .table-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .table-card .table {
            margin: 0;
            border: 1px solid #d9dee8;
        }

        .table-card .table thead th {
            background: var(--primary);
            color: #fff;
            padding: 16px 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #e9edf4;
        }

        .table-card .table tbody td {
            padding: 14px 20px;
            color: #1e293b;
            border-bottom: 1px solid #f0f2f7;
        }

        .table-card .table tbody tr:hover {
            background: #f8faff;
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

            .table-card .table thead th,
            .table-card .table tbody td {
                padding: 12px 14px;
                font-size: 13px;
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
            .table-card .table thead th {
                font-size: 11px;
                padding: 10px 10px;
            }

            .table-card .table tbody td {
                font-size: 12px;
                padding: 10px 10px;
            }

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

                <li><a class="active" href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>

                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>

                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>

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
            <h3>Reports</h3>
            <div class="profile"> ADMIN <i class="bi bi-caret-down-fill"></i> </div>
        </div>

        <div class="content-wrapper">

           
            <div class="page-header">

            <!-- Search Bar -->
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search Reports...">
                </div>

                <div class="dropdown">

    <button class="btn btn-filter dropdown-toggle"
            type="button"
            data-bs-toggle="dropdown"
            aria-expanded="false">

        <i class="bi bi-clipboard2-data"></i>
        Generate Report

    </button>

    <ul class="dropdown-menu report-dropdown">

        <li>
            <a class="dropdown-item" href="#">
                <i class="bi bi-person"></i>
                Patient Report
            </a>
        </li>

        <li>
            <a class="dropdown-item" href="#">
                <i class="bi bi-clipboard2-pulse"></i>
                Case Report
            </a>
        </li>

        <!-- Inventory Submenu -->
        <li class="dropdown-submenu">

            <a class="dropdown-item submenu-toggle" href="#">
                <i class="bi bi-box-seam"></i>
                Inventory Report
                <i class="bi bi-chevron-right float-end"></i>
            </a>

            <ul class="submenu">

                <li>
                    <a class="dropdown-item" href="#">
                        <i class="bi bi-capsule"></i>
                        Medical Supplies
                    </a>
                </li>

                <li>
                    <a class="dropdown-item" href="#">
                        <i class="bi bi-box"></i>
                        Non-Medical Supplies
                    </a>
                </li>

            </ul>

        </li>

        <li>
            <a class="dropdown-item" href="#">
                <i class="bi bi-graph-up-arrow"></i>
                Prediction Report
            </a>
        </li>

    </ul>

</div>

            </div>

            <!-- Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Report Name</th>
                                <th>Report Type</th>
                                <th>Date Generated</th>
                                <th>Generated By</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <tr>
                                <td>Patient Report- June 2026</td>
                                <td>Patient Report</td>
                                <td>6-30-2026 10:30 AM</td>
                                <td>Admin</td>
                            <td>
                                <div class="action-buttons">
                                    <i class="bi bi-eye action-icon"></i>
                                    <i class="bi bi-download action-icon"></i>
                                </div>
                            </td>
                        </tr>

                         <tr>
                                <td>Inventory Report (Medical Supplies)- June 2026</td>
                                <td>Inventory Report</td>
                                <td>6-30-2026 10:30 AM</td>
                                <td>Nurse</td>
                                <td>
                                <div class="action-buttons">
                                    <i class="bi bi-eye action-icon"></i>
                                    <i class="bi bi-download action-icon"></i>
                                </div>
                            </td>
                        </tr>

                            
                        </tbody>
                    </table>
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