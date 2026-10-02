<?php
declare(strict_types=1);

require_once __DIR__ . '/flow_upgrade.php';
require_once __DIR__ . '/procurement.php';

function apply_workflow_cancellation(PDO $pdo, string $entityType, int $entityId, int $actorId): void
{
    if ($entityType === 'request') {
        $stmt=$pdo->prepare('SELECT * FROM material_requests WHERE id=? FOR UPDATE'); $stmt->execute([$entityId]); $row=$stmt->fetch();
        if(!$row || in_array((string)$row['status'],['completed','cancelled','rejected'],true)) throw new RuntimeException('This request cannot be cancelled.');
        $released=$pdo->prepare('SELECT COALESCE(SUM(released_qty),0) FROM material_request_items WHERE request_id=?'); $released->execute([$entityId]);
        if((float)$released->fetchColumn()>0) throw new RuntimeException('A request with released material cannot be voided. Use Return / Damage / discrepancy workflows instead.');
        $res=$pdo->prepare("SELECT mr.* FROM material_reservations mr JOIN material_request_items i ON i.id=mr.request_item_id WHERE i.request_id=? AND mr.status='active' FOR UPDATE"); $res->execute([$entityId]);
        foreach($res->fetchAll() as $r){ $pdo->prepare('UPDATE inventory_balances SET reserved_quantity=GREATEST(0,reserved_quantity-?) WHERE material_id=? AND location_id=?')->execute([(float)$r['reserved_qty']-(float)$r['released_qty'],(int)$r['material_id'],(int)$r['location_id']]); }
        $pdo->prepare("UPDATE material_reservations mr JOIN material_request_items i ON i.id=mr.request_item_id SET mr.status='cancelled' WHERE i.request_id=? AND mr.status='active'")->execute([$entityId]);
        $pdo->prepare("UPDATE material_request_items SET item_status='cancelled', shortage_qty=0 WHERE request_id=?")->execute([$entityId]);
        $pdo->prepare("UPDATE material_requests SET status='cancelled' WHERE id=?")->execute([$entityId]);
    } elseif ($entityType === 'purchase_order') {
        $stmt=$pdo->prepare('SELECT * FROM purchase_orders WHERE id=? FOR UPDATE'); $stmt->execute([$entityId]); $row=$stmt->fetch();
        if(!$row || !in_array((string)$row['status'],['submitted','open'],true)) throw new RuntimeException('Only an unreceived purchase order can be cancelled.');
        $received=$pdo->prepare('SELECT COALESCE(SUM(received_qty),0) FROM purchase_order_items WHERE purchase_order_id=?'); $received->execute([$entityId]); if((float)$received->fetchColumn()>0) throw new RuntimeException('A purchase order with received quantities cannot be voided.');
        $items=$pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id=? FOR UPDATE'); $items->execute([$entityId]);
        foreach($items->fetchAll() as $item){ if(!empty($item['purchase_request_item_id'])){ $pdo->prepare("UPDATE purchase_request_items SET ordered_qty=GREATEST(0,ordered_qty-?), status=CASE WHEN GREATEST(0,ordered_qty-?)<=0 THEN 'approved' ELSE 'partially_ordered' END WHERE id=?")->execute([(float)$item['ordered_qty'],(float)$item['ordered_qty'],(int)$item['purchase_request_item_id']]); }}
        $pdo->prepare("UPDATE purchase_order_items SET status='cancelled' WHERE purchase_order_id=?")->execute([$entityId]);
        $pdo->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=?")->execute([$entityId]);
        if(!empty($row['purchase_request_id'])) refresh_purchase_request_status($pdo,(int)$row['purchase_request_id']);
    } elseif ($entityType === 'transfer') {
        $stmt=$pdo->prepare('SELECT status FROM material_transfers WHERE id=? FOR UPDATE'); $stmt->execute([$entityId]); $status=(string)$stmt->fetchColumn();
        if(!in_array($status,['draft','submitted','approved'],true)) throw new RuntimeException('A dispatched transfer cannot be cancelled.');
        $pdo->prepare("UPDATE material_transfers SET status='cancelled' WHERE id=?")->execute([$entityId]); $pdo->prepare("UPDATE material_transfer_items SET status='cancelled' WHERE transfer_id=?")->execute([$entityId]);
    } elseif ($entityType === 'return') {
        $stmt=$pdo->prepare('SELECT status FROM material_returns WHERE id=? FOR UPDATE'); $stmt->execute([$entityId]); $status=(string)$stmt->fetchColumn();
        if(!in_array($status,['draft','submitted'],true)) throw new RuntimeException('A posted return cannot be cancelled.');
        $pdo->prepare("UPDATE material_returns SET status='cancelled' WHERE id=?")->execute([$entityId]); $pdo->prepare("UPDATE material_return_items SET status='cancelled' WHERE return_id=?")->execute([$entityId]);
    } elseif ($entityType === 'damage_report') {
        $stmt=$pdo->prepare('SELECT status FROM damage_reports WHERE id=? FOR UPDATE'); $stmt->execute([$entityId]); $status=(string)$stmt->fetchColumn();
        if(!in_array($status,['draft','submitted'],true)) throw new RuntimeException('An approved damage report cannot be cancelled.');
        $pdo->prepare("UPDATE damage_reports SET status='cancelled' WHERE id=?")->execute([$entityId]); $pdo->prepare("UPDATE damage_report_items SET status='cancelled' WHERE damage_report_id=?")->execute([$entityId]);
    } elseif ($entityType === 'stock_count') {
        $stmt=$pdo->prepare('SELECT status FROM stock_counts WHERE id=? FOR UPDATE'); $stmt->execute([$entityId]); $status=(string)$stmt->fetchColumn();
        if(!in_array($status,['draft','submitted','rejected'],true)) throw new RuntimeException('An approved/adjusted stock count cannot be cancelled.');
        $pdo->prepare("UPDATE stock_counts SET status='cancelled' WHERE id=?")->execute([$entityId]); $pdo->prepare("UPDATE stock_count_items SET status='cancelled' WHERE stock_count_id=?")->execute([$entityId]);
    } else {
        throw new RuntimeException('Unsupported cancellation record type.');
    }
    write_audit($pdo,$actorId,'void',$entityType,$entityId,'Approved cancellation/void applied.');
}

function approve_and_post_writeoff(PDO $pdo, int $id, int $actorId, string $remarks, bool $postNow=false): void
{
    $stmt=$pdo->prepare('SELECT * FROM disposal_writeoffs WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row=$stmt->fetch();
    if(!$row) throw new RuntimeException('Write-off record not found.');
    if(!$postNow){ if((string)$row['status']!=='submitted') throw new RuntimeException('Write-off is not awaiting approval.'); $pdo->prepare("UPDATE disposal_writeoffs SET status='approved',approved_by=?,approved_at=NOW(),approval_remarks=? WHERE id=?")->execute([$actorId,$remarks,$id]); write_audit($pdo,$actorId,'approve','writeoff',$id,$remarks); return; }
    if((string)$row['status']!=='approved') throw new RuntimeException('Approve the write-off before posting it.');
    $balance=ensure_inventory_balance($pdo,(int)$row['material_id'],(int)$row['location_id']); $available=available_stock((float)$balance['quantity_on_hand'],(float)$balance['reserved_quantity'],(float)$balance['damaged_quantity']);
    if((float)$row['quantity']>$available) throw new RuntimeException('Write-off exceeds current available stock.');
    post_inventory_movement($pdo,['movement_type'=>'stock_out','material_id'=>(int)$row['material_id'],'location_id'=>(int)$row['location_id'],'quantity_in'=>0,'quantity_out'=>(float)$row['quantity'],'reference_type'=>'writeoff','reference_id'=>$id,'remarks'=>$remarks?:'Approved disposal/write-off','created_by'=>$actorId]);
    $pdo->prepare("UPDATE disposal_writeoffs SET status='posted',posted_by=?,posted_at=NOW() WHERE id=?")->execute([$actorId,$id]);
    write_audit($pdo,$actorId,'post','writeoff',$id,$remarks!==''?$remarks:'Approved write-off posted to inventory.');
}

function ship_supplier_return(PDO $pdo, int $id, int $actorId, string $remarks): void
{
    $stmt=$pdo->prepare('SELECT * FROM return_to_supplier_records WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row=$stmt->fetch();
    if(!$row || (string)$row['status']!=='approved') throw new RuntimeException('Supplier return must be approved before shipment.');
    if(empty($row['location_id'])) throw new RuntimeException('Supplier return has no source warehouse.');
    $balance=ensure_inventory_balance($pdo,(int)$row['material_id'],(int)$row['location_id']); $available=available_stock((float)$balance['quantity_on_hand'],(float)$balance['reserved_quantity'],(float)$balance['damaged_quantity']);
    if((float)$row['quantity']>$available) throw new RuntimeException('Supplier return exceeds available stock.');
    post_inventory_movement($pdo,['movement_type'=>'stock_out','material_id'=>(int)$row['material_id'],'location_id'=>(int)$row['location_id'],'quantity_in'=>0,'quantity_out'=>(float)$row['quantity'],'reference_type'=>'return_to_supplier','reference_id'=>$id,'remarks'=>$remarks?:'Material shipped back to supplier','created_by'=>$actorId]);
    $pdo->prepare("UPDATE return_to_supplier_records SET status='shipped',shipped_by=?,shipped_at=NOW() WHERE id=?")->execute([$actorId,$id]);
    write_audit($pdo,$actorId,'ship','return_to_supplier',$id,$remarks!==''?$remarks:'Supplier return shipped and inventory deducted.');
}
