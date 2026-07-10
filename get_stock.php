<?php
session_start();
require_once 'sources/db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$item_id = intval($_GET['item_id'] ?? 0);
$branch_id = $_SESSION['branch_id'];

if ($item_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid item ID']);
    exit();
}

$sql = "SELECT COALESCE(SUM(quantity_available), 0) as total_stock 
        FROM inventory_stocks 
        WHERE item_id = ? AND branch_id = ? AND is_active = 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $item_id, $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

echo json_encode([
    'success' => true,
    'stock' => $data['total_stock'] ?? 0
]);
?>