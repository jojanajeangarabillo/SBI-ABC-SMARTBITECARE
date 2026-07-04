<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Staff Medical Documents</title>

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

        .content{
            padding:30px;
        }

        .section-card{
            background:#fff;
            border:1px solid #e8e8e8;
            border-radius:18px;
            padding:20px;
            box-shadow:0 4px 12px rgba(0,0,0,.05);
        }

        .section-title{
            color:#1F2A44;
            font-weight:700;
            margin-bottom:18px;
        }

        /* Cards */

        .document-grid{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:20px;
        }

        .document-card{
            border:1px solid #ececec;
            border-radius:15px;
            padding:22px;
            display:flex;
            gap:18px;
            align-items:flex-start;
        }

        .document-icon{
            width:78px;
            height:78px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:38px;
        }

        .medical{
            background:#EAF2FF;
            color:#2563EB;
        }

        .vaccine{
            background:#E8FAF2;
            color:#1DBA6C;
        }

        .referral{
            background:#F2EAFE;
            color:#7C4DFF;
        }

        .document-details h5{
            font-size:20px;
            font-weight:700;
            margin-bottom:12px;
        }

        .document-details p{
            color:#666;
            line-height:1.6;
            margin-bottom:18px;
        }

        .blue-btn{
            background:var(--primary);
            color:#fff;
        }

        .green-btn{
            background:var(--primary);
            color:#fff;
        }

        .purple-btn{
            background:var(--primary);
            color:#fff;
        }

        .blue-btn,
        .green-btn,
        .purple-btn{
            border:none;
            border-radius:8px;
            padding:10px 22px;
        }

        /* Table */

        .table-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:18px;
        }

        .view-btn{
            text-decoration:none;
            color:#2563EB;
            border:1px solid #dbe6ff;
            padding:8px 15px;
            border-radius:8px;
            font-weight:600;
        }

        .table{
            margin-bottom:0;
        }

        .table thead{
            background:#F7F9FC;
        }

        .table th{
            border:none;
            color:#555;
            padding:15px;
        }

        .table td{
            padding:18px 15px;
            vertical-align:middle;
        }

        /* ===========================
             ACTION BUTTONS
        =========================== */

        .actions{
            white-space:nowrap;
            text-align:center;
        }

        .action-btn{
            width:42px;
            height:42px;
            display:inline-flex;
            justify-content:center;
            align-items:center;
            border-radius:50%;
            margin:0 4px;
            text-decoration:none;
            font-size:20px;
            transition:all .25s ease;
        }

        /* View */

        .view{
            color:#4F63B8;
            background:transparent;
        }

        .view:hover{
            background:#EEF2FF;
            color:#2B3A8C;
            transform:translateY(-2px);
        }

        /* Print */

        .print{
            color:#27AE60;
            background:transparent;
        }

        .print:hover{
            background:#EAF9F1;
            color:#1E874B;
            transform:translateY(-2px);
        }

        /* Download */

        .download{
            color:#6C63FF;
            background:transparent;
        }

        .download:hover{
            background:#F1EEFF;
            color:#574BDB;
            transform:translateY(-2px);
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
            <li><a href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
            <li><a href="AdminStaff_MedicalDocuments.php" class="active"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
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
        <h3>Medical Documents</h3>

        <div class="profile">
            ADMIN STAFF
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

   <div class="content">

    <!-- ================= Document Types ================= -->

    <div class="section-card">

        <h4 class="section-title">Document Types</h4>

        <div class="document-grid">

            <!-- Medical Certificate -->

            <div class="document-card">

                <div class="document-icon medical">
                    <i class="bi bi-file-earmark-medical"></i>
                </div>

                <div class="document-details">

                    <h5>Medical Certificate /<br>Fit-to-Work Certificate</h5>

                    <p>
                        Certifies the medical condition
                        and fitness of the patient.
                    </p>

                    <button class="btn blue-btn">
                        Create Document
                    </button>

                </div>

            </div>

            <!-- Vaccination -->

            <div class="document-card">

                <div class="document-icon vaccine">
                    <i class="bi bi-shield-check"></i>
                </div>

                <div class="document-details">

                    <h5>Vaccination Certificate</h5>

                    <p>
                        Certifies the vaccination details
                        of the patient.
                    </p>

                    <button class="btn green-btn">
                        Create Document
                    </button>

                </div>

            </div>

            <!-- Referral -->

            <div class="document-card">

                <div class="document-icon referral">
                    <i class="bi bi-envelope"></i>
                </div>

                <div class="document-details">

                    <h5>Referral Letter</h5>

                    <p>
                        Refers the patient to another
                        healthcare provider.
                    </p>

                    <button class="btn purple-btn">
                        Create Document
                    </button>

                </div>

            </div>

        </div>

    </div>

    <!-- ================= Recent Documents ================= -->

        <div class="section-card mt-4">

            <div class="table-header">

                <h4 class="section-title mb-0">
                    Recently Created Documents
                </h4>

                <a href="#" class="view-btn">
                    <i class="bi bi-file-earmark-text"></i>
                    View All Documents
                </a>

            </div>

            <table class="table align-middle">

                <thead>

                <tr>

                    <th>Document Type</th>
                    <th>Patient Name</th>
                    <th>Date Created</th>
                    <th>Created By</th>
                    <th>Actions</th>

                </tr>

                </thead>

                <tbody>

                <tr>

                    <td><i class="bi bi-file-earmark-medical text-primary me-2"></i>Medical Certificate</td>

                    <td>Juan Dela Cruz</td>

                    <td>May 14, 2025 10:30 AM</td>

                    <td>Admin Staff</td>

                <td class="actions">

                    <a href="#" class="action-btn view" title="View">
                        <i class="bi bi-eye"></i>
                    </a>

                    <a href="#" class="action-btn print" title="Print">
                        <i class="bi bi-printer"></i>
                    </a>

                    <a href="#" class="action-btn download" title="Download">
                        <i class="bi bi-download"></i>
                    </a>

                </td>

                </tr>

                <tr>

                    <td><i class="bi bi-shield-check text-success me-2"></i>Vaccination Certificate</td>

                    <td>Maria Santos</td>

                    <td>May 14, 2025 09:15 AM</td>

                    <td>Admin Staff</td>

                   <td class="actions">

                    <a href="#" class="action-btn view" title="View">
                        <i class="bi bi-eye"></i>
                    </a>

                    <a href="#" class="action-btn print" title="Print">
                        <i class="bi bi-printer"></i>
                    </a>

                    <a href="#" class="action-btn download" title="Download">
                        <i class="bi bi-download"></i>
                    </a>

                </td>

                </tr>

                <tr>

                    <td><i class="bi bi-envelope text-secondary me-2"></i>Referral Letter</td>

                    <td>Pedro Reyes</td>

                    <td>May 13, 2025 02:45 PM</td>

                    <td>Admin Staff</td>

                   <td class="actions">

                    <a href="#" class="action-btn view" title="View">
                        <i class="bi bi-eye"></i>
                    </a>

                    <a href="#" class="action-btn print" title="Print">
                        <i class="bi bi-printer"></i>
                    </a>

                    <a href="#" class="action-btn download" title="Download">
                        <i class="bi bi-download"></i>
                    </a>

                    </td>

                </tr>

                </tbody>

            </table>

        </div>

  

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>
</body>
</html>