<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$notification_count = getUnreadNotificationCount($conn, $user_id);
$user = workflowRequireUser($conn, 3);

$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Nurse');

function nurseCalendarH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nurseCalendarDoseLabel(int $doseNumber): string
{
    $map = [
        1 => 'D0',
        2 => 'D3',
        3 => 'D7',
        4 => 'D14',
        5 => 'D21',
        6 => 'D28/30',
    ];

    return $map[$doseNumber] ?? ('Dose ' . $doseNumber);
}

function nurseCalendarProfileLabel(?string $profile): string
{
    $profile = strtoupper(trim((string)$profile));

    $labels = [
        'PEP_ID' => 'PEP - Intradermal',
        'PEP_IM' => 'PEP - Intramuscular',
        'PREP' => 'PrEP',
        'BOOSTER' => 'Booster',
    ];

    return $labels[$profile] ?? ($profile !== '' ? $profile : 'Not set');
}


function nurseCalendarBind(mysqli_stmt $stmt, string $types, array &$params): void
{
    $bindArgs = [$types];

    foreach ($params as $key => &$value) {
        $bindArgs[] = &$value;
    }
    unset($value);

    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

function nurseCalendarWindowLabel(string $range): string
{
    return match ($range) {
        '7' => 'Next 7 Days',
        '14' => 'Next 14 Days',
        '30' => 'Next 30 Days',
        default => 'Today',
    };
}

$allowedRanges = ['today', '7', '14', '30'];
$range = (string)($_GET['range'] ?? 'today');
if (!in_array($range, $allowedRanges, true)) {
    $range = 'today';
}

$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$today = date('Y-m-d');
$windowOffsets = [
    'today' => 0,
    '7' => 6,
    '14' => 13,
    '30' => 29,
];
$endDate = date('Y-m-d', strtotime('+' . $windowOffsets[$range] . ' days'));

// Summary counts use the same source of truth as the calendar itself:
// active, unarchived Scheduled vaccination rows for this Nurse's branch.
$summarySql = "
    SELECT
        SUM(CASE WHEN vr.scheduled_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
        SUM(CASE WHEN vr.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS next_7_count,
        SUM(CASE WHEN vr.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 13 DAY) THEN 1 ELSE 0 END) AS next_14_count,
        SUM(CASE WHEN vr.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS next_30_count
    FROM vaccination_records vr
    INNER JOIN patients p
        ON p.patient_id = vr.patient_id
       AND p.branch_id = vr.branch_id
       AND p.is_archived = 0
    INNER JOIN animal_bite_cases abc
        ON abc.case_id = vr.case_id
       AND abc.patient_id = vr.patient_id
       AND abc.branch_id = vr.branch_id
       AND abc.is_archived = 0
       AND abc.case_status <> 'Completed'
    WHERE vr.branch_id = ?
      AND vr.is_archived = 0
      AND vr.vaccination_status = 'Scheduled'
      AND vr.scheduled_date IS NOT NULL
      AND vr.scheduled_date >= CURDATE()
";

$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param('s', $branchId);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$todayCount = (int)($summary['today_count'] ?? 0);
$next7Count = (int)($summary['next_7_count'] ?? 0);
$next14Count = (int)($summary['next_14_count'] ?? 0);
$next30Count = (int)($summary['next_30_count'] ?? 0);

$whereSql = "
    vr.branch_id = ?
    AND vr.is_archived = 0
    AND vr.vaccination_status = 'Scheduled'
    AND vr.scheduled_date IS NOT NULL
    AND vr.scheduled_date BETWEEN ? AND ?
    AND p.is_archived = 0
    AND abc.is_archived = 0
    AND abc.case_status <> 'Completed'
";

$baseParams = [$branchId, $today, $endDate];
$baseTypes = 'sss';

if ($search !== '') {
    $whereSql .= "
        AND (
            p.full_name LIKE ?
            OR p.contact_number LIKE ?
            OR p.email LIKE ?
            OR abc.case_number LIKE ?
        )
    ";
    $like = '%' . $search . '%';
    array_push($baseParams, $like, $like, $like, $like);
    $baseTypes .= 'ssss';
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM vaccination_records vr
    INNER JOIN patients p
        ON p.patient_id = vr.patient_id
       AND p.branch_id = vr.branch_id
    INNER JOIN animal_bite_cases abc
        ON abc.case_id = vr.case_id
       AND abc.patient_id = vr.patient_id
       AND abc.branch_id = vr.branch_id
    WHERE {$whereSql}
";

$countStmt = $conn->prepare($countSql);
nurseCalendarBind($countStmt, $baseTypes, $baseParams);
$countStmt->execute();
$totalRows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$scheduleSql = "
    SELECT
        vr.vaccination_id,
        vr.patient_id,
        vr.case_id,
        vr.dose_number,
        vr.scheduled_date,
        vr.remarks AS schedule_remarks,
        p.full_name,
        p.contact_number,
        p.email,
        abc.case_number,
        abc.animal_type,
        (
            SELECT ca.treatment_profile
            FROM clinical_assessments ca
            WHERE ca.case_id = vr.case_id
              AND ca.branch_id = vr.branch_id
            ORDER BY ca.updated_at DESC, ca.assessment_id DESC
            LIMIT 1
        ) AS treatment_profile,
        DATEDIFF(vr.scheduled_date, CURDATE()) AS days_until
    FROM vaccination_records vr
    INNER JOIN patients p
        ON p.patient_id = vr.patient_id
       AND p.branch_id = vr.branch_id
    INNER JOIN animal_bite_cases abc
        ON abc.case_id = vr.case_id
       AND abc.patient_id = vr.patient_id
       AND abc.branch_id = vr.branch_id
    WHERE {$whereSql}
    ORDER BY vr.scheduled_date ASC, p.full_name ASC, vr.dose_number ASC, vr.vaccination_id ASC
    LIMIT ? OFFSET ?
";

$scheduleParams = $baseParams;
$scheduleParams[] = $limit;
$scheduleParams[] = $offset;
$scheduleTypes = $baseTypes . 'ii';

$scheduleStmt = $conn->prepare($scheduleSql);
nurseCalendarBind($scheduleStmt, $scheduleTypes, $scheduleParams);
$scheduleStmt->execute();
$schedules = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$scheduleStmt->close();

function nurseCalendarUrl(string $range, string $search, int $page = 1): string
{
    $params = ['range' => $range];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($page > 1) {
        $params['page'] = $page;
    }

    return 'Nurse_Calendar.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nurse Vaccination Calendar - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #202d72;
            --accent: #F21D2F;
            --success: #28a745;
            --warning: #e4a300;
            --info: #17a2b8;
            --page-bg: #f9faff;
            --muted: #7a85a8;
            --border: #e4e9f4;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f0f2f5;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            color: #1f2a4a;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: var(--page-bg);
        }

        .topbar {
            min-height: 80px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            border-bottom: 1px solid #e9edf5;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .topbar h3 {
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -.3px;
        }

        .topbar h3 small {
            margin-left: 10px;
            color: #666;
            font-size: 16px;
            font-weight: 400;
        }

        .profile {
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .content { padding: 35px 35px 40px; }

        .page-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-intro h4 {
            margin: 0 0 5px;
            color: var(--primary);
            font-weight: 700;
            font-size: 21px;
        }

        .page-intro p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .stat-card {
            height: 100%;
            display: flex;
            align-items: center;
            gap: 15px;
            background: #fff;
            border-radius: 16px;
            padding: 18px;
            border: 1px solid #eef1f7;
            box-shadow: 0 3px 12px rgba(31,45,110,.06);
            text-decoration: none;
            color: inherit;
            transition: .16s ease;
        }

        .stat-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 7px 18px rgba(31,45,110,.10);
            color: inherit;
        }

        .stat-card.active {
            border-color: rgba(43,58,140,.35);
            box-shadow: 0 0 0 2px rgba(43,58,140,.08), 0 7px 18px rgba(31,45,110,.08);
        }

        .stat-icon {
            flex: 0 0 48px;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: #eef1ff;
            color: var(--primary);
            font-size: 22px;
        }

        .stat-card.today .stat-icon { background: #eef1ff; color: var(--primary); }
        .stat-card.seven .stat-icon { background: #e9f7fb; color: #1387a0; }
        .stat-card.fourteen .stat-icon { background: #eef9ef; color: #26963c; }
        .stat-card.thirty .stat-icon { background: #fff5dc; color: #b57c00; }

        .stat-label { color: var(--muted); font-size: 13px; font-weight: 600; }
        .stat-value { color: #14204a; font-size: 28px; line-height: 1.05; font-weight: 800; margin-top: 2px; }

        .filter-card {
            background: #fff;
            border-radius: 18px;
            border: 1px solid #edf0f7;
            box-shadow: 0 3px 12px rgba(31,45,110,.05);
            padding: 20px;
            margin: 24px 0 18px;
        }

        .range-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .range-tabs a {
            text-decoration: none;
            color: var(--primary);
            border: 1px solid #dbe1f1;
            background: #fff;
            border-radius: 10px;
            padding: 9px 15px;
            font-weight: 700;
            font-size: 14px;
            transition: .15s ease;
        }

        .range-tabs a:hover,
        .range-tabs a.active {
            color: #fff;
            background: var(--primary);
            border-color: var(--primary);
        }

        .search-wrap {
            position: relative;
        }

        .search-wrap i {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: #8290b3;
            font-size: 18px;
        }

        .search-wrap input {
            width: 100%;
            min-height: 46px;
            padding: 10px 14px 10px 43px;
            border: 1px solid #dce2ef;
            border-radius: 12px;
            outline: none;
            color: #1f2a4a;
            background: #fff;
        }

        .search-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43,58,140,.08);
        }

        .schedule-card {
            background: #fff;
            border-radius: 18px;
            border: 1px solid #edf0f7;
            box-shadow: 0 3px 12px rgba(31,45,110,.05);
            overflow: hidden;
        }

        .schedule-card-header {
            padding: 18px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 1px solid #edf1f7;
        }

        .schedule-card-header h5 {
            margin: 0;
            color: var(--primary);
            font-weight: 800;
            font-size: 19px;
        }

        .schedule-card-header small { color: var(--muted); }

        .schedule-table { margin: 0; }
        .schedule-table thead th {
            background: var(--primary);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            padding: 14px 16px;
            border: 0;
            white-space: nowrap;
        }

        .schedule-table tbody td {
            padding: 15px 16px;
            border-bottom: 1px solid #edf1f7;
            vertical-align: middle;
            color: #1f2a4a;
            font-size: 14px;
        }

        .schedule-table tbody tr:last-child td { border-bottom: 0; }
        .schedule-table tbody tr.today-row { background: #fbfcff; }
        .schedule-table tbody tr:hover { background: #f7f9ff; }

        .patient-name {
            font-weight: 750;
            color: #14204a;
            margin-bottom: 3px;
        }

        .subtext {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }

        .dose-badge,
        .schedule-badge,
        .profile-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 750;
            white-space: nowrap;
        }

        .dose-badge { background: #eef1ff; color: var(--primary); }
        .profile-badge { background: #f1f3f8; color: #536080; }
        .schedule-badge.today { background: #e7f7eb; color: #187f31; }
        .schedule-badge.tomorrow { background: #fff4d6; color: #9a6900; }
        .schedule-badge.upcoming { background: #e9f4ff; color: #126aa3; }

        .date-main { font-weight: 750; color: #182551; }

        .btn-open {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border-radius: 9px;
            padding: 8px 11px;
            background: var(--primary);
            border: 1px solid var(--primary);
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .btn-open:hover { background: var(--primary-dark); color: #fff; }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            color: #c7cee0;
            font-size: 48px;
            margin-bottom: 12px;
        }

        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 15px 18px;
            border-top: 1px solid #edf1f7;
            background: #fff;
        }

        .pagination-wrap .pagination { margin: 0; gap: 5px; }
        .pagination-wrap .page-link {
            border: 1px solid #e2e7f2;
            border-radius: 8px !important;
            color: var(--primary);
            min-width: 38px;
            text-align: center;
            font-weight: 600;
        }
        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .pagination-wrap .page-item.disabled .page-link { color: #b0b8c8; }
        .pagination-info { color: var(--muted); font-size: 13px; }

        .confirm-modal .modal-content {
            border: 0;
            border-radius: 20px;
            box-shadow: 0 20px 55px rgba(31,45,110,.20);
        }
        .confirm-modal .modal-header {
            display: block;
            text-align: center;
            border: 0;
            padding: 26px 24px 6px;
        }
        .confirm-modal .modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            background: linear-gradient(135deg,#ef3340,#f05b68);
            color: #fff;
            font-size: 27px;
        }
        .confirm-modal .modal-title { font-size: 22px; color: #1d2858; font-weight: 800; }
        .confirm-modal .modal-body { text-align: center; color: #69738e; padding: 14px 28px 8px; }
        .confirm-modal .modal-footer { border: 0; justify-content: center; padding: 16px 24px 24px; }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .topbar { padding: 0 22px; }
            .content { padding: 28px 22px 35px; }
            .topbar h3 small { display: none; }
        }

        @media (max-width: 767px) {
            .topbar {
                min-height: 70px;
                height: auto;
                padding: 12px 16px;
                gap: 8px;
                flex-wrap: wrap;
            }
            .topbar h3 { font-size: 21px; }
            .content { padding: 20px 14px 30px; }
            .page-intro { flex-direction: column; }
            .schedule-card-header { align-items: flex-start; flex-direction: column; }
            .table-footer { flex-direction: column; align-items: stretch; text-align: center; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a class="active" href="Nurse_Calendar.php" aria-current="page"><i class="bi bi-calendar3"></i><span>Calendar</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
            <li>
                <a href="Nurse_Notification.php">
                    <i class="bi bi-bell-fill"></i>
                    <span class="notification-label">
                        Notifications
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?= (int)$notification_count ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<main class="main">
    <div class="topbar">
        <h3>
            Vaccination Calendar
            <small><?= nurseCalendarH($branchName) ?></small>
        </h3>

        <div class="dropdown">
            <button
                class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                type="button"
                id="nurseProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-person-circle"></i>
                <span><?= nurseCalendarH($username) ?></span>
                <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| Nurse</span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2" aria-labelledby="nurseProfileMenu">
                <li><h6 class="dropdown-header">Account options</h6></li>
                <li>
                    <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill me-2"></i>Change Password
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item rounded-2 py-2 text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="content">
        <div class="page-intro">
            <div>
                <h4><i class="bi bi-calendar2-week me-2"></i>Nurse Vaccination Schedule</h4>
                <p>View active vaccination appointments for your branch. Only current Scheduled records are shown.</p>
            </div>
            <div class="text-muted small">
                <i class="bi bi-clock me-1"></i><?= nurseCalendarH(date('F d, Y')) ?>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-3 col-md-6">
                <a class="stat-card today <?= $range === 'today' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('today', $search)) ?>">
                    <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                    <div><div class="stat-label">Scheduled Today</div><div class="stat-value"><?= $todayCount ?></div></div>
                </a>
            </div>
            <div class="col-xl-3 col-md-6">
                <a class="stat-card seven <?= $range === '7' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('7', $search)) ?>">
                    <div class="stat-icon"><i class="bi bi-calendar-week"></i></div>
                    <div><div class="stat-label">Next 7 Days</div><div class="stat-value"><?= $next7Count ?></div></div>
                </a>
            </div>
            <div class="col-xl-3 col-md-6">
                <a class="stat-card fourteen <?= $range === '14' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('14', $search)) ?>">
                    <div class="stat-icon"><i class="bi bi-calendar2-range"></i></div>
                    <div><div class="stat-label">Next 14 Days</div><div class="stat-value"><?= $next14Count ?></div></div>
                </a>
            </div>
            <div class="col-xl-3 col-md-6">
                <a class="stat-card thirty <?= $range === '30' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('30', $search)) ?>">
                    <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
                    <div><div class="stat-label">Next 30 Days</div><div class="stat-value"><?= $next30Count ?></div></div>
                </a>
            </div>
        </div>

        <div class="filter-card">
            <div class="range-tabs" aria-label="Schedule date filters">
                <a class="<?= $range === 'today' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('today', $search)) ?>">Today</a>
                <a class="<?= $range === '7' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('7', $search)) ?>">Next 7 Days</a>
                <a class="<?= $range === '14' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('14', $search)) ?>">Next 14 Days</a>
                <a class="<?= $range === '30' ? 'active' : '' ?>" href="<?= nurseCalendarH(nurseCalendarUrl('30', $search)) ?>">Next 30 Days</a>
            </div>

            <form method="get" action="Nurse_Calendar.php" class="row g-2 align-items-center">
                <input type="hidden" name="range" value="<?= nurseCalendarH($range) ?>">
                <div class="col-lg-10">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input
                            type="text"
                            name="search"
                            value="<?= nurseCalendarH($search) ?>"
                            placeholder="Search patient, case number, contact number, or email"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="col-lg-2 d-grid">
                    <button type="submit" class="btn btn-primary" style="min-height:46px;background:#2B3A8C;border-color:#2B3A8C;border-radius:12px;font-weight:700;">
                        Search
                    </button>
                </div>
            </form>
        </div>

        <section class="schedule-card">
            <div class="schedule-card-header">
                <div>
                    <h5><?= nurseCalendarH(nurseCalendarWindowLabel($range)) ?> Schedule</h5>
                    <small>
                        <?= nurseCalendarH(date('M d, Y', strtotime($today))) ?>
                        <?php if ($endDate !== $today): ?>
                            – <?= nurseCalendarH(date('M d, Y', strtotime($endDate))) ?>
                        <?php endif; ?>
                    </small>
                </div>
                <span class="badge rounded-pill text-bg-light border px-3 py-2">
                    <?= $totalRows ?> appointment<?= $totalRows === 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if (!$schedules): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar2-x"></i>
                    <h5 class="fw-bold text-dark">No scheduled vaccinations found</h5>
                    <p class="mb-0">
                        There are no active vaccination appointments matching this date range<?= $search !== '' ? ' and search' : '' ?>.
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table schedule-table align-middle">
                        <thead>
                            <tr>
                                <th>Schedule</th>
                                <th>Patient</th>
                                <th>Case</th>
                                <th>Dose</th>
                                <th>Treatment Profile</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $row): ?>
                                <?php
                                    $daysUntil = (int)$row['days_until'];
                                    $scheduleDate = (string)$row['scheduled_date'];
                                    $badgeClass = $daysUntil === 0 ? 'today' : ($daysUntil === 1 ? 'tomorrow' : 'upcoming');
                                    $badgeText = $daysUntil === 0 ? 'Today' : ($daysUntil === 1 ? 'Tomorrow' : 'In ' . $daysUntil . ' days');
                                    $caseNumber = trim((string)($row['case_number'] ?? ''));
                                    if ($caseNumber === '') {
                                        $caseNumber = 'C' . str_pad((string)$row['case_id'], 4, '0', STR_PAD_LEFT);
                                    }
                                ?>
                                <tr class="<?= $daysUntil === 0 ? 'today-row' : '' ?>">
                                    <td>
                                        <div class="date-main"><?= nurseCalendarH(date('M d, Y', strtotime($scheduleDate))) ?></div>
                                        <div class="subtext"><?= nurseCalendarH(date('l', strtotime($scheduleDate))) ?></div>
                                    </td>
                                    <td>
                                        <div class="patient-name"><?= nurseCalendarH($row['full_name']) ?></div>
                                        <div class="subtext">P<?= str_pad((string)$row['patient_id'], 4, '0', STR_PAD_LEFT) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= nurseCalendarH($caseNumber) ?></div>
                                        <div class="subtext"><?= nurseCalendarH($row['animal_type'] ?: 'Animal not specified') ?></div>
                                    </td>
                                    <td><span class="dose-badge"><?= nurseCalendarH(nurseCalendarDoseLabel((int)$row['dose_number'])) ?></span></td>
                                    <td><span class="profile-badge"><?= nurseCalendarH(nurseCalendarProfileLabel($row['treatment_profile'] ?? null)) ?></span></td>
                                    <td>
                                        <div class="fw-semibold"><?= nurseCalendarH($row['contact_number'] ?: 'No contact') ?></div>
                                        <?php if (!empty($row['email'])): ?>
                                            <div class="subtext text-truncate" style="max-width:190px;"><?= nurseCalendarH($row['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="schedule-badge <?= nurseCalendarH($badgeClass) ?>"><?= nurseCalendarH($badgeText) ?></span></td>
                                    <td class="text-center">
                                        <a class="btn-open" href="Nurse_Vaccination.php?tab=vaccination&amp;patient_id=<?= (int)$row['patient_id'] ?>&amp;case_id=<?= (int)$row['case_id'] ?>">
                                            <i class="bi bi-shield-plus"></i>Vaccination
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <div class="pagination-info">
                        <?php
                            $from = $totalRows > 0 ? $offset + 1 : 0;
                            $to = min($offset + $limit, $totalRows);
                        ?>
                        Showing <?= $from ?>–<?= $to ?> of <?= $totalRows ?> scheduled appointment<?= $totalRows === 1 ? '' : 's' ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination-wrap" aria-label="Calendar pagination">
                            <ul class="pagination pagination-sm">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page <= 1 ? '#' : nurseCalendarH(nurseCalendarUrl($range, $search, $page - 1)) ?>" aria-label="Previous">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>

                                <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= nurseCalendarH(nurseCalendarUrl($range, $search, $p)) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : nurseCalendarH(nurseCalendarUrl($range, $search, $page + 1)) ?>" aria-label="Next">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon"><i class="bi bi-box-arrow-right"></i></div>
                <h2 class="modal-title" id="logoutConfirmModalLabel">Log out of Smart Bite Care?</h2>
            </div>
            <div class="modal-body">
                <p class="mb-0">Make sure you have saved any unfinished work before leaving your account.</p>
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
</body>
</html>
