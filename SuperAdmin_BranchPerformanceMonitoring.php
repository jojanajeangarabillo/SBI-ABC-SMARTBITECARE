<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Branch Performance Monitoring</title>
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

        /* ---- filter row ---- */
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 24px 40px;
            background: white;
            padding: 18px 24px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 28px;
            align-items: center;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-group label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            letter-spacing: 0.2px;
            margin: 0;
        }
        .filter-group .filter-value {
            background: #f0f3fc;
            padding: 6px 18px;
            border-radius: 30px;
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: default;
        }
        .filter-group .filter-value i {
            font-size: 14px;
        }

        /* ---- chart card ---- */
        .chart-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 28px 30px 30px;
            margin-bottom: 28px;
        }
        .chart-card .chart-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }

        /* ---- bar chart ---- */
        .bar-chart-wrapper {
            display: flex;
            height: 280px;
            align-items: flex-end;
            gap: 18px;
            padding: 0 8px;
            border-bottom: 2px solid #d7def0;
            margin-bottom: 8px;
            position: relative;
        }
        .bar-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            justify-content: flex-end;
        }
        .bar {
            width: 60%;
            max-width: 56px;
            min-height: 8px;
            background: var(--primary);
            border-radius: 6px 6px 0 0;
            transition: 0.2s;
            position: relative;
        }
        .bar-label {
            margin-top: 10px;
            font-weight: 600;
            font-size: 13px;
            color: #1f2a4a;
            text-align: center;
            line-height: 1.2;
        }
        .bar-value {
            font-weight: 700;
            font-size: 14px;
            color: var(--primary);
            margin-bottom: 6px;
        }

        /* y-axis labels (static) */
        .chart-area {
            position: relative;
        }
        .y-axis-labels {
            position: absolute;
            left: -36px;
            top: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 4px 0;
            font-weight: 600;
            font-size: 13px;
            color: #5a6a8c;
        }
        .y-axis-labels span {
            display: block;
        }

        .chart-wrap {
            position: relative;
            padding-left: 40px;
        }

        /* ---- legend (metrics) ---- */
        .legend-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 20px 48px;
            padding-top: 20px;
            border-top: 1px solid #edf1f8;
            margin-top: 8px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .legend-item .legend-label {
            font-weight: 500;
            color: #3a4a6a;
            font-size: 15px;
        }
        .legend-item .legend-value {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
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
            .filter-row {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .filter-group {
                flex-wrap: wrap;
            }
            .bar-chart-wrapper {
                height: 200px;
                gap: 10px;
            }
            .bar {
                width: 50%;
            }
            .legend-metrics {
                gap: 12px 24px;
            }
            .chart-card {
                padding: 16px;
            }
            .y-axis-labels {
                font-size: 11px;
                left: -28px;
            }
            .chart-wrap {
                padding-left: 28px;
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
            <li><a class="active" href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Branch Performance Monitoring</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Filter row: Select Metric | Date Range -->
        <div class="filter-row">
            <div class="filter-group">
                <label>Select Metric</label>
                <span class="filter-value"><i class="bi bi-bar-chart-fill"></i> Total Cases</span>
            </div>
            <div class="filter-group">
                <label>Date Range</label>
                <span class="filter-value"><i class="bi bi-calendar3"></i> This Month</span>
            </div>
        </div>

        <!-- Chart Card -->
        <div class="chart-card">
            <div class="chart-title">Total Cases by Branch</div>

            <!-- Chart area with y-axis labels -->
            <div class="chart-wrap">
                <!-- Y-axis labels -->
                <div class="y-axis-labels">
                    <span>800</span>
                    <span>600</span>
                    <span>400</span>
                    <span>200</span>
                    <span>0</span>
                </div>

                <!-- Bar chart -->
                <div class="bar-chart-wrapper">
                    <!-- Sta. Clara: 750 -->
                    <div class="bar-container">
                        <div class="bar-value">750</div>
                        <div class="bar" style="height: 94%;"></div>
                        <div class="bar-label">Sta. Clara</div>
                    </div>
                    <!-- San Isidro: 550 -->
                    <div class="bar-container">
                        <div class="bar-value">550</div>
                        <div class="bar" style="height: 69%;"></div>
                        <div class="bar-label">San Isidro</div>
                    </div>
                    <!-- Cabantian: 650 -->
                    <div class="bar-container">
                        <div class="bar-value">650</div>
                        <div class="bar" style="height: 81%;"></div>
                        <div class="bar-label">Cabantian</div>
                    </div>
                    <!-- Poblacion: 550 -->
                    <div class="bar-container">
                        <div class="bar-value">550</div>
                        <div class="bar" style="height: 69%;"></div>
                        <div class="bar-label">Poblacion</div>
                    </div>
                    <!-- Tugbok: 420 -->
                    <div class="bar-container">
                        <div class="bar-value">420</div>
                        <div class="bar" style="height: 52%;"></div>
                        <div class="bar-label">Tugbok</div>
                    </div>
                </div>
            </div>

            <!-- Legend / Metrics -->
            <div class="legend-metrics">
                <div class="legend-item">
                    <span class="legend-dot" style="background: #2B3A8C;"></span>
                    <span class="legend-label">Total Patients:</span>
                    <span class="legend-value">2,458</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #F21D2F;"></span>
                    <span class="legend-label">Total Cases:</span>
                    <span class="legend-value">1,357</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #28a745;"></span>
                    <span class="legend-label">Total Vaccinations:</span>
                    <span class="legend-value">8,946</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #fd7e14;"></span>
                    <span class="legend-label">Inventory Utilization:</span>
                    <span class="legend-value">78%</span>
                </div>
            </div>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>