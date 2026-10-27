<?php
declare(strict_types=1);

require_once __DIR__ . '/report_export.php';
require_once __DIR__ . '/inventory_approval_helpers.php';
require_once __DIR__ . '/inventory_unit_helpers.php';
require_once __DIR__ . '/stock_quantity_helpers.php';
require_once __DIR__ . '/daily_inventory_helpers.php';
require_once __DIR__ . '/forecast_status_helper.php';
require_once __DIR__ . '/patient_display_helper.php';

/*
 * CONTROL AND OPERATIONS REPORTS (read-only, live data).
 * Every report takes a branch (null = all branches, Super Admin only) and a
 * date range, and returns one report array that both the screen preview and
 * the PDF/CSV exports use (sources/report_export.php). Quantities are shown
 * in vials/ampules/pieces where the item has a counting unit, like on screen.
 * Forecast data is only READ from the saved forecast_results; no forecast
 * is run and no training data is touched.
 *
 * $params: branch_id (string|null), from (Y-m-d), to (Y-m-d), horizon (7|14|30|null),
 *          link_base (page that opens a record, Data Quality only)
 */

const REPORT_CATALOG = [
    'approval_control' => ['title' => 'Approval / Control Report', 'roles' => [1, 2], 'orientation' => 'L',
        'description' => 'Every inventory request: type, item, quantity, reason, requester, approver, decision, dates and status, with counts by type and status.'],
    'adjustment_disposal' => ['title' => 'Stock Adjustment & Disposal Report', 'roles' => [2, 5], 'orientation' => 'L',
        'description' => 'Approved stock adjustments, disposals and pull-outs with before/after values and reasons.'],
    'restock' => ['title' => 'Restock Request Report', 'roles' => [2, 5], 'orientation' => 'L',
        'description' => 'Pending, approved, fulfilled and rejected restock requests with the quantity requested, approved and received.'],
    'daily_closing' => ['title' => 'Daily Inventory / Closing Report', 'roles' => [2, 3], 'orientation' => 'L',
        'description' => 'Per day and item: beginning, delivery, consumed, pull-out, ending, actual, variance, wastage, review status, plus the day\'s patients, cases and doses.'],
    'wastage' => ['title' => 'Wastage Report', 'roles' => [2, 5], 'orientation' => 'P',
        'description' => 'Wastage by item, reason and date.'],
    'forecast' => ['title' => 'Forecast Report', 'roles' => [2, 3], 'orientation' => 'L', 'horizon' => true,
        'description' => 'The latest saved 7-, 14- and 30-day supply forecasts (read from saved results; no new forecast is run).'],
    'philhealth_status' => ['title' => 'PhilHealth Status Report', 'roles' => [2, 4], 'orientation' => 'L',
        'description' => 'PhilHealth claims by status, with the case, patient, membership, requirements and dates.'],
    'data_quality' => ['title' => 'Data Quality Report', 'roles' => [2, 4], 'orientation' => 'L',
        'description' => 'Patients and cases with missing or invalid required fields, with a count per problem and links to fix them.'],
];

/** Report keys a role can open. */
function reportCatalogForRole(int $roleId): array
{
    return array_keys(array_filter(REPORT_CATALOG, static fn($d) => in_array($roleId, $d['roles'], true)));
}

/** Build one report. */
function reportBuild(mysqli $conn, string $key, array $params): array
{
    if (!isset(REPORT_CATALOG[$key])) throw new InvalidArgumentException('Unknown report.');
    $def = REPORT_CATALOG[$key];
    $branchId = isset($params['branch_id']) && $params['branch_id'] !== '' ? (string)$params['branch_id'] : null;
    $from = (string)($params['from'] ?? date('Y-m-01'));
    $to = (string)($params['to'] ?? date('Y-m-d'));
    $report = match ($key) {
        'approval_control' => reportApprovalControl($conn, $branchId, $from, $to),
        'adjustment_disposal' => reportAdjustmentDisposal($conn, (string)$branchId, $from, $to),
        'restock' => reportRestock($conn, (string)$branchId, $from, $to),
        'daily_closing' => reportDailyClosing($conn, (string)$branchId, $from, $to),
        'wastage' => reportWastage($conn, (string)$branchId, $from, $to),
        'forecast' => reportForecast($conn, (string)$branchId, $from, $to, isset($params['horizon']) && $params['horizon'] ? (int)$params['horizon'] : null),
        'philhealth_status' => reportPhilhealthStatus($conn, (string)$branchId, $from, $to),
        'data_quality' => reportDataQuality($conn, (string)$branchId, $from, $to, (string)($params['link_base'] ?? '')),
    };
    $report['key'] = $key;
    $report['title'] = $report['title'] ?? $def['title'];
    $report['orientation'] = $report['orientation'] ?? $def['orientation'];
    return $report;
}

function reportDateTime(?string $value): string
{
    return $value ? date('M d, Y h:i A', strtotime($value)) : '';
}

function reportDate(?string $value): string
{
    return $value && !str_starts_with($value, '0000') ? date('M d, Y', strtotime($value)) : '';
}

/** Base quantity in the item's counting format ("8 vials + 2 sites"). */
function reportQty($quantity, array $item): string
{
    if ($quantity === null || $quantity === '') return '';
    return inventoryQuantityText((float)$quantity, $item);
}

/* ------------------------------------------------------------------
 * 1. APPROVAL / CONTROL REPORT
 * ---------------------------------------------------------------- */
function reportApprovalControl(mysqli $conn, ?string $branchId, string $from, string $to): array
{
    $filters = ['requested_from' => $from, 'requested_to' => $to, 'limit' => 20000];
    if ($branchId !== null) $filters['branch_id'] = $branchId;
    $requests = array_reverse(inventoryApprovalList($conn, $filters));
    $statuses = ['Pending', 'Approved', 'Fulfilled', 'Rejected', 'Cancelled'];
    $counts = [];
    $rows = [];
    foreach ($requests as $r) {
        $type = inventoryApprovalTypeLabel((string)$r['request_type']);
        $counts[$type][$r['status']] = ($counts[$type][$r['status']] ?? 0) + 1;
        $p = $r['payload_data'];
        if ($r['request_type'] === 'stock_adjustment') {
            $delta = (float)($p['counted'] ?? 0) - (float)($p['system_at_count'] ?? 0);
            $qty = ($delta > 0 ? '+' : '-') . inventoryQuantityText(abs($delta), $r);
        } elseif ($r['request_type'] === 'restock') {
            $qty = 'Requested ' . reportQty($r['quantity'], $r)
                . (in_array($r['status'], ['Approved', 'Fulfilled'], true) ? '; approved ' . reportQty($r['approved_quantity'] ?? $r['quantity'], $r)
                    . '; received ' . reportQty($r['fulfilled_quantity'], $r) : '');
        } elseif ($r['request_type'] === 'return_transfer') {
            $qty = trim(($p['quantity'] ?? '') . ' ' . ($p['unit_name'] ?? ''));
        } else {
            $qty = reportQty($r['quantity'], $r);
        }
        $row = [
            '#' . (int)$r['request_id'],
            reportDateTime($r['requested_at']),
            $type,
            trim((string)($r['item_name'] ?? '') . (!empty($r['batch_snapshot']) ? ' (batch ' . $r['batch_snapshot'] . ')' : '')),
            $qty,
            trim((string)($r['reason'] ?? '') . (trim((string)($r['remarks'] ?? '')) !== '' ? ' — ' . $r['remarks'] : '')),
            trim(($r['requester_name'] ?? '') . ' (' . ($r['requester_role'] ?? '') . ')'),
            (string)($r['decider_name'] ?? ''),
            $r['status'] === 'Cancelled' ? 'Cancelled ' . reportDateTime($r['cancelled_at']) : reportDateTime($r['decided_at']),
            $r['status'],
            trim((string)($r['decision_remarks'] ?? '') . ($r['result_transaction_id'] ? ' | Stock transaction #' . $r['result_transaction_id'] : '')
                . ($r['result_reference'] ? ' | ' . $r['result_reference'] : ''), ' |'),
        ];
        if ($branchId === null) array_splice($row, 1, 0, [(string)$r['branch_name']]);
        $rows[] = $row;
    }
    $columns = [['label' => 'Request', 'width' => 1.1], ['label' => 'Requested', 'width' => 2], ['label' => 'Type', 'width' => 2],
        ['label' => 'Item', 'width' => 2.6], ['label' => 'Quantity', 'width' => 2.4], ['label' => 'Reason / Remarks', 'width' => 3.4],
        ['label' => 'Requested By', 'width' => 2.2], ['label' => 'Approved / Decided By', 'width' => 1.8], ['label' => 'Decision Date', 'width' => 2],
        ['label' => 'Status', 'width' => 1.4], ['label' => 'Decision Remarks / Result', 'width' => 3]];
    if ($branchId === null) array_splice($columns, 1, 0, [['label' => 'Branch', 'width' => 1.6]]);

    $countRows = [];
    $totals = array_fill_keys($statuses, 0);
    foreach ($counts as $type => $byStatus) {
        $line = [$type];
        $sum = 0;
        foreach ($statuses as $st) {
            $n = $byStatus[$st] ?? 0;
            $line[] = (string)$n;
            $totals[$st] += $n;
            $sum += $n;
        }
        $line[] = (string)$sum;
        $countRows[] = $line;
    }
    ksort($counts);
    $countColumns = [['label' => 'Type', 'width' => 3]];
    foreach ($statuses as $st) $countColumns[] = ['label' => $st, 'width' => 1, 'align' => 'R'];
    $countColumns[] = ['label' => 'Total', 'width' => 1, 'align' => 'R'];

    return [
        'summary' => ['Requests' => count($requests)] + array_map('strval', $totals),
        'sections' => [
            ['title' => 'Counts by type and status', 'columns' => $countColumns, 'rows' => $countRows,
                'totals' => array_merge(['Total'], array_map('strval', array_values($totals)), [(string)count($requests)])],
            ['title' => 'Requests', 'columns' => $columns, 'rows' => $rows],
        ],
        'notes' => [
            'Pending requests have not changed stock. Approved requests were applied once, at the decision time, through the normal stock functions.',
            'A restock request is Approved until its deliveries (linked Stock In) reach the approved quantity, then Fulfilled.',
        ],
    ];
}

/** "Before: X | After: Y" of an approved request (stored with it, or from its audit entry for older ones). */
function reportApprovalBeforeAfter(mysqli $conn, array $request): array
{
    $result = $request['payload_data']['result'] ?? null;
    if (is_array($result) && ($result['before'] ?? null) !== null) return [(string)$result['before'], (string)$result['after']];
    $like = 'Approved request #' . (int)$request['request_id'] . ' from%';
    $stmt = $conn->prepare("SELECT action FROM audit_logs WHERE action LIKE ? ORDER BY log_id DESC LIMIT 1");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $action = (string)($stmt->get_result()->fetch_assoc()['action'] ?? '');
    $stmt->close();
    if (preg_match('/\| Before: (.*?) \| After: (.*?)(?: \||$)/', $action, $m)) return [$m[1], $m[2]];
    return ['', ''];
}

/* ------------------------------------------------------------------
 * 2. STOCK ADJUSTMENT & DISPOSAL REPORT
 * ---------------------------------------------------------------- */
function reportAdjustmentDisposal(mysqli $conn, string $branchId, string $from, string $to): array
{
    // The movements themselves: adjustments, expired-batch disposals and
    // pull-outs (Stock Out without a patient usage record, not a return).
    $ready = inventoryApprovalReady($conn);
    $stmt = $conn->prepare(
        "SELECT st.transaction_id, st.transaction_type, st.quantity, st.remarks, st.transaction_date, st.item_id,
                " . ($ready ? 'st.approval_request_id' : 'NULL AS approval_request_id') . ",
                i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base, u.username
         FROM stock_transactions st
         JOIN inventory_items i ON i.item_id = st.item_id
         JOIN units un ON un.unit_id = i.unit_id
         LEFT JOIN users u ON u.user_id = st.user_id
         WHERE st.branch_id = ? AND st.transaction_date >= ? AND st.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
           AND (st.transaction_type IN ('ADJUSTMENT', 'EXPIRED')
                OR (st.transaction_type = 'OUT' AND st.vaccination_id IS NULL
                    AND COALESCE(st.remarks, '') NOT LIKE '%Usage ID: %'
                    AND COALESCE(st.remarks, '') NOT LIKE 'Return RET-%'))
         ORDER BY st.transaction_date, st.transaction_id"
    );
    $stmt->bind_param('sss', $branchId, $from, $to);
    $stmt->execute();
    $movements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $rows = [];
    $counts = ['Adjustments' => 0, 'Expired disposals' => 0, 'Pull-outs / stock out' => 0];
    foreach ($movements as $m) {
        $request = $m['approval_request_id'] ? inventoryApprovalLoad($conn, (int)$m['approval_request_id']) : null;
        $remarks = (string)($m['remarks'] ?? '');
        if ($m['transaction_type'] === 'ADJUSTMENT') {
            $kind = 'Adjustment';
            $counts['Adjustments']++;
            $qty = ((float)$m['quantity'] > 0 ? '+' : '-') . inventoryQuantityText(abs((float)$m['quantity']), $m);
        } elseif (str_starts_with($remarks, 'Expired stock disposal') || $m['transaction_type'] === 'EXPIRED'
            || ($request && $request['request_type'] === 'expired_disposal')) {
            $kind = 'Expired disposal';
            $counts['Expired disposals']++;
            $qty = '-' . inventoryQuantityText(abs((float)$m['quantity']), $m);
        } else {
            $kind = 'Pull-out / stock out';
            $counts['Pull-outs / stock out']++;
            $qty = '-' . inventoryQuantityText(abs((float)$m['quantity']), $m);
        }
        [$before, $after] = $request ? reportApprovalBeforeAfter($conn, $request) : ['', ''];
        if ($before === '' && preg_match('/Previous Stock: ([0-9.]+) \| New Stock: ([0-9.]+)/', $remarks, $pm)) {
            $before = 'batch ' . inventoryQuantityText((float)$pm[1], $m);
            $after = 'batch ' . inventoryQuantityText((float)$pm[2], $m);
        }
        $batch = preg_match('/Batch\/Lot(?: No\.)?: ([^|]+)/', $remarks, $bm) ? trim($bm[1]) : (string)($request['batch_snapshot'] ?? '');
        $reason = $request ? (string)$request['reason'] : (preg_match('/Reason: ([^|]+)/', $remarks, $rm) ? trim($rm[1]) : ($kind === 'Expired disposal' ? 'Expired' : ''));
        $rows[] = [
            reportDateTime($m['transaction_date']),
            'TRX-' . str_pad((string)$m['transaction_id'], 4, '0', STR_PAD_LEFT),
            $kind,
            (string)$m['item_name'],
            $batch,
            $qty,
            $before,
            $after,
            $reason,
            $request ? trim(($request['requester_name'] ?? '') . ' (' . ($request['requester_role'] ?? '') . ')') : (string)($m['username'] ?? ''),
            $request ? trim(($request['decider_name'] ?? '') . ', ' . reportDateTime($request['decided_at']), ', ') : 'Recorded before approval controls',
            $request ? '#' . (int)$request['request_id'] : 'No request',
        ];
    }
    return [
        'summary' => ['Movements' => count($rows)] + array_map('strval', $counts),
        'sections' => [['columns' => [
            ['label' => 'Date', 'width' => 2], ['label' => 'Transaction', 'width' => 1.4], ['label' => 'Type', 'width' => 1.8],
            ['label' => 'Item', 'width' => 2.2], ['label' => 'Batch', 'width' => 1.7], ['label' => 'Quantity', 'width' => 1.8, 'align' => 'R'],
            ['label' => 'Before', 'width' => 2], ['label' => 'After', 'width' => 2], ['label' => 'Reason', 'width' => 2.2],
            ['label' => 'Requested By', 'width' => 2], ['label' => 'Approved By', 'width' => 2.2], ['label' => 'Request', 'width' => 1.1],
        ], 'rows' => $rows]],
        'notes' => ['Only applied movements are listed (approved requests, or movements recorded before the approval controls). Pending and rejected requests changed nothing and are in the Approval / Control Report.',
            'An adjustment\'s Before/After is the batch (or usable stock) at approval; a disposal removes the whole expired batch.'],
    ];
}

/* ------------------------------------------------------------------
 * 3. RESTOCK REQUEST REPORT
 * ---------------------------------------------------------------- */
function reportRestock(mysqli $conn, string $branchId, string $from, string $to): array
{
    $requests = array_reverse(inventoryApprovalList($conn, ['branch_id' => $branchId, 'types' => ['restock'],
        'requested_from' => $from, 'requested_to' => $to, 'limit' => 20000]));
    $rows = [];
    $counts = ['Pending' => 0, 'Approved' => 0, 'Fulfilled' => 0, 'Rejected' => 0, 'Cancelled' => 0];
    foreach ($requests as $r) {
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        $approved = in_array($r['status'], ['Approved', 'Fulfilled'], true) ? (float)($r['approved_quantity'] ?? $r['quantity']) : null;
        $received = (float)$r['fulfilled_quantity'];
        $rows[] = [
            '#' . (int)$r['request_id'],
            reportDateTime($r['requested_at']),
            (string)$r['item_name'],
            reportQty($r['quantity'], $r),
            $approved === null ? '—' : reportQty($approved, $r),
            $received > 0 ? reportQty($received, $r) : ($approved === null ? '—' : '0'),
            $r['status'] === 'Approved' ? reportQty(max(0, $approved - $received), $r) : '—',
            $r['status'],
            (string)($r['source'] ?? ''),
            trim(($r['requester_name'] ?? '') . ' (' . ($r['requester_role'] ?? '') . ')'),
            trim(($r['decider_name'] ?? '') . ($r['decided_at'] ? ', ' . reportDateTime($r['decided_at']) : ''), ', '),
            reportDateTime($r['fulfilled_at']),
            trim((string)($r['reason'] ?? '') . (trim((string)($r['decision_remarks'] ?? '')) !== '' ? ' | Decision: ' . $r['decision_remarks'] : '')),
        ];
    }
    return [
        'summary' => ['Restock requests' => count($rows)] + array_map('strval', $counts),
        'sections' => [['columns' => [
            ['label' => 'Request', 'width' => 1.1], ['label' => 'Requested', 'width' => 2], ['label' => 'Item', 'width' => 2.2],
            ['label' => 'Requested Qty', 'width' => 1.7, 'align' => 'R'], ['label' => 'Approved Qty', 'width' => 1.7, 'align' => 'R'],
            ['label' => 'Received', 'width' => 1.7, 'align' => 'R'], ['label' => 'Remaining', 'width' => 1.6, 'align' => 'R'],
            ['label' => 'Status', 'width' => 1.4], ['label' => 'Source', 'width' => 1.4], ['label' => 'Requested By', 'width' => 2.2],
            ['label' => 'Decided By', 'width' => 2.4], ['label' => 'Fulfilled', 'width' => 2], ['label' => 'Reason / Decision', 'width' => 3],
        ], 'rows' => $rows]],
        'notes' => ['Received is the total of the Stock Ins linked to the request (approved deliveries only). A partial delivery keeps the request Approved and shows the remaining quantity.'],
    ];
}

/* ------------------------------------------------------------------
 * 4. DAILY INVENTORY / CLOSING REPORT
 * ---------------------------------------------------------------- */
function reportDailyClosing(mysqli $conn, string $branchId, string $from, string $to): array
{
    $hasRemarks = workflowColumnExists($conn, 'daily_inventory_closings', 'review_remarks');
    $stmt = $conn->prepare(
        "SELECT d.closing_id, d.inventory_date, d.item_id, d.beginning_stock, d.delivery, d.consumed, d.pull_out,
                d.computed_ending, d.actual_count, d.variance, d.status, d.remarks, d.reviewed_at,
                " . ($hasRemarks ? 'd.review_remarks' : 'NULL AS review_remarks') . ",
                i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base,
                sub.username AS submitter_name, rev.username AS reviewer_name, COALESCE(w.wastage, 0) AS wastage
         FROM daily_inventory_closings d
         JOIN inventory_items i ON i.item_id = d.item_id
         JOIN units un ON un.unit_id = i.unit_id
         LEFT JOIN users sub ON sub.user_id = d.submitted_by
         LEFT JOIN users rev ON rev.user_id = d.reviewed_by
         LEFT JOIN (SELECT h.item_id, h.usage_date, SUM(h.quantity_used) AS wastage FROM inventory_usage_history h
                    WHERE h.branch_id = ? AND h.usage_type = 'Wastage' AND h.usage_date BETWEEN ? AND ?
                    GROUP BY h.item_id, h.usage_date) w ON w.item_id = d.item_id AND w.usage_date = d.inventory_date
         WHERE d.branch_id = ? AND d.inventory_date BETWEEN ? AND ?
         ORDER BY d.inventory_date, i.item_name"
    );
    $stmt->bind_param('ssssss', $branchId, $from, $to, $branchId, $from, $to);
    $stmt->execute();
    $closings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $rows = [];
    $days = [];
    $withVariance = 0;
    foreach ($closings as $c) {
        $days[(string)$c['inventory_date']] = ($days[(string)$c['inventory_date']] ?? 0) + 1;
        if ((float)$c['variance'] != 0) $withVariance++;
        $status = match ((string)$c['status']) {
            'Draft' => 'Needs resubmission', 'Returned' => 'Returned for recount', default => (string)$c['status'],
        };
        $rows[] = [
            reportDate($c['inventory_date']),
            (string)$c['item_name'],
            reportQty($c['beginning_stock'], $c),
            reportQty($c['delivery'], $c),
            reportQty($c['consumed'], $c),
            reportQty($c['pull_out'], $c),
            reportQty($c['computed_ending'], $c),
            reportQty($c['actual_count'], $c),
            reportQty($c['variance'], $c),
            (float)$c['wastage'] > 0 ? reportQty($c['wastage'], $c) : '—',
            $status,
            (string)($c['submitter_name'] ?? ''),
            trim(($c['reviewer_name'] ?? '') . ($c['reviewed_at'] ? ', ' . reportDate($c['reviewed_at']) : '')
                . (trim((string)($c['review_remarks'] ?? '')) !== '' ? ' — ' . $c['review_remarks'] : ''), ', '),
        ];
    }
    $dayRows = [];
    foreach (array_keys($days) as $date) {
        $counts = dailyInventoryDayCounts($conn, $branchId, $date);
        $dayRows[] = [reportDate($date), (string)$days[$date], (string)($counts['patient_count'] ?? 0),
            (string)($counts['animal_bite_cases'] ?? 0), (string)($counts['vaccinations_administered'] ?? 0)];
    }
    return [
        'summary' => ['Days' => count($days), 'Item closings' => count($rows), 'With variance' => $withVariance,
            'Reviewed' => count(array_filter($closings, static fn($c) => $c['status'] === 'Reviewed')),
            'Returned for recount' => count(array_filter($closings, static fn($c) => $c['status'] === 'Returned'))],
        'sections' => [
            ['title' => 'Closings per day and item', 'columns' => [
                ['label' => 'Date', 'width' => 1.6], ['label' => 'Item', 'width' => 2.2], ['label' => 'Beginning', 'width' => 1.6, 'align' => 'R'],
                ['label' => 'Delivery', 'width' => 1.4, 'align' => 'R'], ['label' => 'Consumed', 'width' => 1.4, 'align' => 'R'],
                ['label' => 'Pull-out', 'width' => 1.4, 'align' => 'R'], ['label' => 'Ending', 'width' => 1.6, 'align' => 'R'],
                ['label' => 'Actual', 'width' => 1.6, 'align' => 'R'], ['label' => 'Variance', 'width' => 1.4, 'align' => 'R'],
                ['label' => 'Wastage', 'width' => 1.3, 'align' => 'R'], ['label' => 'Review Status', 'width' => 1.6],
                ['label' => 'Nurse', 'width' => 1.6], ['label' => 'Reviewed By', 'width' => 2.4],
            ], 'rows' => $rows],
            ['title' => 'Patients, cases and doses per day', 'columns' => [
                ['label' => 'Date', 'width' => 2], ['label' => 'Items Closed', 'width' => 1, 'align' => 'R'],
                ['label' => 'Patients', 'width' => 1, 'align' => 'R'], ['label' => 'Animal Bite Cases', 'width' => 1.3, 'align' => 'R'],
                ['label' => 'Doses Administered', 'width' => 1.3, 'align' => 'R'],
            ], 'rows' => $dayRows],
        ],
        'notes' => ['Ending = Beginning + Delivery - Consumed - Pull-out. Variance = Actual - Ending. Wastage is part of Consumed.',
            'Patients, cases and doses are the day counts used by Daily Inventory and the forecasting dataset.'],
    ];
}

/* ------------------------------------------------------------------
 * 5. WASTAGE REPORT
 * ---------------------------------------------------------------- */
function reportWastage(mysqli $conn, string $branchId, string $from, string $to): array
{
    $stmt = $conn->prepare(
        "SELECT h.usage_id, h.usage_date, h.quantity_used, h.wastage_reason, h.usage_source, h.recorded_at, h.item_id,
                i.item_name, COALESCE(NULLIF(TRIM(i.base_unit_label), ''), un.unit_name) AS base_unit_label,
                COALESCE(NULLIF(TRIM(i.display_unit_label), ''), un.unit_name) AS display_unit_label,
                COALESCE(NULLIF(i.conversion_to_base, 0), 1) AS conversion_to_base, u.username
         FROM inventory_usage_history h
         JOIN inventory_items i ON i.item_id = h.item_id
         JOIN units un ON un.unit_id = i.unit_id
         LEFT JOIN users u ON u.user_id = h.recorded_by
         WHERE h.branch_id = ? AND h.usage_type = 'Wastage' AND h.usage_date BETWEEN ? AND ?
         ORDER BY h.usage_date, i.item_name, h.usage_id"
    );
    $stmt->bind_param('sss', $branchId, $from, $to);
    $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $rows = [];
    $byItem = [];
    $byReason = [];
    foreach ($entries as $e) {
        $reason = trim((string)($e['wastage_reason'] ?? '')) ?: 'Not stated';
        $rows[] = [reportDate($e['usage_date']), (string)$e['item_name'], reportQty($e['quantity_used'], $e), $reason,
            (string)($e['username'] ?? ''), (string)($e['usage_source'] ?? '')];
        $key = (int)$e['item_id'];
        $byItem[$key] = $byItem[$key] ?? ['row' => $e, 'count' => 0, 'qty' => 0.0];
        $byItem[$key]['count']++;
        $byItem[$key]['qty'] += (float)$e['quantity_used'];
        $byReason[$reason][$e['item_name']] = ($byReason[$reason][$e['item_name']] ?? 0) + 1;
    }
    $itemRows = [];
    foreach ($byItem as $entry) {
        $itemRows[] = [(string)$entry['row']['item_name'], (string)$entry['count'], reportQty($entry['qty'], $entry['row'])];
    }
    $reasonRows = [];
    foreach ($byReason as $reason => $items) {
        $reasonRows[] = [$reason, (string)array_sum($items), implode(', ', array_keys($items))];
    }
    return [
        'summary' => ['Wastage entries' => count($rows), 'Items' => count($byItem), 'Reasons' => count($byReason)],
        'sections' => [
            ['title' => 'Wastage entries', 'columns' => [['label' => 'Date', 'width' => 1.4], ['label' => 'Item', 'width' => 2],
                ['label' => 'Quantity', 'width' => 1.5, 'align' => 'R'], ['label' => 'Reason', 'width' => 2.4],
                ['label' => 'Recorded By', 'width' => 1.5], ['label' => 'Source', 'width' => 1.3]], 'rows' => $rows],
            ['title' => 'Total by item', 'columns' => [['label' => 'Item', 'width' => 3], ['label' => 'Entries', 'width' => 1, 'align' => 'R'],
                ['label' => 'Total Quantity', 'width' => 2, 'align' => 'R']], 'rows' => $itemRows],
            ['title' => 'By reason', 'columns' => [['label' => 'Reason', 'width' => 3], ['label' => 'Entries', 'width' => 1, 'align' => 'R'],
                ['label' => 'Items', 'width' => 4]], 'rows' => $reasonRows],
        ],
        'notes' => ['Wastage is recorded by the Nurse in the daily closing and counts as Consumed in Daily Inventory.'],
    ];
}

/* ------------------------------------------------------------------
 * 6. FORECAST REPORT (also the Supply Forecasting export)
 * ---------------------------------------------------------------- */
function reportForecast(mysqli $conn, string $branchId, string $from, string $to, ?int $horizon = null): array
{
    $horizons = $horizon !== null ? [$horizon] : [7, 14, 30];
    $sections = [];
    $summary = [];
    $legendCounts = ['Shortage Risk' => 0, 'Reorder Needed' => 0, 'Sufficient' => 0];
    foreach ($horizons as $h) {
        $rows = [];
        $counts = ['Shortage Risk' => 0, 'Reorder Needed' => 0, 'Sufficient' => 0];
        $generated = [];
        $periods = [];
        foreach (forecastStatusLatestRows($conn, $branchId, $h) as $f) {
            $status = (string)$f['forecast_status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $legendCounts[$status] = ($legendCounts[$status] ?? 0) + 1;
            $generated[(string)$f['forecast_date']] = true;
            $periods[reportDate($f['forecast_start_date']) . ' – ' . reportDate($f['forecast_end_date'])] = true;
            $reorder = max(0.0, (float)$f['recommended_reorder']);
            $rows[] = [
                (string)$f['item_name'],
                inventoryCountingUnit($f),
                inventoryQuantityText(max(0.0, (float)$f['current_stock_snapshot']), $f),
                inventoryQuantityText(max(0.0, (float)$f['minimum_stock_snapshot']), $f),
                (string)($f['stock_status'] ?? ''),
                inventoryForecastQuantityText(max(0.0, (float)$f['forecasted_consumption']), $f),
                forecastMethodLabel($f['forecast_method'] ?? null),
                number_format((float)$f['shortage_probability'], 1) . '%',
                $status,
                $reorder > 0 ? inventoryReorderText($reorder, $f) : 'No reorder',
            ];
        }
        // Saved results that no longer match live stock or minimum stock are
        // not shown (the Supply Forecasting page refreshes them); say so.
        $current = forecastStatusCurrentSql('fr');
        $stmt = $conn->prepare("SELECT COUNT(*) AS total, MAX(fr.forecast_date) AS latest FROM forecast_results fr
            WHERE fr.branch_id = ? AND fr.forecast_days = ? AND COALESCE(($current), 0) = 0
              AND fr.forecast_date = (SELECT MAX(fr3.forecast_date) FROM forecast_results fr3 WHERE fr3.branch_id = fr.branch_id AND fr3.forecast_days = fr.forecast_days)");
        $stmt->bind_param('si', $branchId, $h);
        $stmt->execute();
        $outdated = $stmt->get_result()->fetch_assoc() ?: ['total' => 0, 'latest' => null];
        $stmt->close();
        $outdatedText = (int)$outdated['total'] > 0
            ? (int)$outdated['total'] . ' saved result(s) from ' . reportDate($outdated['latest']) . ' are out of date (stock or minimum stock changed after they were saved). Open Supply Forecasting to refresh them.'
            : '';
        $summary[$h . '-day forecast'] = $rows
            ? 'saved ' . implode(', ', array_map(static fn($d) => reportDate($d), array_keys($generated)))
            : ($outdatedText !== '' ? 'out of date' : 'none saved');
        $sections[] = [
            'title' => $h . '-day forecast' . ($periods ? ' (period ' . implode('; ', array_keys($periods)) . ')' : '')
                . ' — Shortage Risk ' . $counts['Shortage Risk'] . ', Reorder Needed ' . $counts['Reorder Needed'] . ', Sufficient ' . $counts['Sufficient'],
            'columns' => [['label' => 'Item', 'width' => 2.4], ['label' => 'Unit', 'width' => 1.1], ['label' => 'Stock at Forecast', 'width' => 1.9, 'align' => 'R'],
                ['label' => 'Minimum Stock', 'width' => 1.7, 'align' => 'R'], ['label' => 'Stock Status', 'width' => 1.4],
                ['label' => 'Forecasted Consumption', 'width' => 1.9, 'align' => 'R'], ['label' => 'Method', 'width' => 1.8],
                ['label' => 'Stockout Risk', 'width' => 1.2, 'align' => 'R'], ['label' => 'Status', 'width' => 1.5],
                ['label' => 'Recommended Reorder', 'width' => 1.8, 'align' => 'R']],
            'rows' => $rows,
            'empty' => $outdatedText !== '' ? $outdatedText : 'No saved ' . $h . '-day forecast for this branch.',
        ];
    }
    $summary = ['Shortage Risk' => (string)$legendCounts['Shortage Risk'], 'Reorder Needed' => (string)$legendCounts['Reorder Needed'],
        'Sufficient' => (string)$legendCounts['Sufficient']] + $summary;
    $policy = forecastStatusPolicy();
    return [
        'title' => $horizon !== null ? 'Supply Forecast Report (' . $horizon . ' days)' : null,
        'summary' => $summary,
        'summary_title' => 'Status counts and generation dates',
        'sections' => $sections,
        'notes_title' => 'Legend',
        'notes' => [
            'Shortage Risk: stock is zero, runs out within the horizon, or covers fewer than ' . ($policy['restocking_lead_days'] + $policy['safety_buffer_days']) . ' days (lead time plus safety buffer).',
            'Reorder Needed: forecasted demand would leave less than the minimum stock, with no shortage warning.',
            'Sufficient: stock covers the forecasted demand and stays at or above the minimum.',
            'Recommended Reorder is rounded up to whole vials, ampules or pieces. Stockout Risk is an approximate estimate and does not decide the status.',
            'These are the latest saved forecast results; exporting does not run a new forecast.',
        ],
    ];
}

/* ------------------------------------------------------------------
 * 7. PHILHEALTH STATUS REPORT
 * ---------------------------------------------------------------- */
function reportPhilhealthStatus(mysqli $conn, string $branchId, string $from, string $to): array
{
    $reqFields = function_exists('philhealthRequirementFields') ? philhealthRequirementFields() : [];
    $reqReady = $reqFields && workflowColumnExists($conn, 'philhealth_records', (string)array_key_first($reqFields));
    $stmt = $conn->prepare(
        "SELECT ph.*, c.case_number, DATE(c.created_at) AS admission_date, c.date_of_bite, p.full_name, b.branch_name, u.username AS updated_by_name
         FROM philhealth_records ph
         JOIN animal_bite_cases c ON c.case_id = ph.case_id
         JOIN patients p ON p.patient_id = c.patient_id
         LEFT JOIN branches b ON b.branch_id = c.branch_id
         LEFT JOIN users u ON u.user_id = ph.updated_by
         WHERE c.branch_id = ? AND ph.has_philhealth = 'Yes' AND ph.is_archived = 0 AND c.is_archived = 0
           AND COALESCE(ph.service_date, DATE(c.created_at)) BETWEEN ? AND ?
         ORDER BY FIELD(COALESCE(ph.status, ''), 'Returned for Correction', 'For Writing', 'For Screening', 'Ready for Main Branch',
                  'Sent to Main Branch', 'Submitted to PhilHealth', 'Denied', 'Reimbursed', 'Completed'),
                  COALESCE(ph.service_date, DATE(c.created_at)), c.case_number"
    );
    $stmt->bind_param('sss', $branchId, $from, $to);
    $stmt->execute();
    $claims = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $rows = [];
    $counts = [];
    foreach ($claims as $c) {
        $status = trim((string)($c['status'] ?? '')) ?: 'No status yet';
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        $req = '';
        if ($reqReady) {
            $have = 0;
            foreach (array_keys($reqFields) as $f) $have += !empty($c[$f]) ? 1 : 0;
            $req = $have . ' of ' . count($reqFields);
        }
        $rows[] = [
            (string)$c['case_number'], (string)$c['full_name'], (string)($c['philhealth_membership'] ?? ''), $status,
            reportDate($c['service_date'] ?? $c['admission_date']), $req,
            reportDate($c['date_sent_to_main'] ?? null), reportDate($c['date_submitted_philhealth'] ?? null),
            reportDate($c['date_returned'] ?? null), reportDate($c['date_resolved'] ?? null),
            trim((string)($c['updated_by_name'] ?? '') . ($c['updated_at'] ? ', ' . reportDate($c['updated_at']) : ''), ', '),
            (string)($c['remarks'] ?? ''),
        ];
    }
    $countRows = [];
    foreach ($counts as $status => $n) $countRows[] = [$status, (string)$n];
    return [
        'summary' => ['Claims' => count($rows)] + array_map('strval', $counts),
        'sections' => [
            ['title' => 'Claims by status', 'columns' => [['label' => 'Status', 'width' => 3], ['label' => 'Claims', 'width' => 1, 'align' => 'R']],
                'rows' => $countRows, 'totals' => ['Total', (string)count($rows)]],
            ['title' => 'Claims', 'columns' => [['label' => 'Case No.', 'width' => 1.4], ['label' => 'Patient', 'width' => 2.2],
                ['label' => 'Membership', 'width' => 1.5], ['label' => 'Status', 'width' => 1.8], ['label' => 'Service Date', 'width' => 1.4],
                ['label' => 'Requirements', 'width' => 1.1, 'align' => 'C'], ['label' => 'Sent to Main', 'width' => 1.4],
                ['label' => 'Submitted', 'width' => 1.4], ['label' => 'Returned', 'width' => 1.4], ['label' => 'Resolved', 'width' => 1.4],
                ['label' => 'Last Update', 'width' => 2], ['label' => 'Remarks', 'width' => 2.4]], 'rows' => $rows],
        ],
        'notes' => ['Claims are cases with PhilHealth = Yes. The date range uses the service date (admission date when there is none).'],
    ];
}

/* ------------------------------------------------------------------
 * 8. DATA QUALITY REPORT
 * ---------------------------------------------------------------- */
function reportDataQuality(mysqli $conn, string $branchId, string $from, string $to, string $linkBase): array
{
    $stmt = $conn->prepare(
        "SELECT c.case_id, c.case_number, c.patient_id, DATE(c.created_at) AS admission_date, c.case_status,
                " . patientClinicalSelectSql($conn, 'c', 'la') . ",
                " . patientCaseStatusSql('c') . " AS shared_status,
                p.full_name, p.birthday, p.gender, p.address, p.contact_number,
                r.active_regimen AS registry_regimen
         FROM animal_bite_cases c
         JOIN patients p ON p.patient_id = c.patient_id
         " . patientLatestAssessmentJoinSql('c', 'la') . "
         LEFT JOIN registry_records r ON r.case_id = c.case_id AND r.is_archived = 0
         WHERE c.branch_id = ? AND c.is_archived = 0 AND p.is_archived = 0
           AND DATE(c.created_at) BETWEEN ? AND ?
         ORDER BY DATE(c.created_at), c.case_number"
    );
    $stmt->bind_param('sss', $branchId, $from, $to);
    $stmt->execute();
    $cases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $problems = [];
    $rows = [];
    $seenPatients = [];
    $today = date('Y-m-d');
    foreach ($cases as $c) {
        $issues = [];
        $patientId = (int)$c['patient_id'];
        if (!isset($seenPatients[$patientId])) {
            $seenPatients[$patientId] = true;
            $dob = (string)($c['birthday'] ?? '');
            if ($dob === '' || str_starts_with($dob, '0000')) $issues[] = 'No date of birth';
            elseif ($dob > $today || $dob < '1900-01-01') $issues[] = 'Invalid date of birth';
            $contact = trim((string)($c['contact_number'] ?? ''));
            if ($contact === '') $issues[] = 'No contact number';
            elseif (!preg_match('/^(\+?63|0)?9\d{9}$/', preg_replace('/[\s\-()]/', '', $contact))
                && !preg_match('/^\d{7,8}$/', preg_replace('/[\s\-()]/', '', $contact))) $issues[] = 'Invalid contact number';
            if (trim((string)($c['address'] ?? '')) === '') $issues[] = 'No address';
            if (!in_array((string)($c['gender'] ?? ''), ['Male', 'Female'], true)) $issues[] = 'No gender';
        }
        $active = in_array((string)$c['shared_status'], ['Ongoing', 'Active', 'Scheduled'], true) || $c['case_status'] === 'Ongoing';
        if (trim((string)($c['bite_category'] ?? '')) === '') $issues[] = 'No category';
        if (trim((string)($c['bite_location'] ?? '')) === '') $issues[] = 'No bite site';
        if (empty($c['date_of_bite'])) $issues[] = 'No date of bite';
        elseif ((string)$c['date_of_bite'] > (string)$c['admission_date']) $issues[] = 'Date of bite after admission';
        if (trim((string)($c['animal_type'] ?? '')) === '') $issues[] = 'No biting animal';
        if ($active && trim((string)($c['treatment_profile'] ?? '')) === '' && trim((string)($c['registry_regimen'] ?? '')) === '') {
            $issues[] = 'No regimen on an active case';
        }
        if (!$issues) continue;
        foreach ($issues as $issue) $problems[$issue] = ($problems[$issue] ?? 0) + 1;
        // link_base is a page with {case_id} / {patient} placeholders, or a page
        // that opens the case for editing with ?edit_case=ID.
        $url = $linkBase === '' ? '' : (str_contains($linkBase, '{')
            ? strtr($linkBase, ['{case_id}' => (string)(int)$c['case_id'], '{patient}' => rawurlencode((string)$c['full_name'])])
            : $linkBase . (str_contains($linkBase, '?') ? '&' : '?') . 'edit_case=' . (int)$c['case_id']);
        $rows[] = [
            (string)$c['case_number'], (string)$c['full_name'], reportDate($c['admission_date']), (string)$c['shared_status'],
            implode('; ', $issues),
            $url !== '' ? ['text' => 'Fix', 'url' => $url] : '',
        ];
    }
    arsort($problems);
    $problemRows = [];
    foreach ($problems as $issue => $n) $problemRows[] = [$issue, (string)$n];
    return [
        'summary' => ['Cases checked' => count($cases), 'Cases with problems' => count($rows), 'Problems found' => array_sum($problems)],
        'sections' => [
            ['title' => 'Count per problem', 'columns' => [['label' => 'Problem', 'width' => 3], ['label' => 'Count', 'width' => 1, 'align' => 'R']],
                'rows' => $problemRows, 'totals' => ['Total', (string)array_sum($problems)], 'empty' => 'No problems found.'],
            ['title' => 'Records to fix', 'columns' => [['label' => 'Case No.', 'width' => 1.4], ['label' => 'Patient', 'width' => 2.4],
                ['label' => 'Admission', 'width' => 1.4], ['label' => 'Case Status', 'width' => 1.4], ['label' => 'Problems', 'width' => 5],
                ['label' => 'Fix', 'width' => 0.8, 'screen_only' => true]], 'rows' => $rows, 'empty' => 'No problems found.'],
        ],
        'notes' => ['Patient fields are checked once per patient; case fields for each case admitted in the period.',
            'Clinical fields use the latest Nurse assessment, with the intake case as fallback (the same values every page shows).',
            'A valid contact number is a Philippine mobile number (09XXXXXXXXX or +639XXXXXXXXX) or a 7-8 digit landline.'],
    ];
}
