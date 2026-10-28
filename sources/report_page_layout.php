<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_helpers.php';

/*
 * Page frame (sidebar, top bar, logout dialog) for the Nurse and Admin Staff
 * Reports pages. The menu matches the role's other pages, plus "Reports".
 */

const REPORT_PAGE_MENUS = [
    3 => [
        ['Nurse_Dashboard.php', 'bi-grid-fill', 'Dashboard'],
        ['Nurse_Calendar.php', 'bi-calendar3', 'Calendar'],
        ['Nurse_Patients.php', 'bi-heart-pulse-fill', 'Patients'],
        ['Nurse_Assessment.php', 'bi-clipboard2-pulse-fill', 'Assessment Queue'],
        ['Nurse_Vaccination.php', 'bi-shield-plus', 'Vaccination'],
        ['Nurse_DailyInventory.php', 'bi-clipboard-data-fill', 'Daily Inventory'],
        ['Nurse_MedicalSuppliesManagement.php', 'bi-box2-heart-fill', 'Medical Supply Monitoring'],
        ['Nurse_Supplyforecasting.php', 'bi-graph-up-arrow', 'Supply Forecasting'],
        ['Nurse_Reports.php', 'bi-file-earmark-bar-graph-fill', 'Reports'],
        ['Nurse_Notification.php', 'bi-bell-fill', 'Notifications'],
    ],
    4 => [
        ['AdminStaff_Dashboard.php', 'bi-grid-fill', 'Dashboard'],
        ['AdminStaff_Calendar.php', 'bi-calendar-fill', 'Calendar'],
        ['AdminStaff_PatientRecord.php', 'bi-people-fill', 'Patient Record Management'],
        ['AdminStaff_VisitQueue.php', 'bi-person-check-fill', 'Visit Check-in'],
        ['AdminStaff_Registry.php', 'bi-journal-check', 'Registry Queue'],
        ['AdminStaff_PhilhealthWorkflow.php', 'bi-check2-all', 'PhilHealth Workflow'],
        ['AdminStaff_MedicalDocuments.php', 'bi-file-earmark-ruled', 'Medical Documents'],
        ['AdminStaff_Reports.php', 'bi-file-earmark-bar-graph-fill', 'Reports'],
        ['AdminStaff_Notifications.php', 'bi-bell-fill', 'Notifications'],
    ],
];

/** The whole page with $content in the main area. */
function reportPageRender(array $user, string $title, string $content, int $notificationCount): string
{
    $roleId = (int)$user['role_id'];
    $roleLabel = $roleId === 3 ? 'Nurse' : 'Admin Staff';
    $current = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($title) ?> - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f9faff;color:#1f2a44;font-family:'Segoe UI',Roboto,system-ui,sans-serif}
        .main{min-height:100vh;margin-left:260px}
        .topbar{height:80px;padding:0 35px;display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid #e9edf5;box-shadow:0 2px 8px rgba(0,0,0,.06)}
        .topbar h3{margin:0;color:#2B3A8C;font-size:28px;font-weight:700;letter-spacing:-.3px}
        .topbar h3 small{margin-left:10px;color:#666;font-size:16px;font-weight:400}
        .profile{font-weight:600;color:#2B3A8C;display:flex;align-items:center;gap:6px}
        .content{padding:32px 35px 40px}
        .confirm-modal .modal-content{border:0;border-radius:20px;box-shadow:0 20px 55px rgba(31,45,110,.2)}
        .confirm-modal .modal-header{display:block;padding:26px 24px 6px;border:0;text-align:center}
        .confirm-modal .modal-icon{width:64px;height:64px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto 14px;color:#fff;background:linear-gradient(135deg,#ef3340,#f05b68);border-radius:50%;font-size:27px}
        .confirm-modal .modal-title{color:#283a7a;font-size:24px;font-weight:700}
        .confirm-modal .modal-body{padding:6px 30px 22px;text-align:center;color:#7a879e}
        .confirm-modal .modal-footer{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:0 24px 26px;border:0}
        @media(max-width:991px){.main{margin-left:90px}.topbar{padding:0 22px}.content{padding:24px 18px}.topbar h3 small{display:none}}
        @media(max-width:767px){.topbar{height:70px;padding:0 16px}.topbar h3{font-size:20px}.content{padding:18px 12px}}
    </style>
</head>
<body>
<aside class="sidebar" id="sidebar">
    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true"><i class="bi bi-chevron-left"></i></button>
    <div class="logo-area">
        <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo" /></div>
        <div class="system-name">Smart Bite Care</div>
    </div>
    <nav class="nav-menu" aria-label="<?= $h($roleLabel) ?> navigation">
        <ul>
            <?php foreach (REPORT_PAGE_MENUS[$roleId] ?? [] as [$href, $icon, $label]): $active = $href === $current; $isNotif = str_contains($href, 'Notification'); ?>
                <li><a href="<?= $h($href) ?>" class="<?= trim(($active ? 'active ' : '') . ($isNotif ? 'notification-link' : '')) ?>"<?= $active ? ' aria-current="page"' : '' ?>><i class="bi <?= $h($icon) ?>"></i><span><?= $h($label) ?></span><?php if ($isNotif && $notificationCount > 0): ?><span class="notification-badge"><?= (int)$notificationCount ?></span><?php endif; ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>
<main class="main">
    <div class="topbar">
        <h3><?= $h($title) ?> <small><?= $h($user['branch_name'] ?? $user['branch_id']) ?></small></h3>
        <div class="dropdown">
            <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3" type="button" id="profileMenu" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i><span><?= $h($user['username'] ?? '') ?></span>
                <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| <?= $h($roleLabel) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2" aria-labelledby="profileMenu">
                <li><h6 class="dropdown-header">Account options</h6></li>
                <li><a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php"><i class="bi bi-key-fill me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item rounded-2 py-2 text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
    <div class="content"><?= $content ?></div>
</main>
<div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><div class="modal-icon"><i class="bi bi-box-arrow-right"></i></div><h2 class="modal-title" id="logoutConfirmModalLabel">Log out of Smart Bite Care?</h2></div>
        <div class="modal-body"><p class="mb-0">Make sure you have saved any unfinished work before leaving your account.</p></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
            <a href="logout.php" class="btn btn-danger d-flex align-items-center justify-content-center"><i class="bi bi-box-arrow-right me-1"></i>Yes, Log Out</a>
        </div>
    </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="sources/sidebar.js"></script>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
