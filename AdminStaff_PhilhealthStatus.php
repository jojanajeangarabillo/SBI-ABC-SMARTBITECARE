<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Staff Philhealth Status</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!--REUSABLE SIDEBAR CSS-->
    <link rel="stylesheet" href="sidebar.css">

    <style>

        /*=========================================
          INTERNAL CSS
        =========================================*/
        :root {

            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;

        }

        body {

            background: white;

            font-family: 'Segoe UI', sans-serif;

        }

        .main {

            margin-left: 260px;

            min-height: 100vh;

        }

        .topbar {

            background: white;

            height: 80px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 0 35px;

            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);

        }

        .topbar h3 {

            font-size: 28px;

            font-weight: 700;

            color: var(--primary);

            margin: 0;

        }

        .profile {

            font-weight: 600;

            color: var(--primary);

            cursor: pointer;

        }
        
       /* ===========================
   TABLE
=========================== */

.table-wrapper{
    border:1px solid #444;
    border-radius:20px;
    overflow:hidden;
    background:#fff;
}

.table{
    width:100%;
    margin-bottom:0;
    border-collapse:collapse;
    border-spacing:0;
}

.table thead tr{
    background:#2D3D8F;
}

.table thead th{
    background:#2D3D8F !important;
    color:#fff !important;
    border:none !important;
    padding:16px;
    text-align:center;
    font-size:13px;
    font-weight:600;
}

.table tbody td{
    text-align:center;
    vertical-align:middle;
    padding:14px;
    border-top:1px solid #d9d9d9;
}

.table td a{
    color:#2D3D8F;
    margin:0 6px;
    text-decoration:none;
    font-size:16px;
}

        .content {
            padding: 25px;
        }

        /* Statistics */

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: #fff;
            border-radius: 18px;
            padding: 22px 28px;
            min-height: 125px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .08);
            position: relative;
            overflow: hidden;
        }

        .stat-box::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 6px;
            height: 100%;
            background: #2D3D8F;
        }

        /* Number */

        .stat-box h1 {
            margin: 0;
            font-size: 48px;
            font-weight: 700;
            line-height: 1;
            color: #2D3D8F;
        }

        /* Title */

        .stat-box h6 {
            margin: 8px 0 4px;
            font-size: 20px;
            font-weight: 600;
            text-transform: uppercase;
            color: #4f6482;
        }

        /* Subtitle */

        .stat-box p {
            margin: 0;
            color: #9aa6b2;
            font-size: 14px;
        }

        /* Toolbar */

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .left-tools {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-box {
            width: 320px;
            height: 45px;
            border: 1px solid #2D3D8F;
            border-radius: 10px;
            display: flex;
            align-items: center;
            padding: 0 15px;
            background: #fff;
        }

        .search-box i {
            color: #2D3D8F;
            margin-right: 10px;
        }

        .search-box input {
            width: 100%;
            border: none;
            outline: none;
            font-size: 13px;
        }

        .toolbar-btn {
            background: #2D3D8F;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 14px;
        }

        .toolbar-btn i {
            margin-right: 6px;
        }

        .date-box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 8px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
        }

        .date-box input {
            border: none;
            outline: none;
        }

        /* Table */

        .table-wrapper {
            border: 1px solid #444;
            border-radius: 20px;
            overflow: hidden;
        }

        .table thead {
            background: #2D3D8F;
            color: #fff;
        }

        .table th {
            text-align: center;
            border: none;
            font-size: 13px;
            padding: 14px;
        }

        .table td {
            text-align: center;
            vertical-align: middle;
            font-size: 14px;
            height: 46px;
        }

        .table td a {
            color: #2D3D8F;
            margin: 0 6px;
            font-size: 16px;
            text-decoration: none;
        }

        /* Pagination */

        .pagination-area {
            margin-top: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
        }

        .pagination-area span {
            cursor: pointer;
            color: #2D3D8F;
            font-size: 14px;
        }

        .active-page {
            width: 28px;
            height: 28px;
            background: #2D3D8F;
            color: #fff !important;
            border-radius: 5px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .pagination-area i {
            color: #8a8a8a;
            cursor: pointer;
        }

        /* Responsive */

        @media (max-width: 1200px) {

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 768px) {

            .stats {
                grid-template-columns: 1fr;
            }

            .toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .left-tools {
                flex-wrap: wrap;
            }

            .search-box {
                width: 100%;
            }

        }

        @media (max-width: 991px) {

            .main {

                margin-left: 90px;

            }

        }

        .bi-pencil-square {
            margin-right: 12px;
        }

        .bi-trash3-fill {
            margin-left: 12px;
        }

        /* =========================================
           MAIN CONTENT STYLES
           ========================================= */

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
            <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
            <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
            <li><a class="active" href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
            <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
            <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>

    </nav>

    <div class="logout">
        <a href="#"> <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>

</div>

<!-- MAIN CONTENT -->

<div class="main">

    <!-- Top Header -->
    <div class="topbar">
        <h3>PhilHealth Patient Status</h3>

        <div class="profile">
            ADMIN STAFF
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

    <div class="content">

        <div class="stats">

            <div class="stat-box writing">
                <h1>43</h1>
                <h6>For Writing</h6>
                <p>Waiting to be processed</p>
            </div>

            <div class="stat-box screening">
                <h1>28</h1>
                <h6>For Screening</h6>
                <p>Under verification</p>
            </div>

            <div class="stat-box signing">
                <h1>33</h1>
                <h6>For Signing/Transmittal</h6>
                <p>Ready for approval</p>
            </div>

            <div class="stat-box completed">
                <h1>33</h1>
                <h6>Completed</h6>
                <p>Successfully processed</p>
            </div>

        </div>

        <!-- Controls -->
        <div class="toolbar">

            <div class="left-tools">

                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search Patient, Case No., Status, etc...">
                </div>

                <button class="btn toolbar-btn">
                    <i class="bi bi-funnel"></i>
                    Filters
                </button>

                <button class="btn toolbar-btn">
                    <i class="bi bi-file-earmark-arrow-down"></i>
                    Export
                </button>

            </div>

            <div class="date-box">

                <i class="bi bi-calendar3"></i>

                <input type="date">

            </div>

        </div>

        <!-- Table -->

        <div class="table-wrapper">

            <table class="table mb-0">

                <thead>

                <tr>

                    <th>Case No.</th>
                    <th>Patient Name</th>
                    <th>Date of Confinement</th>
                    <th>Date of Discharged</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Actions</th>

                </tr>

                </thead>

                <tbody>

                <tr>

                    <td>26-0001</td>
                    <td>Dela Cruz, Juan</td>
                    <td>05/14/2026</td>
                    <td>05/21/2026</td>
                    <td>For Signing/Transmittal</td>
                    <td>N/A</td>

                    <td>

                        <a href="#"><i class="bi bi-eye"></i></a>

                        <a href="#"><i class="bi bi-pencil"></i></a>

                        <a href="#"><i class="bi bi-trash"></i></a>

                    </td>

                </tr>

                <tr><td colspan="7">&nbsp;</td></tr>
                <tr><td colspan="7">&nbsp;</td></tr>
                <tr><td colspan="7">&nbsp;</td></tr>
                <tr><td colspan="7">&nbsp;</td></tr>
                <tr><td colspan="7">&nbsp;</td></tr>
                <tr><td colspan="7">&nbsp;</td></tr>
                <tr><td colspan="7">&nbsp;</td></tr>

                </tbody>

            </table>

        </div>

        <!-- Pagination -->

        <div class="pagination-area">

            <i class="bi bi-chevron-left"></i>

            <span class="active-page">1</span>

            <span>2</span>
            <span>3</span>
            <span>4</span>
            <span>5</span>
            <span>6</span>
            <span>7</span>
            <span>8</span>

            <i class="bi bi-chevron-right"></i>

        </div>

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>
</body>
</html>