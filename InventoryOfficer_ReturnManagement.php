<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';
require_once 'sources/inventory_officer_activity_helper.php';
require_once 'sources/return_management_helper.php';
require_once 'sources/return_stock_out_helper.php';

/* -------------------------------------------------------------
 * Inventory Officer access
 * ----------------------------------------------------------- */
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
$username = 'Inventory Officer';

/* -------------------------------------------------------------
 * Authenticated user + branch
 * ----------------------------------------------------------- */
$userQuery = "
    SELECT u.branch_id, u.username, u.role_id, b.branch_name
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
if ((int)$userData['role_id'] !== 5) { http_response_code(403); die('Inventory Officer access is required.'); }
$branch_id = $userData['branch_id'];
$username = $userData['username'] ?: 'Inventory Officer';
$branch_name = $userData['branch_name'] ?: 'No Branch Assigned';

if ($branch_id === null || $branch_id === '') {
    http_response_code(403);
    die('Your account is not assigned to a branch.');
}

$notification_count = getUnreadNotificationCount($conn, $user_id);

/* -------------------------------------------------------------
 * Helpers
 * ----------------------------------------------------------- */
function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function returnFlash($type, $message)
{
    $_SESSION['return_management_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function redirectReturnPage()
{
    header('Location: InventoryOfficer_ReturnManagement.php');
    exit();
}

function validReturnDate($date)
{
    if (!is_string($date) || $date === '') {
        return false;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

$allowedReturnReasons = [
    'Wrong Item',
    'Incorrect Quantity',
    'Damaged Item',
    'Expired Item',
    'Other Reason for Return'
];

$allowedStatuses = [
    'In Transit',
    'Received'
];

/* Read-only setup check; run the separate CLI migration once. */
try {
    stockReceiptSourceAssertSchema($conn);
    returnManagementAssertSchema($conn);
    returnStockOutAssertSchema($conn);
} catch (Throwable $e) {
    http_response_code(503);
    die(h($e->getMessage()));
}

/* -------------------------------------------------------------
 * Units for Return Form
 * ----------------------------------------------------------- */
$units = [];
$unitSql = "SELECT unit_id, unit_name FROM units ORDER BY unit_name ASC";
$unitResult = $conn->query($unitSql);
if ($unitResult) {
    while ($row = $unitResult->fetch_assoc()) {
        $units[] = $row;
    }
}

/* -------------------------------------------------------------
 * Active branches
 * ----------------------------------------------------------- */
$activeBranches = [];
$branchesResult = $conn->query("SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name");
if ($branchesResult) {
    $activeBranches = $branchesResult->fetch_all(MYSQLI_ASSOC);
}

$mainBranchDefault = ''; // No guessed return destination.

/* -------------------------------------------------------------
 * CSRF
 * ----------------------------------------------------------- */
if (empty($_SESSION['return_management_csrf'])) {
    $_SESSION['return_management_csrf'] = bin2hex(random_bytes(32));
}
$returnCsrf = $_SESSION['return_management_csrf'];

/* POST actions preserve the delivery-only workflow. Inventory links are optional. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($returnCsrf, $_POST['csrf_token'])) {
        returnFlash('danger', 'Security validation failed. Please try again.');
        redirectReturnPage();
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'return_stock_out') {
        try {
            $returnId=returnManagementOptionalId($_POST['return_id'] ?? null);
            $stockId=returnManagementOptionalId($_POST['stock_out_stock_id'] ?? null);
            $transactionId=returnManagementOptionalId($_POST['outgoing_transaction_id'] ?? null);
            $mode=returnManagementPostedText($_POST['stock_out_mode'] ?? '',10,'Stock Out mode');
            $date=returnManagementPostedText($_POST['stock_out_date'] ?? date('Y-m-d'),10,'Stock Out date');
            if (!$returnId) throw new RuntimeException('Select a valid return.');
        } catch (Throwable $e) { returnFlash('danger',$e->getMessage()); redirectReturnPage(); }
        $conn->begin_transaction();
        try {
            $result=returnStockOutRecord($conn,['user_id'=>$user_id,'branch_id'=>$branch_id,
                'branch_name'=>$branch_name,'username'=>$username],$returnId,$mode,$stockId,$transactionId,$date);
            $conn->commit();
            $message=$result['already_recorded'] ? 'Stock Out was already recorded for this return; the existing movement was retained.'
                : ($mode==='record' ? ($result['usable_out']
                    ? 'Stock Out recorded once for this return. Include this outgoing quantity in the Daily Inventory Pull Out total for '.$result['date'].'.'
                    : 'Expired-stock quantity was removed once from its recorded batch. The return Stock Out is saved.')
                    : 'Existing Stock Out linked. No additional stock was deducted.');
            returnFlash('success',$message); redirectReturnPage();
        } catch (Throwable $e) {
            $conn->rollback(); returnFlash('danger',$e instanceof mysqli_sql_exception
                ? 'Unable to save Stock Out. The transaction may already be linked to another return. No Stock Out changes were saved.' : $e->getMessage());
            redirectReturnPage();
        }
    }

    if ($action === 'save_return') {
        try {
            $itemName = returnManagementPostedText($_POST['item_name'] ?? '', 255, 'item name');
            $unitId = returnManagementOptionalId($_POST['unit_id'] ?? null);
            $destinationBranchId = returnManagementPostedText($_POST['destination_branch_id'] ?? '', 10, 'destination branch');
            $quantity = returnManagementQuantity(returnManagementPostedText($_POST['quantity'] ?? '', 32, 'quantity'));
            $returnReason = returnManagementPostedText($_POST['return_reason'] ?? '', 100, 'return reason');
            $remarks = returnManagementPostedText($_POST['remarks'] ?? '', 2000, 'remarks');
            $transportationDetails = returnManagementPostedText($_POST['transportation_details'] ?? '', 500, 'transportation details');
            $itemId = returnManagementOptionalId($_POST['inventory_item_id'] ?? null);
            $stockId = returnManagementOptionalId($_POST['source_stock_id'] ?? null);
            $sourceReceiptId=returnManagementOptionalId($_POST['source_receipt_transaction_id'] ?? null);
            $destinationReason=returnManagementPostedText($_POST['destination_reason'] ?? '',500,'destination explanation');
            if ($itemName === '' || !$unitId || $destinationBranchId === ''
                || !in_array($returnReason, $allowedReturnReasons, true)) throw new RuntimeException('Complete all required return details.');
            if ($destinationBranchId === (string)$branch_id) throw new RuntimeException('The destination must differ from your current branch.');
        } catch (Throwable $e) {
            returnFlash('danger', $e->getMessage());
            redirectReturnPage();
        }
        $conn->begin_transaction();
        try {
            returnManagementRequireActor($conn, $user_id, $branch_id);
            $stmt = returnManagementStatement($conn,
                "SELECT branch_id,branch_name FROM branches WHERE branch_id = ? AND status = 'Active' FOR UPDATE");
            $stmt->bind_param('s', $destinationBranchId);
            returnManagementExecute($stmt);
            $destination = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$destination) throw new RuntimeException('The selected destination branch is not active.');
            $destinationBranchId=(string)$destination['branch_id'];
            if ($destinationBranchId===(string)$branch_id) throw new RuntimeException('The destination must differ from your current branch.');
            $stmt = returnManagementStatement($conn, 'SELECT unit_id FROM units WHERE unit_id = ?');
            $stmt->bind_param('i', $unitId);
            returnManagementExecute($stmt);
            $unitExists = $stmt->get_result()->num_rows === 1;
            $stmt->close();
            if (!$unitExists) throw new RuntimeException('The selected unit does not exist.');
            $reference = returnManagementSourceReference($conn, $branch_id, $itemName, $unitId, $quantity, $itemId, $stockId);
            $route=stockReceiptSourceReturnRoute($conn,$branch_id,$destinationBranchId,$reference,$sourceReceiptId,$destinationReason);
            $sourceReceiptId=$route['receipt_id']; $destinationReason=$route['reason'];
            $itemId = $reference['item_id'];
            $stockId = $reference['stock_id'];
            $baseQuantity = $reference['base_quantity'];
            $baseUnit = $reference['base_unit'];
            $batch = $reference['batch'];
            $now = date('Y-m-d H:i:s');
            $stmt = returnManagementStatement($conn, "INSERT INTO inventory_returns
                (return_number, branch_id, destination_branch_id, item_name, unit_id, quantity,
                 return_reason, remarks, transportation_details, status, created_by, sent_at, created_at,
                 item_id, stock_id, inventory_quantity_snapshot, inventory_base_unit_snapshot, source_batch_snapshot,
                 source_receipt_transaction_id,destination_reason)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'In Transit', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssissssissiisssis', $branch_id, $destinationBranchId, $itemName, $unitId,
                $quantity, $returnReason, $remarks, $transportationDetails, $user_id, $now, $now,
                $itemId, $stockId, $baseQuantity, $baseUnit, $batch,$sourceReceiptId,$destinationReason);
            returnManagementExecute($stmt);
            $returnId = (int)$conn->insert_id;
            $stmt->close();
            $returnNumber = 'RET-' . date('Y') . '-' . str_pad((string)$returnId, 6, '0', STR_PAD_LEFT);
            $stmt = returnManagementStatement($conn, 'UPDATE inventory_returns SET return_number = ? WHERE return_id = ?');
            $stmt->bind_param('si', $returnNumber, $returnId);
            returnManagementExecute($stmt);
            $stmt->close();
            $actionText = 'Recorded return shipment ' . $returnNumber . ' for ' . $itemName . ' (' . $quantity . ') to branch ' . $destinationBranchId;
            $message = 'Return ' . $returnNumber . ' was recorded for delivery to ' . $destination['branch_name']
                . '. Item: ' . $itemName . '. Quantity: ' . $quantity . '. Reason: ' . $returnReason . '.';
            if (!inventoryOfficerRecordReturnActivity($conn, $user_id, $branch_id, $branch_name, $username,
                $returnId, $returnNumber, $actionText, $message, 'sent')) {
                throw new RuntimeException('Unable to save the return audit and Branch Admin notification.');
            }
            if (!inventoryOfficerNotifyIncomingReturn($conn,$returnId)) throw new RuntimeException('Unable to save the receiving Inventory Officer notification.');
            $conn->commit();
            returnFlash('success', 'Return ' . $returnNumber . ' was recorded and is now In Transit.');
            redirectReturnPage();
        } catch (Throwable $e) {
            $conn->rollback();
            returnFlash('danger', $e instanceof mysqli_sql_exception ? 'Unable to save this return. No return changes were saved.' : $e->getMessage());
            redirectReturnPage();
        }
    }
    if ($action === 'confirm_receipt' || $action === 'link_stock_receipt') {
        try {
            $returnId = returnManagementOptionalId($_POST['return_id'] ?? null);
            $transactionId = returnManagementOptionalId($_POST['receipt_transaction_id'] ?? null);
            if (!$returnId) throw new RuntimeException('Select a valid return record.');
            if ($action === 'link_stock_receipt' && !$transactionId) throw new RuntimeException('Select the stock receipt to link.');
        } catch (Throwable $e) {
            returnFlash('danger', $e->getMessage());
            redirectReturnPage();
        }
        $conn->begin_transaction();
        try {
            returnManagementRequireActor($conn, $user_id, $branch_id);
            $stmt = returnManagementStatement($conn, "SELECT ir.*, b.branch_name AS source_name
                FROM inventory_returns ir LEFT JOIN branches b ON b.branch_id = ir.branch_id
                WHERE ir.return_id = ? AND ir.destination_branch_id = ? FOR UPDATE");
            $stmt->bind_param('is', $returnId, $branch_id);
            returnManagementExecute($stmt);
            $return = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$return) throw new RuntimeException('Return not found or you are not the receiving branch.');
            if (!in_array($return['status'], ['In Transit','Received'], true)) throw new RuntimeException('This legacy return status needs review.');
            if ($action === 'link_stock_receipt' && $return['status'] !== 'Received') throw new RuntimeException('Confirm delivery receipt before linking a stock receipt.');
            $existingTransaction = $return['stock_transaction_id'] === null ? null : (int)$return['stock_transaction_id'];
            if ($existingTransaction !== null && $transactionId !== null && $existingTransaction !== $transactionId) {
                throw new RuntimeException('This return already has a stock receipt reference. It cannot be replaced here.');
            }
            $newLink = $transactionId !== null && $existingTransaction === null;
            if ($newLink) returnManagementValidateReceipt($conn, $return, $branch_id, $transactionId);
            $firstReceipt = $return['status'] === 'In Transit';
            if (!$firstReceipt && !$newLink) {
                $conn->commit();
                returnFlash('success', 'This return has already been received. Its existing record was retained.');
                redirectReturnPage();
            }
            if ($firstReceipt) {
                $now = date('Y-m-d H:i:s');
                $stmt = returnManagementStatement($conn, "UPDATE inventory_returns SET status = 'Received',
                    received_at = ?, processed_by = ?, stock_transaction_id = COALESCE(?, stock_transaction_id)
                    WHERE return_id = ? AND destination_branch_id = ? AND status = 'In Transit'");
                $stmt->bind_param('siiis', $now, $user_id, $transactionId, $returnId, $branch_id);
            } else {
                $stmt = returnManagementStatement($conn, "UPDATE inventory_returns SET stock_transaction_id = ?
                    WHERE return_id = ? AND destination_branch_id = ? AND status = 'Received' AND stock_transaction_id IS NULL");
                $stmt->bind_param('iis', $transactionId, $returnId, $branch_id);
            }
            returnManagementExecute($stmt);
            if ($stmt->affected_rows !== 1) throw new RuntimeException('The return changed. Refresh the page and try again.');
            $stmt->close();
            $number = $return['return_number'] ?: ('RET-' . $returnId);
            $actionText = $firstReceipt ? 'Confirmed receipt of return ' . $number : 'Linked existing stock receipt to return ' . $number;
            if ($newLink) $actionText .= ' | Stock receipt #' . $transactionId;
            $message = $firstReceipt ? 'Return ' . $number . ' from ' . ($return['source_name'] ?: $return['branch_id']) . ' was confirmed as received.'
                : 'Return ' . $number . ' was linked to an existing stock receipt.';
            if ($newLink) $message .= ' Stock transaction ID: ' . $transactionId . '.';
            if (!inventoryOfficerRecordReturnActivity($conn, $user_id, $branch_id, $branch_name, $username,
                $returnId, $number, $actionText, $message, $firstReceipt ? 'received' : 'stock_receipt')) {
                throw new RuntimeException('Unable to save the return audit and Branch Admin notification.');
            }
            if ($firstReceipt && !inventoryOfficerNotifyIncomingReturn($conn,$returnId)) throw new RuntimeException('Unable to update the receiving Inventory Officer notification.');
            if ($firstReceipt && !inventoryOfficerNotifyReturnSenderReceived($conn,$returnId)) throw new RuntimeException('Unable to notify the sending Inventory Officers. Receipt confirmation was not saved.');
            $conn->commit();
            returnFlash('success', $firstReceipt ? 'Return ' . $number . ' was marked Received.' : 'Stock receipt linked. The existing stock movement was retained.');
            redirectReturnPage();
        } catch (Throwable $e) {
            $conn->rollback();
            returnFlash('danger', $e instanceof mysqli_sql_exception ? 'Unable to save this receipt reference. It may already be linked to another return. No return changes were saved.' : $e->getMessage());
            redirectReturnPage();
        }
    }
    returnFlash('danger', 'Unsupported return action.');
    redirectReturnPage();
}

/* -------------------------------------------------------------
 * Page data
 * ----------------------------------------------------------- */
$flash = $_SESSION['return_management_flash'] ?? null;
unset($_SESSION['return_management_flash']);

$stats = [
    'sent_total' => 0,
    'sent_in_transit' => 0,
    'received_total' => 0,
    'received_count' => 0,
    'total' => 0
];

$statsSql = "SELECT
                SUM(CASE WHEN branch_id = ? THEN 1 ELSE 0 END) AS sent_total,
                SUM(CASE WHEN branch_id = ? AND status = 'In Transit' THEN 1 ELSE 0 END) AS sent_in_transit,
                SUM(CASE WHEN destination_branch_id = ? THEN 1 ELSE 0 END) AS received_total,
                SUM(CASE WHEN destination_branch_id = ? AND status = 'Received' THEN 1 ELSE 0 END) AS received_count,
                COUNT(*) AS total
            FROM inventory_returns
            WHERE branch_id = ? OR destination_branch_id = ?";
$statsStmt = $conn->prepare($statsSql);
if ($statsStmt) {
    $statsStmt->bind_param('ssssss', $branch_id, $branch_id, $branch_id, $branch_id, $branch_id, $branch_id);
    $statsStmt->execute();
    $statsRow = $statsStmt->get_result()->fetch_assoc();
    if ($statsRow) {
        $stats['sent_total'] = (int)($statsRow['sent_total'] ?? 0);
        $stats['sent_in_transit'] = (int)($statsRow['sent_in_transit'] ?? 0);
        $stats['received_total'] = (int)($statsRow['received_total'] ?? 0);
        $stats['received_count'] = (int)($statsRow['received_count'] ?? 0);
        $stats['total'] = (int)($statsRow['total'] ?? 0);
    }
    $statsStmt->close();
}

$returns = [];
$returnError = '';
$returnListSql = "SELECT
                    ir.return_id,
                    ir.return_number,
                    ir.branch_id,
                    ir.destination_branch_id,
                    ir.item_name,
                    ir.unit_id,
                    un.unit_name,
                    ir.quantity,
                    ir.return_reason,
                    ir.remarks,
                    ir.transportation_details,
                    ir.status,
                    ir.created_by,
                    ir.created_at,
                    ir.sent_at,
                    ir.received_at,
                    ir.processed_by,
                    ir.item_id, ir.stock_id, ir.stock_transaction_id,
                    ir.inventory_quantity_snapshot, ir.inventory_base_unit_snapshot,
                    ir.source_batch_snapshot, ir.legacy_status,
                    ir.source_receipt_transaction_id,ir.destination_reason,
                    ir.sent_stock_transaction_id,ir.stock_out_stock_id,ir.stock_out_batch_snapshot,
                    outbound.transaction_id AS outbound_transaction_id,outbound.quantity AS outbound_quantity,
                    outbound.transaction_date AS outbound_date,outbound_actor.username AS outbound_actor,
                    origin.source_name_snapshot AS original_source_name,origin.receipt_source_type AS original_source_type,
                    ci.item_id AS catalog_item_id, ci.item_name AS catalog_item_name,
                    COALESCE(NULLIF(TRIM(ci.base_unit_label), ''), cu.unit_name) AS current_base_unit,
                    ss.stock_id AS source_stock_id, ss.item_id AS source_stock_item_id, ss.branch_id AS source_stock_branch_id,
                    st.transaction_id AS receipt_transaction_id, st.item_id AS receipt_item_id,
                    st.branch_id AS receipt_branch_id, st.quantity AS receipt_quantity, st.transaction_type AS receipt_type,
                    st.receipt_source_type,st.source_branch_id AS receipt_source_branch,
                    sb.branch_name AS source_name,
                    db.branch_name AS destination_name,
                    creator.username AS creator_username,
                    processor.username AS processor_username
                FROM inventory_returns ir
                LEFT JOIN units un ON un.unit_id = ir.unit_id
                LEFT JOIN stock_transactions outbound ON outbound.transaction_id=ir.sent_stock_transaction_id
                    AND outbound.branch_id=ir.branch_id AND outbound.item_id=ir.item_id AND outbound.transaction_type IN ('OUT','TRANSFER_OUT')
                LEFT JOIN users outbound_actor ON outbound_actor.user_id=outbound.user_id
                LEFT JOIN stock_transactions origin ON origin.transaction_id=ir.source_receipt_transaction_id AND origin.branch_id=ir.branch_id AND origin.item_id=ir.item_id
                LEFT JOIN inventory_items ci ON ci.item_id = ir.item_id
                LEFT JOIN units cu ON cu.unit_id = ci.unit_id
                LEFT JOIN inventory_stocks ss ON ss.stock_id = ir.stock_id AND ss.branch_id = ir.branch_id AND ss.item_id = ir.item_id
                LEFT JOIN stock_transactions st ON st.transaction_id = ir.stock_transaction_id AND st.branch_id = ir.destination_branch_id AND st.item_id = ir.item_id
                LEFT JOIN branches sb ON sb.branch_id = ir.branch_id
                LEFT JOIN branches db ON db.branch_id = ir.destination_branch_id
                LEFT JOIN users creator ON creator.user_id = ir.created_by
                LEFT JOIN users processor ON processor.user_id = ir.processed_by
                WHERE ir.branch_id = ? OR ir.destination_branch_id = ?
                ORDER BY ir.created_at DESC, ir.return_id DESC";
$returnListStmt = $conn->prepare($returnListSql);
if ($returnListStmt) {
    $returnListStmt->bind_param('ss', $branch_id, $branch_id);
    $returnListStmt->execute();
    $result = $returnListStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['view_type'] = ((string)$row['branch_id'] === (string)$branch_id) ? 'Sent' : 'Received';
        $row['route'] = ($row['source_name'] ?: $row['branch_id']) . ' → ' . ($row['destination_name'] ?: $row['destination_branch_id']);
        $row['inventory_reference_label'] = returnManagementReferenceLabel($row);
        $returns[] = $row;
    }
    $returnListStmt->close();
} else {
    $returnError = 'Unable to load return records.';
}

/* Optional source references and existing destination receipts. No quantity is changed. */
$inventorySourceItems = $conn->query('SELECT i.item_id, i.item_name, i.unit_id, i.base_unit_label,
    i.conversion_to_base, u.unit_name FROM inventory_items i JOIN units u ON u.unit_id = i.unit_id ORDER BY i.item_name')->fetch_all(MYSQLI_ASSOC);
$stmt = returnManagementStatement($conn, 'SELECT stock_id, item_id, batch_lot_no, expiration_date, quantity_available
    FROM inventory_stocks WHERE branch_id = ? ORDER BY stock_id');
$stmt->bind_param('s', $branch_id);
returnManagementExecute($stmt);
$sourceStocks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$stmt = returnManagementStatement($conn, "SELECT t.transaction_id, t.item_id, t.quantity, t.transaction_date,
    i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), u.unit_name) AS base_unit
    FROM stock_transactions t JOIN inventory_items i ON i.item_id = t.item_id JOIN units u ON u.unit_id = i.unit_id
    WHERE t.branch_id = ? AND t.transaction_type IN ('IN','RETURN','TRANSFER_IN') AND t.quantity > 0
      AND DATE(t.transaction_date) <= CURDATE()
      AND NOT EXISTS (SELECT 1 FROM inventory_returns ir WHERE ir.stock_transaction_id = t.transaction_id)
    ORDER BY t.transaction_date DESC, t.transaction_id DESC");
$stmt->bind_param('s', $branch_id);
returnManagementExecute($stmt);
$stockReceipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$stmt=returnManagementStatement($conn,"SELECT transaction_id,item_id,quantity,transaction_date,source_branch_id,
    receipt_source_type,source_name_snapshot,receipt_stock_id,receipt_batch_snapshot,receipt_base_unit_snapshot
    FROM stock_transactions WHERE branch_id=? AND transaction_type IN ('IN','RETURN','TRANSFER_IN')
      AND receipt_base_unit_snapshot IS NOT NULL AND quantity>0 AND DATE(transaction_date)<=CURDATE()
    ORDER BY transaction_date DESC,transaction_id DESC");
$stmt->bind_param('s',$branch_id); returnManagementExecute($stmt);
$originalReceipts=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$stmt=returnManagementStatement($conn,"SELECT t.transaction_id,t.item_id,t.quantity,t.transaction_date
    FROM stock_transactions t WHERE t.branch_id=? AND t.transaction_type IN ('OUT','TRANSFER_OUT') AND t.quantity>0
      AND DATE(t.transaction_date)<=CURDATE()
      AND NOT EXISTS (SELECT 1 FROM inventory_returns ir WHERE ir.sent_stock_transaction_id=t.transaction_id)
    ORDER BY t.transaction_date DESC,t.transaction_id DESC");
$stmt->bind_param('s',$branch_id); returnManagementExecute($stmt);
$returnOutgoingTransactions=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$inventoryChoicesJson = json_encode([$inventorySourceItems, $sourceStocks, $stockReceipts,$originalReceipts], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$returnRowsJson = json_encode($returns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($returnRowsJson === false) {
    $returnRowsJson = '[]';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Return Items Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="sidebar.css">
<link rel="stylesheet" href="notif-num.css">


<style>
:root {
    --primary: #2B3A8C;
    --primary-dark: #202d70;
    --text-dark: #1f2a44;
    --text-muted: #6c757d;
    --border: #e2e6ef;
    --page-bg: #f0f2f5;
    --card-bg: #ffffff;
}

/* =========================================================
   GLOBAL
   ========================================================= */

*,
*::before,
*::after {
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    min-height: 100%;
    margin: 0;
    padding: 0;
}

html {
    overflow-x: hidden;
}

body {
    background: var(--page-bg);
    font-family: 'Segoe UI', sans-serif;
    color: var(--text-dark);
    overflow-x: hidden;
}

/* =========================================================
   MAIN
   ========================================================= */

.main {
    margin-left: 260px;
    width: calc(100% - 260px);
    min-height: 100vh;
    min-width: 0;
}

/* =========================================================
   TOPBAR
   ========================================================= */

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



/* =========================================================
   PAGE BODY
   ========================================================= */

.page-body {
    width: 100%;
    min-width: 0;
    padding: 24px 30px 30px;
}

.return-page-header {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 16px;
}

.return-page-header h1 {
    margin: 0 0 4px;
    color: var(--primary);
    font-size: 28px;
    font-weight: 700;
    line-height: 1.2;
}

.return-page-header p {
    margin: 0;
    color: #6c757d;
    max-width: 900px;
    font-size: 13px;
    line-height: 1.4;
}

/* =========================================================
   BUTTON
   ========================================================= */

.btn-primary-custom {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--primary);
    border: 1px solid var(--primary);
    color: #fff;
    border-radius: 9px;
    font-weight: 600;
    padding: 9px 15px;
    font-size: 13px;
    transition: all .18s ease;
    white-space: nowrap;
    cursor: pointer;
}

.btn-primary-custom:hover,
.btn-primary-custom:focus {
    background: var(--primary-dark);
    border-color: var(--primary-dark);
    color: #fff;
    transform: translateY(-1px);
}

/* =========================================================
   INFO NOTE
   ========================================================= */

.info-note {
    width: 100%;
    background: #f4f7ff;
    border: 1px solid #dce5fb;
    border-radius: 9px;
    color: #47536c;
    font-size: 11px;
    line-height: 1.45;
    padding: 9px 11px;
    margin-bottom: 14px;
}

/* =========================================================
   SUMMARY CARDS
   ========================================================= */

.stat-grid {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}

.stat-card {
    position: relative;
    min-width: 0;
    border: 1px solid var(--border);
    border-left: 5px solid var(--primary);
    background: var(--card-bg);
    border-radius: 13px;
    padding: 15px 16px;
    min-height: 92px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 3px 12px rgba(31,42,80,.05);
    transition: transform .2s ease, box-shadow .2s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 9px 22px rgba(31,42,80,.12);
}

.stat-card.pending {
    border-left-color: #2a67c7;
}

.stat-card.ready {
    border-left-color: #6c4bd9;
}

.stat-card.sent {
    border-left-color: #2f8f5b;
}

.stat-card.resolved {
    border-left-color: #17803a;
}

.stat-icon {
    width: 46px;
    height: 46px;
    min-width: 46px;
    min-height: 46px;
    border-radius: 50%;
    background: transparent !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex: 0 0 auto;
}

.stat-card.pending .stat-icon {
    color: #2a67c7;
}

.stat-card.ready .stat-icon {
    color: #6c4bd9;
}

.stat-card.sent .stat-icon {
    color: #2f8f5b;
}

.stat-card.resolved .stat-icon {
    color: #17803a;
}

.stat-card > div:last-child {
    min-width: 0;
}

.stat-label {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 2px;
    line-height: 1.3;
}

.stat-number {
    font-size: 25px;
    font-weight: 700;
    color: #1f2a44;
    line-height: 1.05;
}

.stat-description {
    font-size: 11px;
    color: #8a93a5;
    margin-top: 3px;
    line-height: 1.35;
}

/* =========================================================
   PANEL
   ========================================================= */

.panel-card {
    width: 100%;
    min-width: 0;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(31,42,80,.05);
    overflow: hidden;
}

/* =========================================================
   FILTER TOOLBAR
   ========================================================= */

.panel-toolbar {
    width: 100%;
    padding: 12px 14px;
    border-bottom: 1px solid #edf0f5;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
}

.search-wrap {
    position: relative;
    flex: 1 1 auto;
    min-width: 200px;
}

.search-wrap i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #98a1b4;
    pointer-events: none;
    z-index: 1;
}

.search-wrap input {
    width: 100%;
    padding-left: 37px;
}

.filter-select,
.filter-date,
.form-control,
.form-select {
    border-radius: 8px !important;
    border: 1px solid #dbe0ea !important;
    min-height: 40px;
    height: 40px;
    font-size: 13px;
    color: #263149;
    box-shadow: none !important;
    max-width: 100%;
}

.filter-select:focus,
.filter-date:focus,
.form-control:focus,
.form-select:focus {
    border-color: #aebee8 !important;
    box-shadow: 0 0 0 3px rgba(43,58,140,.08) !important;
}

.filter-select {
    width: 150px;
    min-width: 150px;
    flex: 0 0 150px;
}

.filter-date {
    width: 155px;
    min-width: 155px;
    flex: 0 0 155px;
}

.btn-reset {
    min-height: 40px;
    height: 40px;
    border-radius: 8px;
    padding: 0 14px;
    font-size: 13px;
    border: 1px solid #e3e7ef;
    flex: 0 0 auto;
    white-space: nowrap;
}

/* =========================================================
   TRANSPORTATION LINK
   ========================================================= */

.transport-link {
    color: #1f66c2;
    text-decoration: none;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.transport-link:hover {
    text-decoration: underline;
}

/* =========================================================
   TABLE
   ========================================================= */

.table-responsive {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}

.return-table {
    margin: 0;
    width: 100%;
    min-width: 980px;
}

.return-table thead th {
    background: #f8f9fc;
    color: #4d5668;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .02em;
    white-space: nowrap;
    padding: 11px 12px;
    border-bottom: 1px solid #e2e6ef;
}

.return-table tbody td {
    vertical-align: middle;
    padding: 10px 12px;
    font-size: 13px;
    color: #263149;
    border-bottom: 1px solid #edf0f5;
}

.return-table tbody tr:hover {
    background: #fbfcff;
}

.return-table tbody tr:last-child td {
    border-bottom: none;
}

.return-number {
    color: #155db8;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

.return-number:hover {
    text-decoration: underline;
}


/* =========================================================
   TABLE PAGINATION
   ========================================================= */

.return-table-footer {
    display: grid;
    grid-template-columns: minmax(160px, 1fr) auto minmax(160px, 1fr);
    align-items: center;
    gap: 12px;
    padding: 14px;
    border-top: 1px solid #edf0f5;
    background: #fff;
}

.return-table-footer .branch-summary {
    text-align: right;
}

.pagination-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
}

.pagination-wrap .pagination {
    margin: 0;
    gap: 5px;
}

.pagination-wrap .page-item {
    margin: 0;
}

.pagination-wrap .page-link {
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #dfe4ef;
    border-radius: 8px !important;
    color: var(--primary);
    background: #fff;
    font-size: 12px;
    font-weight: 600;
    box-shadow: none !important;
    cursor: pointer;
}

.pagination-wrap .page-link:hover {
    background: #f0f3fc;
    border-color: #bac5e5;
    color: var(--primary-dark);
}

.pagination-wrap .page-item.active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.pagination-wrap .page-item.disabled .page-link {
    color: #a8b0bf;
    background: #f8f9fb;
    border-color: #e7eaf0;
    cursor: default;
    pointer-events: none;
}

.pagination-ellipsis .page-link {
    border-color: transparent;
    background: transparent;
    cursor: default;
    pointer-events: none;
}

@media (max-width: 768px) {
    .return-table-footer {
        grid-template-columns: 1fr;
        text-align: center;
    }

    .return-table-footer .branch-summary {
        text-align: center;
    }

    .pagination-wrap {
        order: 3;
    }
}

/* =========================================================
   STATUS
   ========================================================= */

.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.status-in-transit {
    background: #eee8ff;
    color: #6446ba;
}

.status-received {
    background: #e4f7e8;
    color: #1d7a3b;
}

/* =========================================================
   ACTION BUTTON
   ========================================================= */

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid #dde2ec;
    background: #fff;
    color: #40506f;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 13px;
    transition: all .15s ease;
    flex: 0 0 auto;
}

.action-btn:hover {
    background: #f4f6fb;
    border-color: #cdd5e4;
    color: var(--primary);
    transform: translateY(-1px);
}

.action-btn.primary-action {
    color: #1f66c2;
}

/* =========================================================
   EMPTY STATE
   ========================================================= */

.empty-state {
    padding: 38px 20px;
    text-align: center;
    color: #7d8799;
}

.empty-state i {
    display: block;
    font-size: 36px;
    opacity: .55;
    margin-bottom: 7px;
}

/* =========================================================
   MODAL
   ========================================================= */

.modal-header-custom {
    background: var(--primary);
    color: #fff;
}

.modal-header-custom .btn-close {
    filter: brightness(0) invert(1);
}

.modal-content {
    border-radius: 12px;
    overflow: hidden;
}

.field-label {
    font-size: 12px;
    font-weight: 700;
    color: #505a6c;
    margin-bottom: 5px;
}

.required {
    color: #dc3545;
}

.detail-label {
    font-size: 11px;
    color: #7a8496;
    margin-bottom: 4px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .25px;
}

.detail-value {
    font-size: 14px;
    color: #263149;
    font-weight: 600;
    line-height: 1.35;
    word-break: break-word;
    overflow-wrap: anywhere;
}

.modal-dialog.details-modal {
    width: calc(100% - 32px);
    max-width: 620px;
    margin: 1rem auto;
}

.details-modal .modal-content {
    max-height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
}

.details-modal .modal-body {
    overflow-y: auto;
    overflow-x: hidden;
    padding: 20px 24px;
}

.details-section {
    border: 1px solid #e5e9f2;
    border-radius: 10px;
    padding: 14px 16px;
    background: #fff;
}

.details-section + .details-section {
    margin-top: 14px;
}

.details-section-title {
    font-size: 12px;
    font-weight: 700;
    color: #4d5870;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 12px;
}

.details-remarks {
    background: #f7f8fb;
    border: 1px solid #e1e5ee;
    border-radius: 8px;
    padding: 12px 14px;
    color: #354056;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}

/* =========================================================
   TIMELINE
   ========================================================= */

.timeline {
    display: flex;
    align-items: flex-start;
    gap: 0;
    margin: 18px 4px 7px;
    width: calc(100% - 8px);
}

.timeline-step {
    flex: 1 1 0;
    min-width: 0;
    position: relative;
    text-align: center;
}

.timeline-step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 10px;
    left: 50%;
    width: 100%;
    height: 2px;
    background: #d8deea;
    z-index: 0;
}

.timeline-dot {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #d8deea;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
    font-size: 9px;
}

.timeline-step.active .timeline-dot,
.timeline-step.completed .timeline-dot {
    background: var(--primary);
}

.timeline-step.completed:not(:last-child)::after {
    background: #b7c7ec;
}

.timeline-label {
    font-size: 9px;
    color: #7c8799;
    margin-top: 6px;
    line-height: 1.3;
}

.timeline-step.active .timeline-label,
.timeline-step.completed .timeline-label {
    color: #27324a;
    font-weight: 700;
}

/* =========================================================
   STATUS NOTE
   ========================================================= */

.status-flow-note {
    background: #f4f7ff;
    border: 1px solid #dce5fb;
    border-radius: 9px;
    color: #47536c;
    font-size: 11px;
    line-height: 1.45;
    padding: 9px 11px;
}

/* =========================================================
   DESKTOP → TABLET
   ========================================================= */

@media (max-width: 1200px) {

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .panel-toolbar {
        flex-wrap: wrap;
    }

    .search-wrap {
        flex: 1 1 100%;
        min-width: 100%;
    }

    .filter-select,
    .filter-date {
        flex: 1 1 0;
        width: auto;
        min-width: 150px;
    }
}

/* =========================================================
   COLLAPSED SIDEBAR
   IMPORTANT:
   sidebar.css makes sidebar = 90px here.
   Main must ALSO remain 90px away.
   ========================================================= */

@media (max-width: 991px) {

    .main {
        margin-left: 90px !important;
        width: calc(100% - 90px) !important;
    }

    .topbar {
        min-height: 72px;
        height: 72px;
        padding: 0 20px;
        gap: 12px;
    }

    .topbar h3 {
        font-size: 22px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .topbar h3 small {
        font-size: 11px;
        margin-left: 5px;
    }

    .profile {
        font-size: 13px;
    }

    .page-body {
        padding: 20px;
    }

    .return-page-header {
        gap: 14px;
    }

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .stat-card {
        min-height: 86px;
    }
}

/* =========================================================
   HALF-SCREEN / SMALL TABLET
   ========================================================= */

@media (max-width: 768px) {

    /*
       DO NOT set margin-left to 0.
       The sidebar is still visible at 90px.
    */
    .main {
        margin-left: 90px !important;
        width: calc(100% - 90px) !important;
    }

    .topbar {
        min-height: 68px;
        height: auto;
        padding: 12px 14px;
        gap: 8px;
    }

    .topbar h3 {
        font-size: 19px;
        min-width: 0;
        flex: 1 1 auto;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .topbar h3 small {
        display: none;
    }

    .profile {
        font-size: 11px;
        max-width: 42%;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .page-body {
        padding: 14px;
    }

    .return-page-header {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }

    .return-page-header h1 {
        font-size: 22px;
    }

    .return-page-header p {
        font-size: 11px;
    }

    .btn-primary-custom {
        width: 100%;
    }

    /* One card per row at smaller width */
    .stat-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .stat-card {
        min-height: 80px;
        padding: 12px 14px;
    }

    .stat-icon {
        width: 40px;
        height: 40px;
        min-width: 40px;
        min-height: 40px;
        font-size: 18px;
    }

    .stat-number {
        font-size: 23px;
    }

    .stat-description {
        font-size: 10px;
    }

    /* Stack filters */
    .panel-toolbar {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
        padding: 10px;
    }

    .search-wrap,
    .filter-select,
    .filter-date,
    .btn-reset {
        width: 100%;
        min-width: 0;
        max-width: 100%;
        flex: none;
    }

    /* Table scrolls inside panel only */
    .table-responsive {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
    }

    .return-table {
        min-width: 920px;
    }

    .details-modal .modal-dialog {
        width: calc(100% - 20px);
        margin: 10px auto;
    }

    .details-modal .modal-content {
        max-height: calc(100vh - 20px);
    }

    .details-modal .modal-body {
        padding: 16px;
    }
}

/* =========================================================
   VERY SMALL WIDTH
   ========================================================= */

@media (max-width: 480px) {

    .main {
        margin-left: 90px !important;
        width: calc(100% - 90px) !important;
    }

    .topbar {
        padding: 10px 11px;
    }

    .topbar h3 {
        font-size: 16px;
    }

    .profile {
        display: none;
    }

    .page-body {
        padding: 10px;
    }

    .return-page-header h1 {
        font-size: 20px;
    }

    .stat-card {
        padding: 11px 12px;
    }

    .panel-toolbar {
        padding: 8px;
    }

    .details-modal .modal-body {
        padding: 12px;
    }

    .detail-value {
        font-size: 13px;
    }
}

/* =========================================================
   PRINT
   ========================================================= */

@media print {

    html,
    body {
        overflow: visible !important;
        background: #fff !important;
    }

    .sidebar,
    .topbar {
        display: none !important;
    }

    .main {
        margin-left: 0 !important;
        width: 100% !important;
    }

    .page-body {
        padding: 0 !important;
    }
}


/* =========================================================
   FIXED TOPBAR - THIS PAGE ONLY
   Keeps the topbar above the main content while leaving the
   sidebar in its original position.
   ========================================================= */
.topbar {
    position: fixed !important;
    top: 0 !important;
    left: 260px !important;
    width: calc(100% - 260px) !important;
    height: 80px !important;
    margin: 0 !important;
    z-index: 1030 !important;
    background: #fff !important;
}

.main {
    padding-top: 80px !important;
}

@media (max-width: 991px) {
    .topbar {
        left: 90px !important;
        width: calc(100% - 90px) !important;
        height: 72px !important;
    }

    .main {
        padding-top: 72px !important;
    }
}

@media (max-width: 768px) {
    .topbar {
        left: 90px !important;
        width: calc(100% - 90px) !important;
        height: 68px !important;
        min-height: 68px !important;
    }

    .main {
        padding-top: 68px !important;
    }
}

@media (max-width: 480px) {
    .topbar {
        left: 90px !important;
        width: calc(100% - 90px) !important;
        height: 60px !important;
        min-height: 60px !important;
        padding: 0 11px !important;
    }

    .main {
        padding-top: 60px !important;
    }
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
            <li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
            <li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
            <li><a class="active" href="InventoryOfficer_ReturnManagement.php"><i class="bi bi-arrow-return-left"></i><span>Return Management</span></a></li>
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
        <h3>Return Management<small><?php echo h($branch_name); ?></small></h3>
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
   <?php if ($flash): ?>
        <div
            id="returnFlashMessage"
            class="alert alert-<?php echo h($flash['type']); ?> fade show"
            role="alert"
        >
            <?php echo h($flash['message']); ?>
        </div>
    <?php endif; ?>

        <div class="return-page-header">
            <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#returnModal" onclick="prepareNewReturn()">
                <i class="bi bi-plus-lg me-1"></i> Record Return
            </button>
        </div>

        <div class="stat-grid">
            <div class="stat-card pending">
                <div class="stat-icon"><i class="bi bi-send"></i></div>
                <div>
                    <div class="stat-label">Sent Returns</div>
                    <div class="stat-number"><?php echo number_format($stats['sent_total']); ?></div>
                    <div class="stat-description">Returns sent by this branch</div>
                </div>
            </div>

            <div class="stat-card ready">
                <div class="stat-icon"><i class="bi bi-truck"></i></div>
                <div>
                    <div class="stat-label">In Transit</div>
                    <div class="stat-number"><?php echo number_format($stats['sent_in_transit']); ?></div>
                    <div class="stat-description">Sent returns still in transit</div>
                </div>
            </div>

            <div class="stat-card sent">
                <div class="stat-icon"><i class="bi bi-box-arrow-in-down"></i></div>
                <div>
                    <div class="stat-label">Received Returns</div>
                    <div class="stat-number"><?php echo number_format($stats['received_total']); ?></div>
                    <div class="stat-description">Returns sent to this branch</div>
                </div>
            </div>

            <div class="stat-card resolved">
                <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="stat-label">Received</div>
                    <div class="stat-number"><?php echo number_format($stats['received_count']); ?></div>
                    <div class="stat-description">Incoming returns confirmed received</div>
                </div>
            </div>
        </div>

        <div class="panel-card">
            <div class="panel-toolbar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" id="returnSearch" class="form-control" placeholder="Search return no., item, reason...">
                </div>

                <select id="statusFilter" class="form-select filter-select">
                    <option value="">All Status</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?php echo h($status); ?>"><?php echo h($status); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="reasonFilter" class="form-select filter-select">
                    <option value="">All Reasons</option>
                    <?php foreach ($allowedReturnReasons as $reason): ?>
                        <option value="<?php echo h($reason); ?>"><?php echo h($reason); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="routeFilter" class="form-select filter-select">
                    <option value="">All Routes</option>
                    <option value="Sent">Sent</option>
                    <option value="Received">Received</option>
                </select>

                <input type="date" id="dateFilter" class="form-control filter-date" title="Filter by date reported">
                <button type="button" class="btn btn-light btn-reset" onclick="resetReturnFilters()">Reset</button>
            </div>

            <div class="table-responsive">
                <table class="table return-table mb-0">
                    <thead>
                        <tr>
                            <th>Return No.</th>
                            <th>Date Reported</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Reason</th>
                            <th>Route</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="returnsBody">
                    <?php if ($returnError): ?>
                        <tr><td colspan="9" class="text-center text-danger py-4"><?php echo h($returnError); ?></td></tr>
                    <?php elseif (empty($returns)): ?>
                        <tr id="noReturnsRow">
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="bi bi-arrow-return-left"></i>
                                    <div class="fw-semibold">No return records yet.</div>
                                    <div class="small">Use <strong>Record Return</strong> to record a return shipment to another branch. Use the <strong>Status</strong> filter to view returns by In Transit or Received status.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($returns as $row): ?>
                            <?php
                                $statusClass = match ($row['status']) {
                                    'In Transit' => 'status-in-transit',
                                    'Received' => 'status-received',
                                    default => 'status-in-transit'
                                };
                                $lastUpdated = $row['received_at'] ?: ($row['sent_at'] ?: $row['created_at']);
                                $searchData = strtolower(
                                    ($row['return_number'] ?? '') . ' ' .
                                    ($row['item_name'] ?? '') . ' ' . ($row['unit_name'] ?? '') . ' ' .
                                        ($row['return_reason'] ?? '') . ' ' .
                                    ($row['source_name'] ?? '') . ' ' .
                                    ($row['destination_name'] ?? '') . ' ' .
                                    ($row['route'] ?? '') . ' ' .
                                    ($row['view_type'] ?? '') . ' ' .
                                    ($row['transportation_details'] ?? '')
                                );
                            ?>
                            <tr class="return-row"
                                data-search="<?php echo h($searchData); ?>"
                                data-status="<?php echo h($row['status']); ?>"
                                data-reason="<?php echo h($row['return_reason']); ?>"
                                data-route="<?php echo h($row['view_type']); ?>"
                                data-date="<?php echo h(date('Y-m-d', strtotime($row['created_at']))); ?>">
                                <td>
                                    <a href="#" class="return-number" onclick="openDetails(<?php echo (int)$row['return_id']; ?>); return false;">
                                        <?php echo h($row['return_number'] ?: ('RET-' . $row['return_id'])); ?>
                                    </a>
                                </td>
                                <td><?php echo h(date('M d, Y', strtotime($row['created_at']))); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo h($row['item_name']); ?></div>
                                    <div class="small text-muted"><?php echo h($row['unit_name'] ?? ''); ?></div>
                                </td>
                                <td><?php echo h(rtrim(rtrim(number_format((float)$row['quantity'], 2, '.', ''), '0'), '.')); ?></td>
                                <td><?php echo h($row['return_reason']); ?></td>
                                <td><?php echo h($row['route']); ?></td>
                                <td><span class="status-pill <?php echo h($statusClass); ?>"><?php echo h($row['status']); ?></span></td>
                                <td><?php echo h(date('M d, Y', strtotime($lastUpdated))); ?></td>
                                <td class="text-center">
                                    <div class="d-inline-flex gap-1">
                                        <?php if ($row['view_type']==='Sent' && $row['item_id'] && $row['inventory_quantity_snapshot']!==null && !$row['sent_stock_transaction_id']): ?>
                                            <button class="action-btn primary-action" type="button" title="Stock Out" onclick="openReturnStockOut(<?php echo (int)$row['return_id']; ?>)"><i class="bi bi-box-arrow-up"></i></button>
                                        <?php endif; ?>
                                        <button class="action-btn" type="button" title="View Details" onclick="openDetails(<?php echo (int)$row['return_id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($row['view_type'] === 'Received' && $row['status'] === 'In Transit'): ?>
                                            <button class="action-btn primary-action" type="button" title="Confirm Receipt" onclick="openReceiveModal(<?php echo (int)$row['return_id']; ?>)">
                                                <i class="bi bi-check2-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noFilteredReturnsRow" style="display:none;">
                            <td colspan="9">
                                <div class="empty-state py-4">
                                    <i class="bi bi-search"></i>
                                    <div class="fw-semibold">No matching return records.</div>
                                    <div class="small">Try changing or resetting the search and filters.</div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="return-table-footer">
                <div class="small text-muted" id="returnCountLabel"></div>

                <nav class="pagination-wrap" aria-label="Return records pagination">
                    <ul class="pagination pagination-sm" id="returnsPagination"></ul>
                </nav>

                <div class="small text-muted branch-summary">
                    Branch: <strong><?php echo h($branch_name); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Record / Edit Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    <span id="returnModalTitle">Record Return</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="InventoryOfficer_ReturnManagement.php" id="returnForm">
                <input type="hidden" name="csrf_token" value="<?php echo h($returnCsrf); ?>">
                <input type="hidden" name="action" value="save_return">
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="field-label">Inventory Item <span class="text-muted fw-normal">(Optional)</span></div>
                            <select name="inventory_item_id" id="inventory_item_id" class="form-select" onchange="updateSourceReference()">
                                <option value="">Delivery item entered as text</option>
                                <?php foreach ($inventorySourceItems as $item): ?>
                                    <option value="<?php echo (int)$item['item_id']; ?>"><?php echo h($item['item_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small text-muted mt-1">Select only when this shipment corresponds to an existing inventory item.</div>
                        </div>
                        <div class="col-12">
                            <div class="field-label">Sending Branch Batch <span class="text-muted fw-normal">(Optional)</span></div>
                            <select name="source_stock_id" id="source_stock_id" class="form-select" disabled onchange="updateOriginalReceipts()">
                                <option value="">No batch reference</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="source_receipt_transaction_id" class="field-label">Original Stock Receipt</label>
                            <select name="source_receipt_transaction_id" id="source_receipt_transaction_id" class="form-select" disabled onchange="updateReturnOrigin()">
                                <option value="">No original receipt selected</option>
                            </select>
                            <div id="return_origin_hint" class="small text-muted mt-1">Older or unstocked items need a destination explanation.</div>
                        </div>
                        <div class="col-12">
                            <label for="destination_reason" class="field-label">Destination Explanation</label>
                            <input name="destination_reason" id="destination_reason" maxlength="500" class="form-control" required placeholder="Why is this branch receiving the return?">
                            <div class="small text-muted mt-1">Required when the source is unknown/external, or the destination differs from the original source branch.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-label">Item Name <span class="required">*</span></div>
                            <input
                                type="text"
                                name="item_name"
                                id="item_name"
                                class="form-control"
                                maxlength="255"
                                required
                                value=""
                                placeholder="Enter item name..."
                            >
                        </div>

                        <div class="col-md-6">
                            <div class="field-label">Destination Branch <span class="required">*</span></div>
                            <select name="destination_branch_id" id="destination_branch_id" class="form-select" required onchange="updateDestinationReason()">
                                <option value="">Select destination...</option>
                                <?php foreach ($activeBranches as $branch): ?>
                                    <?php if ((string)$branch['branch_id'] === (string)$branch_id) continue; ?>
                                    <option value="<?php echo h($branch['branch_id']); ?>"
                                        <?php echo ((string)$mainBranchDefault === (string)$branch['branch_id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo h($branch['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="field-label">Transportation Details <span class="text-muted fw-normal">(Optional)</span></div>
                            <input
                                type="text"
                                name="transportation_details"
                                id="transportation_details"
                                class="form-control"
                                maxlength="500"
                                value=""
                                placeholder="Enter a Lalamove transaction link or other delivery reference..."
                            >
                            <div class="small text-muted mt-1">Optional. Use this field when a third-party delivery service or other transportation reference is available.</div>
                        </div>

                        <div class="col-md-6">
                            <div class="field-label">Return Quantity <span class="required">*</span></div>
                            <input
                                type="number"
                                name="quantity"
                                id="quantity"
                                class="form-control"
                                min="0.01"
                                step="0.01"
                                required
                                value=""
                                placeholder="Enter quantity..."
                            >
                        </div>

                    <div class="col-md-6">
                        <div class="field-label">
                            Unit <span class="required">*</span>
                        </div>

                        <select name="unit_id" id="unit_id" class="form-select" required>
                            <option value="">Select unit...</option>

                            <?php foreach ($units as $unit): ?>
                                <option value="<?php echo (int)$unit['unit_id']; ?>">
                                    <?php echo h($unit['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                        <div class="col-md-6">
                            <div class="field-label">Reason for Return <span class="required">*</span></div>
                            <select name="return_reason" id="return_reason" class="form-select" required>
                                <option value="">Select reason...</option>
                                <?php foreach ($allowedReturnReasons as $reason): ?>
                                    <option value="<?php echo h($reason); ?>" ><?php echo h($reason); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="field-label">Recorded By</div>
                            <input type="text" class="form-control" value="<?php echo h($username); ?> (Inventory Officer)" readonly>
                        </div>

                        <div class="col-12">
                            <div class="field-label">Problem Description / Remarks</div>
                            <textarea
                                name="remarks"
                                id="remarks"
                                rows="4"
                                maxlength="2000"
                                class="form-control"
                                placeholder="Describe the delivery issue (e.g., wrong item received, extra quantity delivered, damaged packaging, expired item)..."
                            ></textarea>
                        </div>
                    </div>

                    <div class="status-flow-note mt-3">
                        <strong>Delivery tracking:</strong> Saving marks this shipment <strong>In Transit</strong>. The destination confirms physical receipt. Record any stock movement separately in Stock Management.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="bi bi-save me-1"></i>
                        Save Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Return Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered details-modal">
        <div class="modal-content border-0 shadow">
            <div class="modal-header modal-header-custom py-3">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Return Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>



<!-- Sending-branch return Stock Out -->
<div class="modal fade" id="returnStockOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Return Stock Out</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST" action="InventoryOfficer_ReturnManagement.php">
            <input type="hidden" name="csrf_token" value="<?php echo h($returnCsrf); ?>">
            <input type="hidden" name="action" value="return_stock_out">
            <input type="hidden" name="return_id" id="out_return_id">
            <div class="modal-body">
                <p id="out_return_summary"></p>
                <label for="out_mode" class="field-label">Action</label>
                <select name="stock_out_mode" id="out_mode" class="form-select mb-3" onchange="updateReturnOutMode()">
                    <option value="record">Record Stock Out</option><option value="link">Link Existing Stock Out</option>
                </select>
                <div id="out_new_fields">
                    <label for="out_stock" class="field-label">Sending Stock Batch</label>
                    <select name="stock_out_stock_id" id="out_stock" class="form-select mb-3" required></select>
                    <label for="out_date" class="field-label">Stock Out Date</label>
                    <input type="date" name="stock_out_date" id="out_date" class="form-control" max="<?php echo h(date('Y-m-d')); ?>" required>
                </div>
                <div id="out_existing_fields" hidden>
                    <label for="out_transaction" class="field-label">Recorded Stock Out</label>
                    <select name="outgoing_transaction_id" id="out_transaction" class="form-select" disabled></select>
                </div>
                <p class="small text-muted mt-3 mb-0">Record Stock Out for goods included in sending-branch stock that have physically left. If already deducted in Stock Management, link that existing transaction. Delivery confirmation remains separate.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary-custom">Save Stock Out</button></div>
        </form>
    </div></div>
</div>

<!-- Confirm Receipt Modal -->
<div class="modal fade" id="receiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="bi bi-check2-circle me-2"></i><span id="receive_modal_title">Confirm Receipt</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="InventoryOfficer_ReturnManagement.php">
                <input type="hidden" name="csrf_token" value="<?php echo h($returnCsrf); ?>">
                <input type="hidden" name="action" value="confirm_receipt" id="receive_action">
                <input type="hidden" name="return_id" id="receive_return_id">

                <div class="modal-body">
                    <div class="alert alert-light border mb-0">
                        <div class="small text-muted mb-1">Return</div>
                        <div class="fw-bold" id="receive_return_number">—</div>
                        <div class="mt-2" id="receive_return_summary">—</div>
                    </div>

                    <div class="mt-3" id="receipt_reference_fields">
                        <label for="receipt_transaction_id" class="field-label">Existing Stock Receipt <span class="text-muted fw-normal">(Optional)</span></label>
                        <select name="receipt_transaction_id" id="receipt_transaction_id" class="form-select">
                            <option value="">Delivery confirmation only</option>
                        </select>
                        <div class="small text-muted mt-1">Choose a Stock In already recorded for this item and quantity. You may link it later after confirming delivery.</div>
                    </div>
                    <div class="status-flow-note mt-3" id="receive_note">
                        Confirm only after the returned item has physically arrived at this branch. The return will automatically change from <strong>In Transit</strong> to <strong>Received</strong>.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom" id="receive_submit"><i class="bi bi-check2-circle me-1"></i><span id="receive_submit_label">Confirm Receipt</span></button>
                </div>
            </form>
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
<script src="sources/sidebar.js"></script>  
<script>
    
const returnRows = <?php echo $returnRowsJson; ?>;
const returnOutgoingTransactions = <?php echo json_encode($returnOutgoingTransactions,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?: '[]'; ?>;
const [inventorySourceItems, sourceStocks, stockReceipts,originalReceipts] = <?php echo $inventoryChoicesJson ?: '[[],[],[],[]]'; ?>;

function updateSourceReference() {
    const itemId = document.getElementById('inventory_item_id').value;
    const item = inventorySourceItems.find(item => String(item.item_id) === itemId);
    const batch = document.getElementById('source_stock_id');
    batch.replaceChildren(new Option('No batch reference', ''));
    batch.disabled = !item;
    if (!item) { updateOriginalReceipts(); return; }
    document.getElementById('item_name').value = item.item_name;
    document.getElementById('unit_id').value = String(item.unit_id);
    sourceStocks.filter(stock => String(stock.item_id) === itemId).forEach(stock => {
        batch.add(new Option(`#${stock.stock_id} — ${stock.batch_lot_no || 'No lot number'}${stock.expiration_date ? ' — Expires ' + stock.expiration_date : ''}`, String(stock.stock_id)));
    });
    updateOriginalReceipts();
}

function updateOriginalReceipts() {
    const item=document.getElementById('inventory_item_id').value;
    const stock=document.getElementById('source_stock_id').value;
    const select=document.getElementById('source_receipt_transaction_id');
    select.replaceChildren(new Option('No original receipt selected',''));
    const choices=originalReceipts.filter(r=>String(r.item_id)===item && (!stock || String(r.receipt_stock_id)===stock));
    choices.forEach(r=>select.add(new Option(`#${r.transaction_id} — ${r.source_name_snapshot || 'Unknown source'} — ${r.quantity} ${r.receipt_base_unit_snapshot}`,String(r.transaction_id))));
    select.disabled=!item || !choices.length;
    if (stock && choices.length===1) select.value=String(choices[0].transaction_id);
    updateReturnOrigin();
}
function updateReturnOrigin() {
    const id=document.getElementById('source_receipt_transaction_id').value;
    const receipt=originalReceipts.find(r=>String(r.transaction_id)===id);
    const destination=document.getElementById('destination_branch_id');
    destination.value='';
    if (receipt?.receipt_source_type==='Branch' && Array.from(destination.options).some(o=>o.value===receipt.source_branch_id)) destination.value=receipt.source_branch_id;
    document.getElementById('return_origin_hint').textContent=receipt
        ? 'Recorded source: '+(receipt.source_name_snapshot || 'Unknown')+'. Destination follows a known source branch; explain any different destination.'
        : 'No verified receipt source selected. Choose the receiving branch and explain the destination.';
    updateDestinationReason();
}
function updateDestinationReason() {
    const id=document.getElementById('source_receipt_transaction_id').value;
    const receipt=originalReceipts.find(r=>String(r.transaction_id)===id);
    document.getElementById('destination_reason').required=!receipt || receipt.receipt_source_type!=='Branch'
        || document.getElementById('destination_branch_id').value!==receipt.source_branch_id;
}

function prepareNewReturn() {
    const form = document.getElementById('returnForm');
    if (!form) return;

    form.reset();
    updateSourceReference();
    const destination = document.getElementById('destination_branch_id');
    const defaultDestination = <?php echo json_encode($mainBranchDefault); ?>;
    if (destination && defaultDestination) {
        destination.value = defaultDestination;
    }
    document.getElementById('returnModalTitle').textContent = 'Record Return';
}

function openDetails(returnId) {
    const row = returnRows.find(r => Number(r.return_id) === Number(returnId));
    if (!row) return;

    const statusClass = getStatusClass(row.status);
    const qty = Number(row.quantity || 0).toLocaleString(undefined, {maximumFractionDigits: 2});
    const timeline = [
        {label: 'In Transit', date: row.sent_at || row.created_at, done: true},
        {label: 'Received', date: row.received_at, done: row.status === 'Received'}
    ].map((step, index) => {
        const active = row.status === 'In Transit' && index === 0;
        const completed = step.done && !active;
        const cls = active ? 'active' : (completed ? 'completed' : '');
        const icon = completed ? 'bi-check2' : 'bi-circle';
        return `<div class="timeline-step ${cls}">
                    <div class="timeline-dot"><i class="bi ${icon}"></i></div>
                    <div class="timeline-label">${escapeHtml(step.label)}${step.date ? `<span class="d-block small text-muted">${formatDateTime(step.date)}</span>` : ''}</div>
                </div>`;
    }).join('');

    const lastUpdated = row.received_at || row.sent_at || row.created_at;
    const actionButton = row.view_type === 'Received' && row.status === 'In Transit'
        ? `<button type="button" class="btn btn-primary-custom btn-sm" onclick="closeDetailsAndOpenReceive(${Number(row.return_id)})"><i class="bi bi-check2-circle me-1"></i>Confirm Receipt</button>`
        : '';

    const linkButton = row.view_type === 'Received' && row.status === 'Received'
        && row.item_id && row.inventory_quantity_snapshot != null && !row.stock_transaction_id
        ? `<button type="button" class="btn btn-primary-custom btn-sm" onclick="closeDetailsAndOpenReceive(${Number(row.return_id)})">Link Stock Receipt</button>` : '';

    const outButton=row.view_type==='Sent' && row.item_id && row.inventory_quantity_snapshot!=null && !row.sent_stock_transaction_id
        ? `<button type="button" class="btn btn-primary-custom btn-sm" onclick="closeDetailsAndOpenOut(${Number(row.return_id)})">Stock Out</button>` : '';

    let transportationHtml = '—';
    if (row.transportation_details) {
        const transport = String(row.transportation_details);
        if (/^https?:\/\/\S+$/i.test(transport)) {
            transportationHtml = `<a class="transport-link" href="${escapeHtml(transport)}" target="_blank" rel="noopener noreferrer">${escapeHtml(transport)}</a>`;
        } else {
            transportationHtml = escapeHtml(transport);
        }
    }

    document.getElementById('detailsContent').innerHTML = `
        <div class="d-flex justify-content-between align-items-start mb-3 gap-3">
            <div>
                <div class="fw-bold fs-4 text-primary">${escapeHtml(row.return_number || ('RET-' + row.return_id))}</div>
                <span class="status-pill ${statusClass}">${escapeHtml(row.status)}</span>
            </div>
            <div class="text-end">
                <div class="detail-label mb-1">Date Reported</div>
                <div class="detail-value">${formatDateTime(row.created_at)}</div>
            </div>
        </div>

        <div class="details-section">
            <div class="details-section-title">Return Information</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="detail-label">Item Name</div>
                    <div class="detail-value">${escapeHtml(row.item_name || '—')}</div>
                </div>
                <div class="col-md-3">
                    <div class="detail-label">Quantity</div>
                    <div class="detail-value">${escapeHtml(qty)}</div>
                </div>
                <div class="col-md-3">
                    <div class="detail-label">Unit</div>
                    <div class="detail-value">${escapeHtml(row.unit_name || '—')}</div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Route</div>
                    <div class="detail-value">${escapeHtml(row.route || '—')}</div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Return Reason</div>
                    <div class="detail-value">${escapeHtml(row.return_reason || '—')}</div>
                </div>
            </div>
        </div>

        <div class="details-section">
            <div class="details-section-title">Branch Transfer</div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="detail-label">From Branch</div>
                    <div class="detail-value">${escapeHtml(row.source_name || row.branch_id || '—')}</div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">To Branch</div>
                    <div class="detail-value">${escapeHtml(row.destination_name || row.destination_branch_id || '—')}</div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Recorded By</div>
                    <div class="detail-value">${escapeHtml(row.creator_username || '—')}</div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Received By</div>
                    <div class="detail-value">${escapeHtml(row.processor_username || '—')}</div>
                </div>
            </div>
        </div>

        <div class="details-section">
            <div class="details-section-title">Inventory Reference</div>
            <div class="detail-value">${escapeHtml(row.inventory_reference_label || 'Delivery record')}</div>
            ${row.item_id ? `<div class="small mt-2">Catalog: ${escapeHtml(row.catalog_item_name || 'Unavailable item')} (#${Number(row.item_id)})</div>` : ''}
            ${row.inventory_quantity_snapshot != null ? `<div class="small mt-2">Inventory quantity: ${escapeHtml(row.inventory_quantity_snapshot)} ${escapeHtml(row.inventory_base_unit_snapshot || '')}</div>` : ''}
            ${row.source_batch_snapshot ? `<div class="small mt-2">Sending batch: ${escapeHtml(row.source_batch_snapshot)}</div>` : ''}
            ${row.source_receipt_transaction_id ? `<div class="small mt-2">Original receipt #${Number(row.source_receipt_transaction_id)}: ${escapeHtml(row.original_source_name || 'Unknown source')}</div>` : ''}
            ${row.destination_reason ? `<div class="small mt-2">Destination explanation: ${escapeHtml(row.destination_reason)}</div>` : ''}
            ${row.sent_stock_transaction_id ? `<div class="small mt-2">Stock Out #${Number(row.sent_stock_transaction_id)}${row.outbound_transaction_id ? ': '+escapeHtml(row.outbound_quantity)+' '+escapeHtml(row.inventory_base_unit_snapshot)+' — '+escapeHtml(row.outbound_date)+' — '+escapeHtml(row.outbound_actor || '') : ' — reference needs review'}</div>` : ''}
            ${row.stock_out_batch_snapshot ? `<div class="small mt-2">Stock Out batch: ${escapeHtml(row.stock_out_batch_snapshot)}</div>` : ''}
            ${row.legacy_status ? `<div class="small text-muted mt-2">Original status: ${escapeHtml(row.legacy_status)}</div>` : ''}
            <div class="small text-muted mt-2">Stock movements are recorded separately in Stock Management.</div>
        </div>

        <div class="details-section">
            <div class="details-section-title">Transportation Details</div>
            <div class="details-remarks">${transportationHtml}</div>
        </div>

        <div class="details-section">
            <div class="details-section-title">Problem Description / Remarks</div>
            <div class="details-remarks">${escapeHtml(row.remarks || 'No remarks recorded.')}</div>
        </div>

        <div class="details-section">
            <div class="details-section-title">Return Status Timeline</div>
            <div class="timeline">${timeline}</div>
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <div class="detail-label">In Transit Since</div>
                    <div class="detail-value">${formatDateTime(row.sent_at)}</div>
                </div>
                <div class="col-md-6">
                    <div class="detail-label">Received At</div>
                    <div class="detail-value">${formatDateTime(row.received_at)}</div>
                </div>
            </div>
        </div>

        ${(actionButton || linkButton || outButton) ? `<div class="d-flex justify-content-end gap-2 mt-4">${actionButton}${linkButton}${outButton}</div>` : ''}
    `;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('detailsModal')).show();
}

function openReturnStockOut(returnId) {
    const row=returnRows.find(r=>Number(r.return_id)===Number(returnId));
    if(!row || row.view_type!=='Sent' || !row.item_id || row.inventory_quantity_snapshot==null || row.sent_stock_transaction_id) return;
    document.getElementById('out_return_id').value=row.return_id;
    document.getElementById('out_return_summary').textContent=(row.return_number || 'RET-'+row.return_id)+' — '+row.item_name+' — '+row.inventory_quantity_snapshot+' '+row.inventory_base_unit_snapshot;
    document.getElementById('out_mode').value='record';
    const origin=originalReceipts.find(r=>Number(r.transaction_id)===Number(row.source_receipt_transaction_id));
    const expected=row.stock_id || origin?.receipt_stock_id;
    const stock=document.getElementById('out_stock'); stock.replaceChildren(new Option('Select stock batch...',''));
    sourceStocks.filter(s=>Number(s.item_id)===Number(row.item_id) && (!expected || Number(s.stock_id)===Number(expected))).forEach(s=>{
        stock.add(new Option('#'+s.stock_id+' — '+(s.batch_lot_no || 'No lot number')+' — '+s.quantity_available+' '+row.inventory_base_unit_snapshot+(s.expiration_date ? ' — Expires '+s.expiration_date : ''),String(s.stock_id)));
    });
    if(stock.options.length===2) stock.value=stock.options[1].value;
    const sent=String(row.sent_at || row.created_at).slice(0,10);
    const date=document.getElementById('out_date'); date.min=sent; date.value=<?php echo json_encode(date('Y-m-d')); ?>;
    const select=document.getElementById('out_transaction'); select.replaceChildren(new Option('Select recorded Stock Out...',''));
    returnOutgoingTransactions.filter(t=>Number(t.item_id)===Number(row.item_id)
        && Math.round(Number(t.quantity)*10000)===Math.round(Number(row.inventory_quantity_snapshot)*10000)
        && String(t.transaction_date).slice(0,10)>=sent).forEach(t=>select.add(new Option('#'+t.transaction_id+' — '+t.quantity+' — '+String(t.transaction_date).slice(0,10),String(t.transaction_id))));
    updateReturnOutMode(); bootstrap.Modal.getOrCreateInstance(document.getElementById('returnStockOutModal')).show();
}
function updateReturnOutMode() {
    const record=document.getElementById('out_mode').value==='record';
    document.getElementById('out_new_fields').hidden=!record;
    document.getElementById('out_existing_fields').hidden=record;
    ['out_stock','out_date'].forEach(id=>{const e=document.getElementById(id);e.disabled=!record;e.required=record;});
    const transaction=document.getElementById('out_transaction'); transaction.disabled=record;transaction.required=!record;
}
function closeDetailsAndOpenOut(returnId) {
    const modal=bootstrap.Modal.getInstance(document.getElementById('detailsModal'));if(modal)modal.hide();
    setTimeout(()=>openReturnStockOut(returnId),250);
}

function openReceiveModal(returnId) {
    const row = returnRows.find(r => Number(r.return_id) === Number(returnId));
    if (!row || row.view_type !== 'Received' || !['In Transit', 'Received'].includes(row.status)) return;
    const linking = row.status === 'Received';
    if (linking && (!row.item_id || row.inventory_quantity_snapshot == null || row.stock_transaction_id)) return;
    document.getElementById('receive_action').value = linking ? 'link_stock_receipt' : 'confirm_receipt';
    document.getElementById('receive_submit_label').textContent = linking ? 'Link Stock Receipt' : 'Confirm Receipt';
    document.getElementById('receive_modal_title').textContent = linking ? 'Link Stock Receipt' : 'Confirm Receipt';
    document.getElementById('receive_note').textContent = linking
        ? 'Link an existing stock receipt. The stock movement is already recorded.'
        : 'Confirm only after the shipment physically arrives. This marks the delivery Received. Stock movements are recorded separately.';
    document.getElementById('receive_return_id').value = row.return_id;
    document.getElementById('receive_return_number').textContent = row.return_number || ('RET-' + row.return_id);
    document.getElementById('receive_return_summary').textContent = `${row.item_name || ''} | Qty: ${Number(row.quantity || 0).toLocaleString(undefined, {maximumFractionDigits: 2})} ${row.unit_name || ''} | From: ${row.source_name || row.branch_id || ''}`;
    const select = document.getElementById('receipt_transaction_id');
    select.replaceChildren(new Option(linking ? 'Select a recorded stock receipt...' : 'Delivery confirmation only', ''));
    select.required = linking;
    const canLink = !!row.item_id && row.inventory_quantity_snapshot != null && !row.stock_transaction_id;
    select.disabled = !canLink;
    document.getElementById('receipt_reference_fields').hidden = !canLink;
    const quantity = Math.round(Number(row.inventory_quantity_snapshot) * 10000);
    const sentDate = String(row.sent_at || row.created_at || '').slice(0, 10);
    if (canLink) stockReceipts.filter(receipt => Number(receipt.item_id) === Number(row.item_id)
        && Math.round(Number(receipt.quantity) * 10000) === quantity
        && String(receipt.transaction_date).slice(0, 10) >= sentDate).forEach(receipt => {
            select.add(new Option(`#${receipt.transaction_id} — ${receipt.quantity} ${receipt.base_unit} — ${String(receipt.transaction_date).slice(0,10)}`, String(receipt.transaction_id)));
        });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('receiveModal')).show();
}

function closeDetailsAndOpenReceive(returnId) {
    const details = bootstrap.Modal.getInstance(document.getElementById('detailsModal'));
    if (details) details.hide();
    setTimeout(() => openReceiveModal(returnId), 250);
}

function getStatusClass(status) {
    switch (status) {
        case 'In Transit': return 'status-in-transit';
        case 'Received': return 'status-received';
        default: return 'status-in-transit';
    }
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString([], {
        year:'numeric',
        month:'short',
        day:'numeric',
        hour:'numeric',
        minute:'2-digit'
    });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&':'&amp;',
        '<':'&lt;',
        '>':'&gt;',
        "'":'&#39;',
        '"':'&quot;'
    }[char]));
}

const RETURNS_PER_PAGE = 10;
let currentReturnPage = 1;

function getFilteredReturnRows() {
    const search = document.getElementById('returnSearch').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const reason = document.getElementById('reasonFilter').value;
    const route = document.getElementById('routeFilter').value;
    const date = document.getElementById('dateFilter').value;

    return Array.from(document.querySelectorAll('.return-row')).filter(row => {
        const matchesSearch = !search || (row.dataset.search || '').includes(search);
        const matchesStatus = !status || row.dataset.status === status;
        const matchesReason = !reason || row.dataset.reason === reason;
        const matchesRoute = !route || row.dataset.route === route;
        const matchesDate = !date || row.dataset.date === date;

        return matchesSearch && matchesStatus && matchesReason && matchesRoute && matchesDate;
    });
}

function paginationSequence(currentPage, totalPages) {
    if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    if (currentPage <= 4) {
        return [1, 2, 3, 4, 5, 'ellipsis', totalPages];
    }

    if (currentPage >= totalPages - 3) {
        return [
            1,
            'ellipsis',
            totalPages - 4,
            totalPages - 3,
            totalPages - 2,
            totalPages - 1,
            totalPages
        ];
    }

    return [
        1,
        'ellipsis',
        currentPage - 1,
        currentPage,
        currentPage + 1,
        'ellipsis',
        totalPages
    ];
}

function renderReturnPagination(totalPages) {
    const pagination = document.getElementById('returnsPagination');
    if (!pagination) return;

    pagination.innerHTML = '';

    if (totalPages <= 1) {
        pagination.closest('.pagination-wrap')?.classList.add('d-none');
        return;
    }

    pagination.closest('.pagination-wrap')?.classList.remove('d-none');

    const addPageItem = (label, pageNumber, options = {}) => {
        const li = document.createElement('li');
        li.className = 'page-item';

        if (options.active) li.classList.add('active');
        if (options.disabled) li.classList.add('disabled');
        if (options.ellipsis) li.classList.add('pagination-ellipsis');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'page-link';
        button.innerHTML = label;

        if (options.active) {
            button.setAttribute('aria-current', 'page');
        }

        if (!options.disabled && !options.ellipsis && Number.isInteger(pageNumber)) {
            button.addEventListener('click', () => {
                currentReturnPage = pageNumber;
                applyReturnFilters(false);

                const table = document.querySelector('.return-table');
                if (table) {
                    table.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        }

        li.appendChild(button);
        pagination.appendChild(li);
    };

    addPageItem(
        '<i class="bi bi-chevron-left"></i>',
        currentReturnPage - 1,
        {
            disabled: currentReturnPage <= 1
        }
    );

    paginationSequence(currentReturnPage, totalPages).forEach(pageNumber => {
        if (pageNumber === 'ellipsis') {
            addPageItem('&hellip;', null, {
                disabled: true,
                ellipsis: true
            });
            return;
        }

        addPageItem(String(pageNumber), pageNumber, {
            active: pageNumber === currentReturnPage
        });
    });

    addPageItem(
        '<i class="bi bi-chevron-right"></i>',
        currentReturnPage + 1,
        {
            disabled: currentReturnPage >= totalPages
        }
    );
}

function applyReturnFilters(resetPage = true) {
    const allRows = Array.from(document.querySelectorAll('.return-row'));
    const filteredRows = getFilteredReturnRows();

    if (resetPage) {
        currentReturnPage = 1;
    }

    const totalFiltered = filteredRows.length;
    const totalPages = Math.max(1, Math.ceil(totalFiltered / RETURNS_PER_PAGE));

    if (currentReturnPage > totalPages) {
        currentReturnPage = totalPages;
    }

    /* Hide every normal data row first. */
    allRows.forEach(row => {
        row.style.display = 'none';
    });

    const startIndex = (currentReturnPage - 1) * RETURNS_PER_PAGE;
    const endIndex = Math.min(startIndex + RETURNS_PER_PAGE, totalFiltered);

    filteredRows.slice(startIndex, endIndex).forEach(row => {
        row.style.display = '';
    });

    const noFilteredRow = document.getElementById('noFilteredReturnsRow');
    if (noFilteredRow) {
        noFilteredRow.style.display = totalFiltered === 0 && allRows.length > 0 ? '' : 'none';
    }

    const countLabel = document.getElementById('returnCountLabel');
    if (countLabel) {
        if (totalFiltered === 0) {
            countLabel.textContent = 'No returns found';
        } else {
            countLabel.textContent =
                `Showing ${startIndex + 1}–${endIndex} of ${totalFiltered} ` +
                `${totalFiltered === 1 ? 'return' : 'returns'}`;
        }
    }

    renderReturnPagination(totalFiltered === 0 ? 0 : totalPages);
}

function resetReturnFilters() {
    document.getElementById('returnSearch').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('reasonFilter').value = '';
    document.getElementById('routeFilter').value = '';
    document.getElementById('dateFilter').value = '';
    currentReturnPage = 1;
    applyReturnFilters(true);
}

document.addEventListener('DOMContentLoaded', function() {
    const search = document.getElementById('returnSearch');
    const status = document.getElementById('statusFilter');
    const reason = document.getElementById('reasonFilter');
    const route = document.getElementById('routeFilter');
    const date = document.getElementById('dateFilter');

    if (search) {
        search.addEventListener('input', () => applyReturnFilters(true));
    }

    if (status) {
        status.addEventListener('change', () => applyReturnFilters(true));
    }

    if (reason) {
        reason.addEventListener('change', () => applyReturnFilters(true));
    }

    if (route) {
        route.addEventListener('change', () => applyReturnFilters(true));
    }

    if (date) {
        date.addEventListener('change', () => applyReturnFilters(true));
    }

    applyReturnFilters(true);
});
</script>

<script>document.addEventListener('DOMContentLoaded',()=>{ const id=<?php echo json_encode((int)($_GET['return_id'] ?? 0)); ?>; if(id>0) openDetails(id); });</script>
</body>
</html>
