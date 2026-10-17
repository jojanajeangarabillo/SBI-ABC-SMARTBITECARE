<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/inventory_stock_helpers.php';
require_once 'sources/daily_inventory_helpers.php';
$stockTotalsSql = inventoryStockTotalsSql();
require_once 'sources/workflow_helpers.php';
require_once 'sources/forecast_training_helper.php';
require_once 'sources/inventory_unit_helpers.php';
require_once 'sources/notification_helper.php';
require_once __DIR__ . '/fpdf/fpdf.php';

// Authenticate before reading session data or loading notifications.
$user = workflowRequireUser($conn, 3);
$userId = (int)$user['user_id'];
$user_id = $userId;
$branchId = (string)$user['branch_id'];

// A stale session role must not authorize a different current database role.
$roleCheck = $conn->prepare("SELECT user_id FROM users WHERE user_id=? AND role_id=3 AND status='Active'");
$roleCheck->bind_param('i', $userId);
$roleCheck->execute();
$isNurse = (bool)$roleCheck->get_result()->fetch_assoc();
$roleCheck->close();
if (!$isNurse) {
    header('Location: login.php');
    exit;
}

$notification_count = getUnreadNotificationCount($conn, $userId);
$csrf = workflowCsrfToken();

function dailyInventoryValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function dailyInventoryPdfText($value): string
{
    $text = trim((string)$value);

    if ($text === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv(
            'UTF-8',
            'windows-1252//TRANSLIT//IGNORE',
            $text
        );

        if ($converted !== false) {
            return $converted;
        }
    }

    return preg_replace('/[^\\x20-\\x7E]/', '', $text) ?: '';
}

function dailyInventoryPdfShort($value, int $max = 28): string
{
    $text = dailyInventoryPdfText($value);

    if (strlen($text) <= $max) {
        return $text;
    }

    return substr($text, 0, max(0, $max - 3)) . '...';
}

class DailyInventoryClosingPdf extends FPDF
{
    private array $branch;
    private string $inventoryDate;

    public function __construct(array $branch, string $inventoryDate)
    {
        parent::__construct('L', 'mm', 'A4');

        $this->branch = $branch;
        $this->inventoryDate = $inventoryDate;

        $this->SetMargins(12, 12, 12);
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages();
    }

    public function Header(): void
    {
        $logo = __DIR__ . DIRECTORY_SEPARATOR . 'logo.png';

        if (is_file($logo)) {
            $this->Image($logo, 12, 8, 20, 20);
        }

        $this->SetXY(36, 9);
        $this->SetTextColor(43, 58, 140);
        $this->SetFont('Arial', 'B', 15);

        $this->Cell(
            0,
            6,
            dailyInventoryPdfText(
                $this->branch['branch_name'] ?? 'Smart Bite Care'
            ),
            0,
            1
        );

        $this->SetX(36);
        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(75, 83, 105);

        $this->Cell(
            0,
            4.5,
            dailyInventoryPdfText(
                $this->branch['branch_address'] ?? ''
            ),
            0,
            1
        );

        $this->SetX(36);

        $contact = trim(
            (string)($this->branch['contact_number'] ?? '')
        );
        $email = trim((string)($this->branch['email'] ?? ''));

        $this->Cell(
            0,
            4.5,
            dailyInventoryPdfText(
                trim($contact . ($contact && $email ? ' | ' : '') . $email)
            ),
            0,
            1
        );

        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.5);
        $this->Line(12, 31, 285, 31);
        $this->Ln(8);

        $this->SetTextColor(43, 58, 140);
        $this->SetFont('Arial', 'B', 16);

        $this->Cell(
            0,
            7,
            'DAILY INVENTORY CLOSING REPORT',
            0,
            1,
            'C'
        );

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(85, 93, 115);

        $this->Cell(
            0,
            5,
            'Inventory Date: ' .
                date('F d, Y', strtotime($this->inventoryDate)),
            0,
            1,
            'C'
        );

        $this->Ln(4);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120, 126, 140);

        $this->Cell(
            0,
            5,
            'SmartBiteCare - Daily Inventory | Page ' .
                $this->PageNo() . '/{nb}',
            0,
            0,
            'C'
        );
    }
}

function fetchDailyInventoryClosingsForDate(
    mysqli $conn,
    string $branchId,
    string $date
): array {
    $stmt = $conn->prepare(
        "SELECT d.*, i.item_name, u2.unit_name,
                COALESCE(NULLIF(i.base_unit_label,''),u2.unit_name)
                    AS base_unit_label,
                COALESCE(NULLIF(i.display_unit_label,''),u2.unit_name)
                    AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base,0),1)
                    AS conversion_to_base,
                u.username
         FROM daily_inventory_closings d
         INNER JOIN inventory_items i ON i.item_id=d.item_id
         INNER JOIN users u ON u.user_id=d.submitted_by
         INNER JOIN units u2 ON u2.unit_id=i.unit_id
         WHERE d.branch_id=? AND d.inventory_date=?
         ORDER BY i.item_name"
    );

    $stmt->bind_param('ss', $branchId, $date);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// Export the selected day's closing report.
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $exportDate = trim((string)($_GET['date'] ?? ''));

    if (!dailyInventoryValidDate($exportDate)) {
        http_response_code(400);
        exit('Invalid inventory date.');
    }

    $exportRows = fetchDailyInventoryClosingsForDate(
        $conn,
        $branchId,
        $exportDate
    );

    if (!$exportRows) {
        http_response_code(404);
        exit(
            'No daily inventory closing records were found ' .
            'for the selected date.'
        );
    }

    $branchStmt = $conn->prepare(
        "SELECT branch_name, branch_address, contact_number, email
         FROM branches
         WHERE branch_id=?
         LIMIT 1"
    );

    $branchStmt->bind_param('s', $branchId);
    $branchStmt->execute();

    $branchInfo = $branchStmt->get_result()->fetch_assoc() ?: [
        'branch_name' => (string)($user['branch_name'] ?? $branchId),
        'branch_address' => '',
        'contact_number' => '',
        'email' => ''
    ];

    $branchStmt->close();

    $pdf = new DailyInventoryClosingPdf($branchInfo, $exportDate);

    $pdf->SetTitle(
        dailyInventoryPdfText('Daily Inventory - ' . $exportDate)
    );
    $pdf->SetAuthor('SmartBiteCare');
    $pdf->AddPage();

    $headers = [
        'Item',
        'Beginning',
        'Delivery',
        'Consumed',
        'Pull-out',
        'Computed',
        'Actual',
        'Variance',
        'Status'
    ];

    $widths = [55, 28, 25, 28, 25, 29, 29, 27, 24];

    $pdf->SetFillColor(43, 58, 140);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);

    foreach ($headers as $i => $header) {
        $pdf->Cell(
            $widths[$i],
            8,
            $header,
            1,
            0,
            'C',
            true
        );
    }

    $pdf->Ln();
    $pdf->SetFont('Arial', '', 7.6);

    $tallied = 0;
    $differences = 0;

    foreach ($exportRows as $row) {
        $variance = (float)$row['variance'];
        $isTallied = abs($variance) < 0.00005;

        if ($isTallied) {
            $tallied++;
        } else {
            $differences++;
        }

        $cells = [
            dailyInventoryPdfShort($row['item_name'], 34),
            inventoryStockBreakdown(
                (float)$row['beginning_stock'],
                $row
            ),
            inventoryStockBreakdown((float)$row['delivery'], $row),
            inventoryStockBreakdown((float)$row['consumed'], $row),
            inventoryStockBreakdown((float)$row['pull_out'], $row),
            inventoryStockBreakdown(
                (float)$row['computed_ending'],
                $row
            ),
            inventoryStockBreakdown(
                (float)$row['actual_count'],
                $row
            ),
            inventoryUsageDescription($variance, $row),
            $isTallied ? 'Tallied' : 'Variance'
        ];

        $pdf->SetTextColor(45, 52, 70);

        foreach ($cells as $i => $cell) {
            $align = $i === 0 ? 'L' : 'C';

            $pdf->Cell(
                $widths[$i],
                7.5,
                dailyInventoryPdfShort($cell, $i === 0 ? 34 : 20),
                1,
                0,
                $align
            );
        }

        $pdf->Ln();
    }

    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(43, 58, 140);
    $pdf->Cell(0, 5, 'Daily Closing Summary', 0, 1);

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(60, 68, 88);

    $pdf->Cell(
        0,
        5,
        'Items closed: ' . count($exportRows) .
            ' | Tallied: ' . $tallied .
            ' | With variance: ' . $differences,
        0,
        1
    );

    $remarks = array_values(
        array_filter(
            $exportRows,
            fn($row) => trim((string)($row['remarks'] ?? '')) !== ''
        )
    );

    if ($remarks) {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(43, 58, 140);
        $pdf->Cell(0, 5, 'Remarks / Adjustments', 0, 1);

        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor(60, 68, 88);

        foreach ($remarks as $row) {
            $line = $row['item_name'] . ': ' . $row['remarks'];

            $pdf->MultiCell(
                0,
                4.5,
                dailyInventoryPdfText($line),
                0,
                'L'
            );
        }
    }

    workflowAudit(
        $conn,
        $userId,
        $branchId,
        'Exported daily inventory closing PDF for ' . $exportDate,
        'Daily Inventory'
    );

    $filename = 'Daily_Inventory_' .
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            (string)($branchInfo['branch_name'] ?? $branchId)
        ) .
        '_' . $exportDate . '.pdf';

    $pdf->Output('D', $filename);
    exit;
}

/** Read usage and the selected day's closing context; never invent an opening. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'daily_usage') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $itemId = dailyInventoryItemId($_GET['item_id'] ?? '');
        $inventoryDate = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
        dailyInventoryAssertDate($conn, $inventoryDate);
        echo json_encode(dailyInventoryContext($conn, $branchId, $itemId, $inventoryDate), JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException | RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('Daily inventory context: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load daily inventory. Refresh and try again.']);
    }
    exit;
}

// Save or correct a daily closing.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();

        $itemId = dailyInventoryItemId($_POST['item_id'] ?? '');
        $inventoryDate = is_string($_POST['inventory_date'] ?? null) ? trim($_POST['inventory_date']) : '';
        $values = [];
        foreach (['beginning_stock', 'delivery', 'pull_out', 'actual_count'] as $field) {
            $values[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
        $remarks = is_string($_POST['remarks'] ?? null) ? $_POST['remarks'] : '';
        $contextToken = is_string($_POST['closing_context_token'] ?? null) ? $_POST['closing_context_token'] : '';
        try {
            // Same validation, closing and training_dataset sync as the whole-day sheet.
            $saved = dailyInventorySaveClosing(
                $conn, $branchId, $userId, $itemId, $inventoryDate, $values, $remarks, $contextToken,
                is_string($_POST['extra_consumed'] ?? null) ? $_POST['extra_consumed'] : '',
                is_string($_POST['extra_consumed_remarks'] ?? null) ? $_POST['extra_consumed_remarks'] : ''
            );
        } catch (DailyInventoryStaleContextException $stale) {
            $needsNewCount = true;
            throw $stale;
        }
        $trainingSynced = $saved['training_synced'];
        unset($_SESSION['daily_inventory_form']);

        workflowFlash(
            'success',
            'Daily inventory submitted. Consumption came from recorded usage. ' .
                ($saved['manual_usage'] !== null
                    ? 'The consumed quantity you entered (' . $saved['manual_usage']['description']
                      . ') was recorded as usage and deducted from stock once. '
                    : 'This closing records counts; it does not deduct stock again.') .
                (
                    $trainingSynced
                        ? ' The forecasting dataset was updated; forecasts ' .
                          'will refresh when the Branch Administrator ' .
                          'opens Supply Forecasting.'
                        : ' This item is not enabled for forecasting.'
                )
        );
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }

        $_SESSION['daily_inventory_form'] = [];
        foreach (['item_id', 'inventory_date', 'beginning_stock', 'delivery', 'pull_out', 'actual_count', 'remarks',
                  'extra_consumed', 'extra_consumed_remarks'] as $field) {
            $_SESSION['daily_inventory_form'][$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
        }
        if (!empty($needsNewCount)) {
            $_SESSION['daily_inventory_form']['actual_count'] = '';
        }
        workflowFlash('danger', $e->getMessage());
    }

    $redirect = 'Nurse_DailyInventory.php';
    if (isset($inventoryDate) && dailyInventoryValidDate($inventoryDate)) {
        $redirect .= '?' . http_build_query(['item_id' => $itemId ?? '', 'inventory_date' => $inventoryDate, 'history_date' => $inventoryDate]);
    }
    header('Location: ' . $redirect);
    exit;
}

// Restore a rejected form once, or load the item/date selected after submission.
$formRestore = $_SESSION['daily_inventory_form'] ?? null;
unset($_SESSION['daily_inventory_form']);
$formItemId = (string)($formRestore['item_id'] ?? (is_string($_GET['item_id'] ?? null) ? $_GET['item_id'] : ''));
$formDate = (string)($formRestore['inventory_date'] ?? (is_string($_GET['inventory_date'] ?? null) ? $_GET['inventory_date'] : date('Y-m-d')));
if (!dailyInventoryValidDate($formDate) || $formDate > date('Y-m-d')) {
    $formDate = date('Y-m-d');
}

// Current stock is a live reference only; it is never used as the opening count.
$itemStmt = $conn->prepare(
    "SELECT i.item_id,i.item_name,u.unit_name,
            COALESCE(NULLIF(i.base_unit_label,''),u.unit_name)
                AS base_unit_label,
            COALESCE(NULLIF(i.display_unit_label,''),u.unit_name)
                AS display_unit_label,
            COALESCE(NULLIF(i.conversion_to_base,0),1)
                AS conversion_to_base,
            COALESCE(s.usable_stock,0) AS current_stock
     FROM inventory_items i
     INNER JOIN units u ON u.unit_id=i.unit_id
     LEFT JOIN ($stockTotalsSql) s
        ON s.item_id=i.item_id AND s.branch_id=?
     WHERE i.is_consumable=1
     ORDER BY i.item_name"
);

$itemStmt->bind_param('s', $branchId);
$itemStmt->execute();

$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemStmt->close();

foreach ($items as &$itemRow) {
    $itemRow['stock_display'] = inventoryStockBreakdown(
        (float)$itemRow['current_stock'],
        $itemRow
    );

    // Allow the database's four-decimal inventory precision for closings.
    $itemRow['input_step'] = '0.0001';
}

unset($itemRow);

// Closing history filters.
$historyRange = trim((string)($_GET['range'] ?? 'today'));
$historyDate = trim((string)($_GET['history_date'] ?? ''));

$allowedHistoryRanges = ['today', '7', '30', 'all'];

if (!in_array($historyRange, $allowedHistoryRanges, true)) {
    $historyRange = 'today';
}

if ($historyDate !== '' && !dailyInventoryValidDate($historyDate)) {
    $historyDate = '';
}

$historySql =
    "SELECT d.*,i.item_name,u2.unit_name,
            COALESCE(NULLIF(i.base_unit_label,''),u2.unit_name)
                AS base_unit_label,
            COALESCE(NULLIF(i.display_unit_label,''),u2.unit_name)
                AS display_unit_label,
            COALESCE(NULLIF(i.conversion_to_base,0),1)
                AS conversion_to_base,
            u.username
     FROM daily_inventory_closings d
     INNER JOIN inventory_items i ON i.item_id=d.item_id
     INNER JOIN users u ON u.user_id=d.submitted_by
     INNER JOIN units u2 ON u2.unit_id=i.unit_id
     WHERE d.branch_id=?";

$historyLabel = 'Today';

if ($historyDate !== '') {
    $historySql .= ' AND d.inventory_date=?';
    $historyLabel = date('F d, Y', strtotime($historyDate));
} elseif ($historyRange === '7') {
    $historySql .=
        ' AND d.inventory_date BETWEEN ' .
        'DATE_SUB(CURDATE(),INTERVAL 6 DAY) AND CURDATE()';

    $historyLabel = 'Last 7 days';
} elseif ($historyRange === '30') {
    $historySql .=
        ' AND d.inventory_date BETWEEN ' .
        'DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND CURDATE()';

    $historyLabel = 'Last 30 days';
} elseif ($historyRange === 'all') {
    $historyLabel = 'All closing history';
} else {
    $historySql .= ' AND d.inventory_date=CURDATE()';
}

$historySql .= ' ORDER BY d.inventory_date DESC,i.item_name';

$stmt = $conn->prepare($historySql);

if ($historyDate !== '') {
    $stmt->bind_param('ss', $branchId, $historyDate);
} else {
    $stmt->bind_param('s', $branchId);
}

$stmt->execute();

$closings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$closingsByDate = [];

foreach ($closings as $closingRow) {
    $dateKey = (string)$closingRow['inventory_date'];

    if (!isset($closingsByDate[$dateKey])) {
        $closingsByDate[$dateKey] = [];
    }

    $closingsByDate[$dateKey][] = $closingRow;
}

$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Daily Inventory - Smart Bite Care</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --success: #28a745;
            --danger: #dc3545;
            --text: #1f2a44;
            --muted: #6f7b91;
            --border: #e6eaf2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f9faff;
            color: var(--text);
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            height: 80px;
            padding: 0 35px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
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
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-role {
            margin-left: 3px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .content {
            padding: 35px 35px 40px;
        }

        .page-intro {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            padding: 15px 18px;
            color: #314269;
            background: #edf1ff;
            border: 1px solid #dce3fb;
            border-radius: 12px;
        }

        .page-intro i {
            color: var(--primary);
            font-size: 22px;
        }

        .page-intro strong {
            display: block;
            margin-bottom: 2px;
            color: var(--primary);
        }

        .page-intro p {
            margin: 0;
            color: #64708b;
            font-size: 13px;
        }

        .content-card {
            overflow: hidden;
            margin-bottom: 24px;
            background: #fff;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 3px 8px rgba(0,0,0,.08);
        }

        .content-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 20px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h2 {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            color: var(--primary);
            font-size: 19px;
            font-weight: 700;
        }

        .content-card-header p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .section-icon {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
        }

        .content-card-body {
            padding: 24px;
        }

        .form-label {
            margin-bottom: 6px;
            color: #48546f;
            font-size: 13px;
            font-weight: 650;
        }

        .required::after {
            content: ' *';
            color: var(--danger);
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-color: #d9dfeb;
            border-radius: 9px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(43,58,140,.12);
        }

        .small-help {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            color: var(--muted);
            font-size: 12px;
        }

        .btn-primary {
            min-height: 44px;
            background: var(--primary);
            border-color: var(--primary);
            font-weight: 650;
        }

        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .alert {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .inventory-table {
            min-width: 1020px;
            margin: 0;
        }

        .inventory-table thead th {
            padding: 13px 16px;
            color: #667085;
            background: #f8f9fc;
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .25px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .inventory-table tbody td {
            padding: 13px 16px;
            color: #34405d;
            border-color: #edf0f5;
            font-size: 13px;
            vertical-align: middle;
        }

        .inventory-table tbody tr:hover {
            background: #fafbff;
        }

        .item-name {
            color: var(--primary);
            font-weight: 650;
        }

        .variance-badge {
            display: inline-block;
            min-width: 62px;
            padding: 4px 8px;
            text-align: center;
            border-radius: 999px;
            font-weight: 700;
        }

        .variance-badge.match {
            color: #198754;
            background: #e8f7ef;
        }

        .variance-badge.difference {
            color: #c0392b;
            background: #fdebec;
        }

        .empty {
            padding: 38px 20px !important;
            color: #8a94a6 !important;
            text-align: center;
        }

        .history-tools {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .history-tools .filter-block {
            min-width: 155px;
        }

        .history-tools .filter-block.date-filter {
            min-width: 180px;
        }

        .history-tools .form-label {
            margin-bottom: 5px;
        }

        .day-closing {
            margin: 0 24px 20px;
            border: 1px solid #e7ebf3;
            border-radius: 14px;
            overflow: hidden;
            background: #fff;
        }

        .day-closing:first-child {
            margin-top: 20px;
        }

        .day-closing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            padding: 15px 17px;
            background: #f7f9ff;
            border-bottom: 1px solid #e7ebf3;
        }

        .day-closing-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .day-closing-title .date-icon {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #e9edff;
            color: var(--primary);
            font-size: 18px;
        }

        .day-closing-title strong {
            display: block;
            color: var(--primary);
            font-size: 15px;
        }

        .day-closing-title small {
            color: var(--muted);
        }

        .day-summary {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            align-items: center;
        }

        .summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .summary-pill.total {
            color: #435176;
            background: #edf1f7;
        }

        .summary-pill.tallied {
            color: #177245;
            background: #e8f7ef;
        }

        .summary-pill.variance {
            color: #b04a36;
            background: #fff0ec;
        }

        .result-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .result-badge.tallied {
            color: #177245;
            background: #e8f7ef;
        }

        .result-badge.variance {
            color: #b04a36;
            background: #fff0ec;
        }

        .closing-preview {
            display: grid;
            grid-template-columns: repeat(3,minmax(0,1fr));
            gap: 10px;
            margin-top: 4px;
        }

        .preview-box {
            padding: 12px 14px;
            border: 1px solid #e3e8f1;
            border-radius: 11px;
            background: #fafbff;
        }

        .preview-box .label {
            display: block;
            margin-bottom: 4px;
            color: #7a8498;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .25px;
        }

        .preview-box .value {
            color: #273661;
            font-size: 17px;
            font-weight: 750;
        }

        .preview-box.match {
            background: #f0faf4;
            border-color: #cee9d9;
        }

        .preview-box.difference {
            background: #fff5f2;
            border-color: #f3d5ce;
        }

        .btn-soft-primary {
            color: var(--primary);
            background: #eef1ff;
            border: 1px solid #d6ddff;
            font-weight: 650;
        }

        .btn-soft-primary:hover {
            color: #fff;
            background: var(--primary);
            border-color: var(--primary);
        }

        .history-empty {
            padding: 42px 24px;
            text-align: center;
            color: #8791a4;
        }

        /* Logout confirmation modal. */
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
            box-shadow:
                0 20px 55px rgba(31,45,110,0.20) !important;
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
            background:
                linear-gradient(135deg,#ef3340,#f05b68) !important;
            border-radius: 50% !important;
            box-shadow:
                0 9px 22px rgba(239,51,64,0.22) !important;
            font-size: 27px !important;
        }

        .confirm-modal .modal-icon.logout-icon {
            background:
                linear-gradient(135deg,#ef3340,#f05b68) !important;
            box-shadow:
                0 9px 22px rgba(239,51,64,0.22) !important;
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

        #logoutConfirmModal .confirmation-summary,
        #logoutConfirmModal .confirmation-warning {
            display: none !important;
        }

        @media (max-width: 576px) {
            .confirm-modal .modal-dialog {
                width: calc(100% - 20px);
                margin: 10px auto;
            }

            .confirm-modal .modal-header {
                padding: 22px 18px 6px !important;
            }

            .confirm-modal .modal-icon {
                width: 58px !important;
                height: 58px !important;
                margin-bottom: 12px !important;
                font-size: 24px !important;
            }

            .confirm-modal .modal-title {
                font-size: 21px !important;
            }

            .confirm-modal .modal-body {
                padding: 6px 20px 18px !important;
            }

            .confirm-modal .modal-body p {
                font-size: 15px !important;
            }

            .confirm-modal .modal-footer {
                padding: 0 18px 20px !important;
            }

            .confirm-modal .modal-footer .btn {
                min-height: 46px !important;
                font-size: 15px !important;
            }
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }

            .topbar {
                padding: 0 22px;
            }

            .content {
                padding: 28px 22px 35px;
            }

            .topbar h3 small,
            .profile-role {
                display: none;
            }
        }

        @media (max-width: 767px) {
            .topbar {
                height: 70px;
                padding: 0 16px;
            }

            .topbar h3 {
                font-size: 20px;
            }

            .content {
                padding: 20px 14px 30px;
            }

            .content-card-header,
            .content-card-body {
                padding: 17px;
            }

            .page-intro {
                align-items: flex-start;
            }
        }

        @media (max-width: 520px) {
            .profile span {
                display: none;
            }
        }
    </style>
</head>

<body>
<aside class="sidebar" id="sidebar">
    <button
        type="button"
        class="sidebar-toggle"
        id="sidebarToggle"
        aria-label="Toggle sidebar"
        aria-expanded="true"
    >
        <i class="bi bi-chevron-left"></i>
    </button>

    <div class="logo-area">
        <div class="logo-frame">
            <img
                src="logo.png"
                alt="Smart Bite Care Logo"
                class="logo"
            >
        </div>

        <div class="system-name">
            Smart Bite Care
        </div>
    </div>

    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li>
                <a href="Nurse_Dashboard.php">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Calendar.php" aria-current="page">
                    <i class="bi bi-calendar3"></i>
                    <span>Calendar</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Patients.php">
                    <i class="bi bi-heart-pulse-fill"></i>
                    <span>Patients</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Assessment.php">
                    <i class="bi bi-clipboard2-pulse-fill"></i>
                    <span>Assessment Queue</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Vaccination.php">
                    <i class="bi bi-shield-plus"></i>
                    <span>Vaccination</span>
                </a>
            </li>

            <li>
                <a
                    class="active"
                    href="Nurse_DailyInventory.php"
                    aria-current="page"
                >
                    <i class="bi bi-clipboard-data-fill"></i>
                    <span>Daily Inventory</span>
                </a>
            </li>

            <li>
                <a href="Nurse_MedicalSuppliesManagement.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Medical Supplies Management</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Supplyforecasting.php">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Supply Forecasting</span>
                </a>
            </li>

            <li>
                <a
                    href="Nurse_Notification.php"
                    class="notification-link"
                >
                    <i class="bi bi-bell-fill"></i>
                    <span>Notifications</span>

                    <?php if ($notification_count > 0): ?>
                        <span class="notification-badge">
                            <?php echo $notification_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<main class="main">
    <div class="topbar">
        <h3>
            Daily Inventory
            <small>
                <?= workflowH(
                    (string)($user['branch_name'] ?? $branchId)
                ) ?>
            </small>
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

                <span>
                    <?php echo htmlspecialchars(
                        $user['username'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </span>

                <span
                    style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;"
                >
                    | Nurse
                </span>
            </button>

            <ul
                class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                aria-labelledby="nurseProfileMenu"
            >
                <li>
                    <h6 class="dropdown-header">
                        Account options
                    </h6>
                </li>

                <li>
                    <a
                        class="dropdown-item rounded-2 py-2"
                        href="Account_ChangePassword.php"
                    >
                        <i class="bi bi-key-fill me-2"></i>
                        Change Password
                    </a>
                </li>

                <li>
                    <hr class="dropdown-divider">
                </li>

                <li>
                    <a
                        class="dropdown-item rounded-2 py-2 text-danger"
                        href="#"
                        data-bs-toggle="modal"
                        data-bs-target="#logoutConfirmModal"
                    >
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="content">
        <?php if ($flash): ?>
            <div
                class="alert alert-<?= workflowH(
                    (string)$flash['type']
                ) ?> alert-dismissible fade show"
                role="alert"
            >
                <?= workflowH((string)$flash['message']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"
                ></button>
            </div>
        <?php endif; ?>

        <div class="page-intro">
            <i class="bi bi-calculator-fill"></i>

            <div>
                <strong>Daily closing formula</strong>
                <p>
                    Beginning stock + delivery − consumed quantity −
                    pull-out = computed ending stock.
                </p>
            </div>
        </div>

        <section class="content-card">
            <div class="content-card-header">
                <div>
                    <h2>
                        <span class="section-icon">
                            <i class="bi bi-clipboard-check-fill"></i>
                        </span>
                        Submit End-of-Shift Count
                    </h2>

                    <p>
                        Record the physical count for one consumable item, or
                        <a href="Nurse_DailyInventorySheet.php?date=<?= workflowH($formDate) ?>">open the whole-day sheet</a>
                        to enter all items at once.
                    </p>
                </div>
            </div>

            <div class="content-card-body">
                <form method="post" class="row g-3" id="dailyClosingForm">
                    <input type="hidden" name="closing_context_token" id="closing_context_token" value="">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= workflowH($csrf) ?>"
                    >

                    <div class="col-xl-4 col-lg-6">
                        <label
                            class="form-label required"
                            for="item_id"
                        >
                            Item
                        </label>

                        <select
                            class="form-select"
                            id="item_id"
                            name="item_id"
                            required
                        >
                            <option value="">Select an item</option>

                            <?php foreach ($items as $item): ?>
                                <option
                                    value="<?= (int)$item['item_id'] ?>"
                                    data-stock="<?= workflowH(
                                        (string)$item['current_stock']
                                    ) ?>"
                                    data-stock-display="<?= workflowH(
                                        (string)$item['stock_display']
                                    ) ?>"
                                    data-base-unit="<?= workflowH(
                                        (string)$item['base_unit_label']
                                    ) ?>"
                                    data-display-unit="<?= workflowH(
                                        (string)$item['display_unit_label']
                                    ) ?>"
                                    data-conversion="<?= workflowH(
                                        (string)$item['conversion_to_base']
                                    ) ?>"
                                    data-step="<?= workflowH(
                                        (string)$item['input_step']
                                    ) ?>"
                                >
                                    <?= workflowH(
                                        $item['item_name'] .
                                        ' | Stock: ' .
                                        $item['stock_display']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-xl-2 col-lg-3 col-md-6">
                        <label
                            class="form-label required"
                            for="inventory_date"
                        >
                            Inventory Date
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            id="inventory_date"
                            name="inventory_date"
                            value="<?= workflowH($formDate) ?>"
                            max="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <div id="openingBalanceHelp" class="alert alert-info py-2 px-3 mb-0" role="status">
                            Select an item and date. Beginning stock is the opening count, not the current remaining stock.
                        </div>
                    </div>

                    <div class="col-xl-2 col-lg-3 col-md-6">
                        <label
                            class="form-label required"
                            for="beginning_stock"
                        >
                            Beginning Stock
                            <span class="inventory-unit-label"></span>
                        </label>

                        <input
                            type="number"
                            step="0.0001"
                            min="0"
                            class="form-control inventory-number"
                            id="beginning_stock"
                            name="beginning_stock"
                            required
                        >
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label" for="delivery">
                            Delivery
                            <span class="inventory-unit-label"></span>
                        </label>

                        <input
                            type="number"
                            step="0.0001"
                            min="0"
                            value="0"
                            class="form-control inventory-number"
                            id="delivery"
                            name="delivery"
                        >
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label" for="consumed_auto">
                            Consumed (Auto)
                            <span class="inventory-unit-label"></span>
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="consumed_auto"
                            value="0"
                            readonly
                        >

                        <small class="text-muted">
                            From completed vaccination / usage records.
                        </small>
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label" for="extra_consumed">
                            Add Consumed
                            <span class="inventory-unit-label"></span>
                        </label>

                        <input
                            type="number"
                            step="0.0001"
                            min="0"
                            class="form-control inventory-number"
                            id="extra_consumed"
                            name="extra_consumed"
                            placeholder="0"
                        >

                        <small class="text-muted">
                            Sold or given without a vaccination record (e.g. Mefenamic). Recorded as usage and deducted from stock once.
                        </small>
                    </div>

                    <div class="col-xl-4 col-md-6" id="extraConsumedRemarksBox">
                        <label class="form-label" for="extra_consumed_remarks">Remark for Added Consumed</label>
                        <input
                            type="text"
                            class="form-control"
                            id="extra_consumed_remarks"
                            name="extra_consumed_remarks"
                            maxlength="500"
                            placeholder="Required when Add Consumed is entered"
                        >
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label" for="pull_out">
                            Pull-out
                            <span class="inventory-unit-label"></span>
                        </label>

                        <input
                            type="number"
                            step="0.0001"
                            min="0"
                            value="0"
                            class="form-control inventory-number"
                            id="pull_out"
                            name="pull_out"
                        >
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label
                            class="form-label required"
                            for="actual_count"
                        >
                            Actual Physical Count
                            <span class="inventory-unit-label"></span>
                        </label>

                        <div class="input-group">
                            <input
                                type="number"
                                step="0.0001"
                                min="0"
                                class="form-control inventory-number"
                                id="actual_count"
                                name="actual_count"
                                required
                            >

                            <button
                                class="btn btn-soft-primary"
                                type="button"
                                id="useComputedBtn"
                                title="Use this only when the physical count confirms the computed ending."
                            >
                                Use Computed
                            </button>
                        </div>

                        <small class="text-muted">
                            Actual should come from the physical count.
                            Use Computed only when the tally matches.
                        </small>
                    </div>

                    <div class="col-lg-7 col-md-6">
                        <label class="form-label" for="remarks">
                            Remarks / Adjustment Note
                        </label>

                        <input
                            class="form-control"
                            id="remarks"
                            name="remarks"
                            maxlength="500"
                            placeholder="Explain a variance, correction, adjustment, or pull-out"
                        >
                    </div>

                    <div class="col-lg-2 d-flex align-items-end">
                        <button
                            class="btn btn-primary w-100"
                            type="submit"
                            id="submitClosingBtn"
                            disabled
                        >
                            <i class="bi bi-send-check-fill me-1"></i>
                            Submit
                        </button>
                    </div>

                    <div class="col-12">
                        <input
                            type="hidden"
                            id="consumed_numeric"
                            value="0"
                        >

                        <div class="closing-preview">
                            <div class="preview-box">
                                <span class="label">
                                    Computed Ending
                                </span>
                                <span
                                    class="value"
                                    id="computedPreview"
                                >
                                    0
                                </span>
                            </div>

                            <div
                                class="preview-box"
                                id="actualPreviewBox"
                            >
                                <span class="label">
                                    Actual Count
                                </span>
                                <span
                                    class="value"
                                    id="actualPreview"
                                >
                                    -
                                </span>
                            </div>

                            <div
                                class="preview-box"
                                id="variancePreviewBox"
                            >
                                <span class="label">
                                    Tally Status
                                </span>
                                <span
                                    class="value"
                                    id="variancePreview"
                                >
                                    Enter actual count
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div
                            id="unitConversionHelp"
                            class="alert alert-info py-2 px-3 mb-0"
                            style="display:none"
                        ></div>
                    </div>

                    <div class="col-12 small-help">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>
                            All numbers on this form use the selected
                            item's base unit. Consumed quantity is read
                            automatically from completed Nurse Vaccination
                            and other recorded supply usage for the
                            selected date; it is not manually entered here.
                            Verify opening stock, delivery and pull-out for that day.
                            A closing records the reconciliation; it does not change
                            stored stock. Record stock corrections in Stock Management.
                        </span>
                    </div>
                </form>
            </div>
        </section>

        <section class="content-card mb-0">
            <div class="content-card-header">
                <div>
                    <h2>
                        <span class="section-icon">
                            <i class="bi bi-clock-history"></i>
                        </span>
                        Closing History
                    </h2>

                    <p>
                        Daily closings are grouped by date. Default view
                        is today; use the filters to review earlier days.
                    </p>
                </div>

                <form method="get" class="history-tools">
                    <div class="filter-block">
                        <label class="form-label" for="range">
                            Quick Filter
                        </label>

                        <select
                            class="form-select"
                            id="range"
                            name="range"
                        >
                            <option
                                value="today"
                                <?= $historyRange === 'today'
                                    ? 'selected' : '' ?>
                            >
                                Today
                            </option>

                            <option
                                value="7"
                                <?= $historyRange === '7'
                                    ? 'selected' : '' ?>
                            >
                                Last 7 Days
                            </option>

                            <option
                                value="30"
                                <?= $historyRange === '30'
                                    ? 'selected' : '' ?>
                            >
                                Last 30 Days
                            </option>

                            <option
                                value="all"
                                <?= $historyRange === 'all'
                                    ? 'selected' : '' ?>
                            >
                                All History
                            </option>
                        </select>
                    </div>

                    <div class="filter-block date-filter">
                        <label class="form-label" for="history_date">
                            Specific Day
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            id="history_date"
                            name="history_date"
                            value="<?= workflowH($historyDate) ?>"
                            max="<?= date('Y-m-d') ?>"
                        >
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>
                        Apply
                    </button>

                    <a
                        href="Nurse_DailyInventory.php"
                        class="btn btn-light border"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Today
                    </a>
                </form>
            </div>

            <?php if (!$closingsByDate): ?>
                <div class="history-empty">
                    <i
                        class="bi bi-inbox d-block mb-2"
                        style="font-size:34px;color:#c0c7d5;"
                    ></i>

                    No submitted daily inventory closings found for
                    <strong>
                        <?= workflowH($historyLabel) ?>
                    </strong>.
                </div>
            <?php endif; ?>

            <?php foreach ($closingsByDate as $closingDate => $dailyRows): ?>
                <?php
                $dayTallied = 0;
                $dayVariance = 0;

                foreach ($dailyRows as $dailyRow) {
                    if (abs((float)$dailyRow['variance']) < 0.00005) {
                        $dayTallied++;
                    } else {
                        $dayVariance++;
                    }
                }
                ?>

                <div class="day-closing">
                    <div class="day-closing-header">
                        <div class="day-closing-title">
                            <span class="date-icon">
                                <i class="bi bi-calendar-check-fill"></i>
                            </span>

                            <div>
                                <strong>
                                    <?= workflowH(
                                        date(
                                            'l, F d, Y',
                                            strtotime($closingDate)
                                        )
                                    ) ?>
                                </strong>

                                <small>
                                    Daily closing report for
                                    <?= workflowH(
                                        (string)(
                                            $user['branch_name'] ??
                                            $branchId
                                        )
                                    ) ?>
                                </small>
                            </div>
                        </div>

                        <div class="day-summary">
                            <span class="summary-pill total">
                                <i class="bi bi-box-seam"></i>
                                <?= count($dailyRows) ?>
                                item<?= count($dailyRows) === 1
                                    ? '' : 's' ?>
                            </span>

                            <span class="summary-pill tallied">
                                <i class="bi bi-check-circle-fill"></i>
                                <?= $dayTallied ?> tallied
                            </span>

                            <?php if ($dayVariance > 0): ?>
                                <span class="summary-pill variance">
                                    <i
                                        class="bi bi-exclamation-circle-fill"
                                    ></i>
                                    <?= $dayVariance ?> variance
                                </span>
                            <?php endif; ?>

                            <a
                                class="btn btn-sm btn-outline-danger"
                                href="Nurse_DailyInventory.php?export=pdf&amp;date=<?= workflowH($closingDate) ?>"
                            >
                                <i
                                    class="bi bi-file-earmark-pdf-fill me-1"
                                ></i>
                                Export PDF
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table inventory-table align-middle">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Beginning</th>
                                    <th>Delivery</th>
                                    <th>Consumed</th>
                                    <th>Pull-out</th>
                                    <th>Computed</th>
                                    <th>Actual</th>
                                    <th>Variance</th>
                                    <th>Result</th>
                                    <th>Submitted By</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($dailyRows as $closing): ?>
                                <?php
                                $hasVariance =
                                    abs((float)$closing['variance']) >= 0.00005;
                                ?>

                                <tr>
                                    <td class="item-name">
                                        <?= workflowH(
                                            (string)$closing['item_name']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            inventoryStockBreakdown(
                                                (float)$closing['beginning_stock'],
                                                $closing
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            inventoryStockBreakdown(
                                                (float)$closing['delivery'],
                                                $closing
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            inventoryStockBreakdown(
                                                (float)$closing['consumed'],
                                                $closing
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            inventoryStockBreakdown(
                                                (float)$closing['pull_out'],
                                                $closing
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            inventoryStockBreakdown(
                                                (float)$closing['computed_ending'],
                                                $closing
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            inventoryStockBreakdown(
                                                (float)$closing['actual_count'],
                                                $closing
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <span
                                            class="variance-badge <?= $hasVariance
                                                ? 'difference' : 'match' ?>"
                                        >
                                            <?= workflowH(
                                                inventoryUsageDescription(
                                                    (float)$closing['variance'],
                                                    $closing
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if ($closing['status'] === 'Draft'): ?>
                                            <span class="result-badge variance">
                                                Resubmission required
                                            </span>

                                            <div class="small text-danger mt-1">
                                                Usage changed after closing.
                                                Select this item and date above
                                                and submit updated counts.
                                            </div>
                                        <?php else: ?>
                                            <span
                                                class="result-badge <?= $hasVariance
                                                    ? 'variance' : 'tallied' ?>"
                                            >
                                                <i
                                                    class="bi <?= $hasVariance
                                                        ? 'bi-exclamation-triangle-fill'
                                                        : 'bi-check-circle-fill' ?>"
                                                ></i>

                                                <?= $hasVariance
                                                    ? 'Needs review'
                                                    : 'Tallied' ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (
                                            $hasVariance &&
                                            trim(
                                                (string)(
                                                    $closing['remarks'] ?? ''
                                                )
                                            ) !== ''
                                        ): ?>
                                            <div
                                                class="small text-muted mt-1"
                                                title="<?= workflowH(
                                                    (string)$closing['remarks']
                                                ) ?>"
                                            >
                                                Adjustment note recorded
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= workflowH(
                                            (string)$closing['username']
                                        ) ?>
                                    </td>
                                </tr>

                                <?php if (
                                    trim(
                                        (string)($closing['remarks'] ?? '')
                                    ) !== ''
                                ): ?>
                                    <tr>
                                        <td
                                            colspan="10"
                                            class="py-2 px-3 bg-light text-muted small"
                                        >
                                            <i
                                                class="bi bi-chat-left-text me-1"
                                            ></i>

                                            <strong>
                                                <?= workflowH(
                                                    (string)$closing['item_name']
                                                ) ?> note:
                                            </strong>

                                            <?= workflowH(
                                                (string)$closing['remarks']
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <div
        class="modal fade confirm-modal"
        id="logoutConfirmModal"
        tabindex="-1"
        aria-labelledby="logoutConfirmModalLabel"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-icon logout-icon">
                        <i class="bi bi-box-arrow-right"></i>
                    </div>

                    <h2
                        class="modal-title"
                        id="logoutConfirmModalLabel"
                    >
                        Log out of Smart Bite Care?
                    </h2>
                </div>

                <div class="modal-body">
                    <p class="mb-0">
                        Make sure you have saved any unfinished work
                        before leaving your account.
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
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="sources/sidebar.js"></script>

<script>
const initialDailyForm = <?= json_encode(['item_id' => $formItemId, 'inventory_date' => $formDate, 'restore' => $formRestore], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let dailyContextSequence = 0;
let dailyContextController = null;
let dailyContextReady = false;

function selectedBaseUnit() {
    const select = document.getElementById('item_id');
    return select?.options[select.selectedIndex]?.dataset.baseUnit || 'unit';
}

// Use integer ten-thousandths, matching server-side DECIMAL(12,4) arithmetic.
function dailyScaled(value) {
    if (typeof value !== 'string' || !/^(?:[0-9]+(?:\.[0-9]{1,4})?|\.[0-9]{1,4})$/.test(value)) return null;
    const parts = value.split('.');
    const whole = Number(parts[0] || '0');
    const fraction = Number((parts[1] || '').padEnd(4, '0'));
    const scaled = whole * 10000 + fraction;
    return Number.isSafeInteger(scaled) && scaled <= 999999999999 ? scaled : null;
}

function dailyDecimal(scaled) {
    const magnitude = Math.abs(scaled);
    return (scaled < 0 ? '-' : '') + Math.floor(magnitude / 10000)
        + '.' + String(magnitude % 10000).padStart(4, '0');
}

function formatClosingNumber(scaled) {
    return (scaled / 10000).toLocaleString(undefined, {maximumFractionDigits: 4});
}

function dailyComputed() {
    const fields = ['beginning_stock', 'delivery', 'consumed_numeric', 'pull_out'];
    const numbers = fields.map(id => dailyScaled(document.getElementById(id)?.value ?? ''));
    // Optional consumed entered without a vaccination record (blank = 0).
    const extraValue = document.getElementById('extra_consumed')?.value ?? '';
    const extra = extraValue === '' ? 0 : dailyScaled(extraValue);
    return numbers.includes(null) || extra === null ? null : numbers[0] + numbers[1] - numbers[2] - extra - numbers[3];
}

function refreshClosingPreview() {
    const computed = dailyContextReady ? dailyComputed() : null;
    const actual = dailyScaled(document.getElementById('actual_count')?.value ?? '');
    const unit = selectedBaseUnit();
    const computedEl = document.getElementById('computedPreview');
    const actualEl = document.getElementById('actualPreview');
    const varianceEl = document.getElementById('variancePreview');
    const varianceBox = document.getElementById('variancePreviewBox');
    const actualBox = document.getElementById('actualPreviewBox');
    computedEl.textContent = computed === null ? 'Enter opening and movements' : `${formatClosingNumber(computed)} ${unit}`;
    actualEl.textContent = actual === null ? '-' : `${formatClosingNumber(actual)} ${unit}`;
    varianceBox.classList.remove('match', 'difference');
    actualBox.classList.remove('match', 'difference');
    document.getElementById('useComputedBtn').disabled = computed === null || computed < 0 || computed > 999999999999;
    document.getElementById('submitClosingBtn').disabled = !dailyContextReady || computed === null || computed < 0
        || computed > 999999999999 || actual === null;
    if (computed === null) {
        varianceEl.textContent = 'Enter opening and movements';
    } else if (computed < 0 || computed > 999999999999) {
        varianceEl.textContent = 'Check opening stock and movements';
        varianceBox.classList.add('difference');
    } else if (actual === null) {
        varianceEl.textContent = 'Enter actual physical count';
    } else {
        const variance = actual - computed;
        varianceEl.textContent = variance === 0 ? 'Tallied - actual matches computed' : `Variance: ${formatClosingNumber(variance)} ${unit}`;
        varianceBox.classList.add(variance === 0 ? 'match' : 'difference');
        actualBox.classList.add(variance === 0 ? 'match' : 'difference');
    }
}

function resetDailyFields() {
    for (const id of ['beginning_stock', 'actual_count', 'remarks', 'extra_consumed', 'extra_consumed_remarks']) document.getElementById(id).value = '';
    for (const id of ['delivery', 'pull_out']) document.getElementById(id).value = '0';
    document.getElementById('consumed_numeric').value = '0';
    document.getElementById('closing_context_token').value = '';
}

async function refreshAutomaticConsumption(restore = null) {
    const sequence = ++dailyContextSequence;
    dailyContextController?.abort();
    dailyContextController = new AbortController();
    dailyContextReady = false;
    resetDailyFields();
    const select = document.getElementById('item_id');
    const dateInput = document.getElementById('inventory_date');
    const consumedInput = document.getElementById('consumed_auto');
    const help = document.getElementById('openingBalanceHelp');
    const unitHelp = document.getElementById('unitConversionHelp');
    const option = select.options[select.selectedIndex];
    const selected = select.value;
    const date = dateInput.value;
    const baseUnit = selectedBaseUnit();
    document.querySelectorAll('.inventory-unit-label').forEach(el => { el.textContent = selected ? `(${baseUnit})` : ''; });
    document.querySelectorAll('.inventory-number').forEach(el => { el.step = '0.0001'; el.disabled = true; });
    document.getElementById('remarks').disabled = true;
    document.getElementById('extra_consumed_remarks').disabled = true;
    consumedInput.value = selected && date ? 'Loading...' : '0';
    help.textContent = selected && date ? 'Loading the selected day...' : 'Select an item and date. Beginning stock is the opening count, not the current remaining stock.';
    unitHelp.style.display = 'none';
    refreshClosingPreview();
    if (!selected || !date) return;
    try {
        const params = new URLSearchParams({ajax: 'daily_usage', item_id: selected, date});
        const response = await fetch(`Nurse_DailyInventory.php?${params}`, {
            headers: {Accept: 'application/json'}, cache: 'no-store', signal: dailyContextController.signal
        });
        const data = await response.json();
        if (sequence !== dailyContextSequence) return;
        if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load the selected day.');
        consumedInput.value = data.display || `${data.consumed} ${baseUnit}`;
        document.getElementById('consumed_numeric').value = data.consumed;
        for (const [id, value] of Object.entries(data.fields)) document.getElementById(id).value = value;
        document.getElementById('closing_context_token').value = data.context_token;
        help.textContent = data.opening_message;
        if (restore && restore.item_id === selected && restore.inventory_date === date) {
            for (const id of ['beginning_stock', 'delivery', 'pull_out', 'remarks', 'extra_consumed', 'extra_consumed_remarks']) {
                if (typeof restore[id] === 'string') document.getElementById(id).value = restore[id];
            }
            if (!data.needs_physical_count && typeof restore.actual_count === 'string') document.getElementById('actual_count').value = restore.actual_count;
            help.textContent += ' Your entered values were restored. Verify them before resubmitting.';
        }
        const displayUnit = option.dataset.displayUnit || baseUnit;
        const conversion = Number(option.dataset.conversion || 1);
        unitHelp.textContent = `Current usable stock now: ${data.current_stock_display}. All counts use ${baseUnit}.`
            + (conversion > 1 && baseUnit.toLowerCase() !== displayUnit.toLowerCase() ? ` 1 ${displayUnit} = ${conversion} ${baseUnit}.` : '')
            + ' This live stock is a reference, not the opening count for the selected date.';
        unitHelp.style.display = 'block';
        document.querySelectorAll('.inventory-number').forEach(el => { el.disabled = false; });
        document.getElementById('remarks').disabled = false;
        document.getElementById('extra_consumed_remarks').disabled = false;
        dailyContextReady = true;
        refreshClosingPreview();
    } catch (error) {
        if (sequence !== dailyContextSequence || error.name === 'AbortError') return;
        consumedInput.value = 'Unavailable';
        help.textContent = error.message || 'Unable to load daily inventory. Refresh and try again.';
        document.getElementById('closing_context_token').value = '';
        refreshClosingPreview();
    }
}

document.getElementById('item_id').addEventListener('change', () => refreshAutomaticConsumption());
document.getElementById('inventory_date').addEventListener('change', () => refreshAutomaticConsumption());
for (const id of ['beginning_stock', 'delivery', 'pull_out', 'actual_count', 'extra_consumed']) {
    document.getElementById(id).addEventListener('input', refreshClosingPreview);
}
document.getElementById('useComputedBtn').addEventListener('click', () => {
    const computed = dailyContextReady ? dailyComputed() : null;
    if (computed === null || computed < 0 || computed > 999999999999) return;
    document.getElementById('actual_count').value = dailyDecimal(computed);
    refreshClosingPreview();
});
document.getElementById('dailyClosingForm').addEventListener('submit', event => {
    if (!dailyContextReady || document.getElementById('submitClosingBtn').disabled) {
        event.preventDefault();
        document.getElementById('openingBalanceHelp').textContent = 'Load the selected item and date, then verify the counts before submitting.';
    }
});
document.getElementById('item_id').value = initialDailyForm.item_id || '';
document.getElementById('inventory_date').value = initialDailyForm.inventory_date;
refreshAutomaticConsumption(initialDailyForm.restore);

</script>
</body>
</html>