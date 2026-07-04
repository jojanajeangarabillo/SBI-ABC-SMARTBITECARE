<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin Dashboard</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS (matches image design)
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

        .dashboard {
            padding: 35px 35px 40px;
        }

        /* ---- stat cards ---- */
        .stat-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 20px 22px;
            height: 140px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 17px;
            letter-spacing: 0.2px;
        }
        .stat-number {
            margin-top: 8px;
            font-size: 44px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
        }
        .stat-sub {
            font-size: 14px;
            font-weight: 400;
            color: #4a5a8c;
            margin-top: 2px;
        }

        /* ---- large cards ---- */
        .large-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 22px 24px;
            margin-top: 25px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
        }
        .section-title small {
            font-weight: 400;
            font-size: 15px;
            color: #6c7a9e;
        }

        .placeholder-box {
            height: 200px;
            background: #d6def0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 500;
            color: #4a5a8c;
            border-radius: 14px;
            letter-spacing: 0.5px;
        }

        .btn-custom {
            background: var(--primary);
            color: white;
            border-radius: 8px;
            padding: 8px 22px;
            border: none;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-custom:hover {
            background: #1d2863;
            color: #fff;
        }

        /* activity & alert items */
        .activity,
        .alert-item {
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 15px;
            color: #1f2a4a;
        }
        .activity i {
            color: var(--accent);
            font-size: 14px;
            margin-top: 4px;
            flex-shrink: 0;
        }
        .alert-item i {
            color: var(--accent);
            font-size: 18px;
            margin-top: 2px;
        }
        .activity small,
        .alert-item small {
            color: #4a5a8c;
            font-size: 13px;
            display: block;
        }
        .text-end.mt-4 {
            margin-top: auto;
            padding-top: 14px;
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

        /* extra small tweaks */
        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .dashboard {
                padding: 20px 16px;
            }
            .stat-number {
                font-size: 34px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Super Admin) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <!-- logo placeholder (image from image.png concept) -->
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a class="active" href="#"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="#"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Dashboard</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- DASHBOARD CONTENT – EXACTLY AS IN IMAGE -->
    <div class="dashboard">

        <!-- FIRST ROW: 6 stat cards (3+3) -->
        <div class="row g-4">

            <!-- Total Branches -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title">Total Branches</div>
                    <div class="stat-number">25</div>
                    <div class="stat-sub">Active Branches</div>
                </div>
            </div>

            <!-- Total Patients -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title">Total Patients</div>
                    <div class="stat-number">2,458</div>
                    <div class="stat-sub">All Time Total</div>
                </div>
            </div>

            <!-- Total Users -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title">Total Users</div>
                    <div class="stat-number">186</div>
                    <div class="stat-sub">System Users</div>
                </div>
            </div>

            <!-- Total Cases -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title">Total Cases</div>
                    <div class="stat-number">1,357</div>
                    <div class="stat-sub">All Time Total</div>
                </div>
            </div>

            <!-- Total Vaccinations -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title">Total Vaccinations</div>
                    <div class="stat-number">8,946</div>
                    <div class="stat-sub">All Time Total</div>
                </div>
            </div>

            <!-- Total Inventory Items -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title">Total Inventory Items</div>
                    <div class="stat-number">532</div>
                    <div class="stat-sub">All Items</div>
                </div>
            </div>
        </div>

        <!-- SECOND ROW: Recent Activities & System Alerts (2 large cards) -->
        <div class="row g-4 mt-2">

            <!-- Recent System Activities -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">Recent System Activities</div>

                    <div class="activity">
                        <i class="bi bi-square-fill"></i>
                        <div>
                            <strong>New Branch Added</strong><br />
                            Sta. Clars Branch was added successfully.<br />
                            <small>May 15, 2025 10:35 AM</small>
                        </div>
                    </div>

                    <div class="activity">
                        <i class="bi bi-square-fill"></i>
                        <div>
                            <strong>New Admin Account Created</strong><br />
                            Juan Oda Cruz was added as Branch Admin.<br />
                            <small>May 15, 2025 10:20 AM</small>
                        </div>
                    </div>

                    <div class="activity">
                        <i class="bi bi-square-fill"></i>
                        <div>
                            <strong>User Role Updated</strong><br />
                            Maria Sotero's role was updated to Admin Staff.<br />
                            <small>May 14, 2025 08:45 AM</small>
                        </div>
                    </div>

                    <div class="activity">
                        <i class="bi bi-square-fill"></i>
                        <div>
                            <strong>Branch Deactivated</strong><br />
                            Poblacion Branch has been deactivated.<br />
                            <small>May 14, 2025 04:15 PM</small>
                        </div>
                    </div>

                    <div class="text-end mt-3">
                        <button class="btn btn-custom">View All</button>
                    </div>
                </div>
            </div>

            <!-- System Alerts -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">System Alerts</div>

                    <div class="alert-item">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <strong>High shortage probability for ERIG in Tugbok Branch.</strong><br />
                            <small>May 14, 2025 08:30 AM</small>
                        </div>
                    </div>

                    <div class="alert-item">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <strong>Low stock for ATS in San Isidro Branch.</strong><br />
                            <small>May 14, 2025 08:50 AM</small>
                        </div>
                    </div>

                    <div class="alert-item">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <strong>Expiring vaccines in Poblacion Branch.</strong><br />
                            <small>May 14, 2025 08:32 AM</small>
                        </div>
                    </div>

                    <div class="text-end mt-3">
                        <button class="btn btn-custom">View All</button>
                    </div>
                </div>
            </div>

        </div> <!-- /row -->

        <!-- (optional) extra placeholder row to match spacing – but we already have everything -->
    </div> <!-- /dashboard -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>