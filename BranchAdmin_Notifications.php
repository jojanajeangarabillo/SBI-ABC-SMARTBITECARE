<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 2);
$userId = (int)$user['user_id'];
$username = (string)($user['username'] ?? 'Branch Admin');
$branchName = (string)($user['branch_name'] ?? 'Assigned Branch');
$csrf = workflowCsrfToken();

function notificationTypeLabel(?string $type): string
{
    $label = trim((string)$type);
    if ($label === '') return 'General';
    $label = str_ireplace(['prediction', 'predictive'], ['forecasting', 'forecast'], $label);
    return ucwords(str_replace(['_', '-'], ' ', $label));
}

function notificationPresentation(?string $type, ?string $title): array
{
    $value = strtolower(trim((string)$type . ' ' . (string)$title));
    if (strpos($value, 'forecast') !== false || strpos($value, 'prediction') !== false) {
        return ['bi-graph-up-arrow', 'forecast', 'BranchAdmin_Forecasting.php', 'View Forecast'];
    }
    if (strpos($value, 'philhealth') !== false) {
        return ['bi-file-medical-fill', 'philhealth', 'BranchAdmin_PhilhealthWorkflow.php', 'View PhilHealth'];
    }
    if (strpos($value, 'stock') !== false || strpos($value, 'inventor') !== false ||
        strpos($value, 'expir') !== false || strpos($value, 'supply') !== false) {
        return ['bi-box-seam-fill', 'inventory', 'BranchAdmin_InventoryOverview.php', 'View Inventory'];
    }
    if (strpos($value, 'patient') !== false || strpos($value, 'vaccin') !== false ||
        strpos($value, 'case') !== false || strpos($value, 'registry') !== false) {
        return ['bi-heart-pulse-fill', 'patient', 'BranchAdmin_PatientMonitoring.php', 'View Patients'];
    }
    if (strpos($value, 'user') !== false || strpos($value, 'account') !== false) {
        return ['bi-people-fill', 'user', 'BranchAdmin_UserManagement.php', 'View Users'];
    }
    return ['bi-bell-fill', 'general', '', ''];
}

function notificationDateLabel(string $date): string
{
    $day = date('Y-m-d', strtotime($date));
    if ($day === date('Y-m-d')) return 'Today';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('F j, Y', strtotime($date));
}

function notificationPageUrl(int $page): string
{
    return 'BranchAdmin_Notifications.php?' . http_build_query(['page' => $page]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'mark_all') {
            $stmt = $conn->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            workflowFlash('success', $changed > 0
                ? $changed . ' notification' . ($changed === 1 ? '' : 's') . ' marked as read.'
                : 'All notifications are already marked as read.');
        } elseif ($action === 'mark_one') {
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            if ($notificationId < 1) throw new RuntimeException('Choose a valid notification.');
            $stmt = $conn->prepare(
                'UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?'
            );
            $stmt->bind_param('ii', $notificationId, $userId);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $check = $conn->prepare('SELECT notification_id FROM notifications WHERE notification_id=? AND user_id=?');
                $check->bind_param('ii', $notificationId, $userId);
                $check->execute();
                if (!$check->get_result()->fetch_assoc()) {
                    $check->close();
                    throw new RuntimeException('Notification was not found.');
                }
                $check->close();
            }
            $stmt->close();
            workflowFlash('success', 'Notification marked as read.');
        } else {
            throw new RuntimeException('Unsupported notification action.');
        }
    } catch (Throwable $e) {
        workflowFlash('danger', $e->getMessage());
    }

    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));
    header('Location: ' . notificationPageUrl($returnPage));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$countStmt = $conn->prepare(
    'SELECT COUNT(*) total, COALESCE(SUM(is_read=0),0) unread
     FROM notifications WHERE user_id=?'
);
$countStmt->bind_param('i', $userId);
$countStmt->execute();
$counts = $countStmt->get_result()->fetch_assoc() ?: [];
$totalRows = (int)($counts['total'] ?? 0);
$unreadCount = (int)($counts['unread'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listStmt = $conn->prepare(
    'SELECT notification_id,title,message,notification_type,source_key,is_read,created_at
     FROM notifications
     WHERE user_id=?
     ORDER BY created_at DESC,notification_id DESC
     LIMIT ? OFFSET ?'
);
$listStmt->bind_param('iii', $userId, $perPage, $offset);
$listStmt->execute();
$notifications = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$grouped = [];
foreach ($notifications as $notification) {
    $grouped[notificationDateLabel((string)$notification['created_at'])][] = $notification;
}
$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notifications - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root{--primary:#2B3A8C;--primary-dark:#1f2d6e;--bg:#f4f6fb;--text:#1f2a44;--muted:#6b7280;--border:#e2e7f0}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif}
        .main{margin-left:260px;min-height:100vh}
        .topbar{display:flex;align-items:center;justify-content:space-between;height:80px;padding:0 35px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        .topbar h3{margin:0;color:var(--primary);font-size:28px;font-weight:700}.topbar h3 small{margin-left:10px;color:#666;font-size:16px;font-weight:400}
        .profile{display:flex;align-items:center;gap:6px;color:var(--primary);font-weight:600}.profile-role{margin-left:4px;color:#adb5bd;font-size:12px;font-weight:400}
        .content-wrapper{padding:35px}
        .btn-primary{background:var(--primary);border-color:var(--primary);font-weight:650}.btn-primary:hover,.btn-primary:focus{background:var(--primary-dark);border-color:var(--primary-dark)}
        .notification-section{overflow:hidden;background:#fff;border:0;border-radius:18px;box-shadow:0 3px 8px rgba(0,0,0,.08)}
        .notification-toolbar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:19px 22px;border-bottom:1px solid #edf0f5}
        .notification-toolbar h4{margin:0;color:var(--primary);font-size:19px;font-weight:700}.notification-toolbar p{margin:4px 0 0;color:var(--muted);font-size:13px}.btn-read-all{white-space:nowrap;font-weight:650}
        .notification-list{padding:18px 22px 22px}.notification-day{margin:3px 0 9px;color:var(--primary);font-size:14px;font-weight:750}.notification-day:not(:first-child){margin-top:20px}
        .notification-card{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:11px;margin-bottom:9px;background:#fff;transition:.18s}
        .notification-card:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(31,42,68,.08)}.notification-card.unread{background:#f7f8ff;border-left:4px solid var(--primary)}
        .notification-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;color:#fff;font-size:17px;background:#64748b}.notification-icon.inventory{background:#e48b16}.notification-icon.forecast{background:#6f42c1}.notification-icon.philhealth{background:#167a5b}.notification-icon.patient{background:#d64555}.notification-icon.user{background:#2775c9}
        .notification-content{min-width:0}.notification-content h2{margin:0 0 3px;color:#26345f;font-size:15px;font-weight:700}
        .notification-message{display:-webkit-box;margin:0;color:#515d79;font-size:13px;line-height:1.4;white-space:pre-line;overflow:hidden;overflow-wrap:anywhere;-webkit-box-orient:vertical;-webkit-line-clamp:2}
        .notification-message.expanded{display:block;overflow:visible;-webkit-line-clamp:unset}.view-more-btn{display:none;margin:4px 0 0;padding:0;border:0;background:transparent;color:var(--primary);font-size:12px;font-weight:700}.view-more-btn:hover{text-decoration:underline}
        .notification-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:5px;color:#7b849d;font-size:11px}.type-pill{background:#edf0f7;color:#4b5675;border-radius:999px;padding:2px 8px;font-weight:650}
        .notification-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:8px}.notification-actions .btn{white-space:nowrap}
        .empty-state{padding:50px 20px;text-align:center;color:var(--muted)}.empty-state>i{display:block;color:#b0b8cc;font-size:44px;margin-bottom:10px}.empty-state h2{color:var(--primary);font-size:18px}
        .results-footer{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-top:17px}.results-count{color:var(--muted);font-size:12px}.pagination{margin:0}.page-link{color:var(--primary);border-color:var(--border)}.page-item.active .page-link{background:var(--primary);border-color:var(--primary)}
        @media(max-width:991px){.main{margin-left:90px}.topbar{padding:0 22px}.content-wrapper{padding:28px 22px}.topbar h3 small,.profile-role{display:none}}
        @media(max-width:767px){.topbar{height:70px;padding:0 16px}.topbar h3{font-size:20px}.content-wrapper{padding:20px 14px 32px}.notification-toolbar{align-items:flex-start;padding:16px}.notification-list{padding:14px}.notification-card{grid-template-columns:40px minmax(0,1fr);align-items:start;padding:11px}.notification-actions{grid-column:1/-1;justify-content:flex-start;padding-left:52px}.results-footer{align-items:flex-start;flex-direction:column}}
        @media(max-width:520px){.profile span{display:none}.notification-toolbar{flex-direction:column}.notification-toolbar form,.btn-read-all{width:100%}.notification-actions{padding-left:0}.notification-actions .btn{flex:1}}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo-area"><div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo"></div><div class="system-name">Smart Bite Care</div></div>
    <nav class="nav-menu" aria-label="Branch Admin navigation"><ul>
        <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
        <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
        <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
        <li><a href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
        <li><a href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
        <li><a href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
        <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
        <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
        <li><a class="active" href="BranchAdmin_Notifications.php" aria-current="page"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
    </ul></nav>
    <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>

<main class="main">
    <div class="topbar">
        <h3>Notifications <small><?= workflowH($branchName) ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?= workflowH($username) ?></span><span class="profile-role">| Branch Admin</span></div>
    </div>
    <div class="content-wrapper">
        <?php if ($flash): ?>
            <div class="alert alert-<?= workflowH((string)$flash['type']) ?> alert-dismissible fade show" role="alert"><?= workflowH((string)$flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>

        <section class="notification-section" aria-label="Notification list">
            <div class="notification-toolbar">
                <div><h4><i class="bi bi-bell-fill me-2"></i>Recent Notifications</h4><p><?= $unreadCount ?> unread notification<?= $unreadCount === 1 ? '' : 's' ?></p></div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                    <input type="hidden" name="action" value="mark_all">
                    <input type="hidden" name="return_page" value="<?= $page ?>">
                    <button class="btn btn-success btn-read-all" type="submit" <?= $unreadCount === 0 ? 'disabled' : '' ?>><i class="bi bi-check2-all me-1"></i>Mark All as Read</button>
                </form>
            </div>
            <div class="notification-list">
              <?php if (!$notifications): ?>
                <div class="empty-state"><i class="bi bi-bell-slash"></i><h2>No notifications found</h2><p class="mb-0">You currently have no notifications.</p></div>
              <?php else: ?>
                <?php foreach ($grouped as $dateLabel => $items): ?>
                    <div class="notification-day"><?= workflowH($dateLabel) ?></div>
                    <?php foreach ($items as $notification): ?>
                        <?php [$icon,$iconClass,$destination,$destinationLabel] = notificationPresentation((string)($notification['notification_type']??''),(string)($notification['title']??'')); $isUnread=(int)$notification['is_read']===0; ?>
                        <article class="notification-card <?= $isUnread?'unread':'' ?>">
                            <div class="notification-icon <?= workflowH($iconClass) ?>"><i class="bi <?= workflowH($icon) ?>"></i></div>
                            <div class="notification-content">
                                <h2><?= workflowH((string)($notification['title']?:'Notification')) ?></h2>
                                <p class="notification-message" id="notification-message-<?= (int)$notification['notification_id'] ?>"><?= workflowH((string)($notification['message']??'')) ?></p>
                                <button class="view-more-btn" type="button" data-message-id="notification-message-<?= (int)$notification['notification_id'] ?>" aria-expanded="false">View more</button>
                                <div class="notification-meta"><span><i class="bi bi-clock me-1"></i><?= workflowH(date('g:i A',strtotime((string)$notification['created_at']))) ?></span><span class="type-pill"><?= workflowH(notificationTypeLabel((string)($notification['notification_type']??''))) ?></span></div>
                            </div>
                            <div class="notification-actions">
                                <span class="badge <?= $isUnread?'text-bg-danger':'text-bg-success' ?>"><?= $isUnread?'Unread':'Read' ?></span>
                                <?php if ($destination!==''): ?><a class="btn btn-sm btn-outline-primary" href="<?= workflowH($destination) ?>"><?= workflowH($destinationLabel) ?></a><?php endif; ?>
                                <?php if ($isUnread): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>"><input type="hidden" name="action" value="mark_one"><input type="hidden" name="notification_id" value="<?= (int)$notification['notification_id'] ?>"><input type="hidden" name="return_page" value="<?= $page ?>"><button class="btn btn-sm btn-primary" type="submit">Mark Read</button></form><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endforeach; ?>
              <?php endif; ?>

            <?php if ($totalRows>0): ?>
                <div class="results-footer"><div class="results-count">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$totalRows) ?> of <?= $totalRows ?> notification<?= $totalRows===1?'':'s' ?></div>
                    <?php if ($totalPages>1): ?><nav aria-label="Notification pages"><ul class="pagination pagination-sm">
                        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $page>1?workflowH(notificationPageUrl($page-1)):'#' ?>" aria-label="Previous"><i class="bi bi-chevron-left"></i></a></li>
                        <?php $startPage=max(1,$page-2);$endPage=min($totalPages,$page+2);for($pageNumber=$startPage;$pageNumber<=$endPage;$pageNumber++): ?><li class="page-item <?= $pageNumber===$page?'active':'' ?>"><a class="page-link" href="<?= workflowH(notificationPageUrl($pageNumber)) ?>"><?= $pageNumber ?></a></li><?php endfor; ?>
                        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $page<$totalPages?workflowH(notificationPageUrl($page+1)):'#' ?>" aria-label="Next"><i class="bi bi-chevron-right"></i></a></li>
                    </ul></nav><?php endif; ?>
                </div>
            <?php endif; ?>
            </div>
        </section>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.view-more-btn').forEach(function (button) {
        var message = document.getElementById(button.dataset.messageId);
        if (!message) return;

        if (message.scrollHeight > message.clientHeight + 1) {
            button.style.display = 'inline-block';
        }

        button.addEventListener('click', function () {
            var expanded = message.classList.toggle('expanded');
            button.textContent = expanded ? 'View less' : 'View more';
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    });
});
</script>
</body>
</html>
