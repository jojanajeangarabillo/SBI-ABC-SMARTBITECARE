<?php
// No PHP logic needed for this static dashboard
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Staff Dashboard</title>

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


        @media (max-width: 991px) {

            .main {

                margin-left: 90px;

            }

        }


        /* Responsive */
        @media (max-width: 992px) {

            .stats-container {
                flex-direction: column;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .philhealth-content {
                flex-direction: column;
                gap: 20px;
            }

            .chart-area,
            .legend-area {
                width: 100%;
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

        /* =========================================
           DASHBOARD CONTENT STYLES
        ========================================= */
        .dashboard-content {
            padding: 30px 35px 50px;
            background: #f8f9fc;
        }

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-left: 5px solid var(--primary);
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .stat-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 14px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .stat-sub {
            font-size: 12px;
            color: #adb5bd;
            margin-top: 2px;
        }

        .stat-card.overdue {
            border-left-color: var(--accent);
        }
        .stat-card.overdue .stat-number {
            color: var(--accent);
        }

        .stat-card.completed {
            border-left-color: #28a745;
        }
        .stat-card.completed .stat-number {
            color: #28a745;
        }

        .stat-card.pending {
            border-left-color: #ffc107;
        }
        .stat-card.pending .stat-number {
            color: #e6a800;
        }

        /* Two-column layout: Calendar + Legend */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 24px;
            margin-bottom: 30px;
        }

        /* Calendar */
        .calendar-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .calendar-wrapper .cal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .calendar-wrapper .cal-header h5 {
            font-weight: 700;
            color: var(--primary);
            font-size: 18px;
            margin: 0;
        }

        .calendar-wrapper .cal-header .cal-nav {
            display: flex;
            gap: 8px;
        }

        .calendar-wrapper .cal-header .cal-nav button {
            background: none;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #495057;
            font-size: 14px;
            transition: all 0.2s;
        }

        .calendar-wrapper .cal-header .cal-nav button:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .calendar-wrapper table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .calendar-wrapper table th {
            text-align: center;
            font-weight: 600;
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 0;
        }

        .calendar-wrapper table td {
            text-align: center;
            padding: 6px 0;
            font-weight: 500;
            color: #212529;
            border-radius: 50%;
            cursor: default;
        }

        .calendar-wrapper table td .day-cell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            transition: all 0.2s;
            font-size: 14px;
        }

        .calendar-wrapper table td .day-cell.today {
            background: var(--primary);
            color: white;
            font-weight: 700;
        }

        .calendar-wrapper table td .day-cell.has-event {
            background: #eef2ff;
            color: var(--primary);
            font-weight: 600;
        }

        .calendar-wrapper table td .day-cell.has-event.today {
            background: var(--primary);
            color: white;
        }

        .calendar-wrapper table td .day-cell.other-month {
            color: #ced4da;
        }

        .calendar-wrapper .cal-footer {
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #6c757d;
            border-top: 1px solid #f1f3f5;
            padding-top: 12px;
        }

        .calendar-wrapper .cal-footer span i {
            margin-right: 4px;
        }

        /* Legend */
        .legend-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .legend-wrapper h5 {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
            margin-bottom: 16px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 0;
            font-size: 14px;
            color: #212529;
        }

        .legend-item .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .legend-item .dot.today-dot {
            background: var(--primary);
        }
        .legend-item .dot.event-dot {
            background: #eef2ff;
            border: 2px solid var(--primary);
        }
        .legend-item .dot.overdue-dot {
            background: var(--accent);
        }
        .legend-item .dot.pending-dot {
            background: #ffc107;
        }
        .legend-item .dot.completed-dot {
            background: #28a745;
        }

        .legend-divider {
            border: none;
            border-top: 1px solid #f1f3f5;
            margin: 10px 0 12px;
        }

        /* Filter Tabs + Table */
        .table-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }

        .table-wrapper .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .table-wrapper .table-header .filter-tabs {
            display: flex;
            gap: 4px;
            background: #f1f3f5;
            border-radius: 10px;
            padding: 3px;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn {
            border: none;
            background: transparent;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.2s;
            cursor: pointer;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn:hover {
            color: var(--primary);
        }

        .table-wrapper .table-header .filter-tabs .tab-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count {
            display: inline-block;
            background: var(--primary);
            color: white;
            border-radius: 20px;
            padding: 0 8px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 4px;
            line-height: 18px;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.overdue-badge {
            background: var(--accent);
        }
        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.completed-badge {
            background: #28a745;
        }
        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.pending-badge {
            background: #ffc107;
        }

        .table-wrapper .table-responsive {
            overflow-x: auto;
        }

        .table-wrapper table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table-wrapper table thead th {
            background: #f8f9fc;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .table-wrapper table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f3f5;
            vertical-align: middle;
            color: #212529;
        }

        .table-wrapper table tbody tr:hover {
            background: #f8f9fc;
        }

        .table-wrapper table tbody td .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-badge.pending-badge {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.completed-badge {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.overdue-badge {
            background: #f8d7da;
            color: #721c24;
        }

        .table-wrapper table tbody td .btn-view {
            background: var(--primary);
            color: white;
            border: none;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            cursor: pointer;
        }

        .table-wrapper table tbody td .btn-view:hover {
            background: #1f2d6b;
            transform: scale(1.02);
        }

        /* Pagination + Tip + Export */
        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f1f3f5;
        }

        .table-footer .pagination-info {
            font-size: 14px;
            color: #6c757d;
        }

        .table-footer .pagination-info strong {
            color: #212529;
        }

        .table-footer .pagination-controls {
            display: flex;
            gap: 4px;
        }

        .table-footer .pagination-controls button {
            border: 1px solid #e9ecef;
            background: white;
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 13px;
            color: #495057;
            transition: all 0.2s;
            cursor: pointer;
        }

        .table-footer .pagination-controls button:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .table-footer .pagination-controls button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Tip Box */
        .tip-box {
            background: #eef2ff;
            border-radius: 12px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }

        .tip-box i {
            font-size: 20px;
            color: var(--primary);
        }

        .tip-box p {
            margin: 0;
            font-size: 14px;
            color: #1f2d6b;
            font-weight: 500;
        }

        .tip-box p span {
            font-weight: 700;
        }

        /* Export button */
        .export-btn {
            background: white;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 8px 24px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn:hover {
            background: var(--primary);
            color: white;
        }

        /* responsive */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 20px 16px 40px;
            }
            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .stat-card {
                padding: 16px;
            }
            .stat-card .stat-number {
                font-size: 28px;
            }
            .table-wrapper .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            .table-wrapper .table-header .filter-tabs {
                flex-wrap: wrap;
            }
            .table-footer {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .table-footer .pagination-controls {
                justify-content: center;
            }
            .topbar {
                padding: 0 16px;
                height: 64px;
            }
            .topbar h3 {
                font-size: 20px;
            }
            .calendar-wrapper {
                padding: 16px;
            }
            .legend-wrapper {
                padding: 16px;
            }
            .table-wrapper {
                padding: 16px;
            }
            .tip-box {
                flex-direction: column;
                text-align: center;
                padding: 16px;
            }
            .export-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat-card .stat-number {
                font-size: 22px;
            }
            .stat-card .stat-label {
                font-size: 11px;
            }
            .calendar-wrapper table td .day-cell {
                width: 26px;
                height: 26px;
                font-size: 12px;
            }
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
                <li><a class="active" href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
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
            <h3>Dashboard</h3>

            <div class="profile">
                ADMIN STAFF
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <!-- ==========================================
        DASHBOARD CONTENT
        ========================================== -->
        <div class="dashboard-content">

            <!-- STATS ROW -->
            <div class="stats-row">

                <div class="stat-card">
                    <div class="stat-number">18</div>
                    <div class="stat-label">Follow-up Patients</div>
                    <div class="stat-sub">Scheduled for today</div>
                </div>

                <div class="stat-card overdue">
                    <div class="stat-number">2</div>
                    <div class="stat-label">Overdue</div>
                    <div class="stat-sub">Missed schedule</div>
                </div>

                <div class="stat-card completed">
                    <div class="stat-number">12</div>
                    <div class="stat-label">Completed</div>
                    <div class="stat-sub">Already done today</div>
                </div>

                <div class="stat-card pending">
                    <div class="stat-number">4</div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-sub">Yet to be administered</div>
                </div>

            </div>

            <!-- CALENDAR + LEGEND -->
            <div class="dashboard-grid">

                <!-- Calendar -->
                <div class="calendar-wrapper">
                    <div class="cal-header">
                        <h5>May 2025</h5>
                        <div class="cal-nav">
                            <button><i class="bi bi-chevron-left"></i></button>
                            <button><i class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>SUN</th>
                                <th>MON</th>
                                <th>TUE</th>
                                <th>WED</th>
                                <th>THU</th>
                                <th>FRI</th>
                                <th>SAT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Week 1: Apr 27 – May 3 -->
                            <tr>
                                <td><span class="day-cell other-month">27</span></td>
                                <td><span class="day-cell other-month">28</span></td>
                                <td><span class="day-cell other-month">29</span></td>
                                <td><span class="day-cell other-month">30</span></td>
                                <td><span class="day-cell other-month">1</span></td>
                                <td><span class="day-cell other-month">2</span></td>
                                <td><span class="day-cell other-month">3</span></td>
                            </tr>
                            <!-- Week 2: May 4 – May 10 -->
                            <tr>
                                <td><span class="day-cell">4</span></td>
                                <td><span class="day-cell">5</span></td>
                                <td><span class="day-cell">6</span></td>
                                <td><span class="day-cell">7</span></td>
                                <td><span class="day-cell">8</span></td>
                                <td><span class="day-cell">9</span></td>
                                <td><span class="day-cell has-event">10</span></td>
                            </tr>
                            <!-- Week 3: May 11 – May 17 -->
                            <tr>
                                <td><span class="day-cell">11</span></td>
                                <td><span class="day-cell">12</span></td>
                                <td><span class="day-cell">13</span></td>
                                <td><span class="day-cell has-event today">14</span></td>
                                <td><span class="day-cell has-event">15</span></td>
                                <td><span class="day-cell">16</span></td>
                                <td><span class="day-cell has-event">17</span></td>
                            </tr>
                            <!-- Week 4: May 18 – May 24 -->
                            <tr>
                                <td><span class="day-cell has-event">18</span></td>
                                <td><span class="day-cell">19</span></td>
                                <td><span class="day-cell">20</span></td>
                                <td><span class="day-cell">21</span></td>
                                <td><span class="day-cell">22</span></td>
                                <td><span class="day-cell">23</span></td>
                                <td><span class="day-cell has-event">24</span></td>
                            </tr>
                            <!-- Week 5: May 25 – May 31 -->
                            <tr>
                                <td><span class="day-cell has-event">25</span></td>
                                <td><span class="day-cell">26</span></td>
                                <td><span class="day-cell">27</span></td>
                                <td><span class="day-cell">28</span></td>
                                <td><span class="day-cell">29</span></td>
                                <td><span class="day-cell">30</span></td>
                                <td><span class="day-cell has-event">31</span></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="cal-footer">
                        <span><i class="bi bi-calendar-event"></i> 8 events this month</span>
                        <span><i class="bi bi-circle-fill" style="color:var(--primary);font-size:10px;"></i> Today</span>
                    </div>
                </div>

                <!-- Legend -->
                <div class="legend-wrapper">
                    <h5>Filter · Legend</h5>

                    <div class="legend-item">
                        <span class="dot today-dot"></span>
                        Today
                    </div>
                    <div class="legend-item">
                        <span class="dot event-dot"></span>
                        Has Follow-up
                    </div>
                    <hr class="legend-divider">
                    <div class="legend-item">
                        <span class="dot overdue-dot"></span>
                        Overdue (2)
                    </div>
                    <div class="legend-item">
                        <span class="dot pending-dot"></span>
                        Pending (4)
                    </div>
                    <div class="legend-item">
                        <span class="dot completed-dot"></span>
                        Completed (12)
                    </div>
                    <hr class="legend-divider">
                    <div style="font-size:13px;color:#6c757d;margin-top:4px;">
                        <i class="bi bi-info-circle"></i> Click "View" to update status
                    </div>
                </div>

            </div>

            <!-- TIP BOX -->
            <div class="tip-box">
                <i class="bi bi-lightbulb-fill"></i>
                <p><span>Tip:</span> Click "View" to open the patient record and mark the dose as completed after administration.</p>
            </div>

            <!-- PATIENT TABLE -->
            <div class="table-wrapper">

                <div class="table-header">
                    <div class="filter-tabs">
                        <button class="tab-btn active">All <span class="badge-count">18</span></button>
                        <button class="tab-btn">Pending <span class="badge-count pending-badge">4</span></button>
                        <button class="tab-btn">Completed <span class="badge-count completed-badge">12</span></button>
                        <button class="tab-btn">Overdue <span class="badge-count overdue-badge">2</span></button>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <span style="font-size:13px;color:#6c757d;">Follow-up Patients for May 14, 2025 (Wednesday)</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Case ID</th>
                                <th>Patient Name</th>
                                <th>Dose Due</th>
                                <th>Scheduled Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- 1 -->
                            <tr>
                                <td>1</td>
                                <td>26-0011</td>
                                <td>Dela Cruz, Juan 28 / M - Dog Bite</td>
                                <td>Day 7 (3rd Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge pending-badge">Pending</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 2 -->
                            <tr>
                                <td>2</td>
                                <td>26-0008</td>
                                <td>Dela Cruz, Juan 28 / F - Cat Bite</td>
                                <td>Day 14 (4th Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge pending-badge">Pending</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 3 -->
                            <tr>
                                <td>3</td>
                                <td>26-0009</td>
                                <td>Dela Cruz, Juan 15 / M - Dog Bite</td>
                                <td>Day 7 (3rd Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge pending-badge">Pending</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 4 -->
                            <tr>
                                <td>4</td>
                                <td>26-0010</td>
                                <td>Dela Cruz, Juan 25 / F - Dog Bite</td>
                                <td>Day 14 (4th Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge pending-badge">Pending</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 5 -->
                            <tr>
                                <td>5</td>
                                <td>26-0012</td>
                                <td>Dela Cruz, Juan 23 / F - Dog Bite</td>
                                <td>Day 7 (3rd Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge pending-badge">Pending</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 6 -->
                            <tr>
                                <td>6</td>
                                <td>26-0014</td>
                                <td>Dela Cruz, Juan 25 / F - Cat Bite</td>
                                <td>Day 7 (3rd Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge completed-badge">Completed</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 7 -->
                            <tr>
                                <td>7</td>
                                <td>26-0016</td>
                                <td>Dela Cruz, Juan 31 / M - Cat Bite</td>
                                <td>Day 7 (3rd Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge completed-badge">Completed</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 8 -->
                            <tr>
                                <td>8</td>
                                <td>26-0017</td>
                                <td>Dela Cruz, Juan 36 / M - Dog Bite</td>
                                <td>Day 14 (4th Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge completed-badge">Completed</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 9 -->
                            <tr>
                                <td>9</td>
                                <td>26-0020</td>
                                <td>Dela Cruz, Juan 21 / F - Cat Bite</td>
                                <td>Day 28 (5th Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge pending-badge">Pending</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                            <!-- 10 -->
                            <tr>
                                <td>10</td>
                                <td>26-0021</td>
                                <td>Dela Cruz, Juan 27 / M - Dog Bite</td>
                                <td>Day 7 (3rd Dose)</td>
                                <td>May 14, 2025</td>
                                <td><span class="status-badge overdue-badge">Overdue</span></td>
                                <td><button class="btn-view">View</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Table Footer: Pagination + Export -->
                <div class="table-footer">
                    <div class="pagination-info">
                        <strong>10</strong> / page
                    </div>
                    <div class="pagination-controls">
                        <button><i class="bi bi-chevron-left"></i></button>
                        <button class="active">1</button>
                        <button>2</button>
                        <button><i class="bi bi-chevron-right"></i></button>
                    </div>
                    <div>
                        <button class="export-btn"><i class="bi bi-download"></i> Export List</button>
                    </div>
                </div>

            </div>

        </div><!-- /dashboard-content -->

    </div> <!-- end main -->


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>