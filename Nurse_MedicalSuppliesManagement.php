<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/inventory_unit_helpers.php';
require_once 'sources/forecast_training_helper.php';
require_once 'sources/notification_helper.php';

/* ============================================================
 * NURSE - MEDICAL SUPPLY MANAGEMENT
 * ------------------------------------------------------------
 * Purpose of this page:
 *   - Branch medical supply monitoring and consumption recording
 *   - Low/out-of-stock and expiration monitoring
 *   - Medical-supply usage history and batch stock deductions
 *   - Restock requests to the branch Inventory Officer
 *
 * IMPORTANT:
 *   Only Record Supply Usage deducts stock here. Never re-enter consumption
 *   already recorded in Vaccination or Assessment. This page does not create
 *   clinical administration records. Daily closing remains a separate step.
 * ============================================================ */

$user = workflowRequireUser($conn, 3); // Nurse only
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$branchName = (string)($user['branch_name'] ?? $branchId);
$username = (string)($user['username'] ?? 'Nurse');
$csrf = workflowCsrfToken();
$notification_count = getUnreadNotificationCount($conn, $userId);

function validInventoryDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function supplyStatus(array $item): array
{
    $stock = (float)($item['current_stock'] ?? 0);
    $minimum = (float)($item['minimum_stock'] ?? 0);
    $nearestExpiry = trim((string)($item['nearest_expiry'] ?? ''));

    if ($stock <= 0) {
        return ['Out of Stock', 'out'];
    }

    if ($minimum > 0 && $stock <= $minimum) {
        return ['Low Stock', 'low'];
    }

    if ($nearestExpiry !== '') {
        $days = (int)floor((strtotime($nearestExpiry) - strtotime(date('Y-m-d'))) / 86400);
        if ($days >= 0 && $days <= 30) {
            return ['Expiring Soon', 'expiring'];
        }
    }

    return ['In Stock', 'normal'];
}

function restockRecipientIds(mysqli $conn, string $branchId): array
{
    $ids = [];

    $stmt = $conn->prepare(
        "SELECT user_id
         FROM users
         WHERE branch_id=?
           AND role_id=5
           AND status='Active'
         ORDER BY user_id"
    );
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['user_id'];
    }
    $stmt->close();

    if ($ids) {
        return array_values(array_unique($ids));
    }

    // Fallback only when this branch has no active Inventory Officer.
    $fallback = $conn->query(
        "SELECT user_id
         FROM users
         WHERE role_id=1
           AND status='Active'
         ORDER BY user_id"
    );
    if ($fallback) {
        while ($row = $fallback->fetch_assoc()) {
            $ids[] = (int)$row['user_id'];
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function fetchMedicalSupply(mysqli $conn, int $itemId): ?array
{
    $stmt = $conn->prepare(
        "SELECT i.item_id,i.item_name,i.minimum_stock,i.is_consumable,u.unit_name,
                COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label,
                COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base
         FROM inventory_items i
         INNER JOIN inventory_categories c ON c.category_id=i.category_id
         INNER JOIN units u ON u.unit_id=i.unit_id
         WHERE i.item_id=?
           AND c.category_name='Medical Supplies'
         LIMIT 1"
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function currentUsableStock(mysqli $conn, int $itemId, string $branchId): float
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity_available),0) AS total
         FROM inventory_stocks
         WHERE item_id=?
           AND branch_id=?
           AND quantity_available>0
           AND (expiration_date IS NULL OR expiration_date>=CURDATE())"
    );
    $stmt->bind_param('is', $itemId, $branchId);
    $stmt->execute();
    $total = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function supplyStatement(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare medical-supply records.');
    return $stmt;
}

function supplyExecute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) throw new RuntimeException('Unable to save medical-supply records.');
}

function supplyTextLength(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8')
        : (preg_match_all('/./us', $text, $matches) ?: strlen($text));
}

/** One form token represents one stock movement, including browser retries. */
function supplyUsageFormToken(): string
{
    $tokens = $_SESSION['supply_usage_tokens'] ?? [];
    if (!is_array($tokens)) $tokens = [];
    foreach ($tokens as $token => $issuedAt) {
        if ((int)$issuedAt < time() - 86400) unset($tokens[$token]);
    }
    // Keep separate forms in different tabs usable without growing the session.
    $tokens = array_slice($tokens, -63, null, true);
    $token = bin2hex(random_bytes(32));
    $tokens[$token] = time();
    $_SESSION['supply_usage_tokens'] = $tokens;
    return $token;
}

/** All stock, usage, audit, and forecast changes succeed or roll back together. */
function recordSupplyUsage(mysqli $conn, int $userId, string $branchId): string
{
    $token = trim((string)($_POST['usage_token'] ?? ''));
    $issuedAt = $_SESSION['supply_usage_tokens'][$token] ?? 0;
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || (int)$issuedAt < time() - 86400) {
        throw new RuntimeException('This usage form expired. Refresh the page and try again.');
    }
    if (($_POST['usage_not_previously_recorded'] ?? '') !== '1') {
        throw new RuntimeException('Confirm that this consumption has not already been recorded.');
    }
    $itemId = filter_var($_POST['item_id'] ?? '', FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    $quantityText = trim((string)($_POST['quantity_used'] ?? ''));
    // Match DECIMAL(12,4), reject exponent notation and silently truncated input.
    if (!$itemId || !preg_match('/^\d{1,8}(?:\.\d{1,4})?$/D', $quantityText)) {
        throw new RuntimeException('Select a supply and enter a quantity with at most four decimal places.');
    }
    $quantity = (float)$quantityText;
    if ($quantity <= 0 || !is_finite($quantity)) {
        throw new RuntimeException('Quantity used must be greater than zero.');
    }
    $date = trim((string)($_POST['usage_date'] ?? ''));
    if (!validInventoryDate($date)) throw new RuntimeException('Enter a valid usage date.');
    forecastTrainingAssertDate($conn, $date);
    $remarks = trim((string)($_POST['usage_remarks'] ?? ''));
    if (supplyTextLength($remarks) > 500) throw new RuntimeException('Usage remarks must not exceed 500 characters.');
    $patientText = trim((string)($_POST['patient_id'] ?? ''));
    $patientId = $patientText === '' ? 0 : filter_var($patientText, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($patientId === false) throw new RuntimeException('Enter a valid patient ID or leave it blank.');

    $conn->begin_transaction();
    try {
        // The same branch lock is used by patched Vaccination and Daily Inventory.
        forecastTrainingRevision($conn, $branchId, true);
        $marker = 'Supply usage #' . $token . ' | ';
        $markerPattern = $marker . '%';
        $duplicate = supplyStatement($conn,
            "SELECT transaction_id FROM stock_transactions
             WHERE branch_id=? AND user_id=? AND transaction_type='OUT'
               AND vaccination_id IS NULL AND remarks LIKE ? LIMIT 1");
        $duplicate->bind_param('sis', $branchId, $userId, $markerPattern);
        supplyExecute($duplicate);
        $alreadyRecorded = $duplicate->get_result()->fetch_assoc();
        $duplicate->close();
        if ($alreadyRecorded) {
            $conn->commit();
            return 'This supply usage was already saved. Stock was not deducted again.';
        }

        $item = fetchMedicalSupply($conn, (int)$itemId);
        if (!$item || !(bool)$item['is_consumable']) {
            throw new RuntimeException('Select a consumable medical supply, not reusable equipment.');
        }
        if (inventoryIsSiteBased($item) && abs($quantity - round($quantity)) > 0.00001) {
            throw new RuntimeException('Site-based consumption must be entered in whole sites.');
        }
        $patientLabel = '';
        if ($patientId > 0) {
            $patient = supplyStatement($conn,
                'SELECT patient_id FROM patients WHERE patient_id=? AND branch_id=? LIMIT 1');
            $patient->bind_param('is', $patientId, $branchId);
            supplyExecute($patient);
            $foundPatient = $patient->get_result()->fetch_assoc();
            $patient->close();
            if (!$foundPatient) throw new RuntimeException('The patient was not found in your branch.');
            $patientLabel = ' | Patient ID: ' . $patientId;
        }

        $stock = supplyStatement($conn,
            "SELECT stock_id,batch_lot_no,quantity_available,expiration_date
             FROM inventory_stocks
             WHERE item_id=? AND branch_id=? AND quantity_available>0
               AND (expiration_date IS NULL OR expiration_date>=CURDATE())
             ORDER BY CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
                      expiration_date,stock_id FOR UPDATE");
        $stock->bind_param('is', $itemId, $branchId);
        supplyExecute($stock);
        $batches = $stock->get_result()->fetch_all(MYSQLI_ASSOC);
        $stock->close();
        $available = round(array_sum(array_map(fn($batch) => (float)$batch['quantity_available'], $batches)), 4);
        if ($available + 0.00005 < $quantity) {
            throw new RuntimeException('Insufficient usable stock. Available: '
                . inventoryUsageDescription($available, $item) . '.');
        }
        $remaining = $quantity;
        $batchDetails = [];
        foreach ($batches as $batch) {
            if ($remaining <= 0.00005) break;
            $take = round(min((float)$batch['quantity_available'], $remaining), 4);
            $stockId = (int)$batch['stock_id'];
            $update = supplyStatement($conn,
                'UPDATE inventory_stocks SET quantity_available=quantity_available-?,
                 last_updated=CURRENT_TIMESTAMP WHERE stock_id=? AND item_id=? AND branch_id=?
                 AND quantity_available>=?');
            $update->bind_param('diisd', $take, $stockId, $itemId, $branchId, $take);
            supplyExecute($update);
            if ($update->affected_rows !== 1) throw new RuntimeException('Stock changed. Refresh and try again.');
            $update->close();
            $batchDetails[] = 'Stock #' . $stockId . ' / Lot '
                . (trim((string)$batch['batch_lot_no']) ?: 'N/A') . ': '
                . inventoryUsageDescription($take, $item);
            $remaining = round($remaining - $take, 4);
        }
        if ($remaining > 0.00005) throw new RuntimeException('Unable to allocate the complete supply quantity.');

        $patientsServed = $patientId > 0 ? 1 : 0;
        $usage = supplyStatement($conn,
            'INSERT INTO inventory_usage_history
             (item_id,branch_id,usage_date,quantity_used,patient_count) VALUES (?,?,?,?,?)');
        $usage->bind_param('issdi', $itemId, $branchId, $date, $quantity, $patientsServed);
        supplyExecute($usage);
        $usageId = (int)$conn->insert_id;
        $usage->close();
        $description = inventoryUsageDescription($quantity, $item);
        $movementRemarks = $marker . 'Usage ID: ' . $usageId
            . ' | Used: ' . $description . ' | Usage date: ' . $date . $patientLabel
            . ' | Batches: ' . implode('; ', $batchDetails)
            . ($remarks !== '' ? ' | Note: ' . $remarks : '');
        $movement = supplyStatement($conn,
            "INSERT INTO stock_transactions
             (item_id,user_id,vaccination_id,branch_id,transaction_type,quantity,remarks,transaction_date)
             VALUES (?,?,NULL,?,'OUT',?,?,CONCAT(?, ' ', TIME(NOW())))");
        $movement->bind_param('iisdss', $itemId, $userId, $branchId, $quantity, $movementRemarks, $date);
        supplyExecute($movement);
        $movement->close();
        $reopened = forecastTrainingUsageChanged($conn, $branchId, (int)$itemId, $date);
        $staleReason = 'Actual medical-supply usage changed for ' . $date . '.';
        $stale = supplyStatement($conn, 'UPDATE forecast_results SET stale_reason=? WHERE branch_id=?');
        $stale->bind_param('ss', $staleReason, $branchId);
        supplyExecute($stale);
        $stale->close();
        $auditText = 'Recorded supply usage: ' . $item['item_name'] . ' | ' . $description
            . ' | Date: ' . $date . $patientLabel . ' | Usage ID: ' . $usageId;
        $audit = supplyStatement($conn,
            "INSERT INTO audit_logs(user_id,branch_id,action,module) VALUES (?,?,?,'Medical Supplies')");
        $audit->bind_param('iss', $userId, $branchId, $auditText);
        supplyExecute($audit);
        $audit->close();
        $conn->commit();
        return 'Supply usage recorded: ' . $item['item_name'] . ' — ' . $description
            . '. Branch stock was deducted and Daily Inventory consumption was updated.'
            . ($reopened > 0 ? ' Resubmit the Daily Inventory closing for this item/date.'
                : ' Submit the Daily Inventory closing to include this usage in forecasting.');
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

/* ============================================================
 * RECORD CONSUMPTION OR REQUEST RESTOCK
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'record_usage') {
            workflowFlash('success', recordSupplyUsage($conn, $userId, $branchId));
            header('Location: Nurse_MedicalSuppliesManagement.php');
            exit;
        }
        if ($action !== 'request_restock') {
            throw new RuntimeException('Unsupported medical-supply action.');
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        $requestedDisplayQty = (float)($_POST['requested_quantity'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));

        if ($itemId < 1 || $requestedDisplayQty <= 0) {
            throw new RuntimeException('Select a medical supply and enter a valid requested quantity.');
        }
        if (supplyTextLength($reason) > 500) {
            throw new RuntimeException('Restock reason must not exceed 500 characters.');
        }

        $item = fetchMedicalSupply($conn, $itemId);
        if (!$item) {
            throw new RuntimeException('The selected item is not a valid medical supply.');
        }

        $currentStock = currentUsableStock($conn, $itemId, $branchId);
        $displayUnit = inventoryDisplayUnitLabel($item);
        $baseUnit = inventoryBaseUnitLabel($item);
        $conversion = inventoryConversionToBase($item);
        $baseEquivalent = inventoryDisplayToBase($requestedDisplayQty, $item);

        $requestedText = inventoryFormatNumber($requestedDisplayQty) . ' ' . $displayUnit;
        if ($conversion > 1 && strcasecmp($displayUnit, $baseUnit) !== 0) {
            $requestedText .= ' (' . inventoryFormatNumber($baseEquivalent) . ' ' . $baseUnit . ' equivalent)';
        }

        $recipients = restockRecipientIds($conn, $branchId);
        if (!$recipients) {
            throw new RuntimeException('No Inventory Officer or Super Admin is available to receive the request.');
        }

        $title = 'Restock Request: ' . $item['item_name'];
        $message =
            "Requested Quantity: {$requestedText}\n" .
            'Current Usable Stock: ' . inventoryStockBreakdown($currentStock, $item) . "\n" .
            'Reason: ' . ($reason !== '' ? $reason : 'No reason provided') . "\n" .
            "Branch: {$branchName}\n" .
            "Requested By: {$username}";

        $conn->begin_transaction();

        $insert = $conn->prepare(
            "INSERT INTO notifications
             (user_id,title,message,notification_type,is_read,created_at)
             VALUES (?,?,?,'restock_request',0,NOW())"
        );
        foreach ($recipients as $recipientId) {
            $insert->bind_param('iss', $recipientId, $title, $message);
            $insert->execute();
        }
        $insert->close();

        workflowAudit(
            $conn,
            $userId,
            $branchId,
            'Requested restock for ' . $item['item_name'] . ': ' . $requestedText,
            'Medical Supplies'
        );

        $conn->commit();
        workflowFlash('success', 'Restock request sent successfully.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        workflowFlash('danger', $e->getMessage());
    }

    header('Location: Nurse_MedicalSuppliesManagement.php');
    exit;
}

/* ============================================================
 * FILTERS
 * ============================================================ */
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$allowedStatuses = ['all', 'normal', 'low', 'out', 'expiring'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$today = date('Y-m-d');
$usageFrom = trim((string)($_GET['usage_from'] ?? date('Y-m-d', strtotime('-6 days'))));
$usageTo = trim((string)($_GET['usage_to'] ?? $today));
if (!validInventoryDate($usageFrom)) {
    $usageFrom = date('Y-m-d', strtotime('-6 days'));
}
if (!validInventoryDate($usageTo)) {
    $usageTo = $today;
}
if ($usageFrom > $usageTo) {
    [$usageFrom, $usageTo] = [$usageTo, $usageFrom];
}

/* ============================================================
 * BRANCH MEDICAL SUPPLY SNAPSHOT
 * ============================================================ */
$stmt = $conn->prepare(
    "SELECT
        i.item_id,
        i.item_name,
        i.is_consumable,
        i.minimum_stock,
        u.unit_name,
        COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label,
        COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label,
        COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
        COALESCE(SUM(
            CASE
                WHEN s.quantity_available>0
                 AND (s.expiration_date IS NULL OR s.expiration_date>=CURDATE())
                THEN s.quantity_available ELSE 0
            END
        ),0) AS current_stock,
        COALESCE(SUM(
            CASE
                WHEN s.quantity_available>0
                 AND s.expiration_date IS NOT NULL
                 AND s.expiration_date<CURDATE()
                THEN s.quantity_available ELSE 0
            END
        ),0) AS expired_stock,
        COUNT(DISTINCT CASE
            WHEN s.quantity_available>0
             AND (s.expiration_date IS NULL OR s.expiration_date>=CURDATE())
            THEN s.stock_id END
        ) AS usable_batches,
        MIN(CASE
            WHEN s.quantity_available>0
             AND s.expiration_date IS NOT NULL
             AND s.expiration_date>=CURDATE()
            THEN s.expiration_date END
        ) AS nearest_expiry,
        MAX(s.last_updated) AS last_updated,
        COALESCE(ut.today_used,0) AS today_used
     FROM inventory_items i
     INNER JOIN inventory_categories c ON c.category_id=i.category_id
     INNER JOIN units u ON u.unit_id=i.unit_id
     LEFT JOIN inventory_stocks s
       ON s.item_id=i.item_id
      AND s.branch_id=?
     LEFT JOIN (
         SELECT item_id,SUM(quantity_used) AS today_used
         FROM inventory_usage_history
         WHERE branch_id=? AND usage_date=CURDATE()
         GROUP BY item_id
     ) ut ON ut.item_id=i.item_id
     WHERE c.category_name='Medical Supplies'
     GROUP BY i.item_id,i.item_name,i.is_consumable,i.minimum_stock,u.unit_name,
              i.base_unit_label,i.display_unit_label,i.conversion_to_base,ut.today_used
     ORDER BY i.item_name"
);
$stmt->bind_param('ss', $branchId, $branchId);
$stmt->execute();
$allItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($allItems as &$item) {
    [$statusLabel, $statusKey] = supplyStatus($item);
    $item['status_label'] = $statusLabel;
    $item['status_key'] = $statusKey;
    $item['stock_display'] = inventoryStockBreakdown((float)$item['current_stock'], $item);
    $item['minimum_display'] = inventoryUsageDescription((float)$item['minimum_stock'], $item);
    $item['today_usage_display'] = inventoryUsageDescription((float)$item['today_used'], $item);
    $item['request_step'] = inventoryIsSiteBased($item) ? '1' : '0.0001';
}
unset($item);
$usageItems = array_values(array_filter($allItems, fn(array $item): bool => (bool)$item['is_consumable']));
$usageToken = supplyUsageFormToken();

$stats = [
    'total' => count($allItems),
    'low' => 0,
    'out' => 0,
    'expiring' => 0,
];
foreach ($allItems as $item) {
    if ($item['status_key'] === 'low') $stats['low']++;
    if ($item['status_key'] === 'out') $stats['out']++;
    if ($item['status_key'] === 'expiring') $stats['expiring']++;
}

$filteredItems = array_values(array_filter($allItems, function (array $item) use ($search, $statusFilter): bool {
    if ($statusFilter !== 'all' && $item['status_key'] !== $statusFilter) {
        return false;
    }
    if ($search === '') {
        return true;
    }
    $needle = mb_strtolower($search);
    $haystack = mb_strtolower(
        (string)$item['item_name'] . ' ' .
        (string)$item['status_label'] . ' ' .
        (string)$item['base_unit_label'] . ' ' .
        (string)$item['display_unit_label']
    );
    return mb_strpos($haystack, $needle) !== false;
}));

/* ============================================================
 * ITEM PAGINATION
 * ============================================================ */
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalFiltered = count($filteredItems);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$items = array_slice($filteredItems, $offset, $perPage);

function supplyPageUrl(int $page, string $search, string $status, string $usageFrom, string $usageTo): string
{
    return 'Nurse_MedicalSuppliesManagement.php?' . http_build_query([
        'search' => $search,
        'status' => $status,
        'usage_from' => $usageFrom,
        'usage_to' => $usageTo,
        'page' => $page,
    ]);
}

/* ============================================================
 * READ-ONLY USAGE HISTORY
 * ============================================================ */
$usageStmt = $conn->prepare(
    "SELECT
        h.usage_date,
        i.item_id,
        i.item_name,
        u.unit_name,
        COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label,
        COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label,
        COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
        SUM(h.quantity_used) AS quantity_used,
        SUM(COALESCE(h.patient_count,0)) AS patient_count,
        COUNT(*) AS usage_entries
     FROM inventory_usage_history h
     INNER JOIN inventory_items i ON i.item_id=h.item_id
     INNER JOIN inventory_categories c ON c.category_id=i.category_id
     INNER JOIN units u ON u.unit_id=i.unit_id
     WHERE h.branch_id=?
       AND h.usage_date BETWEEN ? AND ?
       AND c.category_name='Medical Supplies'
     GROUP BY h.usage_date,i.item_id,i.item_name,u.unit_name,
              i.base_unit_label,i.display_unit_label,i.conversion_to_base
     ORDER BY h.usage_date DESC,i.item_name ASC
     LIMIT 100"
);
$usageStmt->bind_param('sss', $branchId, $usageFrom, $usageTo);
$usageStmt->execute();
$usageRows = $usageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$usageStmt->close();

// Manual usage details retain patient references, notes, nurse, and batch allocations.
$movementStmt = supplyStatement($conn,
    "SELECT t.transaction_date,t.quantity,t.remarks,i.item_name,u.unit_name,
            COALESCE(NULLIF(i.base_unit_label,''),u.unit_name) AS base_unit_label,
            COALESCE(NULLIF(i.display_unit_label,''),u.unit_name) AS display_unit_label,
            COALESCE(NULLIF(i.conversion_to_base,0),1) AS conversion_to_base,
            nurse.username
     FROM stock_transactions t
     JOIN inventory_items i ON i.item_id=t.item_id
     JOIN units u ON u.unit_id=i.unit_id
     JOIN users nurse ON nurse.user_id=t.user_id
     WHERE t.branch_id=? AND t.transaction_type='OUT' AND t.vaccination_id IS NULL
       AND t.remarks LIKE 'Supply usage #%'
       AND DATE(t.transaction_date) BETWEEN ? AND ?
     ORDER BY t.transaction_date DESC,t.transaction_id DESC LIMIT 100");
$movementStmt->bind_param('sss', $branchId, $usageFrom, $usageTo);
supplyExecute($movementStmt);
$manualMovements = $movementStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$movementStmt->close();

$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medical Supply Management - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">
    <style>
        :root {
            --primary:#2B3A8C;
            --primary-dark:#1f2d6e;
            --success:#28a745;
            --danger:#dc3545;
            --warning:#f0ad00;
            --info:#17a2b8;
            --text:#1f2a44;
            --muted:#6f7b91;
            --border:#e6eaf2;
            --bg:#f9faff;
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:'Segoe UI',Roboto,system-ui,sans-serif}
        .main{min-height:100vh;margin-left:260px}
        .topbar{height:80px;padding:0 35px;display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid #e9edf5;box-shadow:0 2px 8px rgba(0,0,0,.06)}
        .topbar h3{margin:0;color:var(--primary);font-size:28px;font-weight:700;letter-spacing:-.3px}
        .topbar h3 small{margin-left:10px;color:#666;font-size:16px;font-weight:400}
        .profile{display:flex;align-items:center;gap:6px;color:var(--primary);font-weight:600}
        .content{padding:35px 35px 40px}
        .page-intro{display:flex;align-items:flex-start;gap:13px;margin-bottom:22px;padding:16px 18px;background:#edf1ff;border:1px solid #dce3fb;border-radius:12px}
        .page-intro i{font-size:23px;color:var(--primary)}
        .page-intro strong{display:block;color:var(--primary);margin-bottom:3px}
        .page-intro p{margin:0;color:#64708b;font-size:13px;line-height:1.5}
        .stat-card{height:112px;padding:18px;background:#fff;border-radius:16px;box-shadow:0 3px 10px rgba(0,0,0,.07);display:flex;align-items:center;gap:14px;border-left:5px solid var(--primary)}
        .stat-card.low{border-left-color:var(--warning)}
        .stat-card.out{border-left-color:var(--danger)}
        .stat-card.expiring{border-left-color:var(--info)}
        .stat-icon{width:48px;height:48px;display:flex;align-items:center;justify-content:center;border-radius:12px;background:#eef1ff;color:var(--primary);font-size:23px}
        .stat-card.low .stat-icon{background:#fff6d9;color:#b27c00}
        .stat-card.out .stat-icon{background:#fdebec;color:var(--danger)}
        .stat-card.expiring .stat-icon{background:#e4f7fb;color:#14879a}
        .stat-label{font-size:13px;color:var(--muted);margin-bottom:2px}
        .stat-value{font-size:28px;font-weight:750;color:#172142;line-height:1}
        .content-card{margin-top:24px;background:#fff;border-radius:18px;box-shadow:0 3px 10px rgba(0,0,0,.07);overflow:hidden}
        .card-head{padding:20px 24px;border-bottom:1px solid #edf0f5;display:flex;align-items:center;justify-content:space-between;gap:15px}
        .card-head h2{margin:0;color:var(--primary);font-size:19px;font-weight:700;display:flex;align-items:center;gap:9px}
        .card-head p{margin:5px 0 0;color:var(--muted);font-size:13px}
        .section-icon{width:36px;height:36px;border-radius:9px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center}
        .filters{padding:18px 24px;background:#fbfcff;border-bottom:1px solid #edf0f5}
        .form-control,.form-select{min-height:44px;border-color:#d9dfeb;border-radius:9px}
        .form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 .2rem rgba(43,58,140,.12)}
        .btn-primary{background:var(--primary);border-color:var(--primary);font-weight:650;min-height:44px}
        .btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark)}
        .quick-actions{display:flex;gap:9px;flex-wrap:wrap}
        .quick-actions .btn{border-radius:9px;font-weight:600}
        .inventory-table{min-width:1150px;margin:0}
        .inventory-table thead th{padding:13px 15px;background:#f8f9fc;color:#667085;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;letter-spacing:.25px;white-space:nowrap}
        .inventory-table tbody td{padding:14px 15px;border-color:#edf0f5;color:#34405d;font-size:13px;vertical-align:middle}
        .inventory-table tbody tr:hover{background:#fafbff}
        .item-name{color:var(--primary);font-weight:700}
        .status-badge{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
        .status-badge.normal{background:#e7f7ed;color:#198754}
        .status-badge.low{background:#fff5d6;color:#926a00}
        .status-badge.out{background:#fdebec;color:#c0392b}
        .status-badge.expiring{background:#e4f7fb;color:#137d90}
        .expired-note{display:block;margin-top:4px;color:#c0392b;font-size:11px;font-weight:600}
        .stock-sub{display:block;margin-top:3px;color:#8993a8;font-size:11px}
        .btn-icon{width:36px;height:36px;border:1px solid #dbe1ee;border-radius:9px;background:#fff;color:var(--primary);display:inline-flex;align-items:center;justify-content:center}
        .btn-icon:hover{background:#eef1ff;border-color:#cbd3e8}
        .empty{padding:42px 20px!important;text-align:center;color:#8a94a6!important}
        .pagination-wrap{display:flex;justify-content:center;align-items:center;gap:8px;padding:18px 20px 6px}
        .pagination-wrap .page-link{color:var(--primary);border-radius:8px;padding:8px 14px;font-weight:500;border:1px solid #e2e7f2}
        .pagination-wrap .page-item.active .page-link{background:var(--primary);border-color:var(--primary);color:#fff}
        .pagination-info{text-align:center;color:#7a85a8;font-size:13px;padding:0 20px 18px}
        .usage-date{white-space:nowrap;font-weight:600;color:#425071}
        .note-box{padding:12px 14px;background:#f7f9ff;border:1px solid #e2e7f6;border-radius:10px;color:#65718d;font-size:12px}
        .modal-content{border:0;border-radius:18px;box-shadow:0 18px 50px rgba(31,45,110,.18)}
        .modal-header{padding:20px 24px;background:var(--primary);color:#fff;border:0}
        .modal-header .modal-title{font-weight:700}
        .modal-header .btn-close{filter:invert(1)}
        .modal-body{padding:24px}
        .modal-footer{padding:16px 24px;border-top:1px solid #edf0f5}
        .request-info{padding:12px 14px;border-radius:10px;background:#f7f9ff;border:1px solid #e2e7f6;color:#55627e;font-size:13px}
        .confirm-modal .modal-header{display:block!important;padding:26px 24px 6px!important;background:#fff!important;text-align:center!important}
        .confirm-modal .modal-icon{width:64px;height:64px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto 14px;color:#fff;background:linear-gradient(135deg,#ef3340,#f05b68);border-radius:50%;font-size:27px}
        .confirm-modal .modal-title{color:#283a7a!important;font-size:24px;font-weight:700}
        .confirm-modal .modal-body{padding:6px 30px 22px;text-align:center;color:#7a879e}
        .confirm-modal .modal-footer{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:0 24px 26px;border:0}
        @media(max-width:991px){.main{margin-left:90px}.topbar{padding:0 22px}.content{padding:28px 22px 35px}.topbar h3 small{display:none}}
        @media(max-width:767px){.topbar{height:70px;padding:0 16px}.topbar h3{font-size:20px}.content{padding:20px 14px 30px}.card-head,.filters{padding:17px}}
    </style>
</head>
<body>
<!-- ========== SIDEBAR ========== -->
<aside class="sidebar" id="sidebar">

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
    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Calendar.php"><i class="bi bi-calendar3"></i><span>Calendar</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a class="active" href="Nurse_MedicalSuppliesManagement.php" aria-current="page"><i class="bi bi-box2-heart-fill"></i><span>Medical Supply Monitoring</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
            <li><a  href="Nurse_Notification.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a></li>
        </ul>
    </nav>
</aside>

<main class="main">
    <div class="topbar">
        <h3>Medical Supply Management <small><?= workflowH($branchName) ?></small></h3>
        <div class="dropdown">
            <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                    type="button" id="nurseProfileMenu" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span><?= workflowH($username) ?></span>
                <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| Nurse</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2" aria-labelledby="nurseProfileMenu">
                <li><h6 class="dropdown-header">Account options</h6></li>
                <li><a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php"><i class="bi bi-key-fill me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item rounded-2 py-2 text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>

    <div class="content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= workflowH((string)$flash['type']) ?> alert-dismissible fade show border-0 shadow-sm" role="alert">
                <?= workflowH((string)$flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="page-intro">
            <i class="bi bi-info-circle-fill"></i>
            <div>
                <strong>Record supplies used during treatment or daily clinic work</strong>
                <p>Record Supply Usage deducts branch stock and updates daily consumption. Enter only usage that has not already been recorded in Vaccination or Assessment. Submit Daily Inventory separately after counting the remaining stock.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-3 col-md-6"><div class="stat-card"><div class="stat-icon"><i class="bi bi-boxes"></i></div><div><div class="stat-label">Medical Supply Items</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div></div></div>
            <div class="col-xl-3 col-md-6"><div class="stat-card low"><div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div><div class="stat-label">Low Stock</div><div class="stat-value"><?= (int)$stats['low'] ?></div></div></div></div>
            <div class="col-xl-3 col-md-6"><div class="stat-card out"><div class="stat-icon"><i class="bi bi-x-octagon-fill"></i></div><div><div class="stat-label">Out of Stock</div><div class="stat-value"><?= (int)$stats['out'] ?></div></div></div></div>
            <div class="col-xl-3 col-md-6"><div class="stat-card expiring"><div class="stat-icon"><i class="bi bi-calendar2-exclamation-fill"></i></div><div><div class="stat-label">Expiring Within 30 Days</div><div class="stat-value"><?= (int)$stats['expiring'] ?></div></div></div></div>
        </div>

        <section class="content-card" id="recordSupplyUsage">
            <div class="card-head">
                <div><h2><span class="section-icon"><i class="bi bi-box-arrow-up"></i></span>Record Supply Usage</h2>
                    <p>Enter actual consumption that has not already been recorded. Stock is deducted when you save.</p></div>
            </div>
            <div class="p-3 p-md-4">
                <form method="post" id="supplyUsageForm" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                    <input type="hidden" name="action" value="record_usage">
                    <input type="hidden" name="usage_token" value="<?= workflowH($usageToken) ?>">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold" for="usageItem">Medical Supply</label>
                        <select class="form-select" name="item_id" id="usageItem" required>
                            <option value="">Select a consumable item</option>
                            <?php foreach ($usageItems as $item): ?>
                                <option value="<?= (int)$item['item_id'] ?>"
                                        data-base-unit="<?= workflowH((string)$item['base_unit_label']) ?>"
                                        data-display-unit="<?= workflowH((string)$item['display_unit_label']) ?>"
                                        data-conversion="<?= workflowH((string)$item['conversion_to_base']) ?>"
                                        data-step="<?= workflowH(inventoryInputStep($item)) ?>"
                                        data-stock="<?= workflowH((string)$item['current_stock']) ?>"
                                        data-stock-display="<?= workflowH((string)$item['stock_display']) ?>">
                                    <?= workflowH((string)$item['item_name']) ?> — <?= workflowH((string)$item['stock_display']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-semibold" for="usageQuantity">Quantity Used <span id="usageUnitLabel"></span></label>
                        <input type="number" class="form-control" name="quantity_used" id="usageQuantity"
                               min="0.0001" step="0.0001" required>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-semibold" for="usageDate">Usage Date</label>
                        <input type="date" class="form-control" name="usage_date" id="usageDate"
                               value="<?= workflowH($today) ?>" max="<?= workflowH($today) ?>" required>
                    </div>
                    <div class="col-12"><div class="request-info" id="usageUnitHelp">Select an item to see its stock and counting unit.</div></div>
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label fw-semibold" for="usagePatient">Patient ID (optional)</label>
                        <input type="number" class="form-control" name="patient_id" id="usagePatient" min="1" step="1" max="2147483647">
                        <div class="form-text">Leave blank for general clinic consumption.</div>
                    </div>
                    <div class="col-lg-9 col-md-8">
                        <label class="form-label fw-semibold" for="usageRemarks">Purpose / Note (optional)</label>
                        <textarea class="form-control" name="usage_remarks" id="usageRemarks" rows="2" maxlength="500"
                                  placeholder="Example: Supplies consumed during wound care."></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="usage_not_previously_recorded" value="1"
                                   id="usageNotPreviouslyRecorded" required>
                            <label class="form-check-label" for="usageNotPreviouslyRecorded">This consumption has not already been recorded in Vaccination, Assessment, or another supply usage entry.</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                        <button class="btn btn-primary" type="submit" id="saveSupplyUsage"><i class="bi bi-check2-circle me-1"></i>Save Usage and Deduct Stock</button>
                        <span class="small text-muted">Daily Inventory will show this under Consumed automatically.</span>
                    </div>
                </form>
            </div>
        </section>

        <section class="content-card">
            <div class="card-head">
                <div>
                    <h2><span class="section-icon"><i class="bi bi-box2-heart-fill"></i></span>Branch Medical Supplies</h2>
                    <p>Usable stock is branch-specific and excludes expired batches.</p>
                </div>
                <div class="quick-actions">
                    <a href="#recordSupplyUsage" class="btn btn-primary"><i class="bi bi-box-arrow-up me-1"></i>Record Supply Usage</a>
                    <a href="Nurse_Vaccination.php" class="btn btn-outline-primary"><i class="bi bi-shield-plus me-1"></i>Vaccination</a>
                    <a href="Nurse_DailyInventory.php" class="btn btn-outline-primary"><i class="bi bi-clipboard-data me-1"></i>Daily Inventory</a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#restockModal"><i class="bi bi-send-plus me-1"></i>Request Restock</button>
                </div>
            </div>

            <form method="get" class="filters">
                <input type="hidden" name="usage_from" value="<?= workflowH($usageFrom) ?>">
                <input type="hidden" name="usage_to" value="<?= workflowH($usageTo) ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-7">
                        <label class="form-label small text-muted">Search</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control border-start-0" name="search" value="<?= workflowH($search) ?>" placeholder="Search item, status, or unit">
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label small text-muted">Stock Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All statuses</option>
                            <option value="normal" <?= $statusFilter==='normal'?'selected':'' ?>>In Stock</option>
                            <option value="low" <?= $statusFilter==='low'?'selected':'' ?>>Low Stock</option>
                            <option value="out" <?= $statusFilter==='out'?'selected':'' ?>>Out of Stock</option>
                            <option value="expiring" <?= $statusFilter==='expiring'?'selected':'' ?>>Expiring Soon</option>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit">Apply Filter</button></div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table inventory-table align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Usable Stock</th>
                            <th>Minimum</th>
                            <th>Batches</th>
                            <th>Nearest Expiry</th>
                            <th>Today's Usage</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$items): ?>
                        <tr><td colspan="8" class="empty"><i class="bi bi-inbox me-1"></i>No medical supplies match the selected filter.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $nearestExpiry = trim((string)($item['nearest_expiry'] ?? ''));
                        $expiryText = 'No active expiry date';
                        if ($nearestExpiry !== '') {
                            $days = (int)floor((strtotime($nearestExpiry) - strtotime($today)) / 86400);
                            $expiryText = date('M d, Y', strtotime($nearestExpiry));
                            if ($days === 0) $expiryText .= ' (Today)';
                            elseif ($days === 1) $expiryText .= ' (Tomorrow)';
                            elseif ($days > 1 && $days <= 30) $expiryText .= ' (' . $days . ' days)';
                        }
                        ?>
                        <tr>
                            <td>
                                <span class="item-name"><?= workflowH((string)$item['item_name']) ?></span>
                                <span class="stock-sub">Base unit: <?= workflowH((string)$item['base_unit_label']) ?><?php if (strcasecmp((string)$item['base_unit_label'], (string)$item['display_unit_label']) !== 0): ?> · Display: <?= workflowH((string)$item['display_unit_label']) ?><?php endif; ?></span>
                            </td>
                            <td>
                                <strong><?= workflowH((string)$item['stock_display']) ?></strong>
                                <?php if ((float)$item['expired_stock'] > 0): ?>
                                    <span class="expired-note"><?= workflowH(inventoryUsageDescription((float)$item['expired_stock'], $item)) ?> expired/not usable</span>
                                <?php endif; ?>
                            </td>
                            <td><?= workflowH((string)$item['minimum_display']) ?></td>
                            <td><?= (int)$item['usable_batches'] ?></td>
                            <td><?= workflowH($expiryText) ?></td>
                            <td><?= workflowH((string)$item['today_usage_display']) ?></td>
                            <td><span class="status-badge <?= workflowH((string)$item['status_key']) ?>"><?= workflowH((string)$item['status_label']) ?></span></td>
                            <td>
                                <?php if ((bool)$item['is_consumable']): ?>
                                    <a href="#recordSupplyUsage" class="btn-icon record-supply-usage"
                                       title="Record supply usage" aria-label="Record supply usage"
                                       data-item-id="<?= (int)$item['item_id'] ?>"><i class="bi bi-box-arrow-up"></i></a>
                                <?php endif; ?>
                                <button type="button" class="btn-icon request-restock"
                                        title="Request restock"
                                        data-bs-toggle="modal" data-bs-target="#restockModal"
                                        data-item-id="<?= (int)$item['item_id'] ?>"
                                        data-item-name="<?= workflowH((string)$item['item_name']) ?>"
                                        data-display-unit="<?= workflowH((string)$item['display_unit_label']) ?>"
                                        data-base-unit="<?= workflowH((string)$item['base_unit_label']) ?>"
                                        data-conversion="<?= workflowH((string)$item['conversion_to_base']) ?>"
                                        data-step="<?= workflowH((string)$item['request_step']) ?>"
                                        data-stock-display="<?= workflowH((string)$item['stock_display']) ?>">
                                    <i class="bi bi-send-plus"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap">
                    <nav aria-label="Medical supplies pagination"><ul class="pagination mb-0">
                        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= workflowH(supplyPageUrl(max(1,$page-1),$search,$statusFilter,$usageFrom,$usageTo)) ?>"><i class="bi bi-chevron-left"></i></a></li>
                        <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                            <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="<?= workflowH(supplyPageUrl($p,$search,$statusFilter,$usageFrom,$usageTo)) ?>"><?= $p ?></a></li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= workflowH(supplyPageUrl(min($totalPages,$page+1),$search,$statusFilter,$usageFrom,$usageTo)) ?>"><i class="bi bi-chevron-right"></i></a></li>
                    </ul></nav>
                </div>
            <?php endif; ?>
            <div class="pagination-info">Showing <?= $totalFiltered ? ($offset+1) : 0 ?>–<?= min($offset+$perPage,$totalFiltered) ?> of <?= $totalFiltered ?> item(s)</div>
        </section>

        <section class="content-card">
            <div class="card-head">
                <div>
                    <h2><span class="section-icon"><i class="bi bi-clock-history"></i></span>Medical Supply Usage History</h2>
                    <p>Read-only usage recorded by vaccination and other approved supply-use workflows.</p>
                </div>
            </div>
            <form method="get" class="filters">
                <input type="hidden" name="search" value="<?= workflowH($search) ?>">
                <input type="hidden" name="status" value="<?= workflowH($statusFilter) ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label small text-muted">From</label><input type="date" class="form-control" name="usage_from" value="<?= workflowH($usageFrom) ?>" max="<?= workflowH($today) ?>"></div>
                    <div class="col-md-4"><label class="form-label small text-muted">To</label><input type="date" class="form-control" name="usage_to" value="<?= workflowH($usageTo) ?>" max="<?= workflowH($today) ?>"></div>
                    <div class="col-md-4 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter Usage</button></div>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table inventory-table align-middle" style="min-width:900px">
                    <thead><tr><th>Date</th><th>Item</th><th>Quantity Used</th><th>Patient Count</th><th>Usage Entries</th></tr></thead>
                    <tbody>
                    <?php if (!$usageRows): ?>
                        <tr><td colspan="5" class="empty"><i class="bi bi-inbox me-1"></i>No medical-supply usage records for the selected period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($usageRows as $usage): ?>
                        <tr>
                            <td class="usage-date"><?= workflowH(date('M d, Y', strtotime((string)$usage['usage_date']))) ?></td>
                            <td class="item-name"><?= workflowH((string)$usage['item_name']) ?></td>
                            <td><?= workflowH(inventoryUsageDescription((float)$usage['quantity_used'], $usage)) ?></td>
                            <td><?= (int)$usage['patient_count'] ?></td>
                            <td><?= (int)$usage['usage_entries'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 pt-0">
                <div class="note-box"><i class="bi bi-shield-check me-1"></i>Usage history includes entries from Supply Usage, Vaccination, and Assessment. Viewing this history does not deduct stock. Patient Count is the sum of patient references on usage entries, not a distinct clinic patient count.</div>
            </div>
        </section>
        <section class="content-card">
            <div class="card-head"><div><h2><span class="section-icon"><i class="bi bi-journal-text"></i></span>Recorded Supply Usage Details</h2>
                <p>The latest 100 manual supply entries within the usage-history dates selected above.</p></div></div>
            <div class="table-responsive">
                <table class="table inventory-table align-middle" style="min-width:900px">
                    <thead><tr><th>Usage Date</th><th>Item</th><th>Quantity Used</th><th>Recorded By</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php if (!$manualMovements): ?>
                        <tr><td colspan="5" class="empty">No manual supply usage entries for the selected dates.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($manualMovements as $movement): ?>
                        <?php $visibleRemarks = preg_replace('/^Supply usage #[a-f0-9]{64} \| /', '', (string)$movement['remarks']); ?>
                        <tr>
                            <td class="usage-date"><?= workflowH(date('M d, Y', strtotime((string)$movement['transaction_date']))) ?></td>
                            <td class="item-name"><?= workflowH((string)$movement['item_name']) ?></td>
                            <td><?= workflowH(inventoryUsageDescription((float)$movement['quantity'], $movement)) ?></td>
                            <td><?= workflowH((string)$movement['username']) ?></td>
                            <td class="small" style="min-width:320px;white-space:normal"><?= workflowH((string)$visibleRemarks) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<!-- RESTOCK REQUEST MODAL -->
<div class="modal fade" id="restockModal" tabindex="-1" aria-labelledby="restockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content" id="restockForm">
            <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
            <input type="hidden" name="action" value="request_restock">
            <div class="modal-header">
                <h5 class="modal-title" id="restockModalLabel"><i class="bi bi-send-plus me-2"></i>Request Restock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Medical Supply</label>
                    <select class="form-select" name="item_id" id="restockItem" required>
                        <option value="">Select an item</option>
                        <?php foreach ($allItems as $item): ?>
                            <option value="<?= (int)$item['item_id'] ?>"
                                    data-item-name="<?= workflowH((string)$item['item_name']) ?>"
                                    data-display-unit="<?= workflowH((string)$item['display_unit_label']) ?>"
                                    data-base-unit="<?= workflowH((string)$item['base_unit_label']) ?>"
                                    data-conversion="<?= workflowH((string)$item['conversion_to_base']) ?>"
                                    data-step="<?= workflowH((string)$item['request_step']) ?>"
                                    data-stock-display="<?= workflowH((string)$item['stock_display']) ?>">
                                <?= workflowH((string)$item['item_name']) ?> — <?= workflowH((string)$item['stock_display']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="request-info mb-3" id="restockInfo">Select an item to view its current branch stock and request unit.</div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Requested Quantity <span id="requestUnitLabel"></span></label>
                    <input type="number" class="form-control" name="requested_quantity" id="requestedQuantity" min="0.0001" step="0.0001" required>
                    <div class="form-text" id="requestConversionHelp"></div>
                </div>
                <div>
                    <label class="form-label fw-semibold">Reason / Note</label>
                    <textarea class="form-control" name="reason" rows="3" maxlength="500" placeholder="Example: Low stock; expected usage is increasing."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send Request</button>
            </div>
        </form>
    </div>
</div>

<!-- LOGOUT CONFIRMATION -->
<div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon"><i class="bi bi-box-arrow-right"></i></div>
                <h2 class="modal-title" id="logoutConfirmModalLabel">Log out of Smart Bite Care?</h2>
            </div>
            <div class="modal-body"><p class="mb-0">Make sure you have saved any unfinished work before leaving your account.</p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-danger d-flex align-items-center justify-content-center"><i class="bi bi-box-arrow-right me-1"></i>Yes, Log Out</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="sources/sidebar.js"></script>
<script>
const restockItem = document.getElementById('restockItem');
const requestedQuantity = document.getElementById('requestedQuantity');
const requestUnitLabel = document.getElementById('requestUnitLabel');
const requestConversionHelp = document.getElementById('requestConversionHelp');
const restockInfo = document.getElementById('restockInfo');

function updateRestockDetails() {
    const option = restockItem?.options[restockItem.selectedIndex];
    if (!option || !option.value) {
        requestUnitLabel.textContent = '';
        requestConversionHelp.textContent = '';
        restockInfo.textContent = 'Select an item to view its current branch stock and request unit.';
        return;
    }

    const displayUnit = option.dataset.displayUnit || 'unit';
    const baseUnit = option.dataset.baseUnit || displayUnit;
    const conversion = Number(option.dataset.conversion || 1);
    const stockDisplay = option.dataset.stockDisplay || '0';
    const itemName = option.dataset.itemName || option.textContent.trim();

    requestUnitLabel.textContent = `(${displayUnit})`;
    requestedQuantity.step = option.dataset.step || '0.0001';
    restockInfo.textContent = `${itemName}. Current usable branch stock: ${stockDisplay}`;

    if (conversion > 1 && displayUnit.toLowerCase() !== baseUnit.toLowerCase()) {
        requestConversionHelp.textContent = `Request is entered in ${displayUnit}. Inventory base unit: ${baseUnit}. 1 ${displayUnit} = ${conversion} ${baseUnit}.`;
    } else {
        requestConversionHelp.textContent = `Request quantity is entered in ${displayUnit}.`;
    }
}

restockItem?.addEventListener('change', updateRestockDetails);

for (const button of document.querySelectorAll('.request-restock')) {
    button.addEventListener('click', () => {
        if (!restockItem) return;
        restockItem.value = button.dataset.itemId || '';
        updateRestockDetails();
        setTimeout(() => requestedQuantity?.focus(), 250);
    });
}

updateRestockDetails();

const usageItem = document.getElementById('usageItem');
const usageQuantity = document.getElementById('usageQuantity');
const usageUnitLabel = document.getElementById('usageUnitLabel');
const usageUnitHelp = document.getElementById('usageUnitHelp');

function updateUsageDetails() {
    const option = usageItem?.options[usageItem.selectedIndex];
    if (!option || !option.value) {
        usageUnitLabel.textContent = '';
        usageUnitHelp.textContent = 'Select an item to see its stock and counting unit.';
        usageQuantity.removeAttribute('max');
        return;
    }
    const baseUnit = option.dataset.baseUnit || 'unit';
    const displayUnit = option.dataset.displayUnit || baseUnit;
    const conversion = Number(option.dataset.conversion || 1);
    const available = Number(option.dataset.stock || 0);
    const step = option.dataset.step || '0.0001';
    usageUnitLabel.textContent = `(${baseUnit})`;
    usageQuantity.step = step;
    usageQuantity.min = step;
    usageQuantity.max = String(available);
    let help = `Usable stock: ${option.dataset.stockDisplay || '0'}. Enter quantity actually used in ${baseUnit}.`;
    if (conversion > 1 && baseUnit.toLowerCase() !== displayUnit.toLowerCase()) {
        help += ` 1 ${displayUnit} = ${conversion} ${baseUnit}.`;
    } else if (/pack|box/i.test(baseUnit)) {
        help += ` This item is currently counted in ${baseUnit}, so individual pieces must be converted using the verified package contents.`;
    }
    usageUnitHelp.textContent = help;
}

usageItem?.addEventListener('change', () => {
    usageQuantity.value = '';
    updateUsageDetails();
});
for (const button of document.querySelectorAll('.record-supply-usage')) {
    button.addEventListener('click', () => {
        usageItem.value = button.dataset.itemId || '';
        usageQuantity.value = '';
        updateUsageDetails();
        setTimeout(() => usageQuantity.focus(), 250);
    });
}
document.getElementById('supplyUsageForm')?.addEventListener('submit', () => {
    const button = document.getElementById('saveSupplyUsage');
    if (button) {
        button.disabled = true;
        button.textContent = 'Saving usage...';
    }
});
updateUsageDetails();
</script>
</body>
</html>
