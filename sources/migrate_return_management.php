<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true)) {
    echo "Usage: php sources/migrate_return_management.php [--apply]\n"
        . "Default: read-only preview. --apply: apply the planned returns schema changes.\n";
    exit(0);
}
foreach ($arguments as $argument) {
    if ($argument !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
}
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/return_management_helper.php';
$completed = 0;

try {
    $apply = in_array('--apply', $arguments, true);
    $columns = returnManagementColumns($conn);
    $plan = [];
    if (!$columns) {
        $plan[] = ['Create inventory_returns with the delivery workflow and optional inventory references',
            "CREATE TABLE inventory_returns (
                return_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                return_number VARCHAR(30) DEFAULT NULL,
                branch_id VARCHAR(10) NOT NULL,
                destination_branch_id VARCHAR(10) NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                unit_id INT NOT NULL,
                quantity DECIMAL(12,2) NOT NULL,
                return_reason VARCHAR(100) NOT NULL,
                remarks TEXT DEFAULT NULL,
                transportation_details TEXT DEFAULT NULL,
                status ENUM('In Transit','Received') NOT NULL DEFAULT 'In Transit',
                created_by INT NOT NULL,
                sent_at DATETIME DEFAULT NULL,
                received_at DATETIME DEFAULT NULL,
                processed_by INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                item_id INT DEFAULT NULL,
                stock_id INT DEFAULT NULL,
                stock_transaction_id INT DEFAULT NULL,
                inventory_quantity_snapshot DECIMAL(12,4) DEFAULT NULL,
                inventory_base_unit_snapshot VARCHAR(100) DEFAULT NULL,
                source_batch_snapshot VARCHAR(100) DEFAULT NULL,
                legacy_status VARCHAR(100) DEFAULT NULL,
                UNIQUE KEY uq_inventory_returns_number (return_number),
                UNIQUE KEY uq_inventory_returns_receipt (stock_transaction_id),
                KEY idx_returns_branch_status (branch_id, status),
                KEY idx_returns_destination (destination_branch_id),
                KEY idx_returns_item (item_id), KEY idx_returns_stock (stock_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"];
    } else {
        // Preflight every unsafe case before executing any DDL. Never fabricate
        // missing branch, item name, unit, quantity, actor or timestamp data.
        foreach (['return_id','return_number','branch_id','destination_branch_id','item_name','unit_id',
            'quantity','return_reason','remarks','status','created_by','created_at','updated_at'] as $required) {
            if (!isset($columns[$required])) throw new RuntimeException('An existing returns table is missing ' . $required . '. Review that older schema before migrating.');
        }
        if (strtolower($columns['quantity']['COLUMN_TYPE']) !== 'decimal(12,2)') {
            throw new RuntimeException('The existing return quantity column differs from DECIMAL(12,2). Review its precision before migrating.');
        }
        $optional = [
            'transportation_details' => 'TEXT DEFAULT NULL',
            'sent_at' => 'DATETIME DEFAULT NULL', 'received_at' => 'DATETIME DEFAULT NULL',
            'processed_by' => 'INT DEFAULT NULL', 'item_id' => 'INT DEFAULT NULL',
            'stock_id' => 'INT DEFAULT NULL', 'stock_transaction_id' => 'INT DEFAULT NULL',
            'inventory_quantity_snapshot' => 'DECIMAL(12,4) DEFAULT NULL',
            'inventory_base_unit_snapshot' => 'VARCHAR(100) DEFAULT NULL',
            'source_batch_snapshot' => 'VARCHAR(100) DEFAULT NULL',
            'legacy_status' => 'VARCHAR(100) DEFAULT NULL',
        ];
        foreach ($optional as $name => $definition) {
            if (!isset($columns[$name])) $plan[] = ['Add ' . $name,
                "ALTER TABLE inventory_returns ADD COLUMN `$name` $definition"];
        }
        if (isset($columns['inventory_quantity_snapshot'])
            && strtolower($columns['inventory_quantity_snapshot']['COLUMN_TYPE']) !== 'decimal(12,4)') {
            throw new RuntimeException('The existing inventory quantity snapshot has a different precision. No automatic conversion is performed.');
        }
        $oldStatuses = ['Pending','Ready to Send','Sent to Main Branch','Transferred','Resolved','Complete'];
        $known = array_merge($oldStatuses, ['In Transit','Received']);
        $statuses = $conn->query('SELECT DISTINCT status FROM inventory_returns')->fetch_all(MYSQLI_ASSOC);
        foreach ($statuses as $row) {
            if (!in_array($row['status'], $known, true)) throw new RuntimeException('An existing return has an unrecognized status. Review it before migrating.');
        }
        $type = $columns['status']['COLUMN_TYPE'];
        if (stripos($type, 'enum(') !== 0) throw new RuntimeException('The returns status column is not the expected ENUM. Review the older schema first.');
        if (strpos($type, "'In Transit'") === false || strpos($type, "'Received'") === false) {
            $expanded = substr($type, 0, -1);
            if (strpos($type, "'In Transit'") === false) $expanded .= ",'In Transit'";
            if (strpos($type, "'Received'") === false) $expanded .= ",'Received'";
            $expanded .= ')';
            $plan[] = ['Add delivery statuses while retaining existing ENUM values',
                "ALTER TABLE inventory_returns MODIFY status $expanded NOT NULL DEFAULT 'In Transit'"];
        }
        $legacyCount = (int)$conn->query("SELECT COUNT(*) total FROM inventory_returns
            WHERE status IN ('Pending','Ready to Send','Sent to Main Branch','Transferred','Resolved','Complete')")->fetch_assoc()['total'];
        if ($legacyCount > 0) {
            $plan[] = ['Preserve and map ' . $legacyCount . ' legacy statuses; retain recorded timestamps',
                "UPDATE inventory_returns SET legacy_status = COALESCE(legacy_status, status),
                 status = CASE WHEN status IN ('Resolved','Complete') THEN 'Received' ELSE 'In Transit' END,
                 updated_at = updated_at
                 WHERE status IN ('Pending','Ready to Send','Sent to Main Branch','Transferred','Resolved','Complete')"];
        }
        foreach (['return_number' => 'uq_inventory_returns_number',
                  'stock_transaction_id' => 'uq_inventory_returns_receipt'] as $field => $index) {
            if (isset($columns[$field])) {
                $duplicates = $conn->query("SELECT COUNT(*) total FROM (SELECT `$field` FROM inventory_returns
                    WHERE `$field` IS NOT NULL GROUP BY `$field` HAVING COUNT(*) > 1) duplicates")->fetch_assoc()['total'];
                if ((int)$duplicates > 0) throw new RuntimeException('Duplicate existing ' . $field . ' references need manual review before migration. No records were changed.');
            }
            if (!isset($columns[$field]) || !returnManagementUniqueIndex($conn, $field)) {
                $nameExists = $conn->query("SELECT COUNT(*) total FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_returns' AND INDEX_NAME = '$index'")->fetch_assoc()['total'];
                if ((int)$nameExists > 0) throw new RuntimeException('An existing index named ' . $index . ' differs from the required unique index. Review it before migration.');
                $plan[] = ['Require one return per ' . $field,
                    "ALTER TABLE inventory_returns ADD UNIQUE KEY `$index` (`$field`)"];
            }
        }
    }
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) {
            if (!$conn->query($sql)) throw new RuntimeException('Unable to apply: ' . $label);
            $completed++;
        }
    }
    if ($apply) {
        returnManagementAssertSchema($conn);
        echo "Return Management setup is ready. Existing records and legacy columns were retained.\n";
    } elseif ($plan) {
        echo "No data or structure was changed. Run again with --apply to execute this plan.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Returns migration stopped: ' . $e->getMessage() . "\n");
    fwrite(STDERR, $completed > 0
        ? "Some migration steps were applied. DDL cannot roll back as one transaction. Correct the reported problem and rerun; completed schema steps are detected.\n"
        : "No migration changes were applied.\n");
    exit(1);
}
