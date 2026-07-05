<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Supply Prediction</title>
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

        /* ---- prediction period ---- */
        .period-badge {
            display: inline-block;
            background: var(--card-bg);
            color: var(--primary);
            font-weight: 600;
            padding: 8px 24px;
            border-radius: 40px;
            font-size: 15px;
            margin-bottom: 24px;
        }
        .period-badge i {
            margin-right: 8px;
        }

        /* ---- table ---- */
        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0 6px 0;
            margin-bottom: 20px;
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
        .prob-high {
            color: var(--accent);
            font-weight: 700;
        }
        .prob-high-bg {
            background: #fde8e8;
            padding: 2px 12px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            color: var(--accent);
        }
        .reorder-qty {
            font-weight: 600;
            color: var(--primary);
        }

        /* ---- note ---- */
        .note-box {
            background: #f0f3fc;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 28px;
            color: #2a3a5a;
            font-size: 14px;
            border-left: 4px solid var(--primary);
        }
        .note-box i {
            color: var(--primary);
            margin-right: 8px;
        }
        .note-box strong {
            color: var(--primary);
        }

        /* ---- chart card ---- */
        .chart-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 24px 28px 28px;
            margin-bottom: 20px;
        }
        .chart-card .chart-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 18px;
        }

        /* ---- bar chart ---- */
        .bar-chart-wrapper {
            display: flex;
            height: 200px;
            align-items: flex-end;
            gap: 16px;
            padding: 0 4px 8px 4px;
            border-bottom: 2px solid #d7def0;
            margin-bottom: 12px;
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
            width: 55%;
            max-width: 48px;
            min-height: 6px;
            border-radius: 6px 6px 0 0;
            transition: 0.2s;
            position: relative;
        }
        .bar.bar-primary {
            background: var(--primary);
        }
        .bar.bar-accent {
            background: var(--accent);
        }
        .bar.bar-success {
            background: #28a745;
        }
        .bar-label {
            margin-top: 8px;
            font-weight: 600;
            font-size: 12px;
            color: #1f2a4a;
            text-align: center;
            line-height: 1.2;
        }
        .bar-value {
            font-weight: 700;
            font-size: 13px;
            color: var(--primary);
            margin-bottom: 4px;
        }

        /* ---- legend ---- */
        .legend-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px 32px;
            padding-top: 14px;
            border-top: 1px solid #edf1f8;
            margin-top: 6px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
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
            font-size: 14px;
        }

        /* ---- shortage levels ---- */
        .shortage-levels {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 30px;
            margin: 16px 0 20px 0;
        }
        .shortage-levels .level-item {
            font-weight: 500;
            color: #2a3a5a;
            font-size: 14px;
        }
        .shortage-levels .level-item .level-badge {
            display: inline-block;
            padding: 2px 14px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            margin-left: 6px;
        }
        .level-badge.high {
            background: #fde8e8;
            color: var(--accent);
        }

        /* ---- view report button ---- */
        .btn-report {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 32px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-report:hover {
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
            .table-wrap {
                overflow-x: auto;
            }
            .table thead th,
            .table tbody td {
                padding: 12px 14px;
                font-size: 13px;
            }
            .bar-chart-wrapper {
                height: 150px;
                gap: 10px;
            }
            .bar {
                width: 50%;
            }
            .chart-card {
                padding: 16px;
            }
            .legend-row {
                gap: 10px 18px;
            }
            .shortage-levels {
                flex-direction: column;
                gap: 6px;
            }
            .btn-report {
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
            <li><a class="active" href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
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
        <h3>Prediction Viewer</h3>
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Prediction Period -->
        <div class="period-badge">
            <i class="bi bi-calendar-range"></i> Prediction Period: Next 30 Days
        </div>

        <!-- Prediction Summary Table -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Probability</th>
                        <th>Predicted Shortage</th>
                        <th>Recommended Reorder</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>SPEEDA</strong></td>
                        <td><span class="prob-high">82%</span></td>
                        <td><span class="prob-high-bg">High</span></td>
                        <td><span class="reorder-qty">30 Vials</span></td>
                    </tr>
                    <tr>
                        <td><strong>SPEEDA</strong></td>
                        <td><span class="prob-high">82%</span></td>
                        <td><span class="prob-high-bg">High</span></td>
                        <td><span class="reorder-qty">50 Vials</span></td>
                    </tr>
                    <tr>
                        <td><strong>SPEEDA</strong></td>
                        <td><span class="prob-high">82%</span></td>
                        <td><span class="prob-high-bg">High</span></td>
                        <td><span class="reorder-qty">*</span></td>
                    </tr>
                    <tr>
                        <td><strong>SPEEDA</strong></td>
                        <td><span class="prob-high">82%</span></td>
                        <td><span class="prob-high-bg">High</span></td>
                        <td><span class="reorder-qty">*</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Note -->
        <div class="note-box">
            <i class="bi bi-info-circle"></i>
            <strong>Note:</strong> Nurses can only view prediction results. Prediction generation is managed by Branch Admin.
        </div>

        <!-- Shortage Probability Chart -->
        <div class="chart-card">
            <div class="chart-title">Shortage Probability (%)</div>

            <!-- Bar Chart -->
            <div class="bar-chart-wrapper">
                <!-- SPEEDA: 82% -->
                <div class="bar-container">
                    <div class="bar-value">82%</div>
                    <div class="bar bar-primary" style="height: 82%;"></div>
                    <div class="bar-label">SPEEDA</div>
                </div>
                <!-- ERIG: 75% -->
                <div class="bar-container">
                    <div class="bar-value">75%</div>
                    <div class="bar bar-accent" style="height: 75%;"></div>
                    <div class="bar-label">ERIG</div>
                </div>
                <!-- ATS: 70% -->
                <div class="bar-container">
                    <div class="bar-value">70%</div>
                    <div class="bar bar-primary" style="height: 70%;"></div>
                    <div class="bar-label">ATS</div>
                </div>
                <!-- SYRINGES: 45% -->
                <div class="bar-container">
                    <div class="bar-value">45%</div>
                    <div class="bar bar-success" style="height: 45%;"></div>
                    <div class="bar-label">SYRINGES</div>
                </div>
                <!-- BETT: 30% -->
                <div class="bar-container">
                    <div class="bar-value">30%</div>
                    <div class="bar bar-success" style="height: 30%;"></div>
                    <div class="bar-label">BETT</div>
                </div>
            </div>

            <!-- Legend -->
            <div class="legend-row">
                <div class="legend-item">
                    <span class="legend-dot" style="background: var(--primary);"></span>
                    <span class="legend-label">Shortage Prediction</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: var(--accent);"></span>
                    <span class="legend-label">Usage Trends</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #28a745;"></span>
                    <span class="legend-label">Patient Trends</span>
                </div>
            </div>
        </div>

        <!-- Predicted Shortage Level -->
        <div class="shortage-levels">
            <div class="level-item">
                Predicted Shortage Level
                <span class="level-badge high">High (≥ 70%)</span>
            </div>
            <div class="level-item">
                <span class="level-badge high">High (≥ 70%)</span>
            </div>
            <div class="level-item">
                <span class="level-badge high">High (≥ 70%)</span>
            </div>
        </div>

        <!-- View Full Report Button -->
        <button class="btn-report"><i class="bi bi-file-earmark-text me-2"></i> View Full Prediction Report</button>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>