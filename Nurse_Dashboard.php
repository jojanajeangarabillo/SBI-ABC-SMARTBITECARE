<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
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
    $username = $userData['username'] ?? 'Nurse';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// =============================================
// FETCH ALL STATISTICS FOR NURSE DASHBOARD
// =============================================

$stats = [];

// 1. PATIENT WAITING (patients with ongoing cases)
$waitingQuery = "SELECT COUNT(DISTINCT p.patient_id) as waiting 
                 FROM patients p 
                 JOIN animal_bite_cases abc ON p.patient_id = abc.patient_id 
                 WHERE abc.branch_id = ? AND abc.case_status = 'Ongoing'";
$stmt = $conn->prepare($waitingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$waitingResult = $stmt->get_result();
$stats['patient_waiting'] = $waitingResult->fetch_assoc()['waiting'] ?? 0;

// 2. ONGOING CASES
$ongoingQuery = "SELECT COUNT(*) as ongoing 
                 FROM animal_bite_cases 
                 WHERE branch_id = ? AND case_status = 'Ongoing'";
$stmt = $conn->prepare($ongoingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$ongoingResult = $stmt->get_result();
$stats['ongoing_cases'] = $ongoingResult->fetch_assoc()['ongoing'] ?? 0;

// 3. COMPLETED CASES
$completedQuery = "SELECT COUNT(*) as completed 
                   FROM animal_bite_cases 
                   WHERE branch_id = ? AND case_status = 'Completed'";
$stmt = $conn->prepare($completedQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$completedResult = $stmt->get_result();
$stats['completed_cases'] = $completedResult->fetch_assoc()['completed'] ?? 0;

// 4. TOTAL CASES
$totalCasesQuery = "SELECT COUNT(*) as total 
                    FROM animal_bite_cases 
                    WHERE branch_id = ?";
$stmt = $conn->prepare($totalCasesQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$totalCasesResult = $stmt->get_result();
$stats['total_cases'] = $totalCasesResult->fetch_assoc()['total'] ?? 0;

// 5. TOTAL PATIENTS
$totalPatientsQuery = "SELECT COUNT(*) as total 
                       FROM patients 
                       WHERE branch_id = ?";
$stmt = $conn->prepare($totalPatientsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$totalPatientsResult = $stmt->get_result();
$stats['total_patients'] = $totalPatientsResult->fetch_assoc()['total'] ?? 0;

// 6. VACCINATIONS TODAY
$todayQuery = "SELECT COUNT(*) as today_vaccinations 
               FROM vaccination_records 
               WHERE branch_id = ? AND DATE(date_administered) = CURDATE()";
$stmt = $conn->prepare($todayQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$todayResult = $stmt->get_result();
$stats['today_vaccinations'] = $todayResult->fetch_assoc()['today_vaccinations'] ?? 0;

// 7. TOTAL VACCINATIONS (All Time)
$totalVaccQuery = "SELECT COUNT(*) as total 
                   FROM vaccination_records 
                   WHERE branch_id = ?";
$stmt = $conn->prepare($totalVaccQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$totalVaccResult = $stmt->get_result();
$stats['total_vaccinations'] = $totalVaccResult->fetch_assoc()['total'] ?? 0;

// 8. UPCOMING SCHEDULED VACCINATIONS (Next 7 days)
$upcomingQuery = "SELECT COUNT(*) as upcoming 
                  FROM vaccination_records 
                  WHERE branch_id = ? 
                  AND vaccination_status = 'Scheduled' 
                  AND DATE(scheduled_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
$stmt = $conn->prepare($upcomingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$upcomingResult = $stmt->get_result();
$stats['upcoming_vaccinations'] = $upcomingResult->fetch_assoc()['upcoming'] ?? 0;

// 9. MISSED VACCINATIONS
$missedQuery = "SELECT COUNT(*) as missed 
                FROM vaccination_records 
                WHERE branch_id = ? AND vaccination_status = 'Missed'";
$stmt = $conn->prepare($missedQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$missedResult = $stmt->get_result();
$stats['missed_vaccinations'] = $missedResult->fetch_assoc()['missed'] ?? 0;

// 10. ANIMAL BITE CATEGORY STATISTICS
$categoryQuery = "SELECT bite_category, COUNT(*) as count 
                  FROM animal_bite_cases 
                  WHERE branch_id = ? 
                  GROUP BY bite_category";
$stmt = $conn->prepare($categoryQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$categoryResult = $stmt->get_result();
$biteCategories = [];
while ($row = $categoryResult->fetch_assoc()) {
    $biteCategories[] = $row;
}

// 11. ANIMAL TYPE STATISTICS
$animalTypeQuery = "SELECT animal_type, COUNT(*) as count 
                    FROM animal_bite_cases 
                    WHERE branch_id = ? AND animal_type IS NOT NULL
                    GROUP BY animal_type 
                    ORDER BY count DESC 
                    LIMIT 5";
$stmt = $conn->prepare($animalTypeQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$animalTypeResult = $stmt->get_result();
$animalTypes = [];
while ($row = $animalTypeResult->fetch_assoc()) {
    $animalTypes[] = $row;
}

// 12. PHILHEALTH COVERAGE
$philhealthQuery = "SELECT has_philhealth, COUNT(*) as count 
                    FROM philhealth_records pr
                    JOIN animal_bite_cases abc ON pr.case_id = abc.case_id
                    WHERE abc.branch_id = ? 
                    GROUP BY has_philhealth";
$stmt = $conn->prepare($philhealthQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$philhealthResult = $stmt->get_result();
$philhealthStats = [];
while ($row = $philhealthResult->fetch_assoc()) {
    $philhealthStats[$row['has_philhealth']] = $row['count'];
}

// 13. LOW STOCK ITEMS
$lowStockQuery = "SELECT ii.item_name, is_.quantity_available, ii.minimum_stock, u.unit_name
                  FROM inventory_stocks is_
                  JOIN inventory_items ii ON is_.item_id = ii.item_id
                  JOIN units u ON ii.unit_id = u.unit_id
                  WHERE is_.branch_id = ? 
                  AND is_.quantity_available <= ii.minimum_stock
                  ORDER BY (is_.quantity_available / ii.minimum_stock) ASC
                  LIMIT 5";
$stmt = $conn->prepare($lowStockQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockResult = $stmt->get_result();
$lowStockItems = [];
while ($row = $lowStockResult->fetch_assoc()) {
    $lowStockItems[] = $row;
}

// 14. TODAY'S SCHEDULE
$scheduleQuery = "SELECT v.*, p.full_name, p.contact_number 
                  FROM vaccination_records v
                  JOIN patients p ON v.patient_id = p.patient_id
                  WHERE v.branch_id = ? 
                  AND DATE(v.scheduled_date) = CURDATE() 
                  AND v.vaccination_status = 'Scheduled'
                  ORDER BY v.scheduled_date ASC
                  LIMIT 10";
$stmt = $conn->prepare($scheduleQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$scheduleResult = $stmt->get_result();
$schedules = [];
while ($row = $scheduleResult->fetch_assoc()) {
    $schedules[] = $row;
}

// 15. FOLLOW-UP DUE (cases needing follow-up)
$followupQuery = "SELECT abc.case_id, p.full_name, abc.date_of_bite, 
                  DATEDIFF(CURDATE(), abc.date_of_bite) as days_since_bite,
                  abc.remarks
                  FROM animal_bite_cases abc
                  JOIN patients p ON abc.patient_id = p.patient_id
                  WHERE abc.branch_id = ? 
                  AND abc.case_status = 'Ongoing'
                  AND DATEDIFF(CURDATE(), abc.date_of_bite) >= 7
                  ORDER BY abc.date_of_bite ASC
                  LIMIT 5";
$stmt = $conn->prepare($followupQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$followupResult = $stmt->get_result();
$followups = [];
while ($row = $followupResult->fetch_assoc()) {
    $followups[] = $row;
}

// 16. WEEKLY VACCINATION TREND (Last 7 days)
$weeklyTrendQuery = "SELECT DATE(date_administered) as date, COUNT(*) as count 
                     FROM vaccination_records 
                     WHERE branch_id = ? 
                     AND date_administered >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                     GROUP BY DATE(date_administered)
                     ORDER BY date ASC";
$stmt = $conn->prepare($weeklyTrendQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$weeklyTrendResult = $stmt->get_result();
$weeklyTrend = [];
while ($row = $weeklyTrendResult->fetch_assoc()) {
    $weeklyTrend[] = $row;
}

// 17. REGISTRY RECORDS STATUS
$registryStatusQuery = "SELECT 
                        SUM(erig) as erig_count,
                        SUM(ats) as ats_count,
                        SUM(tt) as tt_count
                        FROM registry_records rr
                        JOIN animal_bite_cases abc ON rr.case_id = abc.case_id
                        WHERE abc.branch_id = ?";
$stmt = $conn->prepare($registryStatusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$registryStatusResult = $stmt->get_result();
$registryStatus = $registryStatusResult->fetch_assoc();

// 18. DOSE COMPLETION RATE
$doseCompletionQuery = "SELECT 
                        AVG(CASE WHEN dose_d0 = 1 THEN 100 ELSE 0 END) as dose0_rate,
                        AVG(CASE WHEN dose_d3 = 1 THEN 100 ELSE 0 END) as dose3_rate,
                        AVG(CASE WHEN dose_d7 = 1 THEN 100 ELSE 0 END) as dose7_rate,
                        AVG(CASE WHEN dose_d14 = 1 THEN 100 ELSE 0 END) as dose14_rate,
                        AVG(CASE WHEN dose_d21 = 1 THEN 100 ELSE 0 END) as dose21_rate,
                        AVG(CASE WHEN dose_d28_30 = 1 THEN 100 ELSE 0 END) as dose28_rate
                        FROM registry_records rr
                        JOIN animal_bite_cases abc ON rr.case_id = abc.case_id
                        WHERE abc.branch_id = ?";
$stmt = $conn->prepare($doseCompletionQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$doseCompletionResult = $stmt->get_result();
$doseCompletion = $doseCompletionResult->fetch_assoc();

// 19. CASE STATUS DISTRIBUTION
$caseStatusQuery = "SELECT case_status, COUNT(*) as count 
                    FROM animal_bite_cases 
                    WHERE branch_id = ? 
                    GROUP BY case_status";
$stmt = $conn->prepare($caseStatusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$caseStatusResult = $stmt->get_result();
$caseStatusStats = [];
while ($row = $caseStatusResult->fetch_assoc()) {
    $caseStatusStats[$row['case_status']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Reusable Sidebar CSS -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
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
        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
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

        /* ALL STAT CARDS - UNIFORM SIZE */
        .stat-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 20px 24px;
            height: 120px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: transform 0.2s;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-card .stat-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 16px;
            letter-spacing: 0.2px;
            margin-bottom: 2px;
        }
        .stat-card .stat-number {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
        }
        .stat-card .stat-icon {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 48px;
            opacity: 0.15;
            color: var(--primary);
        }

        /* Large Cards */
        .large-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 22px 24px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            height: 100%;
            min-height: 340px;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s;
        }
        .large-card:hover {
            transform: translateY(-2px);
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Schedule Table */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            flex: 1;
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

        /* Follow-up Items */
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
            flex: 1;
        }
        .followup-item .followup-days {
            font-size: 13px;
            color: #6c757d;
            margin-left: auto;
        }

        /* Stock Items */
        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #d7def0;
        }
        .stock-item:last-child {
            border-bottom: none;
        }
        .stock-item .stock-name {
            font-weight: 500;
            color: #1f2a4a;
        }
        .stock-item .stock-qty {
            font-weight: 600;
            color: var(--danger);
            white-space: nowrap;
        }

        /* Buttons */
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 20px 10px;
            color: #999;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .empty-state i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        /* Chart Container */
        .chart-container {
            height: 200px;
            position: relative;
            flex: 1;
        }

        /* Responsive */
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
            .stat-card .stat-number {
                font-size: 32px;
            }
            .stat-card {
                height: 100px;
                padding: 16px;
            }
            .stat-card .stat-icon {
                font-size: 36px;
                right: 14px;
            }
            .schedule-table .time-col {
                width: 60px;
                font-size: 13px;
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
                min-height: 280px;
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
            <li><a class="active" href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
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
        <h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile"><?php echo htmlspecialchars($username); ?> <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- ============================================ -->
        <!-- STATS ROW - ALL 8 CARDS UNIFORM -->
        <!-- ============================================ -->
        <div class="row g-4">
            <!-- Patient Waiting -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-person"></i></span>
                    <div class="stat-title">Patient Waiting</div>
                    <div class="stat-number"><?php echo number_format($stats['patient_waiting']); ?></div>
                </div>
            </div>
            <!-- Ongoing Cases -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-activity"></i></span>
                    <div class="stat-title">Ongoing Cases</div>
                    <div class="stat-number"><?php echo number_format($stats['ongoing_cases']); ?></div>
                </div>
            </div>
            <!-- Completed Cases -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-check-circle"></i></span>
                    <div class="stat-title">Completed Cases</div>
                    <div class="stat-number"><?php echo number_format($stats['completed_cases']); ?></div>
                </div>
            </div>
            <!-- Vaccinations Today -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-syringe"></i></span>
                    <div class="stat-title">Vaccinations Today</div>
                    <div class="stat-number"><?php echo number_format($stats['today_vaccinations']); ?></div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECOND STATS ROW - ALL 4 CARDS UNIFORM -->
        <!-- ============================================ -->
        <div class="row g-4 mt-3">
            <!-- Total Patients -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-people"></i></span>
                    <div class="stat-title">Total Patients</div>
                    <div class="stat-number"><?php echo number_format($stats['total_patients']); ?></div>
                </div>
            </div>
            <!-- Total Cases -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-file-medical"></i></span>
                    <div class="stat-title">Total Cases</div>
                    <div class="stat-number"><?php echo number_format($stats['total_cases']); ?></div>
                </div>
            </div>
            <!-- Upcoming (7 days) -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-calendar-check"></i></span>
                    <div class="stat-title">Upcoming (7 days)</div>
                    <div class="stat-number"><?php echo number_format($stats['upcoming_vaccinations']); ?></div>
                </div>
            </div>
            <!-- Missed Vaccinations -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-exclamation-triangle"></i></span>
                    <div class="stat-title">Missed Vaccinations</div>
                    <div class="stat-number"><?php echo number_format($stats['missed_vaccinations']); ?></div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- CHART & STATISTICS ROW -->
        <!-- ============================================ -->
        <div class="row g-4 mt-2">
            
            <!-- Weekly Vaccination Trend Chart -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-graph-up"></i> Weekly Vaccination Trend
                    </div>
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bite Category Distribution -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-pie-chart"></i> Bite Category Distribution
                    </div>
                    <?php if (empty($biteCategories)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>No data available</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- 2 COLUMN LAYOUT: Schedule & Follow-ups -->
        <!-- ============================================ -->
        <div class="row g-4 mt-2">

            <!-- Today's Schedule -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-calendar-day"></i> Today's Schedule
                        <span class="badge bg-primary rounded-pill ms-auto"><?php echo count($schedules); ?></span>
                    </div>

                    <?php if (empty($schedules)): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-check"></i>
                            <p>No scheduled vaccinations for today.</p>
                        </div>
                    <?php else: ?>
                        <table class="schedule-table">
                            <?php foreach ($schedules as $schedule): ?>
                                <tr>
                                    <td class="time-col">
                                        <?php 
                                        if ($schedule['scheduled_date']) {
                                            echo date('h:i A', strtotime($schedule['scheduled_date']));
                                        } else {
                                            echo '--:--';
                                        }
                                        ?>
                                    </td>
                                    <td class="activity-col">
                                        <?php echo htmlspecialchars($schedule['full_name']); ?>
                                        <span class="sub-activity">
                                            Dose <?php echo $schedule['dose_number']; ?>
                                            <?php if ($schedule['is_final_dose']): ?>
                                                <span class="badge bg-success">Final</span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">Contact: <?php echo htmlspecialchars($schedule['contact_number'] ?? 'N/A'); ?></small>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_Patients.php'">View All Schedule</button>
                    </div>
                </div>
            </div>

            <!-- Follow-up Due -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-clock-history"></i> Follow-up Due
                        <span class="badge bg-warning rounded-pill ms-auto"><?php echo count($followups); ?></span>
                    </div>

                    <?php if (empty($followups)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <p>No follow-ups due at this time.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($followups as $followup): ?>
                            <div class="followup-item">
                                <span class="followup-date">
                                    <?php echo date('M d, Y', strtotime($followup['date_of_bite'])); ?>
                                </span>
                                <span class="followup-name">
                                    <?php echo htmlspecialchars($followup['full_name']); ?>
                                    <?php if (!empty($followup['remarks'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($followup['remarks'], 0, 50)); ?></small>
                                    <?php endif; ?>
                                </span>
                                <span class="followup-days">
                                    <span class="badge bg-danger"><?php echo $followup['days_since_bite']; ?> days</span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_Patients.php'">View All</button>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================ -->
        <!-- BOTTOM ROW: Low Stock & PhilHealth -->
        <!-- ============================================ -->
        <div class="row g-4 mt-2">

            <!-- Low Stock Items -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger);"></i> Low Stock Items
                        <span class="badge bg-danger rounded-pill ms-auto"><?php echo count($lowStockItems); ?></span>
                    </div>

                    <?php if (empty($lowStockItems)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle-fill" style="color:var(--success);"></i>
                            <p>All items are adequately stocked.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lowStockItems as $item): ?>
                            <div class="stock-item">
                                <div>
                                    <span class="stock-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                    <br>
                                    <small class="text-muted">Min: <?php echo $item['minimum_stock']; ?> <?php echo $item['unit_name']; ?></small>
                                </div>
                                <span class="stock-qty">
                                    <?php echo $item['quantity_available']; ?> <?php echo $item['unit_name']; ?>
                                    <?php if ($item['quantity_available'] == 0): ?>
                                        <span class="badge bg-danger">Out of Stock</span>
                                    <?php elseif ($item['quantity_available'] <= $item['minimum_stock'] / 2): ?>
                                        <span class="badge bg-danger">Critical</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Low</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_MedicalSuppliesManagement.php'">Manage Inventory</button>
                    </div>
                </div>
            </div>

            <!-- PhilHealth Coverage & Dose Completion -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-hospital"></i> PhilHealth Coverage & Dose Completion
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <div class="p-3 bg-white rounded-3 text-center">
                                <div class="text-muted small">With PhilHealth</div>
                                <div class="h3 fw-bold text-success">
                                    <?php echo number_format($philhealthStats['Yes'] ?? 0); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-white rounded-3 text-center">
                                <div class="text-muted small">Without PhilHealth</div>
                                <div class="h3 fw-bold text-danger">
                                    <?php echo number_format($philhealthStats['No'] ?? 0); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="small text-muted">Dose Completion Rates</div>
                    <div class="row g-2 mt-1">
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 0</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose0_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 3</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose3_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 7</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose7_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 14</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose14_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 21</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose21_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 28</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose28_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- ============================================ -->
<!-- CHARTS INITIALIZATION -->
<!-- ============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Weekly Vaccination Trend Chart
    const weeklyData = <?php echo json_encode($weeklyTrend); ?>;
    if (weeklyData.length > 0) {
        const ctx = document.getElementById('weeklyChart').getContext('2d');
        const labels = weeklyData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const values = weeklyData.map(item => item.count);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Vaccinations',
                    data: values,
                    backgroundColor: 'rgba(43, 58, 140, 0.2)',
                    borderColor: '#2B3A8C',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#2B3A8C',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
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
    }

    // Bite Category Distribution Chart
    const categoryData = <?php echo json_encode($biteCategories); ?>;
    if (categoryData.length > 0) {
        const ctx2 = document.getElementById('categoryChart').getContext('2d');
        const labels = categoryData.map(item => item.bite_category || 'Unknown');
        const values = categoryData.map(item => item.count);
        const colors = ['#2B3A8C', '#F21D2F', '#28a745', '#ffc107', '#17a2b8', '#6f42c1'];
        
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, values.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>