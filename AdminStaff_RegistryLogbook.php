<?php
session_start();
require_once 'sources/pagination_helper.php';
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';
require_once 'sources/patient_display_helper.php';

/*
 * Registry logbook: the clinic's paper logbook columns, read-only, with a
 * date-range filter and PDF (landscape) and CSV exports (shared builders). ERIG, ATS and TT come from
 * the registry fields or, when those are empty, from the administered product
 * records. Dose columns show the date given, or a check mark when only the
 * registry flag is known.
 */
$user = workflowRequireUser($conn, 4);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Administrative Staff');
$notification_count = getAdminStaffNotificationCount($conn, $branchId);

function logbookDate(string $value, string $fallback): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value ? $value : $fallback;
}

function logbookNumber(float $value): string
{
    return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
}

$from = logbookDate((string)($_GET['from'] ?? ''), date('Y-m-01'));
$to = logbookDate((string)($_GET['to'] ?? ''), date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// Clinical fields: latest nurse assessment, intake as fallback; age as of
// today (sources/patient_display_helper.php, same as every other page).
$casesStmt = $conn->prepare(
    "SELECT c.case_id, c.case_number, DATE(c.created_at) AS logbook_date,
            " . patientClinicalSelectSql($conn, 'c', 'la') . ",
            c.remarks AS case_remarks,
            " . patientCaseVaccinationStatusSql('c') . " AS treatment_status,
            p.full_name, p.address, p.gender, p.contact_number, p.birthday,
            " . patientDisplayAgeSql('p') . " AS age,
            r.registry_number, r.registry_status, r.status_of_biting_animal, r.erig, r.ats, r.tt, r.active_regimen,
            r.dose_d0, r.dose_d3, r.dose_d7, r.dose_d14, r.dose_d21, r.dose_d28_30, r.remarks AS registry_remarks
     FROM animal_bite_cases c
     INNER JOIN patients p ON p.patient_id = c.patient_id
     " . patientLatestAssessmentJoinSql('c', 'la') . "
     LEFT JOIN registry_records r ON r.case_id = c.case_id AND r.is_archived = 0
     WHERE c.branch_id = ? AND c.is_archived = 0
       AND DATE(c.created_at) BETWEEN ? AND ?
     ORDER BY DATE(c.created_at) ASC, c.case_number ASC, c.case_id ASC"
);
$casesStmt->bind_param('sss', $branchId, $from, $to);
$casesStmt->execute();
$cases = $casesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$casesStmt->close();

// Completed administrations of the current cycle for the listed cases.
$administrations = [];
$caseIds = array_map(static fn($row) => (int)$row['case_id'], $cases);
if ($caseIds) {
    $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
    $types = 's' . str_repeat('i', count($caseIds));
    $params = array_merge([$branchId], $caseIds);
    $vaccStmt = $conn->prepare(
        "SELECT vr.case_id, vr.dose_number, vr.date_administered, vr.quantity_used,
                COALESCE(NULLIF(vr.quantity_unit_label, ''), NULLIF(i.base_unit_label, ''), u.unit_name, '') AS unit_label,
                UPPER(COALESCE(NULLIF(vr.vaccine_name, ''), i.item_name, '')) AS product_name
         FROM vaccination_records vr
         LEFT JOIN inventory_items i ON i.item_id = vr.item_id
         LEFT JOIN units u ON u.unit_id = i.unit_id
         WHERE vr.branch_id = ? AND vr.is_archived = 0 AND vr.vaccination_status = 'Completed'
           AND vr.date_administered IS NOT NULL AND vr.case_id IN ($placeholders)
         ORDER BY vr.date_administered, vr.vaccination_id"
    );
    $vaccStmt->bind_param($types, ...$params);
    $vaccStmt->execute();
    foreach ($vaccStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $administrations[(int)$row['case_id']][] = $row;
    }
    $vaccStmt->close();
}

// D21 is listed so PrEP (D0, D7, D21) doses are not lost.
$doseColumns = ['D0' => [1, 'dose_d0'], 'D3' => [2, 'dose_d3'], 'D7' => [3, 'dose_d7'],
    'D14' => [4, 'dose_d14'], 'D21' => [5, 'dose_d21'], 'D28' => [6, 'dose_d28_30']];

$rows = [];
foreach ($cases as $case) {
    $records = $administrations[(int)$case['case_id']] ?? [];
    $erig = [];
    $ats = [];
    $tt = !empty($case['tt']);
    $doseDates = [];
    foreach ($records as $record) {
        $name = (string)$record['product_name'];
        $unit = (string)$record['unit_label'];
        $quantity = (float)$record['quantity_used'];
        if (str_contains($name, 'ERIG') && $quantity > 0) {
            $erig[$unit] = ($erig[$unit] ?? 0) + $quantity;
        } elseif (str_starts_with($name, 'ATS') && $quantity > 0) {
            $ats[$unit] = ($ats[$unit] ?? 0) + $quantity;
        } elseif (str_starts_with($name, 'TT') || str_contains($name, 'TOXOID')) {
            $tt = true;
        }
        $dose = (int)$record['dose_number'];
        if ($dose >= 1 && !isset($doseDates[$dose])) {
            $doseDates[$dose] = (string)$record['date_administered'];
        }
    }
    $formatAmounts = static function (array $amounts, string $preferredUnit): string {
        $parts = [];
        foreach ($amounts as $unit => $quantity) {
            $parts[] = logbookNumber((float)$quantity)
                . (strcasecmp($unit, $preferredUnit) === 0 || $unit === '' ? '' : ' ' . $unit);
        }
        return implode(' + ', $parts);
    };
    $erigText = $formatAmounts($erig, 'mL');
    if ($erigText === '' && (float)($case['erig'] ?? 0) > 0) $erigText = logbookNumber((float)$case['erig']);
    $atsText = $formatAmounts($ats, 'mL');
    if ($atsText === '' && (float)($case['ats'] ?? 0) > 0) $atsText = logbookNumber((float)$case['ats']);

    $doses = [];
    foreach ($doseColumns as $label => [$doseNumber, $flag]) {
        if (isset($doseDates[$doseNumber])) {
            $doses[$label] = date('m/d/Y', strtotime($doseDates[$doseNumber]));
        } elseif (!empty($case[$flag])) {
            $doses[$label] = '✓';
        } else {
            $doses[$label] = '';
        }
    }

    $remarks = trim((string)($case['registry_remarks'] ?? ''));
    if ($remarks === '') $remarks = trim((string)($case['case_remarks'] ?? ''));

    $rows[] = [
        'Date' => !empty($case['logbook_date']) ? date('m/d/Y', strtotime($case['logbook_date'])) : '',
        'Case No.' => patientDisplayCaseNumber($case['case_number'], $case['registry_number']),
        'Patient Name' => patientDisplayName($case['full_name']),
        'Address' => (string)($case['address'] ?? ''),
        'Age' => $case['age'] !== null ? (string)$case['age'] : '',
        'Gender' => (string)($case['gender'] ?? ''),
        'Date of Bite' => !empty($case['date_of_bite']) ? date('m/d/Y', strtotime($case['date_of_bite'])) : '',
        'Site of Bite' => (string)($case['bite_location'] ?? ''),
        'Biting Animal' => (string)($case['animal_type'] ?? ''),
        'Status of Animal' => (string)($case['animal_status'] ?: ($case['status_of_biting_animal'] ?? '')),
        'Category' => (string)($case['bite_category'] ?? ''),
        'ERIG (mL)' => $erigText,
        'ATS (mL)' => $atsText,
        'TT' => $tt ? '✓' : '',
        'Active Regimen' => (string)(($case['assessment_active_regimen'] ?? '') ?: ($case['active_regimen'] ?? '')),
    ] + $doses + [
        'Contact No.' => (string)($case['contact_number'] ?? ''),
        // Shared rule: a one-time / Not Anti-Rabies case is Completed once its products are recorded.
        // Verified (locked) registry entries are marked; changes go through Request correction.
        'Status' => (string)$case['treatment_status'] . match ((string)($case['registry_status'] ?? '')) {
            'Registered' => ' · Verified (locked)',
            'For Re-verification' => ' · For re-verification',
            default => '',
        },
        'Remarks' => $remarks,
    ];
}
$columns = $rows ? array_keys($rows[0]) : array_merge(
    ['Date', 'Case No.', 'Patient Name', 'Address', 'Age', 'Gender', 'Date of Bite', 'Site of Bite',
     'Biting Animal', 'Status of Animal', 'Category', 'ERIG (mL)', 'ATS (mL)', 'TT', 'Active Regimen'],
    array_keys($doseColumns),
    ['Contact No.', 'Status', 'Remarks']
);

$exportFormat = (string)($_GET['export'] ?? '');
if (in_array($exportFormat, ['csv', 'pdf'], true)) {
    require_once 'sources/report_export.php';
    workflowAudit($conn, $userId, $branchId, 'Exported registry logbook (' . strtoupper($exportFormat) . ') ' . $from . ' to ' . $to . ' (' . count($rows) . ' cases)', 'Registry');
    // Widths in mm at 5.5 pt so dates and words fit on one line (A4 landscape, 8 mm margins).
    $widths = ['Date' => 12, 'Case No.' => 11, 'Patient Name' => 15.5, 'Address' => 15.5, 'Age' => 5.5, 'Gender' => 8.5, 'Date of Bite' => 12,
        'Site of Bite' => 13, 'Biting Animal' => 9, 'Status of Animal' => 13, 'Category' => 10, 'ERIG (mL)' => 7, 'ATS (mL)' => 7, 'TT' => 4.5,
        'Active Regimen' => 13, 'D0' => 12, 'D3' => 12, 'D7' => 12, 'D14' => 12, 'D21' => 12, 'D28' => 12,
        'Contact No.' => 16, 'Status' => 12.5, 'Remarks' => 12];
    $report = [
        'title' => 'Registry Logbook', 'orientation' => 'L', 'margin' => 8,
        'summary' => ['Cases' => (string)count($rows)],
        'sections' => [[
            'font' => 5.5,
            'columns' => array_map(static fn($c) => ['label' => $c, 'width' => $widths[$c] ?? 12,
                'align' => in_array($c, ['Age', 'ERIG (mL)', 'ATS (mL)'], true) ? 'R' : (in_array($c, ['TT', 'D0', 'D3', 'D7', 'D14', 'D21', 'D28'], true) ? 'C' : 'L')], $columns),
            'rows' => array_map('array_values', $rows),
            'empty' => 'No cases were admitted in this date range.',
        ]],
        'notes' => ['Dose columns show the date given, or a check mark when only the registry flag is known. ERIG and ATS are in mL.',
            'Clinical fields use the latest Nurse assessment, with the intake as fallback. "Verified (locked)" entries change only through Request correction.'],
    ];
    reportSend($report, reportMeta($conn, $userId, $branchName, reportPeriodText($from, $to)), $exportFormat,
        'Registry_Logbook_' . $branchId . '_' . $from . '_to_' . $to);
}
// The screen shows one page (10/25/50); the exports above always use every row.
$logbookPage = paginationFromRequest('', 25);
$logbookPageRows = paginationSlice($rows, $logbookPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registry Logbook - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">
    <style>
        :root{--primary:#2B3A8C;--accent:#F21D2F}
        *{box-sizing:border-box}
        body{margin:0;background:#f0f2f5;font-family:'Segoe UI',sans-serif}
        .main{min-height:100vh;margin-left:260px;background:#f0f2f5}
        .topbar{display:flex;align-items:center;justify-content:space-between;height:80px;padding:0 35px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        .topbar h3{margin:0;color:var(--primary);font-size:28px;font-weight:700}
        .topbar h3 small{margin-left:8px;color:#6c757d;font-size:16px;font-weight:400}
        .profile{display:flex;align-items:center;gap:6px;color:var(--primary);font-weight:600}
        .page-content{padding:30px 35px}
        .content-card{overflow:hidden;background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(43,58,140,.08)}
        .content-card-header{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:16px;padding:22px 24px;border-bottom:1px solid #edf0f5}
        .content-card-header h5{margin:0;color:var(--primary);font-size:18px;font-weight:700}
        .content-card-header p{margin:6px 0 0;color:#8b95a7;font-size:13px}
        .logbook-table{min-width:2100px;margin:0;font-size:12px}
        .logbook-table thead th{position:sticky;top:0;padding:10px;color:#fff;background:var(--primary);font-size:11px;font-weight:700;text-transform:uppercase;white-space:nowrap}
        .logbook-table tbody td{padding:9px 10px;vertical-align:top;border-color:#edf0f4}
        .logbook-table tbody tr:nth-child(even){background:#f8f9fc}
        .dose-cell{text-align:center;white-space:nowrap}
        .btn-primary-custom{display:inline-flex;align-items:center;gap:6px;color:#fff;background:var(--primary);border:0;border-radius:8px;padding:8px 14px;font-weight:600;text-decoration:none}
        .btn-primary-custom:hover{color:#fff;background:#202d70}
        @media(max-width:991px){.main{margin-left:90px}}
        @media(max-width:767px){.topbar{height:70px;padding:0 16px}.topbar h3{font-size:20px}.page-content{padding:20px 16px}}
    </style>
</head>
<body>
<div class="sidebar" id="sidebar">
    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true">
        <i class="bi bi-chevron-left"></i>
    </button>
    <div class="logo-area">
        <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo" /></div>
        <div class="system-name">Smart Bite Care</div>
    </div>
    <nav class="nav-menu">
        <ul>
            <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
            <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
            <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
            <li><a class="active" href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
            <li><a href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
            <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
            <li><a href="AdminStaff_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li>
                <a href="AdminStaff_Notifications.php" class="notification-link">
                    <i class="bi bi-bell-fill"></i><span>Notifications</span>
                    <?php if ($notification_count > 0): ?>
                        <span class="notification-badge"><?php echo (int)$notification_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </nav>
</div>

<div class="main">
    <div class="topbar">
        <h3>Registry Logbook <small><?php echo workflowH($branchName); ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?php echo workflowH($username); ?></span></div>
    </div>

    <div class="page-content">
        <section class="content-card">
            <div class="content-card-header">
                <div>
                    <h5><i class="bi bi-journal-text me-1"></i>Clinic Logbook View</h5>
                    <p>
                        <?php echo number_format(count($rows)); ?> case(s) admitted
                        <?php echo workflowH(date('M d, Y', strtotime($from))); ?> to <?php echo workflowH(date('M d, Y', strtotime($to))); ?>.
                        Dose columns show the date given (✓ when only the registry flag is recorded).
                    </p>
                </div>
                <form method="get" class="d-flex flex-wrap align-items-end gap-2">
                    <div>
                        <label class="form-label small mb-1" for="from">From</label>
                        <input type="date" class="form-control form-control-sm" id="from" name="from" value="<?php echo workflowH($from); ?>">
                    </div>
                    <div>
                        <label class="form-label small mb-1" for="to">To</label>
                        <input type="date" class="form-control form-control-sm" id="to" name="to" value="<?php echo workflowH($to); ?>">
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <a class="btn-primary-custom btn-sm" href="?<?php echo workflowH(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])); ?>">
                        <i class="bi bi-file-earmark-spreadsheet"></i>Export to Excel (CSV)
                    </a>
                    <a class="btn-primary-custom btn-sm" href="?<?php echo workflowH(http_build_query(['from' => $from, 'to' => $to, 'export' => 'pdf'])); ?>">
                        <i class="bi bi-file-earmark-pdf"></i>Export PDF (landscape)
                    </a>
                    <a class="btn btn-light border btn-sm" href="AdminStaff_Registry.php"><i class="bi bi-arrow-left me-1"></i>Registry Queue</a>
                </form>
            </div>
            <div class="table-responsive" style="max-height:70vh">
                <table class="table logbook-table align-middle">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <th><?php echo workflowH($column === 'TT' ? 'TT (✓)' : $column); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="<?php echo count($columns); ?>" class="text-center text-muted py-5">No cases were admitted in this date range.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($logbookPageRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $column => $value): ?>
                                    <td class="<?php echo isset($doseColumns[$column]) || $column === 'TT' ? 'dose-cell' : ''; ?>"><?php echo workflowH((string)$value); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo paginationRender($logbookPage, '', 'cases'); ?>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="sources/sidebar.js"></script>
</body>
</html>
