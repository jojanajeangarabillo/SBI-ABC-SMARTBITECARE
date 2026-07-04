<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Notifications</title>
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

        /* ---- notification list ---- */
        .notif-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 8px 0;
            overflow: hidden;
        }
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 18px 28px;
            border-bottom: 1px solid #edf1f8;
            transition: 0.1s;
        }
        .notif-item:last-child {
            border-bottom: none;
        }
        .notif-item:hover {
            background: #f8faff;
        }
        .notif-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e7ecfc;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .notif-icon i {
            font-size: 20px;
            color: var(--primary);
        }
        .notif-icon.alert-icon {
            background: #fde8e8;
        }
        .notif-icon.alert-icon i {
            color: var(--accent);
        }
        .notif-icon.success-icon {
            background: #e0f5e0;
        }
        .notif-icon.success-icon i {
            color: #1a8a1a;
        }
        .notif-icon.warning-icon {
            background: #fef3d5;
        }
        .notif-icon.warning-icon i {
            color: #b8860b;
        }
        .notif-content {
            flex: 1;
        }
        .notif-content .notif-title {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
            margin-bottom: 2px;
        }
        .notif-content .notif-desc {
            color: #2a3a5a;
            font-size: 15px;
            font-weight: 500;
        }
        .notif-content .notif-time {
            color: #6a7a9a;
            font-size: 13px;
            font-weight: 500;
            margin-top: 2px;
        }
        .notif-content .notif-desc + .notif-time {
            margin-top: 4px;
        }

        /* ---- view all button ---- */
        .view-all-wrap {
            padding: 20px 28px 24px;
            display: flex;
            justify-content: flex-end;
        }
        .btn-view-all {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-view-all:hover {
            background: #1d2863;
            color: #fff;
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
            .notif-item {
                padding: 14px 16px;
                gap: 12px;
            }
            .notif-icon {
                width: 34px;
                height: 34px;
            }
            .notif-icon i {
                font-size: 16px;
            }
            .notif-content .notif-title {
                font-size: 14px;
            }
            .notif-content .notif-desc {
                font-size: 13px;
            }
            .view-all-wrap {
                padding: 14px 16px 18px;
                justify-content: center;
            }
            .btn-view-all {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Nurse) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a class="active" href="Nurse_Notification.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
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
        <h3>Notifications</h3>
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Notification List -->
        <div class="notif-card">

            <!-- Follow-Up Due -->
            <div class="notif-item">
                <div class="notif-icon warning-icon">
                    <i class="bi bi-clock"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Follow-Up Due</div>
                    <div class="notif-desc">Imelda Castor is due for follow-up consultation.</div>
                    <div class="notif-time">Today, 08:00 AM</div>
                </div>
            </div>

            <!-- Follow-Up Due -->
            <div class="notif-item">
                <div class="notif-icon warning-icon">
                    <i class="bi bi-clock"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Follow-Up Due</div>
                    <div class="notif-desc">Ariana Garden is due for wound care follow-up.</div>
                    <div class="notif-time">Today, 09:00 AM</div>
                </div>
            </div>

            <!-- Follow-Up Due -->
            <div class="notif-item">
                <div class="notif-icon warning-icon">
                    <i class="bi bi-clock"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Follow-Up Due</div>
                    <div class="notif-desc">Mimi Dominico is due for vaccination follow-up.</div>
                    <div class="notif-time">Tomorrow, 10:00 AM</div>
                </div>
            </div>

            <!-- Low Stock Alert -->
            <div class="notif-item">
                <div class="notif-icon alert-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Low Stock Alert</div>
                    <div class="notif-desc">Syringes (2ml) are running low. Only 15 units remaining.</div>
                    <div class="notif-time">May 15, 2025 08:45 AM</div>
                </div>
            </div>

            <!-- Low Stock Alert -->
            <div class="notif-item">
                <div class="notif-icon alert-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Low Stock Alert</div>
                    <div class="notif-desc">SPEEDA vaccine stock is critically low. Only 8 vials remaining.</div>
                    <div class="notif-time">May 15, 2025 07:30 AM</div>
                </div>
            </div>

            <!-- Expiring Vaccine Alert -->
            <div class="notif-item">
                <div class="notif-icon alert-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Expiring Vaccine Alert</div>
                    <div class="notif-desc">ERIG vaccine (5 vials) expires on May 20, 2026.</div>
                    <div class="notif-time">May 14, 2025 04:15 PM</div>
                </div>
            </div>

            <!-- Expiring Vaccine Alert -->
            <div class="notif-item">
                <div class="notif-icon alert-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">Expiring Vaccine Alert</div>
                    <div class="notif-desc">BETT vaccine (3 vials) expires on May 20, 2026.</div>
                    <div class="notif-time">May 14, 2025 04:10 PM</div>
                </div>
            </div>

            <!-- View All Button -->
            <div class="view-all-wrap">
                <button class="btn-view-all">
                    <i class="bi bi-eye"></i> View All Notifications
                </button>
            </div>

        </div> <!-- /notif-card -->

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>