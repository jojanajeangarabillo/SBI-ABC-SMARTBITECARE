<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is branch admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2 // Assuming role_id 2 is for branch admin
) {
    header("Location: login.php");
    exit();
}

// Get user branch information
$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';

// Fetch user's branch info
$userQuery = "SELECT u.branch_id, b.branch_name 
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
}

// If no branch assigned, show error or redirect
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
    // You might want to handle this case differently
}

// Fetch dynamic statistics for the branch
$stats = [];

// Total patients for this branch (using branch_id from patients table)
$patientQuery = "SELECT COUNT(DISTINCT p.patient_id) as total 
                 FROM patients p 
                 WHERE p.branch_id = ?";
$stmt = $conn->prepare($patientQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$patientResult = $stmt->get_result();
$stats['total_patients'] = $patientResult->fetch_assoc()['total'] ?? 0;

// Total animal bite cases for this branch
$totalCasesQuery = "SELECT COUNT(*) as total 
                    FROM animal_bite_cases 
                    WHERE branch_id = ?";
$stmt = $conn->prepare($totalCasesQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$totalCasesResult = $stmt->get_result();
$stats['total_cases'] = $totalCasesResult->fetch_assoc()['total'] ?? 0;

// Ongoing cases
$ongoingQuery = "SELECT COUNT(*) as ongoing 
                 FROM animal_bite_cases 
                 WHERE branch_id = ? AND case_status = 'Ongoing'";
$stmt = $conn->prepare($ongoingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$ongoingResult = $stmt->get_result();
$stats['ongoing_cases'] = $ongoingResult->fetch_assoc()['ongoing'] ?? 0;

// Completed cases
$completedQuery = "SELECT COUNT(*) as completed 
                   FROM animal_bite_cases 
                   WHERE branch_id = ? AND case_status = 'Completed'";
$stmt = $conn->prepare($completedQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$completedResult = $stmt->get_result();
$stats['completed_cases'] = $completedResult->fetch_assoc()['completed'] ?? 0;

// Total inventory items for this branch
$totalItemsQuery = "SELECT COUNT(DISTINCT s.item_id) as total 
                    FROM inventory_stocks s 
                    WHERE s.branch_id = ?";
$stmt = $conn->prepare($totalItemsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$totalItemsResult = $stmt->get_result();
$stats['total_items'] = $totalItemsResult->fetch_assoc()['total'] ?? 0;

// Low stocks (with proper branch filtering)
$lowStockQuery = "SELECT COUNT(*) as low_stock 
                  FROM inventory_stocks s 
                  JOIN inventory_items i ON s.item_id = i.item_id 
                  WHERE s.branch_id = ? 
                  AND s.quantity_available < i.minimum_stock";
$stmt = $conn->prepare($lowStockQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockResult = $stmt->get_result();
$stats['low_stocks'] = $lowStockResult->fetch_assoc()['low_stock'] ?? 0;

// Expiring stocks (within 30 days) - branch specific
$expiringQuery = "SELECT COUNT(*) as expiring 
                  FROM inventory_stocks 
                  WHERE branch_id = ? 
                  AND expiration_date IS NOT NULL 
                  AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
                  AND expiration_date >= CURDATE()";
$stmt = $conn->prepare($expiringQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$expiringResult = $stmt->get_result();
$stats['expiring_stocks'] = $expiringResult->fetch_assoc()['expiring'] ?? 0;

// Total vaccinations for this branch
$vaccinationQuery = "SELECT COUNT(*) as total 
                     FROM vaccination_records 
                     WHERE branch_id = ?";
$stmt = $conn->prepare($vaccinationQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$vaccinationResult = $stmt->get_result();
$stats['total_vaccinations'] = $vaccinationResult->fetch_assoc()['total'] ?? 0;

// Completed vaccinations for this branch
$completedVaccQuery = "SELECT COUNT(*) as completed 
                       FROM vaccination_records 
                       WHERE branch_id = ? 
                       AND vaccination_status = 'Completed'";
$stmt = $conn->prepare($completedVaccQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$completedVaccResult = $stmt->get_result();
$stats['completed_vaccinations'] = $completedVaccResult->fetch_assoc()['completed'] ?? 0;

// Scheduled vaccinations for this branch
$scheduledVaccQuery = "SELECT COUNT(*) as scheduled 
                       FROM vaccination_records 
                       WHERE branch_id = ? 
                       AND vaccination_status = 'Scheduled' 
                       AND scheduled_date >= CURDATE()";
$stmt = $conn->prepare($scheduledVaccQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$scheduledVaccResult = $stmt->get_result();
$stats['scheduled_vaccinations'] = $scheduledVaccResult->fetch_assoc()['scheduled'] ?? 0;

// Missed vaccinations for this branch
$missedVaccQuery = "SELECT COUNT(*) as missed 
                    FROM vaccination_records 
                    WHERE branch_id = ? 
                    AND vaccination_status = 'Missed'";
$stmt = $conn->prepare($missedVaccQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$missedVaccResult = $stmt->get_result();
$stats['missed_vaccinations'] = $missedVaccResult->fetch_assoc()['missed'] ?? 0;

// Fetch recent prediction alerts for this branch
$alerts = [];
$alertQuery = "SELECT pr.*, i.item_name 
               FROM prediction_results pr 
               JOIN inventory_items i ON pr.item_id = i.item_id 
               WHERE pr.branch_id = ? 
               AND pr.prediction_status = 'High Risk' 
               ORDER BY pr.prediction_date DESC 
               LIMIT 5";
$stmt = $conn->prepare($alertQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$alertResult = $stmt->get_result();
while ($row = $alertResult->fetch_assoc()) {
    $alerts[] = $row;
}

// Fetch recent activities for this branch
$activities = [];
$activityQuery = "SELECT * FROM audit_logs 
                  WHERE branch_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT 5";
$stmt = $conn->prepare($activityQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$activityResult = $stmt->get_result();
while ($row = $activityResult->fetch_assoc()) {
    $activities[] = $row;
}

// Fetch monthly patient trend (last 6 months) - branch specific
$patientTrend = [];
$trendQuery = "SELECT DATE_FORMAT(p.created_at, '%Y-%m') as month, 
                      COUNT(p.patient_id) as count 
               FROM patients p 
               WHERE p.branch_id = ? 
               AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
               GROUP BY DATE_FORMAT(p.created_at, '%Y-%m') 
               ORDER BY month ASC";
$stmt = $conn->prepare($trendQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$trendResult = $stmt->get_result();
while ($row = $trendResult->fetch_assoc()) {
    $patientTrend[] = $row;
}

// Fetch monthly case trend (last 6 months) - branch specific
$caseTrend = [];
$caseTrendQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
                    COUNT(*) as count 
                   FROM animal_bite_cases 
                   WHERE branch_id = ? 
                   AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
                   GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
                   ORDER BY month ASC";
$stmt = $conn->prepare($caseTrendQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$caseTrendResult = $stmt->get_result();
while ($row = $caseTrendResult->fetch_assoc()) {
    $caseTrend[] = $row;
}

// Fetch top 5 low stock items for this branch
$lowStockItems = [];
$lowStockItemsQuery = "SELECT i.item_name, s.quantity_available, i.minimum_stock 
                       FROM inventory_stocks s 
                       JOIN inventory_items i ON s.item_id = i.item_id 
                       WHERE s.branch_id = ? 
                       AND s.quantity_available < i.minimum_stock 
                       ORDER BY (s.quantity_available / i.minimum_stock) ASC 
                       LIMIT 5";
$stmt = $conn->prepare($lowStockItemsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockItemsResult = $stmt->get_result();
while ($row = $lowStockItemsResult->fetch_assoc()) {
    $lowStockItems[] = $row;
}

// Fetch recent cases for this branch
$recentCases = [];
$recentCasesQuery = "SELECT abc.case_id, p.full_name, abc.case_status, abc.created_at 
                     FROM animal_bite_cases abc 
                     JOIN patients p ON abc.patient_id = p.patient_id 
                     WHERE abc.branch_id = ? 
                     ORDER BY abc.created_at DESC 
                     LIMIT 5";
$stmt = $conn->prepare($recentCasesQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentCasesResult = $stmt->get_result();
while ($row = $recentCasesResult->fetch_assoc()) {
    $recentCases[] = $row;
}

// Fetch user info for profile display
$userInfoQuery = "SELECT username, email FROM users WHERE user_id = ?";
$stmt = $conn->prepare($userInfoQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userInfoResult = $stmt->get_result();
$userInfo = $userInfoResult->fetch_assoc();
$username = $userInfo['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Branch Admin Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <!-- Chart.js for dynamic charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
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
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
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
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dashboard {
            padding: 35px;
        }

        /* New stat card design based on the image */
        .stat-card {
            background: #ECEEF7;
            border-radius: 12px;
            padding: 24px 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,.10);
        }

        .stat-number {
            font-size: 42px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .stat-number.success {
            color: #28a745;
        }

        .stat-number.warning {
            color: #dc3545;
        }

        .stat-number.info {
            color: #17a2b8;
        }

        .stat-number.primary {
            color: var(--primary);
        }

        .stat-label {
            font-size: 16px;
            font-weight: 600;
            color: #2B3A8C;
            margin-bottom: 2px;
        }

        .stat-description {
            font-size: 13px;
            color: #888;
            font-weight: 400;
            margin-top: 2px;
        }

        .stat-card .stat-icon {
            font-size: 28px;
            opacity: 0.2;
            position: absolute;
            right: 20px;
            top: 20px;
        }

        .stat-card {
            position: relative;
        }

        .large-card {
            background: #ECEEF7;
            border-radius: 18px;
            padding: 20px;
            margin-top: 25px;
            box-shadow: 0 3px 8px rgba(0,0,0,.08);
            height: 100%;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .section-title small {
            font-size: 14px;
            font-weight: 400;
            color: #666;
        }

        .chart-container {
            position: relative;
            height: 220px;
            width: 100%;
        }

        .btn-custom {
            background: var(--primary);
            color: white;
            border-radius: 8px;
            padding: 8px 18px;
            border: none;
            transition: background 0.2s;
        }

        .btn-custom:hover {
            background: #1d2863;
            color: white;
        }

        .btn-custom-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 8px;
            padding: 8px 18px;
            transition: all 0.2s;
        }

        .btn-custom-outline:hover {
            background: var(--primary);
            color: white;
        }

        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid rgba(43, 58, 140, 0.1);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item i {
            color: var(--accent);
            margin-right: 10px;
            width: 20px;
        }

        .alert-item {
            padding: 10px 0;
            border-bottom: 1px solid rgba(43, 58, 140, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-item:last-child {
            border-bottom: none;
        }

        .alert-item i {
            color: #ffc107;
            font-size: 20px;
        }

        .alert-item .alert-high {
            color: #dc3545;
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.ongoing {
            background: #ffc107;
            color: #000;
        }

        .status-badge.completed {
            background: #28a745;
            color: #fff;
        }

        .status-badge.scheduled {
            background: #17a2b8;
            color: #fff;
        }

        .status-badge.missed {
            background: #dc3545;
            color: #fff;
        }

        @media(max-width: 991px) {
            .main {
                margin-left: 90px;
            }
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
            </div>
            <div class="system-name">
                Smart Bite Care
            </div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a class="active" href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>
                <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="logout.php"> <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main">
        <div class="topbar">
            <h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
            <div class="profile"> 
                <i class="bi bi-person-circle"></i>
                <?php echo htmlspecialchars($username); ?> 
                <i class="bi bi-caret-down-fill"></i> 
            </div>
        </div>

        <div class="dashboard">
            <!-- Statistics Cards Row 1 - Based on the image design -->
            <div class="row g-4">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number primary"><?php echo number_format($stats['total_patients']); ?></div>
                        <div class="stat-label">Total Patients</div>
                        <div class="stat-description">Registered patients</div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number primary"><?php echo number_format($stats['total_cases']); ?></div>
                        <div class="stat-label">Total Cases</div>
                        <div class="stat-description">All animal bite cases</div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number warning"><?php echo number_format($stats['ongoing_cases']); ?></div>
                        <div class="stat-label">Ongoing Cases</div>
                        <div class="stat-description">Active treatment cases</div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number success"><?php echo number_format($stats['completed_cases']); ?></div>
                        <div class="stat-label">Completed Cases</div>
                        <div class="stat-description">Fully resolved cases</div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards Row 2 -->
            <div class="row g-4 mt-2">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number info"><?php echo number_format($stats['total_items']); ?></div>
                        <div class="stat-label">Inventory Items</div>
                        <div class="stat-description">Unique stock items</div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number <?php echo $stats['low_stocks'] > 0 ? 'warning' : 'primary'; ?>">
                            <?php echo number_format($stats['low_stocks']); ?>
                        </div>
                        <div class="stat-label">Low Stock Items</div>
                        <div class="stat-description">Needs reordering</div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number <?php echo $stats['expiring_stocks'] > 0 ? 'warning' : 'primary'; ?>">
                            <?php echo number_format($stats['expiring_stocks']); ?>
                        </div>
                        <div class="stat-label">Expiring Soon</div>
                        <div class="stat-description">Within 30 days</div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="stat-number info"><?php echo number_format($stats['scheduled_vaccinations']); ?></div>
                        <div class="stat-label">Scheduled Vaccinations</div>
                        <div class="stat-description">Upcoming appointments</div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards Row 3 - Vaccination Stats -->
            <div class="row g-4 mt-2">
                <div class="col-lg-4 col-md-6">
                    <div class="stat-card" style="border-left: 4px solid #28a745;">
                        <div class="stat-number success"><?php echo number_format($stats['completed_vaccinations']); ?></div>
                        <div class="stat-label">Completed Vaccinations</div>
                        <div class="stat-description">Successfully completed doses</div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="stat-card" style="border-left: 4px solid #dc3545;">
                        <div class="stat-number warning"><?php echo number_format($stats['missed_vaccinations']); ?></div>
                        <div class="stat-label">Missed Vaccinations</div>
                        <div class="stat-description">Missed scheduled appointments</div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6">
                    <div class="stat-card" style="border-left: 4px solid #17a2b8;">
                        <div class="stat-number info"><?php echo number_format($stats['total_vaccinations']); ?></div>
                        <div class="stat-label">Total Vaccinations</div>
                        <div class="stat-description">All vaccination records</div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-4 mt-2">
                <div class="col-lg-6">
                    <div class="large-card">
                        <div class="section-title">
                            Patient Trend <small>(Last 6 Months)</small>
                        </div>
                        <div class="chart-container">
                            <canvas id="patientTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="large-card">
                        <div class="section-title">
                            Case Trend <small>(Last 6 Months)</small>
                        </div>
                        <div class="chart-container">
                            <canvas id="caseTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Row -->
            <div class="row g-4 mt-2">
                <div class="col-lg-4">
                    <div class="large-card">
                        <div class="section-title">
                            Low Stock Items
                            <span class="badge bg-danger float-end"><?php echo count($lowStockItems); ?></span>
                        </div>
                        <?php if (empty($lowStockItems)): ?>
                            <div class="alert-item">
                                <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                                All items are well-stocked.
                            </div>
                        <?php else: ?>
                            <?php foreach ($lowStockItems as $item): ?>
                                <div class="alert-item">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                        <br>
                                        <small>Stock: <?php echo $item['quantity_available']; ?> / Min: <?php echo $item['minimum_stock']; ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-end mt-3">
                            <button class="btn btn-custom" onclick="window.location.href='BranchAdmin_MedicalSupplies.php'">
                                View All Supplies
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="large-card">
                        <div class="section-title">
                            Prediction Alerts
                            <span class="badge bg-warning float-end"><?php echo count($alerts); ?></span>
                        </div>
                        <?php if (empty($alerts)): ?>
                            <div class="alert-item">
                                <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                                No high-risk predictions at this time.
                            </div>
                        <?php else: ?>
                            <?php foreach ($alerts as $alert): ?>
                                <div class="alert-item">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <div>
                                        <strong><?php echo htmlspecialchars($alert['item_name']); ?></strong>
                                        <br>
                                        <small class="alert-high">Risk Score: <?php echo $alert['probability_score']; ?>%</small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-end mt-3">
                            <button class="btn btn-custom" onclick="window.location.href='BranchAdmin_PredictionModule.php'">
                                View All Predictions
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="large-card">
                        <div class="section-title">
                            Recent Cases
                            <span class="badge bg-primary float-end">New</span>
                        </div>
                        <?php if (empty($recentCases)): ?>
                            <div class="activity-item">
                                <i class="bi bi-square-fill"></i>
                                No recent cases
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentCases as $case): ?>
                                <div class="activity-item">
                                    <i class="bi bi-person"></i>
                                    <div>
                                        <strong><?php echo htmlspecialchars($case['full_name']); ?></strong>
                                        <br>
                                        <span class="status-badge <?php echo strtolower($case['case_status']); ?>">
                                            <?php echo $case['case_status']; ?>
                                        </span>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($case['created_at'])); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="text-end mt-3">
                            <button class="btn btn-custom" onclick="window.location.href='BranchAdmin_PatientMonitoring.php'">
                                View All Cases
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities Row -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="large-card">
                        <div class="section-title">
                            Recent Activities
                            <small>(Branch Activities)</small>
                        </div>
                        <div class="row">
                            <?php if (empty($activities)): ?>
                                <div class="col-12">
                                    <div class="activity-item">
                                        <i class="bi bi-square-fill"></i>
                                        No recent activities
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): ?>
                                    <div class="col-md-6">
                                        <div class="activity-item">
                                            <i class="bi bi-square-fill"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="bi bi-clock"></i> 
                                                    <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                                                    <?php if ($activity['module']): ?>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($activity['module']); ?></span>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-end mt-3">
                            <button class="btn btn-custom" onclick="window.location.href='BranchAdmin_AuditLogs.php'">
                                View All Activities
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Patient Trend Chart
        const patientCtx = document.getElementById('patientTrendChart').getContext('2d');
        const patientLabels = <?php echo json_encode(array_column($patientTrend, 'month')); ?>;
        const patientData = <?php echo json_encode(array_column($patientTrend, 'count')); ?>;

        new Chart(patientCtx, {
            type: 'line',
            data: {
                labels: patientLabels.length ? patientLabels : ['No Data'],
                datasets: [{
                    label: 'New Patients',
                    data: patientData.length ? patientData : [0],
                    borderColor: '#2B3A8C',
                    backgroundColor: 'rgba(43, 58, 140, 0.1)',
                    tension: 0.4,
                    fill: true
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

        // Case Trend Chart
        const caseCtx = document.getElementById('caseTrendChart').getContext('2d');
        const caseLabels = <?php echo json_encode(array_column($caseTrend, 'month')); ?>;
        const caseData = <?php echo json_encode(array_column($caseTrend, 'count')); ?>;

        new Chart(caseCtx, {
            type: 'bar',
            data: {
                labels: caseLabels.length ? caseLabels : ['No Data'],
                datasets: [{
                    label: 'New Cases',
                    data: caseData.length ? caseData : [0],
                    backgroundColor: 'rgba(242, 29, 47, 0.7)',
                    borderColor: '#F21D2F',
                    borderWidth: 2
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

        // Auto-refresh every 60 seconds (optional)
        // setTimeout(() => location.reload(), 60000);
    </script>
</body>
</html>