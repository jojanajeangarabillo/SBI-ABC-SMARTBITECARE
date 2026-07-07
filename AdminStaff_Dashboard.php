<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an admin staff
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 4 // role_id 4 is for Admin Staff
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Admin Staff';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// ----------------------------------------------------------------------
// FETCH DASHBOARD STATISTICS
// ----------------------------------------------------------------------

// 1. Get Follow-up Patients (patients with pending vaccination schedules)
$followUpQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    LEFT JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND c.case_status != 'Completed'
    AND (
        v.vaccination_status IS NULL 
        OR v.vaccination_status = 'Scheduled' 
        OR v.vaccination_status = 'Missed'
    )
";
$stmt = $conn->prepare($followUpQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$followUpResult = $stmt->get_result();
$followUpCount = $followUpResult->fetch_assoc()['count'] ?? 0;

// 2. Get New Patients (patients admitted this month)
$newPatientsQuery = "
    SELECT COUNT(*) as count
    FROM animal_bite_cases c
    WHERE c.branch_id = ?
    AND YEAR(COALESCE(c.date_of_bite, c.created_at)) = YEAR(CURDATE())
    AND MONTH(COALESCE(c.date_of_bite, c.created_at)) = MONTH(CURDATE())
";
$stmt = $conn->prepare($newPatientsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$newPatientsResult = $stmt->get_result();
$newPatientsCount = $newPatientsResult->fetch_assoc()['count'] ?? 0;

// 3. Get PhilHealth Patients
$philhealthQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    WHERE c.branch_id = ?
    AND ph.philhealth_number IS NOT NULL 
    AND ph.philhealth_number != ''
";
$stmt = $conn->prepare($philhealthQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$philhealthResult = $stmt->get_result();
$philhealthCount = $philhealthResult->fetch_assoc()['count'] ?? 0;

// 4. Get Follow-up Calendar Data (next 30 days)
$calendarData = [];
$followUpCalendarQuery = "
    SELECT 
        DATE(v.next_schedule) as schedule_date,
        COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.next_schedule BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND v.vaccination_status = 'Scheduled'
    GROUP BY DATE(v.next_schedule)
    ORDER BY schedule_date
";
$stmt = $conn->prepare($followUpCalendarQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$calendarResult = $stmt->get_result();
while ($row = $calendarResult->fetch_assoc()) {
    $calendarData[$row['schedule_date']] = (int)$row['count'];
}

// 5. Get PhilHealth Status Breakdown
$philhealthStatusQuery = "
    SELECT 
        ph.status,
        COUNT(*) as count
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    WHERE c.branch_id = ?
    GROUP BY ph.status
";
$stmt = $conn->prepare($philhealthStatusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$statusResult = $stmt->get_result();

$philhealthStatus = [
    'For Writing' => 0,
    'For Screening' => 0,
    'For Signing' => 0,
    'For Transmittal' => 0,
    'Completed' => 0
];

while ($row = $statusResult->fetch_assoc()) {
    $status = $row['status'] ?? 'For Writing';
    if ($status == 'For Signing' || $status == 'For Transmittal') {
        $philhealthStatus['For Signing'] += (int)$row['count'];
    } else {
        $philhealthStatus[$status] = (int)$row['count'];
    }
}

$totalPhilhealthRecords = array_sum($philhealthStatus);

// 6. Get Recent Patient Records (for quick view)
$recentPatientsQuery = "
    SELECT 
        c.case_id,
        p.full_name as patient_name,
        DATE(COALESCE(c.date_of_bite, c.created_at)) as admission_date,
        r.registry_number as case_no,
        c.case_status,
        ph.status as philhealth_status
    FROM animal_bite_cases c
    JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    LEFT JOIN philhealth_records ph ON c.case_id = ph.case_id
    WHERE c.branch_id = ?
    ORDER BY c.created_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($recentPatientsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentResult = $stmt->get_result();
$recentPatients = [];
while ($row = $recentResult->fetch_assoc()) {
    $recentPatients[] = $row;
}

// 7. Get Monthly Patient Trend (last 6 months - for chart)
$monthlyTrendQuery = "
    SELECT 
        DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%b') as month_name,
        DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%m') as month_num,
        COUNT(*) as count
    FROM animal_bite_cases c
    WHERE c.branch_id = ?
    AND COALESCE(c.date_of_bite, c.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%Y-%m')
    ORDER BY month_num ASC
";
$stmt = $conn->prepare($monthlyTrendQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$trendResult = $stmt->get_result();
$monthlyTrendData = [];
while ($row = $trendResult->fetch_assoc()) {
    $monthlyTrendData[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Staff Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f0f2f5;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
            margin-bottom: 0;
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
            display: flex;
            align-items: center;
            gap: 6px;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
        }

        .dashboard-content {
            padding: 30px;
        }

        .dashboard-card {
            background: #fff;
            border-radius: 22px;
            padding: 25px;
            box-shadow: 0 6px 18px rgba(0,0,0,.08);
            border: 1px solid #E9ECEF;
            height: 100%;
        }

        /* Statistics */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 22px 24px;
            border-left: 5px solid var(--primary);
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            transition: all .25s ease;
            text-align: left;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,.12);
        }

        .stat-card.follow-up {
            border-left-color: var(--accent);
        }
        .stat-card.new-patients {
            border-left-color: var(--success);
        }
        .stat-card.philhealth-patients {
            border-left-color: var(--info);
        }

        .stat-card h6 {
            margin: 0;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6c757d;
        }

        .stat-card h1 {
            margin: 0;
            font-size: 42px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
        }

        .stat-card .stat-trend {
            font-size: 13px;
            color: #6c757d;
            margin-top: 8px;
        }

        .stat-card .stat-trend .up {
            color: var(--success);
        }
        .stat-card .stat-trend .down {
            color: var(--danger);
        }

        @media (max-width:992px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            align-items: stretch;
        }

        .calendar-panel,
        .dashboard-card {
            height: 100%;
        }

        /* Calendar */
        .calendar-panel {
            background: #fff;
            border-radius: 22px;
            padding: 25px;
            box-shadow: 0 6px 18px rgba(0,0,0,.08);
        }

        .panel-title {
            color: #2B3A8C;
            font-weight: 700;
            margin-bottom: 20px;
            font-size: 18px;
        }

        .calendar-title {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            font-size: 24px;
            font-weight: 700;
            color: #2B3A8C;
            margin-bottom: 20px;
        }

        .month-btn {
            border: none;
            background: #EEF2FF;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            color: #2B3A8C;
            transition: .3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .month-btn:hover {
            background: #2B3A8C;
            color: white;
        }

        .calendar-table {
            width: 100%;
            border-collapse: collapse;
        }

        .calendar-table th {
            background: #F8F9FC;
            padding: 12px;
            color: #667085;
            font-size: 13px;
            border: 1px solid #E9ECEF;
            text-align: center;
        }

        .calendar-table td {
            height: 85px;
            border: 1px solid #E9ECEF;
            vertical-align: top;
            padding: 6px;
            position: relative;
            text-align: center;
        }

        .day-number {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 700;
            font-size: 14px;
            margin: 0 auto;
        }

        .today {
            background: #2B3A8C;
            color: white;
        }

        .followup-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            background: #E8EEFF;
            color: #2B3A8C;
            font-size: 10px;
            font-weight: 600;
        }

        .calendar-legend {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 12px 0;
            font-size: 13px;
            color: #667085;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2B3A8C;
            display: inline-block;
        }

        .button-row {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 10px 24px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-block;
            text-align: center;
            flex: 1;
            min-width: 120px;
            transition: .3s;
        }

        .btn-action.primary {
            background: #2B3A8C;
            color: white;
        }

        .btn-action.primary:hover {
            background: #1f2d6b;
            color: white;
        }

        .btn-action.secondary {
            background: #E8EEFF;
            color: #2B3A8C;
        }

        .btn-action.secondary:hover {
            background: #d0d9f0;
        }

        /* PhilHealth Section */
        .philhealth-content {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .chart-area {
            flex: 1;
            min-width: 180px;
            max-width: 280px;
        }

        .chart-area canvas {
            max-width: 100%;
            height: auto;
        }

        .legend-area {
            flex: 1;
        }

        .legend-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #ECECEC;
        }

        .legend-item:last-child {
            border-bottom: none;
        }

        .legend-left {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: #495057;
        }

        .legend-box {
            width: 14px;
            height: 14px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .legend-box.writing {
            background: #4E79A7;
        }
        .legend-box.screening {
            background: #F28E2B;
        }
        .legend-box.signing {
            background: #E15759;
        }
        .legend-box.completed {
            background: #76B7B2;
        }

        .legend-item strong {
            font-size: 18px;
            color: var(--primary);
        }

        .total-count {
            margin-top: 20px;
            text-align: center;
            color: #2B3A8C;
            font-size: 22px;
            font-weight: 700;
            padding-top: 15px;
            border-top: 2px solid #ECECEC;
        }

        .dashboard-btn {
            background: var(--primary);
            color: white;
            border: none;
            width: 75%;
            border-radius: 40px;
            font-weight: 600;
            padding: 10px;
            transition: .3s;
            cursor: pointer;
        }

        .dashboard-btn:hover {
            background: #1f2d6b;
            transform: translateY(-2px);
        }

        /* Recent Patients Table */
        .recent-patients-section {
            margin-top: 35px;
        }

        .recent-patients-section .dashboard-card {
            padding: 20px 25px;
        }

        .recent-table {
            width: 100%;
            font-size: 14px;
        }

        .recent-table th {
            background: #F8F9FC;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #ECECEC;
        }

        .recent-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ECECEC;
        }

        .recent-table tr:hover {
            background: #F8F9FC;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-badge.ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.completed {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.for-writing {
            background: #cce5ff;
            color: #004085;
        }

        .status-badge.for-screening {
            background: #ffe5cc;
            color: #853d04;
        }

        .status-badge.for-signing {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.for-transmittal {
            background: #d1ecf1;
            color: #0c5460;
        }

        .view-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .view-link:hover {
            text-decoration: underline;
        }

        /* Monthly Trend Chart */
        .trend-chart-container {
            margin-top: 20px;
            padding: 20px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #E9ECEF;
        }

        .trend-chart-container canvas {
            max-height: 200px;
            width: 100% !important;
        }

        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .philhealth-content {
                flex-direction: column;
            }
            .chart-area {
                max-width: 100%;
            }
            .stats-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 16px;
            }
            .topbar {
                padding: 0 16px;
                height: 64px;
            }
            .topbar h3 {
                font-size: 20px;
            }
            .button-row {
                flex-direction: column;
            }
            .btn-action {
                flex: none;
                width: 100%;
            }
            .dashboard-btn {
                width: 100%;
            }
            .calendar-table td {
                height: 60px;
                padding: 4px;
                font-size: 12px;
            }
            .day-number {
                width: 26px;
                height: 26px;
                font-size: 12px;
            }
        }

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

        .no-data {
            text-align: center;
            color: #adb5bd;
            padding: 20px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .section-header .view-all {
            font-size: 14px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .section-header .view-all:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" style="max-width:50px;height:auto;">
            </div>
            <div class="system-name">Smart Bite Care</div>
        </div>

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
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- Top Header -->
        <div class="topbar">
            <h3>Dashboard</h3>
            <div class="profile">
                <?php echo htmlspecialchars($username); ?>
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="dashboard-content">
            <!-- Statistics -->
            <div class="stats-container">
                <div class="stat-card follow-up">
                    <h6>Follow Up Patients</h6>
                    <h1><?php echo $followUpCount; ?></h1>
                    <div class="stat-trend">Pending vaccinations &amp; follow-ups</div>
                </div>

                <div class="stat-card new-patients">
                    <h6>New Patients</h6>
                    <h1><?php echo $newPatientsCount; ?></h1>
                    <div class="stat-trend">Admitted this month</div>
                </div>

                <div class="stat-card philhealth-patients">
                    <h6>PhilHealth Patients</h6>
                    <h1><?php echo $philhealthCount; ?></h1>
                    <div class="stat-trend">With PhilHealth coverage</div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Calendar -->
                <div class="calendar-panel">
                    <h4 class="panel-title">Follow-up Calendar</h4>

                    <div class="calendar-title">
                        <button class="month-btn" id="prevMonth">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <span id="monthName"></span>
                        <button class="month-btn" id="nextMonth">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>

                    <table class="calendar-table">
                        <thead>
                            <tr>
                                <th>Sun</th>
                                <th>Mon</th>
                                <th>Tue</th>
                                <th>Wed</th>
                                <th>Thu</th>
                                <th>Fri</th>
                                <th>Sat</th>
                            </tr>
                        </thead>
                        <tbody id="dashboardCalendarBody"></tbody>
                    </table>

                    <div class="calendar-legend">
                        <span class="legend-dot"></span>
                        Pending follow-ups
                    </div>

                    <div class="button-row">
                        <a href="AdminStaff_Calendar.php" class="btn-action primary">
                            View Calendar
                        </a>
                        <a href="AdminStaff_PatientRecord.php" class="btn-action secondary">
                            Open Patient Records
                        </a>
                    </div>
                </div>

                <!-- PhilHealth -->
                <div class="dashboard-card">
                    <h4 class="panel-title">PhilHealth Status Overview</h4>

                    <div class="philhealth-content">
                        <div class="chart-area">
                            <canvas id="philhealthChart"></canvas>
                        </div>

                        <div class="legend-area">
                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box writing"></span>
                                    For Writing
                                </div>
                                <strong><?php echo $philhealthStatus['For Writing']; ?></strong>
                            </div>

                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box screening"></span>
                                    For Screening
                                </div>
                                <strong><?php echo $philhealthStatus['For Screening']; ?></strong>
                            </div>

                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box signing"></span>
                                    For Signing/Transmittal
                                </div>
                                <strong><?php echo $philhealthStatus['For Signing']; ?></strong>
                            </div>

                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box completed"></span>
                                    Completed
                                </div>
                                <strong><?php echo $philhealthStatus['Completed']; ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="total-count">
                        Total: <?php echo $totalPhilhealthRecords; ?>
                    </div>

                    <div class="text-center mt-3">
                        <a href="AdminStaff_PhilhealthStatus.php">
                            <button class="dashboard-btn">View All PhilHealth Records</button>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend Chart -->
            <div class="trend-chart-container">
                <div class="section-header">
                    <h5 class="panel-title" style="margin-bottom:0;">Monthly Patient Trend</h5>
                    <span class="text-muted" style="font-size:13px;">Last 6 months</span>
                </div>
                <canvas id="trendChart"></canvas>
            </div>

            <!-- Recent Patients -->
            <div class="recent-patients-section">
                <div class="dashboard-card">
                    <div class="section-header">
                        <h4 class="panel-title" style="margin-bottom:0;">Recent Patient Records</h4>
                        <a href="AdminStaff_PatientRecord.php" class="view-all">View All →</a>
                    </div>
                    <div class="table-responsive">
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th>Case No.</th>
                                    <th>Patient Name</th>
                                    <th>Admission Date</th>
                                    <th>Status</th>
                                    <th>PhilHealth</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentPatients)): ?>
                                <tr>
                                    <td colspan="6" class="no-data">No patient records found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recentPatients as $patient): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($patient['case_no'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($patient['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['admission_date'] ?? ''); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower($patient['case_status'] ?? 'ongoing'); ?>">
                                            <?php echo htmlspecialchars($patient['case_status'] ?? 'Ongoing'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($patient['philhealth_status'])): ?>
                                        <span class="status-badge <?php echo strtolower(str_replace(' ', '-', $patient['philhealth_status'] ?? 'for-writing')); ?>">
                                            <?php echo htmlspecialchars($patient['philhealth_status']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="AdminStaff_PatientRecord.php?action=view&case_id=<?php echo $patient['case_id']; ?>" class="view-link">
                                            View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
    // ----------------------------------------------------------------
    // PHILHEALTH STATUS CHART
    // ----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('philhealthChart').getContext('2d');

        const statusData = [
            <?php echo $philhealthStatus['For Writing']; ?>,
            <?php echo $philhealthStatus['For Screening']; ?>,
            <?php echo $philhealthStatus['For Signing']; ?>,
            <?php echo $philhealthStatus['Completed']; ?>
        ];

        const labels = ['For Writing', 'For Screening', 'For Signing', 'Completed'];
        const colors = ['#4E79A7', '#F28E2B', '#E15759', '#76B7B2'];

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: statusData,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                cutout: '70%'
            }
        });

        // ----------------------------------------------------------------
        // MONTHLY TREND CHART
        // ----------------------------------------------------------------
        const trendCtx = document.getElementById('trendChart').getContext('2d');

        const trendData = <?php echo json_encode($monthlyTrendData); ?>;
        const trendLabels = trendData.map(item => item.month_name);
        const trendCounts = trendData.map(item => item.count);

        if (trendData.length > 0) {
            new Chart(trendCtx, {
                type: 'bar',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Patients Admitted',
                        data: trendCounts,
                        backgroundColor: '#2B3A8C',
                        borderRadius: 4,
                        barPercentage: 0.6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y + ' patients';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        } else {
            // No data - show message
            document.getElementById('trendChart').style.display = 'none';
            const container = document.querySelector('.trend-chart-container');
            const noDataMsg = document.createElement('div');
            noDataMsg.className = 'no-data';
            noDataMsg.innerHTML = '<i class="bi bi-bar-chart-line" style="font-size:32px;display:block;margin-bottom:10px;"></i>No patient data available for the last 6 months.';
            container.appendChild(noDataMsg);
        }
    });

    // ----------------------------------------------------------------
    // CALENDAR WITH DYNAMIC FOLLOW-UP DATA
    // ----------------------------------------------------------------
    const followUps = <?php echo json_encode($calendarData); ?>;

    let currentDate = new Date();

    function renderCalendar() {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();

        const monthNames = [
            "January", "February", "March", "April",
            "May", "June", "July", "August",
            "September", "October", "November", "December"
        ];

        document.getElementById("monthName").innerHTML = monthNames[month] + " " + year;

        const tbody = document.getElementById("dashboardCalendarBody");
        tbody.innerHTML = "";

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();

        let date = 1;
        for (let i = 0; i < 6; i++) {
            let row = document.createElement("tr");

            for (let j = 0; j < 7; j++) {
                let cell = document.createElement("td");

                if (i === 0 && j < firstDay) {
                    row.appendChild(cell);
                    continue;
                }

                if (date > daysInMonth) {
                    row.appendChild(cell);
                    continue;
                }

                const dayDiv = document.createElement("div");
                dayDiv.className = "day-number";
                dayDiv.innerHTML = date;

                // Highlight today
                if (date === today.getDate() &&
                    month === today.getMonth() &&
                    year === today.getFullYear()) {
                    dayDiv.classList.add("today");
                }

                cell.appendChild(dayDiv);

                // Check for follow-ups on this date
                const key = year + "-" +
                    String(month + 1).padStart(2, "0") + "-" +
                    String(date).padStart(2, "0");

                if (followUps[key]) {
                    const badge = document.createElement("div");
                    badge.className = "followup-badge";
                    badge.innerHTML = followUps[key] + " due";
                    cell.appendChild(badge);
                }

                date++;
                row.appendChild(cell);
            }

            tbody.appendChild(row);
        }
    }

    document.getElementById("prevMonth").onclick = function() {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    };

    document.getElementById("nextMonth").onclick = function() {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    };

    renderCalendar();

    // ----------------------------------------------------------------
    // AUTO-REFRESH DASHBOARD DATA (every 30 seconds)
    // Uses simple AJAX to reload the page data without full refresh
    // ----------------------------------------------------------------
    function refreshStats() {
        // Only refresh if the page is visible
        if (document.hidden) return;

        fetch(window.location.href, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // Parse the HTML to extract updated stats
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // Update follow-up count
            const newFollowUp = doc.querySelector('.stat-card.follow-up h1');
            if (newFollowUp) {
                document.querySelector('.stat-card.follow-up h1').textContent = newFollowUp.textContent;
            }

            // Update new patients count
            const newPatients = doc.querySelector('.stat-card.new-patients h1');
            if (newPatients) {
                document.querySelector('.stat-card.new-patients h1').textContent = newPatients.textContent;
            }

            // Update PhilHealth count
            const newPhilhealth = doc.querySelector('.stat-card.philhealth-patients h1');
            if (newPhilhealth) {
                document.querySelector('.stat-card.philhealth-patients h1').textContent = newPhilhealth.textContent;
            }

            // Update PhilHealth status counts
            const statusCounts = doc.querySelectorAll('.legend-item strong');
            if (statusCounts.length === 4) {
                document.querySelectorAll('.legend-item strong').forEach((el, index) => {
                    if (statusCounts[index]) {
                        el.textContent = statusCounts[index].textContent;
                    }
                });
            }

            // Update total
            const totalEl = doc.querySelector('.total-count');
            if (totalEl) {
                document.querySelector('.total-count').textContent = totalEl.textContent;
            }

            // Update recent patients table
            const newTableBody = doc.querySelector('.recent-table tbody');
            if (newTableBody) {
                document.querySelector('.recent-table tbody').innerHTML = newTableBody.innerHTML;
            }

            // Update follow-up calendar data
            const scriptContent = html.match(/const followUps = ({[^;]+});/);
            if (scriptContent) {
                try {
                    const newFollowUps = JSON.parse(scriptContent[1].replace(/'/g, '"'));
                    Object.assign(followUps, newFollowUps);
                    renderCalendar();
                } catch (e) {
                    console.error('Error parsing follow-up data:', e);
                }
            }
        })
        .catch(error => console.error('Error refreshing stats:', error));
    }

    // Refresh every 30 seconds
    setInterval(refreshStats, 30000);

    // Also refresh when the page becomes visible again
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshStats();
        }
    });
    </script>
</body>
</html>