<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_helpers.php';
require_once __DIR__ . '/inventory_unit_helpers.php';
require_once __DIR__ . '/stock_quantity_helpers.php';
require_once __DIR__ . '/inventory_officer_activity_helper.php';
require_once __DIR__ . '/inventory_stock_operations.php';
require_once __DIR__ . '/return_management_helper.php';

/*
 * INVENTORY APPROVAL CONTROLS (checks and balances: one person requests, the
 * Branch Admin of the same branch approves). One table, inventory_approval_requests
 * (sources/migrate_inventory_approvals.php), for every request type:
 *
 *   stock_in          a delivery (new batch or added quantity); added only on approval
 *   stock_adjustment  Inventory Officer counted a batch; stock changes only on approval
 *   stock_out         Stock Out / pull-out / damaged, contaminated or lost stock
 *   expired_disposal  dispose and archive an expired batch
 *   restock           Nurse or Inventory Officer asks for stock (or a reorder
 *                     from Supply Forecasting, created already Approved by the
 *                     Branch Admin); fulfilled by a linked Stock In
 *   return_transfer   send stock to another branch (In Transit only once approved)
 *   item_settings     minimum stock / include in forecasting
 *   item_delete       delete an inventory item
 *
 * A Pending request never changes stock, usage, Daily Inventory or training data.
 * Approval applies the change once, through the existing stock functions
 * (sources/inventory_stock_operations.php, return_management_helper.php),
 * inside the same transaction as the status change, with the request row
 * locked FOR UPDATE: a second approval (double click) finds it already decided.
 * Patient usage (Assessment, Vaccination, Medical Supplies) and the Nurse's
 * wastage in the daily closing stay immediate; the closing is reviewed in
 * Closing Review instead.
 */

const INVENTORY_APPROVAL_TYPES = [
    'stock_in' => 'Stock in / delivery',
    'stock_adjustment' => 'Stock adjustment',
    'stock_out' => 'Stock out / pull-out',
    'expired_disposal' => 'Expired stock disposal',
    'restock' => 'Restock request',
    'return_transfer' => 'Return / transfer to another branch',
    'item_settings' => 'Item setting change',
    'item_delete' => 'Item deletion',
];

/** Stock Out reasons the Inventory Officer can request. */
/*
 * Inventory Officer Stock Out reasons. "Dispensed to Patient" is NOT one of
 * them: giving stock to a patient is usage, recorded immediately by the Nurse
 * in Assessment, Vaccination or Medical Supplies (counted once as Consumed).
 * A Stock Out is a pull-out, so a dispensed vial recorded here would show as
 * Pull-out after a delay. Old transactions keep their remarks text and still
 * display; an old pending request with that reason cannot be approved.
 */
const INVENTORY_STOCK_OUT_REASONS = ['Damaged', 'Contaminated', 'Expired', 'Lost / Wastage', 'Pull-out', 'Other'];
const INVENTORY_PATIENT_USAGE_REASON = 'Dispensed to Patient';

function inventoryApprovalTypeLabel(string $type): string
{
    return INVENTORY_APPROVAL_TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** True once sources/migrate_inventory_approvals.php has been applied. */
function inventoryApprovalReady(mysqli $conn): bool
{
    return workflowColumnExists($conn, 'inventory_approval_requests', 'request_id')
        && workflowColumnExists($conn, 'stock_transactions', 'approval_request_id');
}

function inventoryApprovalAssertReady(mysqli $conn): void
{
    if (!inventoryApprovalReady($conn)) {
        throw new RuntimeException('Inventory approvals need the database update. Ask the administrator to run: php sources/migrate_inventory_approvals.php --apply');
    }
}

/** Active user with branch and role, or null. */
function inventoryApprovalUser(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.role_id, u.branch_id, u.status, COALESCE(b.branch_name, u.branch_id) AS branch_name
         FROM users u LEFT JOIN branches b ON b.branch_id = u.branch_id
         WHERE u.user_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

/** Notify one user (deduplicated by source key when the notifications table has one). */
function inventoryApprovalNotifyUser(mysqli $conn, int $userId, string $title, string $message, string $type, string $sourceKey): void
{
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, notification_type, source_key, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, 0, NOW())
         ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message), is_read = 0, created_at = NOW()"
    );
    $stmt->bind_param('issss', $userId, $title, $message, $type, $sourceKey);
    if (!$stmt->execute()) throw new RuntimeException('Unable to save the notification.');
    $stmt->close();
}

/** Notify every active user of a role in the branch. */
function inventoryApprovalNotifyRole(mysqli $conn, string $branchId, int $roleId, string $title, string $message, string $type, string $sourceKey): void
{
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE branch_id = ? AND role_id = ? AND status = 'Active'");
    $stmt->bind_param('si', $branchId, $roleId);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id'));
    $stmt->close();
    foreach ($ids as $id) inventoryApprovalNotifyUser($conn, $id, $title, $message, $type, $sourceKey);
}

/** Unit fields of an item for quantity text ("12 vials + 3 sites"). */
function inventoryApprovalItem(mysqli $conn, int $itemId): array
{
    return inventoryOpsItem($conn, $itemId);
}

/** A stored base quantity in the clinic's counting format; '' when not applicable. */
function inventoryApprovalQuantityText($quantity, array $row): string
{
    if ($quantity === null || $quantity === '') return '';
    return inventoryQuantityText((float)$quantity, $row);
}

/**
 * Create a request. $data: type, branch_id, requested_by, source, and as needed
 * item_id, stock_id, batch, item_name, quantity (base, decimal string), unit,
 * reason, remarks, payload (array), open_key (prevents a second OPEN request
 * for the same thing), status ('Pending', or 'Approved' for a Branch Admin's own
 * reorder) with decided_by / decision_remarks / approved_quantity.
 * Caller holds the transaction. Writes the audit entry and notifies the Branch
 * Admins of a Pending request. Returns the request id.
 */
function inventoryApprovalCreate(mysqli $conn, array $data): int
{
    inventoryApprovalAssertReady($conn);
    $type = (string)$data['type'];
    if (!isset(INVENTORY_APPROVAL_TYPES[$type])) throw new RuntimeException('Unknown request type.');
    $status = (string)($data['status'] ?? 'Pending');
    $branchId = (string)$data['branch_id'];
    $requestedBy = (int)$data['requested_by'];
    $itemId = isset($data['item_id']) ? (int)$data['item_id'] : null;
    $stockId = isset($data['stock_id']) ? (int)$data['stock_id'] : null;
    $batch = isset($data['batch']) ? (string)$data['batch'] : null;
    $itemName = isset($data['item_name']) ? (string)$data['item_name'] : null;
    $quantity = isset($data['quantity']) ? (string)$data['quantity'] : null;
    $approvedQuantity = isset($data['approved_quantity']) ? (string)$data['approved_quantity'] : null;
    $unit = isset($data['unit']) ? (string)$data['unit'] : null;
    $reason = isset($data['reason']) ? mb_substr((string)$data['reason'], 0, 255) : null;
    $remarks = isset($data['remarks']) ? (string)$data['remarks'] : null;
    $payload = json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $source = (string)($data['source'] ?? '');
    $openKey = isset($data['open_key']) ? (string)$data['open_key'] : null;
    $decidedBy = isset($data['decided_by']) ? (int)$data['decided_by'] : null;
    $decisionRemarks = isset($data['decision_remarks']) ? (string)$data['decision_remarks'] : null;

    if ($openKey !== null) {
        $existing = $conn->prepare("SELECT request_id, status FROM inventory_approval_requests WHERE open_key = ? LIMIT 1 FOR UPDATE");
        $existing->bind_param('s', $openKey);
        $existing->execute();
        $open = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($open) {
            throw new RuntimeException('There is already an open request for this (request #' . (int)$open['request_id']
                . ', ' . $open['status'] . '). Wait for it to be decided or fulfilled, or cancel it first.');
        }
    }
    $stmt = $conn->prepare(
        "INSERT INTO inventory_approval_requests
         (request_type, branch_id, item_id, stock_id, batch_snapshot, item_name_snapshot, quantity, approved_quantity,
          unit_snapshot, reason, remarks, payload, status, source, requested_by, requested_at,
          decided_by, decided_at, decision_remarks, open_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, IF(? IS NULL, NULL, NOW()), ?, ?)"
    );
    $stmt->bind_param('ssiissssssssssiiiss', $type, $branchId, $itemId, $stockId, $batch, $itemName, $quantity,
        $approvedQuantity, $unit, $reason, $remarks, $payload, $status, $source, $requestedBy, $decidedBy, $decidedBy,
        $decisionRemarks, $openKey);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save the request: ' . $error);
    }
    $requestId = (int)$conn->insert_id;
    $stmt->close();

    $requester = inventoryApprovalUser($conn, $requestedBy);
    $summary = inventoryApprovalSummary($conn, $requestId);
    workflowAudit($conn, $requestedBy, $branchId,
        ($status === 'Pending' ? 'Requested approval' : 'Created ' . strtolower($status) . ' request')
            . ' #' . $requestId . ': ' . $summary
            . ($reason !== null && $reason !== '' ? ' | Reason: ' . $reason : '')
            . ($remarks !== null && $remarks !== '' ? ' | Remarks: ' . $remarks : ''),
        'Inventory Approvals');
    if ($status === 'Pending') {
        if (!inventoryOfficerNotifyBranchAdmins($conn, $branchId,
            'Approval needed: ' . inventoryApprovalTypeLabel($type),
            ($requester['username'] ?? 'A user') . ' requested ' . $summary
                . ($reason ? '. Reason: ' . $reason : '') . '. Open Pending Approvals to approve or reject it (request #' . $requestId . ').',
            'approval_request', 'approval_request:' . $requestId)) {
            throw new RuntimeException('Unable to notify the Branch Admin about this request.');
        }
    }
    return $requestId;
}

/** One request with item units, requester and decider names. */
function inventoryApprovalLoad(mysqli $conn, int $requestId, bool $lock = false): ?array
{
    if ($lock) {
        // Lock the request row itself; the joined row is read separately.
        $stmt = $conn->prepare('SELECT request_id FROM inventory_approval_requests WHERE request_id = ? FOR UPDATE');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) return null;
    }
    $rows = inventoryApprovalList($conn, ['request_id' => $requestId, 'limit' => 1]);
    return $rows[0] ?? null;
}

/** WHERE parts of a request list or count (see inventoryApprovalList). */
function inventoryApprovalListWhere(array $filters): array
{
    $where = [];
    $types = '';
    $params = [];
    if (isset($filters['request_id'])) { $where[] = 'r.request_id = ?'; $types .= 'i'; $params[] = (int)$filters['request_id']; }
    if (isset($filters['branch_id'])) { $where[] = 'r.branch_id = ?'; $types .= 's'; $params[] = (string)$filters['branch_id']; }
    if (isset($filters['requested_by'])) { $where[] = 'r.requested_by = ?'; $types .= 'i'; $params[] = (int)$filters['requested_by']; }
    if (isset($filters['item_id'])) { $where[] = 'r.item_id = ?'; $types .= 'i'; $params[] = (int)$filters['item_id']; }
    if (!empty($filters['requested_from'])) { $where[] = 'r.requested_at >= ?'; $types .= 's'; $params[] = $filters['requested_from'] . ' 00:00:00'; }
    if (!empty($filters['requested_to'])) { $where[] = 'r.requested_at < DATE_ADD(?, INTERVAL 1 DAY)'; $types .= 's'; $params[] = (string)$filters['requested_to']; }
    foreach (['statuses' => 'r.status', 'types' => 'r.request_type'] as $key => $column) {
        if (!empty($filters[$key])) {
            $where[] = $column . ' IN (' . implode(',', array_fill(0, count($filters[$key]), '?')) . ')';
            $types .= str_repeat('s', count($filters[$key]));
            foreach ($filters[$key] as $value) $params[] = (string)$value;
        }
    }
    return [$where, $types, $params];
}

/** Number of requests matching the filters (for badges and pagination). */
function inventoryApprovalCount(mysqli $conn, array $filters = []): int
{
    if (!inventoryApprovalReady($conn)) return 0;
    [$where, $types, $params] = inventoryApprovalListWhere($filters);
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM inventory_approval_requests r' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/** Number of matching requests per request_type. */
function inventoryApprovalCountByType(mysqli $conn, array $filters = []): array
{
    if (!inventoryApprovalReady($conn)) return [];
    [$where, $types, $params] = inventoryApprovalListWhere($filters);
    $stmt = $conn->prepare('SELECT r.request_type, COUNT(*) AS total FROM inventory_approval_requests r'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY r.request_type');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $counts = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'total', 'request_type'));
    $stmt->close();
    return $counts;
}

/**
 * Requests with display fields matching $filters (request_id, branch_id,
 * requested_by, item_id, statuses, types, requested_from/to), newest first,
 * limit 200 by default; 'order' => 'type'
 * keeps each request type together (Pending Approvals groups); 'limit' and
 * 'offset' give one page.
 */
function inventoryApprovalList(mysqli $conn, array $filters = []): array
{
    if (!inventoryApprovalReady($conn)) return [];
    [$where, $types, $params] = inventoryApprovalListWhere($filters);
    $limit = max(1, min(20000, (int)($filters['limit'] ?? 200)));
    $offset = max(0, (int)($filters['offset'] ?? 0));
    $order = ($filters['order'] ?? '') === 'type'
        ? "FIELD(r.request_type, '" . implode("','", array_map(static fn($t) => str_replace("'", '', (string)$t), array_keys(INVENTORY_APPROVAL_TYPES))) . "'), "
        : '';
    $sql = "SELECT r.*, COALESCE(i.item_name, r.item_name_snapshot) AS item_name,
                   COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name, r.unit_snapshot) AS base_unit_label,
                   COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name, r.unit_snapshot) AS display_unit_label,
                   COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base,
                   COALESCE(un.unit_name, r.unit_snapshot) AS unit_name,
                   req.username AS requester_name, req.role_id AS requester_role_id, rr.role_name AS requester_role,
                   dcd.username AS decider_name
            FROM inventory_approval_requests r
            LEFT JOIN inventory_items i ON i.item_id = r.item_id
            LEFT JOIN units un ON un.unit_id = i.unit_id
            LEFT JOIN users req ON req.user_id = r.requested_by
            LEFT JOIN roles rr ON rr.role_id = req.role_id
            LEFT JOIN users dcd ON dcd.user_id = r.decided_by"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY {$order}r.requested_at DESC, r.request_id DESC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    // Branch names are looked up separately: this table can have a different
    // collation from branches on MySQL 8/9, so a text join could fail.
    $branchNames = [];
    if ($rows) {
        $res = $conn->query('SELECT branch_id, branch_name FROM branches');
        while ($b = $res->fetch_assoc()) $branchNames[(string)$b['branch_id']] = (string)$b['branch_name'];
    }
    foreach ($rows as &$row) {
        $row['branch_name'] = $branchNames[(string)$row['branch_id']] ?? (string)$row['branch_id'];
        $row['payload_data'] = json_decode((string)($row['payload'] ?? ''), true) ?: [];
        $row['summary'] = inventoryApprovalDescribe($row);
    }
    unset($row);
    return $rows;
}

/** One-line description of what the request changes, quantities in vials/ampules. */
function inventoryApprovalDescribe(array $row): string
{
    $p = $row['payload_data'] ?? (json_decode((string)($row['payload'] ?? ''), true) ?: []);
    $item = (string)($row['item_name'] ?? $row['item_name_snapshot'] ?? 'item');
    $qty = inventoryApprovalQuantityText($row['quantity'] ?? null, $row);
    $batch = trim((string)($row['batch_snapshot'] ?? ''));
    $batchText = $batch !== '' ? ' (batch ' . $batch . ')' : '';
    switch ((string)$row['request_type']) {
        case 'stock_in':
            return 'receive ' . $qty . ' of ' . $item . $batchText
                . (!empty($p['expiration_date']) ? ', expires ' . $p['expiration_date'] : '')
                . (!empty($p['source_label']) ? ', from ' . $p['source_label'] : '')
                . (!empty($p['restock_request_id']) ? ', for restock request #' . (int)$p['restock_request_id'] : '');
        case 'stock_adjustment':
            return 'adjust ' . $item . $batchText . ' from ' . inventoryApprovalQuantityText($p['system_at_count'] ?? 0, $row)
                . ' to the counted ' . inventoryApprovalQuantityText($p['counted'] ?? 0, $row);
        case 'stock_out':
            return 'stock out ' . $qty . ' of ' . $item . ' (' . ($p['reason'] ?? $row['reason'] ?? '') . ')';
        case 'expired_disposal':
            return 'dispose ' . $qty . ' of expired ' . $item . $batchText
                . (!empty($p['expiration_date']) ? ', expired ' . $p['expiration_date'] : '');
        case 'restock':
            $approved = $row['approved_quantity'] ?? null;
            return 'restock ' . $item . ': ' . $qty
                . ($approved !== null && (string)$approved !== (string)$row['quantity'] ? ' (approved ' . inventoryApprovalQuantityText($approved, $row) . ')' : '');
        case 'return_transfer':
            return 'send ' . ($p['quantity'] ?? '') . ' ' . ($p['unit_name'] ?? '') . ' of ' . ($p['item_name'] ?? $item)
                . ' to ' . ($p['destination_name'] ?? $p['destination_branch_id'] ?? 'another branch');
        case 'item_settings':
            $parts = [];
            $old = $p['old'] ?? [];
            $new = $p['new'] ?? [];
            if (array_key_exists('minimum_stock', $new) && (int)$new['minimum_stock'] !== (int)($old['minimum_stock'] ?? -1)) {
                $parts[] = 'minimum stock ' . inventoryApprovalQuantityText($old['minimum_stock'] ?? 0, $row)
                    . ' → ' . inventoryApprovalQuantityText($new['minimum_stock'], $row);
            }
            if (array_key_exists('is_forecastable', $new) && (int)$new['is_forecastable'] !== (int)($old['is_forecastable'] ?? -1)) {
                $parts[] = ((int)$new['is_forecastable'] ? 'include in' : 'exclude from') . ' forecasting';
            }
            return 'change ' . $item . ': ' . ($parts ? implode(', ', $parts) : 'settings');
        case 'item_delete':
            return 'delete inventory item ' . $item;
    }
    return inventoryApprovalTypeLabel((string)$row['request_type']) . ' for ' . $item;
}

function inventoryApprovalSummary(mysqli $conn, int $requestId): string
{
    $row = inventoryApprovalLoad($conn, $requestId);
    return $row ? $row['summary'] : 'request #' . $requestId;
}

/** Pending requests of a branch (badge counts). */
function inventoryApprovalPendingCount(mysqli $conn, string $branchId): int
{
    if (!inventoryApprovalReady($conn)) return 0;
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM inventory_approval_requests WHERE branch_id = ? AND status = 'Pending'");
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/** Closings of a branch waiting for the Branch Admin's review (submitted, with a variance or wastage). */
function inventoryApprovalClosingsToReview(mysqli $conn, string $branchId): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM daily_inventory_closings d
         WHERE d.branch_id = ? AND d.status = 'Submitted'
           AND (d.variance <> 0 OR EXISTS (SELECT 1 FROM inventory_usage_history h
                WHERE h.branch_id = d.branch_id AND h.item_id = d.item_id AND h.usage_date = d.inventory_date
                  AND h.usage_type = 'Wastage'))"
    );
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/**
 * Branch Admin sidebar entry with the Pending Approvals badge. Every Branch
 * Admin page calls this with the signed-in user.
 */
function inventoryApprovalSidebarLink(mysqli $conn, int $userId, bool $active = false): string
{
    $requests = 0;
    $closings = 0;
    $deliveries = 0;
    try {
        $user = inventoryApprovalUser($conn, $userId);
        if ($user && (int)$user['role_id'] === 2 && $user['branch_id']) {
            $requests = inventoryApprovalPendingCount($conn, (string)$user['branch_id']);
            $closings = inventoryApprovalClosingsToReview($conn, (string)$user['branch_id']);
            $deliveries = array_sum(array_column(inventoryApprovalPendingDeliveries($conn, (string)$user['branch_id']), 'count'));
        }
    } catch (Throwable $e) {
        $requests = $closings = $deliveries = 0;
    }
    $count = $requests + $closings;
    $title = $requests . ' pending request' . ($requests === 1 ? '' : 's')
        . ($deliveries > 0 ? ' (' . $deliveries . ' ' . ($deliveries === 1 ? 'delivery' : 'deliveries') . ' waiting to be received)' : '')
        . ', ' . $closings . ' closing' . ($closings === 1 ? '' : 's') . ' to review';
    // Pending deliveries (stock-in requests) also get their own line under the
    // link, so stock waiting to be received is never missed.
    return '<li><a href="BranchAdmin_Approvals.php" class="notification-link' . ($active ? ' active' : '') . '"'
        . ($active ? ' aria-current="page"' : '') . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-patch-check-fill"></i><span>Pending Approvals'
        . ($deliveries > 0 ? '<small class="d-block" style="font-size:11px;color:#ffd54f;font-weight:600;line-height:1.2"><i class="bi bi-truck me-1" style="font-size:11px"></i>'
            . $deliveries . ' ' . ($deliveries === 1 ? 'delivery' : 'deliveries') . ' to receive</small>' : '')
        . '</span>'
        . ($count > 0 ? '<span class="notification-badge" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $count . '</span>' : '')
        . '</a></li>';
}

/** CSS class of a status badge. */
function inventoryApprovalStatusClass(string $status): string
{
    return match ($status) {
        'Pending' => 'text-bg-warning',
        'Approved' => 'text-bg-primary',
        'Fulfilled' => 'text-bg-success',
        'Rejected' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

/** Requester cancels their own request while it is still Pending. */
function inventoryApprovalCancel(mysqli $conn, int $requestId, int $userId): string
{
    inventoryApprovalAssertReady($conn);
    $conn->begin_transaction();
    try {
        $row = inventoryApprovalLoad($conn, $requestId, true);
        if (!$row || (int)$row['requested_by'] !== $userId) throw new RuntimeException('Request not found among your requests.');
        if ($row['status'] !== 'Pending') throw new RuntimeException('Only a Pending request can be cancelled. This one is ' . $row['status'] . '.');
        $stmt = $conn->prepare("UPDATE inventory_approval_requests SET status = 'Cancelled', cancelled_at = NOW(), open_key = NULL
            WHERE request_id = ? AND status = 'Pending'");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('The request changed. Refresh and try again.');
        $stmt->close();
        workflowAudit($conn, $userId, (string)$row['branch_id'], 'Cancelled request #' . $requestId . ': ' . $row['summary'], 'Inventory Approvals');
        $conn->commit();
        return 'Request #' . $requestId . ' was cancelled. Nothing was changed.';
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * The approver must be an ACTIVE Branch Admin of the request's branch, and
 * never the requester. Throws otherwise.
 */
function inventoryApprovalAssertApprover(mysqli $conn, array $request, int $approverId): array
{
    $approver = inventoryApprovalUser($conn, $approverId);
    if (!$approver || (int)$approver['role_id'] !== 2 || $approver['status'] !== 'Active') {
        throw new RuntimeException('Only an active Branch Admin can approve or reject requests.');
    }
    if ((string)$approver['branch_id'] !== (string)$request['branch_id']) {
        throw new RuntimeException('This request belongs to another branch. Only that branch\'s Branch Admin can decide it.');
    }
    if ((int)$request['requested_by'] === $approverId) {
        throw new RuntimeException('You cannot approve or reject your own request.');
    }
    return $approver;
}

/**
 * Approve or reject a Pending request. Approval applies the change exactly
 * once (row lock + status check), sets the status, notifies the requester and
 * writes the audit entry with the before and after values. Rejection needs a
 * remark and changes nothing in stock. $approvedQuantity (restock only) is the
 * quantity in the item's counting unit (vials/ampules); null keeps the request.
 */
function inventoryApprovalDecide(
    mysqli $conn,
    int $requestId,
    int $approverId,
    string $decision,
    string $remarks,
    ?string $approvedQuantity = null
): string {
    inventoryApprovalAssertReady($conn);
    $remarks = trim($remarks);
    if (mb_strlen($remarks) > 1000) throw new RuntimeException('Keep the decision remark within 1,000 characters.');
    if (!in_array($decision, ['approve', 'reject'], true)) throw new RuntimeException('Choose Approve or Reject.');
    if ($decision === 'reject' && $remarks === '') throw new RuntimeException('Enter a remark explaining why the request is rejected.');

    $conn->begin_transaction();
    try {
        $request = inventoryApprovalLoad($conn, $requestId, true);
        if (!$request) throw new RuntimeException('Request #' . $requestId . ' was not found.');
        $approver = inventoryApprovalAssertApprover($conn, $request, $approverId);
        if ($request['status'] !== 'Pending') {
            throw new RuntimeException('Request #' . $requestId . ' is already ' . $request['status']
                . ($request['decider_name'] ? ' by ' . $request['decider_name'] : '')
                . ($request['decided_at'] ? ' on ' . $request['decided_at'] : '') . '. Nothing was changed.');
        }
        $branchId = (string)$request['branch_id'];
        $requesterId = (int)$request['requested_by'];

        if ($decision === 'reject') {
            $stmt = $conn->prepare("UPDATE inventory_approval_requests
                SET status = 'Rejected', decided_by = ?, decided_at = NOW(), decision_remarks = ?, open_key = NULL
                WHERE request_id = ? AND status = 'Pending'");
            $stmt->bind_param('isi', $approverId, $remarks, $requestId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('The request changed. Refresh and try again.');
            $stmt->close();
            workflowAudit($conn, $approverId, $branchId, 'Rejected request #' . $requestId . ' from '
                . ($request['requester_name'] ?? 'user') . ': ' . $request['summary'] . ' | Remark: ' . $remarks
                . ' | No stock or setting was changed', 'Inventory Approvals');
            inventoryApprovalNotifyUser($conn, $requesterId, 'Request rejected: ' . inventoryApprovalTypeLabel($request['request_type']),
                'Your request #' . $requestId . ' (' . $request['summary'] . ') was rejected by ' . $approver['username']
                . '. Remark: ' . $remarks . '. Nothing was changed.',
                'approval_decision', 'approval_decision:' . $requestId);
            $conn->commit();
            return 'Request #' . $requestId . ' was rejected. Nothing was changed.';
        }

        $requester = inventoryApprovalUser($conn, $requesterId);
        if (!$requester) throw new RuntimeException('The requester account no longer exists.');
        $actor = ['user_id' => $requesterId, 'branch_id' => $branchId,
            'branch_name' => (string)$requester['branch_name'], 'username' => (string)$requester['username']];
        $result = inventoryApprovalApply($conn, $request, $actor, $approver, $approvedQuantity);

        $status = 'Approved';
        $keepOpen = $request['request_type'] === 'restock';
        $finalApproved = $result['approved_quantity'] ?? null;
        $transactionId = $result['transaction_id'] ?? null;
        $reference = $result['reference'] ?? null;
        // The before/after values are kept with the request for the reports.
        $payloadData = $request['payload_data'];
        $payloadData['result'] = ['before' => $result['before'] ?? null, 'after' => $result['after'] ?? null];
        $payloadJson = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare("UPDATE inventory_approval_requests
            SET status = ?, decided_by = ?, decided_at = NOW(), decision_remarks = NULLIF(?, ''),
                approved_quantity = COALESCE(?, approved_quantity), result_transaction_id = ?, result_reference = ?,
                open_key = IF(?, open_key, NULL), payload = ?
            WHERE request_id = ? AND status = 'Pending'");
        $keep = $keepOpen ? 1 : 0;
        $stmt->bind_param('sissisisi', $status, $approverId, $remarks, $finalApproved, $transactionId, $reference, $keep, $payloadJson, $requestId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('The request changed. Refresh and try again.');
        $stmt->close();

        workflowAudit($conn, $approverId, $branchId, 'Approved request #' . $requestId . ' from '
            . ($request['requester_name'] ?? 'user') . ': ' . $request['summary']
            . ' | Before: ' . ($result['before'] ?? '-') . ' | After: ' . ($result['after'] ?? '-')
            . ($transactionId ? ' | Stock transaction #' . $transactionId : '')
            . ($reference ? ' | ' . $reference : '')
            . ($remarks !== '' ? ' | Remark: ' . $remarks : ''), 'Inventory Approvals');
        inventoryApprovalNotifyUser($conn, $requesterId, 'Request approved: ' . inventoryApprovalTypeLabel($request['request_type']),
            'Your request #' . $requestId . ' (' . $request['summary'] . ') was approved by ' . $approver['username'] . '. '
            . ($result['message'] ?? '') . ($remarks !== '' ? ' Remark: ' . $remarks : ''),
            'approval_decision', 'approval_decision:' . $requestId);
        if ($request['request_type'] === 'restock') {
            inventoryApprovalNotifyRole($conn, $branchId, 5, 'Restock approved: ' . $request['item_name'],
                'Restock request #' . $requestId . ' for ' . $request['item_name'] . ' was approved by ' . $approver['username']
                . ' for ' . inventoryApprovalQuantityText($finalApproved ?? $request['quantity'], $request)
                . '. When the delivery arrives, record Stock In and link it to request #' . $requestId . '.',
                'restock_request', 'restock_approved:' . $requestId);
        }
        $conn->commit();
        return 'Request #' . $requestId . ' was approved. ' . ($result['message'] ?? '');
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Apply an approved request through the existing stock functions. Runs inside
 * inventoryApprovalDecide's transaction. Returns transaction_id, reference,
 * before, after (text), message and, for restock, approved_quantity.
 */
function inventoryApprovalApply(mysqli $conn, array $request, array $actor, array $approver, ?string $approvedQuantity): array
{
    $p = $request['payload_data'];
    $now = date('Y-m-d H:i:s');
    $requestId = (int)$request['request_id'];
    switch ($request['request_type']) {
        case 'stock_in':
            // Validated again: the batch may have expired or been archived since the request.
            $origin = stockReceiptSourceFromForm($conn, (string)$actor['branch_id'], [
                'stock_source_type' => (string)($p['stock_source_type'] ?? ''),
                'stock_source_branch_id' => (string)($p['stock_source_branch_id'] ?? ''),
                'stock_source_name' => (string)($p['stock_source_name'] ?? ''),
            ]);
            $restockId = !empty($p['restock_request_id']) ? (int)$p['restock_request_id'] : null;
            $quantityScaled = stockQuantityParse((string)$request['quantity']);
            $done = inventoryStockInApply($conn, $actor, (int)$request['item_id'], $quantityScaled,
                $p['batch_lot_no'] ?? null, $p['manufacturing_date'] ?? null, $p['expiration_date'] ?? null, $origin,
                trim((string)($p['remarks'] ?? '') . ' | Received on ' . ($p['received_date'] ?? '') . ' | Approved request #' . $requestId
                    . ($restockId ? ' | Restock request #' . $restockId : ''), ' |'),
                $now, $requestId);
            $restockText = '';
            if ($restockId !== null) {
                $restock = inventoryApprovalLoad($conn, $restockId, true);
                if ($restock && $restock['status'] === 'Approved' && (int)$restock['item_id'] === (int)$request['item_id']
                    && (string)$restock['branch_id'] === (string)$request['branch_id']) {
                    $fulfil = inventoryApprovalFulfil($conn, $restockId, (string)$request['branch_id'], (int)$request['item_id'],
                        $quantityScaled, (int)$done['transaction_id'], (int)$approver['user_id']);
                    $restockText = ' Restock request #' . $restockId . ($fulfil['complete']
                        ? ' is now Fulfilled (' . $fulfil['fulfilled_text'] . ' received).'
                        : ' stays Approved: ' . $fulfil['fulfilled_text'] . ' of ' . $fulfil['approved_text'] . ' received, '
                            . $fulfil['remaining_text'] . ' remaining.');
                } else {
                    // The stock physically arrived; it is received, without the link.
                    $restockText = ' Restock request #' . $restockId . ' is ' . ($restock['status'] ?? 'missing')
                        . ', so this delivery was not counted against it.';
                }
            }
            return ['transaction_id' => $done['transaction_id'],
                'reference' => $restockId ? 'Restock request #' . $restockId : null,
                'before' => 'usable ' . inventoryQuantityText((float)stockQuantityDecimal($done['before']), $done['item']),
                'after' => 'usable ' . inventoryQuantityText((float)stockQuantityDecimal($done['after']), $done['item']),
                'message' => 'Stock In recorded (transaction #' . $done['transaction_id'] . '); it appears as Delivery in today\'s Daily Inventory.'
                    . $restockText];
        case 'stock_adjustment':
            $done = inventoryStockAdjustmentApply($conn, $actor, (int)$request['item_id'], (int)$request['stock_id'],
                stockQuantityParse((string)$p['counted'], true), stockQuantityParse((string)$p['system_at_count'], true),
                (string)$request['reason'], trim((string)($request['remarks'] ?? '') . ' | Counted on ' . ($p['count_date'] ?? '') . ' | Approved request #' . $requestId, ' |'),
                $now, $requestId);
            return ['transaction_id' => $done['transaction_id'],
                'before' => inventoryQuantityText((float)stockQuantityDecimal($done['before']), $done['item']),
                'after' => inventoryQuantityText((float)stockQuantityDecimal($done['after']), $done['item']),
                'message' => 'Stock of ' . $done['item']['item_name'] . ' is now '
                    . inventoryQuantityText((float)stockQuantityDecimal($done['after']), $done['item']) . ' (transaction #' . $done['transaction_id'] . ').'];
        case 'stock_out':
            if (($p['reason'] ?? $request['reason']) === INVENTORY_PATIENT_USAGE_REASON) {
                throw new RuntimeException('"Dispensed to Patient" is patient usage, not a pull-out. Reject this request and ask the Nurse to record the usage in Assessment, Vaccination or Medical Supplies so it counts as Consumed once.');
            }
            $done = inventoryStockOutApply($conn, $actor, (int)$request['item_id'], stockQuantityParse((string)$request['quantity']),
                (string)$p['reason'], trim((string)($request['remarks'] ?? '') . ' | Approved request #' . $requestId, ' |'), $now, $requestId);
            $expired = $p['reason'] === 'Expired';
            return ['transaction_id' => $done['transaction_id'],
                'before' => ($expired ? 'expired ' : 'usable ') . inventoryQuantityText((float)stockQuantityDecimal($done['before']), $done['item']),
                'after' => ($expired ? 'expired ' : 'usable ') . inventoryQuantityText((float)stockQuantityDecimal($done['after']), $done['item']),
                'message' => 'Stock Out recorded (transaction #' . $done['transaction_id'] . '); it appears as Pull-out in today\'s Daily Inventory.'];
        case 'expired_disposal':
            $done = inventoryExpiredDisposalApply($conn, $actor, (int)$request['stock_id'], $now, $requestId);
            $item = inventoryApprovalItem($conn, (int)$done['item_id']);
            return ['transaction_id' => $done['transaction_id'],
                'before' => 'batch ' . inventoryQuantityText((float)stockQuantityDecimal($done['before']), $item),
                'after' => 'batch disposed and archived',
                'message' => 'Expired batch disposed and archived (transaction #' . $done['transaction_id'] . '); it appears as Pull-out in today\'s Daily Inventory.'];
        case 'return_transfer':
            $prepared = returnManagementPrepareShipment($conn, (string)$actor['branch_id'], $p['fields'] ?? []);
            $return = returnManagementInsertShipment($conn, $actor, $prepared);
            return ['reference' => 'Return ' . $return['return_number'],
                'before' => 'not sent', 'after' => 'In Transit to ' . $prepared['destination_name'],
                'message' => 'Return ' . $return['return_number'] . ' is now In Transit to ' . $prepared['destination_name'] . '.'];
        case 'item_settings':
            $stmt = $conn->prepare('SELECT item_name, minimum_stock, is_forecastable FROM inventory_items WHERE item_id = ? FOR UPDATE');
            $itemId = (int)$request['item_id'];
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('The inventory item no longer exists.');
            $new = $p['new'] ?? [];
            $minimum = array_key_exists('minimum_stock', $new) ? (int)$new['minimum_stock'] : (int)$current['minimum_stock'];
            $forecastable = array_key_exists('is_forecastable', $new) ? (int)$new['is_forecastable'] : (int)$current['is_forecastable'];
            if ($minimum < 0) throw new RuntimeException('Minimum stock must be zero or greater.');
            $stmt = $conn->prepare('UPDATE inventory_items SET minimum_stock = ?, is_forecastable = ? WHERE item_id = ?');
            $stmt->bind_param('iii', $minimum, $forecastable, $itemId);
            if (!$stmt->execute()) throw new RuntimeException('Unable to update the item settings.');
            $stmt->close();
            $item = inventoryApprovalItem($conn, $itemId);
            $text = static fn(int $min, int $fc): string => 'minimum ' . inventoryQuantityText((float)$min, $item) . ', '
                . ($fc ? 'in forecasting' : 'not in forecasting');
            return ['reference' => 'Item ' . $current['item_name'],
                'before' => $text((int)$current['minimum_stock'], (int)$current['is_forecastable']),
                'after' => $text($minimum, $forecastable),
                'message' => 'The settings of ' . $current['item_name'] . ' were updated.'];
        case 'item_delete':
            $itemId = (int)$request['item_id'];
            $stmt = $conn->prepare('SELECT item_name FROM inventory_items WHERE item_id = ? FOR UPDATE');
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('The inventory item no longer exists.');
            foreach (['inventory_stocks' => 'stock records', 'stock_transactions' => 'stock transaction records'] as $table => $label) {
                $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM $table WHERE item_id = ?");
                $stmt->bind_param('i', $itemId);
                $stmt->execute();
                $count = (int)$stmt->get_result()->fetch_assoc()['total'];
                $stmt->close();
                if ($count > 0) throw new RuntimeException('This item cannot be deleted because it has existing ' . $label . '.');
            }
            try {
                $stmt = $conn->prepare('DELETE FROM inventory_items WHERE item_id = ?');
                $stmt->bind_param('i', $itemId);
                $stmt->execute();
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                throw new RuntimeException('This item is still used by other records (for example usage or forecast history), so it cannot be deleted.');
            }
            return ['reference' => 'Item ' . $current['item_name'], 'before' => 'item exists', 'after' => 'item deleted',
                'message' => 'Inventory item ' . $current['item_name'] . ' was deleted.'];
        case 'restock':
            $approvedBase = (string)$request['quantity'];
            if ($approvedQuantity !== null && trim($approvedQuantity) !== '') {
                $displayScaled = stockQuantityParse(trim($approvedQuantity));
                $approvedBase = stockQuantityDecimal(stockQuantityParse(
                    number_format(inventoryDisplayToBase((float)stockQuantityDecimal($displayScaled), $request), 4, '.', '')));
            }
            if (stockQuantityParse($approvedBase, true) <= 0) throw new RuntimeException('The approved quantity must be greater than zero.');
            return ['approved_quantity' => $approvedBase,
                'before' => 'requested ' . inventoryApprovalQuantityText($request['quantity'], $request),
                'after' => 'approved ' . inventoryApprovalQuantityText($approvedBase, $request),
                'message' => 'Approved quantity: ' . inventoryApprovalQuantityText($approvedBase, $request)
                    . '. The Inventory Officer will link the Stock In to this request when the delivery arrives.'];
    }
    throw new RuntimeException('Unknown request type.');
}

/**
 * Approved restock requests of a branch that a Stock In can be linked to
 * (optionally for one item), with the remaining quantity.
 */
function inventoryApprovalOpenRestocks(mysqli $conn, string $branchId, ?int $itemId = null): array
{
    $filters = ['branch_id' => $branchId, 'statuses' => ['Approved'], 'types' => ['restock']];
    if ($itemId !== null) $filters['item_id'] = $itemId;
    $rows = inventoryApprovalList($conn, $filters);
    foreach ($rows as &$row) {
        $approved = stockQuantityParse((string)($row['approved_quantity'] ?? $row['quantity']), true);
        $fulfilled = stockQuantityParse((string)$row['fulfilled_quantity'], true);
        $row['remaining_scaled'] = max(0, $approved - $fulfilled);
        $row['remaining_text'] = inventoryQuantityText((float)stockQuantityDecimal($row['remaining_scaled']), $row);
        $row['pending_delivery_scaled'] = 0;
    }
    unset($row);
    // Pending Stock In requests already linked to each restock (not received yet).
    $pendingIn = inventoryApprovalList($conn, ['branch_id' => $branchId, 'statuses' => ['Pending'], 'types' => ['stock_in'], 'limit' => 500]);
    foreach ($rows as &$row) {
        foreach ($pendingIn as $in) {
            if ((int)($in['payload_data']['restock_request_id'] ?? 0) === (int)$row['request_id']) {
                $row['pending_delivery_scaled'] += stockQuantityParse((string)$in['quantity'], true);
            }
        }
        $row['pending_delivery_text'] = $row['pending_delivery_scaled'] > 0
            ? inventoryQuantityText((float)stockQuantityDecimal($row['pending_delivery_scaled']), $row) : '';
    }
    unset($row);
    return $rows;
}

/**
 * Link a Stock In (already inserted with approval_request_id) to an approved
 * restock request: add the received quantity; a complete delivery makes the
 * request Fulfilled, a partial one keeps it Approved with the remaining
 * quantity. Caller holds the Stock In transaction. Returns the new state.
 */
function inventoryApprovalFulfil(mysqli $conn, int $requestId, string $branchId, int $itemId, int $receivedScaled,
    int $transactionId, int $userId): array
{
    $request = inventoryApprovalLoad($conn, $requestId, true);
    if (!$request || $request['request_type'] !== 'restock' || (string)$request['branch_id'] !== $branchId) {
        throw new RuntimeException('Select an approved restock request of this branch.');
    }
    if ($request['status'] !== 'Approved') {
        throw new RuntimeException('Restock request #' . $requestId . ' is ' . $request['status'] . ' and cannot take a delivery.');
    }
    if ((int)$request['item_id'] !== $itemId) {
        throw new RuntimeException('Restock request #' . $requestId . ' is for ' . $request['item_name'] . ', not the item received.');
    }
    $approved = stockQuantityParse((string)($request['approved_quantity'] ?? $request['quantity']), true);
    $fulfilled = stockQuantityParse((string)$request['fulfilled_quantity'], true) + $receivedScaled;
    $complete = $fulfilled >= $approved;
    $fulfilledDecimal = stockQuantityDecimal($fulfilled);
    $stmt = $conn->prepare("UPDATE inventory_approval_requests
        SET fulfilled_quantity = ?, status = IF(?, 'Fulfilled', status), fulfilled_at = IF(?, NOW(), fulfilled_at),
            open_key = IF(?, NULL, open_key), result_transaction_id = COALESCE(result_transaction_id, ?)
        WHERE request_id = ? AND status = 'Approved'");
    $c = $complete ? 1 : 0;
    $stmt->bind_param('siiiii', $fulfilledDecimal, $c, $c, $c, $transactionId, $requestId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new RuntimeException('The restock request changed. Refresh and try again.');
    $stmt->close();
    $remaining = max(0, $approved - $fulfilled);
    $text = static fn(int $v): string => inventoryQuantityText((float)stockQuantityDecimal($v), $request);
    workflowAudit($conn, $userId, $branchId, 'Stock In #' . $transactionId . ' linked to restock request #' . $requestId
        . ': received ' . $text($receivedScaled) . ', total ' . $text($fulfilled) . ' of ' . $text($approved)
        . ($complete ? ' | Fulfilled' : ' | Remaining ' . $text($remaining)), 'Inventory Approvals');
    inventoryApprovalNotifyUser($conn, (int)$request['requested_by'],
        ($complete ? 'Restock fulfilled: ' : 'Restock partly delivered: ') . $request['item_name'],
        'Restock request #' . $requestId . ' for ' . $request['item_name'] . ': ' . $text($fulfilled) . ' of '
        . $text($approved) . ' received' . ($complete ? '. The request is Fulfilled.' : '. Remaining: ' . $text($remaining) . '.'),
        'approval_decision', 'restock_delivery:' . $requestId . ':' . $transactionId);
    return ['complete' => $complete, 'remaining_text' => $text($remaining), 'fulfilled_text' => $text($fulfilled),
        'approved_text' => $text($approved)];
}

/**
 * "Request reorder" from Supply Forecasting: the Branch Admin (the approver)
 * creates an APPROVED restock request with the recommended whole vials/ampules.
 * One open restock request per item and branch.
 */
function inventoryApprovalReorderFromForecast(mysqli $conn, int $adminId, string $branchId, int $itemId,
    int $displayUnits, string $remarks): int
{
    $admin = inventoryApprovalUser($conn, $adminId);
    if (!$admin || (int)$admin['role_id'] !== 2 || $admin['status'] !== 'Active' || (string)$admin['branch_id'] !== $branchId) {
        throw new RuntimeException('Only an active Branch Admin of this branch can request a reorder.');
    }
    if ($displayUnits < 1) throw new RuntimeException('This forecast does not recommend a reorder.');
    $item = inventoryApprovalItem($conn, $itemId);
    $base = stockQuantityDecimal(stockQuantityParse(number_format(inventoryDisplayToBase((float)$displayUnits, $item), 4, '.', '')));
    $conn->begin_transaction();
    try {
        $requestId = inventoryApprovalCreate($conn, [
            'type' => 'restock', 'branch_id' => $branchId, 'requested_by' => $adminId, 'source' => 'Forecast',
            'item_id' => $itemId, 'item_name' => $item['item_name'], 'quantity' => $base, 'approved_quantity' => $base,
            'unit' => inventoryBaseUnitLabel($item), 'reason' => 'Reorder from Supply Forecasting', 'remarks' => $remarks,
            'payload' => ['display_quantity' => $displayUnits, 'display_unit' => inventoryDisplayUnitLabel($item)],
            'status' => 'Approved', 'decided_by' => $adminId, 'decision_remarks' => 'Reorder approved from Supply Forecasting',
            'open_key' => 'restock:' . $branchId . ':' . $itemId,
        ]);
        inventoryApprovalNotifyRole($conn, $branchId, 5, 'Reorder approved: ' . $item['item_name'],
            $admin['username'] . ' approved a reorder of ' . inventoryQuantityText((float)$base, $item) . ' of '
            . $item['item_name'] . ' from Supply Forecasting (request #' . $requestId . '). ' . $remarks
            . ' When the delivery arrives, record Stock In and link it to request #' . $requestId . '.',
            'restock_request', 'restock_approved:' . $requestId);
        $conn->commit();
        return $requestId;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/* ------------------------------------------------------------------
 * DAILY CLOSING REVIEW (Branch Admin)
 * A closing with a variance or wastage is listed first. "Reviewed" accepts
 * it; "Return for recount" sets status 'Returned' (remark required) and
 * notifies the Nurse, who must save the closing again. The training row of
 * the closing is KEPT while it is Returned: the Nurse's re-save upserts the
 * same training_dataset row (unique branch, item, date), so nothing is
 * duplicated and usage is never counted twice.
 * ---------------------------------------------------------------- */

/**
 * CLOSING REVIEW LIST. $filter:
 *   'needs'     submitted closings with a variance or wastage, plus closings
 *               returned for a recount (default, the badge count);
 *   'submitted' every submitted closing (reviewed or not yet flagged);
 *   'all'       every closing of the last $days days.
 * Count, page (limit/offset) and the full list of submitted closings in the
 * filter (for "Mark all as Reviewed") use the same conditions.
 */
const INVENTORY_CLOSING_FILTERS = ['needs', 'submitted', 'all'];

function inventoryApprovalClosingFilter($filter): string
{
    if ($filter === true) return 'all';
    return in_array($filter, INVENTORY_CLOSING_FILTERS, true) ? (string)$filter : 'needs';
}

/** FROM/WHERE of the closing review queries; binds branch_id twice. */
function inventoryApprovalClosingFrom(string $filter, int $days): string
{
    $days = max(1, min(366, $days));
    $flagged = "(d.variance <> 0 OR COALESCE(w.wastage, 0) > 0)";
    $condition = match ($filter) {
        'submitted' => "d.status = 'Submitted'",
        'all' => "d.inventory_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)",
        default => "((d.status = 'Submitted' AND $flagged) OR d.status = 'Returned')",
    };
    return "FROM daily_inventory_closings d
            JOIN inventory_items i ON i.item_id = d.item_id
            JOIN units un ON un.unit_id = i.unit_id
            LEFT JOIN users sub ON sub.user_id = d.submitted_by
            LEFT JOIN users rev ON rev.user_id = d.reviewed_by
            LEFT JOIN (SELECT h.item_id, h.usage_date, SUM(h.quantity_used) AS wastage
                       FROM inventory_usage_history h
                       WHERE h.branch_id = ? AND h.usage_type = 'Wastage'
                       GROUP BY h.item_id, h.usage_date) w
                   ON w.item_id = d.item_id AND w.usage_date = d.inventory_date
            WHERE d.branch_id = ? AND $condition";
}

/** One page of the closing review list ($limit null = up to 500 rows, as before). */
function inventoryApprovalClosingList(mysqli $conn, string $branchId, $filter = 'needs', int $days = 60, ?int $limit = null, int $offset = 0): array
{
    $filter = inventoryApprovalClosingFilter($filter);
    $hasRemarks = workflowColumnExists($conn, 'daily_inventory_closings', 'review_remarks');
    $limit = $limit === null ? 500 : max(1, min(500, $limit));
    $offset = max(0, $offset);
    $sql = "SELECT d.closing_id, d.branch_id, d.item_id, d.inventory_date, d.beginning_stock, d.delivery, d.consumed,
                   d.pull_out, d.computed_ending, d.actual_count, d.variance, d.remarks, d.status, d.submitted_by,
                   d.submitted_at, d.reviewed_by, d.reviewed_at, " . ($hasRemarks ? 'd.review_remarks' : 'NULL AS review_remarks') . ",
                   i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                   COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                   COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base, un.unit_name,
                   sub.username AS submitter_name, rev.username AS reviewer_name,
                   COALESCE(w.wastage, 0) AS wastage,
                   (d.variance <> 0 OR COALESCE(w.wastage, 0) > 0) AS flagged
            " . inventoryApprovalClosingFrom($filter, $days) . "
            ORDER BY (d.status = 'Submitted' AND (d.variance <> 0 OR COALESCE(w.wastage, 0) > 0)) DESC,
                     (d.status = 'Returned') DESC, d.inventory_date DESC, i.item_name ASC, d.closing_id ASC
            LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $branchId, $branchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Number of closings in the review filter (all pages). */
function inventoryApprovalClosingCount(mysqli $conn, string $branchId, $filter = 'needs', int $days = 60): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total ' . inventoryApprovalClosingFrom(inventoryApprovalClosingFilter($filter), $days));
    $stmt->bind_param('ss', $branchId, $branchId);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/** Every Submitted closing in the filter, on all pages (for "Mark all as Reviewed"). */
function inventoryApprovalClosingsSubmittedInFilter(mysqli $conn, string $branchId, $filter = 'needs', int $days = 60): array
{
    $stmt = $conn->prepare("SELECT d.closing_id, d.inventory_date, d.variance, d.submitted_by, i.item_name,
                COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base, un.unit_name,
                COALESCE(w.wastage, 0) AS wastage, (d.variance <> 0 OR COALESCE(w.wastage, 0) > 0) AS flagged
            " . inventoryApprovalClosingFrom(inventoryApprovalClosingFilter($filter), $days) . " AND d.status = 'Submitted'
            ORDER BY d.inventory_date DESC, i.item_name ASC, d.closing_id ASC");
    $stmt->bind_param('ss', $branchId, $branchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * BULK CLOSING REVIEW ("Mark all as Reviewed" / "Mark selected as Reviewed").
 * One transaction with row locks and the same checks as the single review:
 * an active Branch Admin of the closing's branch, never the person
 * who submitted it, only closings still Submitted (others are
 * skipped, not failed). A shared remark is required when any closing to
 * review has a variance or wastage. One audit entry per closing.
 * Returns ['reviewed' => n, 'skipped' => n, 'reasons' => [reason => n], 'flagged' => n].
 */
function inventoryApprovalReviewClosingsBulk(mysqli $conn, array $closingIds, int $adminId, string $remark): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $closingIds), static fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('Select at least one closing to review.');
    if (count($ids) > 1000) throw new RuntimeException('Review at most 1,000 closings at a time.');
    $remark = trim($remark);
    if (mb_strlen($remark) > 1000) throw new RuntimeException('Keep the review remark within 1,000 characters.');
    if (!workflowColumnExists($conn, 'daily_inventory_closings', 'review_remarks')) inventoryApprovalAssertReady($conn);
    $admin = inventoryApprovalUser($conn, $adminId);
    if (!$admin || (int)$admin['role_id'] !== 2 || $admin['status'] !== 'Active') {
        throw new RuntimeException('Only an active Branch Admin can review daily closings.');
    }
    $branchId = (string)$admin['branch_id'];
    $conn->begin_transaction();
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT d.closing_id, d.branch_id, d.item_id, d.inventory_date, d.status, d.variance, d.submitted_by,
                i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base
            FROM daily_inventory_closings d JOIN inventory_items i ON i.item_id = d.item_id JOIN units un ON un.unit_id = i.unit_id
            WHERE d.closing_id IN ($placeholders) ORDER BY d.closing_id FOR UPDATE");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $locked = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $locked[(int)$row['closing_id']] = $row;
        $stmt->close();

        $reasons = [];
        $skip = static function (string $reason) use (&$reasons): void { $reasons[$reason] = ($reasons[$reason] ?? 0) + 1; };
        $toReview = [];
        foreach ($ids as $id) {
            $c = $locked[$id] ?? null;
            if (!$c) { $skip('not found'); continue; }
            if ((string)$c['branch_id'] !== $branchId) { $skip('another branch'); continue; }
            if ((int)$c['submitted_by'] === $adminId) { $skip('submitted by you'); continue; }
            if ($c['status'] !== 'Submitted') { $skip('already ' . strtolower($c['status'] === 'Returned' ? 'returned for recount' : (string)$c['status'])); continue; }
            $toReview[] = $c;
        }

        // Wastage of each closing's day (read after the locks; a plain read).
        $flagged = 0;
        $wastageStmt = $conn->prepare("SELECT COALESCE(SUM(quantity_used), 0) AS wastage FROM inventory_usage_history
            WHERE branch_id = ? AND item_id = ? AND usage_date = ? AND usage_type = 'Wastage'");
        foreach ($toReview as &$c) {
            $itemId = (int)$c['item_id'];
            $wastageStmt->bind_param('sis', $branchId, $itemId, $c['inventory_date']);
            $wastageStmt->execute();
            $c['wastage'] = (float)$wastageStmt->get_result()->fetch_assoc()['wastage'];
            $c['flagged'] = (float)$c['variance'] != 0.0 || $c['wastage'] > 0;
            if ($c['flagged']) $flagged++;
        }
        unset($c);
        $wastageStmt->close();
        if ($flagged > 0 && $remark === '') {
            throw new RuntimeException('Enter a shared remark: ' . $flagged . ' of the closings to review ' . ($flagged === 1 ? 'has' : 'have')
                . ' a variance or wastage (for example "Variances checked with the Nurse.").');
        }

        $reviewed = 0;
        $update = $conn->prepare("UPDATE daily_inventory_closings
            SET status = 'Reviewed', reviewed_by = ?, reviewed_at = NOW(), review_remarks = NULLIF(?, '')
            WHERE closing_id = ? AND status = 'Submitted'");
        $total = count($toReview);
        foreach ($toReview as $c) {
            $closingId = (int)$c['closing_id'];
            $update->bind_param('isi', $adminId, $remark, $closingId);
            $update->execute();
            if ($update->affected_rows !== 1) { $skip('changed meanwhile'); continue; }
            $reviewed++;
            workflowAudit($conn, $adminId, $branchId,
                'Reviewed daily closing #' . $closingId . ': ' . $c['item_name'] . ' on ' . $c['inventory_date']
                . ' | Variance: ' . inventoryQuantityText((float)$c['variance'], $c)
                . ($c['wastage'] > 0 ? ' | Wastage: ' . inventoryQuantityText((float)$c['wastage'], $c) : '')
                . ' | Bulk review of ' . $total . ' closing' . ($total === 1 ? '' : 's')
                . ($remark !== '' ? ' | Remark: ' . $remark : ''), 'Daily Inventory Review');
        }
        $update->close();
        $conn->commit();
        return ['reviewed' => $reviewed, 'skipped' => array_sum($reasons), 'reasons' => $reasons, 'flagged' => $flagged];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Mark a submitted closing Reviewed, or return it to the Nurse for a recount. */
function inventoryApprovalReviewClosing(mysqli $conn, int $closingId, int $adminId, string $decision, string $remarks): string
{
    $remarks = trim($remarks);
    if (!in_array($decision, ['reviewed', 'return'], true)) throw new RuntimeException('Choose Reviewed or Return for recount.');
    if ($decision === 'return' && $remarks === '') throw new RuntimeException('Enter a remark telling the Nurse what to recount.');
    if (mb_strlen($remarks) > 1000) throw new RuntimeException('Keep the review remark within 1,000 characters.');
    if (!workflowColumnExists($conn, 'daily_inventory_closings', 'review_remarks')) inventoryApprovalAssertReady($conn);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT d.closing_id, d.branch_id, d.item_id, d.inventory_date, d.status, d.variance,
                d.submitted_by, i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base
            FROM daily_inventory_closings d JOIN inventory_items i ON i.item_id = d.item_id JOIN units un ON un.unit_id = i.unit_id
            WHERE d.closing_id = ? FOR UPDATE");
        $stmt->bind_param('i', $closingId);
        $stmt->execute();
        $closing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$closing) throw new RuntimeException('The daily closing was not found.');
        $admin = inventoryApprovalUser($conn, $adminId);
        if (!$admin || (int)$admin['role_id'] !== 2 || $admin['status'] !== 'Active') {
            throw new RuntimeException('Only an active Branch Admin can review daily closings.');
        }
        if ((string)$admin['branch_id'] !== (string)$closing['branch_id']) {
            throw new RuntimeException('This closing belongs to another branch.');
        }
        if ((int)$closing['submitted_by'] === $adminId) throw new RuntimeException('You cannot review a closing you submitted.');
        if ($closing['status'] !== 'Submitted') {
            throw new RuntimeException('This closing is ' . $closing['status'] . ' and cannot be reviewed now'
                . ($closing['status'] === 'Returned' ? ' (it is waiting for the Nurse\'s recount)' : '') . '.');
        }
        $status = $decision === 'reviewed' ? 'Reviewed' : 'Returned';
        $stmt = $conn->prepare("UPDATE daily_inventory_closings
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = NULLIF(?, '')
            WHERE closing_id = ? AND status = 'Submitted'");
        $stmt->bind_param('sisi', $status, $adminId, $remarks, $closingId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('The closing changed. Refresh and try again.');
        $stmt->close();
        $label = $closing['item_name'] . ' on ' . $closing['inventory_date'];
        $varianceText = inventoryQuantityText((float)$closing['variance'], $closing);
        workflowAudit($conn, $adminId, (string)$closing['branch_id'],
            ($status === 'Reviewed' ? 'Reviewed daily closing #' : 'Returned daily closing for recount #') . $closingId . ': ' . $label
            . ' | Variance: ' . $varianceText . ($remarks !== '' ? ' | Remark: ' . $remarks : '')
            . ($status === 'Returned' ? ' | Training row kept until the Nurse saves the recount' : ''), 'Daily Inventory Review');
        if ($status === 'Returned') {
            inventoryApprovalNotifyUser($conn, (int)$closing['submitted_by'], 'Daily closing returned for recount',
                $admin['username'] . ' returned the daily closing of ' . $label . ' for a recount. Remark: ' . $remarks
                . '. Open Daily Inventory, count the item again and save the closing.',
                'closing_returned', 'closing_returned:' . $closingId);
        }
        $conn->commit();
        return $status === 'Reviewed'
            ? 'The closing of ' . $label . ' is marked Reviewed.'
            : 'The closing of ' . $label . ' was returned to the Nurse for a recount.';
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/* ------------------------------------------------------------------
 * ITEM TRANSACTION HISTORY: who requested, who approved, when, why.
 * stock_transactions.approval_request_id links a movement to its request
 * (approved adjustment / stock-out / disposal, or the restock request a
 * Stock In fulfilled). Unlinked movements show "No request".
 * ---------------------------------------------------------------- */

/** SELECT columns and JOINs for history queries; NULL columns before the migration. */
function inventoryApprovalHistorySql(mysqli $conn, string $alias = 'st'): array
{
    if (!inventoryApprovalReady($conn)) {
        return ['select' => 'NULL AS ar_request_id, NULL AS ar_type, NULL AS ar_reason, NULL AS ar_status,
                NULL AS ar_requested_at, NULL AS ar_decided_at, NULL AS ar_requester, NULL AS ar_decider', 'join' => ''];
    }
    return [
        'select' => 'ar.request_id AS ar_request_id, ar.request_type AS ar_type, ar.reason AS ar_reason, ar.status AS ar_status,
                ar.requested_at AS ar_requested_at, ar.decided_at AS ar_decided_at,
                arq.username AS ar_requester, ard.username AS ar_decider',
        'join' => " LEFT JOIN inventory_approval_requests ar ON ar.request_id = $alias.approval_request_id
                LEFT JOIN users arq ON arq.user_id = ar.requested_by
                LEFT JOIN users ard ON ard.user_id = ar.decided_by",
    ];
}

/** Request / approval fields of one history row for display and CSV. */
function inventoryApprovalHistoryFields(array $row): array
{
    if (empty($row['ar_request_id'])) {
        return ['linked' => false, 'request' => 'No request', 'requested_by' => '', 'requested_at' => '',
            'approved_by' => '', 'approved_at' => '', 'reason' => '', 'text' => 'No request'];
    }
    $fmt = static fn($v): string => $v ? date('m/d/Y h:i A', strtotime((string)$v)) : '';
    $fields = [
        'linked' => true,
        'request' => '#' . (int)$row['ar_request_id'] . ' ' . inventoryApprovalTypeLabel((string)$row['ar_type']),
        'requested_by' => (string)($row['ar_requester'] ?? ''),
        'requested_at' => $fmt($row['ar_requested_at'] ?? null),
        'approved_by' => (string)($row['ar_decider'] ?? ''),
        'approved_at' => $fmt($row['ar_decided_at'] ?? null),
        'reason' => (string)($row['ar_reason'] ?? ''),
    ];
    $fields['text'] = $fields['request'] . ' | Requested by ' . ($fields['requested_by'] ?: '—') . ' ' . $fields['requested_at']
        . ' | Approved by ' . ($fields['approved_by'] ?: '—') . ' ' . $fields['approved_at']
        . ($fields['reason'] !== '' ? ' | Reason: ' . $fields['reason'] : '');
    return $fields;
}

/**
 * Pending Stock In requests of a branch grouped by item, for the notes on the
 * stock views ("1 delivery of Speeda (50 vials) awaiting approval"): pending
 * stock cannot be used until the Branch Admin approves it.
 */
function inventoryApprovalPendingDeliveries(mysqli $conn, string $branchId): array
{
    $byItem = [];
    foreach (inventoryApprovalList($conn, ['branch_id' => $branchId, 'statuses' => ['Pending'], 'types' => ['stock_in'], 'limit' => 500]) as $row) {
        $key = (int)$row['item_id'];
        if (!isset($byItem[$key])) {
            $byItem[$key] = ['item_id' => $key, 'item_name' => (string)$row['item_name'], 'count' => 0, 'scaled' => 0,
                'unit_row' => $row, 'request_ids' => []];
        }
        $byItem[$key]['count']++;
        $byItem[$key]['scaled'] += stockQuantityParse((string)$row['quantity'], true);
        $byItem[$key]['request_ids'][] = (int)$row['request_id'];
    }
    foreach ($byItem as &$entry) {
        $entry['quantity_text'] = inventoryQuantityText((float)stockQuantityDecimal($entry['scaled']), $entry['unit_row']);
        $entry['text'] = $entry['count'] . ' ' . ($entry['count'] === 1 ? 'delivery' : 'deliveries') . ' of '
            . $entry['item_name'] . ' (' . $entry['quantity_text'] . ') awaiting approval';
    }
    unset($entry);
    return array_values($byItem);
}

/** HTML note listing pending deliveries (empty string when there are none). */
function inventoryApprovalPendingDeliveriesNote(mysqli $conn, string $branchId, string $audience = ''): string
{
    try {
        $rows = inventoryApprovalReady($conn) ? inventoryApprovalPendingDeliveries($conn, $branchId) : [];
    } catch (Throwable $e) {
        $rows = [];
    }
    if (!$rows) return '';
    $items = '';
    foreach ($rows as $row) {
        $items .= '<li>' . htmlspecialchars($row['text'], ENT_QUOTES, 'UTF-8') . ' (request #'
            . implode(', #', $row['request_ids']) . ')</li>';
    }
    return '<div class="alert alert-warning d-flex gap-2 align-items-start mb-3" role="status">'
        . '<i class="bi bi-hourglass-split mt-1"></i><div><strong>Deliveries awaiting Branch Admin approval</strong>'
        . '<ul class="mb-0 mt-1 ps-3">' . $items . '</ul>'
        . '<div class="small mt-1">This stock is not in inventory yet and cannot be used until it is approved.'
        . ($audience !== '' ? ' ' . htmlspecialchars($audience, ENT_QUOTES, 'UTF-8') : '') . '</div></div></div>';
}
