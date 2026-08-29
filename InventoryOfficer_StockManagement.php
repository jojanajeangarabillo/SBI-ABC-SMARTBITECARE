<?php
session_start();
require_once 'sources/db_connect.php';

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

function itemOptions($items) {
    foreach ($items as $item) {
        echo '<option value="' . h($item['item_id']) . '"'
            . ' data-stock="' . h($item['quantity_available']) . '"'
            . ' data-unit-id="' . h($item['unit_id']) . '"'
            . ' data-unit-name="' . h($item['unit_name']) . '"'
            . ' data-category-id="' . h($item['category_id']) . '"'
            . ' data-category-name="' . h($item['category_name']) . '"'
            . '>' . h($item['item_name']) . '</option>';
    }
}

/* Get logged-in officer and branch. */
$userQuery = "SELECT u.branch_id, u.username, b.branch_name
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
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Inventory Officer';
}
$stmt->close();

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

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
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($transaction_date === '') {
        $transaction_date = date('Y-m-d');
    }

    $validDate = validYmdDate($transaction_date);

    /* ------------------------- STOCK IN ------------------------- */
    if ($action === 'stock_in') {
        $batch_lot_no = trim($_POST['batch_lot_no'] ?? '');
        $manufacturing_date = trim($_POST['manufacturing_date'] ?? '');
        $expiration_date = trim($_POST['expiration_date'] ?? '');

        if ($expiration_date === '') $expiration_date = null;
        if ($manufacturing_date === '') $manufacturing_date = null;

        if (!$item_id || $item_id <= 0 || !$quantity || $quantity <= 0 || !$validDate) {
            redirectWithMessage('error', 'Please enter a valid item, positive quantity, and transaction date.', 'stockIn');
        }

        $itemStmt = $conn->prepare("SELECT i.item_id, i.category_id, i.unit_id, c.category_name
                                    FROM inventory_items i
                                    INNER JOIN inventory_categories c ON i.category_id = c.category_id
                                    WHERE i.item_id = ?
                                    LIMIT 1");
        if (!$itemStmt) redirectWithMessage('error', 'Unable to verify the selected item.', 'stockIn');
        $itemStmt->bind_param('i', $item_id);
        $itemStmt->execute();
        $itemData = $itemStmt->get_result()->fetch_assoc();
        $itemStmt->close();

        if (!$itemData) {
            redirectWithMessage('error', 'Selected item does not exist.', 'stockIn');
        }

        $isMedicalSupplies = ((int)$itemData['category_id'] === 2);

        if ($isMedicalSupplies) {
            if ($batch_lot_no === '') {
                redirectWithMessage('error', 'Batch/Lot No. is required for Medical Supplies.', 'stockIn');
            }

            if ($manufacturing_date === null || !validYmdDate($manufacturing_date)) {
                redirectWithMessage('error', 'A valid Manufacturing Date is required for Medical Supplies.', 'stockIn');
            }

            if ($expiration_date === null || !validYmdDate($expiration_date)) {
                redirectWithMessage('error', 'A valid Expiration Date is required for Medical Supplies.', 'stockIn');
            }

            if ($expiration_date <= $manufacturing_date) {
                redirectWithMessage('error', 'Expiration Date must be later than Manufacturing Date.', 'stockIn');
            }
        } else {
            $batch_lot_no = null;
            $manufacturing_date = null;
            $expiration_date = null;
        }

        $conn->begin_transaction();

        try {
           
            if ($isMedicalSupplies) {
                $stockStmt = $conn->prepare("SELECT stock_id, quantity_available
                                             FROM inventory_stocks
                                             WHERE item_id = ?
                                               AND branch_id = ?
                                               AND batch_lot_no = ?
                                             LIMIT 1
                                             FOR UPDATE");
                if (!$stockStmt) throw new Exception('Unable to check existing batch stock.');
                $stockStmt->bind_param('iss', $item_id, $branch_id, $batch_lot_no);
            } else {
                $stockStmt = $conn->prepare("SELECT stock_id, quantity_available
                                             FROM inventory_stocks
                                             WHERE item_id = ?
                                               AND branch_id = ?
                                               AND batch_lot_no IS NULL
                                             LIMIT 1
                                             FOR UPDATE");
                if (!$stockStmt) throw new Exception('Unable to check existing stock.');
                $stockStmt->bind_param('is', $item_id, $branch_id);
            }

            $stockStmt->execute();
            $stockRow = $stockStmt->get_result()->fetch_assoc();
            $stockStmt->close();

            if ($stockRow) {
                $newQuantity = (int)$stockRow['quantity_available'] + $quantity;

                if ($isMedicalSupplies) {
                    $update = $conn->prepare("UPDATE inventory_stocks
                                              SET quantity_available = ?,
                                                  manufacturing_date = ?,
                                                  expiration_date = ?
                                              WHERE stock_id = ?
                                                AND branch_id = ?");
                    if (!$update) throw new Exception('Unable to prepare stock update.');
                    $stockId = (int)$stockRow['stock_id'];
                    $update->bind_param('issis', $newQuantity, $manufacturing_date, $expiration_date, $stockId, $branch_id);
                } else {
                    $update = $conn->prepare("UPDATE inventory_stocks
                                              SET quantity_available = ?
                                              WHERE stock_id = ?
                                                AND branch_id = ?");
                    if (!$update) throw new Exception('Unable to prepare stock update.');
                    $stockId = (int)$stockRow['stock_id'];
                    $update->bind_param('iis', $newQuantity, $stockId, $branch_id);
                }

                if (!$update->execute()) throw new Exception('Unable to update stock: ' . $update->error);
                $update->close();
            } else {
                $insertStock = $conn->prepare("INSERT INTO inventory_stocks
                                                (item_id, batch_lot_no, manufacturing_date, branch_id, quantity_available, expiration_date)
                                                VALUES (?, ?, ?, ?, ?, ?)");
                if (!$insertStock) throw new Exception('Unable to prepare stock insert. Make sure batch_lot_no and manufacturing_date exist in inventory_stocks.');
                $insertStock->bind_param('isssis', $item_id, $batch_lot_no, $manufacturing_date, $branch_id, $quantity, $expiration_date);

                if (!$insertStock->execute()) throw new Exception('Unable to create the stock record: ' . $insertStock->error);
                $insertStock->close();
            }

            /* Record the Stock In transaction. */
            $transactionDateTime = $transaction_date . ' 00:00:00';
            $transactionRemarks = $remarks;

            if ($isMedicalSupplies) {
                $batchRemark = 'Batch/Lot No.: ' . $batch_lot_no;
                $transactionRemarks = $batchRemark . ($remarks !== '' ? ' | ' . $remarks : '');
            }

            $insertTrx = $conn->prepare("INSERT INTO stock_transactions
                                         (item_id, user_id, vaccination_id, branch_id, transaction_type, quantity, remarks, transaction_date)
                                         VALUES (?, ?, NULL, ?, 'IN', ?, ?, ?)");
            if (!$insertTrx) throw new Exception('Unable to prepare stock transaction.');
            $insertTrx->bind_param('iisiss', $item_id, $user_id, $branch_id, $quantity, $transactionRemarks, $transactionDateTime);

            if (!$insertTrx->execute()) throw new Exception('Unable to record the stock transaction: ' . $insertTrx->error);
            $insertTrx->close();

            $conn->commit();
            redirectWithMessage('success', 'Stock In recorded successfully.', 'stockIn');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'stockIn');
        }
    }

    /* ------------------------- STOCK OUT ------------------------ */
    if ($action === 'stock_out') {
        $reason = trim($_POST['reason'] ?? '');
        $allowedReasons = ['Dispensed to Patient', 'Damaged', 'Expired', 'Lost / Wastage', 'Other'];

        if (!$item_id || $item_id <= 0 || !$quantity || $quantity <= 0 || !$validDate || !in_array($reason, $allowedReasons, true)) {
            redirectWithMessage('error', 'Please enter a valid item, positive quantity, reason, and transaction date.', 'stockOut');
        }

        $conn->begin_transaction();

        try {
            /* Get category so medical supplies can be issued by batch using FEFO. */
            $itemStmt = $conn->prepare("SELECT i.category_id, c.category_name
                                        FROM inventory_items i
                                        INNER JOIN inventory_categories c ON i.category_id = c.category_id
                                        WHERE i.item_id = ? LIMIT 1");
            if (!$itemStmt) throw new Exception('Unable to verify item.');
            $itemStmt->bind_param('i', $item_id);
            $itemStmt->execute();
            $itemData = $itemStmt->get_result()->fetch_assoc();
            $itemStmt->close();

            if (!$itemData) throw new Exception('Selected item does not exist.');
            $isMedicalSupplies = ((int)$itemData['category_id'] === 2);

            $remaining = $quantity;
            $usedBatches = [];

            if ($isMedicalSupplies) {
                /* First-expiring batches are consumed first. */
                $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
                                             FROM inventory_stocks
                                             WHERE item_id = ?
                                               AND branch_id = ?
                                               AND quantity_available > 0
                                             ORDER BY expiration_date IS NULL ASC, expiration_date ASC, stock_id ASC
                                             FOR UPDATE");
                if (!$stockStmt) throw new Exception('Unable to load medical stock batches.');
                $stockStmt->bind_param('is', $item_id, $branch_id);
            } else {
                $stockStmt = $conn->prepare("SELECT stock_id, quantity_available, batch_lot_no, expiration_date
                                             FROM inventory_stocks
                                             WHERE item_id = ?
                                               AND branch_id = ?
                                               AND batch_lot_no IS NULL
                                             LIMIT 1
                                             FOR UPDATE");
                if (!$stockStmt) throw new Exception('Unable to load stock.');
                $stockStmt->bind_param('is', $item_id, $branch_id);
            }

            $stockStmt->execute();
            $stockResult = $stockStmt->get_result();
            $stockRows = [];
            while ($row = $stockResult->fetch_assoc()) $stockRows[] = $row;
            $stockStmt->close();

            foreach ($stockRows as $stockRow) {
                if ($remaining <= 0) break;

                $available = (int)$stockRow['quantity_available'];
                $deduct = min($remaining, $available);
                $newQuantity = $available - $deduct;
                $stockId = (int)$stockRow['stock_id'];

                $update = $conn->prepare("UPDATE inventory_stocks
                                          SET quantity_available = ?
                                          WHERE stock_id = ? AND branch_id = ?");
                if (!$update) throw new Exception('Unable to prepare stock out update.');
                $update->bind_param('iis', $newQuantity, $stockId, $branch_id);
                if (!$update->execute()) throw new Exception('Unable to update stock: ' . $update->error);
                $update->close();

                $batch = $stockRow['batch_lot_no'];
                if ($batch !== null && $batch !== '') {
                    $usedBatches[] = $batch . ' (-' . $deduct . ')';
                }

                $remaining -= $deduct;
            }

            if ($remaining > 0) {
                throw new Exception('Stock Out cannot exceed the current available stock.');
            }

            $combinedRemarks = 'Reason: ' . $reason;
            if ($usedBatches) $combinedRemarks .= ' | Batch/Lot: ' . implode(', ', $usedBatches);
            if ($remarks !== '') $combinedRemarks .= ' | ' . $remarks;

            $transactionDateTime = $transaction_date . ' 00:00:00';
            $insertTrx = $conn->prepare("INSERT INTO stock_transactions
                                         (item_id, user_id, vaccination_id, branch_id, transaction_type, quantity, remarks, transaction_date)
                                         VALUES (?, ?, NULL, ?, 'OUT', ?, ?, ?)");
            if (!$insertTrx) throw new Exception('Unable to prepare stock transaction.');
            $insertTrx->bind_param('iisiss', $item_id, $user_id, $branch_id, $quantity, $combinedRemarks, $transactionDateTime);
            if (!$insertTrx->execute()) throw new Exception('Unable to record stock transaction: ' . $insertTrx->error);
            $insertTrx->close();

            $conn->commit();
            redirectWithMessage('success', 'Stock Out recorded successfully.', 'stockOut');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'stockOut');
        }
    }

    /* ------------------------- ADJUSTMENT ----------------------- */
    if ($action === 'adjustment') {
        $adjustedQuantity = filter_input(INPUT_POST, 'adjusted_quantity', FILTER_VALIDATE_INT);
        $reason = trim($_POST['adjustment_reason'] ?? '');
        $allowedReasons = ['Miscount / Physical Count Correction', 'Damaged', 'Expired', 'System Correction', 'Other'];

        if (!$item_id || $item_id <= 0 || $adjustedQuantity === false || $adjustedQuantity === null || $adjustedQuantity < 0 || !$validDate || !in_array($reason, $allowedReasons, true)) {
            redirectWithMessage('error', 'Please enter a valid item, non-negative adjusted quantity, reason, and date.', 'adjustment');
        }

        $conn->begin_transaction();

        try {
            /* Adjustment is intentionally item-level for this existing form. */
            $stockStmt = $conn->prepare("SELECT stock_id, quantity_available
                                         FROM inventory_stocks
                                         WHERE item_id = ? AND branch_id = ?
                                         ORDER BY expiration_date IS NULL ASC, expiration_date ASC, stock_id ASC
                                         LIMIT 1 FOR UPDATE");
            if (!$stockStmt) throw new Exception('Unable to load stock for adjustment.');
            $stockStmt->bind_param('is', $item_id, $branch_id);
            $stockStmt->execute();
            $stockRow = $stockStmt->get_result()->fetch_assoc();
            $stockStmt->close();

            if (!$stockRow) throw new Exception('No stock record exists for the selected item in this branch.');

            $currentQuantity = (int)$stockRow['quantity_available'];
            $delta = $adjustedQuantity - $currentQuantity;

            if ($delta === 0) {
                throw new Exception('The adjusted quantity is the same as the current stock. No change was made.');
            }

            $stockId = (int)$stockRow['stock_id'];
            $update = $conn->prepare("UPDATE inventory_stocks SET quantity_available = ? WHERE stock_id = ? AND branch_id = ?");
            if (!$update) throw new Exception('Unable to prepare adjustment update.');
            $update->bind_param('iis', $adjustedQuantity, $stockId, $branch_id);
            if (!$update->execute()) throw new Exception('Unable to update stock: ' . $update->error);
            $update->close();

            $combinedRemarks = 'Reason: ' . $reason . ' | Previous Stock: ' . $currentQuantity . ' | New Stock: ' . $adjustedQuantity;
            if ($remarks !== '') $combinedRemarks .= ' | ' . $remarks;

            $transactionDateTime = $transaction_date . ' 00:00:00';
            $insertTrx = $conn->prepare("INSERT INTO stock_transactions
                                         (item_id, user_id, vaccination_id, branch_id, transaction_type, quantity, remarks, transaction_date)
                                         VALUES (?, ?, NULL, ?, 'ADJUSTMENT', ?, ?, ?)");
            if (!$insertTrx) throw new Exception('Unable to prepare adjustment transaction.');
            $insertTrx->bind_param('iisiss', $item_id, $user_id, $branch_id, $delta, $combinedRemarks, $transactionDateTime);
            if (!$insertTrx->execute()) throw new Exception('Unable to record adjustment: ' . $insertTrx->error);
            $insertTrx->close();

            $conn->commit();
            redirectWithMessage('success', 'Stock Adjustment recorded successfully.', 'adjustment');
        } catch (Throwable $e) {
            $conn->rollback();
            redirectWithMessage('error', $e->getMessage(), 'adjustment');
        }
    }
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';
    $activePanel = $_GET['panel'] ?? 'stockIn';
}

if (!in_array($activePanel, ['stockIn', 'stockOut', 'adjustment', 'expiration'], true)) {
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
    $itemsQuery = "SELECT
                        i.item_id,
                        i.item_name,
                        i.minimum_stock,
                        i.category_id,
                        c.category_name,
                        u.unit_id,
                        u.unit_name,
                        COALESCE(SUM(s.quantity_available), 0) AS quantity_available
                   FROM inventory_items i
                   INNER JOIN inventory_categories c ON i.category_id = c.category_id
                   INNER JOIN units u ON i.unit_id = u.unit_id
                   LEFT JOIN inventory_stocks s
                     ON s.item_id = i.item_id
                    AND s.branch_id = ?
                   GROUP BY i.item_id, i.item_name, i.minimum_stock,
                            i.category_id, c.category_name,
                            u.unit_id, u.unit_name
                   ORDER BY i.item_name ASC";

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
                            u.unit_name
                        FROM inventory_stocks s
                        INNER JOIN inventory_items i ON s.item_id = i.item_id
                        INNER JOIN units u ON i.unit_id = u.unit_id
                        WHERE s.branch_id = ?
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
                      u.unit_name,
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
    box-shadow:0 2px 8px rgba(0,0,0,.08);
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
    font-weight:600;
    color:var(--primary);
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

.medical-field{
    display:block;
}

@media(max-width:991px){
    .main{margin-left:90px;}
}
</style>
</head>

<body>

<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
            <li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
            <li><a class="active" href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
            <li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
            <li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
            <li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<div class="main">

    <div class="topbar">
        <h3>Stock Management<small><?php echo h($branch_name); ?></small></h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <?php echo h($username); ?>
            <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| Inventory Officer</span>
        </div>
    </div>

    <div class="page-body">

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?> alert-custom mb-4" role="alert">
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <div class="tab-row">
            <button type="button" class="tab-btn <?php echo $activePanel === 'stockIn' ? 'active' : ''; ?>" onclick="showPanel('stockIn', this)">Stock In</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'stockOut' ? 'active' : ''; ?>" onclick="showPanel('stockOut', this)">Stock Out</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'adjustment' ? 'active' : ''; ?>" onclick="showPanel('adjustment', this)">Adjustment</button>
            <button type="button" class="tab-btn <?php echo $activePanel === 'expiration' ? 'active' : ''; ?>" onclick="showPanel('expiration', this)">Expiration Monitoring</button>
        </div>

        <!-- =====================================================
             STOCK IN
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'stockIn' ? 'active' : ''; ?>" id="stockIn">
            <div class="form-card">
                <div class="section-title">Record Stock In</div>

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
                            <input id="stockInQuantity" name="quantity" type="number" min="1" step="1" class="form-control" placeholder="Enter quantity..." required>
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

                        <div class="col-12">
                            <label for="stockInRemarks">Remarks</label>
                            <textarea id="stockInRemarks" name="remarks" class="form-control" rows="2" maxlength="500" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>

                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn-custom">Save Stock In</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =====================================================
             STOCK OUT
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'stockOut' ? 'active' : ''; ?>" id="stockOut">
            <div class="form-card">
                <div class="section-title">Record Stock Out</div>

                <form method="POST" action="InventoryOfficer_StockManagement.php" onsubmit="return confirm('Save this Stock Out transaction?');">
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
                            <input id="stockOutQuantity" name="quantity" type="number" min="1" step="1" class="form-control" placeholder="Enter quantity..." required>
                            <div id="stockOutAvailable" class="form-text-small">Available stock: —</div>
                        </div>

                        <div class="col-md-6">
                            <label for="stockOutReason">Reason</label>
                            <select id="stockOutReason" name="reason" class="form-select" required>
                                <option value="">Select reason...</option>
                                <option>Dispensed to Patient</option>
                                <option>Damaged</option>
                                <option>Expired</option>
                                <option>Lost / Wastage</option>
                                <option>Other</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="stockOutDate">Date of Transaction</label>
                            <input id="stockOutDate" name="transaction_date" type="date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required>
                        </div>

                        <div class="col-12">
                            <label for="stockOutRemarks">Remarks</label>
                            <textarea id="stockOutRemarks" name="remarks" class="form-control" rows="2" maxlength="500" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn-custom">Save Stock Out</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =====================================================
             ADJUSTMENT
             ===================================================== -->
        <div class="panel <?php echo $activePanel === 'adjustment' ? 'active' : ''; ?>" id="adjustment">
            <div class="form-card">
                <div class="section-title">Record Stock Adjustment</div>

                <form method="POST" action="InventoryOfficer_StockManagement.php" onsubmit="return confirm('Save this Stock Adjustment?');">
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

                        <div class="col-md-6">
                            <label for="currentStock">Current Stock</label>
                            <input id="currentStock" type="text" class="form-control" value="—" readonly>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustedQuantity">Adjusted Quantity</label>
                            <input id="adjustedQuantity" name="adjusted_quantity" type="number" min="0" step="1" class="form-control" placeholder="Enter new quantity..." required>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustmentReason">Reason for Adjustment</label>
                            <select id="adjustmentReason" name="adjustment_reason" class="form-select" required>
                                <option value="">Select reason...</option>
                                <option>Miscount / Physical Count Correction</option>
                                <option>Damaged</option>
                                <option>Expired</option>
                                <option>System Correction</option>
                                <option>Other</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustmentDate">Date of Transaction</label>
                            <input id="adjustmentDate" name="transaction_date" type="date" class="form-control" value="<?php echo h(date('Y-m-d')); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="adjustmentRemarks">Remarks</label>
                            <textarea id="adjustmentRemarks" name="remarks" class="form-control" rows="1" maxlength="500" placeholder="Enter remarks here (Optional)"></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn-custom">Save Adjustment</button>
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
                                    <td><?php echo h($row['quantity_available'] . ' ' . $row['unit_name']); ?></td>
                                    <td><?php echo $row['manufacturing_date'] ? h(date('m/d/Y', strtotime($row['manufacturing_date']))) : '—'; ?></td>
                                    <td><?php echo h(date('m/d/Y', strtotime($row['expiration_date']))); ?></td>
                                    <td><?php echo $days === null ? '—' : ($days < 0 ? abs($days) . ' days ago' : $days . ' days'); ?></td>
                                    <td><span class="badge-status <?php echo h($badgeClass); ?>"><?php echo h($status); ?></span></td>
                                    <td class="text-center">
                                        <a class="action-btn" title="View Stock Details" href="?panel=expiration&view_stock_id=<?php echo h($row['stock_id']); ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
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
                        <div class="col-md-6"><strong>Unit</strong><div><?php echo h($viewStock['unit_name']); ?></div></div>
                        <div class="col-md-6"><strong>Current Stock</strong><div><?php echo h($viewStock['quantity_available']); ?></div></div>
                        <div class="col-md-6"><strong>Minimum Stock</strong><div><?php echo h($viewStock['minimum_stock']); ?></div></div>
                        <div class="col-md-6"><strong>Batch/Lot No.</strong><div><?php echo h($viewStock['batch_lot_no'] ?: '—'); ?></div></div>
                        <div class="col-md-6"><strong>Manufacturing Date</strong><div><?php echo $viewStock['manufacturing_date'] ? h(date('m/d/Y', strtotime($viewStock['manufacturing_date']))) : '—'; ?></div></div>
                        <div class="col-md-6"><strong>Expiration Date</strong><div><?php echo $viewStock['expiration_date'] ? h(date('m/d/Y', strtotime($viewStock['expiration_date']))) : '—'; ?></div></div>
                        <div class="col-md-6"><strong>Status</strong><div><span class="badge-status <?php echo h($viewBadge); ?>"><?php echo h($viewStatus); ?></span></div></div>
                        <div class="col-12"><strong>Last Updated</strong><div><?php echo h($viewStock['last_updated']); ?></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="InventoryOfficer_StockManagement.php?panel=expiration" class="btn btn-secondary">Close</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
function updateStockInItemDetails() {
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

    /* Medical Supplies = category_id 2. */
    var categoryId = option.getAttribute('data-category-id') || '';
    var isMedicalSupplies = categoryId === '2';

    if (isMedicalSupplies) {
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

function validateStockInForm() {
    var select = document.getElementById('stockInItem');
    var quantity = document.getElementById('stockInQuantity');
    var date = document.getElementById('stockInDate');

    if (!select.value) {
        alert('Please select an item.');
        return false;
    }

    if (!quantity.value || Number(quantity.value) <= 0) {
        alert('Please enter a valid quantity.');
        return false;
    }

    if (!date.value) {
        alert('Please enter the Stock In date.');
        return false;
    }

    var option = select.options[select.selectedIndex];
    var categoryId = option.getAttribute('data-category-id') || '';

    if (categoryId === '2') {
        var batch = document.getElementById('stockInBatch').value.trim();
        var mfg = document.getElementById('stockInManufacturingDate').value;
        var exp = document.getElementById('stockInExpiry').value;

        if (!batch) {
            alert('Please enter the Batch/Lot No.');
            return false;
        }

        if (!mfg) {
            alert('Please enter the Manufacturing Date.');
            return false;
        }

        if (!exp) {
            alert('Please enter the Expiration Date.');
            return false;
        }

        if (exp <= mfg) {
            alert('Expiration Date must be later than Manufacturing Date.');
            return false;
        }
    }

    return confirm('Save this Stock In transaction?');
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
        if (prefix === 'current') {
            display.value = '—';
        } else {
            display.textContent = 'Available stock: —';
        }
        return;
    }

    var stock = option.getAttribute('data-stock') || '0';
    var unit = option.getAttribute('data-unit-name') || '';

    if (prefix === 'current') {
        display.value = stock + ' ' + unit;
    } else {
        display.textContent = 'Available stock: ' + stock + ' ' + unit;
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
        updateStockInItemDetails();
    }

    var outItem = document.getElementById('stockOutItem');
    var adjItem = document.getElementById('adjustmentItem');

    if (outItem) {
        outItem.addEventListener('change', function() {
            updateStockDisplay('stockOutItem', 'stockOutAvailable', 'available');
        });
    }

    if (adjItem) {
        adjItem.addEventListener('change', function() {
            updateStockDisplay('adjustmentItem', 'currentStock', 'current');
        });
    }

    var filter = document.getElementById('expirationFilter');
    if (filter) {
        filter.addEventListener('change', applyExpirationFilter);
        applyExpirationFilter();
    }
});
</script>

</body>
</html>
