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
            MAIN CONTENT STYLES 
           ========================================= */
        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

        .page-header {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 24px;
        }


        .btn-add-user {
            background: var(--primary);
            border: none;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 15px;
            border-radius: 8px;
            color: #fff;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-add-user:hover {
            background: #1f2d6e;
            color: #fff;
        }

        .btn-add-user i {
            font-size: 18px;
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

        /* status badges */
        .badge-status {
            font-weight: 600;
            font-size: 12px;
            padding: 5px 14px;
            border-radius: 20px;
            letter-spacing: 0.2px;
        }

        .badge-active {
            background: #dff0e6;
            color: #0f7b3a;
        }

        .badge-inactive {
            background: #f1f2f6;
            color: #6b7280;
        }

        /* action icon */
        .action-icon {
            color: var(--primary);
            font-size: 20px;
            opacity: 0.7;
            transition: opacity 0.2s, transform 0.15s;
            cursor: default;
            display: inline-block;
        }

        .action-icon:hover {
            opacity: 1;
            transform: scale(1.1);
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

        /* quick actions card */
        .quick-actions-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9edf4;
            padding: 22px 28px;
            margin-top: 28px;
        }

        .quick-actions-card .qa-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
            margin: 0 0 14px 0;
            letter-spacing: 0.2px;
        }

        .quick-actions-card .qa-title i {
            margin-right: 8px;
            font-size: 18px;
        }

        .qa-btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .qa-btn-group .btn-qa {
            background: #F21D2F;
            border: none;
            padding: 9px 22px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            color: white;
            transition: background 0.2s, transform 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .qa-btn-group .btn-qa i {
            font-size: 16px;
            color: white;
        }

        .qa-btn-group .btn-qa:hover {
            background:#2B3A8C;
            transform: translateY(-1px);
        }

        .qa-btn-group .btn-qa.btn-qa-primary {
            background: var(--primary);
            color: #fff;
        }

        .qa-btn-group .btn-qa.btn-qa-primary i {
            color: #fff;
        }

        .qa-btn-group .btn-qa.btn-qa-primary:hover {
            background: #1f2d6e;
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

        /* topbar profile dropdown tweak */
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

                <li><a class="active" href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>

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

    <!-- MAIN CONTENT   -->

    <div class="main">

        <!-- Top Header-->


        <div class="topbar">
            <h3>Users</h3>
            <div class="profile"> ADMIN <i class="bi bi-caret-down-fill"></i> </div>
        </div>

        <div class="content-wrapper">

            <!-- Add User button row -->
            <div class="page-header">
                <button class="btn btn-add-user">
                    <i class="bi bi-person-plus"></i> Add User
                </button>
            </div>

            <!-- Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>John Doe</td>
                                <td>Admin Staff</td>
                                <td><span class="badge-status badge-active">Active</span></td>
                                <td>
                                    <i class="bi bi-pencil-square action-icon"></i>
                                    <i class="bi bi-trash3-fill action-icon"></i>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Jane Smith</td>
                                <td>Nurse</td>
                                <td><span class="badge-status badge-inactive">Inactive</span></td>
                                <td>
                                    <i class="bi bi-pencil-square action-icon"></i>
                                    <i class="bi bi-trash3-fill action-icon"></i>
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

            <!-- Quick Actions -->
            <div class="quick-actions-card">
                <div class="qa-title">
                    <i class="bi bi-lightning-fill"></i> Quick Actions
                </div>
                <div class="qa-btn-group">
                    <button class="btn-qa"><i class="bi bi-person-plus"></i> Add Nurse</button>
                    <button class="btn-qa"><i class="bi bi-person-plus"></i> Add Admin Staff</button>
                    <button class="btn-qa"><i class="bi bi-person-plus"></i> Add Inventory Officer</button>
                    <button class="btn-qa"><i class="bi bi-key"></i> Reset Password</button>
                </div>
            </div>

        </div>

    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>