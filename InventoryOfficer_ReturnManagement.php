<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

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

function safeDecimal($value)
{
    return is_numeric($value) ? (float)$value : 0.0;
}

/* -------------------------------------------------------------
 * Return Management table
 *
 * IMPORTANT CHANGE:
 * Return Management is now a delivery-return workflow.
 * The item is entered as text because the returned item may not
 * yet exist in inventory_items/inventory_stocks.
 * ----------------------------------------------------------- */
function ensureReturnManagementTable($conn)
{
    /*
     * Return Management is a simple branch-to-branch return transfer.
     *
     * When the sending branch records the return, it is automatically
     * marked "In Transit". The receiving branch only confirms receipt,
     * which changes the record to "Received".
     *
     * Route is derived from branch_id -> destination_branch_id.
     * No separate route column is stored because that would duplicate
     * the branch relationship.
     *
     * Transportation Details are optional and may contain a Lalamove
     * transaction link or another delivery reference.
     *
     * No Batch/Lot field is stored in this workflow.
     */
    $sql = "CREATE TABLE IF NOT EXISTS inventory_returns (
        return_id INT(11) NOT NULL AUTO_INCREMENT,
        return_number VARCHAR(30) DEFAULT NULL,
        branch_id VARCHAR(10) NOT NULL,
        destination_branch_id VARCHAR(10) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        unit_id INT(11) NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        return_reason VARCHAR(100) NOT NULL,
        remarks TEXT DEFAULT NULL,
        transportation_details TEXT DEFAULT NULL,
        status ENUM('In Transit','Received') NOT NULL DEFAULT 'In Transit',
        created_by INT(11) NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        received_at DATETIME DEFAULT NULL,
        processed_by INT(11) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (return_id),
        UNIQUE KEY uq_inventory_returns_number (return_number),
        KEY idx_returns_branch_status (branch_id, status),
        KEY idx_returns_unit (unit_id),
        KEY idx_returns_destination (destination_branch_id),
        KEY idx_returns_created_by (created_by),
        KEY idx_returns_processed_by (processed_by),
        CONSTRAINT fk_return_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
        CONSTRAINT fk_return_unit FOREIGN KEY (unit_id) REFERENCES units (unit_id),
        CONSTRAINT fk_return_destination FOREIGN KEY (destination_branch_id) REFERENCES branches (branch_id),
        CONSTRAINT fk_return_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
        CONSTRAINT fk_return_processed_by FOREIGN KEY (processed_by) REFERENCES users (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($sql)) {
        throw new Exception('Unable to prepare Return Management database table: ' . $conn->error);
    }

    /* Add optional Transportation Details to an existing table. */
    $transportCheck = $conn->query("SHOW COLUMNS FROM inventory_returns LIKE 'transportation_details'");
    if ($transportCheck && $transportCheck->num_rows === 0) {
        if (!$conn->query("ALTER TABLE inventory_returns ADD COLUMN transportation_details TEXT DEFAULT NULL AFTER remarks")) {
            throw new Exception('Unable to add Transportation Details to Return Management: ' . $conn->error);
        }
    }

    /* Remove legacy Batch/Lot field if an older version still has it. */
    $columnCheck = $conn->query("SHOW COLUMNS FROM inventory_returns LIKE 'batch_lot_no'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        if (!$conn->query("ALTER TABLE inventory_returns DROP COLUMN batch_lot_no")) {
            throw new Exception('Unable to remove the legacy Batch/Lot field from Return Management: ' . $conn->error);
        }
    }

    /*
     * Migrate older status values into the new two-stage workflow.
     * Pending / Ready to Send / Sent / Transferred -> In Transit
     * Received / Resolved / Complete -> Received
     */
    $statusCheck = $conn->query("SHOW COLUMNS FROM inventory_returns LIKE 'status'");
    if ($statusCheck && ($statusColumn = $statusCheck->fetch_assoc())) {
        $type = (string)($statusColumn['Type'] ?? '');

        if (
            strpos($type, "'In Transit'") === false ||
            strpos($type, "'Received'") === false
        ) {
            if (!$conn->query("ALTER TABLE inventory_returns MODIFY status ENUM('Pending','Ready to Send','Sent to Main Branch','Transferred','Received','Resolved','Complete','In Transit') NOT NULL DEFAULT 'In Transit'")) {
                throw new Exception('Unable to prepare Return Management status migration: ' . $conn->error);
            }
        }

        if (!$conn->query("UPDATE inventory_returns
                           SET status = 'In Transit',
                               sent_at = COALESCE(sent_at, created_at)
                           WHERE status IN ('Pending','Ready to Send','Sent to Main Branch','Transferred')")) {
            throw new Exception('Unable to migrate old in-transit return statuses: ' . $conn->error);
        }

        if (!$conn->query("UPDATE inventory_returns
                           SET status = 'Received',
                               received_at = COALESCE(received_at, created_at)
                           WHERE status IN ('Received','Resolved','Complete')")) {
            throw new Exception('Unable to migrate old received return statuses: ' . $conn->error);
        }

        if (!$conn->query("ALTER TABLE inventory_returns MODIFY status ENUM('In Transit','Received') NOT NULL DEFAULT 'In Transit'")) {
            throw new Exception('Unable to finalize Return Management status migration: ' . $conn->error);
        }
    }

    /* Remove legacy resolution-only columns; they are no longer part of this workflow. */
    foreach (['resolved_at', 'resolution', 'resolution_remarks'] as $legacyColumn) {
        $legacyCheck = $conn->query("SHOW COLUMNS FROM inventory_returns LIKE '" . $conn->real_escape_string($legacyColumn) . "'");
        if ($legacyCheck && $legacyCheck->num_rows > 0) {
            if (!$conn->query("ALTER TABLE inventory_returns DROP COLUMN `{$legacyColumn}`")) {
                throw new Exception('Unable to remove legacy Return Management field ' . $legacyColumn . ': ' . $conn->error);
            }
        }
    }
}

function addReturnAuditLog($conn, $userId, $branchId, $action)
{
    $module = 'Return Management';
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, branch_id, action, module, created_at) VALUES (?, ?, ?, ?, NOW())");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isss', $userId, $branchId, $action, $module);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
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

try {
    ensureReturnManagementTable($conn);
} catch (Throwable $e) {
    http_response_code(500);
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

$mainBranchDefault = '';
foreach ($activeBranches as $branch) {
    $name = strtolower((string)$branch['branch_name']);
    if (strpos($name, 'main') !== false && (string)$branch['branch_id'] !== (string)$branch_id) {
        $mainBranchDefault = $branch['branch_id'];
        break;
    }
}

if ($mainBranchDefault === '') {
    foreach ($activeBranches as $branch) {
        if ((string)$branch['branch_id'] !== (string)$branch_id) {
            $mainBranchDefault = $branch['branch_id'];
            break;
        }
    }
}

/* -------------------------------------------------------------
 * CSRF
 * ----------------------------------------------------------- */
if (empty($_SESSION['return_management_csrf'])) {
    $_SESSION['return_management_csrf'] = bin2hex(random_bytes(32));
}
$returnCsrf = $_SESSION['return_management_csrf'];

/* -------------------------------------------------------------
 * POST actions
 * ----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($returnCsrf, (string)$postedToken)) {
        returnFlash('danger', 'Security validation failed. Please try again.');
        redirectReturnPage();
    }

    $action = $_POST['action'] ?? '';

    /* -------------------------- RECORD RETURN ------------------------- */
    if ($action === 'save_return') {
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $unitId = filter_input(INPUT_POST, 'unit_id', FILTER_VALIDATE_INT);
        $destinationBranchId = trim((string)($_POST['destination_branch_id'] ?? ''));
        $quantityRaw = trim((string)($_POST['quantity'] ?? ''));
        $returnReason = trim((string)($_POST['return_reason'] ?? ''));
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        $transportationDetails = trim((string)($_POST['transportation_details'] ?? ''));
        $quantity = safeDecimal($quantityRaw);

       if (
            $itemName === '' ||
            !$unitId ||
            $destinationBranchId === '' ||
            $quantity <= 0 ||
            !in_array($returnReason, $allowedReturnReasons, true)
        ) {
            returnFlash('danger', 'Please complete all required return details with valid values.');
            redirectReturnPage();
        }

        if ($destinationBranchId === (string)$branch_id) {
            returnFlash('danger', 'The return destination must be different from your current clinic branch.');
            redirectReturnPage();
        }

        try {
            $conn->begin_transaction();

            /* Verify selected destination branch. */
            $destinationStmt = $conn->prepare("SELECT branch_id, branch_name FROM branches WHERE branch_id = ? AND status = 'Active' LIMIT 1");
            if (!$destinationStmt) {
                throw new Exception('Unable to verify destination branch.');
            }
            $destinationStmt->bind_param('s', $destinationBranchId);
            $destinationStmt->execute();
            $destination = $destinationStmt->get_result()->fetch_assoc();
            $destinationStmt->close();

            if (!$destination) {
                throw new Exception('The selected destination branch is not active.');
            }

            /* Verify the selected unit exists. */
            $unitStmt = $conn->prepare("SELECT unit_id FROM units WHERE unit_id = ? LIMIT 1");
            if (!$unitStmt) {
                throw new Exception('Unable to verify the selected unit.');
            }
            $unitStmt->bind_param('i', $unitId);
            $unitStmt->execute();
            $unitExists = $unitStmt->get_result()->num_rows > 0;
            $unitStmt->close();

            if (!$unitExists) {
                throw new Exception('The selected unit does not exist.');
            }

            /*
             * Recording the return is the transfer event.
             * The record is automatically In Transit.
             */
            $now = date('Y-m-d H:i:s');
            $insert = $conn->prepare("INSERT INTO inventory_returns
                (return_number, branch_id, destination_branch_id, item_name, unit_id, quantity, return_reason, remarks, transportation_details, status, created_by, sent_at, processed_by, created_at)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'In Transit', ?, ?, NULL, ?)");

            if (!$insert) {
                throw new Exception('Unable to prepare return record.');
            }

            $insert->bind_param(
                'sssidsssiss',
                $branch_id,
                $destinationBranchId,
                $itemName,
                $unitId,
                $quantity,
                $returnReason,
                $remarks,
                $transportationDetails,
                $user_id,
                $now,
                $now
            );

            if (!$insert->execute()) {
                throw new Exception('Unable to save return record: ' . $insert->error);
            }

            $newReturnId = (int)$conn->insert_id;
            $insert->close();

            $returnNumber = 'RET-' . date('Y') . '-' . str_pad((string)$newReturnId, 6, '0', STR_PAD_LEFT);
            $numberUpdate = $conn->prepare("UPDATE inventory_returns SET return_number = ? WHERE return_id = ?");
            if (!$numberUpdate) {
                throw new Exception('Unable to assign return number.');
            }
            $numberUpdate->bind_param('si', $returnNumber, $newReturnId);
            if (!$numberUpdate->execute()) {
                throw new Exception('Unable to assign return number: ' . $numberUpdate->error);
            }
            $numberUpdate->close();

            addReturnAuditLog(
                $conn,
                $user_id,
                $branch_id,
                'Recorded and automatically transferred return ' . $returnNumber . ' for ' . $itemName . ' (' . $quantity . ') to branch ' . $destinationBranchId
            );

            $conn->commit();
            returnFlash('success', 'Return ' . $returnNumber . ' was recorded and is now In Transit.');
            redirectReturnPage();
        } catch (Throwable $e) {
            $conn->rollback();
            returnFlash('danger', $e->getMessage());
            redirectReturnPage();
        }
    }

    /* -------------------------- CONFIRM RECEIPT ------------------------- */
    if ($action === 'confirm_receipt') {
        $returnId = filter_input(INPUT_POST, 'return_id', FILTER_VALIDATE_INT);

        if (!$returnId) {
            returnFlash('danger', 'Invalid return record.');
            redirectReturnPage();
        }

        try {
            $conn->begin_transaction();

            /* Only the destination branch can confirm receipt. */
            $returnStmt = $conn->prepare("SELECT ir.*, b1.branch_name AS source_name, b2.branch_name AS destination_name
                                          FROM inventory_returns ir
                                          LEFT JOIN branches b1 ON b1.branch_id = ir.branch_id
                                          LEFT JOIN branches b2 ON b2.branch_id = ir.destination_branch_id
                                          WHERE ir.return_id = ?
                                            AND ir.destination_branch_id = ?
                                          LIMIT 1 FOR UPDATE");
            if (!$returnStmt) {
                throw new Exception('Unable to load return record.');
            }

            $returnStmt->bind_param('is', $returnId, $branch_id);
            $returnStmt->execute();
            $returnData = $returnStmt->get_result()->fetch_assoc();
            $returnStmt->close();

            if (!$returnData) {
                throw new Exception('Return record not found or you are not the destination branch.');
            }

            if ($returnData['status'] !== 'In Transit') {
                throw new Exception('This return has already been received.');
            }

            $receivedAt = date('Y-m-d H:i:s');
            $update = $conn->prepare("UPDATE inventory_returns
                                      SET status = 'Received',
                                          received_at = ?,
                                          processed_by = ?
                                      WHERE return_id = ?
                                        AND destination_branch_id = ?
                                        AND status = 'In Transit'");
            if (!$update) {
                throw new Exception('Unable to prepare receipt confirmation.');
            }

            $update->bind_param('siis', $receivedAt, $user_id, $returnId, $branch_id);
            if (!$update->execute()) {
                throw new Exception('Unable to confirm receipt: ' . $update->error);
            }
            $update->close();

            addReturnAuditLog(
                $conn,
                $user_id,
                $branch_id,
                'Confirmed receipt of return ' . ($returnData['return_number'] ?? ('RET-' . $returnId))
            );

            $conn->commit();
            returnFlash('success', 'Return ' . ($returnData['return_number'] ?? ('RET-' . $returnId)) . ' was received and marked Received.');
            redirectReturnPage();
        } catch (Throwable $e) {
            $conn->rollback();
            returnFlash('danger', $e->getMessage());
            redirectReturnPage();
        }
    }
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
                    sb.branch_name AS source_name,
                    db.branch_name AS destination_name,
                    creator.username AS creator_username,
                    processor.username AS processor_username
                FROM inventory_returns ir
                LEFT JOIN units un ON un.unit_id = ir.unit_id
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
        $returns[] = $row;
    }
    $returnListStmt->close();
} else {
    $returnError = 'Unable to load return records.';
}

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

.details-modal .modal-dialog {
    width: calc(100% - 32px);
    max-width: 860px;
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
                    <a class="dropdown-item rounded-2 py-2 text-danger" href="logout.php">
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
                                    <div class="small">Use <strong>Record Return</strong> to record and automatically transfer an item to another branch. Use the <strong>Status</strong> filter to view returns by In Transit or Received status.</div>
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
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-top">
                <div class="small text-muted" id="returnCountLabel"></div>
                <div class="small text-muted">Branch: <strong><?php echo h($branch_name); ?></strong></div>
            </div>
        </div>
    </div>
</div>

<!-- Record / Edit Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
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
                            <select name="destination_branch_id" id="destination_branch_id" class="form-select" required>
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
                        <strong>Automatic transfer:</strong> Saving this record immediately marks it as <strong>In Transit</strong> to the selected branch. The receiving branch only needs to confirm receipt.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="bi bi-save me-1"></i>
                        Save & Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Return Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered details-modal">
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

<!-- Confirm Receipt Modal -->
<div class="modal fade" id="receiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="bi bi-check2-circle me-2"></i>Confirm Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="InventoryOfficer_ReturnManagement.php">
                <input type="hidden" name="csrf_token" value="<?php echo h($returnCsrf); ?>">
                <input type="hidden" name="action" value="confirm_receipt">
                <input type="hidden" name="return_id" id="receive_return_id">

                <div class="modal-body">
                    <div class="alert alert-light border mb-0">
                        <div class="small text-muted mb-1">Return</div>
                        <div class="fw-bold" id="receive_return_number">—</div>
                        <div class="mt-2" id="receive_return_summary">—</div>
                    </div>

                    <div class="status-flow-note mt-3">
                        Confirm only after the returned item has physically arrived at this branch. The return will automatically change from <strong>In Transit</strong> to <strong>Received</strong>.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2-circle me-1"></i>Confirm Receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
const returnRows = <?php echo $returnRowsJson; ?>;

function prepareNewReturn() {
    const form = document.getElementById('returnForm');
    if (!form) return;

    form.reset();
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
    const qty = Number(row.quantity || 0).toLocaleString();
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

        ${actionButton ? `<div class="d-flex justify-content-end mt-4">${actionButton}</div>` : ''}
    `;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('detailsModal')).show();
}

function openReceiveModal(returnId) {
    const row = returnRows.find(r => Number(r.return_id) === Number(returnId));
    if (!row || row.view_type !== 'Received' || row.status !== 'In Transit') return;

    document.getElementById('receive_return_id').value = row.return_id;
    document.getElementById('receive_return_number').textContent = row.return_number || ('RET-' + row.return_id);
    document.getElementById('receive_return_summary').textContent = `${row.item_name || ''} | Qty: ${Number(row.quantity || 0).toLocaleString()} ${row.unit_name || ''} | From: ${row.source_name || row.branch_id || ''}`;

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

function applyReturnFilters() {
    const search = document.getElementById('returnSearch').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const reason = document.getElementById('reasonFilter').value;
    const route = document.getElementById('routeFilter').value;
    const date = document.getElementById('dateFilter').value;
    const rows = document.querySelectorAll('.return-row');
    let visible = 0;

    rows.forEach(row => {
        const matchesSearch = !search || row.dataset.search.includes(search);
        const matchesStatus = !status || row.dataset.status === status;
        const matchesReason = !reason || row.dataset.reason === reason;
        const matchesRoute = !route || row.dataset.route === route;
        const matchesDate = !date || row.dataset.date === date;
        const show = matchesSearch && matchesStatus && matchesReason && matchesRoute && matchesDate;

        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('returnCountLabel').textContent =
        `${visible} ${visible === 1 ? 'return' : 'returns'} shown`;
}

function resetReturnFilters() {
    document.getElementById('returnSearch').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('reasonFilter').value = '';
    document.getElementById('routeFilter').value = '';
    document.getElementById('dateFilter').value = '';
    applyReturnFilters();
}

document.addEventListener('DOMContentLoaded', function() {
    const search = document.getElementById('returnSearch');
    const status = document.getElementById('statusFilter');
    const reason = document.getElementById('reasonFilter');
    const route = document.getElementById('routeFilter');
    const date = document.getElementById('dateFilter');
    if (search) search.addEventListener('input', applyReturnFilters);
    if (status) status.addEventListener('change', applyReturnFilters);
    if (reason) reason.addEventListener('change', applyReturnFilters);
    if (route) route.addEventListener('change', applyReturnFilters);
    if (date) date.addEventListener('change', applyReturnFilters);

    applyReturnFilters();
});
</script>

</body>
</html>
