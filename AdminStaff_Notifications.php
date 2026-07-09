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
// GET FILTER PARAMETERS
// ----------------------------------------------------------------------
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// ----------------------------------------------------------------------
// GENERATE NOTIFICATIONS FROM PATIENT RECORDS
// ----------------------------------------------------------------------

/**
 * Generate notifications based on patient record data
 * Returns array of notifications with type, message, link, and date
 */
function generateNotifications($conn, $branch_id, $filter = 'all', $search = '') {
    $notifications = [];
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // ------------------------------------------------------------------
    // 1. UPCOMING VACCINATION SCHEDULES (Next 7 days)
    // ------------------------------------------------------------------
    $upcomingQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            r.registry_number as case_no,
            v.dose_number,
            v.scheduled_date as next_schedule,
            v.vaccination_status,
            CASE 
                WHEN v.dose_number = 1 THEN 'Day 0 (1st Dose)'
                WHEN v.dose_number = 2 THEN 'Day 3 (2nd Dose)'
                WHEN v.dose_number = 3 THEN 'Day 7 (3rd Dose)'
                WHEN v.dose_number = 4 THEN 'Day 14 (4th Dose)'
                WHEN v.dose_number = 5 THEN 'Day 21 (5th Dose)'
                WHEN v.dose_number = 6 THEN 'Day 28 (6th Dose)'
                ELSE CONCAT('Dose ', v.dose_number)
            END as dose_label
        FROM animal_bite_cases c
        INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND v.scheduled_date IS NOT NULL
        AND v.vaccination_status = 'Scheduled'
        AND v.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY v.scheduled_date ASC
    ";
    $stmt = $conn->prepare($upcomingQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $upcomingResult = $stmt->get_result();

    while ($row = $upcomingResult->fetch_assoc()) {
        $daysUntil = (strtotime($row['next_schedule']) - strtotime($today)) / (60 * 60 * 24);
        $daysUntil = (int)$daysUntil;
        
        $patientInfo = $row['patient_name'] . ' (' . $row['age'] . ' yrs, ' . ($row['gender'] ? substr($row['gender'], 0, 1) : 'N/A') . ')';
        
        if ($daysUntil == 0) {
            $message = "⚠️ <strong>{$patientInfo}</strong> has a vaccination scheduled for TODAY - {$row['dose_label']} (Case: {$row['case_no']})";
            $type = 'urgent';
            $icon = 'bi-calendar-check';
            $iconClass = 'warning';
            $badge = 'Today';
            $badgeClass = 'danger';
        } elseif ($daysUntil == 1) {
            $message = "📅 <strong>{$patientInfo}</strong> has a vaccination scheduled for TOMORROW - {$row['dose_label']} (Case: {$row['case_no']})";
            $type = 'upcoming';
            $icon = 'bi-calendar-event';
            $iconClass = 'warning';
            $badge = 'Tomorrow';
            $badgeClass = 'warning';
        } else {
            $message = "📅 <strong>{$patientInfo}</strong> has an upcoming vaccination in {$daysUntil} days - {$row['dose_label']} (Case: {$row['case_no']})";
            $type = 'upcoming';
            $icon = 'bi-calendar-event';
            $iconClass = 'warning';
            $badge = 'Upcoming';
            $badgeClass = 'warning';
        }

        $notifications[] = [
            'id' => 'upcoming_' . $row['case_id'] . '_' . $row['dose_number'],
            'type' => $type,
            'icon' => $icon,
            'icon_class' => $iconClass,
            'title' => 'Upcoming Vaccination',
            'message' => $message,
            'date' => $row['next_schedule'],
            'time' => date('h:i A', strtotime($now)),
            'link' => 'AdminStaff_PatientRecord.php?action=view&case_id=' . $row['case_id'],
            'link_text' => 'View Patient',
            'read' => false,
            'badge' => $badge,
            'badge_class' => $badgeClass,
            'action' => 'view_patient',
            'case_id' => $row['case_id']
        ];
    }

    // ------------------------------------------------------------------
    // 2. OVERDUE / MISSED VACCINATIONS
    // ------------------------------------------------------------------
    $overdueQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            r.registry_number as case_no,
            v.dose_number,
            v.scheduled_date as next_schedule,
            v.vaccination_status,
            DATEDIFF(CURDATE(), v.scheduled_date) as days_overdue,
            CASE 
                WHEN v.dose_number = 1 THEN 'Day 0 (1st Dose)'
                WHEN v.dose_number = 2 THEN 'Day 3 (2nd Dose)'
                WHEN v.dose_number = 3 THEN 'Day 7 (3rd Dose)'
                WHEN v.dose_number = 4 THEN 'Day 14 (4th Dose)'
                WHEN v.dose_number = 5 THEN 'Day 21 (5th Dose)'
                WHEN v.dose_number = 6 THEN 'Day 28 (6th Dose)'
                ELSE CONCAT('Dose ', v.dose_number)
            END as dose_label
        FROM animal_bite_cases c
        INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND v.scheduled_date IS NOT NULL
        AND v.vaccination_status = 'Scheduled'
        AND v.scheduled_date < CURDATE()
        ORDER BY v.scheduled_date ASC
    ";
    $stmt = $conn->prepare($overdueQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $overdueResult = $stmt->get_result();

    while ($row = $overdueResult->fetch_assoc()) {
        $patientInfo = $row['patient_name'] . ' (' . $row['age'] . ' yrs, ' . ($row['gender'] ? substr($row['gender'], 0, 1) : 'N/A') . ')';
        $daysOverdue = $row['days_overdue'];
        
        $message = "🚨 <strong>{$patientInfo}</strong> is {$daysOverdue} day" . ($daysOverdue > 1 ? 's' : '') . " overdue for {$row['dose_label']} (Case: {$row['case_no']})";
        $type = 'overdue';
        $icon = 'bi-exclamation-triangle-fill';
        $iconClass = 'danger';

        $notifications[] = [
            'id' => 'overdue_' . $row['case_id'] . '_' . $row['dose_number'],
            'type' => $type,
            'icon' => $icon,
            'icon_class' => $iconClass,
            'title' => '⚠️ Overdue Vaccination',
            'message' => $message,
            'date' => $row['next_schedule'],
            'time' => date('h:i A', strtotime($now)),
            'link' => 'AdminStaff_PatientRecord.php?action=view&case_id=' . $row['case_id'],
            'link_text' => 'View Patient',
            'read' => false,
            'badge' => 'Overdue',
            'badge_class' => 'danger',
            'action' => 'view_patient',
            'case_id' => $row['case_id']
        ];
    }

    // ------------------------------------------------------------------
    // 3. INCOMPLETE PATIENT RECORDS
    // ------------------------------------------------------------------
    $incompleteQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            r.registry_number as case_no,
            c.created_at,
            CASE 
                WHEN p.birthday IS NULL THEN 'Missing Date of Birth'
                WHEN p.gender IS NULL OR p.gender = '' THEN 'Missing Gender'
                WHEN p.contact_number IS NULL OR p.contact_number = '' THEN 'Missing Contact Number'
                WHEN c.animal_type IS NULL OR c.animal_type = '' THEN 'Missing Animal Type'
                WHEN c.bite_location IS NULL OR c.bite_location = '' THEN 'Missing Bite Location'
                WHEN r.registry_number IS NULL OR r.registry_number = '' THEN 'Missing Registry Number'
                ELSE NULL
            END as missing_field
        FROM animal_bite_cases c
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND (
            p.birthday IS NULL 
            OR p.gender IS NULL 
            OR p.gender = ''
            OR p.contact_number IS NULL 
            OR p.contact_number = ''
            OR c.animal_type IS NULL 
            OR c.animal_type = ''
            OR c.bite_location IS NULL 
            OR c.bite_location = ''
            OR r.registry_number IS NULL 
            OR r.registry_number = ''
        )
        ORDER BY c.created_at DESC
    ";
    $stmt = $conn->prepare($incompleteQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $incompleteResult = $stmt->get_result();

    while ($row = $incompleteResult->fetch_assoc()) {
        $patientInfo = $row['patient_name'] . ' (' . ($row['age'] ?? 'N/A') . ' yrs)';
        $missingField = $row['missing_field'] ?? 'Incomplete information';
        
        $message = "📋 <strong>{$patientInfo}</strong> has incomplete records: <em>{$missingField}</em> (Case: {$row['case_no']})";
        $type = 'incomplete';
        $icon = 'bi-pencil-square';
        $iconClass = 'expire';

        $notifications[] = [
            'id' => 'incomplete_' . $row['case_id'],
            'type' => $type,
            'icon' => $icon,
            'icon_class' => $iconClass,
            'title' => '📋 Incomplete Patient Record',
            'message' => $message,
            'date' => $row['created_at'],
            'time' => date('h:i A', strtotime($row['created_at'])),
            'link' => 'AdminStaff_PatientRecord.php?action=edit&case_id=' . $row['case_id'],
            'link_text' => 'Update Record',
            'read' => false,
            'badge' => 'Incomplete',
            'badge_class' => 'warning',
            'action' => 'edit_patient',
            'case_id' => $row['case_id']
        ];
    }

    // ------------------------------------------------------------------
    // 4. NEW PATIENTS (Last 7 days)
    // ------------------------------------------------------------------
    $newPatientsQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            r.registry_number as case_no,
            c.created_at,
            c.animal_type
        FROM animal_bite_cases c
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY c.created_at DESC
    ";
    $stmt = $conn->prepare($newPatientsQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $newPatientsResult = $stmt->get_result();

    while ($row = $newPatientsResult->fetch_assoc()) {
        $patientInfo = $row['patient_name'] . ' (' . ($row['age'] ?? 'N/A') . ' yrs, ' . ($row['gender'] ? substr($row['gender'], 0, 1) : 'N/A') . ')';
        $daysAgo = (strtotime($today) - strtotime(date('Y-m-d', strtotime($row['created_at'])))) / (60 * 60 * 24);
        $daysAgo = (int)$daysAgo;
        
        if ($daysAgo == 0) {
            $timeLabel = 'Today';
            $badgeClass = 'danger';
        } elseif ($daysAgo == 1) {
            $timeLabel = 'Yesterday';
            $badgeClass = 'warning';
        } else {
            $timeLabel = $daysAgo . ' days ago';
            $badgeClass = 'info';
        }
        
        $message = "🆕 <strong>{$patientInfo}</strong> was registered as a new patient - {$row['animal_type']} bite (Case: {$row['case_no']})";
        $type = 'new_patient';
        $icon = 'bi-person-plus-fill';
        $iconClass = 'info';

        $notifications[] = [
            'id' => 'new_patient_' . $row['case_id'],
            'type' => $type,
            'icon' => $icon,
            'icon_class' => $iconClass,
            'title' => '🆕 New Patient Registered',
            'message' => $message,
            'date' => $row['created_at'],
            'time' => date('h:i A', strtotime($row['created_at'])),
            'link' => 'AdminStaff_PatientRecord.php?action=view&case_id=' . $row['case_id'],
            'link_text' => 'View Patient',
            'read' => false,
            'badge' => $timeLabel,
            'badge_class' => $badgeClass,
            'action' => 'view_patient',
            'case_id' => $row['case_id']
        ];
    }

    // ------------------------------------------------------------------
    // 5. PATIENTS WITH NO VACCINATION SCHEDULE YET
    // ------------------------------------------------------------------
    $noScheduleQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            r.registry_number as case_no,
            c.created_at,
            c.animal_type
        FROM animal_bite_cases c
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND c.case_status != 'Completed'
        AND NOT EXISTS (
            SELECT 1 FROM vaccination_records v 
            WHERE v.case_id = c.case_id AND v.branch_id = c.branch_id
        )
        ORDER BY c.created_at DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($noScheduleQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $noScheduleResult = $stmt->get_result();

    while ($row = $noScheduleResult->fetch_assoc()) {
        $patientInfo = $row['patient_name'] . ' (' . ($row['age'] ?? 'N/A') . ' yrs)';
        
        $message = "💉 <strong>{$patientInfo}</strong> has no vaccination schedule set yet - {$row['animal_type']} bite (Case: {$row['case_no']})";
        $type = 'no_schedule';
        $icon = 'bi-syringe';
        $iconClass = 'expire';

        $notifications[] = [
            'id' => 'no_schedule_' . $row['case_id'],
            'type' => $type,
            'icon' => $icon,
            'icon_class' => $iconClass,
            'title' => '💉 No Vaccination Schedule',
            'message' => $message,
            'date' => $row['created_at'],
            'time' => date('h:i A', strtotime($row['created_at'])),
            'link' => 'AdminStaff_PatientRecord.php?action=edit&case_id=' . $row['case_id'],
            'link_text' => 'Add Schedule',
            'read' => false,
            'badge' => 'Action Needed',
            'badge_class' => 'warning',
            'action' => 'edit_patient',
            'case_id' => $row['case_id']
        ];
    }

    // ------------------------------------------------------------------
    // 6. PHILHEALTH RECORDS PENDING ACTION
    // ------------------------------------------------------------------
    $philhealthQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            r.registry_number as case_no,
            ph.status as philhealth_status,
            ph.updated_at
        FROM animal_bite_cases c
        INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND ph.status IN ('For Writing', 'For Screening', 'For Signing')
        ORDER BY ph.updated_at ASC
        LIMIT 10
    ";
    $stmt = $conn->prepare($philhealthQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $philhealthResult = $stmt->get_result();

    while ($row = $philhealthResult->fetch_assoc()) {
        $statusLabel = $row['philhealth_status'] ?? 'Pending';
        
        $message = "📄 <strong>{$row['patient_name']}</strong> has PhilHealth record status: <em>{$statusLabel}</em> (Case: {$row['case_no']})";
        $type = 'philhealth_pending';
        $icon = 'bi-file-earmark-text';
        $iconClass = 'warning';

        $notifications[] = [
            'id' => 'philhealth_' . $row['case_id'],
            'type' => $type,
            'icon' => $icon,
            'icon_class' => $iconClass,
            'title' => '📄 PhilHealth Action Required',
            'message' => $message,
            'date' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
            'time' => date('h:i A', strtotime($row['updated_at'] ?? $now)),
            'link' => 'AdminStaff_PhilhealthStatus.php',
            'link_text' => 'View Status',
            'read' => false,
            'badge' => $statusLabel,
            'badge_class' => 'warning',
            'action' => 'view_philhealth',
            'case_id' => $row['case_id']
        ];
    }

    // ------------------------------------------------------------------
    // APPLY FILTERS AND SEARCH
    // ------------------------------------------------------------------
    if ($filter != 'all') {
        $notifications = array_filter($notifications, function($n) use ($filter) {
            return $n['type'] == $filter;
        });
    }

    if (!empty($search)) {
        $searchLower = strtolower($search);
        $notifications = array_filter($notifications, function($n) use ($searchLower) {
            return strpos(strtolower($n['message']), $searchLower) !== false ||
                   strpos(strtolower($n['title']), $searchLower) !== false;
        });
    }

    // Sort notifications by date (most recent first)
    usort($notifications, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    return array_values($notifications);
}

// ----------------------------------------------------------------------
// GENERATE NOTIFICATIONS
// ----------------------------------------------------------------------
$allNotifications = generateNotifications($conn, $branch_id, $filter, $search);

// Paginate notifications
$totalNotifications = count($allNotifications);
$totalPages = ceil($totalNotifications / $limit);
$paginatedNotifications = array_slice($allNotifications, $offset, $limit);

// Count unread notifications (all are considered unread since we're generating them dynamically)
$unreadCount = count($allNotifications);

// ----------------------------------------------------------------------
// HANDLE AJAX REQUESTS FOR REAL-TIME UPDATES
// ----------------------------------------------------------------------
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = isset($_GET['ajax_action']) ? $_GET['ajax_action'] : '';
    
    switch ($action) {
        case 'get_notifications':
            $notifFilter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
            $notifSearch = isset($_GET['search']) ? trim($_GET['search']) : '';
            $notifPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $notifLimit = 10;
            $notifOffset = ($notifPage - 1) * $notifLimit;
            
            $notifs = generateNotifications($conn, $branch_id, $notifFilter, $notifSearch);
            $total = count($notifs);
            $pages = ceil($total / $notifLimit);
            $paginated = array_slice($notifs, $notifOffset, $notifLimit);
            
            // Count unread
            $unread = count($notifs);
            
            echo json_encode([
                'success' => true,
                'notifications' => $paginated,
                'total' => $total,
                'pages' => $pages,
                'unread' => $unread,
                'current_page' => $notifPage
            ]);
            break;
            
        case 'mark_all_read':
            // Since notifications are generated dynamically, we just return success
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            break;
            
        case 'mark_read':
            // Mark a single notification as read (for dynamic notifications, we just track in session)
            $notifId = isset($_GET['id']) ? $_GET['id'] : '';
            if (!isset($_SESSION['read_notifications'])) {
                $_SESSION['read_notifications'] = [];
            }
            if (!in_array($notifId, $_SESSION['read_notifications'])) {
                $_SESSION['read_notifications'][] = $notifId;
            }
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
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
    <title>Notifications - SmartBiteCare</title>
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

        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid var(--primary);
            border-radius: 50px;
            padding: 8px 18px;
            width: 300px;
        }

        .search-box i {
            color: var(--primary);
            font-size: 18px;
            margin-right: 10px;
        }

        .search-box input {
            border: none;
            outline: none;
            background: transparent;
            color: black;
            width: 100%;
            font-size: 14px;
        }

        .search-box input::placeholder {
            color: rgba(0, 0, 0, 0.85);
        }

        .btn-filter {
            background: var(--primary);
            color: #fff;
            border: 2px solid var(--primary);
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            transition: .2s;
        }

        .btn-filter:hover,
        .btn-filter:focus,
        .btn-filter:active,
        .btn-filter.show {
            background: #1f2d6e;
            color: #fff;
            border-color: #1f2d6e;
            box-shadow: none;
        }

        .btn-filter i {
            margin-right: 8px;
        }

        .btn-readall {
            background: var(--success);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            transition: .2s;
        }

        .btn-readall:hover {
            background: #157347;
            color: #fff;
        }

        .btn-readall i {
            margin-right: 8px;
        }

        .notification-section {
            width: 100%;
            margin-top: 25px;
        }

        .notification-day {
            margin: 25px 0 15px;
        }

        .notification-day h5 {
            color: var(--primary);
            font-weight: 700;
        }

        .notification-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            background: #fff;
            border: 1px solid #e8ebf3;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 18px;
            transition: var(--transition);
        }

        .notification-card:hover {
            box-shadow: 0 5px 20px rgba(0, 0, 0, .08);
        }

        .notification-card.unread {
            border-left: 5px solid var(--danger);
            background: #fefefe;
        }

        .notification-card.read {
            border-left: 5px solid var(--success);
            opacity: 0.85;
        }

        .notification-icon {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-right: 20px;
            flex-shrink: 0;
        }

        .notification-icon.danger {
            background: #fdecec;
            color: var(--danger);
        }

        .notification-icon.warning {
            background: #fff4dd;
            color: #f59e0b;
        }

        .notification-icon.expire {
            background: #fff8e6;
            color: #ff9800;
        }

        .notification-icon.info {
            background: #d1ecf1;
            color: var(--info);
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-content h6 {
            font-size: 18px;
            font-weight: 700;
            color: #222;
            margin-bottom: 6px;
        }

        .notification-content p {
            margin-bottom: 6px;
            color: #555;
            font-size: 14px;
        }

        .notification-content p strong {
            color: var(--gray-900);
        }

        .notification-content small {
            color: #999;
        }

        .notification-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
            flex-shrink: 0;
            margin-left: 15px;
        }

        .btn-view {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: .2s;
            text-decoration: none;
        }

        .btn-view:hover {
            background: #1f2d6e;
            color: #fff;
        }

        .btn-mark-read {
            background: transparent;
            color: var(--gray-600);
            border: 1px solid #ddd;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: .2s;
            cursor: pointer;
        }

        .btn-mark-read:hover {
            background: var(--gray-100);
            border-color: var(--gray-500);
        }

        .btn-mark-read.marked {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .badge-status {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-status.danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-status.success {
            background: #d4edda;
            color: #155724;
        }

        .pagination-wrap {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination-wrap .pagination {
            margin: 0;
            gap: 2px;
        }

        .pagination-wrap .page-link {
            border: none;
            color: var(--primary);
            font-weight: 600;
            font-size: 14px;
            padding: 8px 15px;
            border-radius: 8px;
            background: transparent;
            transition: background 0.15s, color 0.15s;
        }

        .pagination-wrap .page-link:hover {
            background: #eef2ff;
            color: var(--primary);
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
        }

        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
            opacity: 0.6;
        }

        .pagination-wrap .page-item:first-child .page-link,
        .pagination-wrap .page-item:last-child .page-link {
            font-size: 16px;
            padding: 8px 12px;
        }

        .no-notifications {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .no-notifications i {
            font-size: 64px;
            display: block;
            margin-bottom: 16px;
            opacity: 0.4;
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

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
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 18px 16px 30px 16px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }

            .header-left {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                width: 100%;
            }

            .btn-readall {
                width: 100%;
                justify-content: center;
            }

            .notification-card {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
            }

            .notification-icon {
                width: 50px;
                height: 50px;
                font-size: 22px;
                margin-right: 0;
                margin-bottom: 10px;
            }

            .notification-right {
                flex-direction: row;
                align-items: center;
                margin-left: 0;
                margin-top: 10px;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .pagination-wrap .page-link {
                padding: 6px 11px;
                font-size: 13px;
            }

            .topbar {
                padding: 0 16px;
                height: 64px;
            }

            .topbar h3 {
                font-size: 20px;
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

        .notification-count-badge {
            background: var(--danger);
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 12px;
            font-weight: 700;
            margin-left: 6px;
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
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a class="active" href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications <span class="notification-count-badge" id="unreadBadge"><?php echo $unreadCount; ?></span></span></a></li>
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
            <h3>Notifications <span style="font-size:16px; color:#6c757d; font-weight:400; margin-left:8px;"> <?php echo htmlspecialchars($branch_name); ?> </span> </h3>
            <div class="profile">
                <?php echo htmlspecialchars($username); ?>
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="content-wrapper">
            <div class="page-header">
                <div class="header-left">
                    <!-- Search -->
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search Notifications..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <!-- Filter Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-filter dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-funnel"></i>
                            Filters
                        </button>
                        <ul class="dropdown-menu" id="filterMenu">
                            <li><a class="dropdown-item <?php echo $filter == 'all' ? 'active' : ''; ?>" data-filter="all" href="#">All</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'urgent' ? 'active' : ''; ?>" data-filter="urgent" href="#">⚠️ Urgent</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'upcoming' ? 'active' : ''; ?>" data-filter="upcoming" href="#">📅 Upcoming</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'overdue' ? 'active' : ''; ?>" data-filter="overdue" href="#">🚨 Overdue</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'incomplete' ? 'active' : ''; ?>" data-filter="incomplete" href="#">📋 Incomplete</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'new_patient' ? 'active' : ''; ?>" data-filter="new_patient" href="#">🆕 New Patients</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'no_schedule' ? 'active' : ''; ?>" data-filter="no_schedule" href="#">💉 No Schedule</a></li>
                            <li><a class="dropdown-item <?php echo $filter == 'philhealth_pending' ? 'active' : ''; ?>" data-filter="philhealth_pending" href="#">📄 PhilHealth</a></li>
                        </ul>
                    </div>

                    <span class="branch-indicator"><?php echo htmlspecialchars($branch_name); ?></span>
                </div>

                <button class="btn btn-readall" id="markAllReadBtn">
                    <i class="bi bi-check2-all"></i>
                    Mark All as Read
                </button>
            </div>

            <!-- Notifications -->
            <div class="notification-section" id="notificationSection">
                <?php if (empty($paginatedNotifications)): ?>
                <div class="no-notifications">
                    <i class="bi bi-bell-slash"></i>
                    <h4>No Notifications</h4>
                    <p>All caught up! There are no notifications to display.</p>
                </div>
                <?php else: ?>
                <?php 
                $currentDate = '';
                $readNotifications = isset($_SESSION['read_notifications']) ? $_SESSION['read_notifications'] : [];
                foreach ($paginatedNotifications as $notification):
                    $notifDate = date('Y-m-d', strtotime($notification['date']));
                    $displayDate = '';
                    if ($notifDate != $currentDate) {
                        $currentDate = $notifDate;
                        if ($notifDate == date('Y-m-d')) {
                            $displayDate = 'Today';
                        } elseif ($notifDate == date('Y-m-d', strtotime('-1 day'))) {
                            $displayDate = 'Yesterday';
                        } else {
                            $displayDate = date('F d, Y', strtotime($notification['date']));
                        }
                        echo '<div class="notification-day"><h5>' . $displayDate . '</h5></div>';
                    }
                    $isRead = in_array($notification['id'], $readNotifications);
                ?>
                <div class="notification-card <?php echo $isRead ? 'read' : 'unread'; ?>" data-id="<?php echo $notification['id']; ?>">
                    <div class="notification-icon <?php echo $notification['icon_class']; ?>">
                        <i class="bi <?php echo $notification['icon']; ?>"></i>
                    </div>

                    <div class="notification-content">
                        <h6><?php echo $notification['title']; ?></h6>
                        <p><?php echo $notification['message']; ?></p>
                        <small><?php echo $notification['time']; ?> • <?php echo date('M d, Y', strtotime($notification['date'])); ?></small>
                    </div>

                    <div class="notification-right">
                        <span class="badge-status <?php echo $notification['badge_class']; ?>">
                            <?php echo $notification['badge']; ?>
                        </span>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <a href="<?php echo $notification['link']; ?>" class="btn-view" target="_blank">
                                <?php echo $notification['link_text']; ?>
                            </a>
                            <button class="btn-mark-read <?php echo $isRead ? 'marked' : ''; ?>" data-id="<?php echo $notification['id']; ?>">
                                <i class="bi <?php echo $isRead ? 'bi-check-circle-fill' : 'bi-circle'; ?>"></i>
                                <?php echo $isRead ? 'Read' : 'Mark Read'; ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <div class="pagination-wrap">
                <nav aria-label="Notification pagination">
                    <ul class="pagination" id="paginationControls">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="#" data-page="<?php echo $page - 1; ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="#" data-page="<?php echo $page + 1; ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>

            <div class="text-center mt-3">
                <small class="text-muted">
                    Showing <?php echo count($paginatedNotifications); ?> of <?php echo $totalNotifications; ?> notifications
                    <?php if ($unreadCount > 0): ?>
                    • <span class="text-danger"><strong><?php echo $unreadCount; ?></strong> unread</span>
                    <?php endif; ?>
                </small>
            </div>
        </div>
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
    // MARK SINGLE NOTIFICATION AS READ
    // ----------------------------------------------------------------
    document.querySelectorAll('.btn-mark-read').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const notifId = this.dataset.id;
            const card = this.closest('.notification-card');
            
            fetch(window.location.pathname + '?ajax_action=mark_read&id=' + encodeURIComponent(notifId), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (card) {
                        card.classList.remove('unread');
                        card.classList.add('read');
                    }
                    this.classList.add('marked');
                    this.innerHTML = '<i class="bi bi-check-circle-fill"></i> Read';
                    
                    // Update badge count
                    const badge = document.getElementById('unreadBadge');
                    let currentCount = parseInt(badge.textContent) || 0;
                    if (currentCount > 0) {
                        currentCount--;
                        badge.textContent = currentCount;
                        if (currentCount === 0) {
                            badge.style.display = 'none';
                        }
                    }
                    showToast('Notification marked as read');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to mark as read', error.message, true);
            });
        });
    });

    // ----------------------------------------------------------------
    // MARK ALL AS READ
    // ----------------------------------------------------------------
    document.getElementById('markAllReadBtn').addEventListener('click', function() {
        fetch(window.location.pathname + '?ajax_action=mark_all_read', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('All notifications marked as read');
                // Update UI - remove unread styling
                document.querySelectorAll('.notification-card.unread').forEach(card => {
                    card.classList.remove('unread');
                    card.classList.add('read');
                    const btn = card.querySelector('.btn-mark-read');
                    if (btn) {
                        btn.classList.add('marked');
                        btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Read';
                    }
                });
                document.getElementById('unreadBadge').textContent = '0';
                document.getElementById('unreadBadge').style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to mark all as read', error.message, true);
        });
    });

    // ----------------------------------------------------------------
    // SEARCH
    // ----------------------------------------------------------------
    let searchTimeout = null;
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const search = this.value.trim();
            const url = new URL(window.location.href);
            if (search) {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search');
            }
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }, 500);
    });

    // ----------------------------------------------------------------
    // FILTER
    // ----------------------------------------------------------------
    document.querySelectorAll('#filterMenu .dropdown-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const filter = this.dataset.filter;
            const url = new URL(window.location.href);
            if (filter && filter !== 'all') {
                url.searchParams.set('filter', filter);
            } else {
                url.searchParams.delete('filter');
            }
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        });
    });

    // ----------------------------------------------------------------
    // PAGINATION
    // ----------------------------------------------------------------
    document.querySelectorAll('#paginationControls .page-link[data-page]').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const page = this.dataset.page;
            if (page) {
                const url = new URL(window.location.href);
                url.searchParams.set('page', page);
                window.location.href = url.toString();
            }
        });
    });

    // ----------------------------------------------------------------
    // AUTO-REFRESH (every 30 seconds)
    // ----------------------------------------------------------------
    let currentFilter = '<?php echo $filter; ?>';
    let currentSearch = '<?php echo $search; ?>';
    let currentPage = <?php echo $page; ?>;

    function refreshNotifications() {
        if (document.hidden) return;

        fetch(window.location.pathname + '?ajax_action=get_notifications&filter=' + currentFilter + '&search=' + encodeURIComponent(currentSearch) + '&page=' + currentPage, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update unread count
                document.getElementById('unreadBadge').textContent = data.unread || 0;
                if (data.unread > 0) {
                    document.getElementById('unreadBadge').style.display = 'inline';
                } else {
                    document.getElementById('unreadBadge').style.display = 'none';
                }

                // Update notification section
                const section = document.getElementById('notificationSection');
                if (data.notifications && data.notifications.length > 0) {
                    let html = '';
                    let currentDate = '';
                    const readNotifications = <?php echo json_encode(isset($_SESSION['read_notifications']) ? $_SESSION['read_notifications'] : []); ?>;
                    
                    data.notifications.forEach(notif => {
                        const notifDate = notif.date ? new Date(notif.date).toLocaleDateString() : '';
                        if (notifDate !== currentDate) {
                            currentDate = notifDate;
                            const displayDate = notifDate === new Date().toLocaleDateString() ? 'Today' : 
                                               notifDate === new Date(Date.now() - 86400000).toLocaleDateString() ? 'Yesterday' :
                                               new Date(notif.date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                            html += `<div class="notification-day"><h5>${displayDate}</h5></div>`;
                        }
                        const isRead = readNotifications.includes(notif.id);
                        const cardClass = isRead ? 'read' : 'unread';
                        const btnClass = isRead ? 'marked' : '';
                        const btnText = isRead ? 'Read' : 'Mark Read';
                        const btnIcon = isRead ? 'bi-check-circle-fill' : 'bi-circle';
                        
                        html += `
                            <div class="notification-card ${cardClass}" data-id="${notif.id}">
                                <div class="notification-icon ${notif.icon_class}">
                                    <i class="bi ${notif.icon}"></i>
                                </div>
                                <div class="notification-content">
                                    <h6>${notif.title}</h6>
                                    <p>${notif.message}</p>
                                    <small>${notif.time} • ${new Date(notif.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</small>
                                </div>
                                <div class="notification-right">
                                    <span class="badge-status ${notif.badge_class}">${notif.badge}</span>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                        <a href="${notif.link}" class="btn-view" target="_blank">${notif.link_text}</a>
                                        <button class="btn-mark-read ${btnClass}" data-id="${notif.id}">
                                            <i class="bi ${btnIcon}"></i>
                                            ${btnText}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    section.innerHTML = html;

                    // Re-bind mark read events
                    document.querySelectorAll('.btn-mark-read').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const notifId = this.dataset.id;
                            const card = this.closest('.notification-card');
                            
                            fetch(window.location.pathname + '?ajax_action=mark_read&id=' + encodeURIComponent(notifId), {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    if (card) {
                                        card.classList.remove('unread');
                                        card.classList.add('read');
                                    }
                                    this.classList.add('marked');
                                    this.innerHTML = '<i class="bi bi-check-circle-fill"></i> Read';
                                    
                                    const badge = document.getElementById('unreadBadge');
                                    let currentCount = parseInt(badge.textContent) || 0;
                                    if (currentCount > 0) {
                                        currentCount--;
                                        badge.textContent = currentCount;
                                        if (currentCount === 0) {
                                            badge.style.display = 'none';
                                        }
                                    }
                                    showToast('Notification marked as read');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showToast('Failed to mark as read', error.message, true);
                            });
                        });
                    });
                } else {
                    section.innerHTML = `
                        <div class="no-notifications">
                            <i class="bi bi-bell-slash"></i>
                            <h4>No Notifications</h4>
                            <p>All caught up! There are no notifications to display.</p>
                        </div>
                    `;
                }

                // Update pagination
                const pagination = document.getElementById('paginationControls');
                let pagHtml = '';
                pagHtml += `<li class="page-item ${data.current_page <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-page="${data.current_page - 1}" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>`;
                for (let i = 1; i <= data.pages; i++) {
                    pagHtml += `<li class="page-item ${i == data.current_page ? 'active' : ''}">
                        <a class="page-link" href="#" data-page="${i}">${i}</a>
                    </li>`;
                }
                pagHtml += `<li class="page-item ${data.current_page >= data.pages ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-page="${data.current_page + 1}" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>`;
                pagination.innerHTML = pagHtml;

                // Re-bind pagination events
                document.querySelectorAll('#paginationControls .page-link[data-page]').forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const page = this.dataset.page;
                        if (page) {
                            const url = new URL(window.location.href);
                            url.searchParams.set('page', page);
                            window.location.href = url.toString();
                        }
                    });
                });
            }
        })
        .catch(error => console.error('Refresh error:', error));
    }

    // Auto-refresh every 30 seconds
    setInterval(refreshNotifications, 30000);

    // Refresh when tab becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshNotifications();
        }
    });

    // ----------------------------------------------------------------
    // INITIAL LOAD
    // ----------------------------------------------------------------
    console.log('Notifications page loaded successfully');
    console.log('Branch: <?php echo htmlspecialchars($branch_name); ?>');
    console.log('Total notifications: <?php echo $totalNotifications; ?>');
    console.log('Unread notifications: <?php echo $unreadCount; ?>');
    </script>
</body>
</html>