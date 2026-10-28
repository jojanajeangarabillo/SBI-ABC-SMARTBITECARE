<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';
require_once 'sources/philhealth_requirements_helper.php';
require_once 'sources/pagination_helper.php';
require_once 'sources/report_page_layout.php';

/*
 * ADMIN STAFF - PHILHEALTH PATIENT STATUS (read-only overview).
 * Same look as the Branch Admin PhilHealth Processing page: summary cards
 * by status, filters, a paginated queue with status badges, and exports of
 * every row of the filters. Statuses are only changed on the PhilHealth
 * Workflow page (history + audit there); this page never changes them.
 * Archived cases, patients and PhilHealth records are not listed.
 */
$user = workflowRequireUser($conn, 4);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Every PhilHealth status, in workflow order (the philhealth_records.status values).
const PHILHEALTH_STATUS_ORDER = ['For Writing', 'For Screening', 'Returned for Correction', 'Ready for Main Branch',
    'Sent to Main Branch', 'Submitted to PhilHealth', 'Denied', 'Reimbursed', 'Completed'];

function philhealthStatusBadgeClass(string $status): string
{
    return [
        'For Writing' => 'status-warning', 'For Screening' => 'status-info', 'Returned for Correction' => 'status-danger',
        'Ready for Main Branch' => 'status-primary', 'Sent to Main Branch' => 'status-info',
        'Submitted to PhilHealth' => 'status-warning', 'Denied' => 'status-danger',
        'Reimbursed' => 'status-success', 'Completed' => 'status-success',
    ][$status] ?? 'status-secondary';
}

function philhealthDateText(?string $value): string
{
    return $value && !str_starts_with($value, '0000') ? date('M d, Y', strtotime($value)) : '';
}

// ---------------------------------------------------------------- filters
$search = trim((string)($_GET['q'] ?? ''));
if (strlen($search) > 100) $search = substr($search, 0, 100);
$statusFilter = (string)($_GET['status'] ?? '');
if ($statusFilter !== 'none' && !in_array($statusFilter, PHILHEALTH_STATUS_ORDER, true)) $statusFilter = '';
$coverage = ($_GET['coverage'] ?? 'members') === 'all' ? 'all' : 'members';
$validDate = static function (string $v): string {
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : '';
};
$dateFrom = $validDate(trim((string)($_GET['from'] ?? '')));
$dateTo = $validDate(trim((string)($_GET['to'] ?? '')));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$baseFrom = "FROM philhealth_records ph
    INNER JOIN animal_bite_cases c ON c.case_id = ph.case_id
    INNER JOIN patients p ON p.patient_id = c.patient_id";
$where = ['c.branch_id = ?', 'ph.is_archived = 0', 'c.is_archived = 0', 'p.is_archived = 0'];
$types = 's';
$params = [$branchId];
if ($coverage === 'members') $where[] = "ph.has_philhealth = 'Yes'";
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(p.full_name LIKE ? OR c.case_number LIKE ? OR EXISTS (SELECT 1 FROM registry_records rs WHERE rs.case_id = c.case_id AND rs.is_archived = 0 AND rs.registry_number LIKE ?))";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($statusFilter === 'none') {
    $where[] = "(ph.status IS NULL OR ph.status = '')";
} elseif ($statusFilter !== '') {
    $where[] = 'ph.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($dateFrom !== '') { $where[] = 'c.created_at >= ?'; $types .= 's'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $types .= 's'; $params[] = $dateTo; }
$whereSql = implode(' AND ', $where);

$selectSql = "SELECT ph.philhealth_record_id, ph.has_philhealth, ph.philhealth_membership, ph.status, ph.remarks, ph.updated_at,
        ph.date_sent_to_main, ph.date_submitted_philhealth, ph.date_resolved, ph.eligible_again_on,
        " . (philhealthRequirementsReady($conn) ? implode(', ', array_map(static fn($f) => 'ph.' . $f, array_keys(philhealthRequirementFields()))) . ',' : '') . "
        p.full_name, p.contact_number, DATE(c.created_at) AS confinement_date,
        COALESCE(NULLIF(TRIM(c.case_number), ''), (SELECT MAX(rr.registry_number) FROM registry_records rr WHERE rr.case_id = c.case_id AND rr.is_archived = 0)) AS case_no,
        (SELECT MAX(v.date_administered) FROM vaccination_records v WHERE v.case_id = c.case_id AND v.branch_id = c.branch_id
            AND v.is_archived = 0 AND v.vaccination_status = 'Completed') AS discharge_date
    $baseFrom WHERE $whereSql";
$orderSql = ' ORDER BY c.created_at DESC, c.case_id DESC';

$fetch = static function (string $sql, string $t, array $p) use ($conn): array {
    $stmt = $conn->prepare($sql);
    if ($t !== '') $stmt->bind_param($t, ...$p);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
};

// ---------------------------------------------------------------- export (every row of the filters)
if (($_GET['export'] ?? '') === 'pdf') {
    require_once __DIR__ . '/sources/report_export.php';
    $exportFormat = (string)$_GET['export'];
    $rows = $fetch($selectSql . $orderSql, $types, $params);
    $exportRows = array_map(static fn(array $r): array => [
        (string)$r['full_name'], (string)($r['case_no'] ?? ''), philhealthDateText($r['confinement_date']), philhealthDateText($r['discharge_date']),
        $r['has_philhealth'] === 'Yes' ? ((string)$r['philhealth_membership'] ?: 'Member') : 'Not a member',
        (string)($r['status'] ?: 'No status yet'), (string)($r['remarks'] ?? ''),
    ], $rows);
    $filters = array_filter([
        $coverage === 'members' ? 'PhilHealth members' : 'All patients',
        $statusFilter !== '' ? 'Status: ' . ($statusFilter === 'none' ? 'No status yet' : $statusFilter) : '',
        $search !== '' ? 'Search: ' . $search : '',
    ]);
    $exportReport = [
        'title' => 'PhilHealth Patient Status', 'orientation' => 'L',
        'summary' => ['Records' => (string)count($exportRows)] + array_map('strval', array_count_values(array_column($exportRows, 5))),
        'sections' => [[
            'columns' => [['label' => 'Patient Name', 'width' => 2.2], ['label' => 'Case No.', 'width' => 1.1], ['label' => 'Confinement', 'width' => 1.2],
                ['label' => 'Discharge', 'width' => 1.2], ['label' => 'PhilHealth', 'width' => 1.3], ['label' => 'Status', 'width' => 1.6], ['label' => 'Remarks', 'width' => 3]],
            'rows' => $exportRows,
            'empty' => 'No PhilHealth records match the filters.',
        ]],
        'notes' => ['Discharge is the date of the last completed dose. Statuses are changed on the PhilHealth Workflow page.'],
    ];
    $period = ($dateFrom || $dateTo) ? reportPeriodText($dateFrom ?: $dateTo, $dateTo ?: $dateFrom) : 'All dates';
    workflowAudit($conn, $userId, $branchId, reportExportVerb() . ' PhilHealth patient status (PDF) - ' . $period . ' (' . count($exportRows) . ' records)', 'PhilHealth');
    reportSend($exportReport, reportMeta($conn, $userId, $branchName, $period, implode(' · ', $filters)), $exportFormat,
        'PhilHealth_Status_' . $branchId . '_' . date('Ymd_His'));
}

// ---------------------------------------------------------------- summary cards (branch members, not the filters)
// Grouped over a derived column (ONLY_FULL_GROUP_BY on MariaDB and MySQL).
$cardRows = $fetch("SELECT s.status, COUNT(*) AS total FROM (
        SELECT COALESCE(NULLIF(ph.status, ''), 'none') AS status
        FROM philhealth_records ph
        INNER JOIN animal_bite_cases c ON c.case_id = ph.case_id
        INNER JOIN patients p ON p.patient_id = c.patient_id
        WHERE c.branch_id = ? AND ph.has_philhealth = 'Yes' AND ph.is_archived = 0 AND c.is_archived = 0 AND p.is_archived = 0
    ) s GROUP BY s.status", 's', [$branchId]);
$byStatus = array_column($cardRows, 'total', 'status');
$sum = static fn(array $keys): int => array_sum(array_map(static fn($k) => (int)($byStatus[$k] ?? 0), $keys));
$cards = [
    ['', 'bi-people-fill', 'PhilHealth members', array_sum(array_map('intval', $byStatus)), 'Active cases with PhilHealth', ''],
    ['warning', 'bi-pencil-square', 'Action required', $sum(['none', 'For Writing', 'For Screening', 'Returned for Correction']), 'No status, writing, screening or returned', ''],
    ['info', 'bi-send-fill', 'At the main branch', $sum(['Ready for Main Branch', 'Sent to Main Branch']), 'Ready for or sent to the main branch', ''],
    ['danger', 'bi-arrow-counterclockwise', 'Returned / denied', $sum(['Returned for Correction', 'Denied']), 'Needs correction or was denied', ''],
    ['success', 'bi-check2-circle', 'Submitted / resolved', $sum(['Submitted to PhilHealth', 'Reimbursed', 'Completed']), 'Submitted, reimbursed or completed', ''],
];

// ---------------------------------------------------------------- queue (one page)
$total = (int)($fetch("SELECT COUNT(*) AS total $baseFrom WHERE $whereSql", $types, $params)[0]['total'] ?? 0);
$pg = paginationApply(paginationFromRequest('', 10), $total);
$records = $fetch($selectSql . $orderSql . ' LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $types, $params);
$statusCountsInFilter = $fetch("SELECT s.status, COUNT(*) AS total FROM (
        SELECT COALESCE(NULLIF(ph.status, ''), 'No status yet') AS status $baseFrom WHERE $whereSql
    ) s GROUP BY s.status ORDER BY total DESC, s.status", $types, $params);

$exportQuery = http_build_query(array_filter(['q' => $search, 'status' => $statusFilter, 'coverage' => $coverage, 'from' => $dateFrom, 'to' => $dateTo],
    static fn($v) => $v !== ''));
$requirementsReady = philhealthRequirementsReady($conn);
$requirementTotal = count(philhealthRequirementFields());

ob_start();
?>
<style>
    .ph-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:20px;margin-bottom:25px}
    .ph-stat{position:relative;display:flex;align-items:center;gap:18px;min-height:120px;overflow:hidden;padding:22px 24px;background:#fff;border-radius:16px;box-shadow:0 5px 15px rgba(0,0,0,.06)}
    .ph-stat::before{position:absolute;top:0;bottom:0;left:0;width:5px;background:#2B3A8C;content:''}
    .ph-stat.info::before{background:#12a8c0}.ph-stat.warning::before{background:#ffb800}.ph-stat.danger::before{background:#dc3545}.ph-stat.success::before{background:#28a745}
    .ph-stat .ph-stat-icon{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;color:#2B3A8C;font-size:30px;flex-shrink:0}
    .ph-stat.info .ph-stat-icon{color:#12a8c0}.ph-stat.warning .ph-stat-icon{color:#d89b00}.ph-stat.danger .ph-stat-icon{color:#dc3545}.ph-stat.success .ph-stat-icon{color:#28a745}
    .ph-stat-label{margin-bottom:3px;color:#71809d;font-size:12px;font-weight:700;letter-spacing:.25px;text-transform:uppercase}
    .ph-stat-number{color:#111827;font-size:32px;font-weight:700;line-height:1.1}
    .ph-stat-desc{margin-top:5px;color:#8a94a6;font-size:11px}
    .ph-notice{display:flex;align-items:flex-start;gap:12px;margin-bottom:25px;padding:16px 18px;color:#34526f;background:#eaf6fb;border:1px solid #cceaf4;border-radius:12px}
    .ph-notice>i{color:#12a8c0;font-size:21px}.ph-notice strong{display:block;margin-bottom:3px;color:#234664}.ph-notice p{margin:0;font-size:13px;line-height:1.5}
    .ph-card{overflow:hidden;background:#fff;border-radius:18px;box-shadow:0 3px 8px rgba(0,0,0,.08)}
    .ph-card-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 24px;border-bottom:1px solid #edf0f5;flex-wrap:wrap}
    .ph-card-header h5{display:flex;align-items:center;gap:10px;margin:0;color:#2B3A8C;font-size:19px;font-weight:700}
    .ph-card-header p{margin:6px 0 0;color:#8b95a7;font-size:13px}
    .ph-section-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;color:#fff;background:#2B3A8C;border-radius:10px;font-size:17px}
    .ph-filters{display:grid;grid-template-columns:2fr 1.4fr 1.2fr 1fr 1fr auto;gap:12px;align-items:end;padding:18px 24px;background:#f8f9fc;border-bottom:1px solid #edf0f5}
    .ph-filters label{display:block;margin-bottom:5px;color:#596579;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.25px}
    .ph-filters .form-control,.ph-filters .form-select{min-height:38px;border-color:#dfe3eb;border-radius:9px;font-size:13px}
    .ph-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;padding:7px 14px;border-radius:9px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid #2B3A8C}
    .ph-btn.primary{color:#fff;background:#2B3A8C}.ph-btn.primary:hover{background:#202d70}
    .ph-btn.outline{color:#2B3A8C;background:#fff}.ph-btn.outline:hover{color:#fff;background:#2B3A8C}
    .ph-table{min-width:1000px;margin:0}
    .ph-table thead th{padding:14px 18px;color:#667085;background:#f8f9fc;border-bottom:1px solid #e6eaf0;font-size:12px;font-weight:700;letter-spacing:.25px;text-transform:uppercase;white-space:nowrap}
    .ph-table tbody td{padding:16px 18px;color:#4c566a;border-color:#edf0f4;font-size:13px;vertical-align:top}
    .ph-table tbody tr:hover{background:#fbfcff}
    .ph-name{color:#25324b;font-size:14px;font-weight:700}.ph-case{display:inline-block;margin-top:4px;color:#2B3A8C;font-size:12px;font-weight:600}
    .ph-muted{color:#98a2b3;font-size:12px}
    .badge-status{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
    .status-warning{color:#8a6300;background:#fff3cd}.status-info{color:#0c6876;background:#d7f3f7}.status-primary{color:#26377f;background:#e4e8fb}
    .status-success{color:#1f7a35;background:#ddf3e3}.status-danger{color:#a22632;background:#fde2e5}.status-secondary{color:#5e6878;background:#edf0f4}
    .ph-remarks{max-width:240px;margin-top:8px;color:#7d8798;font-size:12px;line-height:1.4}
    .ph-chips{display:flex;flex-wrap:wrap;gap:6px;padding:12px 24px;border-bottom:1px solid #edf0f5}
    .ph-chip{padding:4px 10px;border-radius:999px;background:#eef1fb;color:#2B3A8C;font-size:12px;font-weight:600}
    .ph-empty{padding:52px 20px!important;color:#98a2b3!important;text-align:center}.ph-empty i{display:block;margin-bottom:8px;font-size:36px}
    @media(max-width:1199px){.ph-filters{grid-template-columns:1fr 1fr 1fr}}
    @media(max-width:767px){.ph-filters{grid-template-columns:1fr}}
</style>

<div class="ph-stats">
    <?php foreach ($cards as [$tone, $icon, $label, $value, $desc]): ?>
        <div class="ph-stat <?= $h($tone) ?>">
            <div class="ph-stat-icon"><i class="bi <?= $h($icon) ?>"></i></div>
            <div><div class="ph-stat-label"><?= $h($label) ?></div><div class="ph-stat-number"><?= (int)$value ?></div><div class="ph-stat-desc"><?= $h($desc) ?></div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="ph-notice">
    <i class="bi bi-info-circle-fill"></i>
    <div>
        <strong>Read-only status overview</strong>
        <p>Change a status on the <a href="AdminStaff_PhilhealthWorkflow.php">PhilHealth Workflow</a> page, where every change is saved in the status history and the audit log. The cards count all PhilHealth members of the branch; the table follows the filters.</p>
    </div>
</div>

<section class="ph-card" id="queue">
    <div class="ph-card-header">
        <div>
            <h5><span class="ph-section-icon"><i class="bi bi-list-check"></i></span>PhilHealth Patient Queue</h5>
            <p><?= $coverage === 'members' ? 'PhilHealth members' : 'All patients, PhilHealth members and non-members' ?> · <?= number_format($total) ?> matching record<?= $total === 1 ? '' : 's' ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap" id="phExportButtons">
            <a class="ph-btn outline" href="AdminStaff_PhilhealthStatus.php?<?= $h($exportQuery . ($exportQuery !== '' ? '&' : '') . 'export=pdf') ?>"><i class="bi bi-file-earmark-pdf"></i>Export PDF</a>
            <button type="button" class="ph-btn outline" data-print-pdf="AdminStaff_PhilhealthStatus.php?<?= $h($exportQuery . ($exportQuery !== '' ? '&' : '') . 'export=pdf') ?>"><i class="bi bi-printer"></i>Print</button>
        </div>
    </div>
    <form method="get" class="ph-filters" action="AdminStaff_PhilhealthStatus.php#queue">
        <div><label for="phSearch">Search</label><input type="search" class="form-control" id="phSearch" name="q" maxlength="100" value="<?= $h($search) ?>" placeholder="Patient name or case no."></div>
        <div><label for="phStatus">Status</label>
            <select class="form-select" id="phStatus" name="status">
                <option value="">All statuses</option>
                <option value="none"<?= $statusFilter === 'none' ? ' selected' : '' ?>>No status yet</option>
                <?php foreach (PHILHEALTH_STATUS_ORDER as $status): ?><option value="<?= $h($status) ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= $h($status) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label for="phCoverage">Patients</label>
            <select class="form-select" id="phCoverage" name="coverage">
                <option value="members"<?= $coverage === 'members' ? ' selected' : '' ?>>PhilHealth members</option>
                <option value="all"<?= $coverage === 'all' ? ' selected' : '' ?>>All patients</option>
            </select>
        </div>
        <div><label for="phFrom">Confined from</label><input type="date" class="form-control" id="phFrom" name="from" value="<?= $h($dateFrom) ?>"></div>
        <div><label for="phTo">Confined to</label><input type="date" class="form-control" id="phTo" name="to" value="<?= $h($dateTo) ?>"></div>
        <div class="d-flex gap-2">
            <input type="hidden" name="per_page" value="<?= (int)$pg['per_page'] ?>">
            <button type="submit" class="ph-btn primary"><i class="bi bi-funnel"></i>Apply</button>
            <a class="ph-btn outline" href="AdminStaff_PhilhealthStatus.php#queue" title="Clear filters"><i class="bi bi-eraser"></i></a>
        </div>
    </form>
    <?php if ($statusCountsInFilter): ?>
        <div class="ph-chips">
            <?php foreach ($statusCountsInFilter as $row): ?><span class="ph-chip"><?= $h($row['status']) ?>: <?= (int)$row['total'] ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table ph-table align-middle">
            <thead><tr><th>Patient / Case</th><th>PhilHealth</th><th>Confinement</th><th>Discharge</th><th>Status</th><th>Requirements</th><th>Last Updated</th></tr></thead>
            <tbody>
            <?php if (!$records): ?>
                <tr><td colspan="7" class="ph-empty"><i class="bi bi-inbox"></i>No PhilHealth records match the filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): $status = (string)($r['status'] ?? ''); ?>
                <tr>
                    <td><div class="ph-name"><?= $h($r['full_name']) ?></div><span class="ph-case"><?= $h($r['case_no'] ?: 'No case no.') ?></span><?php if (!empty($r['contact_number'])): ?><div class="ph-muted"><?= $h($r['contact_number']) ?></div><?php endif; ?></td>
                    <td><?= $r['has_philhealth'] === 'Yes' ? $h($r['philhealth_membership'] ?: 'Member') : '<span class="ph-muted">Not a member</span>' ?></td>
                    <td><?= $h(philhealthDateText($r['confinement_date'])) ?></td>
                    <td><?= $r['discharge_date'] ? $h(philhealthDateText($r['discharge_date'])) : '<span class="badge-status status-secondary">Ongoing</span>' ?></td>
                    <td><span class="badge-status <?= $h(philhealthStatusBadgeClass($status)) ?>"><?= $h($status !== '' ? $status : 'No status yet') ?></span>
                        <?php if (trim((string)($r['remarks'] ?? '')) !== ''): ?><div class="ph-remarks"><?= $h($r['remarks']) ?></div><?php endif; ?></td>
                    <td><?php if ($requirementsReady): $received = philhealthRequirementsReceived($r); ?><?= count($received) ?> of <?= $requirementTotal ?><?php if ($received): ?><div class="ph-muted"><?= $h(implode(', ', $received)) ?></div><?php endif; ?><?php else: ?><span class="ph-muted">—</span><?php endif; ?></td>
                    <td><?= $h($r['updated_at'] ? date('M d, Y h:i A', strtotime($r['updated_at'])) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginationRender($pg, '#queue', 'records') ?>
</section>
<?php
$content = (string)ob_get_clean();
echo reportPageRender(['role_id' => 4] + $user, 'PhilHealth Patient Status', $content,
    (int)getAdminStaffNotificationCount($conn, $branchId, $userId), 'AdminStaff_PhilhealthWorkflow.php');
