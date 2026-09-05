<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a nurse (role_id = 3)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 3
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';
$role_name = 'Nurse';

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
    $username = $userData['username'] ?? 'Nurse';
    $role_name = $userData['role_name'] ?? 'Nurse';
}

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// ----------------------------------------------------------------------
// GET FILTER PARAMETERS
// ----------------------------------------------------------------------
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterType = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$markRead = isset($_GET['mark_read']) ? (int)$_GET['mark_read'] : 0;
$markAllRead = isset($_GET['mark_all_read']) ? true : false;

// Handle Mark as Read
if ($markRead > 0) {
    $updateQuery = "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ii", $markRead, $user_id);
    $stmt->execute();
    header("Location: " . str_replace('&mark_read=' . $markRead, '', $_SERVER['REQUEST_URI']));
    exit();
}

// Handle Mark All as Read
if ($markAllRead) {
    $updateQuery = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: " . str_replace('&mark_all_read=1', '', $_SERVER['REQUEST_URI']));
    exit();
}

// ----------------------------------------------------------------------
// GET NOTIFICATIONS
// ----------------------------------------------------------------------

// Base query
$notifQuery = "
    SELECT 
        n.notification_id,
        n.user_id,
        n.title,
        n.message,
        n.notification_type,
        n.is_read,
        n.created_at,
        CASE 
            WHEN n.notification_type = 'follow_up' THEN 'followup'
            WHEN n.notification_type = 'low_stock' THEN 'low'
            WHEN n.notification_type = 'expiring' THEN 'expiring'
            WHEN n.notification_type = 'vaccination' THEN 'vaccination'
            WHEN n.notification_type = 'patient_record' THEN 'patient'
            ELSE 'general'
        END as notif_category,
        CASE 
            WHEN n.notification_type = 'follow_up' THEN 'Follow-Up Due'
            WHEN n.notification_type = 'low_stock' THEN 'Low Stock Alert'
            WHEN n.notification_type = 'expiring' THEN 'Expiring Vaccine Alert'
            WHEN n.notification_type = 'vaccination' THEN 'Vaccination Administered'
            WHEN n.notification_type = 'patient_record' THEN 'Patient Record Update'
            ELSE 'Notification'
        END as display_title,
        DATEDIFF(NOW(), n.created_at) as days_ago,
        DATE(n.created_at) as notif_date
    FROM notifications n
    WHERE n.user_id = ?
";

$params = [$user_id];
$types = "i";

// Search filter
if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $notifQuery .= " AND (n.title LIKE ? OR n.message LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

// Type filter
if ($filterType != 'all') {
    if ($filterType == 'unread') {
        $notifQuery .= " AND n.is_read = 0";
    } elseif ($filterType == 'followup') {
        $notifQuery .= " AND n.notification_type = 'follow_up'";
    } elseif ($filterType == 'low_stock') {
        $notifQuery .= " AND n.notification_type = 'low_stock'";
    } elseif ($filterType == 'expiring') {
        $notifQuery .= " AND n.notification_type = 'expiring'";
    } elseif ($filterType == 'vaccination') {
        $notifQuery .= " AND n.notification_type = 'vaccination'";
    }
}

$notifQuery .= " ORDER BY n.created_at DESC";

$stmt = $conn->prepare($notifQuery);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$notifResult = $stmt->get_result();

$notifications = [];
while ($row = $notifResult->fetch_assoc()) {
    $notifications[] = $row;
}

// ----------------------------------------------------------------------
// GET STATISTICS
// ----------------------------------------------------------------------
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN notification_type = 'follow_up' AND is_read = 0 THEN 1 ELSE 0 END) as followup_unread,
        SUM(CASE WHEN notification_type = 'low_stock' AND is_read = 0 THEN 1 ELSE 0 END) as low_stock_unread,
        SUM(CASE WHEN notification_type = 'expiring' AND is_read = 0 THEN 1 ELSE 0 END) as expiring_unread,
        SUM(CASE WHEN notification_type = 'vaccination' AND is_read = 0 THEN 1 ELSE 0 END) as vaccination_unread
    FROM notifications
    WHERE user_id = ?
";
$stmt = $conn->prepare($statsQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$statsResult = $stmt->get_result();
$stats = $statsResult->fetch_assoc();

$totalNotifications = $stats['total'] ?? 0;
$unreadCount = $stats['unread'] ?? 0;
$followupUnread = $stats['followup_unread'] ?? 0;
$lowStockUnread = $stats['low_stock_unread'] ?? 0;
$expiringUnread = $stats['expiring_unread'] ?? 0;
$vaccinationUnread = $stats['vaccination_unread'] ?? 0;

// ----------------------------------------------------------------------
// GET FOLLOW-UP PATIENTS FOR TODAY
// ----------------------------------------------------------------------
$today = date('Y-m-d');
$followUpTodayQuery = "
    SELECT 
        p.full_name as patient_name,
        c.case_id,
        c.case_number,
        v.vaccination_id,
        v.dose_number,
        v.next_schedule,
        v.vaccine_name,
        r.registry_number as case_no
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    INNER JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.vaccination_status = 'Scheduled'
    AND DATE(v.next_schedule) = ?
    ORDER BY v.next_schedule ASC
    LIMIT 5
";
$stmt = $conn->prepare($followUpTodayQuery);
$stmt->bind_param("ss", $branch_id, $today);
$stmt->execute();
$followUpTodayResult = $stmt->get_result();
$followUpToday = [];
while ($row = $followUpTodayResult->fetch_assoc()) {
    $followUpToday[] = $row;
}

// ----------------------------------------------------------------------
// GET LOW STOCK ITEMS
// ----------------------------------------------------------------------
$lowStockQuery = "
    SELECT 
        i.item_name,
        s.quantity_available,
        i.minimum_stock,
        i.item_id,
        u.unit_name
    FROM inventory_stocks s
    INNER JOIN inventory_items i ON s.item_id = i.item_id
    LEFT JOIN units u ON i.unit_id = u.unit_id
    WHERE s.branch_id = ?
    AND s.quantity_available <= i.minimum_stock
    ORDER BY (s.quantity_available / i.minimum_stock) ASC
    LIMIT 5
";
$stmt = $conn->prepare($lowStockQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockResult = $stmt->get_result();
$lowStockItems = [];
while ($row = $lowStockResult->fetch_assoc()) {
    $lowStockItems[] = $row;
}

// ----------------------------------------------------------------------
// GET EXPIRING ITEMS
// ----------------------------------------------------------------------
$expiringQuery = "
    SELECT 
        i.item_name,
        s.expiration_date,
        s.quantity_available,
        s.item_id,
        u.unit_name,
        DATEDIFF(s.expiration_date, CURDATE()) as days_until_expiry
    FROM inventory_stocks s
    INNER JOIN inventory_items i ON s.item_id = i.item_id
    LEFT JOIN units u ON i.unit_id = u.unit_id
    WHERE s.branch_id = ?
    AND s.expiration_date IS NOT NULL
    AND s.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND s.quantity_available > 0
    ORDER BY s.expiration_date ASC
    LIMIT 5
";
$stmt = $conn->prepare($expiringQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$expiringResult = $stmt->get_result();
$expiringItems = [];
while ($row = $expiringResult->fetch_assoc()) {
    $expiringItems[] = $row;
}

// ----------------------------------------------------------------------
// GET RECENT VACCINATIONS
// ----------------------------------------------------------------------
$recentVaccinationsQuery = "
    SELECT 
        p.full_name as patient_name,
        v.vaccine_name,
        v.dose_number,
        v.date_administered,
        v.vaccination_id,
        c.case_id,
        r.registry_number as case_no
    FROM vaccination_records v
    INNER JOIN patients p ON v.patient_id = p.patient_id
    INNER JOIN animal_bite_cases c ON v.case_id = c.case_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    WHERE v.branch_id = ?
    AND v.vaccination_status = 'Completed'
    AND v.date_administered IS NOT NULL
    ORDER BY v.date_administered DESC
    LIMIT 5
";
$stmt = $conn->prepare($recentVaccinationsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentVaccinationsResult = $stmt->get_result();
$recentVaccinations = [];
while ($row = $recentVaccinationsResult->fetch_assoc()) {
    $recentVaccinations[] = $row;
}

// ----------------------------------------------------------------------
// GROUP NOTIFICATIONS BY DATE
// ----------------------------------------------------------------------
$groupedNotifications = [];
foreach ($notifications as $notif) {
    $dateKey = $notif['notif_date'];
    if (!isset($groupedNotifications[$dateKey])) {
        $groupedNotifications[$dateKey] = [];
    }
    $groupedNotifications[$dateKey][] = $notif;
}

// Get date labels
function getDateLabel($date) {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date == $today) return 'Today';
    if ($date == $tomorrow) return 'Tomorrow';
    if ($date == $yesterday) return 'Yesterday';
    return date('F d, Y', strtotime($date));
}

// ----------------------------------------------------------------------
// AJAX HANDLING
// ----------------------------------------------------------------------
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = isset($_GET['ajax_action']) ? $_GET['ajax_action'] : '';
    
    switch ($action) {
        case 'get_notifications':
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
            
            $query = "
                SELECT 
                    n.notification_id,
                    n.title,
                    n.message,
                    n.notification_type,
                    n.is_read,
                    n.created_at,
                    CASE 
                        WHEN n.notification_type = 'follow_up' THEN 'followup'
                        WHEN n.notification_type = 'low_stock' THEN 'low'
                        WHEN n.notification_type = 'expiring' THEN 'expiring'
                        WHEN n.notification_type = 'vaccination' THEN 'vaccination'
                        ELSE 'general'
                    END as notif_category,
                    DATE(n.created_at) as notif_date
                FROM notifications n
                WHERE n.user_id = ?
            ";
            
            $params = [$user_id];
            $types = "i";
            
            if (!empty($search)) {
                $searchTerm = "%$search%";
                $query .= " AND (n.title LIKE ? OR n.message LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= "ss";
            }
            
            if ($filter != 'all') {
                if ($filter == 'unread') {
                    $query .= " AND n.is_read = 0";
                } elseif ($filter == 'followup') {
                    $query .= " AND n.notification_type = 'follow_up'";
                } elseif ($filter == 'low_stock') {
                    $query .= " AND n.notification_type = 'low_stock'";
                } elseif ($filter == 'expiring') {
                    $query .= " AND n.notification_type = 'expiring'";
                } elseif ($filter == 'vaccination') {
                    $query .= " AND n.notification_type = 'vaccination'";
                }
            }
            
            $query .= " ORDER BY n.created_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notifs = [];
            while ($row = $result->fetch_assoc()) {
                $notifs[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifs,
                'count' => count($notifs)
            ]);
            break;
            
        case 'mark_read':
            $notif_id = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            if ($notif_id > 0) {
                $updateQuery = "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("ii", $notif_id, $user_id);
                $stmt->execute();
                echo json_encode(['success' => true, 'message' => 'Marked as read']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
            }
            break;
            
        case 'mark_all_read':
            $updateQuery = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            break;
            
        case 'get_stats':
            $statsQuery = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
                FROM notifications
                WHERE user_id = ?
            ";
            $stmt = $conn->prepare($statsQuery);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $statsResult = $stmt->get_result();
            $stats = $statsResult->fetch_assoc();
            
            echo json_encode([
                'success' => true,
                'total' => $stats['total'] ?? 0,
                'unread' => $stats['unread'] ?? 0
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
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #f3f4f6; 
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f3f4f6;
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
            position: sticky;
            top: 0;
            z-index: 99;
        }
        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
            letter-spacing: -0.3px;
        }
        .topbar h3 .badge-unread {
            font-size: 14px;
            font-weight: 600;
            background: var(--accent);
            color: white;
            padding: 2px 12px;
            border-radius: 20px;
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

        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 1.5px solid #64748b;
            border-radius: 30px;
            padding: 0 16px;
            height: 42px;
            width: 320px;
            transition: border 0.2s;
        }
        .search-box:focus-within {
            border: 1.5px solid var(--primary);
            box-shadow: 0 0 0 2px rgba(43,58,140,0.1);
        }
        .search-box i {
            color: #94a3b8;
            font-size: 1.1rem;
            margin-right: 8px;
        }
        .search-box input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 14px;
            background: transparent;
            color: #334155;
        }
        .search-box input::placeholder {
            color: #94a3b8;
            font-weight: 500;
        }

        .filter-dropdown {
            position: relative;
            display: inline-block;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 18px;
            height: 42px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-filter:hover {
            background: #1d2863;
        }
        .btn-filter i {
            font-size: 0.9rem;
        }

        .filter-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            padding: 8px 0;
            min-width: 200px;
            z-index: 100;
            margin-top: 4px;
            border: 1px solid #e9edf5;
        }
        .filter-menu.show {
            display: block;
        }
        .filter-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            color: #334155;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.15s;
        }
        .filter-menu a:hover {
            background: #f1f5f9;
        }
        .filter-menu a .badge-filter {
            margin-left: auto;
            background: var(--gray-200);
            padding: 0 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        .filter-menu a .badge-filter.unread-badge {
            background: var(--accent);
            color: white;
        }

        .branch-text {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-mark-all {
            background: var(--success);
            color: white;
            border: none;
            padding: 0 22px;
            height: 42px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
            cursor: pointer;
        }
        .btn-mark-all:hover {
            background: #16a34a;
        }

        /* Date Group Header */
        .date-header {
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
            margin: 24px 0 16px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .date-header:first-of-type {
            margin-top: 0;
        }
        .date-header .count-badge {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            padding: 0 12px;
            border-radius: 12px;
        }

        /* Notification Card */
        .notif-item {
            display: flex;
            background: white;
            border-radius: 12px;
            margin-bottom: 18px;
            padding: 18px 20px;
            border: 1px solid #e2e8f0;
            position: relative;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .notif-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .notif-item.unread {
            background: #f8fafc;
            border-color: #dbeafe;
        }

        /* Left Accent Borders */
        .border-followup { border-left: 6px solid var(--warning); }
        .border-low { border-left: 6px solid var(--danger); }
        .border-expiring { border-left: 6px solid var(--warning); }
        .border-vaccination { border-left: 6px solid #3b82f6; }
        .border-patient { border-left: 6px solid #8b5cf6; }
        .border-general { border-left: 6px solid #64748b; }

        .notif-icon-wrap {
            display: flex;
            align-items: flex-start;
            padding-right: 16px;
        }

        .notif-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .icon-followup { background: #fef3c7; color: #b45309; }
        .icon-low { background: #fee2e2; color: #dc2626; }
        .icon-expiring { background: #fef3c7; color: #b45309; }
        .icon-vaccination { background: #dbeafe; color: #2563eb; }
        .icon-patient { background: #ede9fe; color: #7c3aed; }
        .icon-general { background: #f1f5f9; color: #64748b; }

        .notif-content {
            flex: 1;
            padding: 2px 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .notif-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 15px;
            margin-bottom: 3px;
        }

        .notif-desc {
            font-size: 14px;
            color: #475569;
            margin-bottom: 4px;
            line-height: 1.4;
            white-space: pre-wrap;
        }

        .notif-time {
            font-size: 12px;
            color: #94a3b8;
        }

        .notif-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            padding-left: 16px;
            gap: 8px;
        }

        .badge-status-pill {
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-followup { background: #fef3c7; color: #b45309; }
        .badge-low { background: #fee2e2; color: #dc2626; }
        .badge-expiring { background: #fef3c7; color: #b45309; }
        .badge-vaccination { background: #dbeafe; color: #2563eb; }
        .badge-patient { background: #ede9fe; color: #7c3aed; }
        .badge-general { background: #f1f5f9; color: #64748b; }
        .badge-read { background: #e2e8f0; color: #64748b; }

        .notif-action-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s;
            cursor: pointer;
            text-decoration: none;
        }
        .notif-action-btn:hover {
            background: #1d2863;
            color: white;
        }
        .notif-action-btn.btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }
        .notif-action-btn.btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .mark-read-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #64748b;
            cursor: pointer;
            margin-top: 2px;
            transition: color 0.2s;
        }
        .mark-read-row:hover {
            color: var(--primary);
        }
        .mark-read-row .circle-icon {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            display: inline-block;
            transition: border-color 0.2s;
        }
        .mark-read-row:hover .circle-icon {
            border-color: var(--primary);
        }
        .mark-read-row.read .circle-icon {
            background: var(--success);
            border-color: var(--success);
        }
        .mark-read-row.read .circle-icon::after {
            content: '✓';
            color: white;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }

        .no-notifications {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        .no-notifications i {
            font-size: 64px;
            display: block;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .no-notifications h5 {
            color: #475569;
            font-weight: 600;
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

        /* Toast */
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
            padding: 14px 18px;
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
            font-size: 22px;
            color: var(--success);
        }
        .toast-custom.error .toast-icon {
            color: var(--danger);
        }
        .toast-custom .toast-msg {
            font-size: 13px;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .search-box { width: 100%; max-width: 300px; }
            .toolbar-left { width: 100%; }
            .btn-mark-all { width: 100%; justify-content: center; }
        }

        @media (max-width: 576px) {
            .topbar { padding: 0 16px; height: 70px; }
            .topbar h3 { font-size: 20px; }
            .content { padding: 20px 16px; }
            .notif-item { padding: 14px 16px; gap: 12px; flex-wrap: wrap; }
            .notif-icon { width: 34px; height: 34px; font-size: 16px; }
            .notif-title { font-size: 14px; }
            .notif-desc { font-size: 13px; }
            .notif-actions { align-items: flex-start; margin-top: 8px; width: 100%; }
            .notif-actions .badge-status-pill { align-self: flex-start; }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR ========== -->
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
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
            <li><a class="active" href="Nurse_Notification.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
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
        <h3>
            Notifications
            <?php if ($unreadCount > 0): ?>
            <span class="badge-unread"><?php echo $unreadCount; ?> unread</span>
            <?php endif; ?>
        </h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($username); ?>
            <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Nurse</span>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- ========== TOOLBAR ========== -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Search Notifications..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>
                <div class="filter-dropdown">
                    <button class="btn-filter" id="filterToggle">
                        <i class="bi bi-funnel-fill"></i> 
                        Filters 
                        <?php if ($filterType != 'all'): ?>
                        <span class="badge" style="background:white;color:var(--primary);font-size:10px;padding:0 6px;">1</span>
                        <?php endif; ?>
                        <i class="bi bi-caret-down-fill" style="font-size: 10px;"></i>
                    </button>
                    <div class="filter-menu" id="filterMenu">
                        <a href="?filter=all<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>" class="<?php echo $filterType == 'all' ? 'active' : ''; ?>">
                            <i class="bi bi-list-ul"></i> All
                            <span class="badge-filter"><?php echo $totalNotifications; ?></span>
                        </a>
                        <a href="?filter=unread<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">
                            <i class="bi bi-envelope"></i> Unread
                            <span class="badge-filter unread-badge"><?php echo $unreadCount; ?></span>
                        </a>
                        <a href="?filter=followup<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">
                            <i class="bi bi-clock"></i> Follow-ups
                            <span class="badge-filter"><?php echo $followupUnread; ?></span>
                        </a>
                        <a href="?filter=low_stock<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">
                            <i class="bi bi-exclamation-triangle"></i> Low Stock
                            <span class="badge-filter"><?php echo $lowStockUnread; ?></span>
                        </a>
                        <a href="?filter=expiring<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">
                            <i class="bi bi-clock-history"></i> Expiring
                            <span class="badge-filter"><?php echo $expiringUnread; ?></span>
                        </a>
                        <a href="?filter=vaccination<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">
                            <i class="bi bi-shield-check"></i> Vaccinations
                            <span class="badge-filter"><?php echo $vaccinationUnread; ?></span>
                        </a>
                    </div>
                </div>
                <span class="branch-text"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($branch_name); ?></span>
            </div>
            <button class="btn-mark-all" id="markAllReadBtn">
                <i class="bi bi-check-lg"></i> Mark All as Read
            </button>
        </div>

        <!-- ========== NOTIFICATIONS LIST ========== -->
        <div id="notificationsContainer">
            <?php if (empty($notifications)): ?>
            <div class="no-notifications">
                <i class="bi bi-bell-slash"></i>
                <h5>No Notifications</h5>
                <p>You're all caught up! No new notifications to display.</p>
            </div>
            <?php else: ?>
            <?php foreach ($groupedNotifications as $date => $notifs): ?>
            <div class="date-header">
                <?php echo getDateLabel($date); ?>
                <span class="count-badge"><?php echo count($notifs); ?></span>
            </div>

            <?php foreach ($notifs as $notif): ?>
            <?php 
                $category = $notif['notif_category'];
                $isRead = $notif['is_read'];
                
                $borderClass = 'border-' . $category;
                $iconClass = 'icon-' . $category;
                $badgeClass = 'badge-' . $category;
                
                $iconMap = [
                    'followup' => 'bi-clock',
                    'low' => 'bi-exclamation-triangle-fill',
                    'expiring' => 'bi-clock-history',
                    'vaccination' => 'bi-shield-check',
                    'patient' => 'bi-person-plus',
                    'general' => 'bi-bell'
                ];
                $icon = $iconMap[$category] ?? 'bi-bell';
                
                $statusLabel = $isRead ? 'Read' : ucfirst($category);
                if ($category == 'followup') $statusLabel = $isRead ? 'Read' : 'Pending';
                elseif ($category == 'low') $statusLabel = $isRead ? 'Read' : 'Low Stock';
                elseif ($category == 'expiring') $statusLabel = $isRead ? 'Read' : 'Expiring';
                elseif ($category == 'vaccination') $statusLabel = $isRead ? 'Read' : 'Vaccination';
                elseif ($category == 'patient') $statusLabel = $isRead ? 'Read' : 'Patient Update';
                
                $timeAgo = '';
                if ($notif['days_ago'] == 0) {
                    $timeAgo = 'Today, ' . date('h:i A', strtotime($notif['created_at']));
                } elseif ($notif['days_ago'] == 1) {
                    $timeAgo = 'Yesterday, ' . date('h:i A', strtotime($notif['created_at']));
                } else {
                    $timeAgo = date('M d, Y h:i A', strtotime($notif['created_at']));
                }
            ?>
            <div class="notif-item <?php echo $borderClass; ?> <?php echo $isRead ? '' : 'unread'; ?>" data-notif-id="<?php echo $notif['notification_id']; ?>">
                <div class="notif-icon-wrap">
                    <div class="notif-icon <?php echo $iconClass; ?>">
                        <i class="bi <?php echo $icon; ?>"></i>
                    </div>
                </div>
                <div class="notif-content">
                    <div class="notif-title"><?php echo htmlspecialchars($notif['display_title']); ?></div>
                    <div class="notif-desc"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                    <div class="notif-time"><?php echo $timeAgo; ?></div>
                </div>
                <div class="notif-actions">
                    <span class="badge-status-pill <?php echo $isRead ? 'badge-read' : $badgeClass; ?>">
                        <?php echo $statusLabel; ?>
                    </span>
                    <button class="notif-action-btn view-action-btn" data-notif-id="<?php echo $notif['notification_id']; ?>">
                        View Details
                    </button>
                    <?php if (!$isRead): ?>
                    <div class="mark-read-row" data-notif-id="<?php echo $notif['notification_id']; ?>">
                        <span class="circle-icon"></span> Mark Read
                    </div>
                    <?php else: ?>
                    <div class="mark-read-row read">
                        <span class="circle-icon"></span> Read
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ========== QUICK STATS FOOTER ========== -->
        <?php if (!empty($followUpToday) || !empty($lowStockItems) || !empty($expiringItems)): ?>
        <div class="row mt-4 g-3">
            <?php if (!empty($followUpToday)): ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="card-title text-warning"><i class="bi bi-clock"></i> Today's Follow-ups</h6>
                        <?php foreach ($followUpToday as $fu): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <span><?php echo htmlspecialchars($fu['patient_name']); ?></span>
                            <small class="text-muted"><?php echo htmlspecialchars($fu['case_no'] ?? $fu['case_number']); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($followUpToday) >= 5): ?>
                        <small class="text-muted">+ more scheduled today</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($lowStockItems)): ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="card-title text-danger"><i class="bi bi-exclamation-triangle"></i> Low Stock Alerts</h6>
                        <?php foreach ($lowStockItems as $item): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <span><?php echo htmlspecialchars($item['item_name']); ?></span>
                            <small class="text-danger"><?php echo $item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit_name'] ?? ''); ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($expiringItems)): ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="card-title text-warning"><i class="bi bi-clock-history"></i> Expiring Soon</h6>
                        <?php foreach ($expiringItems as $item): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <span><?php echo htmlspecialchars($item['item_name']); ?></span>
                            <small class="text-warning"><?php echo $item['days_until_expiry']; ?> days</small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<!-- Toast Container -->
<div class="toast-container-custom" id="toastContainer"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ----------------------------------------------------------------
// TOAST
// ----------------------------------------------------------------
function showToast(msg, sub = '', isError = false) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast-custom' + (isError ? ' error' : '');
    const icon = isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill';
    toast.innerHTML = `
        <span class="toast-icon"><i class="bi ${icon}"></i></span>
        <div class="toast-msg">${msg} ${sub ? '<br><small>' + sub + '</small>' : ''}</div>
    `;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

// ----------------------------------------------------------------
// LOADING
// ----------------------------------------------------------------
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('show');
}
function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('show');
}

// ----------------------------------------------------------------
// FILTER DROPDOWN
// ----------------------------------------------------------------
document.getElementById('filterToggle').addEventListener('click', function(e) {
    e.stopPropagation();
    document.getElementById('filterMenu').classList.toggle('show');
});

document.addEventListener('click', function() {
    document.getElementById('filterMenu').classList.remove('show');
});

// ----------------------------------------------------------------
// SEARCH
// ----------------------------------------------------------------
let searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const search = this.value;
        const url = new URL(window.location.href);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }
        window.location.href = url.toString();
    }, 500);
});

// Enter key for search
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        clearTimeout(searchTimeout);
        const search = this.value;
        const url = new URL(window.location.href);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }
        window.location.href = url.toString();
    }
});

// ----------------------------------------------------------------
// MARK AS READ (Individual)
// ----------------------------------------------------------------
document.querySelectorAll('.mark-read-row').forEach(row => {
    row.addEventListener('click', function() {
        const notifId = this.dataset.notifId;
        if (!notifId) return;
        
        showLoading();
        
        const formData = new FormData();
        formData.append('notification_id', notifId);
        
        fetch(window.location.pathname + '?ajax_action=mark_read', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ Success', 'Notification marked as read');
                // Update UI
                const parent = this.closest('.notif-item');
                if (parent) {
                    parent.classList.remove('unread');
                    const badge = parent.querySelector('.badge-status-pill');
                    if (badge) {
                        badge.className = 'badge-status-pill badge-read';
                        badge.textContent = 'Read';
                    }
                    this.className = 'mark-read-row read';
                    this.innerHTML = '<span class="circle-icon"></span> Read';
                }
                // Update unread count
                const unreadBadge = document.querySelector('.badge-unread');
                if (unreadBadge) {
                    const current = parseInt(unreadBadge.textContent) || 0;
                    if (current > 0) {
                        unreadBadge.textContent = current - 1;
                        if (unreadBadge.textContent == '0') {
                            unreadBadge.remove();
                        }
                    }
                }
            } else {
                showToast('Error', data.error || 'Failed to mark as read', true);
            }
        })
        .catch(error => {
            hideLoading();
            showToast('Error', 'Network error occurred', true);
            console.error('Mark read error:', error);
        });
    });
});

// ----------------------------------------------------------------
// MARK ALL AS READ
// ----------------------------------------------------------------
document.getElementById('markAllReadBtn').addEventListener('click', function() {
    showLoading();
    
    fetch(window.location.pathname + '?ajax_action=mark_all_read', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast('✅ Success', 'All notifications marked as read');
            // Reload page to reflect changes
            setTimeout(() => location.reload(), 600);
        } else {
            showToast('Error', data.error || 'Failed to mark all as read', true);
        }
    })
    .catch(error => {
        hideLoading();
        showToast('Error', 'Network error occurred', true);
        console.error('Mark all read error:', error);
    });
});

// ----------------------------------------------------------------
// VIEW ACTION BUTTONS
// ----------------------------------------------------------------
document.querySelectorAll('.view-action-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const notifId = this.dataset.notifId;
        // Find the notification item
        const parent = this.closest('.notif-item');
        const title = parent?.querySelector('.notif-title')?.textContent || 'Notification';
        const desc = parent?.querySelector('.notif-desc')?.textContent || '';
        
        // Show details in a simple alert or modal
        alert('📋 ' + title + '\n\n' + desc + '\n\nNotification ID: ' + notifId);
    });
});

// ----------------------------------------------------------------
// AUTO-REFRESH
// ----------------------------------------------------------------
function refreshStats() {
    fetch(window.location.pathname + '?ajax_action=get_stats', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const unreadBadge = document.querySelector('.badge-unread');
            if (unreadBadge) {
                if (data.unread > 0) {
                    unreadBadge.textContent = data.unread;
                } else {
                    unreadBadge.remove();
                }
            } else if (data.unread > 0) {
                const title = document.querySelector('.topbar h3');
                if (title) {
                    const badge = document.createElement('span');
                    badge.className = 'badge-unread';
                    badge.textContent = data.unread;
                    title.appendChild(badge);
                }
            }
        }
    })
    .catch(error => console.error('Stats refresh error:', error));
}

// Refresh every 60 seconds
setInterval(() => {
    if (!document.hidden) {
        refreshStats();
    }
}, 60000);

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        refreshStats();
    }
});

console.log('📬 Nurse Notification System loaded');
console.log('Branch:', '<?php echo htmlspecialchars($branch_name); ?>');
console.log('Total notifications:', '<?php echo $totalNotifications; ?>');
console.log('Unread:', '<?php echo $unreadCount; ?>');
</script>
</body>
</html>