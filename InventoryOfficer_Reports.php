<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    (int)$_SESSION['role_id'] !== 5
) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = null;
$branch_name = 'No Branch Assigned';
$notification_count = getUnreadNotificationCount($conn, $user_id);
$username = 'Inventory Officer';

/* -------------------------------------------------------------
 * Helpers
 * ----------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function moneylessNumber($value): string
{
    return number_format((float)$value, 0);
}

function reportLabel(string $type): string
{
    $labels = [
        'low_stock'       => 'Low Stock Report',
        'expiring_stock'  => 'Expiring Stock Report',
        'stock_usage'     => 'Stock Usage Report',
        'transactions'    => 'Stock Transaction Summary',
        'returns'         => 'Return Report',
        'shortage'        => 'Shortage Forecast Report'
    ];
    return $labels[$type] ?? $labels['low_stock'];
}

function csvCell($value): string
{
    $value = (string)$value;
    return '"' . str_replace('"', '""', $value) . '"';
}

/* -------------------------------------------------------------
 * Authenticated user + branch
 * ----------------------------------------------------------- */
$userQuery = "
    SELECT u.branch_id, u.username, b.branch_name
    FROM users u
    LEFT JOIN branches b ON u.branch_id = b.branch_id
    WHERE u.user_id = ?
      AND u.status = 'Active'
    LIMIT 1
";

$userStmt = $conn->prepare($userQuery);

if (!$userStmt) {
    http_response_code(500);
    die('Database error: Unable to prepare user query.');
}

$userStmt->bind_param('i', $user_id);

if (!$userStmt->execute()) {
    $userStmt->close();
    http_response_code(500);
    die('Database error: Unable to retrieve user information.');
}

$userResult = $userStmt->get_result();
$userStmt->close();

if ($userResult->num_rows === 0) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}

$userData = $userResult->fetch_assoc();
$branch_id = $userData['branch_id'];
$username = $userData['username'] ?: 'Inventory Officer';
$branch_name = $userData['branch_name'] ?: 'No Branch Assigned';

if ($branch_id === null || $branch_id === '') {
    http_response_code(403);
    die('Your account is not assigned to a branch.');
}

/* -------------------------------------------------------------
 * Filters
 * ----------------------------------------------------------- */
$report_type = $_POST['report_type'] ?? $_GET['report_type'] ?? 'low_stock';
$allowedReports = ['low_stock', 'expiring_stock', 'stock_usage', 'transactions', 'returns', 'shortage'];

if (!in_array($report_type, $allowedReports, true)) {
    $report_type = 'low_stock';
}

$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

$date_from = trim($_POST['date_from'] ?? $_GET['date_from'] ?? $firstDayOfMonth);
$date_to   = trim($_POST['date_to'] ?? $_GET['date_to'] ?? $today);
$search    = trim($_POST['search'] ?? $_GET['search'] ?? '');

$errors = [];

if (!validDate($date_from)) {
    $date_from = $firstDayOfMonth;
    $errors[] = 'Invalid start date. The current month start was used instead.';
}

if (!validDate($date_to)) {
    $date_to = $today;
    $errors[] = 'Invalid end date. Today was used instead.';
}

if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
    $errors[] = 'The date range was reversed, so the dates were automatically corrected.';
}

/*
 * Expiration monitoring uses the selected date range against expiration_date.
 * Transaction/usage/forecast reports use their respective event dates.
 * Low-stock is a current-stock snapshot, so its stock quantity is not
 * artificially filtered by transaction date.
 */

/* -------------------------------------------------------------
 * Report query functions
 * ----------------------------------------------------------- */
function fetchReportRows(
    mysqli $conn,
    string $reportType,
    string $branchId,
    string $dateFrom,
    string $dateTo,
    string $search
): array {
    $rows = [];

    switch ($reportType) {

        case 'low_stock':
            $sql = "
                SELECT
                    i.item_id,
                    i.item_name,
                    c.category_name,
                    u.unit_name,
                    i.minimum_stock,
                    COALESCE(SUM(s.quantity_available), 0) AS current_stock,
                    COALESCE(SUM(
                        CASE
                            WHEN s.quantity_available > 0 THEN s.quantity_available
                            ELSE 0
                        END
                    ), 0) AS positive_stock
                FROM inventory_items i
                INNER JOIN inventory_categories c
                    ON c.category_id = i.category_id
                INNER JOIN units u
                    ON u.unit_id = i.unit_id
                LEFT JOIN inventory_stocks s
                    ON s.item_id = i.item_id
                   AND s.branch_id = ?
                WHERE (
                    ? = ''
                    OR i.item_name LIKE CONCAT('%', ?, '%')
                    OR c.category_name LIKE CONCAT('%', ?, '%')
                )
                GROUP BY
                    i.item_id,
                    i.item_name,
                    c.category_name,
                    u.unit_name,
                    i.minimum_stock
                HAVING current_stock <= i.minimum_stock
                ORDER BY current_stock ASC, i.item_name ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare Low Stock Report query.');
            }

            $stmt->bind_param(
                'ssss',
                $branchId,
                $search,
                $search,
                $search
            );
            break;

        case 'expiring_stock':
            $sql = "
                SELECT
                    s.stock_id,
                    i.item_name,
                    c.category_name,
                    u.unit_name,
                    s.batch_lot_no,
                    s.manufacturing_date,
                    s.expiration_date,
                    s.quantity_available,
                    DATEDIFF(s.expiration_date, ?) AS days_remaining,
                    CASE
                        WHEN s.expiration_date < ? THEN 'Expired'
                        WHEN s.expiration_date <= DATE_ADD(?, INTERVAL 30 DAY) THEN 'Expiring Soon'
                        ELSE 'Valid'
                    END AS expiry_status
                FROM inventory_stocks s
                INNER JOIN inventory_items i
                    ON i.item_id = s.item_id
                INNER JOIN inventory_categories c
                    ON c.category_id = i.category_id
                INNER JOIN units u
                    ON u.unit_id = i.unit_id
                WHERE s.branch_id = ?
                  AND s.expiration_date IS NOT NULL
                  AND s.expiration_date BETWEEN ? AND ?
                  AND (
                      ? = ''
                      OR i.item_name LIKE CONCAT('%', ?, '%')
                      OR c.category_name LIKE CONCAT('%', ?, '%')
                      OR COALESCE(s.batch_lot_no, '') LIKE CONCAT('%', ?, '%')
                  )
                ORDER BY s.expiration_date ASC, i.item_name ASC, s.batch_lot_no ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare Expiring Stock Report query.');
            }

            $stmt->bind_param(
                'ssssssssss',
                $dateFrom,
                $dateFrom,
                $dateFrom,
                $branchId,
                $dateFrom,
                $dateTo,
                $search,
                $search,
                $search,
                $search
            );
            break;

        case 'stock_usage':
            $sql = "
                SELECT
                    h.usage_id,
                    h.usage_date,
                    i.item_name,
                    c.category_name,
                    u.unit_name,
                    h.quantity_used,
                    h.patient_count,
                    h.stock_received
                FROM inventory_usage_history h
                INNER JOIN inventory_items i
                    ON i.item_id = h.item_id
                INNER JOIN inventory_categories c
                    ON c.category_id = i.category_id
                INNER JOIN units u
                    ON u.unit_id = i.unit_id
                WHERE h.branch_id = ?
                  AND h.usage_date BETWEEN ? AND ?
                  AND (
                      ? = ''
                      OR i.item_name LIKE CONCAT('%', ?, '%')
                      OR c.category_name LIKE CONCAT('%', ?, '%')
                  )
                ORDER BY h.usage_date DESC, i.item_name ASC, h.usage_id DESC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare Stock Usage Report query.');
            }

            $stmt->bind_param(
                'ssssss',
                $branchId,
                $dateFrom,
                $dateTo,
                $search,
                $search,
                $search
            );
            break;

        case 'transactions':
            $sql = "
                SELECT
                    st.transaction_id,
                    st.transaction_type,
                    i.item_name,
                    c.category_name,
                    u.unit_name,
                    st.quantity,
                    st.transaction_date,
                    usr.username,
                    st.remarks
                FROM stock_transactions st
                INNER JOIN inventory_items i
                    ON i.item_id = st.item_id
                INNER JOIN inventory_categories c
                    ON c.category_id = i.category_id
                INNER JOIN units u
                    ON u.unit_id = i.unit_id
                INNER JOIN users usr
                    ON usr.user_id = st.user_id
                WHERE st.branch_id = ?
                  AND DATE(st.transaction_date) BETWEEN ? AND ?
                  AND (
                      ? = ''
                      OR i.item_name LIKE CONCAT('%', ?, '%')
                      OR c.category_name LIKE CONCAT('%', ?, '%')
                      OR st.transaction_type LIKE CONCAT('%', ?, '%')
                      OR usr.username LIKE CONCAT('%', ?, '%')
                      OR COALESCE(st.remarks, '') LIKE CONCAT('%', ?, '%')
                  )
                ORDER BY st.transaction_date DESC, st.transaction_id DESC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare Stock Transaction Summary query.');
            }

            $stmt->bind_param(
                'sssssssss',
                $branchId,
                $dateFrom,
                $dateTo,
                $search,
                $search,
                $search,
                $search,
                $search,
                $search
            );
            break;

        case 'returns':
            $sql = "
                SELECT
                    ir.return_id,
                    ir.return_number,
                    ir.branch_id,
                    ir.destination_branch_id,
                    CASE
                        WHEN ir.branch_id = ? THEN 'Sent'
                        ELSE 'Received'
                    END AS direction,
                    sb.branch_name AS source_branch,
                    db.branch_name AS destination_branch,
                    ir.item_name,
                    un.unit_name,
                    ir.quantity,
                    ir.return_reason,
                    ir.status,
                    ir.transportation_details,
                    ir.remarks,
                    ir.created_at,
                    ir.sent_at,
                    ir.received_at,
                    creator.username AS created_by_name,
                    processor.username AS processed_by_name
                FROM inventory_returns ir
                LEFT JOIN units un
                    ON un.unit_id = ir.unit_id
                LEFT JOIN branches sb
                    ON sb.branch_id = ir.branch_id
                LEFT JOIN branches db
                    ON db.branch_id = ir.destination_branch_id
                LEFT JOIN users creator
                    ON creator.user_id = ir.created_by
                LEFT JOIN users processor
                    ON processor.user_id = ir.processed_by
                WHERE (ir.branch_id = ? OR ir.destination_branch_id = ?)
                  AND DATE(ir.created_at) BETWEEN ? AND ?
                  AND (
                      ? = ''
                      OR CONCAT_WS(
                          ' ',
                          COALESCE(ir.return_number, ''),
                          COALESCE(ir.item_name, ''),
                          COALESCE(ir.return_reason, ''),
                          COALESCE(ir.status, ''),
                          COALESCE(sb.branch_name, ''),
                          COALESCE(db.branch_name, ''),
                          COALESCE(ir.remarks, ''),
                          COALESCE(ir.transportation_details, '')
                      ) LIKE CONCAT('%', ?, '%')
                  )
                ORDER BY ir.created_at DESC, ir.return_id DESC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare Return Report query.');
            }

            $stmt->bind_param(
                'sssssss',
                $branchId,
                $branchId,
                $branchId,
                $dateFrom,
                $dateTo,
                $search,
                $search
            );
            break;

        case 'shortage':
            $sql = "
                SELECT
                    p.forecast_id,
                    p.forecast_date,
                    i.item_name,
                    c.category_name,
                    u.unit_name,
                    p.shortage_probability,
                    p.forecast_status,
                    p.recommended_reorder,
                    p.forecasted_consumption,
                    p.forecast_days,
                    usr.username AS generated_by_name
                FROM forecast_results p
                INNER JOIN inventory_items i
                    ON i.item_id = p.item_id
                INNER JOIN inventory_categories c
                    ON c.category_id = i.category_id
                INNER JOIN units u
                    ON u.unit_id = i.unit_id
                LEFT JOIN users usr
                    ON usr.user_id = p.generated_by
                WHERE p.branch_id = ?
                  AND p.forecast_date BETWEEN ? AND ?
                  AND (
                      ? = ''
                      OR i.item_name LIKE CONCAT('%', ?, '%')
                      OR c.category_name LIKE CONCAT('%', ?, '%')
                      OR p.forecast_status LIKE CONCAT('%', ?, '%')
                  )
                ORDER BY p.forecast_date DESC, p.shortage_probability DESC, i.item_name ASC
            ";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare Shortage Forecast Report query.');
            }

            $stmt->bind_param(
                'sssssss',
                $branchId,
                $dateFrom,
                $dateTo,
                $search,
                $search,
                $search,
                $search
            );
            break;

        default:
            throw new RuntimeException('Unsupported report type.');
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Report query failed: ' . $error);
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

/* -------------------------------------------------------------
 * Load report rows
 * ----------------------------------------------------------- */
$reportRows = [];
$reportError = '';

try {
    $reportRows = fetchReportRows(
        $conn,
        $report_type,
        (string)$branch_id,
        $date_from,
        $date_to,
        $search
    );
} catch (Throwable $e) {
    $reportError = $e->getMessage();
}

/* -------------------------------------------------------------
 * Dashboard/chart data is also branch-scoped and based on real data.
 * Top Supply Usage: usage history in the selected date range.
 * Category Share: usage history grouped by category.
 * ----------------------------------------------------------- */
$barData = [];
$pieData = [];

try {
    $usageChartSql = "
        SELECT
            i.item_name AS label,
            COALESCE(SUM(h.quantity_used), 0) AS value
        FROM inventory_usage_history h
        INNER JOIN inventory_items i
            ON i.item_id = h.item_id
        WHERE h.branch_id = ?
          AND h.usage_date BETWEEN ? AND ?
        GROUP BY i.item_id, i.item_name
        HAVING value > 0
        ORDER BY value DESC, i.item_name ASC
        LIMIT 6
    ";

    $chartStmt = $conn->prepare($usageChartSql);

    if ($chartStmt) {
        $chartStmt->bind_param('sss', $branch_id, $date_from, $date_to);
        if ($chartStmt->execute()) {
            $chartResult = $chartStmt->get_result();
            while ($row = $chartResult->fetch_assoc()) {
                $barData[] = [
                    'label' => $row['label'],
                    'value' => (int)$row['value']
                ];
            }
        }
        $chartStmt->close();
    }

    $pieSql = "
        SELECT
            c.category_name AS label,
            COALESCE(SUM(h.quantity_used), 0) AS value
        FROM inventory_usage_history h
        INNER JOIN inventory_items i
            ON i.item_id = h.item_id
        INNER JOIN inventory_categories c
            ON c.category_id = i.category_id
        WHERE h.branch_id = ?
          AND h.usage_date BETWEEN ? AND ?
        GROUP BY c.category_id, c.category_name
        HAVING value > 0
        ORDER BY value DESC, c.category_name ASC
    ";

    $pieStmt = $conn->prepare($pieSql);

    if ($pieStmt) {
        $pieStmt->bind_param('sss', $branch_id, $date_from, $date_to);
        if ($pieStmt->execute()) {
            $pieResult = $pieStmt->get_result();
            while ($row = $pieResult->fetch_assoc()) {
                $pieData[] = [
                    'label' => $row['label'],
                    'value' => (int)$row['value']
                ];
            }
        }
        $pieStmt->close();
    }
} catch (Throwable $e) {
    // Chart failure should not prevent the detailed report from displaying.
}

/* Convert pie quantities into percentages. */
$pieTotal = array_sum(array_column($pieData, 'value'));

if ($pieTotal > 0) {
    foreach ($pieData as &$slice) {
        $slice['percent'] = round(($slice['value'] / $pieTotal) * 100, 1);
    }
    unset($slice);
}

/* -------------------------------------------------------------
 * CSV export
 * ----------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    /*
     * Re-fetch using the exact same validated filters. The branch is still
     * taken only from the authenticated user, never from GET.
     */
    try {
        $exportRows = fetchReportRows(
            $conn,
            $report_type,
            (string)$branch_id,
            $date_from,
            $date_to,
            $search
        );
    } catch (Throwable $e) {
        http_response_code(500);
        die('Unable to export report: ' . h($e->getMessage()));
    }

    $filename = 'SmartBiteCare_' . preg_replace('/[^A-Za-z0-9_-]/', '_', reportLabel($report_type))
              . '_' . $date_from . '_to_' . $date_to . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    /* UTF-8 BOM for Excel compatibility. */
    fwrite($output, "\xEF\xBB\xBF");

    if ($report_type === 'low_stock') {
        fputcsv($output, [
            'Item', 'Category', 'Unit', 'Minimum Stock', 'Current Stock', 'Status'
        ]);

        foreach ($exportRows as $row) {
            $status = ((int)$row['current_stock'] <= 0)
                ? 'Critical'
                : 'Low';

            fputcsv($output, [
                $row['item_name'],
                $row['category_name'],
                $row['unit_name'],
                $row['minimum_stock'],
                $row['current_stock'],
                $status
            ]);
        }

    } elseif ($report_type === 'expiring_stock') {
        fputcsv($output, [
            'Item', 'Category', 'Unit', 'Batch/Lot No.', 'Manufacturing Date',
            'Expiration Date', 'Quantity', 'Days Remaining', 'Status'
        ]);

        foreach ($exportRows as $row) {
            fputcsv($output, [
                $row['item_name'],
                $row['category_name'],
                $row['unit_name'],
                $row['batch_lot_no'],
                $row['manufacturing_date'],
                $row['expiration_date'],
                $row['quantity_available'],
                $row['days_remaining'],
                $row['expiry_status']
            ]);
        }

    } elseif ($report_type === 'stock_usage') {
        fputcsv($output, [
            'Usage ID', 'Usage Date', 'Item', 'Category', 'Unit',
            'Quantity Used', 'Patient Count', 'Stock Received'
        ]);

        foreach ($exportRows as $row) {
            fputcsv($output, [
                $row['usage_id'],
                $row['usage_date'],
                $row['item_name'],
                $row['category_name'],
                $row['unit_name'],
                $row['quantity_used'],
                $row['patient_count'],
                $row['stock_received']
            ]);
        }

    } elseif ($report_type === 'transactions') {
        fputcsv($output, [
            'Transaction No.', 'Type', 'Item', 'Category', 'Unit',
            'Quantity', 'Transaction Date', 'By', 'Remarks'
        ]);

        foreach ($exportRows as $row) {
            fputcsv($output, [
                'TRX-' . str_pad($row['transaction_id'], 4, '0', STR_PAD_LEFT),
                $row['transaction_type'],
                $row['item_name'],
                $row['category_name'],
                $row['unit_name'],
                $row['quantity'],
                $row['transaction_date'],
                $row['username'],
                $row['remarks']
            ]);
        }

    } elseif ($report_type === 'returns') {
        fputcsv($output, [
            'Return No.', 'Direction', 'Source Branch', 'Destination Branch',
            'Item', 'Unit', 'Quantity', 'Reason', 'Status',
            'Created At', 'Sent At', 'Received At',
            'Created By', 'Received By', 'Transportation Details', 'Remarks'
        ]);

        foreach ($exportRows as $row) {
            fputcsv($output, [
                $row['return_number'] ?: ('RET-' . $row['return_id']),
                $row['direction'],
                $row['source_branch'] ?: $row['branch_id'],
                $row['destination_branch'] ?: $row['destination_branch_id'],
                $row['item_name'],
                $row['unit_name'],
                $row['quantity'],
                $row['return_reason'],
                $row['status'],
                $row['created_at'],
                $row['sent_at'],
                $row['received_at'],
                $row['created_by_name'],
                $row['processed_by_name'],
                $row['transportation_details'],
                $row['remarks']
            ]);
        }

    } elseif ($report_type === 'shortage') {
        fputcsv($output, [
            'Forecast ID', 'Forecast Date', 'Item', 'Category', 'Unit',
            'Probability Score', 'Status', 'Recommended Reorder',
            'Forecasted Consumption', 'Forecast Days', 'Generated By'
        ]);

        foreach ($exportRows as $row) {
            fputcsv($output, [
                $row['forecast_id'],
                $row['forecast_date'],
                $row['item_name'],
                $row['category_name'],
                $row['unit_name'],
                $row['shortage_probability'],
                $row['forecast_status'],
                $row['recommended_reorder'],
                $row['forecasted_consumption'],
                $row['forecast_days'],
                $row['generated_by_name']
            ]);
        }
    }

    fclose($output);
    exit();
}

/* -------------------------------------------------------------
 * Summary calculations for the selected report
 * ----------------------------------------------------------- */
$summary = [
    'rows' => count($reportRows),
    'quantity' => 0,
    'patients' => 0,
    'transactions' => 0,
    'returns_received' => 0,
    'returns_in_transit' => 0
];

foreach ($reportRows as $row) {
    if ($report_type === 'stock_usage') {
        $summary['quantity'] += (int)$row['quantity_used'];
        $summary['patients'] += (int)$row['patient_count'];
    } elseif ($report_type === 'transactions') {
        $summary['quantity'] += (int)$row['quantity'];
        $summary['transactions']++;
    } elseif ($report_type === 'low_stock') {
        $summary['quantity'] += (int)$row['current_stock'];
    } elseif ($report_type === 'expiring_stock') {
        $summary['quantity'] += (int)$row['quantity_available'];
    } elseif ($report_type === 'returns') {
        $summary['quantity'] += (float)$row['quantity'];

        if (($row['status'] ?? '') === 'Received') {
            $summary['returns_received']++;
        } else {
            $summary['returns_in_transit']++;
        }
    } elseif ($report_type === 'shortage') {
        $summary['quantity'] += (int)$row['recommended_reorder'];
    }
}

/* Pie CSS gradient. */
$gradientParts = [];
$cursor = 0;

foreach ($pieData as $slice) {
    $start = $cursor;
    $cursor += (float)$slice['percent'];
    $gradientParts[] = 'hsl(' . (($start * 3.1) % 360) . ' 55% 45%) ' . $start . '% ' . $cursor . '%';
}

$conicGradient = $gradientParts
    ? implode(', ', $gradientParts)
    : 'hsl(220 10% 90%) 0% 100%';

$maxBarValue = 1;
foreach ($barData as $bar) {
    $maxBarValue = max($maxBarValue, (int)$bar['value']);
}

function statusClass(string $status): string
{
    $statusLower = strtolower($status);

    if (str_contains($statusLower, 'critical') || str_contains($statusLower, 'expired')) {
        return 'status-critical';
    }

    if (
        str_contains($statusLower, 'low') ||
        str_contains($statusLower, 'expiring') ||
        str_contains($statusLower, 'shortage') ||
        str_contains($statusLower, 'risk')
    ) {
        return 'status-warning';
    }

    if (
        str_contains($statusLower, 'completed') ||
        str_contains($statusLower, 'valid') ||
        str_contains($statusLower, 'received')
    ) {
        return 'status-good';
    }

    if (str_contains($statusLower, 'in transit')) {
        return 'status-warning';
    }

    return 'status-neutral';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Inventory Reports</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="sidebar.css">

<style>

:root{
--primary:#2B3A8C;
--accent:#F21D2F;
--bg:#F2F2F2;
}

body{
background: #f0f2f5;
font-family:'Segoe UI',sans-serif;
}

.main{
margin-left:260px;
min-height:100vh;
}

.topbar{
    background:white;
    height:80px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 35px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    border-bottom:1px solid #e9edf5;
}

.topbar h3{
    font-size:28px;
    font-weight:700;
    color:var(--primary);
    margin:0;
}

.topbar h3 small{
    font-size:15px;
    font-weight:400;
    color:#6c757d;
    margin-left:10px;
}

.profile{
    display:flex;
    align-items:center;
    gap:6px;
    font-weight:600;
    color:var(--primary);
}

.role-label{
    font-size:12px;
    color:#adb5bd;
    font-weight:400;
    margin-left:4px;
}


.page-body{
padding:35px;
}

.filter-card{
display:flex;
align-items:flex-end;
gap:24px;
flex-wrap:wrap;
margin-bottom:26px;
}

.filter-group{
display:flex;
flex-direction:column;
gap:6px;
}

.filter-group label{
font-weight:600;
color:var(--primary);
font-size:14px;
}

.filter-group select,
.filter-group input{
border-radius:10px;
border:1px solid #dcdee8;
padding:10px 14px;
font-size:14px;
min-width:220px;
}

.filter-group select:focus,
.filter-group input:focus{
outline:none;
border-color:var(--primary);
}

.btn-custom{
background:var(--primary);
color:white;
border-radius:8px;
padding:10px 22px;
border:none;
font-weight:600;
font-size:14px;
height:44px;
}

.btn-custom:hover{
background:#1d2863;
color:white;
}

.large-card{
background:white;
border-radius:18px;
padding:24px;
box-shadow:0 3px 8px rgba(0,0,0,.08);
height:100%;
}

.section-title{
font-size:16px;
font-weight:700;
color:var(--primary);
margin-bottom:20px;
}

/* CSS bar chart */
.bar-chart{
display:flex;
align-items:flex-end;
gap:14px;
height:220px;
background:white;
border-radius:12px;
padding:20px 20px 10px;
border:1px solid #dfe1ee;
}

.bar-col{
flex:1;
display:flex;
flex-direction:column;
align-items:center;
justify-content:flex-end;
height:100%;
}

.bar{
width:100%;
max-width:34px;
background:var(--primary);
border-radius:6px 6px 0 0;
opacity:.85;
}

.bar-label{
margin-top:8px;
font-size:11px;
color:#666;
text-align:center;
line-height:1.2;
}

/* CSS pie chart */
.pie-wrap{
display:flex;
align-items:center;
gap:26px;
background:white;
border-radius:12px;
padding:24px;
border:1px solid #dfe1ee;
flex-wrap:wrap;
justify-content:center;
}

.pie{
width:160px;
height:160px;
border-radius:50%;
flex-shrink:0;
}

.pie-legend{
display:flex;
flex-direction:column;
gap:10px;
}

.legend-row{
display:flex;
align-items:center;
gap:10px;
font-size:14px;
color:#333;
}

.legend-dot{
width:12px;
height:12px;
border-radius:3px;
flex-shrink:0;
}

.legend-value{
margin-left:auto;
font-weight:700;
color:var(--primary);
}

/* =========================================================
   GLOBAL LOGOUT CONFIRMATION MODAL 
   ========================================================= */

.confirm-modal .modal-dialog {
    max-width: 500px !important;
    width: calc(100% - 30px);
    margin: 1.75rem auto;
}

.confirm-modal .modal-content {
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 20px !important;
    background: #fff !important;
    box-shadow: 0 20px 55px rgba(31, 45, 110, 0.20) !important;
}

.confirm-modal .modal-header {
    display: block !important;
    padding: 26px 24px 6px !important;
    border: 0 !important;
    background: #fff !important;
    text-align: center !important;
}

.confirm-modal .modal-icon {
    width: 64px !important;
    height: 64px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 14px !important;

    color: #fff !important;
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    border-radius: 50% !important;

    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
    font-size: 27px !important;
}

.confirm-modal .modal-icon.logout-icon {
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
}

.confirm-modal .modal-title {
    margin: 0 !important;
    color: #283a7a !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    line-height: 1.3 !important;
}

.confirm-modal .modal-body {
    padding: 6px 30px 22px !important;
    background: #fff !important;
    color: #7a879e !important;
    text-align: center !important;
}

.confirm-modal .modal-body p {
    margin: 0 !important;
    color: #7a879e !important;
    font-size: 17px !important;
    line-height: 1.45 !important;
}

.confirm-modal .modal-footer {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    padding: 0 24px 26px !important;
    border: 0 !important;
    background: #fff !important;
}

.confirm-modal .modal-footer .btn {
    min-height: 50px !important;
    margin: 0 !important;
    border-radius: 10px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.confirm-modal .btn-light,
.confirm-modal .btn-cancel {
    color: #111 !important;
    background: #f8f9fa !important;
    border: 1px solid #d9dfe8 !important;
}

.confirm-modal .btn-light:hover,
.confirm-modal .btn-cancel:hover {
    background: #eef0f3 !important;
}

.confirm-modal .btn-danger,
.confirm-modal .btn-logout {
    color: #fff !important;
    background: #e83445 !important;
    border: 1px solid #e83445 !important;
    text-decoration: none !important;
}

.confirm-modal .btn-danger:hover,
.confirm-modal .btn-logout:hover {
    color: #fff !important;
    background: #d92839 !important;
    border-color: #d92839 !important;
}

.summary-empty{
background:white;
border:1px dashed #c7cbe6;
border-radius:12px;
padding:40px 24px;
text-align:center;
color:#8a8fb0;
}

.summary-empty i{
font-size:30px;
color:var(--primary);
opacity:.5;
margin-bottom:10px;
display:block;
}


.filter-card {
    align-items: flex-end;
}

.filter-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-actions .btn-custom,
.filter-actions .btn-outline-custom {
    width: auto;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.btn-outline-custom {
    background: white;
    color: var(--primary);
    border: 1px solid var(--primary);
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
    font-size: 14px;
}

.btn-outline-custom:hover {
    background: var(--primary);
    color: white;
}

.report-meta {
    background: white;
    border: 1px solid #dfe1ee;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 18px;
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    color: #666;
    font-size: 13px;
}

.report-meta strong {
    color: var(--primary);
    font-size: 15px;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.summary-card {
    background: white;
    border: 1px solid #dfe1ee;
    border-radius: 14px;
    padding: 16px 18px;
}

.summary-card span {
    display: block;
    color: #777;
    font-size: 12px;
    margin-bottom: 4px;
}

.summary-card strong {
    color: var(--primary);
    font-size: 22px;
}

.report-status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.status-critical {
    background: #FFEAEA;
    color: #D7192D;
}

.status-warning {
    background: #FFF1D6;
    color: #9A5B00;
}

.status-good {
    background: #E6F4EA;
    color: #1E7B34;
}

.status-neutral {
    background: #EDEFFA;
    color: var(--primary);
}

.bar-value {
    font-size: 11px;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 4px;
}

.chart-empty {
    width: 100%;
    border: 0;
    padding: 20px;
}

.report-table-wrap {
    overflow-x: auto;
}

.report-table-wrap .data-table {
    min-width: 850px;
}

.report-table-wrap .data-table.return-report-table {
    min-width: 1900px;
}

.alert {
    margin-bottom: 18px;
}

@media print {
    .sidebar,
    .topbar,
    .filter-card,
    .btn-outline-custom,
    .btn-custom,
    .alert,
    .summary-cards,
    .row.g-4 > .col-lg-6 {
        display: none !important;
    }

    .main {
        margin-left: 0 !important;
    }

    .page-body {
        padding: 0 !important;
    }

    .report-meta {
        border: 0;
        padding: 0 0 12px 0;
    }

    .large-card {
        box-shadow: none;
        padding: 0;
    }

    .report-table-wrap {
        border: 0;
    }
}

@media(max-width:991px){
    .summary-cards {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        width: 100%;
    }
}
@media(max-width:991px){
.main{
margin-left:90px;
}
}


        /* =========================================================
           CLIENT-SIDE TABLE PAGINATION
           ========================================================= */
        .table-pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 14px 20px;
            background: #fff;
            border-top: 1px solid #edf0f5;
        }

        .table-pagination-summary {
            color: #6f7b91;
            font-size: 12px;
        }

        .table-pagination-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rows-per-page-control {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #6f7b91;
            font-size: 12px;
            white-space: nowrap;
        }

        .rows-per-page-control select {
            width: 78px;
            min-height: 34px;
            padding: 4px 28px 4px 10px;
            border: 1px solid #dde3ee;
            border-radius: 8px;
            background-color: #fff;
            color: #34405d;
            font-size: 12px;
        }

        .table-page-buttons {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .table-page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dde3ee;
            border-radius: 8px;
            background: #fff;
            color: #2B3A8C;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s ease;
        }

        .table-page-btn:hover:not(:disabled),
        .table-page-btn.active {
            color: #fff;
            background: #2B3A8C;
            border-color: #2B3A8C;
        }

        .table-page-btn:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        @media (max-width: 576px) {
            .table-pagination-bar {
                align-items: flex-start;
                flex-direction: column;
            }
        }

</style>

</head>


<body>

<div class="sidebar">

<div class="logo-area">
    <div class="logo-frame">
        <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
    </div>
    <div class="system-name">
        Smart Bite Care
    </div>
</div>

<nav class="nav-menu">
<ul>
<li><a href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
<li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
<li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a href="InventoryOfficer_ReturnManagement.php"><i class="bi bi-arrow-return-left"></i><span>Return Management</span></a></li>
<li><a class="active" href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
<li><a  href="InventoryOfficer_Notifications.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a></li>

</nav>
</div>

<div class="main">

 <div class="topbar">
        <h3>Inventory Reports<small><?php echo htmlspecialchars($branch_name); ?></small></h3>
       <div class="dropdown">
            <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                    type="button" id="inventoryOfficerProfileMenu"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span><?php echo h($username); ?></span>
                <span class="role-label">| Inventory Officer</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                aria-labelledby="inventoryOfficerProfileMenu">
                <li><h6 class="dropdown-header">Account options</h6></li>
                <li>
                    <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill me-2"></i>Change Password
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                       <a class="dropdown-item rounded-2 py-2 text-danger"
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#logoutConfirmModal">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
            </a>
                </li>
            </ul>
        </div>
</div>

<div class="page-body">

<form class="filter-card" method="POST" action="InventoryOfficer_Reports.php" id="reportForm">
<div class="filter-group">
<label for="report_type">Select Report</label>
<select name="report_type" id="report_type">
<option value="low_stock" <?php echo $report_type === 'low_stock' ? 'selected' : ''; ?>>Low Stock Report</option>
<option value="expiring_stock" <?php echo $report_type === 'expiring_stock' ? 'selected' : ''; ?>>Expiring Stock Report</option>
<option value="stock_usage" <?php echo $report_type === 'stock_usage' ? 'selected' : ''; ?>>Stock Usage Report</option>
<option value="transactions" <?php echo $report_type === 'transactions' ? 'selected' : ''; ?>>Stock Transaction Summary</option>
<option value="returns" <?php echo $report_type === 'returns' ? 'selected' : ''; ?>>Return Report</option>
<option value="shortage" <?php echo $report_type === 'shortage' ? 'selected' : ''; ?>>Shortage Forecast Report</option>
</select>
</div>

<div class="filter-group">
<label for="date_from">Date From</label>
<input type="date" name="date_from" id="date_from" value="<?php echo h($date_from); ?>" required>
</div>

<div class="filter-group">
<label for="date_to">Date To</label>
<input type="date" name="date_to" id="date_to" value="<?php echo h($date_to); ?>" required>
</div>

<div class="filter-group">
<label for="search">Search</label>
<input type="text" name="search" id="search" value="<?php echo h($search); ?>" maxlength="100" placeholder="Item, branch, status, or keyword">
</div>

<div class="filter-actions">
<button type="submit" class="btn-custom">
<i class="bi bi-file-earmark-bar-graph me-1"></i> Generate Report
</button>
<a class="btn-outline-custom" target="_blank"
   href="?export=csv&amp;report_type=<?php echo urlencode($report_type); ?>&amp;date_from=<?php echo urlencode($date_from); ?>&amp;date_to=<?php echo urlencode($date_to); ?>&amp;search=<?php echo urlencode($search); ?>">
<i class="bi bi-download me-1"></i> Export CSV
</a>
<button type="button" class="btn-outline-custom" onclick="window.print();">
<i class="bi bi-printer me-1"></i> Print
</button>
</div>
</form>

<?php if (!empty($errors)): ?>
<div class="alert alert-warning">
<?php foreach ($errors as $error): ?>
<div><?php echo h($error); ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($reportError !== ''): ?>
<div class="alert alert-danger">
<?php echo h($reportError); ?>
</div>
<?php endif; ?>

<div class="report-meta">
<strong><?php echo h(reportLabel($report_type)); ?></strong>
<span>Branch: <?php echo h($branch_name); ?></span>
<span>Date: <?php echo h(date('m/d/Y', strtotime($date_from))); ?> – <?php echo h(date('m/d/Y', strtotime($date_to))); ?></span>
</div>

<div class="summary-cards">
<div class="summary-card"><span>Report Rows</span><strong><?php echo moneylessNumber($summary['rows']); ?></strong></div>
<div class="summary-card"><span>Quantity</span><strong><?php echo moneylessNumber($summary['quantity']); ?></strong></div>
<?php if ($report_type === 'stock_usage'): ?>
<div class="summary-card"><span>Patients</span><strong><?php echo moneylessNumber($summary['patients']); ?></strong></div>
<?php elseif ($report_type === 'transactions'): ?>
<div class="summary-card"><span>Transactions</span><strong><?php echo moneylessNumber($summary['transactions']); ?></strong></div>
<?php elseif ($report_type === 'returns'): ?>
<div class="summary-card"><span>Received</span><strong><?php echo moneylessNumber($summary['returns_received']); ?></strong></div>
<div class="summary-card"><span>In Transit</span><strong><?php echo moneylessNumber($summary['returns_in_transit']); ?></strong></div>
<?php elseif ($report_type === 'shortage'): ?>
<div class="summary-card"><span>Recommended Reorder</span><strong><?php echo moneylessNumber($summary['quantity']); ?></strong></div>
<?php else: ?>
<div class="summary-card"><span>Branch</span><strong><?php echo h($branch_id); ?></strong></div>
<?php endif; ?>
</div>

<div class="row g-4">

<div class="col-lg-6">
<div class="large-card">
<div class="section-title">Top Supply Usage (Units Consumed)</div>
<div class="bar-chart">
<?php if (!empty($barData)): ?>
<?php foreach ($barData as $bar): ?>
<div class="bar-col">
<div class="bar-value"><?php echo (int)$bar['value']; ?></div>
<div class="bar" style="height:<?php echo max(8, round(((int)$bar['value'] / $maxBarValue) * 100)); ?>%;"></div>
<div class="bar-label"><?php echo h($bar['label']); ?></div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="summary-empty chart-empty">
<i class="bi bi-bar-chart"></i>
No usage data for the selected date range.
</div>
<?php endif; ?>
</div>
</div>
</div>

<div class="col-lg-6">
<div class="large-card">
<div class="section-title">Inventory Usage by Category</div>
<div class="pie-wrap">
<div class="pie" style="background:conic-gradient(<?php echo h($conicGradient); ?>);"></div>
<div class="pie-legend">
<?php if (!empty($pieData)): ?>
<?php foreach ($pieData as $slice): ?>
<div class="legend-row">
<span class="legend-dot" style="background:hsl(<?php echo (($slice['percent'] * 3.1) % 360); ?> 55% 45%);"></span>
<span><?php echo h($slice['label']); ?></span>
<span class="legend-value"><?php echo h($slice['percent']); ?>%</span>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="summary-empty chart-empty">No usage data for the selected date range.</div>
<?php endif; ?>
</div>
</div>
</div>
</div>

<div class="col-12">
<div class="large-card">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
<div class="section-title mb-0">Detailed Line-Item Summary</div>
<span class="small text-muted"><?php echo count($reportRows); ?> result(s)</span>
</div>

<div class="table-wrap report-table-wrap">
<table class="table data-table<?php echo $report_type === 'returns' ? ' return-report-table' : ''; ?>" id="inventoryReportTable">
<thead>
<?php if ($report_type === 'low_stock'): ?>
<tr><th>Category</th><th>Item</th><th>Unit</th><th>Minimum Stock</th><th>Current Stock</th><th>Status</th></tr>
<?php elseif ($report_type === 'expiring_stock'): ?>
<tr><th>Item</th><th>Category</th><th>Batch / Lot No.</th><th>Unit</th><th>Stock</th><th>Manufacturing Date</th><th>Expiration Date</th><th>Days Remaining</th><th>Status</th></tr>
<?php elseif ($report_type === 'stock_usage'): ?>
<tr><th>Usage Date</th><th>Item</th><th>Category</th><th>Unit</th><th>Qty Used</th><th>Patients</th><th>Stock Received</th></tr>
<?php elseif ($report_type === 'transactions'): ?>
<tr><th>Trx No.</th><th>Type</th><th>Item</th><th>Unit</th><th>Qty</th><th>Date</th><th>By</th><th>Remarks</th></tr>
<?php elseif ($report_type === 'returns'): ?>
<tr>
    <th>Return No.</th>
    <th>Direction</th>
    <th>Route</th>
    <th>Item</th>
    <th>Unit</th>
    <th>Qty</th>
    <th>Reason</th>
    <th>Status</th>
    <th>Created</th>
    <th>Sent</th>
    <th>Received</th>
    <th>Created By</th>
    <th>Received By</th>
    <th>Transportation</th>
    <th>Remarks</th>
</tr>
<?php elseif ($report_type === 'shortage'): ?>
<tr><th>Date</th><th>Item</th><th>Category</th><th>Unit</th><th>Probability</th><th>Status</th><th>Recommended Reorder</th><th>Predicted Consumption</th><th>Forecast Days</th><th>Generated By</th></tr>
<?php endif; ?>
</thead>
<tbody id="inventoryReportBody">
<?php if (empty($reportRows)): ?>
<tr><td colspan="12" class="text-center text-muted py-4">No records found for the selected filters.</td></tr>
<?php else: ?>
<?php foreach ($reportRows as $row): ?>
<?php if ($report_type === 'low_stock'): ?>
<tr>
<td><?php echo h($row['category_name']); ?></td>
<td><?php echo h($row['item_name']); ?></td>
<td><?php echo h($row['unit_name']); ?></td>
<td><?php echo moneylessNumber($row['minimum_stock']); ?></td>
<td><strong><?php echo moneylessNumber($row['current_stock']); ?></strong></td>
<td><span class="report-status <?php echo (int)$row['current_stock'] <= 0 ? 'status-critical' : 'status-warning'; ?>"><?php echo (int)$row['current_stock'] <= 0 ? 'Critical' : 'Low'; ?></span></td>
</tr>
<?php elseif ($report_type === 'expiring_stock'): ?>
<tr>
<td><?php echo h($row['item_name']); ?></td>
<td><?php echo h($row['category_name']); ?></td>
<td><?php echo h($row['batch_lot_no'] ?: '—'); ?></td>
<td><?php echo h($row['unit_name']); ?></td>
<td><?php echo moneylessNumber($row['quantity_available']); ?></td>
<td><?php echo h($row['manufacturing_date'] ?: '—'); ?></td>
<td><?php echo h($row['expiration_date']); ?></td>
<td><?php echo h($row['days_remaining']); ?></td>
<td><span class="report-status <?php echo h(statusClass($row['expiry_status'])); ?>"><?php echo h($row['expiry_status']); ?></span></td>
</tr>
<?php elseif ($report_type === 'stock_usage'): ?>
<tr>
<td><?php echo h($row['usage_date']); ?></td>
<td><?php echo h($row['item_name']); ?></td>
<td><?php echo h($row['category_name']); ?></td>
<td><?php echo h($row['unit_name']); ?></td>
<td><strong><?php echo moneylessNumber($row['quantity_used']); ?></strong></td>
<td><?php echo moneylessNumber($row['patient_count']); ?></td>
<td><?php echo moneylessNumber($row['stock_received']); ?></td>
</tr>
<?php elseif ($report_type === 'transactions'): ?>
<tr>
<td>TRX-<?php echo str_pad((string)$row['transaction_id'], 4, '0', STR_PAD_LEFT); ?></td>
<td><span class="report-status <?php echo $row['transaction_type'] === 'OUT' ? 'status-warning' : ($row['transaction_type'] === 'IN' ? 'status-good' : 'status-neutral'); ?>"><?php echo h($row['transaction_type']); ?></span></td>
<td><?php echo h($row['item_name']); ?></td>
<td><?php echo h($row['unit_name']); ?></td>
<td><?php echo moneylessNumber($row['quantity']); ?></td>
<td><?php echo h(date('m/d/Y H:i', strtotime($row['transaction_date']))); ?></td>
<td><?php echo h($row['username']); ?></td>
<td><?php echo h($row['remarks'] ?: '—'); ?></td>
</tr>
<?php elseif ($report_type === 'returns'): ?>
<tr>
<td><strong><?php echo h($row['return_number'] ?: ('RET-' . $row['return_id'])); ?></strong></td>
<td>
    <span class="report-status <?php echo $row['direction'] === 'Sent' ? 'status-warning' : 'status-neutral'; ?>">
        <?php echo h($row['direction']); ?>
    </span>
</td>
<td>
    <?php
    $sourceBranch = $row['source_branch'] ?: $row['branch_id'];
    $destinationBranch = $row['destination_branch'] ?: $row['destination_branch_id'];
    echo h($sourceBranch . ' → ' . $destinationBranch);
    ?>
</td>
<td><?php echo h($row['item_name']); ?></td>
<td><?php echo h($row['unit_name'] ?: '—'); ?></td>
<td><strong><?php echo h(rtrim(rtrim(number_format((float)$row['quantity'], 2, '.', ''), '0'), '.')); ?></strong></td>
<td><?php echo h($row['return_reason']); ?></td>
<td><span class="report-status <?php echo h(statusClass($row['status'] ?? '')); ?>"><?php echo h($row['status']); ?></span></td>
<td><?php echo h($row['created_at'] ? date('m/d/Y H:i', strtotime($row['created_at'])) : '—'); ?></td>
<td><?php echo h($row['sent_at'] ? date('m/d/Y H:i', strtotime($row['sent_at'])) : '—'); ?></td>
<td><?php echo h($row['received_at'] ? date('m/d/Y H:i', strtotime($row['received_at'])) : '—'); ?></td>
<td><?php echo h($row['created_by_name'] ?: '—'); ?></td>
<td><?php echo h($row['processed_by_name'] ?: '—'); ?></td>
<td><?php echo h($row['transportation_details'] ?: '—'); ?></td>
<td><?php echo h($row['remarks'] ?: '—'); ?></td>
</tr>
<?php elseif ($report_type === 'shortage'): ?>
<tr>
<td><?php echo h($row['forecast_date']); ?></td>
<td><?php echo h($row['item_name']); ?></td>
<td><?php echo h($row['category_name']); ?></td>
<td><?php echo h($row['unit_name']); ?></td>
<td><?php echo $row['shortage_probability'] !== null ? h($row['shortage_probability']) . '%' : '—'; ?></td>
<td><span class="report-status <?php echo h(statusClass($row['forecast_status'] ?? '')); ?>"><?php echo h($row['forecast_status'] ?: '—'); ?></span></td>
<td><?php echo moneylessNumber($row['recommended_reorder']); ?></td>
<td><?php echo moneylessNumber($row['forecasted_consumption']); ?></td>
<td><?php echo $row['forecast_days'] !== null ? moneylessNumber($row['forecast_days']) : '—'; ?></td>
<td><?php echo h($row['generated_by_name'] ?: '—'); ?></td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if (!empty($reportRows)): ?>
<div class="table-pagination-bar">
    <div class="table-pagination-summary" id="reportPaginationSummary">
        Showing report rows
    </div>

    <div class="table-pagination-actions">
        <label class="rows-per-page-control" for="reportRowsPerPage">
            Rows
            <select id="reportRowsPerPage">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </label>

        <div class="table-page-buttons" id="reportPageButtons" aria-label="Inventory report pages"></div>
    </div>
</div>
<?php endif; ?>
</div>
</div>

</div>

</div>

</div>

<div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2 class="modal-title" id="logoutConfirmModalLabel">
                    Log out of Smart Bite Care?
                </h2>
            </div>

            <div class="modal-body">
                <p class="mb-0">
                    Make sure you have saved any unfinished work before leaving your account.
                </p>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="logout.php"
                    class="btn btn-danger d-flex align-items-center justify-content-center"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('reportForm');
    const from = document.getElementById('date_from');
    const to = document.getElementById('date_to');

    if (form) {
        form.addEventListener('submit', function (event) {
            if (from.value && to.value && from.value > to.value) {
                event.preventDefault();
                alert('Date From cannot be later than Date To.');
            }
        });
    }


    const reportRows =
        Array.from(
            document.querySelectorAll(
                '#inventoryReportBody tr'
            )
        );

    const reportRowsPerPage =
        document.getElementById(
            'reportRowsPerPage'
        );

    const reportPageButtons =
        document.getElementById(
            'reportPageButtons'
        );

    const reportPaginationSummary =
        document.getElementById(
            'reportPaginationSummary'
        );

    let reportCurrentPage = 1;


    function renderReportPageButtons(
        totalPages
    ) {

        if (!reportPageButtons) {
            return;
        }

        reportPageButtons.innerHTML = '';

        if (totalPages <= 1) {
            return;
        }


        const addButton =
            (
                label,
                page,
                disabled = false,
                active = false
            ) => {

                const button =
                    document.createElement(
                        'button'
                    );

                button.type =
                    'button';

                button.className =
                    'table-page-btn'
                    +
                    (
                        active
                        ? ' active'
                        : ''
                    );

                button.innerHTML =
                    label;

                button.disabled =
                    disabled;


                if (
                    !disabled
                    &&
                    !active
                ) {

                    button.addEventListener(
                        'click',
                        function () {

                            reportCurrentPage =
                                page;

                            renderReportPage(
                                false
                            );
                        }
                    );
                }


                reportPageButtons
                    .appendChild(
                        button
                    );
            };


        addButton(
            '<i class="bi bi-chevron-left"></i>',
            reportCurrentPage - 1,
            reportCurrentPage === 1
        );


        let startPage =
            Math.max(
                1,
                reportCurrentPage - 2
            );

        let endPage =
            Math.min(
                totalPages,
                startPage + 4
            );

        startPage =
            Math.max(
                1,
                endPage - 4
            );


        for (
            let page = startPage;
            page <= endPage;
            page++
        ) {

            addButton(
                String(page),
                page,
                false,
                page === reportCurrentPage
            );
        }


        addButton(
            '<i class="bi bi-chevron-right"></i>',
            reportCurrentPage + 1,
            reportCurrentPage === totalPages
        );
    }


    function renderReportPage(
        resetPage = true
    ) {

        if (
            !reportRows.length
            ||
            !reportRowsPerPage
            ||
            !reportPaginationSummary
        ) {
            return;
        }


        if (resetPage) {
            reportCurrentPage = 1;
        }


        const rowsPerPage =
            Math.max(
                1,
                parseInt(
                    reportRowsPerPage.value,
                    10
                )
                ||
                10
            );


        const totalPages =
            Math.max(
                1,
                Math.ceil(
                    reportRows.length
                    /
                    rowsPerPage
                )
            );


        if (
            reportCurrentPage
            >
            totalPages
        ) {
            reportCurrentPage =
                totalPages;
        }


        reportRows.forEach(
            row =>
                row.style.display =
                    'none'
        );


        const startIndex =
            (
                reportCurrentPage
                -
                1
            )
            *
            rowsPerPage;


        const endIndex =
            Math.min(
                startIndex
                +
                rowsPerPage,
                reportRows.length
            );


        reportRows
            .slice(
                startIndex,
                endIndex
            )
            .forEach(
                row =>
                    row.style.display =
                        ''
            );


        reportPaginationSummary
            .textContent =
                `Showing ${startIndex + 1}–${endIndex} of ${reportRows.length} result${reportRows.length === 1 ? '' : 's'}`;


        renderReportPageButtons(
            totalPages
        );
    }


    if (
        reportRowsPerPage
    ) {

        reportRowsPerPage
            .addEventListener(
                'change',
                () =>
                    renderReportPage(
                        true
                    )
            );

        renderReportPage(
            true
        );
    }
});
</script>
</body>
</html>