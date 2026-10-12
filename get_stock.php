<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/inventory_stock_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stockEndpointError(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (empty($_SESSION['user_id'])) {
    stockEndpointError('Not authenticated', 401);
}

// Use the account's current branch, rather than a cached session branch.
$userId = (int)$_SESSION['user_id'];
$userStmt = $conn->prepare("SELECT branch_id FROM users WHERE user_id=? AND status='Active' LIMIT 1");
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!$user || empty($user['branch_id'])) {
    stockEndpointError('Your account has no active branch access.', 403);
}

$itemId = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
if (!$itemId || $itemId <= 0) {
    stockEndpointError('Invalid item ID', 400);
}

$branchId = (string)$user['branch_id'];
$stockTotalsSql = inventoryStockTotalsSql();
$stmt = $conn->prepare("SELECT COALESCE(s.usable_stock,0) AS total_stock,
    COALESCE(NULLIF(TRIM(i.base_unit_label),''),u.unit_name) AS unit_name
    FROM inventory_items i JOIN units u ON u.unit_id=i.unit_id
    LEFT JOIN ($stockTotalsSql) s ON s.item_id=i.item_id AND s.branch_id=?
    WHERE i.item_id=? LIMIT 1");
$stmt->bind_param('si', $branchId, $itemId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$data) {
    stockEndpointError('Item not found', 404);
}

echo json_encode([
    'success' => true,
    'stock' => (string)$data['total_stock'],
    'unit' => $data['unit_name'],
]);
