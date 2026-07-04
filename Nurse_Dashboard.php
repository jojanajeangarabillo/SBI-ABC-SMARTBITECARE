<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse Dashboard</title>
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

        /* ---- stat cards ---- */
        .stat-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 20px 22px;
            height: 120px;
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
            margin-top: 4px;
            font-size: 44px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
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

        /* ---- schedule table ---- */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }
        .schedule-table td {
            padding: 10px 4px;
            border-bottom: 1px solid #d7def0;
            vertical-align: top;
        }
        .schedule-table tr:last-child td {
            border-bottom: none;
        }
        .schedule-table .time-col {
            font-weight: 600;
            color: var(--primary);
            white-space: nowrap;
            width: 90px;
        }
        .schedule-table .activity-col {
            font-weight: 500;
            color: #1f2a4a;
        }
        .schedule-table .activity-col .sub-activity {
            font-weight: 400;
            color: #5a6a8a;
            font-size: 14px;
            display: block;
            margin-top: 1px;
        }

        /* ---- follow-up due ---- */
        .followup-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #d7def0;
        }
        .followup-item:last-child {
            border-bottom: none;
        }
        .followup-item .followup-date {
            font-weight: 600;
            color: var(--primary);
            white-space: nowrap;
            min-width: 100px;
            font-size: 15px;
        }
        .followup-item .followup-name {
            font-weight: 500;
            color: #1f2a4a;
            font-size: 15px;
        }

        /* ---- view all button ---- */
        .btn-view {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 8px 28px;
            font-weight: 600;
            transition: 0.15s;
            font-size: 14px;
        }
        .btn-view:hover {
            background: #1d2863;
            color: #fff;
        }
        .text-end.mt-auto {
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
            .stat-number {
                font-size: 34px;
            }
            .stat-card {
                height: 100px;
                padding: 16px;
            }
            .schedule-table .time-col {
                width: 70px;
                font-size: 14px;
            }
            .followup-item {
                flex-wrap: wrap;
                gap: 4px;
            }
            .followup-item .followup-date {
                min-width: auto;
                font-size: 14px;
            }
            .large-card {
                padding: 16px;
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
            <li><a class="active" href="#"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
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
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- STAT ROW: 3 cards -->
        <div class="row g-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Patient Waiting</div>
                    <div class="stat-number">8</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Ongoing Cases</div>
                    <div class="stat-number">16</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Vaccinations Today</div>
                    <div class="stat-number">22</div>
                </div>
            </div>
        </div>

        <!-- SCHEDULE & FOLLOW-UP ROW -->
        <div class="row g-4 mt-2">

            <!-- Today's Schedule -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">Today's Schedule</div>

                    <table class="schedule-table">
                        <tr>
                            <td class="time-col">08:00 AM</td>
                            <td class="activity-col">
                                Imelda Castor
                                <span class="sub-activity">Consultation</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="time-col">09:00 AM</td>
                            <td class="activity-col">
                                Ariana Garden
                                <span class="sub-activity">Wound Care</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="time-col">10:00 AM</td>
                            <td class="activity-col">
                                Mimi Dominico
                                <span class="sub-activity">Vaccination</span>
                            </td>
                        </tr>
                    </table>

                    <div class="text-end mt-auto">
                        <button class="btn-view">View All Schedule</button>
                    </div>
                </div>
            </div>

            <!-- Follow-up Due -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">Follow-up Due</div>

                    <div class="followup-item">
                        <span class="followup-date">May 15, 2026</span>
                        <span class="followup-name">Imelda Castor</span>
                    </div>
                    <div class="followup-item">
                        <span class="followup-date">May 15, 2026</span>
                        <span class="followup-name">Ariana Garden</span>
                    </div>
                    <div class="followup-item">
                        <span class="followup-date">May 16, 2026</span>
                        <span class="followup-name">Mimi Dominico</span>
                    </div>

                    <div class="text-end mt-auto">
                        <button class="btn-view">View All</button>
                    </div>
                </div>
            </div>

        </div> <!-- /row -->

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>