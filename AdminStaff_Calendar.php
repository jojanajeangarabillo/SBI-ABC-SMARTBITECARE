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
$role_name = 'Admin Staff';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name, r.role_name
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id
              LEFT JOIN roles r ON u.role_id = r.role_id
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
    $role_name = $userData['role_name'] ?? 'Admin Staff';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// ----------------------------------------------------------------------
// GET MONTH AND YEAR FROM URL PARAMETERS
// ----------------------------------------------------------------------
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validate month/year
if ($currentMonth < 1) $currentMonth = 1;
if ($currentMonth > 12) $currentMonth = 12;
if ($currentYear < 2000) $currentYear = 2000;
if ($currentYear > 2100) $currentYear = 2100;

// ----------------------------------------------------------------------
// FETCH FOLLOW-UP DATA
// ----------------------------------------------------------------------

// 1. Get all follow-up schedules for the current month
$followUpQuery = "
    SELECT 
        DATE(v.next_schedule) as schedule_date,
        COUNT(DISTINCT c.case_id) as count,
        GROUP_CONCAT(DISTINCT c.case_id) as case_ids
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.vaccination_status = 'Scheduled'
    AND YEAR(v.next_schedule) = ?
    AND MONTH(v.next_schedule) = ?
    GROUP BY DATE(v.next_schedule)
    ORDER BY schedule_date
";
$stmt = $conn->prepare($followUpQuery);
$stmt->bind_param("sii", $branch_id, $currentYear, $currentMonth);
$stmt->execute();
$followUpResult = $stmt->get_result();

$calendarData = [];
$totalEvents = 0;
while ($row = $followUpResult->fetch_assoc()) {
    $calendarData[$row['schedule_date']] = [
        'count' => (int)$row['count'],
        'case_ids' => $row['case_ids']
    ];
    $totalEvents += (int)$row['count'];
}

// 2. Get follow-up statistics
// Today's date
$today = date('Y-m-d');

// Today's follow-ups
$todayQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule = ?
    AND v.vaccination_status = 'Scheduled'
";
$stmt = $conn->prepare($todayQuery);
$stmt->bind_param("ss", $branch_id, $today);
$stmt->execute();
$todayResult = $stmt->get_result();
$todayCount = $todayResult->fetch_assoc()['count'] ?? 0;

// Overdue follow-ups (scheduled date has passed and not completed)
$overdueQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule < CURDATE()
    AND v.vaccination_status = 'Scheduled'
";
$stmt = $conn->prepare($overdueQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$overdueResult = $stmt->get_result();
$overdueCount = $overdueResult->fetch_assoc()['count'] ?? 0;

// Pending follow-ups (future scheduled dates)
$pendingQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule > CURDATE()
    AND v.vaccination_status = 'Scheduled'
";
$stmt = $conn->prepare($pendingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$pendingResult = $stmt->get_result();
$pendingCount = $pendingResult->fetch_assoc()['count'] ?? 0;

// 3. Get follow-up patients list for a specific date (default: today)
$selectedDate = isset($_GET['date']) ? $_GET['date'] : $today;

$patientsQuery = "
    SELECT 
        c.case_id,
        c.patient_id,
        p.full_name as patient_name,
        p.gender,
        p.birthday,
        c.animal_type,
        c.date_of_bite,
        r.registry_number as case_no,
        v.vaccination_id,
        v.dose_number,
        v.date_administered,
        v.next_schedule,
        v.vaccination_status,
        v.is_final_dose,
        v.remarks as vaccination_remarks,
        -- Calculate age
        TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
        -- Determine dose label
        CASE 
            WHEN v.dose_number = 1 THEN 'Day 0 (1st Dose)'
            WHEN v.dose_number = 2 THEN 'Day 3 (2nd Dose)'
            WHEN v.dose_number = 3 THEN 'Day 7 (3rd Dose)'
            WHEN v.dose_number = 4 THEN 'Day 14 (4th Dose)'
            WHEN v.dose_number = 5 THEN 'Day 21 (5th Dose)'
            WHEN v.dose_number = 6 THEN 'Day 28 (6th Dose)'
            ELSE CONCAT('Day ', v.dose_number)
        END as dose_label,
        -- Determine status
        CASE 
            WHEN v.next_schedule < CURDATE() THEN 'Overdue'
            WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 'Today'
            WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 'Upcoming'
            ELSE v.vaccination_status
        END as display_status
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    INNER JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.vaccination_status = 'Scheduled'
    AND DATE(v.next_schedule) = ?
    ORDER BY v.next_schedule ASC, p.full_name ASC
";
$stmt = $conn->prepare($patientsQuery);
$stmt->bind_param("ss", $branch_id, $selectedDate);
$stmt->execute();
$patientsResult = $stmt->get_result();

$followUpPatients = [];
while ($row = $patientsResult->fetch_assoc()) {
    $followUpPatients[] = $row;
}

// 4. Get upcoming follow-ups (next 7 days) for the sidebar/legend
$upcomingQuery = "
    SELECT 
        DATE(v.next_schedule) as schedule_date,
        COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.vaccination_status = 'Scheduled'
    AND v.next_schedule BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(v.next_schedule)
    ORDER BY schedule_date
";
$stmt = $conn->prepare($upcomingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$upcomingResult = $stmt->get_result();
$upcomingData = [];
while ($row = $upcomingResult->fetch_assoc()) {
    $upcomingData[$row['schedule_date']] = (int)$row['count'];
}

// 5. Get total follow-up count (all active follow-ups)
$totalFollowUpQuery = "
    SELECT COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.vaccination_status = 'Scheduled'
";
$stmt = $conn->prepare($totalFollowUpQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$totalFollowUpResult = $stmt->get_result();
$totalFollowUpCount = $totalFollowUpResult->fetch_assoc()['count'] ?? 0;

// ----------------------------------------------------------------------
// HANDLE EXPORT
// ----------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'true') {
    $exportDate = isset($_GET['date']) ? $_GET['date'] : $today;
    
    $exportQuery = "
        SELECT 
            r.registry_number as case_no,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            c.animal_type,
            v.dose_number,
            DATE(v.next_schedule) as scheduled_date,
            v.vaccination_status
        FROM animal_bite_cases c
        INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND v.next_schedule IS NOT NULL
        AND DATE(v.next_schedule) = ?
        ORDER BY v.next_schedule ASC
    ";
    $stmt = $conn->prepare($exportQuery);
    $stmt->bind_param("ss", $branch_id, $exportDate);
    $stmt->execute();
    $exportResult = $stmt->get_result();
    
    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="follow_ups_' . $exportDate . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Case No.', 'Patient Name', 'Gender', 'Age', 'Animal Type', 'Dose Number', 'Scheduled Date', 'Status']);
    
    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['case_no'] ?? 'N/A',
            $row['patient_name'],
            $row['gender'] ?? 'N/A',
            $row['age'] ?? 'N/A',
            $row['animal_type'] ?? 'N/A',
            $row['dose_number'] ?? 'N/A',
            $row['scheduled_date'],
            $row['vaccination_status'] ?? 'Scheduled'
        ]);
    }
    fclose($output);
    exit();
}

// ----------------------------------------------------------------------
// HANDLE AJAX REQUEST FOR REAL-TIME UPDATES
// ----------------------------------------------------------------------
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = isset($_GET['ajax_action']) ? $_GET['ajax_action'] : '';
    
    switch ($action) {
        case 'get_stats':
            // Get updated stats
            $today = date('Y-m-d');
            
            $todayQuery = "SELECT COUNT(DISTINCT c.case_id) as count FROM animal_bite_cases c INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id WHERE c.branch_id = ? AND v.next_schedule = ? AND v.vaccination_status = 'Scheduled'";
            $stmt = $conn->prepare($todayQuery);
            $stmt->bind_param("ss", $branch_id, $today);
            $stmt->execute();
            $todayResult = $stmt->get_result();
            $todayCount = $todayResult->fetch_assoc()['count'] ?? 0;
            
            $overdueQuery = "SELECT COUNT(DISTINCT c.case_id) as count FROM animal_bite_cases c INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id WHERE c.branch_id = ? AND v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled'";
            $stmt = $conn->prepare($overdueQuery);
            $stmt->bind_param("s", $branch_id);
            $stmt->execute();
            $overdueResult = $stmt->get_result();
            $overdueCount = $overdueResult->fetch_assoc()['count'] ?? 0;
            
            $pendingQuery = "SELECT COUNT(DISTINCT c.case_id) as count FROM animal_bite_cases c INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id WHERE c.branch_id = ? AND v.next_schedule > CURDATE() AND v.vaccination_status = 'Scheduled'";
            $stmt = $conn->prepare($pendingQuery);
            $stmt->bind_param("s", $branch_id);
            $stmt->execute();
            $pendingResult = $stmt->get_result();
            $pendingCount = $pendingResult->fetch_assoc()['count'] ?? 0;
            
            $totalQuery = "SELECT COUNT(DISTINCT c.case_id) as count FROM animal_bite_cases c INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id WHERE c.branch_id = ? AND v.next_schedule IS NOT NULL AND v.vaccination_status = 'Scheduled'";
            $stmt = $conn->prepare($totalQuery);
            $stmt->bind_param("s", $branch_id);
            $stmt->execute();
            $totalResult = $stmt->get_result();
            $totalCount = $totalResult->fetch_assoc()['count'] ?? 0;
            
            echo json_encode([
                'success' => true,
                'today' => $todayCount,
                'overdue' => $overdueCount,
                'pending' => $pendingCount,
                'total' => $totalCount
            ]);
            break;
            
        case 'get_calendar':
            $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
            $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            
            $calQuery = "
                SELECT 
                    DATE(v.next_schedule) as schedule_date,
                    COUNT(DISTINCT c.case_id) as count
                FROM animal_bite_cases c
                INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
                WHERE c.branch_id = ?
                AND v.next_schedule IS NOT NULL
                AND v.vaccination_status = 'Scheduled'
                AND YEAR(v.next_schedule) = ?
                AND MONTH(v.next_schedule) = ?
                GROUP BY DATE(v.next_schedule)
            ";
            $stmt = $conn->prepare($calQuery);
            $stmt->bind_param("sii", $branch_id, $year, $month);
            $stmt->execute();
            $calResult = $stmt->get_result();
            
            $calData = [];
            while ($row = $calResult->fetch_assoc()) {
                $calData[$row['schedule_date']] = (int)$row['count'];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $calData,
                'month' => $month,
                'year' => $year
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Follow-up Calendar - SmartBiteCare</title>
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
            --gray-100: #f8f9fc;
            --gray-200: #f1f3f5;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-900: #212529;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            --radius: 12px;
            --transition: all 0.25s ease;
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

        /* Dashboard Content */
        .dashboard-content {
            padding: 30px 35px 50px;
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
            box-shadow: var(--shadow);
            border-left: 5px solid var(--primary);
            transition: var(--transition);
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

        .stat-card.pending {
            border-left-color: var(--warning);
        }
        .stat-card.pending .stat-number {
            color: #e6a800;
        }

        .stat-card.total {
            border-left-color: var(--info);
        }
        .stat-card.total .stat-number {
            color: var(--info);
        }

        /* Calendar + Legend Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 24px;
            margin-bottom: 30px;
        }

        /* Calendar Wrapper */
        .calendar-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px 24px;
            box-shadow: var(--shadow);
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
            transition: var(--transition);
            cursor: pointer;
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
            padding: 4px 0;
            font-weight: 500;
            color: #212529;
            cursor: default;
        }

        .calendar-wrapper table td .day-cell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            transition: var(--transition);
            font-size: 14px;
            position: relative;
            cursor: pointer;
        }

        .calendar-wrapper table td .day-cell:hover {
            background: var(--gray-100);
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

        .calendar-wrapper table td .day-cell.has-event::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%;
            transform: translateX(-50%);
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
        }

        .calendar-wrapper table td .day-cell.has-event.today::after {
            background: white;
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

        /* Legend Wrapper */
        .legend-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: var(--shadow);
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
            background: var(--warning);
        }
        .legend-item .dot.total-dot {
            background: var(--info);
        }

        .legend-divider {
            border: none;
            border-top: 1px solid #f1f3f5;
            margin: 10px 0 12px;
        }

        .legend-upcoming {
            margin-top: 8px;
        }

        .legend-upcoming .upcoming-item {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            padding: 3px 0;
            color: var(--gray-700);
        }

        .legend-upcoming .upcoming-item .date-label {
            font-weight: 500;
        }

        .legend-upcoming .upcoming-item .count-badge {
            background: var(--gray-100);
            padding: 0 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
            color: var(--primary);
        }

        /* Table Wrapper */
        .table-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px 24px;
            box-shadow: var(--shadow);
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
            flex-wrap: wrap;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn {
            border: none;
            background: transparent;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #6c757d;
            transition: var(--transition);
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
        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.pending-badge {
            background: var(--warning);
        }
        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.today-badge {
            background: var(--info);
        }

        .table-wrapper .table-header .date-display {
            font-size: 13px;
            color: #6c757d;
        }

        .table-wrapper .table-header .date-display strong {
            color: var(--gray-900);
        }

        .table-responsive {
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
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-badge.today-badge {
            background: #cce5ff;
            color: #004085;
        }

        .status-badge.pending-badge {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.overdue-badge {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.upcoming-badge {
            background: #d1ecf1;
            color: #0c5460;
        }

        .table-wrapper table tbody td .btn-view {
            background: var(--primary);
            color: white;
            border: none;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .table-wrapper table tbody td .btn-view:hover {
            background: #1f2d6b;
            transform: scale(1.02);
        }

        .no-records {
            text-align: center;
            padding: 40px 20px;
            color: #adb5bd;
        }

        .no-records i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        /* Table Footer */
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
            transition: var(--transition);
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

        /* Export Button */
        .export-btn {
            background: white;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 8px 24px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
            transition: var(--transition);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn:hover {
            background: var(--primary);
            color: white;
        }

        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Toast Notifications */
        .toast-container-custom {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            max-width: 380px;
        }

        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 5px solid var(--success);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
            margin-bottom: 10px;
        }

        .toast-custom.show {
            transform: translateX(0);
        }

        .toast-custom.error {
            border-left-color: var(--danger);
        }

        .toast-custom .toast-icon {
            font-size: 24px;
            color: var(--success);
        }

        .toast-custom.error .toast-icon {
            color: var(--danger);
        }

        /* Responsive */
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
            .calendar-wrapper table td .day-cell {
                width: 26px;
                height: 26px;
                font-size: 12px;
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

        .branch-indicator {
            font-size: 13px;
            color: var(--gray-600);
            background: var(--gray-100);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .date-nav-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            transition: var(--transition);
            display: inline-block;
        }

        .date-nav-link:hover {
            background: var(--gray-100);
            text-decoration: underline;
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
            <div class="system-name">Smart Bite Care</div>
        </div>

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
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- Top Header -->
        <div class="topbar">
            <h3>Follow-up Calendar</h3>
            <div class="profile">
                <?php echo htmlspecialchars($username); ?>
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="dashboard-content">
            <!-- STATS ROW -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-number" id="todayCount"><?php echo $todayCount; ?></div>
                    <div class="stat-label">Today's Follow-ups</div>
                    <div class="stat-sub">Scheduled for today</div>
                </div>

                <div class="stat-card overdue">
                    <div class="stat-number" id="overdueCount"><?php echo $overdueCount; ?></div>
                    <div class="stat-label">Overdue</div>
                    <div class="stat-sub">Missed schedule</div>
                </div>

                <div class="stat-card pending">
                    <div class="stat-number" id="pendingCount"><?php echo $pendingCount; ?></div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-sub">Yet to be administered</div>
                </div>

                <div class="stat-card total">
                    <div class="stat-number" id="totalCount"><?php echo $totalFollowUpCount; ?></div>
                    <div class="stat-label">Total Follow-ups</div>
                    <div class="stat-sub">Active schedules</div>
                </div>
            </div>

            <!-- CALENDAR + LEGEND -->
            <div class="dashboard-grid">
                <!-- Calendar -->
                <div class="calendar-wrapper">
                    <div class="cal-header">
                        <h5 id="calendarTitle"><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></h5>
                        <div class="cal-nav">
                            <button id="prevMonthBtn"><i class="bi bi-chevron-left"></i></button>
                            <button id="nextMonthBtn"><i class="bi bi-chevron-right"></i></button>
                            <button id="todayBtn" style="width:auto;padding:0 12px;font-size:12px;border-radius:8px;">Today</button>
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
                        <tbody id="calendarBody"></tbody>
                    </table>

                    <div class="cal-footer">
                        <span><i class="bi bi-calendar-event"></i> <span id="eventsCount"><?php echo $totalEvents; ?></span> events this month</span>
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
                        Overdue (<?php echo $overdueCount; ?>)
                    </div>
                    <div class="legend-item">
                        <span class="dot pending-dot"></span>
                        Pending (<?php echo $pendingCount; ?>)
                    </div>

                    <hr class="legend-divider">
                    <div style="font-size:13px;color:#6c757d;margin-top:4px;">
                        <i class="bi bi-info-circle"></i> Click "View" to update status
                    </div>

                    <hr class="legend-divider">
                    <div class="legend-upcoming">
                        <div style="font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:6px;">
                            <i class="bi bi-clock"></i> Upcoming (7 days)
                        </div>
                        <?php 
                        $upcomingDisplay = array_slice($upcomingData, 0, 5);
                        if (empty($upcomingDisplay)): ?>
                        <div style="font-size:13px;color:#adb5bd;">No upcoming follow-ups</div>
                        <?php else: ?>
                        <?php foreach ($upcomingDisplay as $date => $count): ?>
                        <div class="upcoming-item">
                            <span class="date-label"><?php echo date('M d', strtotime($date)); ?></span>
                            <span class="count-badge"><?php echo $count; ?> patient<?php echo $count > 1 ? 's' : ''; ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
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
                        <button class="tab-btn active" data-filter="all">All <span class="badge-count"><?php echo count($followUpPatients); ?></span></button>
                        <button class="tab-btn" data-filter="today">Today <span class="badge-count today-badge"><?php echo $todayCount; ?></span></button>
                        <button class="tab-btn" data-filter="pending">Pending <span class="badge-count pending-badge"><?php echo $pendingCount; ?></span></button>
                        <button class="tab-btn" data-filter="overdue">Overdue <span class="badge-count overdue-badge"><?php echo $overdueCount; ?></span></button>
                    </div>
                    <div class="date-display">
                        <strong>Follow-up Patients for <?php echo date('F d, Y', strtotime($selectedDate)); ?></strong>
                        <span class="branch-indicator"><?php echo htmlspecialchars($branch_name); ?></span>
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
                        <tbody id="patientsTableBody">
                            <?php if (empty($followUpPatients)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="no-records">
                                        <i class="bi bi-calendar-x"></i>
                                        <p>No follow-up patients for this date.</p>
                                        <small class="text-muted">Use the calendar to view other dates.</small>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($followUpPatients as $patient): ?>
                            <?php 
                                $statusClass = 'upcoming-badge';
                                $statusLabel = $patient['display_status'] ?? 'Upcoming';
                                if ($statusLabel == 'Overdue') $statusClass = 'overdue-badge';
                                elseif ($statusLabel == 'Today') $statusClass = 'today-badge';
                                elseif ($statusLabel == 'Upcoming') $statusClass = 'pending-badge';
                                
                                $patientInfo = $patient['patient_name'] . ' ' . ($patient['age'] ?? 'N/A') . ' / ' . ($patient['gender'] ? substr($patient['gender'], 0, 1) : 'N/A');
                            ?>
                            <tr data-status="<?php echo strtolower($statusLabel); ?>">
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo htmlspecialchars($patient['case_no'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($patientInfo); ?> - <?php echo htmlspecialchars($patient['animal_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($patient['dose_label'] ?? 'Day ' . $patient['dose_number']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($patient['next_schedule'])); ?></td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                <td>
                                    <a href="AdminStaff_PatientRecord.php?action=view&case_id=<?php echo $patient['case_id']; ?>" class="btn-view">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table Footer -->
                <div class="table-footer">
                    <div class="pagination-info">
                        <strong><?php echo count($followUpPatients); ?></strong> patients
                    </div>
                    <div>
                        <button class="export-btn" id="exportBtn">
                            <i class="bi bi-download"></i> Export List
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container-custom" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // ----------------------------------------------------------------
    // TOAST NOTIFICATIONS
    // ----------------------------------------------------------------
    function showToast(msg, sub = '', isError = false) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast-custom' + (isError ? ' error' : '');
        const icon = isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill';
        toast.innerHTML = `
            <span class="toast-icon"><i class="bi ${icon}"></i></span>
            <div class="toast-msg">${msg} ${sub ? '<small>' + sub + '</small>' : ''}</div>
        `;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    // ----------------------------------------------------------------
    // LOADING OVERLAY
    // ----------------------------------------------------------------
    function showLoading() {
        document.getElementById('loadingOverlay').classList.add('show');
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').classList.remove('show');
    }

    // ----------------------------------------------------------------
    // CALENDAR DATA
    // ----------------------------------------------------------------
    const calendarData = <?php echo json_encode($calendarData); ?>;
    const todayDate = '<?php echo date('Y-m-d'); ?>';
    let currentMonth = <?php echo $currentMonth; ?>;
    let currentYear = <?php echo $currentYear; ?>;

    // ----------------------------------------------------------------
    // RENDER CALENDAR
    // ----------------------------------------------------------------
    function renderCalendar(month, year) {
        const firstDay = new Date(year, month - 1, 1).getDay();
        const daysInMonth = new Date(year, month, 0).getDate();
        const today = new Date();
        const todayStr = today.getFullYear() + '-' + 
            String(today.getMonth() + 1).padStart(2, '0') + '-' + 
            String(today.getDate()).padStart(2, '0');

        // Get calendar data for this month
        const calData = {};
        Object.keys(calendarData).forEach(date => {
            const d = new Date(date);
            if (d.getMonth() === month - 1 && d.getFullYear() === year) {
                calData[date] = calendarData[date];
            }
        });

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                            'July', 'August', 'September', 'October', 'November', 'December'];
        document.getElementById('calendarTitle').textContent = monthNames[month - 1] + ' ' + year;

        let html = '';
        let date = 1;
        let totalEvents = 0;

        for (let i = 0; i < 6; i++) {
            html += '<tr>';
            for (let j = 0; j < 7; j++) {
                if (i === 0 && j < firstDay) {
                    // Previous month days
                    const prevMonthDays = new Date(year, month - 1, 0).getDate();
                    const prevDate = prevMonthDays - (firstDay - j) + 1;
                    const prevMonth = month === 1 ? 12 : month - 1;
                    const prevYear = month === 1 ? year - 1 : year;
                    const dateStr = prevYear + '-' + 
                        String(prevMonth).padStart(2, '0') + '-' + 
                        String(prevDate).padStart(2, '0');
                    const hasEvent = calData[dateStr] ? 'has-event' : '';
                    html += `<td><span class="day-cell other-month ${hasEvent}">${prevDate}</span></td>`;
                } else if (date > daysInMonth) {
                    // Next month days
                    const nextDate = date - daysInMonth;
                    const nextMonth = month === 12 ? 1 : month + 1;
                    const nextYear = month === 12 ? year + 1 : year;
                    const dateStr = nextYear + '-' + 
                        String(nextMonth).padStart(2, '0') + '-' + 
                        String(nextDate).padStart(2, '0');
                    const hasEvent = calData[dateStr] ? 'has-event' : '';
                    html += `<td><span class="day-cell other-month ${hasEvent}">${nextDate}</span></td>`;
                    date++;
                } else {
                    const dateStr = year + '-' + 
                        String(month).padStart(2, '0') + '-' + 
                        String(date).padStart(2, '0');
                    const isToday = dateStr === todayStr;
                    const hasEvent = calData[dateStr] ? 'has-event' : '';
                    const todayClass = isToday ? 'today' : '';
                    const eventClass = hasEvent ? 'has-event' : '';
                    const clickable = hasEvent ? `style="cursor:pointer;" onclick="goToDate('${dateStr}')"` : '';
                    
                    html += `<td><span class="day-cell ${todayClass} ${eventClass}" ${clickable}>${date}</span></td>`;
                    
                    if (hasEvent) {
                        totalEvents += calData[dateStr].count || 1;
                    }
                    date++;
                }
            }
            html += '</tr>';
            if (date > daysInMonth) break;
        }

        document.getElementById('calendarBody').innerHTML = html;
        document.getElementById('eventsCount').textContent = totalEvents;
    }

    // ----------------------------------------------------------------
    // GO TO DATE
    // ----------------------------------------------------------------
    function goToDate(dateStr) {
        const url = new URL(window.location.href);
        url.searchParams.set('date', dateStr);
        window.location.href = url.toString();
    }

    // ----------------------------------------------------------------
    // CALENDAR NAVIGATION
    // ----------------------------------------------------------------
    document.getElementById('prevMonthBtn').addEventListener('click', function() {
        if (currentMonth === 1) {
            currentMonth = 12;
            currentYear--;
        } else {
            currentMonth--;
        }
        const url = new URL(window.location.href);
        url.searchParams.set('month', currentMonth);
        url.searchParams.set('year', currentYear);
        window.location.href = url.toString();
    });

    document.getElementById('nextMonthBtn').addEventListener('click', function() {
        if (currentMonth === 12) {
            currentMonth = 1;
            currentYear++;
        } else {
            currentMonth++;
        }
        const url = new URL(window.location.href);
        url.searchParams.set('month', currentMonth);
        url.searchParams.set('year', currentYear);
        window.location.href = url.toString();
    });

    document.getElementById('todayBtn').addEventListener('click', function() {
        const today = new Date();
        const url = new URL(window.location.href);
        url.searchParams.set('month', today.getMonth() + 1);
        url.searchParams.set('year', today.getFullYear());
        url.searchParams.set('date', today.getFullYear() + '-' + 
            String(today.getMonth() + 1).padStart(2, '0') + '-' + 
            String(today.getDate()).padStart(2, '0'));
        window.location.href = url.toString();
    });

    // ----------------------------------------------------------------
    // TABLE FILTER TABS
    // ----------------------------------------------------------------
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.dataset.filter;
            const rows = document.querySelectorAll('#patientsTableBody tr');
            
            rows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else {
                    const status = row.dataset.status || '';
                    if (status === filter) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        });
    });

    // ----------------------------------------------------------------
    // EXPORT
    // ----------------------------------------------------------------
    document.getElementById('exportBtn').addEventListener('click', function() {
        const currentDate = '<?php echo $selectedDate; ?>';
        const url = window.location.pathname + '?export=true&date=' + currentDate;
        window.location.href = url;
    });

    // ----------------------------------------------------------------
    // AUTO-REFRESH (every 30 seconds)
    // ----------------------------------------------------------------
    function refreshData() {
        if (document.hidden) return;

        // Refresh stats
        fetch(window.location.pathname + '?ajax_action=get_stats&month=' + currentMonth + '&year=' + currentYear, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('todayCount').textContent = data.today || 0;
                document.getElementById('overdueCount').textContent = data.overdue || 0;
                document.getElementById('pendingCount').textContent = data.pending || 0;
                document.getElementById('totalCount').textContent = data.total || 0;
                
                // Update badge counts
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    const filter = btn.dataset.filter;
                    const badge = btn.querySelector('.badge-count');
                    if (badge) {
                        if (filter === 'all') {
                            badge.textContent = data.total || 0;
                        } else if (filter === 'today') {
                            badge.textContent = data.today || 0;
                        } else if (filter === 'pending') {
                            badge.textContent = data.pending || 0;
                        } else if (filter === 'overdue') {
                            badge.textContent = data.overdue || 0;
                        }
                    }
                });
                
                // Update legend counts
                const legendItems = document.querySelectorAll('.legend-item .dot');
                legendItems.forEach(item => {
                    if (item.classList.contains('overdue-dot')) {
                        const parent = item.parentElement;
                        const text = parent.textContent.trim();
                        parent.innerHTML = `<span class="dot overdue-dot"></span> Overdue (${data.overdue || 0})`;
                    }
                    if (item.classList.contains('pending-dot')) {
                        const parent = item.parentElement;
                        parent.innerHTML = `<span class="dot pending-dot"></span> Pending (${data.pending || 0})`;
                    }
                });
            }
        })
        .catch(error => console.error('Refresh error:', error));

        // Refresh calendar data
        fetch(window.location.pathname + '?ajax_action=get_calendar&month=' + currentMonth + '&year=' + currentYear, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update calendar data
                Object.keys(data.data).forEach(date => {
                    if (calendarData[date]) {
                        calendarData[date] = data.data[date];
                    } else {
                        calendarData[date] = data.data[date];
                    }
                });
                renderCalendar(currentMonth, currentYear);
            }
        })
        .catch(error => console.error('Calendar refresh error:', error));
    }

    // Auto-refresh every 30 seconds
    setInterval(refreshData, 30000);

    // Refresh when tab becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshData();
        }
    });

    // ----------------------------------------------------------------
    // INITIAL RENDER
    // ----------------------------------------------------------------
    renderCalendar(currentMonth, currentYear);

    console.log('Follow-up Calendar loaded successfully');
    console.log('Branch: <?php echo htmlspecialchars($branch_name); ?>');
    console.log('Total follow-ups: <?php echo $totalFollowUpCount; ?>');
    </script>
</body>
</html>