<?php
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

        .bi-pencil-square {
            margin-right: 12px;
        }

        .bi-trash3-fill {
            margin-left: 12px;
        }

        /* =========================================
           MAIN CONTENT STYLES
           ========================================= */

        .dashboard-content {
            padding: 30px;
        }

        /* Statistics */

        .stats-container {
            display: flex;
            justify-content: space-between;
            gap: 35px;
            margin-bottom: 35px;
        }

        .stat-card {
            flex: 1;
            background: #E7EAF5;
            border-radius: 18px;
            text-align: center;
            padding: 18px;
        }

        .stat-card h6 {
            color: #2B3A8C;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-card h1 {
            color: #2B3A8C;
            font-size: 45px;
            font-weight: 700;
            margin: 0;
        }

        /* Bottom Grid */

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        /* Card */

        .dashboard-card h5 {
            color: #2B3A8C;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .calendar-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .08);
            padding: 20px;
        }

        .calendar-title {
            text-align: center;
            font-weight: 700;
            margin-bottom: 20px;
            color: #1b2d5a;
        }

        .calendar-table {
            table-layout: fixed;
        }

        .calendar-table th {
            text-align: center;
            font-size: 13px;
            color: #6c757d;
            background: #f8f9fa;
        }

        .calendar-table td {
            height: 110px;
            vertical-align: top;
            padding: 10px;
            position: relative;
        }

        .empty {
            background: #fafafa;
        }

        .day-number {
            font-weight: 700;
            font-size: 18px;
            width: 32px;
            height: 32px;
            line-height: 32px;
            text-align: center;
            border-radius: 50%;
            margin-bottom: 12px;
        }

        .day-number.active {
            background: #0d6efd;
            color: white;
        }

        .event {
            font-size: 14px;
            margin-bottom: 4px;
        }

        .dot {
            width: 8px;
            height: 8px;
            display: inline-block;
            border-radius: 50%;
            margin-right: 6px;
        }

        .blue {
            background: #0d6efd;
        }

        .orange {
            background: #fd7e14;
        }

        .green {
            background: #198754;
        }

        .calendar-legend {
            margin-top: 20px;
            display: flex;
            gap: 30px;
            font-size: 14px;
        }

        /* Buttons */

        .dashboard-btn {
            background: #DDE1EF;
            color: #2B3A8C;
            border: none;
            width: 75%;
            border-radius: 40px;
            font-weight: 600;
            padding: 10px;
            transition: .3s;
        }

        .dashboard-btn:hover {
            background: #2B3A8C;
            color: #fff;
        }

        /* PhilHealth */

        .dashboard-card:last-child {
            border: 1px solid #444;
            border-radius: 20px;
            padding: 20px;
        }

        .philhealth-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 20px;
        }

        .chart-area {
            width: 52%;
        }

        .legend-area {
            width: 42%;
        }

        .legend-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            font-size: 15px;
            font-weight: 500;
        }

        .legend-box {
            width: 14px;
            height: 14px;
            background: #D9D9D9;
            margin-right: 10px;
            display: inline-block;
        }

        .total-count {
            text-align: center;
            margin-top: 20px;
            color: #000;
            font-size: 28px;
            font-weight: 700;
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
                <li><a class="active" href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
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

        <div class="dashboard-content">

            <!-- Statistics -->
            <div class="stats-container">

                <div class="stat-card">
                    <h6>Returning Patients</h6>
                    <h1>43</h1>
                </div>

                <div class="stat-card">
                    <h6>New Patients</h6>
                    <h1>28</h1>
                </div>

                <div class="stat-card">
                    <h6>Calendar</h6>
                    <h1>33</h1>
                </div>

            </div>

            <div class="dashboard-grid">

                <!-- Calendar -->
                <div class="dashboard-card">

                    <div class="calendar-container">

                        <h3 class="calendar-title">July 2026</h3>

                        <table class="table calendar-table">

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

                                <!-- Week 1 -->
                                <tr>

                                    <td class="empty"></td>
                                    <td class="empty"></td>
                                    <td class="empty"></td>

                                    <td>
                                        <div class="day-number">1</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>8
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">2</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>10
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">3</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>6
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">4</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>9
                                        </div>
                                    </td>

                                </tr>

                                <!-- Week 2 -->

                                <tr>

                                    <td>
                                        <div class="day-number">5</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>7
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">6</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>11
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number active">7</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>18
                                        </div>

                                        <div class="event overdue">
                                            <span class="dot orange"></span>2
                                        </div>

                                    </td>

                                    <td>
                                        <div class="day-number">8</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>12
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">9</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>15
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">10</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>5
                                        </div>
                                    </td>

                                    <td>
                                        <div class="day-number">11</div>

                                        <div class="event followup">
                                            <span class="dot blue"></span>9
                                        </div>
                                    </td>

                                </tr>

                                <!-- Continue remaining weeks (weeks 3–5) – add more rows as needed -->

                            </tbody>

                        </table>

                        <div class="calendar-legend">

                            <span><span class="dot blue"></span> Follow-up Due</span>

                            <span><span class="dot orange"></span> Overdue</span>

                            <span><span class="dot green"></span> Completed</span>

                        </div>

                      <div class="text-center mt-3">

                        <a href="AdminStaff_Calendar.php">

                            <button class="btn dashboard-btn">
                                View Calendar
                            </button>
                        </a>

                    </div>

                    </div> <!-- end calendar-container -->

                </div> <!-- end dashboard-card (Calendar) -->

                <!-- PhilHealth -->
                <div class="dashboard-card">

                    <h5>PhilHealth Status Overview</h5>

                    <div class="philhealth-content">

                        <div class="chart-area">

                            <img src="https://quickchart.io/chart?c={type:'pie',data:{labels:['For Writing','For Screening','For Signing','Completed'],datasets:[{data:[20,6,4,2]}]}}" class="img-fluid">

                        </div>

                        <div class="legend-area">

                            <div class="legend-item">
                                <span class="legend-box"></span>
                                For Writing
                                <strong>5</strong>
                            </div>

                            <div class="legend-item">
                                <span class="legend-box"></span>
                                For Screening
                                <strong>5</strong>
                            </div>

                            <div class="legend-item">
                                <span class="legend-box"></span>
                                For Signing/Transmittal
                                <strong>5</strong>
                            </div>

                            <div class="legend-item">
                                <span class="legend-box"></span>
                                Completed
                                <strong>5</strong>
                            </div>

                        </div>

                    </div>

                    <h4 class="total-count">
                        Total: 32
                    </h4>

                    <div class="text-center mt-3">

                        <a href="AdminStaff_PhilhealthStatus.php">

                            <button class="btn dashboard-btn">
                                View All PhilHealth Records
                            </button>
                        </a>

                    </div>

                </div> <!-- end dashboard-card (PhilHealth) -->

            </div> <!-- end dashboard-grid -->

        </div> <!-- end dashboard-content -->

    </div> <!-- end main -->


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>