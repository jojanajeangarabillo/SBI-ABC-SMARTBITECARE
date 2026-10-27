<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    echo "Usage: php sources/migrate_inventory_approvals.php [--apply]\nDefault: read-only preview.\n";
    exit;
}
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
/*
 * Inventory approval controls (one person requests, the Branch Admin approves).
 * Additive only:
 *   - new table inventory_approval_requests (one row per request, every type)
 *   - stock_transactions.approval_request_id: the request a movement came from
 *     (approved adjustment, stock-out, disposal, or a stock-in that fulfils a
 *     restock request). Existing transactions keep NULL ("No request").
 *   - daily_inventory_closings.status gains 'Returned' (Branch Admin returned the
 *     closing for a recount) and review_remarks keeps the reviewer's remark.
 * No stock, usage, closing values or training_dataset rows are changed.
 */
$completed = 0;
try {
    $columns = static function (string $table) use ($conn): array {
        $stmt = $conn->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_column($rows, 'COLUMN_TYPE', 'COLUMN_NAME');
    };
    $hasIndex = static function (string $table, string $index) use ($conn): bool {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->bind_param('ss', $table, $index);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total > 0;
    };
    foreach (['stock_transactions', 'daily_inventory_closings', 'inventory_items', 'branches', 'users'] as $required) {
        if (!$columns($required)) throw new RuntimeException("Missing table $required. Complete the existing database setup first.");
    }

    $plan = [];
    if (!$columns('inventory_approval_requests')) {
        $plan[] = ['Create table inventory_approval_requests', "CREATE TABLE inventory_approval_requests (
            request_id INT NOT NULL AUTO_INCREMENT,
            request_type VARCHAR(30) NOT NULL,
            branch_id VARCHAR(10) NOT NULL,
            item_id INT NULL DEFAULT NULL,
            stock_id INT NULL DEFAULT NULL,
            batch_snapshot VARCHAR(100) NULL DEFAULT NULL,
            item_name_snapshot VARCHAR(255) NULL DEFAULT NULL,
            quantity DECIMAL(12,4) NULL DEFAULT NULL,
            approved_quantity DECIMAL(12,4) NULL DEFAULT NULL,
            fulfilled_quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
            unit_snapshot VARCHAR(100) NULL DEFAULT NULL,
            reason VARCHAR(255) NULL DEFAULT NULL,
            remarks TEXT NULL,
            payload TEXT NULL,
            status ENUM('Pending','Approved','Rejected','Cancelled','Fulfilled') NOT NULL DEFAULT 'Pending',
            source VARCHAR(30) NULL DEFAULT NULL,
            requested_by INT NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            decision_remarks TEXT NULL,
            cancelled_at DATETIME NULL DEFAULT NULL,
            fulfilled_at DATETIME NULL DEFAULT NULL,
            result_transaction_id INT NULL DEFAULT NULL,
            result_reference VARCHAR(100) NULL DEFAULT NULL,
            open_key VARCHAR(80) NULL DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (request_id),
            UNIQUE KEY uq_approval_open_key (open_key),
            KEY idx_approval_branch_status (branch_id, status, requested_at),
            KEY idx_approval_requester (requested_by, requested_at),
            KEY idx_approval_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
    }

    $stockColumns = $columns('stock_transactions');
    if (!isset($stockColumns['approval_request_id'])) {
        $plan[] = ['Add stock_transactions.approval_request_id',
            'ALTER TABLE stock_transactions ADD COLUMN approval_request_id INT NULL DEFAULT NULL'];
    }
    if (!$hasIndex('stock_transactions', 'idx_stock_approval_request')) {
        $plan[] = ['Add index stock_transactions.idx_stock_approval_request',
            'ALTER TABLE stock_transactions ADD INDEX idx_stock_approval_request (approval_request_id)'];
    }

    $closingColumns = $columns('daily_inventory_closings');
    $statusType = strtolower((string)($closingColumns['status'] ?? ''));
    if (!str_contains($statusType, "'returned'")) {
        $plan[] = ["Allow daily_inventory_closings.status 'Returned' (existing values kept)",
            "ALTER TABLE daily_inventory_closings MODIFY COLUMN status
             ENUM('Draft','Submitted','Reviewed','Returned') NOT NULL DEFAULT 'Submitted'"];
    }
    if (!isset($closingColumns['review_remarks'])) {
        $plan[] = ['Add daily_inventory_closings.review_remarks',
            'ALTER TABLE daily_inventory_closings ADD COLUMN review_remarks TEXT NULL'];
    }

    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) {
            if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label . ': ' . $conn->error);
            $completed++;
        }
    }
    if ($apply) echo "Inventory approval controls are ready. Existing stock, closings and training data were retained.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Inventory approval migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some steps completed; correct the problem and rerun. DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n"));
    exit(1);
}
