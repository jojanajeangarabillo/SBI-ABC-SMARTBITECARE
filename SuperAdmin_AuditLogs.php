<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Audit Logs</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS – matches image style
           ========================================= */
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: white;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* ---- main content ---- */
        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f9faff;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-bottom: 1px solid #e9edf5;
        }
        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
            letter-spacing: -0.3px;
        }
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .content {
            padding: 35px 35px 40px;
        }

        /* ---- page header ---- */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .page-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .page-header .badge-role {
            background: var(--primary);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 30px;
            letter-spacing: 0.3px;
            margin-left: 12px;
        }

        /* ---- search ---- */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            margin-bottom: 24px;
        }
        .search-wrap {
            position: relative;
            flex: 1 1 320px;
        }
        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7a85a8;
            font-size: 18px;
        }
        .search-wrap input {
            width: 100%;
            padding: 12px 12px 12px 44px;
            border: 1px solid #d0d7e8;
            border-radius: 40px;
            font-size: 15px;
            background: white;
            outline: none;
            transition: 0.15s;
        }
        .search-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }

        /* ---- table ---- */
        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0 6px 0;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: #f0f3fc;
            color: var(--primary);
            font-weight: 700;
            font-size: 15px;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e7f2;
            letter-spacing: 0.3px;
        }
        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #edf1f8;
            color: #1f2a4a;
            font-weight: 500;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr:hover {
            background: #f8faff;
        }
        .user-tag {
            font-weight: 600;
            color: var(--primary);
        }
        .module-tag {
            display: inline-block;
            background: #e7ecfc;
            color: var(--primary);
            font-weight: 600;
            font-size: 13px;
            padding: 2px 14px;
            border-radius: 40px;
        }

        /* ---- pagination ---- */
        .pagination-wrap {
            display: flex;
            justify-content: flex-end;
            padding-top: 24px;
            align-items: center;
            gap: 6px;
        }
        .pagination-wrap .page-item .page-link {
            color: var(--primary);
            border: 1px solid #d7def0;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            background: white;
            margin: 0 2px;
            transition: 0.1s;
        }
        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .pagination-wrap .page-item .page-link:hover {
            background: #e7ecfc;
            border-color: var(--primary);
        }
        .pagination-wrap .page-item.disabled .page-link {
            color: #b0bcd6;
            background: #f5f7fc;
        }
        .pagination-wrap .ellipsis {
            padding: 8px 8px;
            font-weight: 600;
            color: #4a5a8c;
        }

        /* responsive */
        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
            .sidebar {
                width: 90px;
                padding: 16px 10px;
            }
            .system-name,
            .nav-menu span,
            .logout span {
                display: none;
            }
            .logo-area {
                justify-content: center;
            }
            .nav-menu a {
                justify-content: center;
                padding: 12px 8px;
            }
            .nav-menu a i {
                font-size: 26px;
                margin: 0;
            }
            .logout a {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .table thead th,
            .table tbody td {
                padding: 12px 14px;
                font-size: 14px;
            }
            .pagination-wrap {
                justify-content: center;
            }
            .pagination-wrap .page-item .page-link {
                padding: 6px 12px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Super Admin) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="SuperAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a class="active" href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="landing.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Audit Logs</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Search -->
        <div class="toolbar">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" placeholder="Search logs..." />
            </div>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>May 15, 2025 10:33 AM</td>
                        <td><span class="user-tag">juan.admin</span></td>
                        <td><span class="module-tag">Branch Management</span></td>
                        <td>Added Sta. Clara Branch</td>
                    </tr>
                    <tr>
                        <td>May 15, 2025 10:15 AM</td>
                        <td><span class="user-tag">maria.admin</span></td>
                        <td><span class="module-tag">User Monitoring</span></td>
                        <td>Deactivated user U004</td>
                    </tr>
                    <tr>
                        <td>May 15, 2025 10:54 AM</td>
                        <td><span class="user-tag">pedro.admin</span></td>
                        <td><span class="module-tag">Reports</span></td>
                        <td>Generated Patient Report</td>
                    </tr>
                    <tr>
                        <td>May 15, 2025 11:01 AM</td>
                        <td><span class="user-tag">ana.admin</span></td>
                        <td><span class="module-tag">Branch Management</span></td>
                        <td>Updated Cabantian Branch</td>
                    </tr>
                    <tr>
                        <td>May 15, 2025 02:20 PM</td>
                        <td><span class="user-tag">juan.admin</span></td>
                        <td><span class="module-tag">Branch Admin Management</span></td>
                        <td>Reset password for BA002</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination: 1 2 3 4 5 ... 50 -->
        <div class="pagination-wrap">
            <nav aria-label="Audit logs pagination">
                <ul class="pagination">
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item"><a class="page-link" href="#">4</a></li>
                    <li class="page-item"><a class="page-link" href="#">5</a></li>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <li class="page-item"><a class="page-link" href="#">50</a></li>
                </ul>
            </nav>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>