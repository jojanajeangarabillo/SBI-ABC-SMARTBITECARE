<?php
session_start();
require_once __DIR__ . '/sources/db_connect.php';
require_once __DIR__ . '/sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';

$user = workflowRequireUser($conn, 1);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$username = (string)($user['username'] ?? 'Super Admin');
$notification_count = getUnreadNotificationCount($conn, $userId);
$csrf = workflowCsrfToken();

function superAdminNotificationPageUrl(int $page): string
{
    return 'SuperAdmin_Notifications.php?' . http_build_query([
        'page' => max(1, $page),
    ]);
}

function superAdminNotificationDateLabel(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'Earlier';

    $day = date('Y-m-d', $timestamp);
    if ($day === date('Y-m-d')) return 'Today';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('F j, Y', $timestamp);
}

function superAdminNotificationTypeLabel(?string $type): string
{
    $value = trim((string)$type);
    if ($value === '') return 'System';
    $value = str_ireplace(['prediction', 'predictive'], ['forecasting', 'forecast'], $value);
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function superAdminNotificationPresentation(?string $type, ?string $title): array
{
    $value = strtolower(trim((string)$type . ' ' . (string)$title));

    if (str_contains($value, 'fail') || str_contains($value, 'error') || str_contains($value, 'denied')) {
        return ['bi-exclamation-triangle-fill', 'alert-icon', 'SuperAdmin_AuditLogs.php', 'Review Log'];
    }
    if (str_contains($value, 'branch')) {
        return ['bi-buildings-fill', 'branch-icon', 'SuperAdmin_CombinedManagement.php?tab=branches', 'View Branches'];
    }
    if (str_contains($value, 'user') || str_contains($value, 'admin') || str_contains($value, 'account')) {
        return ['bi-person-badge-fill', 'user-icon', 'SuperAdmin_CombinedManagement.php?tab=admins', 'View Admins'];
    }
    if (str_contains($value, 'forecast') || str_contains($value, 'performance')) {
        return ['bi-graph-up-arrow', 'forecast-icon', 'SuperAdmin_BranchPerformanceMonitoring.php', 'View Performance'];
    }
    if (str_contains($value, 'stock') || str_contains($value, 'inventory') || str_contains($value, 'supply')) {
        return ['bi-box-seam-fill', 'inventory-icon', 'SuperAdmin_BranchPerformanceMonitoring.php', 'View Branches'];
    }
    if (str_contains($value, 'philhealth')) {
        return ['bi-file-medical-fill', 'health-icon', 'SuperAdmin_AuditLogs.php', 'Review Log'];
    }
    if (str_contains($value, 'patient') || str_contains($value, 'registry') || str_contains($value, 'vaccin')) {
        return ['bi-heart-pulse-fill', 'health-icon', 'SuperAdmin_AuditLogs.php', 'Review Log'];
    }

    return ['bi-bell-fill', '', 'SuperAdmin_AuditLogs.php', 'Review Log'];
}

/**
 * Convert important audit events into persistent Super Admin notifications.
 * source_key and the unique (user_id, source_key) index prevent duplicates.
 */
function syncSuperAdminNotifications(mysqli $conn, int $userId): void
{
    try {
        $sql =
            "INSERT IGNORE INTO notifications
                (user_id, title, message, notification_type, source_key, is_read, created_at)
             SELECT
                ?,
                CASE
                    WHEN LOWER(a.action) LIKE '%failed%' OR LOWER(a.action) LIKE '%error%'
                        THEN 'System Action Requires Attention'
                    WHEN LOWER(a.action) LIKE '%added branch%' OR LOWER(a.action) LIKE '%created branch%'
                        THEN 'New Branch Added'
                    WHEN LOWER(a.action) LIKE '%deactivated%' OR LOWER(a.action) LIKE '%inactive%'
                        THEN 'Branch or Account Deactivated'
                    WHEN LOWER(a.action) LIKE '%created new branch admin%'
                         OR LOWER(a.action) LIKE '%created branch admin%'
                        THEN 'New Branch Admin Created'
                    WHEN LOWER(a.action) LIKE '%created%user%' OR LOWER(a.action) LIKE '%added%user%'
                        THEN 'New User Account Created'
                    WHEN LOWER(a.action) LIKE '%updated%role%' OR LOWER(a.action) LIKE '%changed%role%'
                        THEN 'User Role Updated'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%forecast%'
                        THEN 'Forecasting Activity'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%inventory%'
                         OR LOWER(COALESCE(a.module,'')) LIKE '%stock%'
                        THEN 'Inventory Activity'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%philhealth%'
                        THEN 'PhilHealth Activity'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%registry%'
                        THEN 'Registry Activity'
                    ELSE CONCAT(COALESCE(NULLIF(a.module,''), 'System'), ' Update')
                END,
                CONCAT(
                    COALESCE(NULLIF(b.branch_name,''), NULLIF(a.branch_id,''), 'System'),
                    ': ',
                    a.action
                ),
                CASE
                    WHEN LOWER(a.action) LIKE '%failed%' OR LOWER(a.action) LIKE '%error%'
                        THEN 'system_alert'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%branch%'
                         OR LOWER(a.action) LIKE '%branch%'
                        THEN 'branch_management'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%user%'
                         OR LOWER(a.action) LIKE '%user%'
                         OR LOWER(a.action) LIKE '%admin%'
                        THEN 'user_management'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%forecast%'
                        THEN 'forecasting'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%inventory%'
                         OR LOWER(COALESCE(a.module,'')) LIKE '%stock%'
                        THEN 'inventory'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%philhealth%'
                        THEN 'philhealth'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%registry%'
                        THEN 'registry'
                    WHEN LOWER(COALESCE(a.module,'')) LIKE '%vaccin%'
                        THEN 'vaccination'
                    ELSE 'system'
                END,
                CONCAT('superadmin_audit_', a.log_id),
                0,
                a.created_at
             FROM audit_logs a
             LEFT JOIN branches b ON b.branch_id = a.branch_id
             WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND COALESCE(a.module,'') NOT IN
                   ('Dashboard','Audit Logs','Login System','Notifications','Reports')
               AND LOWER(a.action) NOT LIKE 'viewed %'
               AND LOWER(a.action) NOT LIKE 'login %'
               AND LOWER(a.action) NOT LIKE 'logout%'
               AND LOWER(a.action) NOT LIKE 'generated %'
             ORDER BY a.created_at DESC, a.log_id DESC
             LIMIT 500";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Notification synchronization must not make the page unavailable.
        error_log('Super Admin notification synchronization failed: ' . $e->getMessage());
    }
}

syncSuperAdminNotifications($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'mark_one') {
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            if ($notificationId < 1) {
                throw new RuntimeException('Choose a valid notification.');
            }

            $stmt = $conn->prepare(
                'UPDATE notifications
                 SET is_read = 1
                 WHERE notification_id = ? AND user_id = ?'
            );
            $stmt->bind_param('ii', $notificationId, $userId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed < 1) {
                $check = $conn->prepare(
                    'SELECT notification_id
                     FROM notifications
                     WHERE notification_id = ? AND user_id = ? LIMIT 1'
                );
                $check->bind_param('ii', $notificationId, $userId);
                $check->execute();
                $exists = (bool)$check->get_result()->fetch_assoc();
                $check->close();
                if (!$exists) throw new RuntimeException('Notification was not found.');
            }

            workflowAudit($conn, $userId, $branchId, 'Marked notification ' . $notificationId . ' as read', 'Notifications');
            workflowFlash('success', 'Notification marked as read.');
        } elseif ($action === 'mark_all') {
            $stmt = $conn->prepare(
                'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            workflowAudit($conn, $userId, $branchId, 'Marked all Super Admin notifications as read (' . $changed . ' updated)', 'Notifications');
            workflowFlash(
                'success',
                $changed > 0
                    ? $changed . ' notification' . ($changed === 1 ? '' : 's') . ' marked as read.'
                    : 'All notifications are already marked as read.'
            );
        } else {
            throw new RuntimeException('Unsupported notification action.');
        }
    } catch (Throwable $e) {
        workflowFlash('danger', $e->getMessage());
    }

    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));
    header('Location: ' . superAdminNotificationPageUrl($returnPage));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$countStmt = $conn->prepare(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread
     FROM notifications
     WHERE user_id = ?'
);
$countStmt->bind_param('i', $userId);
$countStmt->execute();
$counts = $countStmt->get_result()->fetch_assoc() ?: [];
$countStmt->close();

$totalRows = (int)($counts['total'] ?? 0);
$unreadCount = (int)($counts['unread'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listStmt = $conn->prepare(
    'SELECT notification_id, title, message, notification_type,
            source_key, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC, notification_id DESC
     LIMIT ? OFFSET ?'
);
$listStmt->bind_param('iii', $userId, $perPage, $offset);
$listStmt->execute();
$notifications = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$groupedNotifications = [];
foreach ($notifications as $notification) {
    $dateLabel = superAdminNotificationDateLabel((string)$notification['created_at']);
    $groupedNotifications[$dateLabel][] = $notification;
}

$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Notifications</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS – matches image style
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

        /* ---- notification list ---- */
        .notif-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 8px 0;
            overflow: hidden;
        }
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 18px 28px;
            border-bottom: 1px solid #edf1f8;
            transition: 0.1s;
        }
        .notif-item.unread {
            background: #f7f8ff;
            border-left: 4px solid var(--primary);
            padding-left: 24px;
        }
        .notif-item:last-child {
            border-bottom: none;
        }
        .notif-item:hover {
            background: #f8faff;
        }
        .notif-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e7ecfc;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .notif-icon i {
            font-size: 20px;
            color: var(--primary);
        }
        .notif-icon.alert-icon {
            background: #fde8e8;
        }
        .notif-icon.alert-icon i {
            color: var(--accent);
        }
        .notif-icon.success-icon {
            background: #e0f5e0;
        }
        .notif-icon.success-icon i {
            color: #1a8a1a;
        }
        .notif-icon.branch-icon {
            background: #e8edff;
        }
        .notif-icon.branch-icon i {
            color: var(--primary);
        }
        .notif-icon.user-icon {
            background: #e6f1ff;
        }
        .notif-icon.user-icon i {
            color: #1f6fb2;
        }
        .notif-icon.forecast-icon {
            background: #f0e8ff;
        }
        .notif-icon.forecast-icon i {
            color: #6f42c1;
        }
        .notif-icon.inventory-icon {
            background: #fff1dc;
        }
        .notif-icon.inventory-icon i {
            color: #ce7600;
        }
        .notif-icon.health-icon {
            background: #e1f5ed;
        }
        .notif-icon.health-icon i {
            color: #167a5b;
        }
        .notif-content {
            flex: 1;
        }
        .notif-content .notif-title {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
            margin-bottom: 2px;
        }
        .notif-content .notif-desc {
            color: #2a3a5a;
            font-size: 15px;
            font-weight: 500;
            white-space: pre-line;
            overflow-wrap: anywhere;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            overflow: hidden;
        }
        .notif-content .notif-desc.expanded {
            display: block;
            overflow: visible;
            -webkit-line-clamp: unset;
            line-clamp: unset;
        }
        .notif-content .notif-time {
            color: #6a7a9a;
            font-size: 13px;
            font-weight: 500;
            margin-top: 2px;
        }
        .notif-content .notif-desc + .notif-time {
            margin-top: 4px;
        }

        .notification-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 28px;
            border-bottom: 1px solid #edf1f8;
        }
        .notification-toolbar h4 {
            margin: 0;
            color: var(--primary);
            font-size: 19px;
            font-weight: 700;
        }
        .notification-toolbar p {
            margin: 4px 0 0;
            color: #6a7a9a;
            font-size: 13px;
        }
        .notification-toolbar .btn {
            white-space: nowrap;
            border-radius: 9px;
            font-weight: 650;
        }
        .notif-day {
            padding: 15px 28px 8px;
            color: var(--primary);
            background: #fbfcff;
            border-bottom: 1px solid #edf1f8;
            font-size: 13px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .35px;
        }
        .notif-actions {
            display: flex;
            flex-shrink: 0;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }
        .notif-actions .btn {
            white-space: nowrap;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 650;
        }
        .view-more-btn {
            display: none;
            margin: 5px 0 0;
            padding: 0;
            color: var(--primary);
            background: transparent;
            border: 0;
            font-size: 12px;
            font-weight: 700;
        }
        .view-more-btn:hover {
            text-decoration: underline;
        }
        .type-pill {
            display: inline-flex;
            align-items: center;
            margin-left: 8px;
            padding: 3px 8px;
            color: #4f5d7a;
            background: #edf0f7;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 650;
        }
        .unread-dot {
            width: 9px;
            height: 9px;
            display: inline-block;
            margin-left: 8px;
            background: var(--accent);
            border-radius: 50%;
            vertical-align: middle;
        }
        .empty-state {
            padding: 60px 20px;
            color: #8390a8;
            text-align: center;
        }
        .empty-state i {
            display: block;
            margin-bottom: 10px;
            color: #b7bfd0;
            font-size: 46px;
        }
        .empty-state h4 {
            color: var(--primary);
            font-weight: 700;
        }
        .alert {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
        }

        /* ---- view all button ---- */
        .view-all-wrap {
            padding: 20px 28px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .btn-view-all {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-view-all:hover {
            background: #1d2863;
            color: #fff;
        }
        .results-count {
            color: #73809a;
            font-size: 12px;
        }
        .pagination {
            margin: 0;
        }
        .page-link {
            color: var(--primary);
            border-color: #e1e6f0;
        }
        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        .confirm-modal .modal-content {
            overflow: hidden;
            border: 0;
            border-radius: 20px;
            box-shadow: 0 24px 70px rgba(31, 45, 110, .24);
        }
        .confirm-modal .modal-header {
            display: block;
            padding: 28px 28px 10px;
            border: 0;
            text-align: center;
        }
        .confirm-modal .modal-icon {
            width: 66px;
            height: 66px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: #fff;
            background: linear-gradient(135deg, #dc3545, #f06b77);
            border-radius: 50%;
            box-shadow: 0 10px 24px rgba(220, 53, 69, .22);
            font-size: 29px;
        }
        .confirm-modal .modal-title {
            color: #1f2d6e;
            font-size: 22px;
            font-weight: 750;
        }
        .confirm-modal .modal-body {
            padding: 8px 28px 20px;
            color: #6f7b91;
            text-align: center;
        }
        .confirm-modal .modal-footer {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 0 28px 28px;
            border: 0;
        }
        .confirm-modal .modal-footer .btn {
            min-height: 46px;
            margin: 0;
            border-radius: 10px;
            font-weight: 700;
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
            .notif-item {
                padding: 14px 16px;
                gap: 12px;
            }
            .notif-icon {
                width: 34px;
                height: 34px;
            }
            .notif-icon i {
                font-size: 16px;
            }
            .notif-content .notif-title {
                font-size: 14px;
            }
            .notif-content .notif-desc {
                font-size: 13px;
            }
            .view-all-wrap {
                padding: 14px 16px 18px;
                align-items: flex-start;
                flex-direction: column;
            }
            .btn-view-all {
                width: 100%;
                justify-content: center;
            }
            .notification-toolbar {
                align-items: stretch;
                flex-direction: column;
                padding: 16px;
            }
            .notification-toolbar form,
            .notification-toolbar .btn {
                width: 100%;
            }
            .notif-day {
                padding-left: 16px;
                padding-right: 16px;
            }
            .notif-item {
                flex-wrap: wrap;
            }
            .notif-actions {
                width: 100%;
                padding-left: 46px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Super Admin) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="SuperAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_CombinedManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a class="active" href="SuperAdmin_Notifications.php" class="notification-link">
                <i class="bi bi-bell-fill"></i><span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a></li>
        </ul>
    </nav>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Notifications</h3>
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
                    <a class="dropdown-item text-danger" href="#"
                       data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo workflowH((string)$flash['type']); ?> alert-dismissible fade show" role="alert">
                <?php echo workflowH((string)$flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Notification List -->
        <div class="notif-card">

            <div class="notification-toolbar">
                <div>
                    <h4><i class="bi bi-bell-fill me-2"></i>System Notifications</h4>
                    <p>
                        <?php echo number_format($unreadCount); ?> unread notification<?php echo $unreadCount === 1 ? '' : 's'; ?>
                        from important activity across all branches
                    </p>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                    <input type="hidden" name="action" value="mark_all">
                    <input type="hidden" name="return_page" value="<?php echo $page; ?>">
                    <button class="btn btn-success" type="submit" <?php echo $unreadCount === 0 ? 'disabled' : ''; ?>>
                        <i class="bi bi-check2-all me-1"></i>Mark All as Read
                    </button>
                </form>
            </div>

            <?php if (!$notifications): ?>
                <div class="empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <h4>No notifications found</h4>
                    <p class="mb-0">Important system activity will appear here automatically.</p>
                </div>
            <?php else: ?>
                <?php foreach ($groupedNotifications as $dateLabel => $items): ?>
                    <div class="notif-day"><?php echo workflowH($dateLabel); ?></div>

                    <?php foreach ($items as $notification): ?>
                        <?php
                        [$icon, $iconClass, $destination, $destinationLabel] =
                            superAdminNotificationPresentation(
                                (string)($notification['notification_type'] ?? ''),
                                (string)($notification['title'] ?? '')
                            );
                        $isUnread = (int)$notification['is_read'] === 0;
                        $notificationId = (int)$notification['notification_id'];
                        ?>
                        <article class="notif-item <?php echo $isUnread ? 'unread' : ''; ?>">
                            <div class="notif-icon <?php echo workflowH($iconClass); ?>">
                                <i class="bi <?php echo workflowH($icon); ?>"></i>
                            </div>

                            <div class="notif-content">
                                <div class="notif-title">
                                    <?php echo workflowH((string)($notification['title'] ?: 'System Notification')); ?>
                                    <?php if ($isUnread): ?><span class="unread-dot" title="Unread"></span><?php endif; ?>
                                </div>
                                <div class="notif-desc" id="notification-message-<?php echo $notificationId; ?>">
                                    <?php echo workflowH((string)($notification['message'] ?? '')); ?>
                                </div>
                                <button
                                    class="view-more-btn"
                                    type="button"
                                    data-message-id="notification-message-<?php echo $notificationId; ?>"
                                    aria-expanded="false"
                                >View more</button>
                                <div class="notif-time">
                                    <i class="bi bi-clock me-1"></i>
                                    <?php echo workflowH(date('g:i A', strtotime((string)$notification['created_at']))); ?>
                                    <span class="type-pill">
                                        <?php echo workflowH(superAdminNotificationTypeLabel((string)($notification['notification_type'] ?? ''))); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="notif-actions">
                                <span class="badge <?php echo $isUnread ? 'text-bg-danger' : 'text-bg-success'; ?>">
                                    <?php echo $isUnread ? 'Unread' : 'Read'; ?>
                                </span>
                                <?php if ($destination !== ''): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo workflowH($destination); ?>">
                                        <?php echo workflowH($destinationLabel); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($isUnread): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                        <input type="hidden" name="action" value="mark_one">
                                        <input type="hidden" name="notification_id" value="<?php echo $notificationId; ?>">
                                        <input type="hidden" name="return_page" value="<?php echo $page; ?>">
                                        <button class="btn btn-sm btn-primary" type="submit">Mark Read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($totalRows > 0): ?>
                <div class="view-all-wrap">
                    <div class="results-count">
                        Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalRows); ?>
                        of <?php echo number_format($totalRows); ?> notifications
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Notification pages">
                            <ul class="pagination pagination-sm">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page > 1 ? workflowH(superAdminNotificationPageUrl($page - 1)) : '#'; ?>" aria-label="Previous">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                                ?>
                                    <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo workflowH(superAdminNotificationPageUrl($pageNumber)); ?>">
                                            <?php echo $pageNumber; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page < $totalPages ? workflowH(superAdminNotificationPageUrl($page + 1)) : '#'; ?>" aria-label="Next">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div> <!-- /notif-card -->

    </div> <!-- /content -->
</div> <!-- /main -->

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.view-more-btn').forEach(function (button) {
        const message = document.getElementById(button.dataset.messageId);
        if (!message) return;

        if (message.scrollHeight > message.clientHeight + 1) {
            button.style.display = 'inline-block';
        }

        button.addEventListener('click', function () {
            const expanded = message.classList.toggle('expanded');
            button.textContent = expanded ? 'View less' : 'View more';
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    });
});
</script>
</body>
</html>
