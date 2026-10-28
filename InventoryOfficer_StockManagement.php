<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/inventory_stock_helpers.php';
$stockTotalsSql = inventoryStockTotalsSql();
require_once 'sources/notification_helper.php';
require_once 'sources/inventory_officer_activity_helper.php';
require_once 'sources/stock_quantity_helpers.php';
require_once 'sources/stock_receipt_source_helper.php';
require_once 'sources/inventory_approval_helpers.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 5) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';
$message = '';
$messageType = '';
$activePanel = 'stockIn';
$viewStock = null;

if (empty($_SESSION['stock_management_csrf'])) {
    $_SESSION['stock_management_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['stock_management_csrf'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($type, $message, $panel = 'stockIn') {
    header('Location: InventoryOfficer_StockManagement.php?panel=' . urlencode($panel) . '&msg=' . urlencode($message) . '&type=' . urlencode($type));
    exit();
}

function expiryStatus($date) {
    if (!$date) return ['No Expiration', 'badge-low', null];

    try {
        $today = new DateTime('today');
        $expiry = new DateTime($date);
        $days = (int)$today->diff($expiry)->format('%r%a');
    } catch (Throwable $e) {
        return ['Invalid Date', 'badge-critical', null];
    }

    if ($days < 0) return ['Expired', 'badge-critical', $days];
    if ($days <= 30) return ['Expiring Soon', 'badge-low', $days];
    return ['Good', 'badge-good', $days];
}

function validYmdDate($date) {
    if (!is_string($date) || $date === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}


/* -------------------------------------------------------------
 * EXPIRED STOCK ARCHIVE TABLE
 * ----------------------------------------------------------- */
function ensureStockArchiveTable($conn) {
    if (!$conn->query("CREATE TABLE IF NOT EXISTS inventory_stocks_archive LIKE inventory_stocks")) {
        throw new Exception('Unable to create inventory stock archive table: ' . $conn->error);
    }

    /*
     * Railway uses MySQL, where ALTER TABLE ... ADD COLUMN IF NOT EXISTS
     * is not portable across versions. Check INFORMATION_SCHEMA first,
     * then add only columns that are missing.
     */
    $requiredColumns = [
        'archived_at' => "ALTER TABLE inventory_stocks_archive ADD COLUMN archived_at DATETIME NULL",
        'archived_by' => "ALTER TABLE inventory_stocks_archive ADD COLUMN archived_by INT NULL",
        'archive_reason' => "ALTER TABLE inventory_stocks_archive ADD COLUMN archive_reason VARCHAR(255) NULL"
    ];

    $checkStmt = $conn->prepare(
        "SELECT COUNT(*) AS column_exists
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'inventory_stocks_archive'
           AND COLUMN_NAME = ?"
    );

    if (!$checkStmt) {
        throw new Exception('Unable to prepare archive column check: ' . $conn->error);
    }

    foreach ($requiredColumns as $columnName => $alterSql) {
        $checkStmt->bind_param('s', $columnName);

        if (!$checkStmt->execute()) {
            $error = $checkStmt->error;
            $checkStmt->close();
            throw new Exception('Unable to check inventory stock archive fields: ' . $error);
        }

        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $exists = (int)($row['column_exists'] ?? 0) > 0;

        if (!$exists) {
            if (!$conn->query($alterSql)) {
                $error = $conn->error;
                $checkStmt->close();
                throw new Exception(
                    'Unable to add archive field ' . $columnName . ': ' . $error
                );
            }
        }
    }

    $checkStmt->close();
}


try {
    ensureStockArchiveTable($conn);
} catch (Throwable $e) {
    die('Archive setup error: ' . h($e->getMessage()));
}

function requiresBatchTracking($categoryName) {
    $normalized = strtolower(trim((string)$categoryName));
    return in_array($normalized, ['medical supplies', 'medical supply', 'vaccine', 'vaccines'], true);
}

/* True when the item is counted in a unit other than its base unit (vials of sites, ampules of mL). */
function stockManagementHasCountingUnit(array $item): bool {
    return strcasecmp(inventoryBaseUnitLabel($item), inventoryDisplayUnitLabel($item)) !== 0;
}

/* "1 vial = 6 sites" for counting-unit items, '' otherwise. Display only. */
function stockManagementConversionText(array $item): string {
    if (!stockManagementHasCountingUnit($item)) return '';
    return '1 ' . strtolower(inventoryDisplayUnitLabel($item)) . ' = '
        . inventoryQuantityText(inventoryConversionToBase($item), ['base_unit_label' => inventoryBaseUnitLabel($item)]);
}

/*
 * Quantity text next to forms whose inputs take BASE units:
 * "67 vials + 3 sites (405 sites)". Items without a counting unit: "12 pcs".
 */
function stockManagementFormQuantityText(float $baseQuantity, array $item): string {
    $text = inventoryQuantityText($baseQuantity, $item);
    if (!stockManagementHasCountingUnit($item)) return $text;
    return $text . ' (' . inventoryFormatNumber($baseQuantity) . ' '
        . inventoryUnitWord(inventoryBaseUnitLabel($item), $baseQuantity) . ')';
}

/* Unit wording for quantity-input help text: "sites (1 vial = 6 sites)". */
function stockManagementInputUnitText(array $item): string {
    $unit = inventoryUnitWord(inventoryBaseUnitLabel($item), 2);
    $conversion = stockManagementConversionText($item);
    return $conversion !== '' ? $unit . ' (' . $conversion . ')' : $unit;
}

function itemOptions($items) {
    foreach ($items as $item) {
        echo '<option value="' . h($item['item_id']) . '"'
            . ' data-stock="' . h($item['quantity_available']) . '"'
            . ' data-stock-text="' . h(stockManagementFormQuantityText((float)$item['quantity_available'], $item)) . '"'
            . ' data-expired-stock="' . h($item['expired_stock'] ?? 0) . '"'
            . ' data-expired-stock-text="' . h(stockManagementFormQuantityText((float)($item['expired_stock'] ?? 0), $item)) . '"'
            . ' data-total-stock="' . h($item['total_stock'] ?? $item['quantity_available']) . '"'
            . ' data-unit-id="' . h($item['unit_id']) . '"'
            . ' data-unit-name="' . h($item['unit_name']) . '"'
            . ' data-unit-hint="' . h(stockManagementInputUnitText($item)) . '"'
            . ' data-quantity-step="' . inventoryInputStep($item) . '"'
            . ' data-category-id="' . h($item['category_id']) . '"'
            . ' data-category-name="' . h($item['category_name']) . '"'
            . ' data-minimum-stock="' . h($item['minimum_stock']) . '"'
            . ' data-minimum-stock-text="' . h(stockManagementFormQuantityText((float)$item['minimum_stock'], $item)) . '"'
            . ' data-batch-tracked="' . (requiresBatchTracking($item['category_name']) ? '1' : '0') . '"'
            . '>' . h($item['item_name']) . '</option>';
    }
}

/* Get logged-in officer and branch. */
$userQuery = "SELECT u.branch_id, u.username, u.role_id, u.status, b.branch_name
              FROM users u
              LEFT JOIN branches b ON u.branch_id = b.branch_id
              WHERE u.user_id = ?
              LIMIT 1";
$stmt = $conn->prepare($userQuery);
if (!$stmt) die('Database error while loading user information.');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
if ($userResult->num_rows === 1) {
    $userData = $userResult->fetch_assoc();
    if ($userData['status'] !== 'Active' || (int)$userData['role_id'] !== 5) { http_response_code(403); die('Active Inventory Officer access is required.'); }
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Inventory Officer';
}
$stmt->close();

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

try { stockReceiptSourceAssertSchema($conn); }
catch (Throwable $e) { http_response_code(503); die(h($e->getMessage())); }
$sourceBranches=$conn->query("SELECT branch_id,branch_name FROM branches WHERE status='Active' ORDER BY branch_name")->fetch_all(MYSQLI_ASSOC);

$notification_count = getUnreadNotificationCount($conn, $user_id);

/* -------------------------------------------------------------
 * POST HANDLERS
 * ----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $activePanel = $_POST['panel'] ?? 'stockIn';

    if (!hash_equals($csrfToken, $postedToken)) {
        redirectWithMessage('error', 'Invalid request token. Please try again.', $activePanel);
    }

    if (!$branch_id) {
        redirectWithMessage('error', 'Your account has no branch assigned.', $activePanel);
    }

    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
    $quantityScaled = null;
    $quantity = null;
    if (in_array($action, ['stock_in', 'stock_out'], true)) {
        try {
            $quantityScaled = stockQuantityParse($_POST['quantity'] ?? null);
            $quantity = stockQuantityDecimal($quantityScaled);
        } catch (InvalidArgumentException $e) {
            redirectWithMessage('error', $e->getMessage(), $action === 'stock_in' ? 'stockIn' : 'stockOut');
        }
    }
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($transaction_date === '') {
        $transaction_date = date('Y-m-d');
    }

    $validDate = validYmdDate($transaction_date);

    /* ------------------- STOCK IN (approval request) ------------------- */
    /*
     * A delivery (new batch or added quantity) is saved as a Pending stock_in
     * request; nothing is added to inventory_stocks until the Branch Admin
     * approves it. On approval the same Stock In code runs once
     * (inventoryStockInApply), dated the approval moment, and a linked
     * restock request is fulfilled then.
     */
    if ($action === 'stock_in') {
        $batch_lot_no = trim((string)($_POST['batch_lot_no'] ?? ''));
        $restockRequestId = filter_input(INPUT_POST, 'restock_request_id', FILTER_VALIDATE_INT) ?: null;
        $manufacturing_date = trim((string)($_POST['manufacturing_date'] ?? ''));
        $expiration_date = trim((string)($_POST['expiration_date'] ?? ''));

        if (!$item_id || $item_id <= 0 || !$quantity || $quantity <= 0 || !$validDate) {
            redirectWithMessage('error', 'Please enter a valid item, positive quantity, and transaction date.', 'stockIn');
        }
        if (mb_strlen($remarks) > 500) redirectWithMessage('error', 'Keep the remarks within 500 characters.', 'stockIn');

        $conn->begin_transaction();
        try {
            inventoryApprovalAssertReady($conn);
            $valid = inventoryStockInValidate($conn, (string)$branch_id, (int)$item_id, $quantityScaled,
                $batch_lot_no, $manufacturing_date, $expiration_date);
            $origin = stockReceiptSourceFromForm($conn, (string)$branch_id, $_POST);
            if ($restockRequestId !== null) {
                $restock = inventoryApprovalLoad($conn, (int)$restockRequestId);
                if (!$restock || $restock['request_type'] !== 'restock' || (string)$restock['branch_id'] !== (string)$branch_id
                    || $restock['status'] !== 'Approved') {
                    throw new Exception('Select an approved restock request of this branch.');
                }
                if ((int)$restock['item_id'] !== (int)$item_id) {
                    throw new Exception('Restock request #' . $restockRequestId . ' is for ' . $restock['item_name'] . ', not the item received.');
                }
            }
            $requestId = inventoryApprovalCreate($conn, [
                'type' => 'stock_in', 'branch_id' => (string)$branch_id, 'requested_by' => $user_id, 'source' => 'Inventory Officer',
                'item_id' => (int)$item_id, 'item_name' => $valid['item']['item_name'], 'batch' => $valid['batch'],
                'quantity' => $quantity, 'unit' => inventoryBaseUnitLabel($valid['item']),
                'reason' => 'Delivery from ' . $origin['name'] . ' (' . $origin['type'] . ')',
                'remarks' => trim('Received on ' . $transaction_date . ($remarks !== '' ? ' | ' . $remarks : '')),
                'payload' => [
                    'batch_lot_no' => $valid['batch'], 'manufacturing_date' => $valid['manufacturing_date'],
                    'expiration_date' => $valid['expiration_date'], 'received_date' => $transaction_date,
                    'stock_source_type' => $origin['type'], 'stock_source_branch_id' => (string)($origin['branch'] ?? ''),
                    'stock_source_name' => $origin['type'] === 'External' ? (string)($_POST['stock_source_name'] ?? '') : '',
                    'source_label' => $origin['name'], 'restock_request_id' => $restockRequestId, 'remarks' => $remarks,
                ],
            ]);
            $conn->commit();
            redirectWithMessage('success', 'Stock In request #' . $requestId . ' for '
                . inventoryQuantityText((float)$quantity, $valid['item']) . ' of ' . $valid['item']['item_name']
                . ' was sent: Pending approval. The stock is added (and can be used) only when the Branch Admin approves it.'
                . ($restockRequestId !== null ? ' Restock request #' . $restockRequestId . ' is updated on approval.' : ''), 'requests');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'stockIn');
        }
    }

    /* ------------------- STOCK OUT (approval request) ------------------- */
    /*
     * Stock Out, pull-out and disposal of damaged, contaminated, expired or lost
     * stock no longer change stock directly: the officer sends a request and the
     * Branch Admin approves it (BranchAdmin_Approvals.php). On approval the same
     * FEFO stock-out logic runs (sources/inventory_stock_operations.php), dated
     * the approval moment, so Daily Inventory shows it as Pull-out that day.
     */
    if ($action === 'stock_out') {
        $reason = trim($_POST['reason'] ?? '');

        if (!$item_id || $item_id <= 0 || !$quantity || $quantity <= 0 || !$validDate || !in_array($reason, INVENTORY_STOCK_OUT_REASONS, true)) {
            redirectWithMessage('error', 'Please enter a valid item, positive quantity, reason, and date.', 'stockOut');
        }
        if (mb_strlen($remarks) > 500) redirectWithMessage('error', 'Keep the remarks within 500 characters.', 'stockOut');

        $conn->begin_transaction();
        try {
            inventoryApprovalAssertReady($conn);
            $itemData = inventoryOpsItem($conn, (int)$item_id);
            stockQuantityValidateUnit($quantityScaled, $itemData);
            $availableScaled = inventoryOpsAvailableScaled($conn, (int)$item_id, (string)$branch_id, $reason === 'Expired');
            if ($quantityScaled > $availableScaled) {
                throw new Exception('Stock Out cannot exceed the current ' . ($reason === 'Expired' ? 'expired' : 'usable') . ' stock ('
                    . inventoryQuantityText((float)stockQuantityDecimal($availableScaled), $itemData) . ').');
            }
            $requestId = inventoryApprovalCreate($conn, [
                'type' => 'stock_out', 'branch_id' => (string)$branch_id, 'requested_by' => $user_id, 'source' => 'Inventory Officer',
                'item_id' => (int)$item_id, 'item_name' => $itemData['item_name'], 'quantity' => $quantity,
                'unit' => inventoryBaseUnitLabel($itemData), 'reason' => $reason,
                'remarks' => trim('Noted on ' . $transaction_date . ($remarks !== '' ? ' | ' . $remarks : '')),
                'payload' => ['reason' => $reason, 'noted_date' => $transaction_date,
                    'available_at_request' => stockQuantityDecimal($availableScaled)],
            ]);
            $conn->commit();
            redirectWithMessage('success', 'Stock Out request #' . $requestId . ' was sent: Pending approval. Stock changes only when the Branch Admin approves it.', 'requests');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'stockOut');
        }
    }

    /* ------------- DISPOSE / ARCHIVE EXPIRED (approval request) ------------- */
    if ($action === 'archive_expired') {
        $stock_id = filter_input(INPUT_POST, 'stock_id', FILTER_VALIDATE_INT);

        if (!$stock_id || $stock_id <= 0) {
            redirectWithMessage('error', 'Invalid expired stock record.', 'expiration');
        }

        $conn->begin_transaction();
        try {
            inventoryApprovalAssertReady($conn);
            $expiredStmt = $conn->prepare("SELECT s.stock_id, s.item_id, s.batch_lot_no, s.quantity_available, s.expiration_date
                FROM inventory_stocks s WHERE s.stock_id = ? AND s.branch_id = ? LIMIT 1");
            $expiredStmt->bind_param('is', $stock_id, $branch_id);
            $expiredStmt->execute();
            $expiredRow = $expiredStmt->get_result()->fetch_assoc();
            $expiredStmt->close();
            if (!$expiredRow) throw new Exception('Expired stock record was not found in your branch.');
            if (empty($expiredRow['expiration_date']) || $expiredRow['expiration_date'] >= date('Y-m-d')) {
                throw new Exception('Only already-expired stock can be disposed and archived.');
            }
            $disposeScaled = stockQuantityParse($expiredRow['quantity_available'], true);
            if ($disposeScaled <= 0) throw new Exception('This expired batch has no remaining stock to dispose.');
            $itemData = inventoryOpsItem($conn, (int)$expiredRow['item_id']);
            $requestId = inventoryApprovalCreate($conn, [
                'type' => 'expired_disposal', 'branch_id' => (string)$branch_id, 'requested_by' => $user_id, 'source' => 'Inventory Officer',
                'item_id' => (int)$expiredRow['item_id'], 'stock_id' => (int)$stock_id, 'batch' => $expiredRow['batch_lot_no'],
                'item_name' => $itemData['item_name'], 'quantity' => stockQuantityDecimal($disposeScaled),
                'unit' => inventoryBaseUnitLabel($itemData), 'reason' => 'Expired',
                'remarks' => 'Dispose and archive the expired batch',
                'payload' => ['expiration_date' => $expiredRow['expiration_date']],
                'open_key' => 'dispose:' . (int)$stock_id,
            ]);
            $conn->commit();
            redirectWithMessage('success', 'Disposal request #' . $requestId . ' was sent: Pending approval. The batch stays in stock until the Branch Admin approves it.', 'expiration');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'expiration');
        }
    }

    /* ------------------- ADJUSTMENT (approval request) ------------------- */
    /*
     * The officer's count becomes a Pending request; stock changes only on
     * approval. The request keeps the system quantity at the time of the count,
     * so approval applies the counted difference (usage in between is kept).
     */
    if ($action === 'adjustment') {
        try {
            $adjustedScaled = stockQuantityParse($_POST['adjusted_quantity'] ?? null, true);
            $adjustedQuantity = stockQuantityDecimal($adjustedScaled);
        } catch (InvalidArgumentException $e) {
            redirectWithMessage('error', $e->getMessage(), 'adjustment');
        }
        $reason = trim($_POST['adjustment_reason'] ?? '');
        $selectedStockId = filter_input(INPUT_POST, 'stock_id', FILTER_VALIDATE_INT);
        $allowedReasons = ['Miscount / Physical Count Correction', 'Damaged', 'Contaminated', 'Expired', 'System Correction', 'Other'];

        if (!$item_id || $item_id <= 0 || $adjustedScaled < 0 || !$validDate || !in_array($reason, $allowedReasons, true)) {
            redirectWithMessage('error', 'Please enter a valid item, non-negative adjusted quantity, reason, and date.', 'adjustment');
        }
        if (mb_strlen($remarks) > 500) redirectWithMessage('error', 'Keep the remarks within 500 characters.', 'adjustment');

        $conn->begin_transaction();
        try {
            inventoryApprovalAssertReady($conn);
            $itemData = inventoryOpsItem($conn, (int)$item_id);
            if (!$selectedStockId || $selectedStockId <= 0) {
                throw new Exception('Please select the stock record you want to adjust.');
            }
            $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
                FROM inventory_stocks WHERE stock_id=? AND item_id=? AND branch_id=? LIMIT 1");
            $stockStmt->bind_param('iis', $selectedStockId, $item_id, $branch_id);
            $stockStmt->execute();
            $stockRow = $stockStmt->get_result()->fetch_assoc();
            $stockStmt->close();
            if (!$stockRow) throw new Exception('No matching stock record exists for the selected item in this branch.');

            stockQuantityValidateUnit($adjustedScaled, $itemData);
            $currentScaled = stockQuantityParse($stockRow['quantity_available'], true);
            if ($adjustedScaled === $currentScaled) {
                throw new Exception('The adjusted quantity is the same as the current stock. No change was made.');
            }
            $requestId = inventoryApprovalCreate($conn, [
                'type' => 'stock_adjustment', 'branch_id' => (string)$branch_id, 'requested_by' => $user_id, 'source' => 'Inventory Officer',
                'item_id' => (int)$item_id, 'stock_id' => (int)$selectedStockId, 'batch' => $stockRow['batch_lot_no'],
                'item_name' => $itemData['item_name'], 'quantity' => stockQuantityDecimal($adjustedScaled - $currentScaled),
                'unit' => inventoryBaseUnitLabel($itemData), 'reason' => $reason, 'remarks' => $remarks,
                'payload' => ['counted' => $adjustedQuantity, 'system_at_count' => stockQuantityDecimal($currentScaled),
                    'count_date' => $transaction_date],
                'open_key' => 'adjust:' . (int)$selectedStockId,
            ]);
            $conn->commit();
            redirectWithMessage('success', 'Adjustment request #' . $requestId . ' was sent: Pending approval. Stock changes only when the Branch Admin approves it.', 'requests');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'adjustment');
        }
    }

    /* ---------------------- RESTOCK REQUEST ---------------------- */
    /* Quantity in the item's counting unit (vials/ampules), like the Nurse's request. */
    if ($action === 'restock_request') {
        $requestedText = trim((string)($_POST['restock_quantity'] ?? ''));
        $reason = trim((string)($_POST['restock_reason'] ?? ''));
        if (!$item_id || $item_id <= 0 || $reason === '' || mb_strlen($reason) > 255 || mb_strlen($remarks) > 500) {
            redirectWithMessage('error', 'Select an item and enter the reason for the restock (up to 255 characters).', 'requests');
        }
        $conn->begin_transaction();
        try {
            inventoryApprovalAssertReady($conn);
            $itemData = inventoryOpsItem($conn, (int)$item_id);
            $displayScaled = stockQuantityParse($requestedText);
            $baseScaled = stockQuantityParse(number_format(inventoryDisplayToBase((float)stockQuantityDecimal($displayScaled), $itemData), 4, '.', ''));
            stockQuantityValidateUnit($baseScaled, $itemData);
            $requestId = inventoryApprovalCreate($conn, [
                'type' => 'restock', 'branch_id' => (string)$branch_id, 'requested_by' => $user_id, 'source' => 'Inventory Officer',
                'item_id' => (int)$item_id, 'item_name' => $itemData['item_name'], 'quantity' => stockQuantityDecimal($baseScaled),
                'unit' => inventoryBaseUnitLabel($itemData), 'reason' => $reason, 'remarks' => $remarks,
                'payload' => ['display_quantity' => stockQuantityDecimal($displayScaled), 'display_unit' => inventoryDisplayUnitLabel($itemData)],
                'open_key' => 'restock:' . $branch_id . ':' . (int)$item_id,
            ]);
            $conn->commit();
            redirectWithMessage('success', 'Restock request #' . $requestId . ' was sent: Pending approval.', 'requests');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'requests');
        }
    }

    /* ------------------ CANCEL OWN PENDING REQUEST ------------------ */
    if ($action === 'cancel_request') {
        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        try {
            if (!$requestId) throw new Exception('Select a request to cancel.');
            redirectWithMessage('success', inventoryApprovalCancel($conn, (int)$requestId, $user_id), 'requests');
        } catch (Throwable $e) {
            redirectWithMessage('error', $e->getMessage(), 'requests');
        }
    }
}

/* -------------------------------------------------------------
 * GET PARAMETERS
 * ----------------------------------------------------------- */

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = ($_GET['type'] ?? 'success') === 'error'
        ? 'error'
        : 'success';
}

/* Always read the requested Stock Management panel. */
if (isset($_GET['panel'])) {
    $activePanel = $_GET['panel'];
}

if (!in_array(
    $activePanel,
    ['stockIn', 'stockOut', 'adjustment', 'expiration', 'archived', 'requests'],
    true
)) {
    $activePanel = 'stockIn';
}

/* -------------------------------------------------------------
 * LOAD ITEMS
 * ----------------------------------------------------------- */
$items = [];
if ($branch_id) {
    /*
     * Item data is joined to categories and units.
     * Stock quantity is aggregated because medical supplies may have
     * multiple batches for the same item.
     */
    $itemsQuery = "SELECT i.item_id,i.item_name,i.minimum_stock,i.category_id,c.category_name,
    u.unit_id,COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name,
    COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label, COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label, COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
    COALESCE(s.usable_stock,0) AS quantity_available,
    COALESCE(s.expired_stock,0) AS expired_stock,
    COALESCE(s.total_stock,0) AS total_stock
FROM inventory_items i
JOIN inventory_categories c ON c.category_id=i.category_id
JOIN units u ON u.unit_id=i.unit_id
LEFT JOIN ($stockTotalsSql) s ON s.item_id=i.item_id AND s.branch_id=?
ORDER BY i.item_name";

    $stmt = $conn->prepare($itemsQuery);
    if ($stmt) {
        $stmt->bind_param('s', $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $items[] = $row;
        $stmt->close();
    }
}

/* -------------------------------------------------------------
 * LOAD BATCHES FOR BATCH-SPECIFIC ADJUSTMENT
 * ----------------------------------------------------------- */
$batchesByItem = [];
if ($branch_id) {
    $batchQuery = "SELECT s.stock_id, s.item_id, s.batch_lot_no, s.quantity_available, s.manufacturing_date, s.expiration_date,
                          i.item_name, c.category_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name,
                          COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label, COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label, COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base
                   FROM inventory_stocks s
                   INNER JOIN inventory_items i ON s.item_id = i.item_id
                   INNER JOIN inventory_categories c ON i.category_id = c.category_id
                   INNER JOIN units u ON i.unit_id = u.unit_id
                   WHERE s.branch_id = ?
                   ORDER BY i.item_name ASC, s.expiration_date ASC, s.stock_id ASC";

    $stmt = $conn->prepare($batchQuery);
    if ($stmt) {
        $stmt->bind_param('s', $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $itemKey = (string)$row['item_id'];
            if (!isset($batchesByItem[$itemKey])) $batchesByItem[$itemKey] = [];
            // Display-only text; quantity_available stays the numeric base value.
            $row['quantity_text'] = stockManagementFormQuantityText((float)$row['quantity_available'], $row);
            $batchesByItem[$itemKey][] = $row;
        }
        $stmt->close();
    }
}

/* -------------------------------------------------------------
 * EXPIRATION MONITORING
 * ----------------------------------------------------------- */
$expiringStock = [];
if ($branch_id) {
    $expirationQuery = "SELECT
                            s.stock_id,
                            s.item_id,
                            s.quantity_available,
                            s.batch_lot_no,
                            s.manufacturing_date,
                            s.expiration_date,
                            i.item_name,
                            COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name,
                            COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label, COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label, COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base
                        FROM inventory_stocks s
                        INNER JOIN inventory_items i ON s.item_id = i.item_id
                        INNER JOIN units u ON i.unit_id = u.unit_id
                        WHERE s.branch_id = ?
                          AND s.quantity_available > 0
                          AND s.expiration_date IS NOT NULL
                        ORDER BY s.expiration_date ASC, i.item_name ASC";

    $stmt = $conn->prepare($expirationQuery);
    if ($stmt) {
        $stmt->bind_param('s', $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $expiringStock[] = $row;
        $stmt->close();
    }
}

/* -------------------------------------------------------------
 * ARCHIVED / DISPOSED STOCKS
 * ----------------------------------------------------------- */
$archivedStocks = [];
if ($branch_id) {
    $archivedQuery = "SELECT
                          a.stock_id,
                          a.item_id,
                          a.quantity_available,
                          a.batch_lot_no,
                          a.manufacturing_date,
                          a.expiration_date,
                          a.last_updated,
                          a.archived_at,
                          a.archived_by,
                          a.archive_reason,
                          i.item_name,
                          COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name,
                          COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label, COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label, COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
                          COALESCE(au.username, 'Unknown User') AS archived_by_name
                      FROM inventory_stocks_archive a
                      INNER JOIN inventory_items i ON a.item_id = i.item_id
                      INNER JOIN units u ON i.unit_id = u.unit_id
                      LEFT JOIN users au ON a.archived_by = au.user_id
                      WHERE a.branch_id = ?
                      ORDER BY a.archived_at DESC, a.stock_id DESC";

    $stmt = $conn->prepare($archivedQuery);
    if ($stmt) {
        $stmt->bind_param('s', $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $archivedStocks[] = $row;
        $stmt->close();
    }
}

/* -------------------------------------------------------------
 * APPROVAL REQUESTS (My Requests, open restocks, pending markers)
 * ----------------------------------------------------------- */
$approvalsReady = inventoryApprovalReady($conn);
$myRequests = [];
$openRestocks = [];
$openRestocksByItem = [];
$pendingByStock = [];
if ($branch_id && $approvalsReady) {
    $myRequests = inventoryApprovalList($conn, ['branch_id' => (string)$branch_id, 'requested_by' => $user_id, 'limit' => 100]);
    $openRestocks = array_merge(
        inventoryApprovalList($conn, ['branch_id' => (string)$branch_id, 'statuses' => ['Pending'], 'types' => ['restock']]),
        inventoryApprovalOpenRestocks($conn, (string)$branch_id)
    );
    foreach (inventoryApprovalOpenRestocks($conn, (string)$branch_id) as $restock) {
        $openRestocksByItem[(string)$restock['item_id']][] = [
            'request_id' => (int)$restock['request_id'],
            'label' => '#' . $restock['request_id'] . ' — ' . ($restock['requester_name'] ?? 'user')
                . ' (' . ($restock['source'] ?: 'Request') . '), remaining ' . $restock['remaining_text']
                . ($restock['pending_delivery_text'] !== '' ? ', ' . $restock['pending_delivery_text'] . ' awaiting approval' : ''),
        ];
    }
    foreach (inventoryApprovalList($conn, ['branch_id' => (string)$branch_id, 'statuses' => ['Pending'],
        'types' => ['stock_adjustment', 'expired_disposal']]) as $pending) {
        if ($pending['stock_id']) $pendingByStock[(int)$pending['stock_id']][] = $pending;
    }
}

/* View one stock/batch record. */
if (isset($_GET['view_stock_id']) && ctype_digit((string)$_GET['view_stock_id']) && $branch_id) {
    $viewId = (int)$_GET['view_stock_id'];

    $viewQuery = "SELECT
                      s.stock_id,
                      s.item_id,
                      s.branch_id,
                      s.quantity_available,
                      s.batch_lot_no,
                      s.manufacturing_date,
                      s.expiration_date,
                      s.last_updated,
                      i.item_name,
                      i.minimum_stock,
                      COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS unit_name,
                      COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label, COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label, COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
                      c.category_name
                  FROM inventory_stocks s
                  INNER JOIN inventory_items i ON s.item_id = i.item_id
                  INNER JOIN units u ON i.unit_id = u.unit_id
                  INNER JOIN inventory_categories c ON i.category_id = c.category_id
                  WHERE s.stock_id = ? AND s.branch_id = ?
                  LIMIT 1";

    $stmt = $conn->prepare($viewQuery);
    if ($stmt) {
        $stmt->bind_param('is', $viewId, $branch_id);
        $stmt->execute();
        $viewStock = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock Management</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="sidebar.css">
<link rel="stylesheet" href="notif-num.css">


<style>
:root{
    --primary:#2B3A8C;
    --accent:#F21D2F;
    --bg:#F2F2F2;
}

body{
    background:#f0f2f5;
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

.tab-row{
    display:flex;
    gap:12px;
    margin-bottom:26px;
    flex-wrap:wrap;
}

.tab-btn{
    background:white;
    border:1px solid var(--primary);
    color:var(--primary);
    font-weight:600;
    font-size:14px;
    padding:10px 22px;
    border-radius:8px;
    cursor:pointer;
}

.tab-btn.active{
    background:var(--primary);
    color:white;
}

.form-card{
    background:white;
    border-radius:18px;
    padding:30px;
    box-shadow:0 3px 8px rgba(0,0,0,.08);
}

.form-card label{
    font-weight:600;
    color:var(--primary);
    font-size:14px;
    margin-bottom:6px;
}

.form-card .form-control,
.form-card .form-select{
    border-radius:10px;
    border:1px solid #dcdee8;
    padding:10px 14px;
    font-size:14px;
    background:white;
}

.form-card .form-control:focus,
.form-card .form-select:focus{
    border-color:var(--primary);
    box-shadow:none;
}

.form-card .form-control[readonly]{
    background:#eef0f7;
    color:#666;
}

.btn-custom{
    background:var(--primary);
    color:white;
    border-radius:8px;
    padding:12px 20px;
    border:none;
    font-weight:600;
    font-size:15px;
    width:100%;
    cursor:pointer;
}

.btn-custom:hover{
    background:#1d2863;
    color:white;
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

.section-title{
    font-size:18px;
    font-weight:700;
    color:var(--primary);
    margin-bottom:22px;
}

.table-wrap{
    background:white;
    border-radius:12px;
    border:1px solid #dfe1ee;
    overflow:hidden;
}

.data-table{margin:0;}

.data-table thead th{
    background:var(--primary);
    color:white;
    font-weight:600;
    font-size:13px;
    border:none;
    padding:14px;
    white-space:nowrap;
}

.data-table tbody td{
    font-size:14px;
    color:#333;
    padding:13px 14px;
    vertical-align:middle;
    border-bottom:1px solid #eef0f7;
}

.data-table tbody tr:last-child td{border-bottom:none;}
.data-table tbody tr:hover{background:#f7f8fc;}

.badge-status{
    display:inline-block;
    padding:5px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
}

.badge-good{background:#e6f4ea;color:#1e7b34;}
.badge-low{background:#FFEAEA;color:var(--accent);}
.badge-critical{background:var(--accent);color:white;}

.action-btn{
    border:1px solid #dcdee8;
    background:white;
    color:var(--primary);
    width:34px;
    height:34px;
    border-radius:8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
}

.action-btn:hover{
    background:var(--primary);
    color:white;
    border-color:var(--primary);
}

.filter-select{max-width:260px;}

.panel{display:none;}
.panel.active{display:block;}

.form-text-small{
    font-size:12px;
    color:#6c757d;
    margin-top:5px;
}

.alert-custom{
    border-radius:10px;
    border:0;
    font-weight:500;
}

.expiration-row.expired{background:#fff5f5;}

.archived-note{font-size:12px;color:#6c757d;}
.action-btn[type="submit"]{padding:0;}

.medical-field{
    display:block;
}

/* =========================================================
   CONFIRMATION MODAL
   ========================================================= */
.confirmation-modal .modal-dialog{
    max-width:460px;
    margin:1.75rem auto;
}

.confirmation-modal .modal-content{
    border:0;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 18px 50px rgba(31,42,68,.20);
    background:#fff;
}

.confirmation-modal .modal-header{
    background:#2B3A8C;
    color:#fff;
    border:0;
    padding:18px 22px;
    min-height:68px;
    display:flex;
    align-items:center;
}

.confirmation-modal .modal-title{
    margin:0;
    font-size:19px;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:8px;
}

.confirmation-modal .modal-title i{
    font-size:20px;
}

.confirmation-modal .btn-close{
    opacity:1;
    filter:brightness(0) invert(1);
    box-shadow:none;
}

.confirmation-modal .btn-close:focus{
    box-shadow:none;
}

.confirmation-modal .modal-body{
    padding:30px 28px 26px;
    text-align:center;
}

.confirmation-icon{
    width:58px;
    height:58px;
    margin:0 auto 18px;
    border-radius:50%;
    background:#eef1fb;
    color:#2B3A8C;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:27px;
}

.confirmation-message{
    margin:0;
    color:#2d3748;
    font-size:15px;
    line-height:1.55;
    font-weight:500;
}

.confirmation-modal .modal-footer{
    border-top:1px solid #edf0f5;
    padding:15px 22px;
    display:flex;
    justify-content:flex-end;
    gap:10px;
    background:#fff;
}

.confirmation-modal .btn-cancel,
.confirmation-modal .btn-confirm{
    min-width:100px;
    min-height:40px;
    border-radius:8px;
    padding:8px 16px;
    font-size:13px;
    font-weight:600;
    transition:all .15s ease;
}

.confirmation-modal .btn-cancel{
    background:#fff;
    color:#5f6877;
    border:1px solid #dfe3ec;
}

.confirmation-modal .btn-cancel:hover{
    background:#f5f6f9;
    border-color:#cfd5e1;
    color:#3f4858;
}

.confirmation-modal .btn-confirm{
    background:#2B3A8C;
    color:#fff;
    border:1px solid #2B3A8C;
}

.confirmation-modal .btn-confirm:hover{
    background:#1d2863;
    border-color:#1d2863;
    color:#fff;
    transform:translateY(-1px);
}

@media(max-width:576px){
    .confirmation-modal .modal-dialog{
        width:calc(100% - 24px);
        margin:.75rem auto;
    }

    .confirmation-modal .modal-header{
        padding:16px 18px;
    }

    .confirmation-modal .modal-title{
        font-size:17px;
    }

    .confirmation-modal .modal-body{
        padding:24px 20px 22px;
    }

    .confirmation-modal .modal-footer{
        padding:13px 16px;
    }
}

@media(max-width:991px){
    .main{margin-left:90px;}
}
</style>
</head>

<body>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="sidebar">

    <button type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            aria-expanded="true">
        <i class="bi bi-chevron-left"></i>
    </button>

    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
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
            <li><a class="active" href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
            <li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
            <li><a href="InventoryOfficer_ReturnManagement.php"><i class="bi bi-arrow-return-left"></i><span>Return Management</span></a></li>
            <li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
            <li><a  href="InventoryOfficer_Notifications.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a></li>
        </ul>
    </nav>
</div>

<div class="main">

    <div class="topbar">
        <h3>Stock Management<small><?php echo h($branch_name); ?></small></h3>
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

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?> alert-custom mb-4" role="alert">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <?php echo $branch_id ? inventoryApprovalPendingDeliveriesNote($conn, (string)$branch_id) : ''; ?>

        <div class="tab-row">
            <button type="button" class="tab-btn <?php echo $activePanel === 'stockIn' ? 'active' : ''; ?>" onclick="showPanel('stockIn', this)">Stock In</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'stockOut' ? 'active' : ''; ?>" onclick="showPanel('stockOut', this)">Stock Out</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'adjustment' ? 'active' : ''; ?>" onclick="showPanel('adjustment', this)">Adjustment</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'expiration' ? 'active' : ''; ?>" onclick="showPanel('expiration', this)">Expiration Monitoring</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'archived' ? 'active' : ''; ?>" onclick="showPanel('archived', this)">Archived Stocks</button>
            <?php $myPendingCount = count(array_filter($myRequests, static fn($r) => $r['status'] === 'Pending')); ?>
            <button type="button" class="tab-btn <?php echo $activePanel === 'requests' ? 'active' : ''; ?>" onclick="showPanel('requests', this)">My Requests<?php if ($myPendingCount): ?> <span class="badge rounded-pill text-bg-warning"><?php echo $myPendingCount; ?></span><?php endif; ?></button>
        </div>
        <?php if (!$approvalsReady): ?>
            <div class="alert alert-warning alert-custom mb-4">Stock Out, Adjustment, disposal and restock requests need the database update. Ask the administrator to run <code>php sources/migrate_inventory_approvals.php --apply</code>.</div>
        <?php endif; ?>

        <!-- =====================================================
             STOCK IN
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'stockIn' ? 'active' : ''; ?>" id="stockIn">
            <div class="form-card">
                <div class="section-title">Request Stock In</div>
                <div class="form-text-small mb-3">A delivery is sent to the Branch Admin for approval. The stock is added to inventory (and can be used) only when the request is approved, and it appears as Delivery in Daily Inventory on the approval date. A linked restock request is updated at that moment.</div>

                <form method="POST" action="InventoryOfficer_StockManagement.php" id="stockInForm" onsubmit="return validateStockInForm();">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="stock_in">
                    <input type="hidden" name="panel" value="stockIn">

                    <div class="row g-4">

                        <div class="col-md-6">
                            <label for="stockInItem">Item</label>
                            <select id="stockInItem" name="item_id" class="form-select" required>
                                <option value="">Select item...</option>
                                <?php itemOptions($items); ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="stockInQuantity">Quantity</label>
                            <input id="stockInQuantity" name="quantity" type="number" min="0.0001" max="99999999.9999" step="0.0001" class="form-control" placeholder="Enter quantity..." required>
                            <div id="stockInQuantityHelp" class="form-text-small">Select an item to see its quantity unit.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="stockInUnit">Unit</label>
                            <input id="stockInUnit" type="text" class="form-control" placeholder="Unit will appear automatically" readonly>
                            <input id="stockInUnitId" name="unit_id" type="hidden">
                        </div>

                        <!-- These appear ONLY for Medical Supplies (category_id = 2). -->
                        <div class="col-md-6 medical-field" id="batchLotWrapper">
                            <label for="stockInBatch">Batch/Lot No.</label>
                            <input id="stockInBatch" name="batch_lot_no" type="text" class="form-control" maxlength="100" placeholder="Enter Batch/Lot No...">
                        </div>

                        <div class="col-md-6 medical-field" id="manufacturingDateWrapper">
                            <label for="stockInManufacturingDate">Manufacturing Date</label>
                            <input id="stockInManufacturingDate" name="manufacturing_date" type="date" class="form-control">
                        </div>

                        <div class="col-md-6 medical-field" id="expirationDateWrapper">
                            <label for="stockInExpiry">Expiration Date</label>
                            <input id="stockInExpiry" name="expiration_date" type="date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label for="stockInDate">Date of Transaction</label>
                            <input id="stockInDate" name="transaction_date" type="date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="stockSourceType">Stock Source</label>
                            <select id="stockSourceType" name="stock_source_type" class="form-select" required onchange="updateStockSource()">
                                <option value="">Select source...</option>
                                <option value="Branch">Another clinic branch</option>
                                <option value="External">External supplier</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="stockSourceBranch">Source Branch</label>
                            <select id="stockSourceBranch" name="stock_source_branch_id" class="form-select" disabled>
                                <option value="">Select sending branch...</option>
                                <?php foreach ($sourceBranches as $source): ?>
                                    <?php if ((string)$source['branch_id']===(string)$branch_id) continue; ?>
                                    <option value="<?php echo h($source['branch_id']); ?>"><?php echo h($source['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="stockSourceName">Supplier Name / Reference (Optional)</label>
                            <input id="stockSourceName" name="stock_source_name" class="form-control" maxlength="255" disabled>
                        </div>
                        <div class="col-md-6">
                            <label for="stockInRestock">Restock Request (Optional)</label>
                            <select id="stockInRestock" name="restock_request_id" class="form-select">
                                <option value="">No request</option>
                            </select>
                            <div id="stockInRestockHelp" class="form-text-small">Link this delivery to an approved restock request. A partial delivery keeps the request open with the remaining quantity.</div>
                        </div>
                        <div class="col-12">
                            <label for="stockInRemarks">Remarks</label>
                            <textarea id="stockInRemarks" name="remarks" class="form-control" rows="2" maxlength="500" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>

                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn-custom">Send for Approval</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =====================================================
             STOCK OUT
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'stockOut' ? 'active' : ''; ?>" id="stockOut">
            <div class="form-card">
                <div class="section-title">Request Stock Out / Pull-out</div>
                <div class="form-text-small mb-3">Stock Out, pull-out and disposal of damaged, contaminated, expired or lost stock need the Branch Admin's approval. Stock changes only when the request is approved, and the movement appears as Pull-out in Daily Inventory on the approval date. Patient usage is recorded by the Nurse in Assessment, Vaccination and Medical Supplies.</div>

                <form method="POST" action="InventoryOfficer_StockManagement.php" onsubmit="return openConfirmationModal(this, 'Send this Stock Out request to the Branch Admin for approval?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="stock_out">
                    <input type="hidden" name="panel" value="stockOut">

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="stockOutItem">Item</label>
                            <select id="stockOutItem" name="item_id" class="form-select" required>
                                <option value="">Select item...</option>
                                <?php itemOptions($items); ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="stockOutQuantity">Quantity</label>
                            <input id="stockOutQuantity" name="quantity" type="number" min="0.0001" max="99999999.9999" step="0.0001" class="form-control" placeholder="Enter quantity..." required>
                            <div id="stockOutQuantityHelp" class="form-text-small">Select an item to see its quantity unit.</div>
                            <div id="stockOutAvailable" class="form-text-small">Usable stock available: —</div>
                            <div id="stockOutWarning" class="form-text-small"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="stockOutReason">Reason</label>
                            <select id="stockOutReason" name="reason" class="form-select" required>
                                <option value="">Select reason...</option>
                                <?php foreach (INVENTORY_STOCK_OUT_REASONS as $outReason): ?>
                                    <option><?php echo h($outReason); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="stockOutDate">Date Noted</label>
                            <input id="stockOutDate" name="transaction_date" type="date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required>
                            <div class="form-text-small">The stock movement is dated the day the Branch Admin approves it.</div>
                        </div>

                        <div class="col-12">
                            <label for="stockOutRemarks">Remarks</label>
                            <textarea id="stockOutRemarks" name="remarks" class="form-control" rows="2" maxlength="500" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn-custom">Send for Approval</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =====================================================
             ADJUSTMENT
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'adjustment' ? 'active' : ''; ?>" id="adjustment">
            <div class="form-card">
                <div class="section-title">Request Stock Adjustment</div>
                <div class="form-text-small mb-3">An adjustment is sent to the Branch Admin for approval. Stock changes only when it is approved; the counted difference is then applied to the batch.</div>

                <form method="POST" action="InventoryOfficer_StockManagement.php" onsubmit="return openConfirmationModal(this, 'Send this Stock Adjustment to the Branch Admin for approval?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="adjustment">
                    <input type="hidden" name="panel" value="adjustment">

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="adjustmentItem">Item</label>
                            <select id="adjustmentItem" name="item_id" class="form-select" required>
                                <option value="">Select item...</option>
                                <?php itemOptions($items); ?>
                            </select>
                        </div>

                        <div class="col-md-6" id="adjustmentBatchWrapper" style="display:none;">
                            <label for="adjustmentBatch">Stock Record / Batch</label>
                            <select id="adjustmentBatch" name="stock_id" class="form-select">
                                <option value="">Select batch...</option>
                            </select>
                            <div class="form-text-small">Select the specific stock record whose remaining quantity you counted.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="currentStock">Current Stock</label>
                            <input id="currentStock" type="text" class="form-control" value="—" readonly>
                            <div id="adjustmentStockHelp" class="form-text-small">Select an item to view current stock.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustedQuantity">Adjusted Quantity</label>
                            <input id="adjustedQuantity" name="adjusted_quantity" type="number" min="0" max="99999999.9999" step="0.0001" class="form-control" placeholder="Enter new quantity..." required>
                            <div id="adjustedQuantityHelp" class="form-text-small">Enter the verified remaining stock in the selected item’s unit.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustmentReason">Reason for Adjustment</label>
                            <select id="adjustmentReason" name="adjustment_reason" class="form-select" required>
                                <option value="">Select reason...</option>
                                <option>Miscount / Physical Count Correction</option>
                                <option>Damaged</option>
                                <option>Contaminated</option>
                                <option>Expired</option>
                                <option>System Correction</option>
                                <option>Other</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustmentDate">Count Date</label>
                            <input id="adjustmentDate" name="transaction_date" type="date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustmentRemarks">Remarks</label>
                            <textarea id="adjustmentRemarks" name="remarks" class="form-control" rows="1" maxlength="500" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn-custom">Send for Approval</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =====================================================
             EXPIRATION MONITORING
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'expiration' ? 'active' : ''; ?>" id="expiration">
            <div class="form-card" style="background:transparent;padding:0;box-shadow:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div class="section-title mb-0">Expiration Monitoring</div>
                    <select id="expirationFilter" class="form-select filter-select" aria-label="Expiration filter">
                        <option value="7">Expiring within 7 days</option>
                        <option value="30">Expiring within 30 days</option>
                        <option value="60">Expiring within 60 days</option>
                        <option value="expired">Already Expired</option>
                        <option value="all">All Expiration Dates</option>
                    </select>
                </div>

                <div class="table-wrap">
                    <table class="table data-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch/Lot No.</th>
                                <th>Stock</th>
                                <th>Manufacturing Date</th>
                                <th>Expiration Date</th>
                                <th>Days Remaining</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="expirationBody">
                        <?php if (!$expiringStock): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No expiration records found for this branch.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expiringStock as $row): ?>
                                <?php [$status, $badgeClass, $days] = expiryStatus($row['expiration_date']); ?>
                                <tr class="expiration-row" data-days="<?php echo h($days); ?>" data-expired="<?php echo $days !== null && $days < 0 ? '1' : '0'; ?>">
                                    <td><?php echo h($row['item_name']); ?></td>
                                    <td><?php echo h($row['batch_lot_no'] ?: '—'); ?></td>
                                    <td><?php echo h(inventoryQuantityText((float)$row['quantity_available'], $row)); ?></td>
                                    <td><?php echo $row['manufacturing_date'] ? h(date('m/d/Y', strtotime($row['manufacturing_date']))) : '—'; ?></td>
                                    <td><?php echo h(date('m/d/Y', strtotime($row['expiration_date']))); ?></td>
                                    <td><?php echo $days === null ? '—' : ($days < 0 ? abs($days) . ' days ago' : $days . ' days'); ?></td>
                                    <td><span class="badge-status <?php echo h($badgeClass); ?>"><?php echo h($status); ?></span></td>
                                    <td class="text-center">
                                        <a class="action-btn" title="View Stock Details" href="?panel=expiration&view_stock_id=<?php echo h($row['stock_id']); ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php $rowPending = array_values(array_filter($pendingByStock[(int)$row['stock_id']] ?? [], static fn($p) => $p['request_type'] === 'expired_disposal')); ?>
                                        <?php if ($rowPending): ?>
                                            <span class="badge text-bg-warning" title="Waiting for the Branch Admin">Pending approval #<?php echo (int)$rowPending[0]['request_id']; ?></span>
                                        <?php elseif ($days !== null && $days < 0 && (float)$row['quantity_available'] > 0): ?>
                                            <form method="POST" action="InventoryOfficer_StockManagement.php" style="display:inline;" onsubmit="return openConfirmationModal(this, 'Request approval to dispose the remaining expired stock and archive this batch? The batch stays in stock until the Branch Admin approves.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                <input type="hidden" name="action" value="archive_expired">
                                                <input type="hidden" name="panel" value="expiration">
                                                <input type="hidden" name="stock_id" value="<?php echo (int)$row['stock_id']; ?>">
                                                <button type="submit" class="action-btn" title="Request disposal & archive (needs Branch Admin approval)" style="color:#dc3545;">
                                                    <i class="bi bi-archive-fill"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                            <tr id="noExpirationMatch" style="display:none;">
                                <td colspan="8" class="text-center py-4 text-muted">No records match the selected filter.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =====================================================
             ARCHIVED STOCKS
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'archived' ? 'active' : ''; ?>" id="archived">
            <div class="form-card" style="background:transparent;padding:0;box-shadow:none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <div class="section-title mb-1">Archived Stocks</div>
                        <div class="form-text-small">Expired batches appear here after their remaining quantity is disposed through Stock Out and removed from active inventory.</div>
                    </div>
                    <span class="badge bg-secondary rounded-pill"><?php echo count($archivedStocks); ?> archived</span>
                </div>

                <div class="table-wrap">
                    <table class="table data-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch/Lot No.</th>
                                <th>Disposed Qty</th>
                                <th>Expiration Date</th>
                                <th>Archived At</th>
                                <th>Archived By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$archivedStocks): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No archived stock records found for this branch.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($archivedStocks as $archived): ?>
                                <tr>
                                    <td><?php echo h($archived['item_name']); ?></td>
                                    <td><?php echo h($archived['batch_lot_no'] ?: '—'); ?></td>
                                    <td><?php echo h(inventoryQuantityText((float)$archived['quantity_available'], $archived)); ?></td>
                                    <td><?php echo $archived['expiration_date'] ? h(date('m/d/Y', strtotime($archived['expiration_date']))) : '—'; ?></td>
                                    <td><?php echo $archived['archived_at'] ? h(date('m/d/Y h:i A', strtotime($archived['archived_at']))) : '—'; ?></td>
                                    <td><?php echo h($archived['archived_by_name']); ?></td>
                                    <td><?php echo h($archived['archive_reason'] ?: 'Expired stock disposed and archived'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =====================================================
             MY REQUESTS (approval requests of this officer)
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'requests' ? 'active' : ''; ?>" id="requests">
            <div class="form-card mb-4">
                <div class="section-title">Request Restock</div>
                <div class="form-text-small mb-3">The Branch Admin approves or rejects the request (and may adjust the quantity). When the delivery arrives, record Stock In and link it to the request.</div>
                <form method="POST" action="InventoryOfficer_StockManagement.php" onsubmit="return openConfirmationModal(this, 'Send this restock request to the Branch Admin?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="restock_request">
                    <input type="hidden" name="panel" value="requests">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="restockItem">Item</label>
                            <select id="restockItem" name="item_id" class="form-select" required>
                                <option value="">Select item...</option>
                                <?php foreach ($items as $restockItem): ?>
                                    <option value="<?php echo (int)$restockItem['item_id']; ?>" data-display-unit="<?php echo h(inventoryUnitWord(inventoryDisplayUnitLabel($restockItem), 2)); ?>" data-stock-text="<?php echo h(inventoryQuantityText((float)$restockItem['quantity_available'], $restockItem)); ?>"><?php echo h($restockItem['item_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="restockItemHelp" class="form-text-small">Select an item to see its usable stock.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="restockQuantity">Quantity Needed</label>
                            <input id="restockQuantity" name="restock_quantity" type="number" min="1" step="1" max="99999" class="form-control" required>
                            <div id="restockQuantityHelp" class="form-text-small">In vials, ampules or the item's counting unit.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="restockReason">Reason</label>
                            <input id="restockReason" name="restock_reason" class="form-control" maxlength="255" required placeholder="e.g. Low stock before the weekend">
                        </div>
                        <div class="col-md-6">
                            <label for="restockRemarks">Remarks</label>
                            <textarea id="restockRemarks" name="remarks" class="form-control" rows="1" maxlength="500" placeholder="Optional"></textarea>
                        </div>
                    </div>
                    <div class="mt-4"><button type="submit" class="btn-custom" <?php echo $approvalsReady ? '' : 'disabled'; ?>>Send Restock Request</button></div>
                </form>
            </div>

            <div class="form-card mb-4" style="background:transparent;padding:0;box-shadow:none;">
                <div class="section-title mb-1">Branch Restock Requests</div>
                <div class="form-text-small mb-3">Pending and approved restock requests of the branch (from Nurses, Inventory Officers and Supply Forecasting). Link a Stock In to an approved request when the delivery arrives.</div>
                <div class="table-wrap">
                    <table class="table data-table">
                        <thead><tr><th>Request</th><th>Item</th><th>Requested</th><th>Approved</th><th>Remaining</th><th>Requested By</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (!$openRestocks): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No open restock requests.</td></tr>
                        <?php else: foreach ($openRestocks as $restock): ?>
                            <tr>
                                <td>#<?php echo (int)$restock['request_id']; ?><div class="form-text-small"><?php echo h(date('m/d/Y h:i A', strtotime($restock['requested_at']))); ?></div></td>
                                <td><?php echo h($restock['item_name']); ?></td>
                                <td><?php echo h(inventoryApprovalQuantityText($restock['quantity'], $restock)); ?></td>
                                <td><?php echo $restock['status'] === 'Pending' ? '—' : h(inventoryApprovalQuantityText($restock['approved_quantity'] ?? $restock['quantity'], $restock)); ?></td>
                                <td><?php echo h($restock['remaining_text'] ?? '—'); ?></td>
                                <td><?php echo h(($restock['requester_name'] ?? '') . ' (' . ($restock['source'] ?: ($restock['requester_role'] ?? '')) . ')'); ?></td>
                                <td><span class="badge <?php echo h(inventoryApprovalStatusClass($restock['status'])); ?>"><?php echo h($restock['status'] === 'Pending' ? 'Pending approval' : $restock['status']); ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-card" style="background:transparent;padding:0;box-shadow:none;">
                <div class="section-title mb-1">My Requests</div>
                <div class="form-text-small mb-3">Stock Out, disposal, adjustment and restock requests you sent. You can cancel a request while it is Pending.</div>
                <div class="table-wrap">
                    <table class="table data-table">
                        <thead><tr><th>Request</th><th>Type</th><th>Details</th><th>Reason / Remarks</th><th>Status</th><th>Decision</th><th class="text-center">Action</th></tr></thead>
                        <tbody>
                        <?php if (!$myRequests): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">You have not sent any requests yet.</td></tr>
                        <?php else: foreach ($myRequests as $req): ?>
                            <tr>
                                <td>#<?php echo (int)$req['request_id']; ?><div class="form-text-small"><?php echo h(date('m/d/Y h:i A', strtotime($req['requested_at']))); ?></div></td>
                                <td><?php echo h(inventoryApprovalTypeLabel($req['request_type'])); ?></td>
                                <td><?php echo h(ucfirst($req['summary'])); ?></td>
                                <td><?php echo h(trim(($req['reason'] ?? '') . (($req['remarks'] ?? '') !== '' ? ' — ' . $req['remarks'] : ''))); ?></td>
                                <td><span class="badge <?php echo h(inventoryApprovalStatusClass($req['status'])); ?>"><?php echo h($req['status'] === 'Pending' ? 'Pending approval' : $req['status']); ?></span></td>
                                <td><?php if ($req['decided_at']): ?><?php echo h(($req['decider_name'] ?? '') . ', ' . date('m/d/Y h:i A', strtotime($req['decided_at']))); ?><?php if ($req['decision_remarks']): ?><div class="form-text-small"><?php echo h($req['decision_remarks']); ?></div><?php endif; ?><?php else: ?>—<?php endif; ?></td>
                                <td class="text-center">
                                    <?php if ($req['status'] === 'Pending'): ?>
                                        <form method="POST" action="InventoryOfficer_StockManagement.php" style="display:inline;" onsubmit="return openConfirmationModal(this, 'Cancel request #<?php echo (int)$req['request_id']; ?>? Nothing will be changed.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="cancel_request">
                                            <input type="hidden" name="panel" value="requests">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel</button>
                                        </form>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php if ($viewStock): ?>
    <?php [$viewStatus, $viewBadge, $viewDays] = expiryStatus($viewStock['expiration_date']); ?>
    <div class="modal fade show" id="viewStockModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.5);" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Stock Details</h5>
                    <a href="InventoryOfficer_StockManagement.php?panel=expiration" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12"><strong>Item</strong><div><?php echo h($viewStock['item_name']); ?></div></div>
                        <div class="col-md-6"><strong>Category</strong><div><?php echo h($viewStock['category_name']); ?></div></div>
                        <div class="col-md-6"><strong>Unit</strong><div><?php $viewConversion = stockManagementConversionText($viewStock); echo h(stockManagementHasCountingUnit($viewStock) ? inventoryDisplayUnitLabel($viewStock) . ' (' . $viewConversion . ')' : $viewStock['unit_name']); ?></div></div>
                        <div class="col-md-6"><strong>Current Stock</strong><div><?php echo h(inventoryQuantityText((float)$viewStock['quantity_available'], $viewStock)); ?></div></div>
                        <div class="col-md-6"><strong>Minimum Stock</strong><div><?php echo h(inventoryQuantityText((float)$viewStock['minimum_stock'], $viewStock)); ?></div></div>
                        <div class="col-md-6"><strong>Batch/Lot No.</strong><div><?php echo h($viewStock['batch_lot_no'] ?: '—'); ?></div></div>
                        <div class="col-md-6"><strong>Manufacturing Date</strong><div><?php echo $viewStock['manufacturing_date'] ? h(date('m/d/Y', strtotime($viewStock['manufacturing_date']))) : '—'; ?></div></div>
                        <div class="col-md-6"><strong>Expiration Date</strong><div><?php echo $viewStock['expiration_date'] ? h(date('m/d/Y', strtotime($viewStock['expiration_date']))) : '—'; ?></div></div>
                        <div class="col-md-6"><strong>Status</strong><div><span class="badge-status <?php echo h($viewBadge); ?>"><?php echo h($viewStatus); ?></span></div></div>
                        <div class="col-12"><strong>Last Updated</strong><div><?php echo h($viewStock['last_updated']); ?></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php $viewPending = array_values(array_filter($pendingByStock[(int)$viewStock['stock_id']] ?? [], static fn($p) => $p['request_type'] === 'expired_disposal')); ?>
                    <?php if ($viewPending): ?>
                        <span class="badge text-bg-warning me-auto">Disposal pending approval #<?php echo (int)$viewPending[0]['request_id']; ?></span>
                    <?php elseif ($viewDays !== null && $viewDays < 0 && (float)$viewStock['quantity_available'] > 0): ?>
                        <form method="POST" action="InventoryOfficer_StockManagement.php" class="me-auto" onsubmit="return openConfirmationModal(this, 'Request approval to dispose the remaining expired stock and archive this batch?');">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="action" value="archive_expired">
                            <input type="hidden" name="panel" value="expiration">
                            <input type="hidden" name="stock_id" value="<?php echo (int)$viewStock['stock_id']; ?>">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-archive-fill me-1"></i> Request Disposal
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="InventoryOfficer_StockManagement.php?panel=expiration" class="btn btn-secondary">Close</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- CONFIRMATION MODAL -->
<div class="modal fade confirmation-modal" id="confirmationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-question-circle me-2"></i>
                    Confirm Action
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="bi bi-question-lg"></i>
                </div>
                <p class="confirmation-message" id="confirmationMessage">Are you sure you want to continue?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-confirm" id="confirmationConfirmBtn">Confirm</button>
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
<?php require_once __DIR__ . '/sources/confirm_modal.php'; echo sbcConfirmModalScript(); ?>
<script src="sources/sidebar.js"></script>
<script>
/* REUSABLE CONFIRMATION MODAL */
let pendingConfirmationForm = null;

function openConfirmationModal(form, message) {
    const modalElement = document.getElementById('confirmationModal');
    const messageElement = document.getElementById('confirmationMessage');
    const confirmButton = document.getElementById('confirmationConfirmBtn');

    if (!modalElement || !messageElement || !confirmButton) {
        return false;
    }

    pendingConfirmationForm = form;
    messageElement.textContent = message;

    confirmButton.onclick = function () {
        if (!pendingConfirmationForm) {
            return;
        }

        const formToSubmit = pendingConfirmationForm;
        pendingConfirmationForm = null;

        const modalInstance = bootstrap.Modal.getInstance(modalElement);
        if (modalInstance) {
            modalInstance.hide();
        }

        formToSubmit.submit();
    };

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    modal.show();

    return false;
}

document.getElementById('confirmationModal')?.addEventListener('hidden.bs.modal', function () {
    pendingConfirmationForm = null;

    const confirmButton = document.getElementById('confirmationConfirmBtn');
    if (confirmButton) {
        confirmButton.onclick = null;
    }
});

function showPanel(id, btn) {
    document.querySelectorAll('.panel').forEach(function(panel) {
        panel.classList.remove('active');
    });

    document.querySelectorAll('.tab-btn').forEach(function(button) {
        button.classList.remove('active');
    });

    var panel = document.getElementById(id);
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');

    history.replaceState(null, '', 'InventoryOfficer_StockManagement.php?panel=' + encodeURIComponent(id));
}

/* -------------------------------------------------------------
 * STOCK IN ITEM DETAILS
 * ----------------------------------------------------------- */
function updateQuantityInput(selectId, quantityId, helpId, allowZero) {
    var select = document.getElementById(selectId);
    var quantity = document.getElementById(quantityId);
    var help = document.getElementById(helpId);
    if (!select || !quantity) return;
    var option = select.options[select.selectedIndex];
    var selected = option && option.value;
    var step = selected ? (option.getAttribute('data-quantity-step') || '0.0001') : '0.0001';
    quantity.step = step;
    quantity.min = allowZero ? '0' : step;
    quantity.max = step === '1' ? '99999999' : '99999999.9999';
    if (help) {
        var unit = selected ? (option.getAttribute('data-unit-hint') || option.getAttribute('data-unit-name')) : '';
        help.textContent = selected
            ? 'Enter ' + (allowZero ? 'remaining stock' : 'quantity') + ' in ' + unit + '. '
                + (step === '1' ? 'Use whole numbers.' : 'Up to four decimal places are allowed.')
            : 'Select an item to see its quantity unit.';
    }
}

function updateStockInItemDetails() {
    updateQuantityInput('stockInItem', 'stockInQuantity', 'stockInQuantityHelp', false);
    var select = document.getElementById('stockInItem');
    var unitInput = document.getElementById('stockInUnit');
    var unitIdInput = document.getElementById('stockInUnitId');

    var batchWrapper = document.getElementById('batchLotWrapper');
    var manufacturingWrapper = document.getElementById('manufacturingDateWrapper');
    var expirationWrapper = document.getElementById('expirationDateWrapper');

    var batchInput = document.getElementById('stockInBatch');
    var manufacturingInput = document.getElementById('stockInManufacturingDate');
    var expirationInput = document.getElementById('stockInExpiry');

    if (!select) return;

    var option = select.options[select.selectedIndex];

    if (!option || !option.value) {
        unitInput.value = '';
        unitIdInput.value = '';

        batchWrapper.style.display = 'none';
        manufacturingWrapper.style.display = 'none';
        expirationWrapper.style.display = 'none';

        batchInput.required = false;
        manufacturingInput.required = false;
        expirationInput.required = false;
        return;
    }

    /* Unit always remains visible. */
    unitInput.value = option.getAttribute('data-unit-name') || '';
    unitIdInput.value = option.getAttribute('data-unit-id') || '';

    /* Vaccines and Medical Supplies require batch/expiration tracking. */
    var requiresBatchTracking = option.getAttribute('data-batch-tracked') === '1';

    if (requiresBatchTracking) {
        batchWrapper.style.display = 'block';
        manufacturingWrapper.style.display = 'block';
        expirationWrapper.style.display = 'block';

        batchInput.required = true;
        manufacturingInput.required = true;
        expirationInput.required = true;
    } else {
        batchWrapper.style.display = 'none';
        manufacturingWrapper.style.display = 'none';
        expirationWrapper.style.display = 'none';

        batchInput.required = false;
        manufacturingInput.required = false;
        expirationInput.required = false;

        batchInput.value = '';
        manufacturingInput.value = '';
        expirationInput.value = '';
    }
}

function updateStockSource() {
    const kind=document.getElementById('stockSourceType').value;
    const branch=document.getElementById('stockSourceBranch');
    branch.disabled=kind!=='Branch'; branch.required=kind==='Branch';
    if (branch.disabled) branch.value='';
    const name=document.getElementById('stockSourceName');
    name.disabled=kind!=='External'; if (name.disabled) name.value='';
}

function validateStockInForm() {
    const source=document.getElementById('stockSourceType').value;
    if (!source || (source==='Branch' && !document.getElementById('stockSourceBranch').value)) { SbcDialog.alert('Select the stock source and sending branch.'); return false; }
    var select = document.getElementById('stockInItem');
    var quantity = document.getElementById('stockInQuantity');
    var date = document.getElementById('stockInDate');

    if (!select.value) {
        SbcDialog.alert('Please select an item.');
        return false;
    }

    if (!quantity.value || Number(quantity.value) <= 0) {
        SbcDialog.alert('Please enter a valid quantity.');
        return false;
    }

    if (!date.value) {
        SbcDialog.alert('Please enter the Stock In date.');
        return false;
    }

    var option = select.options[select.selectedIndex];
    var requiresBatchTracking = option.getAttribute('data-batch-tracked') === '1';

    if (requiresBatchTracking) {
        var batch = document.getElementById('stockInBatch').value.trim();
        var mfg = document.getElementById('stockInManufacturingDate').value;
        var exp = document.getElementById('stockInExpiry').value;

        if (!batch) {
            SbcDialog.alert('Please enter the Batch/Lot No.');
            return false;
        }

        if (!mfg) {
            SbcDialog.alert('Please enter the Manufacturing Date.');
            return false;
        }

        if (!exp) {
            SbcDialog.alert('Please enter the Expiration Date.');
            return false;
        }

        if (exp <= mfg) {
            SbcDialog.alert('Expiration Date must be later than Manufacturing Date.');
            return false;
        }


        var today = sbcManilaToday();
        if (exp < today) {
            SbcDialog.alert('Expired stock cannot be received. Please enter a non-expired batch.');
            return false;
        }
    }

    return openConfirmationModal(document.getElementById('stockInForm'), 'Send this Stock In to the Branch Admin for approval?');
}

/* -------------------------------------------------------------
 * STOCK DISPLAY
 * ----------------------------------------------------------- */
function updateStockDisplay(selectId, displayId, prefix) {
    var select = document.getElementById(selectId);
    var display = document.getElementById(displayId);
    if (!select || !display) return;

    var option = select.options[select.selectedIndex];

    if (!option || !option.value) {
        if (prefix === 'current') display.value = '—';
        else display.textContent = 'Usable stock available: —';
        return;
    }

    var stock = option.getAttribute('data-stock') || '0';
    var unit = option.getAttribute('data-unit-name') || '';
    var stockText = option.getAttribute('data-stock-text') || (stock + ' ' + unit);

    if (prefix === 'current') {
        display.value = stockText;
    } else {
        display.textContent = 'Usable stock available: ' + stockText;
    }
}

function updateStockOutDisplay() {
    updateQuantityInput('stockOutItem', 'stockOutQuantity', 'stockOutQuantityHelp', false);
    var select = document.getElementById('stockOutItem');
    var reasonSelect = document.getElementById('stockOutReason');
    var display = document.getElementById('stockOutAvailable');
    if (!select || !reasonSelect || !display) return;

    var option = select.options[select.selectedIndex];
    if (!option || !option.value) {
        display.textContent = 'Usable stock available: —';
        return;
    }

    var unit = option.getAttribute('data-unit-name') || '';
    var reason = reasonSelect.value || '';

    if (reason === 'Expired') {
        var expiredStock = option.getAttribute('data-expired-stock') || '0';
        display.textContent = 'Expired stock available for disposal: '
            + (option.getAttribute('data-expired-stock-text') || (expiredStock + ' ' + unit));
    } else {
        var usableStock = option.getAttribute('data-stock') || '0';
        display.textContent = 'Usable stock available: '
            + (option.getAttribute('data-stock-text') || (usableStock + ' ' + unit));
    }
}

/* -------------------------------------------------------------
 * BATCH-SPECIFIC ADJUSTMENT
 * ----------------------------------------------------------- */
var batchesByItem = <?php echo json_encode($batchesByItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var openRestocksByItem = <?php echo json_encode((object)$openRestocksByItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;
var pendingAdjustmentByStock = <?php
    $pendingAdjustments = [];
    foreach ($pendingByStock as $pendingStockId => $pendingRows) {
        foreach ($pendingRows as $pendingRow) {
            if ($pendingRow['request_type'] === 'stock_adjustment') $pendingAdjustments[(string)$pendingStockId] = (int)$pendingRow['request_id'];
        }
    }
    echo json_encode((object)$pendingAdjustments);
?>;

/* Approved restock requests of the selected item, for linking a Stock In. */
function updateStockInRestockOptions() {
    var item = document.getElementById('stockInItem');
    var select = document.getElementById('stockInRestock');
    var help = document.getElementById('stockInRestockHelp');
    if (!item || !select) return;
    var current = select.value;
    select.innerHTML = '<option value="">No request</option>';
    var rows = openRestocksByItem[String(item.value)] || [];
    rows.forEach(function (row) {
        var opt = document.createElement('option');
        opt.value = row.request_id;
        opt.textContent = row.label;
        select.appendChild(opt);
    });
    if (current && rows.some(function (row) { return String(row.request_id) === current; })) select.value = current;
    if (help) help.textContent = rows.length
        ? 'Link this delivery to an approved restock request. A partial delivery keeps the request open with the remaining quantity.'
        : 'No approved restock request is open for this item. The Stock In is recorded as "No request".';
}

function updateRestockRequestHelp() {
    var item = document.getElementById('restockItem');
    var help = document.getElementById('restockItemHelp');
    var qtyHelp = document.getElementById('restockQuantityHelp');
    if (!item) return;
    var option = item.options[item.selectedIndex];
    if (!option || !option.value) {
        if (help) help.textContent = 'Select an item to see its usable stock.';
        if (qtyHelp) qtyHelp.textContent = "In vials, ampules or the item's counting unit.";
        return;
    }
    if (help) help.textContent = 'Usable stock now: ' + (option.getAttribute('data-stock-text') || '—');
    if (qtyHelp) qtyHelp.textContent = 'Enter whole ' + (option.getAttribute('data-display-unit') || 'units') + '.';
}

function updateAdjustmentItemDetails() {
    updateQuantityInput('adjustmentItem', 'adjustedQuantity', 'adjustedQuantityHelp', true);
    var itemSelect = document.getElementById('adjustmentItem');
    var batchWrapper = document.getElementById('adjustmentBatchWrapper');
    var batchSelect = document.getElementById('adjustmentBatch');
    var currentStock = document.getElementById('currentStock');
    var stockHelp = document.getElementById('adjustmentStockHelp');

    if (!itemSelect || !batchWrapper || !batchSelect || !currentStock) return;

    var option = itemSelect.options[itemSelect.selectedIndex];
    batchSelect.innerHTML = '<option value="">Select batch...</option>';

    if (!option || !option.value) {
        batchWrapper.style.display = 'none';
        batchSelect.required = false;
        currentStock.value = '—';
        if (stockHelp) stockHelp.textContent = 'Select an item to view current stock.';
        return;
    }

    var unit = option.getAttribute('data-unit-name') || '';
    var batches = batchesByItem[String(option.value)] || [];
    batchWrapper.style.display = 'block';
    batchSelect.required = true;
    currentStock.value = '—';
    if (stockHelp) stockHelp.textContent = batches.length
        ? 'Select the stock record whose remaining quantity you counted.'
        : 'No stock record exists for this item in your branch. Request a Stock In first.';
    batches.forEach(function(batch) {
        var opt = document.createElement('option');
        opt.value = batch.stock_id;
        opt.setAttribute('data-stock', batch.quantity_available);
        opt.setAttribute('data-unit', batch.unit_name || unit);
        opt.setAttribute('data-stock-text', batch.quantity_text || (batch.quantity_available + ' ' + (batch.unit_name || unit)));
        opt.setAttribute('data-expiration', batch.expiration_date || '');
        var label = batch.batch_lot_no || ('Unlabelled stock #' + batch.stock_id);
        label += ' — ' + (batch.quantity_text || (batch.quantity_available + ' ' + (batch.unit_name || unit)));
        if (batch.expiration_date) label += ' — Exp: ' + batch.expiration_date;
        if (pendingAdjustmentByStock[String(batch.stock_id)]) {
            label += ' — adjustment #' + pendingAdjustmentByStock[String(batch.stock_id)] + ' pending approval';
            opt.disabled = true;
        }
        opt.textContent = label;
        batchSelect.appendChild(opt);
    });
    if (batches.length === 1) {
        batchSelect.value = String(batches[0].stock_id);
        updateAdjustmentBatchStock();
    }

}

function updateAdjustmentBatchStock() {
    var batchSelect = document.getElementById('adjustmentBatch');
    var currentStock = document.getElementById('currentStock');
    var stockHelp = document.getElementById('adjustmentStockHelp');
    if (!batchSelect || !currentStock) return;

    var option = batchSelect.options[batchSelect.selectedIndex];
    if (!option || !option.value) {
        currentStock.value = '—';
        if (stockHelp) stockHelp.textContent = 'Select the specific batch you want to adjust.';
        return;
    }

    var stock = option.getAttribute('data-stock') || '0';
    var unit = option.getAttribute('data-unit') || '';
    var expiration = option.getAttribute('data-expiration') || '';
    currentStock.value = option.getAttribute('data-stock-text') || (stock + ' ' + unit);
    if (stockHelp) stockHelp.textContent = expiration ? 'Expiration: ' + expiration : 'No expiration date.';
}

function updateStockOutWarning() {
    var select = document.getElementById('stockOutItem');
    var quantity = document.getElementById('stockOutQuantity');
    var warning = document.getElementById('stockOutWarning');
    if (!select || !quantity || !warning) return;

    var option = select.options[select.selectedIndex];
    if (!option || !option.value) {
        warning.textContent = '';
        return;
    }

    var reasonSelect = document.getElementById('stockOutReason');
    var reason = reasonSelect ? reasonSelect.value : '';
    var available = Number(
        reason === 'Expired'
            ? (option.getAttribute('data-expired-stock') || 0)
            : (option.getAttribute('data-stock') || 0)
    );
    var minimum = Number(option.getAttribute('data-minimum-stock') || 0);
    var requested = Number(quantity.value || 0);
    var remaining = available - requested;

    if (requested > available) {
        warning.textContent = reason === 'Expired'
            ? 'Warning: quantity exceeds the expired stock available for disposal.'
            : 'Warning: quantity exceeds usable stock.';
    } else if (reason !== 'Expired' && requested > 0 && remaining < minimum) {
        warning.textContent = 'Warning: this transaction will leave usable stock below the minimum level ('
            + (option.getAttribute('data-minimum-stock-text') || minimum) + ').';
    } else {
        warning.textContent = '';
    }
}

/* -------------------------------------------------------------
 * EXPIRATION FILTER
 * ----------------------------------------------------------- */
function applyExpirationFilter() {
    var filter = document.getElementById('expirationFilter');
    if (!filter) return;

    var value = filter.value;
    var rows = document.querySelectorAll('.expiration-row');
    var visible = 0;

    rows.forEach(function(row) {
        var daysText = row.getAttribute('data-days');
        var days = parseInt(daysText, 10);
        var expired = row.getAttribute('data-expired') === '1';
        var show = false;

        if (value === 'all') {
            show = true;
        } else if (value === 'expired') {
            show = expired;
        } else if (value === '7') {
            show = !expired && !isNaN(days) && days <= 7;
        } else if (value === '30') {
            show = !expired && !isNaN(days) && days <= 30;
        } else if (value === '60') {
            show = !expired && !isNaN(days) && days <= 60;
        }

        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    var empty = document.getElementById('noExpirationMatch');
    if (empty) empty.style.display = visible === 0 ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    var stockInItem = document.getElementById('stockInItem');
    if (stockInItem) {
        stockInItem.addEventListener('change', updateStockInItemDetails);
        stockInItem.addEventListener('change', updateStockInRestockOptions);
        updateStockInItemDetails();
        updateStockInRestockOptions();
    }
    var restockItem = document.getElementById('restockItem');
    if (restockItem) {
        restockItem.addEventListener('change', updateRestockRequestHelp);
        updateRestockRequestHelp();
    }

    var outItem = document.getElementById('stockOutItem');
    var adjItem = document.getElementById('adjustmentItem');

    if (outItem) {
        outItem.addEventListener('change', function() {
            updateStockOutDisplay();
            updateStockOutWarning();
        });
    }

    updateStockOutDisplay();

    var outReason = document.getElementById('stockOutReason');
    if (outReason) {
        outReason.addEventListener('change', function() {
            updateStockOutDisplay();
            updateStockOutWarning();
        });
    }

    var outQty = document.getElementById('stockOutQuantity');
    if (outQty) outQty.addEventListener('input', updateStockOutWarning);

    if (adjItem) {
        adjItem.addEventListener('change', updateAdjustmentItemDetails);
        updateAdjustmentItemDetails();
    }

    var adjustmentBatch = document.getElementById('adjustmentBatch');
    if (adjustmentBatch) adjustmentBatch.addEventListener('change', updateAdjustmentBatchStock);

    var filter = document.getElementById('expirationFilter');
    if (filter) {
        filter.addEventListener('change', applyExpirationFilter);
        applyExpirationFilter();
    }
});
</script>

<script>document.addEventListener('DOMContentLoaded', updateStockSource);</script>
<script src="sources/manila_date.js"></script>
</body>
</html>
