<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$args=array_slice($argv,1);
if (in_array('--help',$args,true)) { echo "Usage: php sources/migrate_return_stock_out.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg!=='--apply') { fwrite(STDERR,"Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__.'/db_connect.php';
require_once __DIR__.'/return_stock_out_helper.php';
$completed=0;
try {
    returnManagementAssertSchema($conn); stockReceiptSourceAssertSchema($conn);
    $columns=returnManagementColumns($conn); $plan=[];
    foreach (['sent_stock_transaction_id'=>'INT DEFAULT NULL','stock_out_stock_id'=>'INT DEFAULT NULL',
        'stock_out_batch_snapshot'=>'VARCHAR(100) DEFAULT NULL'] as $column=>$type) {
        if (!isset($columns[$column])) $plan[]=["Add $column","ALTER TABLE inventory_returns ADD COLUMN `$column` $type"];
    }
    if (isset($columns['sent_stock_transaction_id'])) {
        $duplicates=(int)$conn->query('SELECT COUNT(*) total FROM (SELECT sent_stock_transaction_id FROM inventory_returns
            WHERE sent_stock_transaction_id IS NOT NULL GROUP BY sent_stock_transaction_id HAVING COUNT(*)>1) duplicates')->fetch_assoc()['total'];
        if ($duplicates) throw new RuntimeException('Duplicate existing outbound references need review. No automatic reassignment is performed.');
    }
    if (!returnManagementUniqueIndex($conn,'sent_stock_transaction_id')) {
        $exists=(int)$conn->query("SELECT COUNT(*) total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='inventory_returns' AND INDEX_NAME='uq_return_sent_stock_transaction'")->fetch_assoc()['total'];
        if ($exists) throw new RuntimeException('An existing index with the required name differs. Review it before applying.');
        $plan[]=['Require one return per outbound stock transaction','ALTER TABLE inventory_returns ADD UNIQUE KEY uq_return_sent_stock_transaction (sent_stock_transaction_id)'];
    }
    $apply=in_array('--apply',$args,true); echo ($apply ? 'APPLY' : 'PREVIEW — read-only')."\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label,$sql]) { echo '- '.$label."\n"; if ($apply) { if (!$conn->query($sql)) throw new RuntimeException('Unable to apply '.$label); $completed++; } }
    if ($apply) { returnStockOutAssertSchema($conn); echo "Return Stock Out setup is ready. Existing stock quantities and return records were retained.\n"; }
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'Return Stock Out migration stopped: '.$e->getMessage()."\n".($completed ? "Some schema steps completed. Correct the problem and rerun; DDL cannot roll back as one transaction.\n" : "No migration changes were applied.\n")); exit(1);
}
