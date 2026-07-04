<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a nurse
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 3 // Assuming role_id 3 is for Nurse
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
    $username = $userData['username'] ?? 'Nurse';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Fetch statistics for the nurse's branch
$stats = [];

// Patient waiting (patients with ongoing cases that are not completed)
$waitingQuery = "SELECT COUNT(DISTINCT p.patient_id) as waiting 
                 FROM patients p 
                 JOIN animal_bite_cases abc ON p.patient_id = abc.patient_id 
                 WHERE abc.branch_id = ? AND abc.case_status = 'Ongoing'";
$stmt = $conn->prepare($waitingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$waitingResult = $stmt->get_result();
$stats['patient_waiting'] = $waitingResult->fetch_assoc()['waiting'] ?? 0;

// Ongoing cases
$ongoingQuery = "SELECT COUNT(*) as ongoing 
                 FROM animal_bite_cases 
                 WHERE branch_id = ? AND case_status = 'Ongoing'";
$stmt = $conn->prepare($ongoingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$ongoingResult = $stmt->get_result();
$stats['ongoing_cases'] = $ongoingResult->fetch_assoc()['ongoing'] ?? 0;

// Vaccinations today
$todayQuery = "SELECT COUNT(*) as today_vaccinations 
               FROM vaccination_records 
               WHERE branch_id = ? AND DATE(date_administered) = CURDATE()";
$stmt = $conn->prepare($todayQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$todayResult = $stmt->get_result();
$stats['today_vaccinations'] = $todayResult->fetch_assoc()['today_vaccinations'] ?? 0;

// Fetch today's schedule (vaccinations scheduled for today)
$scheduleQuery = "SELECT v.*, p.full_name 
                  FROM vaccination_records v
                  JOIN patients p ON v.patient_id = p.patient_id
                  WHERE v.branch_id = ? 
                  AND DATE(v.next_schedule) = CURDATE() 
                  AND v.vaccination_status = 'Scheduled'
                  ORDER BY v.next_schedule ASC
                  LIMIT 5";
$stmt = $conn->prepare($scheduleQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$scheduleResult = $stmt->get_result();
$schedules = [];
while ($row = $scheduleResult->fetch_assoc()) {
    $schedules[] = $row;
}

// Fetch follow-up due (cases that need follow-up)
$followupQuery = "SELECT abc.case_id, p.full_name, abc.date_of_bite, 
                  DATEDIFF(CURDATE(), abc.date_of_bite) as days_since_bite
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
    <!-- Reusable Sidebar CSS -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS
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
        .followup-item .followup-days {
            font-size: 13px;
            color: #6c757d;
            margin-left: auto;
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

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 20px 10px;
            color: #999;
        }
        .empty-state i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
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
            <li><a class="active" href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
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

        <!-- STAT ROW: 3 cards -->
        <div class="row g-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Patient Waiting</div>
                    <div class="stat-number"><?php echo number_format($stats['patient_waiting']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Ongoing Cases</div>
                    <div class="stat-number"><?php echo number_format($stats['ongoing_cases']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Vaccinations Today</div>
                    <div class="stat-number"><?php echo number_format($stats['today_vaccinations']); ?></div>
                </div>
            </div>
        </div>

        <!-- SCHEDULE & FOLLOW-UP ROW -->
        <div class="row g-4 mt-2">

            <!-- Today's Schedule -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">Today's Schedule</div>

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
                                        if ($schedule['next_schedule']) {
                                            echo date('h:i A', strtotime($schedule['next_schedule']));
                                        } else {
                                            echo '--:--';
                                        }
                                        ?>
                                    </td>
                                    <td class="activity-col">
                                        <?php echo htmlspecialchars($schedule['full_name']); ?>
                                        <span class="sub-activity">
                                            Vaccination - Dose <?php echo $schedule['dose_number']; ?>
                                            <?php if ($schedule['is_final_dose']): ?>
                                                (Final Dose)
                                            <?php endif; ?>
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
                    <div class="section-title">Follow-up Due</div>

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
                                </span>
                                <span class="followup-days">
                                    <?php echo $followup['days_since_bite']; ?> days ago
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_Patients.php'">View All</button>
                    </div>
                </div>
            </div>

        </div> <!-- /row -->

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>