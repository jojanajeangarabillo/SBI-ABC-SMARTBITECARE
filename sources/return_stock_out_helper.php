<?php
declare(strict_types=1);
require_once __DIR__ . '/return_management_helper.php';

function returnStockOutAssertSchema(mysqli $conn): void
{
    $columns=returnManagementColumns($conn);
    foreach (['sent_stock_transaction_id','stock_out_stock_id','stock_out_batch_snapshot'] as $name) {
        if (!isset($columns[$name])) throw new RuntimeException('Return Stock Out setup is incomplete. Run migrate_return_stock_out.php before opening this page.');
    }
    if (!returnManagementUniqueIndex($conn,'sent_stock_transaction_id')) {
        throw new RuntimeException('Return Stock Out setup is incomplete. Run migrate_return_stock_out.php before opening this page.');
    }
}

/** Caller owns the transaction. Stock Out is separate from physical receipt confirmation. */
function returnStockOutRecord(mysqli $conn, array $officer, int $returnId, string $mode,
    ?int $stockId, ?int $transactionId, string $date): array
{
    $branch=(string)$officer['branch_id']; $userId=(int)$officer['user_id'];
    returnManagementRequireActor($conn,$userId,$branch);
    if (!in_array($mode,['record','link'],true)) throw new RuntimeException('Choose Record Stock Out or Link Existing Stock Out.');
    $stmt=returnManagementStatement($conn,'SELECT * FROM inventory_returns WHERE return_id=? AND branch_id=? FOR UPDATE');
    $stmt->bind_param('is',$returnId,$branch); returnManagementExecute($stmt);
    $return=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$return) throw new RuntimeException('Only the sending branch can record this return Stock Out.');
    if ($return['sent_stock_transaction_id'] !== null) {
        if ($mode==='link' && $transactionId !== (int)$return['sent_stock_transaction_id']) throw new RuntimeException('This return already has a different Stock Out reference.');
        return ['already_recorded'=>true,'transaction_id'=>(int)$return['sent_stock_transaction_id']];
    }
    if (!in_array($return['status'],['In Transit','Received'],true) || !$return['item_id']
        || $return['inventory_quantity_snapshot'] === null || !$return['inventory_base_unit_snapshot']) {
        throw new RuntimeException('This delivery has no verified inventory item and quantity. Record its stock movement separately in Stock Management.');
    }
    $itemId=(int)$return['item_id'];
    $stmt=returnManagementStatement($conn,'SELECT i.*,u.unit_name FROM inventory_items i JOIN units u ON u.unit_id=i.unit_id WHERE i.item_id=? FOR UPDATE');
    $stmt->bind_param('i',$itemId); returnManagementExecute($stmt); $item=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$item || inventoryBaseUnitLabel($item)!==$return['inventory_base_unit_snapshot']) throw new RuntimeException('The inventory item or its base unit changed. Review the return before recording Stock Out.');
    $quantity=stockQuantityParse((string)$return['inventory_quantity_snapshot']);
    stockQuantityValidateUnit($quantity,$item);
    $base=stockQuantityDecimal($quantity);
    $number=$return['return_number'] ?: ('RET-'.$returnId);
    $stockSnapshot=null; $batch=null;
    if ($mode==='link') {
        if (!$transactionId) throw new RuntimeException('Select an existing Stock Out transaction.');
        $stmt=returnManagementStatement($conn,'SELECT * FROM stock_transactions WHERE transaction_id=? AND branch_id=? FOR UPDATE');
        $stmt->bind_param('is',$transactionId,$branch); returnManagementExecute($stmt);
        $transaction=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$transaction || !in_array($transaction['transaction_type'],['OUT','TRANSFER_OUT'],true)
            || (int)$transaction['item_id']!==$itemId || !returnManagementSameQuantity($transaction['quantity'],$base)) {
            throw new RuntimeException('Select an outgoing transaction for this sending branch, item and exact return quantity.');
        }
        $date=substr((string)$transaction['transaction_date'],0,10);
    } else {
        if (!$stockId) throw new RuntimeException('Select the sending stock batch.');
        $expected=$return['stock_id']===null ? null : (int)$return['stock_id'];
        if ($return['source_receipt_transaction_id']) {
            $receiptId=(int)$return['source_receipt_transaction_id'];
            $stmt=returnManagementStatement($conn,'SELECT receipt_stock_id FROM stock_transactions WHERE transaction_id=? AND branch_id=?');
            $stmt->bind_param('is',$receiptId,$branch); returnManagementExecute($stmt);
            $receipt=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($receipt && $receipt['receipt_stock_id']!==null) {
                if ($expected!==null && $expected!==(int)$receipt['receipt_stock_id']) throw new RuntimeException('The return batch and its original receipt disagree. Review the references.');
                $expected=(int)$receipt['receipt_stock_id'];
            }
        }
        if ($expected!==null && $expected!==$stockId) throw new RuntimeException('Use the batch recorded for this return and original receipt.');
        $stmt=returnManagementStatement($conn,'SELECT * FROM inventory_stocks WHERE stock_id=? AND item_id=? AND branch_id=? FOR UPDATE');
        $stmt->bind_param('iis',$stockId,$itemId,$branch); returnManagementExecute($stmt);
        $stock=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$stock) throw new RuntimeException('The selected stock does not belong to this item and sending branch.');
        $available=stockQuantityParse((string)$stock['quantity_available'],true);
        if ($available<$quantity) throw new RuntimeException('This batch has insufficient stock for the complete return quantity.');
        $remaining=stockQuantityDecimal($available-$quantity);
        $stockSnapshot=$stockId;
        $batch=trim((string)($stock['batch_lot_no'] ?? '')) ?: 'No lot number';
        $stmt=returnManagementStatement($conn,'UPDATE inventory_stocks SET quantity_available=? WHERE stock_id=? AND branch_id=?');
        $stmt->bind_param('sis',$remaining,$stockId,$branch); returnManagementExecute($stmt); $stmt->close();
        // Expired batches may physically leave as returns. This removes only
        // their recorded quantity and does not treat them as usable stock.
    }
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$parsed || $parsed->format('Y-m-d')!==$date || $date>date('Y-m-d')
        || $date<substr((string)($return['sent_at'] ?: $return['created_at']),0,10)) {
        throw new RuntimeException('Stock Out date must be on or after the shipment date and not in the future.');
    }
    if ($mode==='record') {
        $remarks='Return '.$number.' to branch '.$return['destination_branch_id'].' | Stock #'.$stockId
            .' | Batch/Lot: '.$batch.' | Reason: '.$return['return_reason'];
        $timestamp=$date.' 00:00:00';
        $stmt=returnManagementStatement($conn,"INSERT INTO stock_transactions (item_id,user_id,branch_id,transaction_type,quantity,remarks,transaction_date)
            VALUES (?,?,?,'OUT',?,?,?)");
        $stmt->bind_param('iissss',$itemId,$userId,$branch,$base,$remarks,$timestamp); returnManagementExecute($stmt);
        $transactionId=(int)$conn->insert_id; $stmt->close();
    }
    // Unique reference prevents one existing outbound transaction being reused.
    $stmt=returnManagementStatement($conn,'UPDATE inventory_returns SET sent_stock_transaction_id=?,stock_out_stock_id=?,stock_out_batch_snapshot=?
        WHERE return_id=? AND branch_id=? AND sent_stock_transaction_id IS NULL');
    $stmt->bind_param('iisis',$transactionId,$stockSnapshot,$batch,$returnId,$branch); returnManagementExecute($stmt);
    if ($stmt->affected_rows!==1) throw new RuntimeException('The return changed. Refresh and try again.');
    $stmt->close();
    $action=($mode==='record' ? 'Recorded' : 'Linked existing').' Stock Out #'.$transactionId.' for return '.$number
        .' | Quantity: '.stockQuantityText($quantity).' '.$return['inventory_base_unit_snapshot'];
    if (!inventoryOfficerRecordReturnActivity($conn,$userId,$branch,(string)$officer['branch_name'],(string)$officer['username'],
        $returnId,$number,$action,$action.'.','stock_out')) throw new RuntimeException('Unable to save the return Stock Out audit or Branch Admin notification.');
    $usableOut=$mode==='record' && (($stock['expiration_date'] ?? null)===null || $stock['expiration_date']>=$date);
    return ['already_recorded'=>false,'transaction_id'=>$transactionId,'quantity'=>$base,'date'=>$date,'mode'=>$mode,'usable_out'=>$usableOut];
}
