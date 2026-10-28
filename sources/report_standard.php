<?php
declare(strict_types=1);

require_once __DIR__ . '/report_catalog.php';
require_once __DIR__ . '/report_center.php';
require_once __DIR__ . '/inventory_stock_helpers.php';
require_once __DIR__ . '/patient_display_helper.php';
require_once __DIR__ . '/dashboard_patient_metrics.php';
require_once __DIR__ . '/workflow_helpers.php';

/*
 * THE EXISTING BRANCH ADMIN AND SUPER ADMIN REPORTS on the shared builders
 * (sources/report_export.php): one report array gives the preview, the PDF
 * and the CSV, with every row (no LIMIT, no cut text).
 * Totals use the same definitions as the pages they must match:
 *   patients / cases / vaccinations given: the Branch Admin Dashboard
 *     (dashboardPatientMetrics / dashboardVaccinationsGiven, archived excluded);
 *   inventory: Inventory Overview (inventoryBranchSummary).
 * With no dates, a report's totals equal those cards.
 * $p: branch_id, from, to, all_dates (see sources/report_center.php).
 */

function reportStdCol(string $label, float $width, string $align = 'L'): array
{
    return ['label' => $label, 'width' => $width, 'align' => $align];
}

/** " AND DATE(col) BETWEEN ? AND ?" when a period was chosen. */
function reportStdPeriod(array $p, string $column, string &$types, array &$params): string
{
    if (!empty($p['all_dates'])) return '';
    $types .= 'ss';
    $params[] = (string)$p['from'];
    $params[] = (string)$p['to'];
    return " AND DATE($column) BETWEEN ? AND ?";
}

function reportStdFetch(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function reportStdAge(?string $birthday): string
{
    if (!$birthday || str_starts_with($birthday, '0000')) return '';
    try {
        return (string)(new DateTime($birthday))->diff(new DateTime('today'))->y;
    } catch (Throwable $e) {
        return '';
    }
}

/* ---------------- Branch Admin: Patient Report ---------------- */
function reportStdPatients(mysqli $conn, array $p): array
{
    $branchId = (string)$p['branch_id'];
    $types = 's';
    $params = [$branchId];
    $period = reportStdPeriod($p, 'p.created_at', $types, $params);
    // Counts are subqueries (no GROUP BY, no row multiplication).
    $rows = reportStdFetch($conn, "SELECT p.patient_id, p.full_name, p.gender, p.birthday, p.contact_number, p.address, p.created_at,
            (SELECT COUNT(*) FROM animal_bite_cases c WHERE c.patient_id = p.patient_id AND c.branch_id = p.branch_id AND c.is_archived = 0) AS total_cases,
            (SELECT COUNT(*) FROM vaccination_records vr LEFT JOIN animal_bite_cases vc ON vc.case_id = vr.case_id
               WHERE vr.patient_id = p.patient_id AND vr.branch_id = p.branch_id AND vr.is_archived = 0
                 AND vr.vaccination_status = 'Completed' AND COALESCE(vc.is_archived, 0) = 0) AS vaccinations_given
        FROM patients p
        WHERE p.branch_id = ? AND p.is_archived = 0 $period
        ORDER BY p.created_at DESC, p.patient_id DESC", $types, $params);
    $gender = ['Male' => 0, 'Female' => 0, 'Not recorded' => 0];
    $data = [];
    foreach ($rows as $r) {
        $g = in_array($r['gender'], ['Male', 'Female'], true) ? $r['gender'] : 'Not recorded';
        $gender[$g]++;
        $data[] = [(string)$r['patient_id'], (string)$r['full_name'], $r['gender'] ?: '—', reportStdAge($r['birthday']),
            $r['contact_number'] ?: '—', $r['address'] ?: '—', (string)$r['total_cases'], (string)$r['vaccinations_given'], reportDate($r['created_at'])];
    }
    $cases = array_sum(array_column($rows, 'total_cases'));
    $given = array_sum(array_column($rows, 'vaccinations_given'));
    return [
        'title' => 'Patient Report', 'orientation' => 'L',
        'summary' => ['Patients' => (string)count($rows), 'Cases' => (string)$cases, 'Vaccinations given' => (string)$given]
            + array_map('strval', $gender),
        'sections' => [[
            'title' => 'Patients registered in the period',
            'columns' => [reportStdCol('Patient ID', 1), reportStdCol('Name', 2.6), reportStdCol('Gender', 1), reportStdCol('Age', 0.7, 'R'),
                reportStdCol('Contact No.', 1.6), reportStdCol('Address', 3.2), reportStdCol('Cases', 0.8, 'R'), reportStdCol('Vaccinations Given', 1.3, 'R'),
                reportStdCol('Registered', 1.4)],
            'rows' => $data,
            'totals' => ['Total', count($rows) . ' patients', '', '', '', '', (string)$cases, (string)$given, ''],
        ]],
        'notes' => ['Archived patients, cases and doses are not counted (same as the Branch Admin Dashboard).',
            'Cases and vaccinations given are the patient\'s totals; the period filters the registration date.'],
    ];
}

/* ---------------- Branch Admin: Case Report ---------------- */
function reportStdCases(mysqli $conn, array $p): array
{
    $branchId = (string)$p['branch_id'];
    $types = 's';
    $params = [$branchId];
    $period = reportStdPeriod($p, 'c.created_at', $types, $params);
    $rows = reportStdFetch($conn, "SELECT c.case_id, c.case_number, c.created_at, p.full_name,
            " . patientClinicalSelectSql($conn, 'c', 'la') . ",
            " . patientCaseStatusSql('c') . " AS case_status,
            (SELECT MAX(rr.registry_number) FROM registry_records rr WHERE rr.case_id = c.case_id AND rr.is_archived = 0) AS registry_number
        FROM animal_bite_cases c
        INNER JOIN patients p ON p.patient_id = c.patient_id AND p.branch_id = c.branch_id AND p.is_archived = 0
        " . patientLatestAssessmentJoinSql('c', 'la') . "
        WHERE c.branch_id = ? AND c.is_archived = 0 $period
        ORDER BY c.created_at DESC, c.case_id DESC", $types, $params);
    $status = [];
    $withRegistry = 0;
    $data = [];
    foreach ($rows as $r) {
        $s = (string)$r['case_status'];
        $status[$s] = ($status[$s] ?? 0) + 1;
        if (trim((string)$r['registry_number']) !== '') $withRegistry++;
        $data[] = [patientDisplayCaseNumber($r['case_number'], $r['registry_number']), (string)$r['full_name'], reportDate($r['created_at']),
            reportDate($r['date_of_bite']), $r['animal_type'] ?: '—', $r['bite_location'] ?: '—', $r['bite_category'] ?: '—', $s,
            $r['registry_number'] ?: '—'];
    }
    $summary = ['Cases' => (string)count($rows), 'Ongoing' => (string)($status['Ongoing'] ?? 0), 'Completed' => (string)($status['Completed'] ?? 0)];
    foreach ($status as $s => $n) if (!isset($summary[$s])) $summary[$s] = (string)$n;
    $summary['With registry number'] = (string)$withRegistry;
    return [
        'title' => 'Case Report', 'orientation' => 'L', 'summary' => $summary,
        'sections' => [[
            'title' => 'Cases admitted in the period',
            'columns' => [reportStdCol('Case No.', 1.2), reportStdCol('Patient', 2.4), reportStdCol('Admission', 1.3), reportStdCol('Date of Bite', 1.3),
                reportStdCol('Biting Animal', 1.4), reportStdCol('Site of Bite', 2.6), reportStdCol('Category', 1), reportStdCol('Status', 1.2),
                reportStdCol('Registry No.', 1.3)],
            'rows' => $data,
        ]],
        'notes' => ['Bite details use the latest Nurse assessment, with the intake as fallback (the values every page shows).',
            'Status is the shared case status used on the dashboards. Archived cases are not counted.'],
    ];
}

/* ---------------- Branch Admin: Inventory Report ---------------- */
function reportStdInventory(mysqli $conn, array $p): array
{
    $branchId = (string)$p['branch_id'];
    $usageWhere = empty($p['all_dates']) ? ' AND h.usage_date BETWEEN ? AND ?' : '';
    $types = 's' . ($usageWhere ? 'ss' : '') . 's';
    $params = [$branchId];
    if ($usageWhere) { $params[] = (string)$p['from']; $params[] = (string)$p['to']; }
    $params[] = $branchId;
    // Every item with a stock record in the branch (Inventory Overview), as of now.
    $rows = reportStdFetch($conn, "SELECT i.item_id, i.item_name, ic.category_name, i.minimum_stock,
            COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS base_unit_label,
            COALESCE(NULLIF(TRIM(i.display_unit_label), ''), u.unit_name) AS display_unit_label,
            COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base, u.unit_name,
            s.usable_stock, s.nearest_expiration,
            (SELECT COALESCE(SUM(h.quantity_used), 0) FROM inventory_usage_history h
               WHERE h.item_id = i.item_id AND h.branch_id = ?$usageWhere) AS used_in_period
        FROM (" . inventoryStockTotalsSql() . ") s
        INNER JOIN inventory_items i ON i.item_id = s.item_id
        INNER JOIN inventory_categories ic ON ic.category_id = i.category_id
        LEFT JOIN units u ON u.unit_id = i.unit_id
        WHERE s.branch_id = ?
        ORDER BY ic.category_name, i.item_name", $types, $params);
    $card = inventoryBranchSummary($conn, $branchId);
    $data = [];
    foreach ($rows as $r) {
        $key = inventoryStockStatusKey((float)$r['usable_stock'], (float)$r['minimum_stock']);
        $statusText = ['out' => 'Out of Stock', 'low' => 'Low Stock', 'ok' => 'OK'][$key];
        if ($key === 'ok' && $r['nearest_expiration'] && $r['nearest_expiration'] >= date('Y-m-d')
            && $r['nearest_expiration'] <= date('Y-m-d', strtotime('+30 days'))) $statusText = 'Expiring';
        $data[] = [(string)$r['item_name'], (string)$r['category_name'], inventoryCountingUnit($r),
            inventoryQuantityText(max(0.0, (float)$r['usable_stock']), $r), inventoryQuantityText((float)$r['minimum_stock'], $r), $statusText,
            reportDate($r['nearest_expiration']) ?: '—', inventoryQuantityText((float)$r['used_in_period'], $r)];
    }
    return [
        'title' => 'Inventory Report', 'orientation' => 'L',
        'summary' => ['Inventory items (all)' => (string)$card['items'], 'Medical supplies' => (string)$card['medical_items'],
            'Low stock' => (string)$card['low_stock'], 'Out of stock' => (string)$card['out_of_stock'],
            'Expiring batches (30 days)' => (string)$card['expiring_batches'], 'Expired batches' => (string)$card['expired_batches']],
        'sections' => [[
            'title' => 'Current stock (as of ' . date('M d, Y') . ') and usage in the period',
            'columns' => [reportStdCol('Item', 2.6), reportStdCol('Category', 1.8), reportStdCol('Unit', 1), reportStdCol('Usable Stock', 1.8, 'R'),
                reportStdCol('Minimum Stock', 1.6, 'R'), reportStdCol('Status', 1.2), reportStdCol('Nearest Expiry', 1.3), reportStdCol('Used in Period', 1.8, 'R')],
            'rows' => $data,
        ]],
        'notes' => ['Stock is the current usable stock (expired batches excluded), the same as Inventory Overview; the period only filters "Used in Period".',
            'Low Stock: at or below the minimum and above zero. Out of Stock: no usable stock. Expiring: nearest batch expires within 30 days.'],
    ];
}

/* ---------------- Branch Admin: Vaccination Report ---------------- */
function reportStdVaccinations(mysqli $conn, array $p): array
{
    $branchId = (string)$p['branch_id'];
    $types = 's';
    $params = [$branchId];
    $period = reportStdPeriod($p, 'COALESCE(vr.date_administered, vr.scheduled_date)', $types, $params);
    $rows = reportStdFetch($conn, "SELECT vr.vaccination_id, vr.case_id, vr.dose_number, vr.scheduled_date, vr.date_administered,
            vr.vaccination_status, vr.is_final_dose, vr.is_archived, p.full_name, i.item_name, abc.case_number,
            (SELECT MAX(rr.registry_number) FROM registry_records rr WHERE rr.case_id = abc.case_id AND rr.is_archived = 0) AS registry_number
        FROM vaccination_records vr
        LEFT JOIN patients p ON p.patient_id = vr.patient_id
        LEFT JOIN inventory_items i ON i.item_id = vr.item_id
        LEFT JOIN animal_bite_cases abc ON abc.case_id = vr.case_id
        WHERE vr.branch_id = ? AND vr.is_archived = 0
          AND COALESCE(abc.is_archived, 0) = 0 AND COALESCE(p.is_archived, 0) = 0 $period
        ORDER BY COALESCE(vr.date_administered, vr.scheduled_date) DESC, vr.vaccination_id DESC", $types, $params);
    $counts = ['Completed' => 0, 'Scheduled' => 0, 'Missed' => 0];
    $given = 0;
    $final = 0;
    $data = [];
    foreach ($rows as $r) {
        $s = patientDoseStatus($r);
        $counts[$s] = ($counts[$s] ?? 0) + 1;
        if ($r['vaccination_status'] === 'Completed') $given++;
        if ((int)$r['is_final_dose'] === 1) $final++;
        $data[] = [patientDisplayCaseNumber($r['case_number'], $r['registry_number']), $r['full_name'] ?: 'Unknown', $r['item_name'] ?: '—',
            patientDoseDisplay($conn, (int)$r['case_id'], (int)$r['dose_number']), reportDate($r['scheduled_date']) ?: '—',
            reportDate($r['date_administered']) ?: '—', $s, (int)$r['is_final_dose'] === 1 ? 'Yes' : 'No'];
    }
    return [
        'title' => 'Vaccination Report', 'orientation' => 'L',
        'summary' => ['Dose records' => (string)count($rows), 'Vaccinations given' => (string)$given, 'Scheduled' => (string)$counts['Scheduled'],
            'Missed' => (string)$counts['Missed'], 'Final doses' => (string)$final],
        'sections' => [[
            'title' => 'Doses administered or scheduled in the period',
            'columns' => [reportStdCol('Case No.', 1.2), reportStdCol('Patient', 2.4), reportStdCol('Vaccine', 2), reportStdCol('Dose', 1.5),
                reportStdCol('Scheduled', 1.3), reportStdCol('Administered', 1.3), reportStdCol('Status', 1.1), reportStdCol('Final Dose', 0.9)],
            'rows' => $data,
        ]],
        'notes' => ['The period filters the administered date, or the scheduled date for doses not yet given.',
            'Vaccinations given counts Completed doses (same as the Branch Admin Dashboard). Archived and restarted cycles are not counted.'],
    ];
}

/* ---------------- Super Admin: User Report ---------------- */
function reportStdUsers(mysqli $conn, array $p, bool $branchAdminsOnly = false): array
{
    $types = '';
    $params = [];
    $where = "u.status != 'Deleted'" . ($branchAdminsOnly ? ' AND u.role_id = 2' : '');
    if (!empty($p['branch_id'])) { $where .= ' AND u.branch_id = ?'; $types .= 's'; $params[] = (string)$p['branch_id']; }
    $where .= reportStdPeriod($p, 'u.created_at', $types, $params);
    $rows = reportStdFetch($conn, "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at, r.role_name,
            b.branch_name, b.branch_address, b.contact_number
        FROM users u LEFT JOIN roles r ON r.role_id = u.role_id LEFT JOIN branches b ON b.branch_id = u.branch_id
        WHERE $where ORDER BY u.created_at DESC, u.user_id DESC", $types, $params);
    $active = count(array_filter($rows, static fn($r) => $r['status'] === 'Active'));
    $data = [];
    foreach ($rows as $r) {
        $data[] = $branchAdminsOnly
            ? [(string)$r['user_id'], (string)$r['username'], (string)$r['email'], $r['branch_name'] ?: '—', $r['branch_address'] ?: '—',
                $r['contact_number'] ?: '—', (string)$r['status'], reportDateTime($r['last_login']) ?: 'Never', reportDate($r['created_at'])]
            : [(string)$r['user_id'], (string)$r['username'], (string)$r['email'], $r['role_name'] ?: '—', $r['branch_name'] ?: '—',
                (string)$r['status'], reportDateTime($r['last_login']) ?: 'Never', reportDate($r['created_at'])];
    }
    $summary = [($branchAdminsOnly ? 'Branch Admins' : 'Users') => (string)count($rows), 'Active' => (string)$active, 'Inactive' => (string)(count($rows) - $active)];
    if ($branchAdminsOnly) {
        $summary['Branches'] = (string)count(array_unique(array_filter(array_column($rows, 'branch_name'))));
        $columns = [reportStdCol('ID', 0.7), reportStdCol('Username', 1.8), reportStdCol('Email', 2.6), reportStdCol('Branch', 1.6), reportStdCol('Address', 2.6),
            reportStdCol('Contact', 1.4), reportStdCol('Status', 1), reportStdCol('Last Login', 1.6), reportStdCol('Created', 1.3)];
    } else {
        foreach (array_count_values(array_map(static fn($r) => $r['role_name'] ?: 'No role', $rows)) as $role => $n) $summary[$role] = (string)$n;
        $columns = [reportStdCol('ID', 0.7), reportStdCol('Username', 2), reportStdCol('Email', 3), reportStdCol('Role', 1.6), reportStdCol('Branch', 1.8),
            reportStdCol('Status', 1), reportStdCol('Last Login', 1.7), reportStdCol('Created', 1.3)];
    }
    return [
        'title' => $branchAdminsOnly ? 'Branch Admin Report' : 'User Report', 'orientation' => 'L', 'summary' => $summary,
        'sections' => [['title' => 'Accounts created in the period', 'columns' => $columns, 'rows' => $data]],
        'notes' => ['Deleted accounts are not listed. The period filters the account creation date.'],
    ];
}

/* ---------------- Super Admin: Branch Performance Report ---------------- */
function reportStdBranchPerformance(mysqli $conn, array $p): array
{
    $dated = empty($p['all_dates']);
    $range = static fn(string $col): string => " AND DATE($col) BETWEEN ? AND ?";
    // Each count is its own subquery (no row multiplication); the
    // definitions are the Branch Admin Dashboard / Inventory Overview ones.
    $sql = "SELECT b.branch_id, b.branch_name,
          (SELECT COUNT(*) FROM animal_bite_cases abc
             INNER JOIN patients p ON p.patient_id = abc.patient_id AND p.branch_id = abc.branch_id AND p.is_archived = 0
             WHERE abc.branch_id = b.branch_id AND abc.is_archived = 0" . ($dated ? $range('abc.created_at') : '') . ") AS total_cases,
          (SELECT COUNT(*) FROM patients p WHERE p.branch_id = b.branch_id AND p.is_archived = 0" . ($dated ? $range('p.created_at') : '') . ") AS total_patients,
          (SELECT COUNT(*) FROM vaccination_records vr
             LEFT JOIN animal_bite_cases vc ON vc.case_id = vr.case_id
             LEFT JOIN patients vp ON vp.patient_id = vr.patient_id
             WHERE vr.branch_id = b.branch_id AND vr.is_archived = 0 AND vr.vaccination_status = 'Completed'
               AND COALESCE(vc.is_archived, 0) = 0 AND COALESCE(vp.is_archived, 0) = 0" . ($dated ? $range('vr.date_administered') : '') . ") AS total_vaccinations,
          (SELECT COUNT(DISTINCT st.item_id) FROM inventory_stocks st
             INNER JOIN inventory_items ii ON ii.item_id = st.item_id
             INNER JOIN inventory_categories ic ON ic.category_id = ii.category_id
             WHERE st.branch_id = b.branch_id) AS total_items,
          (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.branch_id AND u.status != 'Deleted') AS total_staff,
          (SELECT COUNT(*) FROM audit_logs al WHERE al.branch_id = b.branch_id" . ($dated ? $range('al.created_at') : '') . ") AS total_activities
        FROM branches b WHERE 1 = 1";
    $types = '';
    $params = [];
    if ($dated) {
        for ($i = 0; $i < 4; $i++) { $types .= 'ss'; $params[] = (string)$p['from']; $params[] = (string)$p['to']; }
    }
    if (!empty($p['branch_id'])) { $sql .= ' AND b.branch_id = ?'; $types .= 's'; $params[] = (string)$p['branch_id']; }
    $sql .= ' ORDER BY total_cases DESC, b.branch_name';
    $rows = reportStdFetch($conn, $sql, $types, $params);
    $keys = ['total_cases', 'total_patients', 'total_vaccinations', 'total_items', 'total_staff', 'total_activities'];
    $totals = array_fill_keys($keys, 0);
    $data = [];
    foreach ($rows as $r) {
        foreach ($keys as $k) $totals[$k] += (int)$r[$k];
        $data[] = array_merge([(string)$r['branch_name']], array_map(static fn($k) => (string)(int)$r[$k], $keys));
    }
    $notes = [$dated
        ? 'Cases, patients, vaccinations given and activities are those in the period; inventory items and staff are current.'
        : 'All dates: the totals equal the Branch Admin Dashboard and Inventory Overview cards of each branch.'];
    if (count($rows) > 1 && $totals['total_cases'] > 0) {
        $notes[] = 'Most cases: ' . $rows[0]['branch_name'] . ' (' . (int)$rows[0]['total_cases'] . '). Average cases per branch: '
            . round($totals['total_cases'] / count($rows), 1) . '.';
    }
    return [
        'title' => 'Branch Performance Report', 'orientation' => 'L',
        'summary' => ['Branches' => (string)count($rows), 'Cases' => (string)$totals['total_cases'], 'Patients' => (string)$totals['total_patients'],
            'Vaccinations given' => (string)$totals['total_vaccinations'], 'Inventory items' => (string)$totals['total_items']],
        'sections' => [[
            'title' => 'By branch',
            'columns' => [reportStdCol('Branch', 2.4), reportStdCol('Cases', 1, 'R'), reportStdCol('Patients', 1, 'R'), reportStdCol('Vaccinations Given', 1.4, 'R'),
                reportStdCol('Inventory Items', 1.2, 'R'), reportStdCol('Staff', 0.9, 'R'), reportStdCol('Activities', 1, 'R')],
            'rows' => $data,
            'totals' => array_merge(['Total'], array_map(static fn($k) => (string)$totals[$k], $keys)),
        ]],
        'notes' => $notes,
    ];
}

/* ---------------- Forecast Accuracy (latest saved evaluation) ----------------
 * Read-only copy of the Forecast Accuracy page's saved scores, so they can be
 * downloaded as PDF as well as CSV (ForecastAccuracy.php itself is unchanged).
 * Errors are in vials/ampules like the page's default view; review bands use
 * the page's provisional targets (SMAPE <= 10% High, <= 20% Moderate, at
 * least 7 test observations). Nothing is evaluated or saved here. */
const REPORT_ACCURACY_HIGH_MAX = 10.0;
const REPORT_ACCURACY_MODERATE_MAX = 20.0;
const REPORT_ACCURACY_MIN_TEST_RECORDS = 7;

function reportStdAccuracyBand(array $r): string
{
    if ($r['evaluation_status'] !== 'Available' || $r['actual_total'] === null || $r['smape_percent'] === null
        || (float)$r['smape_percent'] < 0 || (float)$r['smape_percent'] > 200) return 'Not rated';
    if ((int)$r['training_records'] < 1 || (int)$r['testing_records'] < REPORT_ACCURACY_MIN_TEST_RECORDS) return 'Limited test data';
    if ((float)$r['actual_total'] === 0.0) return 'No observed demand';
    $smape = (float)$r['smape_percent'];
    return $smape <= REPORT_ACCURACY_HIGH_MAX ? 'High' : ($smape <= REPORT_ACCURACY_MODERATE_MAX ? 'Moderate' : 'Low');
}

function reportStdForecastAccuracy(mysqli $conn, array $p): array
{
    require_once __DIR__ . '/forecast_accuracy_units.php';
    $branchId = (string)$p['branch_id'];
    $runs = reportStdFetch($conn, 'SELECT * FROM forecast_evaluation_runs WHERE branch_id = ? ORDER BY evaluation_id DESC LIMIT 1', 's', [$branchId]);
    $base = ['title' => 'Forecast Accuracy Report', 'orientation' => 'L'];
    if (!$runs) {
        return $base + ['summary' => ['Evaluation' => 'none saved'],
            'sections' => [['columns' => [reportStdCol('Item', 1)], 'rows' => [], 'empty' => 'No saved forecast evaluation for this branch. Open Forecast Accuracy to run one.']]];
    }
    $run = $runs[0];
    $map = forecastAccuracyUnitMap($conn);
    $rows = reportStdFetch($conn, 'SELECT * FROM forecast_evaluation_items WHERE evaluation_id = ?
        ORDER BY item_name, item_id, horizon_days, method, evaluation_item_id', 'i', [(int)$run['evaluation_id']]);
    $bands = ['High' => 0, 'Moderate' => 0, 'Low' => 0, 'Not rated' => 0];
    $num = static fn($v, int $d = 2): string => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, $d, '.', ','), '0'), '.');
    $data = [];
    foreach ($rows as $raw) {
        $band = reportStdAccuracyBand($raw);
        $bands[isset($bands[$band]) ? $band : 'Not rated']++;
        $r = forecastAccuracyConvertRow($raw, $map, 'display', false);
        $data[] = [(string)$r['item_name'], (string)$r['base_unit'], $r['method'] === 'one_step' ? 'One-step' : ($r['method'] === 'recursive' ? 'Recursive' : (string)$r['method']),
            (string)$r['horizon_days'], (string)$r['evaluation_status'], (string)$r['training_records'], (string)$r['testing_records'],
            $r['testing_start'] ? reportDate($r['testing_start']) . ' – ' . reportDate($r['testing_end'] ?? $r['testing_start']) : '—',
            $num($r['mae'], 4), $num($r['rmse'], 4), $r['smape_percent'] === null ? '—' : $num($r['smape_percent'], 2) . '%',
            $num($r['actual_total']), $num($r['forecast_total']), $band];
    }
    return $base + [
        'period_text' => 'Saved evaluation #' . (int)$run['evaluation_id'] . ' of ' . reportDateTime($run['generated_at']),
        'summary' => ['Evaluation' => '#' . (int)$run['evaluation_id'], 'Saved at' => reportDateTime($run['generated_at']),
            'Generated horizon' => $run['generated_horizon'] . ' days', 'Rows' => (string)count($rows)] + array_map('strval', $bands),
        'sections' => [[
            'columns' => [reportStdCol('Item', 2.2), reportStdCol('Unit', 0.9), reportStdCol('Method', 1.1), reportStdCol('Test Horizon (days)', 1, 'R'),
                reportStdCol('Status', 1.1), reportStdCol('Training', 0.9, 'R'), reportStdCol('Testing', 0.8, 'R'), reportStdCol('Test Window', 2.2),
                reportStdCol('MAE', 1, 'R'), reportStdCol('RMSE', 1, 'R'), reportStdCol('SMAPE', 0.9, 'R'), reportStdCol('Actual Total', 1, 'R'),
                reportStdCol('Forecast Total', 1, 'R'), reportStdCol('Review Band', 1.2)],
            'rows' => $data,
        ]],
        'notes' => ['Historical test of saved forecasts: error scores describe past performance and do not certify future forecasts.',
            'MAE, RMSE and totals are in vials/ampules (the page default); SMAPE does not depend on the unit.',
            'Review bands are provisional targets: SMAPE up to 10% High, up to 20% Moderate, above that Low; at least 7 test observations are required.'],
    ];
}

/* ---------------- Audit Logs (Branch Admin / Super Admin pages) ----------------
 * $f: branch_id (null = all branches), search, module, action (text), user_id,
 *     date_from, date_to: the filters of the Audit Logs page. All rows. */
function reportStdAuditLogs(mysqli $conn, array $f): array
{
    $where = ['1 = 1'];
    $types = '';
    $params = [];
    $add = static function (string $sql, string $t, array $values) use (&$where, &$types, &$params): void {
        $where[] = $sql; $types .= $t; array_push($params, ...$values);
    };
    if (!empty($f['branch_id'])) $add('al.branch_id = ?', 's', [(string)$f['branch_id']]);
    if (($f['search'] ?? '') !== '') {
        $like = '%' . $f['search'] . '%';
        $add('(al.action LIKE ? OR al.module LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR b.branch_name LIKE ?)', 'sssss', [$like, $like, $like, $like, $like]);
    }
    if (($f['module'] ?? '') !== '') $add('al.module = ?', 's', [(string)$f['module']]);
    if (($f['action'] ?? '') !== '') $add('al.action LIKE ?', 's', ['%' . $f['action'] . '%']);
    if (!empty($f['user_id'])) $add('al.user_id = ?', 'i', [(int)$f['user_id']]);
    if (reportCenterDateOk($f['date_from'] ?? '')) $add('al.created_at >= ?', 's', [$f['date_from'] . ' 00:00:00']);
    if (reportCenterDateOk($f['date_to'] ?? '')) $add('al.created_at < DATE_ADD(?, INTERVAL 1 DAY)', 's', [(string)$f['date_to']]);
    $rows = reportStdFetch($conn, "SELECT al.log_id, al.action, al.module, al.created_at, u.username, r.role_name, b.branch_name, al.branch_id
        FROM audit_logs al
        LEFT JOIN users u ON u.user_id = al.user_id
        LEFT JOIN roles r ON r.role_id = u.role_id
        LEFT JOIN branches b ON b.branch_id = al.branch_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY al.created_at DESC, al.log_id DESC", $types, $params);
    $allBranches = empty($f['branch_id']);
    $users = [];
    $modules = [];
    $data = [];
    $n = 0;
    foreach ($rows as $r) {
        $users[(string)$r['username']] = true;
        $m = (string)($r['module'] ?: 'N/A');
        $modules[$m] = ($modules[$m] ?? 0) + 1;
        $row = [(string)++$n, reportDateTime($r['created_at']), $r['username'] ?: 'System', $r['role_name'] ?: '—'];
        if ($allBranches) $row[] = $r['branch_name'] ?: ($r['branch_id'] ?: '—');
        array_push($row, $m, $r['action'] ?: '—');
        $data[] = $row;
    }
    arsort($modules);
    $columns = [reportStdCol('#', 0.6, 'R'), reportStdCol('Date / Time', 1.7), reportStdCol('User', 1.6), reportStdCol('Role', 1.3)];
    if ($allBranches) $columns[] = reportStdCol('Branch', 1.4);
    array_push($columns, reportStdCol('Module', 1.4), reportStdCol('Action', $allBranches ? 6 : 7));
    $filters = [];
    foreach (['search' => 'Search', 'module' => 'Module', 'action' => 'Action', 'user_name' => 'User'] as $k => $label) {
        if (($f[$k] ?? '') !== '' && ($f[$k] ?? 0) !== 0) $filters[] = $label . ': ' . $f[$k];
    }
    return [
        'title' => 'Audit Log Report', 'orientation' => 'L',
        'filters_text' => implode(' · ', $filters),
        'summary' => ['Log entries' => (string)count($rows), 'Users' => (string)count($users), 'Modules' => (string)count($modules),
            'Most active modules' => implode(', ', array_map(static fn($k, $v) => "$k ($v)", array_slice(array_keys($modules), 0, 3), array_slice($modules, 0, 3))) ?: '—'],
        'sections' => [['columns' => $columns, 'rows' => $data, 'empty' => 'No audit log entries match the filters.']],
    ];
}

/** Send the audit log report of a page's filters (PDF, or inline for Print) and stop. */
function reportStdAuditLogsSend(mysqli $conn, int $userId, string $auditBranch, array $f, string $format): void
{
    $report = reportStdAuditLogs($conn, $f);
    $from = reportCenterDateOk($f['date_from'] ?? '') ? (string)$f['date_from'] : null;
    $to = reportCenterDateOk($f['date_to'] ?? '') ? (string)$f['date_to'] : null;
    $meta = reportMeta($conn, $userId, reportBranchName($conn, empty($f['branch_id']) ? null : (string)$f['branch_id']),
        reportPeriodText($from, $to), (string)$report['filters_text']);
    workflowAudit($conn, $userId, $auditBranch, reportExportVerb() . ' audit logs (' . strtoupper($format) . ') - Branch: ' . $meta['branch']
        . ' - Period: ' . $meta['period'] . ' (' . count($report['sections'][0]['rows']) . ' entries)', 'Audit Logs');
    reportSend($report, $meta, $format, 'Audit_Logs_' . ($f['branch_id'] ?: 'All_branches') . '_' . date('Ymd_His'));
}
