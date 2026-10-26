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
require_once 'sources/forecast_auto_evaluation.php';
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

/**
 * After a closing is synced the training data revision increases: start the
 * automatic forecast re-evaluation in the background. Never blocks or fails the save.
 */
function dailyInventoryStartAutoEvaluation(mysqli $conn, string $branchId): void
{
    try {
        forecastAutoEvaluationTrigger($conn, $branchId, 'Daily closing saved');
    } catch (Throwable $e) {
        error_log('Automatic forecast evaluation trigger: ' . $e->getMessage());
    }
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
         INNER JOIN inventory_categories c ON c.category_id=i.category_id
         INNER JOIN users u ON u.user_id=d.submitted_by
         INNER JOIN units u2 ON u2.unit_id=i.unit_id
         WHERE d.branch_id=? AND d.inventory_date=? AND " . dailyInventoryItemSql('i', 'c') . "
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
    // Same read-only counts as the page and the training sync (check-ins and doses).
    $exportCounts = dailyInventoryDayCounts($conn, $branchId, $exportDate);
    $pdf->Cell(
        0,
        5,
        'Patients checked in: ' . (int)$exportCounts['patient_count'] .
            ' | Animal-bite cases: ' . (int)$exportCounts['animal_bite_cases'] .
            ' | Vaccine doses given: ' . (int)$exportCounts['vaccinations_administered'],
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
        $context = dailyInventoryContext($conn, $branchId, $itemId, $inventoryDate);
        // Read-only day counts (same numbers the closing sync saves); a later
        // check-in makes the saved closing out of date.
        $context['day_counts'] = dailyInventoryDayCounts($conn, $branchId, $inventoryDate);
        $savedCounts = dailyInventorySavedDayCounts($conn, $branchId, $inventoryDate)[$itemId] ?? null;
        $context['counts_out_of_date'] = $context['opening_source'] === 'saved_closing'
            && dailyInventoryCountsDiffer($savedCounts, $context['day_counts']);
        $context['saved_counts_text'] = $savedCounts !== null ? dailyInventoryCountsText($savedCounts) : '';
        $context['day_counts_text'] = dailyInventoryCountsText($context['day_counts']);
        echo json_encode($context, JSON_THROW_ON_ERROR);
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

// Save the whole day's closing at once ("Save Closing").
// The result (saved count, or every problem by item) is shown inside the sheet
// after the reload, and the page scrolls back to it (#dayClosing).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_day') {
    $dayDate = is_string($_POST['day_date'] ?? null) ? trim($_POST['day_date']) : '';
    $postedRows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
    $dayLabel = dailyInventoryValidDate($dayDate) ? date('M d, Y', strtotime($dayDate)) : 'this date';
    try {
        workflowVerifyCsrf();
        // PHP drops fields past max_input_vars without an error; never save a cut-off sheet.
        $postedFields = 0;
        array_walk_recursive($_POST, static function () use (&$postedFields): void { $postedFields++; });
        $maxFields = (int)ini_get('max_input_vars');
        if ($maxFields > 0 && $postedFields >= $maxFields) {
            throw new RuntimeException('The sheet sent more fields than the server accepts (max_input_vars = '
                . $maxFields . '), so some rows were cut off. Nothing was saved. Count fewer items per save, '
                . 'or ask the administrator to raise max_input_vars.');
        }
        $result = dailyInventorySaveDay($conn, $branchId, $userId, $dayDate, $postedRows);
        $savedCount = count($result['saved']);
        $details = [];
        if ($result['unchanged'] > 0) {
            $details[] = $result['unchanged'] . ' unchanged item(s) were already saved.';
        }
        $details[] = 'Consumed came from the Assessment, Vaccination and Medical Supplies usage of the day'
            . ' (each use counted once, plus any wastage adjustment).';
        if ($result['synced'] > 0) {
            $details[] = 'The forecasting dataset was updated for ' . $result['synced'] . ' forecastable item(s).';
        }
        $_SESSION['daily_inventory_day_result'] = [
            'date' => $dayDate,
            'type' => 'success',
            'title' => 'Closing for ' . $dayLabel . ' saved – ' . $savedCount . ' item' . ($savedCount === 1 ? '' : 's'),
            'details' => $details,
            'problems' => [],
        ];
        if ($result['synced'] > 0) {
            dailyInventoryStartAutoEvaluation($conn, $branchId);
        }
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        if (!$e instanceof RuntimeException) {
            error_log('Daily inventory save_day: ' . $e->getMessage());
        }
        $restoreRows = [];
        foreach ($postedRows as $rawId => $row) {
            if (is_array($row)) {
                $restoreRows[(string)$rawId] = array_map(static fn($v) => is_string($v) ? $v : '', $row);
            }
        }
        $stale = $e instanceof DailyInventoryDayException ? $e->staleItems : [];
        foreach ($stale as $staleId) {
            // Usage changed after loading: the new Consumed needs a fresh count.
            if (isset($restoreRows[(string)$staleId])) {
                $restoreRows[(string)$staleId]['actual_count'] = '';
                unset($restoreRows[(string)$staleId]['context_token']);
            }
        }
        $_SESSION['daily_inventory_day_restore'] = ['date' => $dayDate, 'rows' => $restoreRows];
        $problems = [];
        if ($e instanceof DailyInventoryDayException) {
            foreach ($e->errors as $key => $message) {
                $problems[] = ['item_id' => is_int($key) && $key > 0 ? $key : 0, 'message' => (string)$message];
            }
        } else {
            $problems[] = ['item_id' => 0, 'message' => $e instanceof RuntimeException
                ? $e->getMessage() : 'Unexpected error while saving. Nothing was saved; try again.'];
        }
        $_SESSION['daily_inventory_day_result'] = [
            'date' => $dayDate,
            'type' => 'danger',
            'title' => 'The closing for ' . $dayLabel . ' was not saved. Fix ' . (count($problems) === 1 ? 'this problem' : 'these ' . count($problems) . ' problems') . ':',
            'details' => [],
            'problems' => $problems,
        ];
    }
    $redirectDate = dailyInventoryValidDate($dayDate) && $dayDate <= date('Y-m-d') ? $dayDate : date('Y-m-d');
    header('Location: Nurse_DailyInventory.php?' . http_build_query(['date' => $redirectDate]) . '#dayClosing');
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
                is_string($_POST['wastage_quantity'] ?? null) ? $_POST['wastage_quantity'] : '',
                is_string($_POST['wastage_reason'] ?? null) ? $_POST['wastage_reason'] : '',
                is_string($_POST['wastage_other'] ?? null) ? $_POST['wastage_other'] : ''
            );
        } catch (DailyInventoryStaleContextException $stale) {
            $needsNewCount = true;
            throw $stale;
        }
        $trainingSynced = $saved['training_synced'];
        unset($_SESSION['daily_inventory_form']);
        if ($trainingSynced) {
            dailyInventoryStartAutoEvaluation($conn, $branchId);
        }

        workflowFlash(
            'success',
            'Daily inventory submitted. Consumed came from the Assessment, Vaccination and supply usage recorded for the day. ' .
                ($saved['manual_usage'] !== null
                    ? 'The adjustment (' . $saved['manual_usage']['description']
                      . ') was recorded as wastage, deducted from stock once and added to Consumed. '
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
                  'wastage_quantity', 'wastage_reason', 'wastage_other'] as $field) {
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

// Whole-day closing: every item used that day is listed with Consumed filled in.
$dayDate = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : $formDate;
if (!dailyInventoryValidDate($dayDate) || $dayDate > date('Y-m-d')) {
    $dayDate = date('Y-m-d');
}
$dayRestoreData = $_SESSION['daily_inventory_day_restore'] ?? null;
unset($_SESSION['daily_inventory_day_restore']);
$dayResultData = $_SESSION['daily_inventory_day_result'] ?? null;
unset($_SESSION['daily_inventory_day_result']);
$dayResult = is_array($dayResultData) && ($dayResultData['date'] ?? '') === $dayDate ? $dayResultData : null;
$dayProblemItems = [];
foreach ((array)($dayResult['problems'] ?? []) as $problem) {
    if ((int)($problem['item_id'] ?? 0) > 0) $dayProblemItems[(int)$problem['item_id']] = true;
}
$dayRestore = is_array($dayRestoreData) && ($dayRestoreData['date'] ?? '') === $dayDate ? (array)$dayRestoreData['rows'] : [];
$dayRows = dailyInventoryDayRows($conn, $branchId, $dayDate);
$dayActiveCount = count(array_filter($dayRows, static fn(array $row): bool => $row['active']));
$dayOutOfDate = array_values(array_filter($dayRows, static fn(array $row): bool => !empty($row['context']['closing_out_of_date'])
    && empty($row['context']['counts_out_of_date'])));
// Closings saved before a later check-in / dose: the saved patient counts are out of date.
$dayCountsOutOfDate = array_values(array_filter($dayRows, static fn(array $row): bool => !empty($row['context']['counts_out_of_date'])));
// Read-only day counts: exactly what the daily closing sync saves (forecastTrainingDayCounts).
$dayCounts = dailyInventoryDayCounts($conn, $branchId, $dayDate);
$dayShowAll = isset($_GET['all']) || array_filter($dayRows, static fn(array $row): bool =>
        !$row['active'] && isset($dayProblemItems[(int)$row['item']['item_id']]))
    || ($dayRestore && array_filter($dayRows, static fn(array $row): bool =>
    !$row['active'] && trim((string)($dayRestore[(string)$row['item']['item_id']]['actual_count'] ?? '')) !== ''));
$dayOpeningLabels = [
    'saved_closing' => 'Saved closing',
    'previous_day' => "Yesterday's Actual",
    'previous_computed' => "Yesterday's Inv. End",
    'system_stock' => 'From system stock',
    'manual' => 'Enter the opening count',
];
// Restored entries win over the loaded values (the context token is always fresh).
$dayValue = static function (array $row, string $field) use ($dayRestore): string {
    $restored = $dayRestore[(string)$row['item']['item_id']] ?? null;
    if (is_array($restored) && array_key_exists($field, $restored)) {
        return (string)$restored[$field];
    }
    if (in_array($field, ['wastage_quantity', 'wastage_reason', 'wastage_other'], true)) {
        return '';
    }
    return (string)($row['context']['fields'][$field] ?? '');
};
// Quantity entry in the clinic's units: vials + sites (or mL) for vaccines and
// immunoglobulins, a single box otherwise. The hidden field carries the base unit.
$dayQtyInput = static function (string $name, string $field, string $baseValue, array $item, bool $required = false): string {
    $split = inventoryHasDisplayUnit($item);
    $conversion = inventoryConversionToBase($item);
    $remStep = inventoryIsSiteBased($item) ? '1' : '0.0001';
    $html = '<div class="qty-group" data-field="' . workflowH($field) . '" data-conv="' . workflowH($split ? rtrim(rtrim(number_format($conversion, 4, '.', ''), '0'), '.') : '1') . '" data-split="' . ($split ? '1' : '0') . '">';
    if ($split) {
        $html .= '<input type="number" class="form-control q-main" min="0" step="1" inputmode="numeric" aria-label="' . workflowH(inventoryUnitWord(inventoryDisplayUnitLabel($item), 2)) . '"' . ($required ? ' required' : '') . '>'
            . '<span class="q-unit">' . workflowH(inventoryUnitWord(inventoryDisplayUnitLabel($item), 2)) . '</span>'
            . '<input type="number" class="form-control q-rem" min="0" step="' . $remStep . '" aria-label="' . workflowH(inventoryUnitWord(inventoryBaseUnitLabel($item), 2)) . '">'
            . '<span class="q-unit">' . workflowH(inventoryUnitWord(inventoryBaseUnitLabel($item), 2)) . '</span>';
    } else {
        $unit = $conversion === 1.0 ? inventoryDisplayUnitLabel($item) : inventoryBaseUnitLabel($item);
        $html .= '<input type="number" class="form-control q-main" min="0" step="0.0001"' . ($required ? ' required' : '') . '>'
            . '<span class="q-unit">' . workflowH(inventoryUnitWord($unit, 2)) . '</span>';
    }
    return $html . '<input type="hidden" class="q-base" name="' . workflowH($name . '[' . $field . ']') . '" value="' . workflowH($baseValue) . '"></div>';
};

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
     INNER JOIN inventory_categories c ON c.category_id=i.category_id
     LEFT JOIN ($stockTotalsSql) s
        ON s.item_id=i.item_id AND s.branch_id=?
     WHERE " . dailyInventoryItemSql('i', 'c') . "
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
     INNER JOIN inventory_categories c ON c.category_id=i.category_id
     INNER JOIN users u ON u.user_id=d.submitted_by
     INNER JOIN units u2 ON u2.unit_id=i.unit_id
     WHERE d.branch_id=? AND " . dailyInventoryItemSql('i', 'c');

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

        .summary-pill.counts {
            background: #eef2ff;
            color: #2b3a8c;
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

        /* Whole-day closing */
        .day-table-wrap { max-height: 75vh; }
        .day-table { min-width: 1350px; font-size: 13px; }
        .day-table thead th { position: sticky; top: 0; z-index: 2; padding: 11px 10px; color: #fff; background: var(--primary);
            font-size: 11px; text-transform: uppercase; letter-spacing: .25px; white-space: nowrap; }
        .day-table td { padding: 10px; vertical-align: top; border-color: #edf0f5; }
        .day-item { min-width: 190px; }
        .day-item .item-name { display: block; font-weight: 700; color: #25324b; }
        .day-note { display: block; margin-top: 3px; color: #8b95a7; font-size: 11px; line-height: 1.3; }
        .day-note.filled { color: #1d4ed8; }
        .day-consumed { min-width: 200px; }
        .day-consumed .consumed-total { color: #25324b; }
        .qty-group { display: flex; align-items: center; gap: 4px; flex-wrap: nowrap; }
        .qty-group .form-control { min-height: 34px; width: 74px; padding: 4px 6px; font-size: 13px; }
        .qty-group .q-unit { color: #64708b; font-size: 11px; white-space: nowrap; }
        .day-remarks { min-width: 170px; }
        .day-table .computed { font-weight: 700; color: #25324b; white-space: nowrap; }
        .day-divider td { background: #f3f5fa; color: #64708b; font-size: 12px; font-weight: 600; }
        .row-saved { background: #f3fbf5; }
        .row-stale { background: #fff8e6; }
        .day-row.row-problem > td { background: #fdecee; }
        .day-counts { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; padding: 10px 14px; border: 1px solid #dfe5f3; border-radius: 12px; background: #f6f8fd; font-size: 14px; color: #33406a; }
        .day-counts .day-count i { color: #2b3a8c; margin-right: 6px; }
        .day-counts .day-count-note { flex-basis: 100%; font-size: 12px; color: #6b7699; }
        .day-row.row-problem .item-name { color: #b02a37; }
        .variance-ok { color: #1f7a35; font-weight: 700; }
        .variance-bad { color: #b42318; font-weight: 700; }
        #dayClosing:not(.show-all) .day-extra { display: none; }
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
                    Inv. End = Beginning + Delivery − Consumed − Pull-out. Variance = Actual − Inv. End.
                </p>
            </div>
        </div>

        <section class="content-card" id="dayClosing">
            <div class="content-card-header flex-wrap">
                <div>
                    <h2>
                        <span class="section-icon">
                            <i class="bi bi-table"></i>
                        </span>
                        Daily Closing
                    </h2>
                    <p>
                        Every item used on this date is listed with Consumed already filled in from the
                        Assessment, Vaccination and Medical Supplies records (each use counted once; doses given at
                        another clinic never count). Enter the physical count in <strong>Actual</strong>, then
                        <strong>Save Closing</strong>.
                    </p>
                </div>
                <form method="get" class="d-flex align-items-end gap-2">
                    <div>
                        <label class="form-label" for="day_date">Inventory date</label>
                        <input type="date" class="form-control" id="day_date" name="date"
                               value="<?= workflowH($dayDate) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Load</button>
                </form>
            </div>

            <div class="day-counts mx-4 mt-3" id="dayCounts" aria-label="Activity on this date (from the check-in and vaccination records)">
                <span class="day-count"><i class="bi bi-person-check-fill"></i>Patients checked in <?= $dayDate === date('Y-m-d') ? 'today' : 'on ' . workflowH(date('M d, Y', strtotime($dayDate))) ?>: <strong data-count="patient_count"><?= (int)$dayCounts['patient_count'] ?></strong></span>
                <span class="day-count"><i class="bi bi-clipboard2-pulse-fill"></i>Animal-bite cases: <strong data-count="animal_bite_cases"><?= (int)$dayCounts['animal_bite_cases'] ?></strong></span>
                <span class="day-count"><i class="bi bi-eyedropper"></i>Vaccine doses given: <strong data-count="vaccinations_administered"><?= (int)$dayCounts['vaccinations_administered'] ?></strong></span>
                <span class="day-count-note">Read-only, from Visit Check-in and vaccination records; saved with the closing for forecasting.</span>
            </div>

            <?php if ($dayCountsOutOfDate): ?>
                <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mx-4 mt-3 mb-0" id="dayCountsOutOfDateBox">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="flex-grow-1">
                        The closing is out of date: patients were checked in (or doses given) after it was saved.
                        Saved: <?= workflowH(dailyInventoryCountsText($dayCountsOutOfDate[0]['context']['saved_counts'])) ?>;
                        now: <?= workflowH(dailyInventoryCountsText($dayCounts)) ?>.
                        Click <strong>Save Closing</strong> to refresh it (the same closing is updated; nothing is added).
                    </span>
                    <a class="btn btn-sm btn-warning" href="Nurse_DailyInventory.php?<?= workflowH(http_build_query(['date' => $dayDate])) ?>">
                        <i class="bi bi-arrow-repeat me-1"></i>Refresh
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($dayOutOfDate): ?>
                <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mx-4 mt-3 mb-0" id="dayOutOfDateBox">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="flex-grow-1">
                        The closing is out of date for
                        <?= workflowH(implode(', ', array_map(static fn(array $row): string => trim((string)$row['item']['item_name']), $dayOutOfDate))) ?>:
                        usage was recorded after it was saved. Consumed below shows the current total. Verify the
                        count and save again.
                    </span>
                    <a class="btn btn-sm btn-warning" href="Nurse_DailyInventory.php?<?= workflowH(http_build_query(['date' => $dayDate])) ?>">
                        <i class="bi bi-arrow-repeat me-1"></i>Refresh
                    </a>
                </div>
            <?php endif; ?>

            <!-- novalidate: the browser's own number checks blocked the submit without
                 any message when the invalid field was in a hidden row. The script below
                 and the server check every value and list the problems instead. -->
            <form method="post" id="dayClosingForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                <input type="hidden" name="action" value="save_day">
                <input type="hidden" name="day_date" value="<?= workflowH($dayDate) ?>">

                <?php if ($dayResult): ?>
                    <div class="alert alert-<?= $dayResult['type'] === 'success' ? 'success' : 'danger' ?> mx-4 mt-3 mb-0" id="dayResult" role="<?= $dayResult['type'] === 'success' ? 'status' : 'alert' ?>" tabindex="-1">
                        <div class="fw-semibold">
                            <i class="bi <?= $dayResult['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-1"></i>
                            <?= workflowH((string)$dayResult['title']) ?>
                        </div>
                        <?php if ($dayResult['problems']): ?>
                            <ul class="mb-0 mt-2 ps-3">
                                <?php foreach ($dayResult['problems'] as $problem): ?>
                                    <li>
                                        <?php if ((int)$problem['item_id'] > 0): ?>
                                            <a href="#" class="alert-link day-problem-link" data-item-id="<?= (int)$problem['item_id'] ?>"><?= workflowH((string)$problem['message']) ?></a>
                                        <?php else: ?>
                                            <?= workflowH((string)$problem['message']) ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php foreach ($dayResult['details'] as $detail): ?>
                            <div class="small mt-1"><?= workflowH((string)$detail) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="alert alert-danger mx-4 mt-3 mb-0 d-none" id="dayProblems" role="alert" tabindex="-1">
                    <div class="fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i><span class="day-problems-title"></span></div>
                    <ul class="mb-0 mt-2 ps-3"></ul>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-4 pt-3">
                    <small class="text-muted">
                        <?= $dayActiveCount ?> item(s) with usage, movements or a saved closing on
                        <?= workflowH(date('M d, Y', strtotime($dayDate))) ?>.
                    </small>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="dayShowAll" <?= $dayShowAll ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="dayShowAll">Show all items (<?= count($dayRows) - $dayActiveCount ?> without activity)</label>
                    </div>
                </div>

                <div class="table-responsive day-table-wrap">
                    <table class="table day-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Beginning</th>
                                <th>Delivery</th>
                                <th>Consumed</th>
                                <th>Pull-out</th>
                                <th>Inv. End</th>
                                <th>Actual</th>
                                <th>Variance</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $dividerShown = false; ?>
                        <?php foreach ($dayRows as $row):
                            $item = $row['item'];
                            $context = $row['context'];
                            $id = (int)$item['item_id'];
                            $name = 'rows[' . $id . ']';
                            $source = (string)$context['opening_source'];
                            $outOfDate = !empty($context['closing_out_of_date']);
                            $itemName = trim((string)$item['item_name']);
                            $meta = [
                                'conv' => inventoryHasDisplayUnit($item) ? inventoryConversionToBase($item) : 1,
                                'display' => inventoryConversionToBase($item) === 1.0 ? inventoryDisplayUnitLabel($item) : inventoryBaseUnitLabel($item),
                                'big' => inventoryDisplayUnitLabel($item),
                                'base' => inventoryBaseUnitLabel($item),
                                'split' => inventoryHasDisplayUnit($item),
                            ];
                        ?>
                            <?php if (!$row['active'] && !$dividerShown): $dividerShown = true; ?>
                                <tr class="day-divider day-extra">
                                    <td colspan="9">Items without usage, movements or a saved closing on this date</td>
                                </tr>
                            <?php endif; ?>
                            <tr class="day-row <?= $row['active'] ? '' : 'day-extra' ?> <?= $row['saved'] ? 'row-saved' : '' ?> <?= $outOfDate ? 'row-stale' : '' ?> <?= isset($dayProblemItems[$id]) ? 'row-problem' : '' ?>"
                                data-item-id="<?= $id ?>"
                                data-needs-actual="<?= $row['used'] && !$row['saved'] ? '1' : '0' ?>"
                                data-item-name="<?= workflowH($itemName) ?>"
                                data-consumed="<?= workflowH((string)$context['consumed']) ?>"
                                data-unit='<?= workflowH(json_encode($meta)) ?>'
                                data-saved-actual="<?= workflowH((string)($context['closing_saved_actual'] ?? '')) ?>">
                                <td class="day-item">
                                    <span class="item-name"><?= workflowH($itemName) ?></span>
                                    <span class="day-note">
                                        <?php if ($meta['split']): ?>
                                            1 <?= workflowH(inventoryUnitWord($meta['big'], 1)) ?> = <?= workflowH(inventoryFormatNumber(inventoryConversionToBase($item))) ?> <?= workflowH(inventoryUnitWord($meta['base'], 2)) ?>
                                        <?php else: ?>
                                            <?= workflowH(inventoryUnitWord($meta['display'], 2)) ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($row['saved']): ?>
                                        <span class="badge text-bg-success mt-1">Saved</span>
                                    <?php elseif ($outOfDate): ?>
                                        <span class="badge text-bg-warning mt-1">Out of date</span>
                                    <?php elseif ($row['used']): ?>
                                        <span class="badge text-bg-primary mt-1">Used today</span>
                                    <?php endif; ?>
                                    <input type="hidden" name="<?= workflowH($name) ?>[context_token]" value="<?= workflowH((string)$context['context_token']) ?>">
                                    <input type="hidden" name="<?= workflowH($name) ?>[item_name]" value="<?= workflowH($itemName) ?>">
                                </td>
                                <td>
                                    <?= $dayQtyInput($name, 'beginning_stock', $dayValue($row, 'beginning_stock'), $item) ?>
                                    <span class="day-note <?= in_array($source, ['previous_day', 'previous_computed', 'system_stock'], true) ? 'filled' : '' ?>"
                                          title="<?= workflowH((string)$context['opening_message']) ?>">
                                        <?= workflowH($dayOpeningLabels[$source] ?? $source) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $dayQtyInput($name, 'delivery', $dayValue($row, 'delivery'), $item) ?>
                                    <?php if ($row['prefilled']['delivery']): ?><span class="day-note filled">From stock-in records</span><?php endif; ?>
                                </td>
                                <td class="day-consumed">
                                    <strong class="consumed-total"><?= workflowH(inventoryQuantityText((float)$context['consumed'], $item)) ?></strong>
                                    <span class="day-note"><?= workflowH((string)$context['consumed_breakdown']) ?></span>
                                    <span class="day-note adjust-summary text-danger"></span>
                                    <button type="button" class="btn btn-link btn-sm p-0 adjust-btn">
                                        <i class="bi bi-pencil-square me-1"></i>Edit / Adjust
                                    </button>
                                    <input type="hidden" class="w-qty" name="<?= workflowH($name) ?>[wastage_quantity]" value="<?= workflowH($dayValue($row, 'wastage_quantity')) ?>">
                                    <input type="hidden" class="w-reason" name="<?= workflowH($name) ?>[wastage_reason]" value="<?= workflowH($dayValue($row, 'wastage_reason')) ?>">
                                    <input type="hidden" class="w-other" name="<?= workflowH($name) ?>[wastage_other]" value="<?= workflowH($dayValue($row, 'wastage_other')) ?>">
                                </td>
                                <td>
                                    <?= $dayQtyInput($name, 'pull_out', $dayValue($row, 'pull_out'), $item) ?>
                                    <?php if ($row['prefilled']['pull_out']): ?><span class="day-note filled">From stock-out records</span><?php endif; ?>
                                </td>
                                <td class="computed" data-output="end">-</td>
                                <td>
                                    <?= $dayQtyInput($name, 'actual_count', $dayValue($row, 'actual_count'), $item, $row['used'] && !$row['saved']) ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 use-end">Use Inv. End</button>
                                    <?php if ($outOfDate && (string)($context['closing_saved_actual'] ?? '') !== ''): ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 d-block use-saved-actual">Reload saved Actual</button>
                                    <?php endif; ?>
                                </td>
                                <td data-output="variance">-</td>
                                <td><input type="text" class="form-control form-control-sm day-remarks" maxlength="500" name="<?= workflowH($name) ?>[remarks]" value="<?= workflowH($dayValue($row, 'remarks')) ?>" placeholder="Required if variance"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$dayRows): ?>
                            <tr><td colspan="9" class="text-center text-muted py-5">No consumable items are configured.</td></tr>
                        <?php elseif ($dayActiveCount === 0): ?>
                            <tr class="day-empty"><td colspan="9" class="text-center text-muted py-4">No usage, deliveries or pull-outs were recorded on this date. Use "Show all items" to count other items.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <small class="text-muted">
                        Consumed is read-only; use Edit / Adjust only for wastage (leakage, a broken vial/ampule, expired or
                        contaminated stock). Items used today need an Actual count. All rows are saved together; if one
                        needs attention, nothing is saved and the problems are listed.
                    </small>
                    <button type="submit" class="btn btn-primary" id="saveDayBtn">
                        <i class="bi bi-save-fill me-1"></i>Save Closing
                    </button>
                </div>
            </form>
        </section>

        <div class="modal fade" id="adjustModal" tabindex="-1" aria-labelledby="adjustModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="adjustModalTitle">Adjust Consumed (wastage)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            For leakage, a broken vial/ampule or expired/contaminated stock. The extra quantity lost is
                            recorded as wastage when you save the closing: it is deducted from stock once and added to
                            Consumed. Medicines given without a vaccination record go to Medical Supplies Management.
                        </p>
                        <div class="mb-2">
                            <span class="small text-muted d-block">Auto Consumed</span>
                            <strong id="adjustAuto">-</strong>
                        </div>
                        <label class="form-label small">Extra quantity lost</label>
                        <div id="adjustQtyHolder" class="mb-2"></div>
                        <label class="form-label small" for="adjustReason">Reason</label>
                        <select class="form-select mb-2" id="adjustReason">
                            <option value="">Select a reason</option>
                            <?php foreach (dailyInventoryWastageReasons() as $reason): ?>
                                <option value="<?= workflowH($reason) ?>"><?= workflowH($reason === 'Other' ? 'Other – specify' : $reason) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control mb-2" id="adjustOther" maxlength="200" placeholder="Specify the reason" style="display:none">
                        <div class="small">Final Consumed: <strong id="adjustFinal">-</strong></div>
                        <div class="small text-danger mt-1" id="adjustError"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light border me-auto" id="adjustRemove">Remove adjustment</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="adjustApply">Apply</button>
                    </div>
                </div>
            </div>
        </div>

        <section class="content-card">
            <div class="content-card-header">
                <div>
                    <h2>
                        <span class="section-icon">
                            <i class="bi bi-clipboard-check-fill"></i>
                        </span>
                        Correct One Item
                    </h2>

                    <p>
                        Optional: correct a single item's closing afterwards (same checks as the daily closing).
                    </p>
                </div>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse"
                        data-bs-target="#singleItemBody" aria-expanded="<?= $formRestore || $formItemId !== '' ? 'true' : 'false' ?>"
                        aria-controls="singleItemBody">
                    <i class="bi bi-pencil me-1"></i>Correct one item
                </button>
            </div>

            <div class="content-card-body collapse <?= $formRestore || $formItemId !== '' ? 'show' : '' ?>" id="singleItemBody">
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

                    <div class="col-xl-4 col-md-6">
                        <label class="form-label" for="consumed_auto">
                            Consumed (Auto)
                            <span class="inventory-unit-label"></span>
                        </label>

                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control"
                                id="consumed_auto"
                                value="0"
                                readonly
                            >
                            <button class="btn btn-outline-secondary" type="button" id="adjustConsumedBtn"
                                    aria-expanded="false" aria-controls="adjustConsumedPanel" disabled>
                                <i class="bi bi-pencil-square me-1"></i>Edit / Adjust
                            </button>
                        </div>

                        <small class="text-muted d-block">
                            Auto-computed from Assessment and Vaccination records (and Medical Supplies usage) for this date.
                        </small>
                        <small class="d-block text-primary" id="consumedBreakdown"></small>
                    </div>

                    <div class="col-12" id="singleDayCounts" style="display:none">
                        <div class="day-counts">
                            <span class="day-count"><i class="bi bi-person-check-fill"></i><span id="singlePatientsLabel">Patients checked in today</span>: <strong id="singlePatients">0</strong></span>
                            <span class="day-count"><i class="bi bi-clipboard2-pulse-fill"></i>Animal-bite cases: <strong id="singleCases">0</strong></span>
                            <span class="day-count"><i class="bi bi-eyedropper"></i>Vaccine doses given: <strong id="singleDoses">0</strong></span>
                            <span class="day-count-note">Read-only, from Visit Check-in and vaccination records; saved with the closing for forecasting.</span>
                        </div>
                    </div>

                    <div class="col-12" id="adjustConsumedPanel" style="display:none">
                        <div class="border rounded p-3 bg-light">
                            <strong class="d-block mb-1"><i class="bi bi-exclamation-octagon me-1"></i>Adjust Consumed (wastage)</strong>
                            <p class="small text-muted mb-2">
                                For leakage, a broken vial/ampule or another accident. The extra quantity lost is recorded as
                                wastage (separate from patient use), deducted from stock once and added to Consumed.
                                To record medicine dispensed without a vaccination record, use Medical Supplies Management.
                            </p>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small" for="wastage_quantity">Extra quantity lost <span class="inventory-unit-label"></span></label>
                                    <input type="number" step="0.0001" min="0" class="form-control inventory-number"
                                           id="wastage_quantity" name="wastage_quantity" placeholder="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small" for="wastage_reason">Reason</label>
                                    <select class="form-select" id="wastage_reason" name="wastage_reason">
                                        <option value="">Select a reason</option>
                                        <?php foreach (dailyInventoryWastageReasons() as $reason): ?>
                                            <option value="<?= workflowH($reason) ?>"><?= workflowH($reason === 'Other' ? 'Other (specify)' : $reason) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3" id="wastageOtherBox" style="display:none">
                                    <label class="form-label small" for="wastage_other">Specify reason</label>
                                    <input type="text" class="form-control" id="wastage_other" name="wastage_other" maxlength="200">
                                </div>
                                <div class="col-md-3">
                                    <span class="small text-muted d-block">Final Consumed</span>
                                    <strong id="finalConsumedPreview">-</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12" id="closingOutOfDateBox" style="display:none">
                        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mb-0 py-2">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <span id="closingOutOfDateText" class="flex-grow-1"></span>
                            <button type="button" class="btn btn-sm btn-warning" id="refreshClosingBtn">
                                <i class="bi bi-arrow-repeat me-1"></i>Refresh closing
                            </button>
                        </div>
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
                            item's base unit. Consumed is read automatically
                            from the Assessment and Vaccination records (and
                            Medical Supplies usage) of the selected date, each
                            use counted once; doses given at another clinic are
                            not counted. Use Edit / Adjust only for wastage.
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
                $historyCounts = dailyInventoryDayCounts($conn, $branchId, (string)$closingDate);
                $historySaved = dailyInventorySavedDayCounts($conn, $branchId, (string)$closingDate);
                $historyCountsStale = false;
                foreach ($historySaved as $savedRow) {
                    $historyCountsStale = $historyCountsStale || dailyInventoryCountsDiffer($savedRow, $historyCounts);
                }

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
                            <span class="summary-pill counts" title="From Visit Check-in and vaccination records (read-only)">
                                <i class="bi bi-person-check-fill"></i>
                                <?= (int)$historyCounts['patient_count'] ?> patients ·
                                <?= (int)$historyCounts['animal_bite_cases'] ?> cases ·
                                <?= (int)$historyCounts['vaccinations_administered'] ?> doses
                            </span>
                            <?php if ($historyCountsStale): ?>
                                <a class="summary-pill variance text-decoration-none" href="Nurse_DailyInventory.php?<?= workflowH(http_build_query(['date' => $closingDate])) ?>#dayClosing" title="Patients were checked in after this closing was saved">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Out of date – save again
                                </a>
                            <?php endif; ?>
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
                                        <?php if ($closing['status'] === 'Returned'): ?>
                                            <span class="result-badge variance">
                                                Returned for recount
                                            </span>

                                            <div class="small text-danger mt-1">
                                                The Branch Admin returned this closing<?= trim((string)($closing['review_remarks'] ?? '')) !== ''
                                                    ? ': ' . workflowH(trim((string)$closing['review_remarks'])) : '' ?>.
                                                <a href="Nurse_DailyInventory.php?<?= workflowH(http_build_query([
                                                    'item_id' => (int)$closing['item_id'],
                                                    'inventory_date' => (string)$closing['inventory_date'],
                                                    'history_date' => (string)$closing['inventory_date'],
                                                ])) ?>">Recount and save again</a>
                                            </div>
                                        <?php elseif ($closing['status'] === 'Draft'): ?>
                                            <span class="result-badge variance">
                                                Resubmission required
                                            </span>

                                            <div class="small text-danger mt-1">
                                                Out of date: usage was recorded after this closing.
                                                <a href="Nurse_DailyInventory.php?<?= workflowH(http_build_query([
                                                    'item_id' => (int)$closing['item_id'],
                                                    'inventory_date' => (string)$closing['inventory_date'],
                                                    'history_date' => (string)$closing['inventory_date'],
                                                ])) ?>">Refresh closing</a>
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

                                        <?php if ($closing['status'] === 'Reviewed'): ?>
                                            <div class="small text-success mt-1">
                                                <i class="bi bi-patch-check-fill"></i> Reviewed by the Branch Admin<?= trim((string)($closing['review_remarks'] ?? '')) !== ''
                                                    ? ': ' . workflowH(trim((string)$closing['review_remarks'])) : '' ?>
                                            </div>
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
// ---------- Whole-day closing ----------
// Integer ten-thousandths, matching the server's DECIMAL(12,4) arithmetic.
function qtyScaled(value) {
    if (value === '' || value === null || value === undefined) return 0;
    if (!/^(?:[0-9]+(?:\.[0-9]{1,4})?|\.[0-9]{1,4})$/.test(String(value))) return null;
    const parts = String(value).split('.');
    return Number(parts[0] || '0') * 10000 + Number((parts[1] || '').padEnd(4, '0'));
}
function qtyDecimal(scaled) {
    const magnitude = Math.abs(scaled);
    return (scaled < 0 ? '-' : '') + Math.floor(magnitude / 10000) + '.' + String(magnitude % 10000).padStart(4, '0');
}
function unitWord(unit, quantity) {
    unit = String(unit || '').trim();
    if (/^(ml|l|mg|g|iu|cc|pcs|pc)$/i.test(unit)) return unit.toLowerCase() === 'ml' ? 'mL' : unit;
    const word = unit.toLowerCase();
    return Math.abs(quantity - 1) < 0.00005 || word.endsWith('s') ? word : word + 's';
}
function plainNumber(value) {
    return Number(value.toFixed(4)).toLocaleString(undefined, {maximumFractionDigits: 4});
}
// "67 vials + 3 sites", "12 vials + 3 mL", "4 ampules" (same as inventoryQuantityText()).
function qtyText(scaled, meta) {
    const sign = scaled < 0 ? '-' : '';
    const base = Math.abs(scaled) / 10000;
    if (!meta.split) return sign + plainNumber(base) + ' ' + unitWord(meta.display, base);
    const conv = Number(meta.conv);
    const full = Math.floor((base + 0.0000001) / conv);
    let rem = Number((base - full * conv).toFixed(4));
    if (Math.abs(rem) < 0.00005) rem = 0;
    const parts = [];
    if (full > 0 || rem === 0) parts.push(full + ' ' + unitWord(meta.big, full));
    if (rem > 0) parts.push(plainNumber(rem) + ' ' + unitWord(meta.base, rem));
    return sign + parts.join(' + ');
}
// Vials + sites boxes -> base-unit hidden value (blank stays blank).
function qtyCompose(group) {
    const main = group.querySelector('.q-main').value.trim();
    const remInput = group.querySelector('.q-rem');
    const rem = remInput ? remInput.value.trim() : '';
    const hidden = group.querySelector('.q-base');
    if (main === '' && rem === '') { hidden.value = ''; return 0; }
    const conv = Number(group.dataset.conv || '1');
    const mainScaled = qtyScaled(main);
    const remScaled = qtyScaled(rem);
    if (mainScaled === null || remScaled === null) { hidden.value = 'invalid'; return null; }
    const scaled = Math.round(mainScaled * conv) + remScaled;
    hidden.value = qtyDecimal(scaled);
    return scaled;
}
// Base-unit value -> vials + sites boxes.
function qtySplit(group, baseValue) {
    const main = group.querySelector('.q-main');
    const remInput = group.querySelector('.q-rem');
    const hidden = group.querySelector('.q-base');
    hidden.value = baseValue === null || baseValue === undefined ? '' : String(baseValue);
    const scaled = qtyScaled(hidden.value);
    if (hidden.value === '' || scaled === null) { main.value = ''; if (remInput) remInput.value = ''; return; }
    const conv = Number(group.dataset.conv || '1');
    if (!remInput) { main.value = plainValue(scaled); return; }
    const convScaled = Math.round(conv * 10000);
    const full = Math.floor(scaled / convScaled);
    main.value = String(full);
    const rem = scaled - full * convScaled;
    remInput.value = rem === 0 ? '0' : plainValue(rem);
}
function plainValue(scaled) {
    return qtyDecimal(scaled).replace(/\.?0+$/, '');
}
function dayRowValue(row, field) {
    const group = row.querySelector(`.qty-group[data-field="${field}"]`);
    return group ? qtyCompose(group) : 0;
}
function dayRefreshRow(row) {
    const meta = JSON.parse(row.dataset.unit);
    const beginningGroup = row.querySelector('.qty-group[data-field="beginning_stock"]');
    const beginning = dayRowValue(row, 'beginning_stock');
    const beginningBlank = beginningGroup.querySelector('.q-base').value === '';
    const delivery = dayRowValue(row, 'delivery');
    const pullOut = dayRowValue(row, 'pull_out');
    const actualGroup = row.querySelector('.qty-group[data-field="actual_count"]');
    const actual = qtyCompose(actualGroup);
    const actualBlank = actualGroup.querySelector('.q-base').value === '';
    const consumed = qtyScaled(row.dataset.consumed);
    const extraValue = row.querySelector('.w-qty').value;
    const extra = extraValue === '' ? 0 : qtyScaled(extraValue);
    const endCell = row.querySelector('[data-output="end"]');
    const varianceCell = row.querySelector('[data-output="variance"]');
    varianceCell.className = '';
    row.querySelector('.consumed-total').textContent = qtyText((consumed ?? 0) + (extra ?? 0), meta);
    row.querySelector('.adjust-summary').textContent = extra > 0
        ? `incl. adjustment +${qtyText(extra, meta)} (${row.querySelector('.w-reason').value === 'Other' ? 'Other: ' + row.querySelector('.w-other').value : row.querySelector('.w-reason').value})`
        : '';
    if (beginningBlank || [beginning, delivery, pullOut, consumed, extra].includes(null)) {
        endCell.textContent = '-'; varianceCell.textContent = '-'; row.dataset.end = ''; return;
    }
    const end = beginning + delivery - consumed - extra - pullOut;
    row.dataset.end = String(end);
    endCell.textContent = qtyText(end, meta);
    if (end < 0) endCell.classList.add('variance-bad'); else endCell.classList.remove('variance-bad');
    if (actualBlank || actual === null) { varianceCell.textContent = '-'; return; }
    const variance = actual - end;
    varianceCell.textContent = variance === 0 ? '0 (tallied)' : (variance > 0 ? '+' : '') + qtyText(variance, meta);
    varianceCell.className = variance === 0 ? 'variance-ok' : 'variance-bad';
}

const dayClosing = document.getElementById('dayClosing');
document.getElementById('dayShowAll')?.addEventListener('change', event => {
    dayClosing.classList.toggle('show-all', event.target.checked);
});
if (document.getElementById('dayShowAll')?.checked) dayClosing.classList.add('show-all');

document.querySelectorAll('.day-row').forEach(row => {
    row.querySelectorAll('.qty-group').forEach(group => qtySplit(group, group.querySelector('.q-base').value));
    row.addEventListener('input', () => dayRefreshRow(row));
    row.querySelector('.use-end')?.addEventListener('click', () => {
        if (row.dataset.end === '' || Number(row.dataset.end) < 0) return;
        qtySplit(row.querySelector('.qty-group[data-field="actual_count"]'), qtyDecimal(Number(row.dataset.end)));
        dayRefreshRow(row);
    });
    row.querySelector('.use-saved-actual')?.addEventListener('click', () => {
        qtySplit(row.querySelector('.qty-group[data-field="actual_count"]'), row.dataset.savedActual);
        dayRefreshRow(row);
    });
    row.querySelector('.adjust-btn').addEventListener('click', () => openAdjust(row));
    dayRefreshRow(row);
});

// Edit / Adjust: the existing wastage adjustment, one row at a time.
let adjustRow = null;
const adjustModalEl = document.getElementById('adjustModal');
function adjustMeta() { return JSON.parse(adjustRow.dataset.unit); }
function adjustRefresh() {
    const group = document.querySelector('#adjustQtyHolder .qty-group');
    const extra = group ? qtyCompose(group) : 0;
    const consumed = qtyScaled(adjustRow.dataset.consumed) ?? 0;
    document.getElementById('adjustFinal').textContent = extra === null ? '-' :
        qtyText(consumed + extra, adjustMeta()) + (extra > 0 ? ` (auto ${qtyText(consumed, adjustMeta())} + adjustment ${qtyText(extra, adjustMeta())})` : '');
    document.getElementById('adjustOther').style.display = document.getElementById('adjustReason').value === 'Other' ? '' : 'none';
}
function openAdjust(row) {
    adjustRow = row;
    const meta = adjustMeta();
    document.getElementById('adjustModalTitle').textContent = 'Adjust Consumed – ' + row.dataset.itemName;
    document.getElementById('adjustAuto').textContent = qtyText(qtyScaled(row.dataset.consumed) ?? 0, meta);
    // Same vials + sites boxes as the table, without a form name.
    const template = row.querySelector('.qty-group[data-field="delivery"]').cloneNode(true);
    template.dataset.field = 'adjust';
    template.querySelector('.q-base').removeAttribute('name');
    template.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
    const holder = document.getElementById('adjustQtyHolder');
    holder.replaceChildren(template);
    qtySplit(template, row.querySelector('.w-qty').value);
    template.addEventListener('input', adjustRefresh);
    document.getElementById('adjustReason').value = row.querySelector('.w-reason').value;
    document.getElementById('adjustOther').value = row.querySelector('.w-other').value;
    document.getElementById('adjustError').textContent = '';
    adjustRefresh();
    bootstrap.Modal.getOrCreateInstance(adjustModalEl).show();
}
document.getElementById('adjustReason').addEventListener('change', adjustRefresh);
document.getElementById('adjustApply').addEventListener('click', () => {
    const group = document.querySelector('#adjustQtyHolder .qty-group');
    const extra = qtyCompose(group);
    const reason = document.getElementById('adjustReason').value;
    const other = document.getElementById('adjustOther').value.trim();
    const error = document.getElementById('adjustError');
    if (extra === null || extra <= 0) { error.textContent = 'Enter the extra quantity lost.'; return; }
    if (!reason) { error.textContent = 'Pick a reason.'; return; }
    if (reason === 'Other' && other === '') { error.textContent = 'Specify the reason.'; return; }
    adjustRow.querySelector('.w-qty').value = qtyDecimal(extra);
    adjustRow.querySelector('.w-reason').value = reason;
    adjustRow.querySelector('.w-other').value = reason === 'Other' ? other : '';
    dayRefreshRow(adjustRow);
    bootstrap.Modal.getOrCreateInstance(adjustModalEl).hide();
});
document.getElementById('adjustRemove').addEventListener('click', () => {
    for (const cls of ['.w-qty', '.w-reason', '.w-other']) adjustRow.querySelector(cls).value = '';
    dayRefreshRow(adjustRow);
    bootstrap.Modal.getOrCreateInstance(adjustModalEl).hide();
});

// Save Closing: check every row that will be saved, list ALL problems at the top
// of the sheet (showing hidden rows that have one) and scroll to the list.
// Rows without an Actual count or adjustment are not sent (the server skips
// them anyway), which keeps the request well under PHP's max_input_vars.
const dayFieldLabels = { beginning_stock: 'Beginning', delivery: 'Delivery', pull_out: 'Pull-out', actual_count: 'Actual' };
let daySaving = false;

function dayGroupEntered(group) {
    return group ? [...group.querySelectorAll('input.form-control')].some(input => input.value.trim() !== '' || input.validity.badInput) : false;
}
function dayShowRow(row) {
    if (row.classList.contains('day-extra') && !dayClosing.classList.contains('show-all')) {
        dayClosing.classList.add('show-all');
        const toggle = document.getElementById('dayShowAll');
        if (toggle) toggle.checked = true;
    }
}
function dayFocusRow(row, input) {
    dayShowRow(row);
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    (input || row.querySelector('input.form-control:not([disabled])'))?.focus({ preventScroll: true });
}
function dayShowProblems(problems) {
    const box = document.getElementById('dayProblems');
    const list = box.querySelector('ul');
    list.innerHTML = '';
    box.querySelector('.day-problems-title').textContent = 'Nothing was saved. Fix '
        + (problems.length === 1 ? 'this problem' : 'these ' + problems.length + ' problems') + ':';
    problems.forEach(problem => {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.href = '#';
        link.className = 'alert-link';
        link.textContent = problem.text;
        link.addEventListener('click', event => { event.preventDefault(); dayFocusRow(problem.row, problem.input); });
        li.appendChild(link);
        list.appendChild(li);
        problem.row.classList.add('row-problem');
        dayShowRow(problem.row);
    });
    document.getElementById('dayResult')?.classList.add('d-none');
    box.classList.remove('d-none');
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    box.focus({ preventScroll: true });
}

document.getElementById('dayClosingForm')?.addEventListener('submit', event => {
    if (daySaving) { event.preventDefault(); return; } // already saving: ignore the second click
    const problems = [];
    const skipRows = [];
    document.querySelectorAll('.day-row').forEach(row => {
        row.classList.remove('row-problem');
        row.querySelectorAll('.is-invalid').forEach(input => input.classList.remove('is-invalid'));
        const name = row.dataset.itemName || 'Item';
        const actualGroup = row.querySelector('.qty-group[data-field="actual_count"]');
        const hasActual = dayGroupEntered(actualGroup);
        const hasAdjustment = (row.querySelector('.w-qty')?.value || '').trim() !== '';
        if (!hasActual && !hasAdjustment) {
            if (row.dataset.needsActual === '1') {
                actualGroup?.querySelectorAll('input.form-control').forEach(input => input.classList.add('is-invalid'));
                problems.push({ row, input: actualGroup?.querySelector('.q-main'),
                    text: name + ': enter the Actual physical count (this item was used today).' });
            } else {
                skipRows.push(row);
            }
            return;
        }
        if (!hasActual) {
            problems.push({ row, input: actualGroup?.querySelector('.q-main'),
                text: name + ': enter the Actual count to save the Consumed adjustment.' });
        }
        row.querySelectorAll('.qty-group').forEach(group => {
            const inputs = [...group.querySelectorAll('input.form-control')];
            const unreadable = inputs.some(input => input.validity.badInput);
            if (unreadable || qtyCompose(group) === null) {
                inputs.forEach(input => input.classList.add('is-invalid'));
                problems.push({ row, input: inputs[0], text: name + ': ' + (dayFieldLabels[group.dataset.field] || 'Quantity')
                    + ' must be a number of 0 or more (digits only, up to 4 decimals).' });
            }
        });
    });
    if (problems.length) {
        event.preventDefault();
        dayShowProblems(problems);
        return;
    }
    daySaving = true;
    // Not sent: rows with nothing to save (re-enabled if the page is shown again from history).
    skipRows.forEach(row => row.querySelectorAll('input[name], select[name], textarea[name]').forEach(input => { input.disabled = true; }));
    const button = document.getElementById('saveDayBtn');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
});
window.addEventListener('pageshow', () => {
    daySaving = false;
    document.querySelectorAll('#dayClosingForm [name]:disabled').forEach(input => { input.disabled = false; });
    const button = document.getElementById('saveDayBtn');
    if (button && button.disabled) {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.innerHTML = '<i class="bi bi-save-fill me-1"></i>Save Closing';
    }
});
// After a save, bring the result (success or the problem list) into view.
(() => {
    const result = document.getElementById('dayResult');
    if (!result) return;
    result.querySelectorAll('.day-problem-link').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        const row = document.querySelector(`.day-row[data-item-id="${link.dataset.itemId}"]`);
        if (row) dayFocusRow(row);
    }));
    result.scrollIntoView({ block: 'start' });
    result.focus({ preventScroll: true });
})();
</script>

<script>
const initialDailyForm = <?= json_encode(['item_id' => $formItemId, 'inventory_date' => $formDate, 'restore' => $formRestore], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let dailyContextSequence = 0;
let dailyContextController = null;
let dailyContextReady = false;
let lastDailyContext = null;

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
    // Optional wastage adjustment (blank = 0); final Consumed = auto + adjustment.
    const extraValue = document.getElementById('wastage_quantity')?.value ?? '';
    const extra = extraValue === '' ? 0 : dailyScaled(extraValue);
    return numbers.includes(null) || extra === null ? null : numbers[0] + numbers[1] - numbers[2] - extra - numbers[3];
}

function refreshFinalConsumed() {
    const auto = dailyScaled(document.getElementById('consumed_numeric').value);
    const extraValue = document.getElementById('wastage_quantity').value;
    const extra = extraValue === '' ? 0 : dailyScaled(extraValue);
    const el = document.getElementById('finalConsumedPreview');
    el.textContent = !dailyContextReady || auto === null || extra === null ? '-'
        : `${formatClosingNumber(auto + extra)} ${selectedBaseUnit()}` + (extra > 0 ? ` (auto ${formatClosingNumber(auto)} + adjustment ${formatClosingNumber(extra)})` : '');
    document.getElementById('wastageOtherBox').style.display =
        document.getElementById('wastage_reason').value === 'Other' ? '' : 'none';
}

function refreshClosingPreview() {
    refreshFinalConsumed();
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
    for (const id of ['beginning_stock', 'actual_count', 'remarks', 'wastage_quantity', 'wastage_reason', 'wastage_other']) document.getElementById(id).value = '';
    document.getElementById('closingOutOfDateBox').style.display = 'none';
    document.getElementById('singleDayCounts').style.display = 'none';
    document.getElementById('consumedBreakdown').textContent = '';
    lastDailyContext = null;
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
    document.getElementById('wastage_reason').disabled = true;
    document.getElementById('wastage_other').disabled = true;
    document.getElementById('adjustConsumedBtn').disabled = true;
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
        document.getElementById('consumedBreakdown').textContent = data.consumed_breakdown || '';
        lastDailyContext = data;
        if (data.closing_out_of_date) {
            document.getElementById('closingOutOfDateText').textContent =
                'The saved closing for this date is out of date: usage was recorded after it was submitted (saved Consumed '
                + formatClosingNumber(dailyScaled(String(data.closing_saved_consumed)) ?? 0) + `, now ${formatClosingNumber(dailyScaled(data.consumed) ?? 0)} ${baseUnit}). `
                + 'Refresh it: verify the counts and submit again.';
            document.getElementById('closingOutOfDateBox').style.display = '';
        }
        if (data.day_counts) {
            const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
            document.getElementById('singlePatientsLabel').textContent = date === today ? 'Patients checked in today' : 'Patients checked in on ' + date;
            document.getElementById('singlePatients').textContent = data.day_counts.patient_count;
            document.getElementById('singleCases').textContent = data.day_counts.animal_bite_cases;
            document.getElementById('singleDoses').textContent = data.day_counts.vaccinations_administered;
            document.getElementById('singleDayCounts').style.display = '';
        }
        if (data.counts_out_of_date) {
            document.getElementById('closingOutOfDateText').textContent =
                'The saved closing for this date is out of date: patients were checked in (or doses given) after it was submitted (saved: '
                + data.saved_counts_text + '; now: ' + data.day_counts_text + '). Submit it again to refresh it; the same closing is updated.';
            document.getElementById('closingOutOfDateBox').style.display = '';
        }
        for (const [id, value] of Object.entries(data.fields)) document.getElementById(id).value = value;
        document.getElementById('closing_context_token').value = data.context_token;
        help.textContent = data.opening_message;
        if (restore && restore.item_id === selected && restore.inventory_date === date) {
            for (const id of ['beginning_stock', 'delivery', 'pull_out', 'remarks', 'wastage_quantity', 'wastage_reason', 'wastage_other']) {
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
        document.getElementById('wastage_reason').disabled = false;
        document.getElementById('wastage_other').disabled = false;
        document.getElementById('adjustConsumedBtn').disabled = false;
        if (document.getElementById('wastage_quantity').value !== '') {
            document.getElementById('adjustConsumedPanel').style.display = '';
        }
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
for (const id of ['beginning_stock', 'delivery', 'pull_out', 'actual_count', 'wastage_quantity', 'wastage_reason']) {
    document.getElementById(id).addEventListener('input', refreshClosingPreview);
    document.getElementById(id).addEventListener('change', refreshClosingPreview);
}
document.getElementById('adjustConsumedBtn').addEventListener('click', () => {
    const panel = document.getElementById('adjustConsumedPanel');
    const open = panel.style.display === 'none';
    panel.style.display = open ? '' : 'none';
    document.getElementById('adjustConsumedBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) {
        for (const id of ['wastage_quantity', 'wastage_reason', 'wastage_other']) document.getElementById(id).value = '';
        refreshClosingPreview();
    } else {
        document.getElementById('wastage_quantity').focus();
    }
});
// Out-of-date closing: reload the saved Actual count so the nurse can verify and resubmit.
document.getElementById('refreshClosingBtn').addEventListener('click', () => {
    if (!lastDailyContext || lastDailyContext.closing_saved_actual === null) return;
    document.getElementById('actual_count').value = lastDailyContext.closing_saved_actual;
    document.getElementById('openingBalanceHelp').textContent =
        'Closing refreshed with the current Consumed. The saved Actual count was reloaded: confirm it with a physical count, explain any variance, then Submit.';
    refreshClosingPreview();
    document.getElementById('actual_count').focus();
});
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