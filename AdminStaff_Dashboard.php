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
    LEFT JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id AND v.is_archived = 0
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

// Patients who checked in today and are still waiting for the Nurse
$visitQueueQuery = "
    SELECT COUNT(*) AS count
    FROM patient_visits
    WHERE branch_id = ?
      AND visit_date = CURDATE()
      AND workflow_status IN ('Checked In', 'Waiting for Nurse')
";
$stmt = $conn->prepare($visitQueueQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$visitQueueCount = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);

// Nurse-signed charts waiting for Administrative Staff registry verification
$registryQueueQuery = "
    SELECT COUNT(*) AS count
    FROM patient_visits
    WHERE branch_id = ?
      AND workflow_status = 'For Registry'
";
$stmt = $conn->prepare($registryQueueQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$registryQueueCount = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);

// Today's scheduled vaccinations
$todayScheduleQuery = "
    SELECT COUNT(DISTINCT case_id) AS count
    FROM vaccination_records
    WHERE branch_id = ?
      AND scheduled_date = CURDATE()
      AND vaccination_status = 'Scheduled'
      AND is_archived = 0
";
$stmt = $conn->prepare($todayScheduleQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$todayScheduleCount = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);

// Overdue or explicitly missed vaccination schedules
$overdueScheduleQuery = "
    SELECT COUNT(DISTINCT case_id) AS count
    FROM vaccination_records
    WHERE branch_id = ?
      AND scheduled_date < CURDATE()
      AND vaccination_status IN ('Scheduled', 'Missed')
      AND is_archived = 0
";
$stmt = $conn->prepare($overdueScheduleQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$overdueScheduleCount = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);

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

// 3. Get PhilHealth Patients - FIXED: Use has_philhealth instead of philhealth_number
$philhealthQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    WHERE c.branch_id = ?
    AND ph.has_philhealth = 'Yes'
    AND ph.is_archived = 0
";
$stmt = $conn->prepare($philhealthQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$philhealthResult = $stmt->get_result();
$philhealthCount = $philhealthResult->fetch_assoc()['count'] ?? 0;

// PhilHealth records returned by the main branch and needing correction
$returnedPhilhealthQuery = "
    SELECT COUNT(*) AS count
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    WHERE c.branch_id = ?
      AND ph.has_philhealth = 'Yes'
      AND ph.is_archived = 0
      AND ph.status = 'Returned for Correction'
";
$stmt = $conn->prepare($returnedPhilhealthQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$returnedPhilhealthCount = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);

// 4. Get Follow-up Calendar Data (next 30 days)
$calendarData = [];
$followUpCalendarQuery = "
    SELECT 
        DATE(v.scheduled_date) as schedule_date,
        COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.scheduled_date IS NOT NULL
    AND v.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND v.vaccination_status = 'Scheduled'
    AND v.is_archived = 0
    GROUP BY DATE(v.scheduled_date)
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
    AND ph.has_philhealth = 'Yes'
    AND ph.is_archived = 0
    GROUP BY ph.status
";
$stmt = $conn->prepare($philhealthStatusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$statusResult = $stmt->get_result();

$philhealthStatus = [
    'For Writing' => 0,
    'For Screening' => 0,
    'Ready for Main Branch' => 0,
    'Sent to Main Branch' => 0,
    'Returned for Correction' => 0,
    'Main Branch / Resolved' => 0
];

while ($row = $statusResult->fetch_assoc()) {
    $status = $row['status'] ?? 'For Writing';
    if (array_key_exists($status, $philhealthStatus)) {
        $philhealthStatus[$status] += (int)$row['count'];
    } else {
        $philhealthStatus['Main Branch / Resolved'] += (int)$row['count'];
    }
}

$totalPhilhealthRecords = array_sum($philhealthStatus);

// 6. Get Recent Patient Records (for quick view)
$recentPatientsQuery = "
    SELECT 
        c.case_id,
        p.full_name as patient_name,
        DATE(COALESCE(c.date_of_bite, c.created_at)) as admission_date,
        c.case_number as case_no,
        c.case_status,
        (
            SELECT ph.status
            FROM philhealth_records ph
            WHERE ph.case_id = c.case_id
              AND ph.is_archived = 0
            ORDER BY ph.updated_at DESC
            LIMIT 1
        ) as philhealth_status
    FROM animal_bite_cases c
    JOIN patients p ON c.patient_id = p.patient_id
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
// MySQL 8/9 with ONLY_FULL_GROUP_BY requires every non-aggregated
// selected expression to also appear in the GROUP BY clause.
$monthlyTrendQuery = "
    SELECT 
        DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%b') AS month_name,
        DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%Y-%m') AS month_sort_key,
        COUNT(*) AS count
    FROM animal_bite_cases c
    WHERE c.branch_id = ?
      AND COALESCE(c.date_of_bite, c.created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY
        DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%Y-%m'),
        DATE_FORMAT(COALESCE(c.date_of_bite, c.created_at), '%b')
    ORDER BY month_sort_key ASC
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
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .profile-button {
            border: 0;
            background: transparent;
            padding: 10px 12px;
            border-radius: 10px;
            transition: background-color 0.2s ease;
        }

    
        .profile-button::after {
            margin-left: 4px;
        }

        .profile-role {
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
            margin-left: 4px;
        }

        .profile-menu {
            min-width: 220px;
            padding: 8px;
            margin-top: 10px !important;
            border: 1px solid #e4e8f1;
            border-radius: 12px;
            box-shadow: 0 10px 28px rgba(32, 45, 110, 0.14);
        }

        .profile-menu .dropdown-header {
            padding: 8px 12px 10px;
            color: #6c757d;
            font-size: 12px;
        }

        .profile-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #24315f;
            font-weight: 500;
        }

        .profile-menu .dropdown-item:hover,
        .profile-menu .dropdown-item:focus {
            color: var(--primary);
            background: #f1f3fb;
        }

        .profile-menu .dropdown-item.text-danger:hover,
        .profile-menu .dropdown-item.text-danger:focus {
            color: #b42332 !important;
            background: #fff0f2;
        }

        .content {
            padding: 35px 35px 40px;
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

/* =========================================================
   GLOBAL LOGOUT CONFIRMATION MODAL 
   ========================================================= */

.confirm-modal .modal-dialog {
    max-width: 500px !important;
    width: calc(100% - 30px);
    margin: 1.75rem auto;
}

.confirm-modal .modal-content {
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 20px !important;
    background: #fff !important;
    box-shadow: 0 20px 55px rgba(31, 45, 110, 0.20) !important;
}

.confirm-modal .modal-header {
    display: block !important;
    padding: 26px 24px 6px !important;
    border: 0 !important;
    background: #fff !important;
    text-align: center !important;
}

.confirm-modal .modal-icon {
    width: 64px !important;
    height: 64px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 14px !important;

    color: #fff !important;
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    border-radius: 50% !important;

    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
    font-size: 27px !important;
}

.confirm-modal .modal-icon.logout-icon {
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
}

.confirm-modal .modal-title {
    margin: 0 !important;
    color: #283a7a !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    line-height: 1.3 !important;
}

.confirm-modal .modal-body {
    padding: 6px 30px 22px !important;
    background: #fff !important;
    color: #7a879e !important;
    text-align: center !important;
}

.confirm-modal .modal-body p {
    margin: 0 !important;
    color: #7a879e !important;
    font-size: 17px !important;
    line-height: 1.45 !important;
}

.confirm-modal .modal-footer {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    padding: 0 24px 26px !important;
    border: 0 !important;
    background: #fff !important;
}

.confirm-modal .modal-footer .btn {
    min-height: 50px !important;
    margin: 0 !important;
    border-radius: 10px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.confirm-modal .btn-light,
.confirm-modal .btn-cancel {
    color: #111 !important;
    background: #f8f9fa !important;
    border: 1px solid #d9dfe8 !important;
}

.confirm-modal .btn-light:hover,
.confirm-modal .btn-cancel:hover {
    background: #eef0f3 !important;
}

.confirm-modal .btn-danger,
.confirm-modal .btn-logout {
    color: #fff !important;
    background: #e83445 !important;
    border: 1px solid #e83445 !important;
    text-decoration: none !important;
}

.confirm-modal .btn-danger:hover,
.confirm-modal .btn-logout:hover {
    color: #fff !important;
    background: #d92839 !important;
    border-color: #d92839 !important;
}

/* Hide elements that are not part of the logout confirmation */
#logoutConfirmModal .confirmation-summary,
#logoutConfirmModal .confirmation-warning {
    display: none !important;
}

@media (max-width: 576px) {
    .confirm-modal .modal-dialog {
        width: calc(100% - 20px);
        margin: 10px auto;
    }

    .confirm-modal .modal-header {
        padding: 22px 18px 6px !important;
    }

    .confirm-modal .modal-icon {
        width: 58px !important;
        height: 58px !important;
        margin-bottom: 12px !important;
        font-size: 24px !important;
    }

    .confirm-modal .modal-title {
        font-size: 21px !important;
    }

    .confirm-modal .modal-body {
        padding: 6px 20px 18px !important;
    }

    .confirm-modal .modal-body p {
        font-size: 15px !important;
    }

    .confirm-modal .modal-footer {
        padding: 0 18px 20px !important;
    }

    .confirm-modal .modal-footer .btn {
        min-height: 46px !important;
        font-size: 15px !important;
    }
}
        /* Statistics */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            position: relative;
            display: block;
            overflow: hidden;
            background: #fff;
            border-radius: 16px;
            padding: 22px 24px;
            border-left: 5px solid var(--primary);
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            transition: all .25s ease;
            text-align: left;
            color: inherit;
            text-decoration: none;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,.12);
            color: inherit;
        }

        .stat-card:focus-visible {
            outline: 3px solid rgba(43,58,140,.25);
            outline-offset: 3px;
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
        .stat-card.visit-queue {
            border-left-color: #6f42c1;
        }
        .stat-card.registry-queue {
            border-left-color: #fd7e14;
        }
        .stat-card.returned-records {
            border-left-color: var(--danger);
        }

        .stat-icon {
            position: absolute;
            top: 18px;
            right: 22px;
            color: rgba(43,58,140,.13);
            font-size: 35px;
        }

        .stat-card.follow-up .stat-icon { color: rgba(242,29,47,.16); }
        .stat-card.new-patients .stat-icon { color: rgba(40,167,69,.16); }
        .stat-card.philhealth-patients .stat-icon { color: rgba(23,162,184,.18); }
        .stat-card.visit-queue .stat-icon { color: rgba(111,66,193,.17); }
        .stat-card.registry-queue .stat-icon { color: rgba(253,126,20,.17); }
        .stat-card.returned-records .stat-icon { color: rgba(220,53,69,.16); }

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

        .card-link-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 13px;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
        }

        .stat-card:hover .card-link-label i {
            transform: translateX(3px);
        }

        .card-link-label i {
            transition: transform .2s ease;
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

        .today-overview {
            display: grid;
            grid-template-columns: auto repeat(4, minmax(0, 1fr));
            align-items: stretch;
            margin-bottom: 25px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .today-heading {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 180px;
            padding: 18px 22px;
            color: #fff;
            background: var(--primary);
        }

        .today-heading strong {
            font-size: 15px;
        }

        .today-heading span {
            margin-top: 3px;
            color: rgba(255,255,255,.75);
            font-size: 11px;
        }

        .priority-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 16px 18px;
            color: #4f5a6d;
            border-right: 1px solid #edf0f4;
            text-decoration: none;
            transition: background .2s ease;
        }

        .priority-item:last-child {
            border-right: 0;
        }

        .priority-item:hover {
            color: #25324b;
            background: #f8f9fc;
        }

        .priority-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            color: var(--primary);
            background: #eef1ff;
            border-radius: 10px;
            font-size: 17px;
            flex-shrink: 0;
        }

        .priority-item.warning .priority-icon {
            color: #946200;
            background: #fff3cd;
        }

        .priority-item.danger .priority-icon {
            color: #a22632;
            background: #fde2e5;
        }

        .priority-copy strong {
            display: block;
            color: #25324b;
            font-size: 18px;
            line-height: 1;
        }

        .priority-copy span {
            display: block;
            margin-top: 4px;
            color: #7d8798;
            font-size: 11px;
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

        .calendar-table td.has-followups {
            cursor: pointer;
            background: #f7f9ff;
        }

        .calendar-table td.has-followups:hover {
            background: #edf1ff;
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
        .legend-box.ready { background: #59A14F; }
        .legend-box.sent { background: #76B7B2; }
        .legend-box.returned { background: #E15759; }
        .legend-box.resolved { background: #B07AA1; }

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
            display: inline-block;
            background: var(--primary);
            color: white;
            border: none;
            width: 75%;
            border-radius: 40px;
            font-weight: 600;
            padding: 10px;
            transition: .3s;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
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
            .today-overview {
                grid-template-columns: repeat(2, 1fr);
            }
            .today-heading {
                grid-column: 1 / -1;
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
            .refresh-btn span,
            .profile span {
                display: none;
            }
            .topbar-actions {
                gap: 8px;
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
            .today-overview {
                grid-template-columns: 1fr;
            }
            .today-heading {
                grid-column: auto;
            }
            .priority-item {
                border-right: 0;
                border-bottom: 1px solid #edf0f4;
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
                <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
                <li><a href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
                <li><a href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>

    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- TOP BAR -->
    <div class="topbar">
        <h3>Dashnboard</h3>
       <div class="dropdown">
            <button
                class="profile profile-button dropdown-toggle"
                type="button"
                id="superAdminProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-person-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'SUPER ADMIN', ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="profile-role">| Super Admin</span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end profile-menu" aria-labelledby="superAdminProfileMenu">
                <li>
                    <div class="dropdown-header">Account options</div>
                </li>
                <li>
                    <a class="dropdown-item" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill"></i>
                        <span>Change Password</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
               <li>
                        <a class="dropdown-item rounded-2 py-2 text-danger"
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#logoutConfirmModal">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                </li>
            </ul>
        </div>
    </div>

        <div class="dashboard-content">
            <!-- Statistics -->
            <div class="stats-container">
                <a href="AdminStaff_Calendar.php" class="stat-card follow-up" aria-label="Open follow-up calendar">
                    <i class="bi bi-calendar2-check-fill stat-icon"></i>
                    <h6>Follow Up Patients</h6>
                    <h1><?php echo $followUpCount; ?></h1>
                    <div class="stat-trend">Pending vaccinations &amp; follow-ups</div>
                    <span class="card-link-label">Open Calendar <i class="bi bi-arrow-right"></i></span>
                </a>

                <a href="AdminStaff_PatientRecord.php" class="stat-card new-patients" aria-label="Open patient records">
                    <i class="bi bi-person-plus-fill stat-icon"></i>
                    <h6>New Patients</h6>
                    <h1><?php echo $newPatientsCount; ?></h1>
                    <div class="stat-trend">Admitted this month</div>
                    <span class="card-link-label">View Patient Records <i class="bi bi-arrow-right"></i></span>
                </a>

                <a href="AdminStaff_PhilhealthWorkflow.php" class="stat-card philhealth-patients" aria-label="Open PhilHealth workflow">
                    <i class="bi bi-file-medical-fill stat-icon"></i>
                    <h6>PhilHealth Patients</h6>
                    <h1><?php echo $philhealthCount; ?></h1>
                    <div class="stat-trend">With PhilHealth coverage</div>
                    <span class="card-link-label">Open PhilHealth <i class="bi bi-arrow-right"></i></span>
                </a>

                <a href="AdminStaff_VisitQueue.php" class="stat-card visit-queue" aria-label="Open today's visit check-in queue">
                    <i class="bi bi-person-check-fill stat-icon"></i>
                    <h6>Waiting for Nurse</h6>
                    <h1><?php echo $visitQueueCount; ?></h1>
                    <div class="stat-trend">Checked-in patients waiting today</div>
                    <span class="card-link-label">Open Visit Queue <i class="bi bi-arrow-right"></i></span>
                </a>

                <a href="AdminStaff_Registry.php" class="stat-card registry-queue" aria-label="Open registry verification queue">
                    <i class="bi bi-journal-check stat-icon"></i>
                    <h6>For Registry</h6>
                    <h1><?php echo $registryQueueCount; ?></h1>
                    <div class="stat-trend">Nurse-signed charts for verification</div>
                    <span class="card-link-label">Open Registry Queue <i class="bi bi-arrow-right"></i></span>
                </a>

                <a href="AdminStaff_PhilhealthWorkflow.php" class="stat-card returned-records" aria-label="Open returned PhilHealth records">
                    <i class="bi bi-arrow-counterclockwise stat-icon"></i>
                    <h6>Returned for Correction</h6>
                    <h1><?php echo $returnedPhilhealthCount; ?></h1>
                    <div class="stat-trend">PhilHealth records needing attention</div>
                    <span class="card-link-label">Review Corrections <i class="bi bi-arrow-right"></i></span>
                </a>
            </div>

            <div class="today-overview">
                <div class="today-heading">
                    <strong>Today's Priorities</strong>
                    <span><?php echo date('F d, Y'); ?></span>
                </div>
                <a href="AdminStaff_Calendar.php" class="priority-item">
                    <span class="priority-icon"><i class="bi bi-calendar-event-fill"></i></span>
                    <span class="priority-copy"><strong><?php echo $todayScheduleCount; ?></strong><span>Vaccinations due today</span></span>
                </a>
                <a href="AdminStaff_Calendar.php" class="priority-item <?php echo $overdueScheduleCount > 0 ? 'danger' : ''; ?>">
                    <span class="priority-icon"><i class="bi bi-calendar-x-fill"></i></span>
                    <span class="priority-copy"><strong><?php echo $overdueScheduleCount; ?></strong><span>Overdue or missed schedules</span></span>
                </a>
                <a href="AdminStaff_VisitQueue.php" class="priority-item">
                    <span class="priority-icon"><i class="bi bi-person-lines-fill"></i></span>
                    <span class="priority-copy"><strong><?php echo $visitQueueCount; ?></strong><span>Waiting for Nurse today</span></span>
                </a>
                <a href="AdminStaff_Registry.php" class="priority-item <?php echo $registryQueueCount > 0 ? 'warning' : ''; ?>">
                    <span class="priority-icon"><i class="bi bi-journal-medical"></i></span>
                    <span class="priority-copy"><strong><?php echo $registryQueueCount; ?></strong><span>Registry verifications</span></span>
                </a>
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
                                    <span class="legend-box ready"></span>
                                    Ready for Main Branch
                                </div>
                                <strong><?php echo $philhealthStatus['Ready for Main Branch']; ?></strong>
                            </div>

                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box sent"></span>
                                    Sent to Main Branch
                                </div>
                                <strong><?php echo $philhealthStatus['Sent to Main Branch']; ?></strong>
                            </div>

                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box returned"></span>
                                    Returned for Correction
                                </div>
                                <strong><?php echo $philhealthStatus['Returned for Correction']; ?></strong>
                            </div>

                            <div class="legend-item">
                                <div class="legend-left">
                                    <span class="legend-box resolved"></span>
                                    Main Branch / Resolved
                                </div>
                                <strong><?php echo $philhealthStatus['Main Branch / Resolved']; ?></strong>
                            </div>
                        </div>
                    </div>

                    <div class="total-count">
                        Total: <?php echo $totalPhilhealthRecords; ?>
                    </div>

                    <div class="text-center mt-3">
                        <a href="AdminStaff_PhilhealthWorkflow.php" class="dashboard-btn">View All PhilHealth Records</a>
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

    <div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon"><i class="bi bi-box-arrow-right"></i></div>
                <h2 class="modal-title" id="logoutConfirmModalLabel">Log out of Smart Bite Care?</h2>
            </div>
            <div class="modal-body">
                <p class="mb-0">You will need to enter your account credentials again to access Super Admin controls.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-danger d-flex align-items-center justify-content-center">
                    <i class="bi bi-box-arrow-right me-1"></i>Yes, Log Out
                </a>
            </div>
        </div>
    </div>
</div>

    <div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2 class="modal-title" id="logoutConfirmModalLabel">
                    Log out of Smart Bite Care?
                </h2>
            </div>

            <div class="modal-body">
                <p class="mb-0">
                    Make sure you have saved any unfinished work before leaving your account.
                </p>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="logout.php"
                    class="btn btn-danger d-flex align-items-center justify-content-center"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>
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
            <?php echo $philhealthStatus['Ready for Main Branch']; ?>,
            <?php echo $philhealthStatus['Sent to Main Branch']; ?>,
            <?php echo $philhealthStatus['Returned for Correction']; ?>,
            <?php echo $philhealthStatus['Main Branch / Resolved']; ?>
        ];

        const labels = [
            'For Writing',
            'For Screening',
            'Ready for Main Branch',
            'Sent to Main Branch',
            'Returned for Correction',
            'Main Branch / Resolved'
        ];
        const colors = ['#4E79A7', '#F28E2B', '#59A14F', '#76B7B2', '#E15759', '#B07AA1'];

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
                    cell.classList.add("has-followups");
                    cell.title = "Open follow-ups for " + key;
                    cell.onclick = function() {
                        window.location.href = "AdminStaff_Calendar.php?date=" + encodeURIComponent(key);
                    };
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

    </script>
</body>
</html>
