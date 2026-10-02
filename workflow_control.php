<?php
require_once __DIR__ . '/lib/high_value.php';
require_once __DIR__ . '/lib/workflow_control_actions.php';
require_any_permission(['manage_workflow_control','request_workflow_control','post_workflow_control']);
require_once __DIR__ . '/config/db.php';
$role = (string) ($_SESSION['role'] ?? '');
$canManage = role_can($role,'manage_workflow_control');
$canRequest = role_can($role,'request_workflow_control') || $canManage;
$canPost = role_can($role,'post_workflow_control') || $role === 'admin';

require_flow_upgrade($pdo, [['return_to_supplier_records','location_id'],['disposal_writeoffs','posted_at'],['workflow_cancellations','decision_remarks']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'');
    try {
        $pdo->beginTransaction();
        if($action==='cancel_request') {
            if(!$canManage) throw new RuntimeException('Only workflow control managers can request cancellation/void decisions.');
            $type=filter_choice($_POST,'entity_type',['request','purchase_order','transfer','return','damage_report','stock_count']);
            $id=max(1,(int)($_POST['entity_id']??0));
            $reason=trim((string)($_POST['reason']??'')); if($reason==='') throw new RuntimeException('Cancellation reason is required.');
            $pdo->prepare("INSERT INTO workflow_cancellations (entity_type,entity_id,reason,status,requested_by) VALUES (?,?,?,'requested',?)")->execute([$type,$id,$reason,(int)current_user()['id']]);
            $cancelId=(int)$pdo->lastInsertId();
            write_audit($pdo,(int)current_user()['id'],'request_void','workflow_cancellation',$cancelId,'Cancellation/void requested for '.$type.' #'.$id.'.');
            notify_role($pdo,'president','Workflow exception submitted','A cancellation/void request requires review.','workflow_control.php','warning');
            flash('success','Cancel/void request submitted for decision.');
        } elseif(in_array($action,['cancel_approve','cancel_reject'],true)) {
            if(!$canManage) throw new RuntimeException('You do not have permission to decide cancellations.');
            $id=(int)($_POST['id']??0); $remarks=trim((string)($_POST['remarks']??''));
            $stmt=$pdo->prepare('SELECT * FROM workflow_cancellations WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row=$stmt->fetch();
            if(!$row || (string)$row['status']!=='requested') throw new RuntimeException('Cancellation request is no longer pending.');
            if((int)$row['requested_by']===(int)current_user()['id']) throw new RuntimeException('You cannot approve or reject your own cancellation request.');
            if($action==='cancel_reject') {
                $pdo->prepare("UPDATE workflow_cancellations SET status='rejected',approved_by=?,approved_at=NOW(),decision_remarks=? WHERE id=?")->execute([(int)current_user()['id'],$remarks,$id]);
            } else {
                apply_workflow_cancellation($pdo,(string)$row['entity_type'],(int)$row['entity_id'],(int)current_user()['id']);
                $pdo->prepare("UPDATE workflow_cancellations SET status='voided',approved_by=?,approved_at=NOW(),decision_remarks=? WHERE id=?")->execute([(int)current_user()['id'],$remarks,$id]);
            }
            write_audit($pdo,(int)current_user()['id'],$action==='cancel_reject'?'reject_void':'approve_void','workflow_cancellation',$id,$remarks);
            flash('success',$action==='cancel_reject'?'Cancellation request rejected.':'Cancellation approved and void applied.');
        } elseif($action==='return_supplier') {
            if(!$canRequest) throw new RuntimeException('You do not have permission to submit a supplier return.');
            $supplierId=(int)($_POST['supplier_id']??0); $materialId=(int)($_POST['material_id']??0); $locationId=(int)($_POST['location_id']??0);
            $quantity=(float)($_POST['quantity']??0); $reason=trim((string)($_POST['reason']??''));
            if($quantity<=0) throw new RuntimeException('Return quantity must be greater than zero.');
            if($reason==='') throw new RuntimeException('Return reason is required.');
            $check=$pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE id=? AND is_active=1"); $check->execute([$supplierId]); if((int)$check->fetchColumn()!==1) throw new RuntimeException('Choose an active supplier.');
            $check=$pdo->prepare("SELECT COUNT(*) FROM locations WHERE id=? AND is_active=1 AND type='warehouse'"); $check->execute([$locationId]); if((int)$check->fetchColumn()!==1) throw new RuntimeException('Choose an active warehouse.');
            assert_operational_material($pdo,$materialId);
            $balance=ensure_inventory_balance($pdo,$materialId,$locationId);
            if($quantity>available_stock((float)$balance['quantity_on_hand'],(float)$balance['reserved_quantity'],(float)$balance['damaged_quantity'])) throw new RuntimeException('Supplier return exceeds current available warehouse stock.');
            $returnNo='RTS-'.date('YmdHis').'-'.random_int(10,99);
            $pdo->prepare("INSERT INTO return_to_supplier_records (return_no,supplier_id,material_id,location_id,quantity,reason,status,created_by) VALUES (?,?,?,?,?,?,'submitted',?)")
                ->execute([$returnNo,$supplierId,$materialId,$locationId,$quantity,$reason,(int)current_user()['id']]);
            $rtsId=(int)$pdo->lastInsertId();
            write_audit($pdo,(int)current_user()['id'],'submit','return_to_supplier',$rtsId,'Supplier return submitted.');
            flash('success','Return-to-supplier record submitted for approval.');
        } elseif(in_array($action,['rts_approve','rts_reject','rts_ship','rts_credit','rts_close'],true)) {
            $id=(int)($_POST['id']??0); $remarks=trim((string)($_POST['remarks']??''));
            $stmt=$pdo->prepare('SELECT * FROM return_to_supplier_records WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $row=$stmt->fetch(); if(!$row) throw new RuntimeException('Supplier return not found.');
            if(in_array($action,['rts_approve','rts_reject','rts_credit','rts_close'],true) && !$canManage) throw new RuntimeException('You do not have permission to manage supplier-return decisions.');
            if($action==='rts_ship' && !$canPost) throw new RuntimeException('Only authorized operational personnel can ship/post an approved supplier return.');
            if(in_array($action,['rts_approve','rts_reject'],true) && (int)$row['created_by']===(int)current_user()['id']) throw new RuntimeException('You cannot approve or reject your own supplier return request.');
            if($action==='rts_approve') { if((string)$row['status']!=='submitted') throw new RuntimeException('Supplier return is not awaiting approval.'); $pdo->prepare("UPDATE return_to_supplier_records SET status='approved',approved_by=?,approved_at=NOW(),approval_remarks=? WHERE id=?")->execute([(int)current_user()['id'],$remarks,$id]); write_audit($pdo,(int)current_user()['id'],'approve','return_to_supplier',$id,$remarks); notify_role($pdo,'warehouse_personnel','Supplier return approved for shipment',(string)$row['return_no'].' is approved and ready to ship.','workflow_control.php','success'); }
            elseif($action==='rts_reject') { if((string)$row['status']!=='submitted') throw new RuntimeException('Supplier return is not awaiting approval.'); $pdo->prepare("UPDATE return_to_supplier_records SET status='rejected',approved_by=?,approved_at=NOW(),approval_remarks=? WHERE id=?")->execute([(int)current_user()['id'],$remarks,$id]); write_audit($pdo,(int)current_user()['id'],'reject','return_to_supplier',$id,$remarks); }
            elseif($action==='rts_ship') { ship_supplier_return($pdo,$id,(int)current_user()['id'],$remarks); }
            elseif($action==='rts_credit') { if((string)$row['status']!=='shipped') throw new RuntimeException('Only a shipped supplier return can be marked credited/replaced.'); $pdo->prepare("UPDATE return_to_supplier_records SET status='credited' WHERE id=?")->execute([$id]); write_audit($pdo,(int)current_user()['id'],'credit','return_to_supplier',$id,$remarks!==''?$remarks:'Supplier credit/replacement confirmed.'); }
            else { if(!in_array((string)$row['status'],['shipped','credited'],true)) throw new RuntimeException('Only shipped/credited supplier returns can be closed.'); $pdo->prepare("UPDATE return_to_supplier_records SET status='closed' WHERE id=?")->execute([$id]); write_audit($pdo,(int)current_user()['id'],'close','return_to_supplier',$id,$remarks); }
            flash('success','Supplier return workflow updated.');
        } elseif($action==='writeoff') {
            if(!$canRequest) throw new RuntimeException('You do not have permission to submit a write-off.');
            $materialId=(int)($_POST['material_id']??0); $locationId=(int)($_POST['location_id']??0); $quantity=(float)($_POST['quantity']??0); $reason=trim((string)($_POST['reason']??''));
            if($quantity<=0) throw new RuntimeException('Write-off quantity must be greater than zero.');
            if($reason==='') throw new RuntimeException('Write-off reason is required.');
            assert_operational_material($pdo,$materialId);
            $balance=ensure_inventory_balance($pdo,$materialId,$locationId);
            if($quantity>available_stock((float)$balance['quantity_on_hand'],(float)$balance['reserved_quantity'],(float)$balance['damaged_quantity'])) throw new RuntimeException('Write-off exceeds current available stock.');
            $writeoffNo='WO-'.date('YmdHis').'-'.random_int(10,99);
            $pdo->prepare("INSERT INTO disposal_writeoffs (writeoff_no,material_id,location_id,quantity,reason,estimated_cost,status,requested_by) VALUES (?,?,?,?,?,?,'submitted',?)")
                ->execute([$writeoffNo,$materialId,$locationId,$quantity,$reason,max(0,(float)($_POST['estimated_cost']??0)),(int)current_user()['id']]);
            $writeoffId=(int)$pdo->lastInsertId(); write_audit($pdo,(int)current_user()['id'],'submit','writeoff',$writeoffId,'Write-off submitted for approval.');
            flash('success','Write-off submitted for approval.');
        } elseif(in_array($action,['writeoff_approve','writeoff_reject','writeoff_post'],true)) {
            if(in_array($action,['writeoff_approve','writeoff_reject'],true) && !$canManage) throw new RuntimeException('You do not have permission to decide write-offs.');
            if($action==='writeoff_post' && !$canPost) throw new RuntimeException('Only authorized operational personnel can post an approved write-off.');
            $id=(int)($_POST['id']??0); $remarks=trim((string)($_POST['remarks']??''));
            $woStmt=$pdo->prepare('SELECT * FROM disposal_writeoffs WHERE id=? FOR UPDATE'); $woStmt->execute([$id]); $woRow=$woStmt->fetch(); if(!$woRow) throw new RuntimeException('Write-off record not found.');
            if(in_array($action,['writeoff_approve','writeoff_reject'],true) && (int)$woRow['requested_by']===(int)current_user()['id']) throw new RuntimeException('You cannot approve or reject your own write-off request.');
            if($action==='writeoff_reject') { $stmt=$pdo->prepare("UPDATE disposal_writeoffs SET status='rejected',approved_by=?,approved_at=NOW(),approval_remarks=? WHERE id=? AND status='submitted'"); $stmt->execute([(int)current_user()['id'],$remarks,$id]); if($stmt->rowCount()===0) throw new RuntimeException('Write-off is not awaiting approval.'); write_audit($pdo,(int)current_user()['id'],'reject','writeoff',$id,$remarks); }
            else approve_and_post_writeoff($pdo,$id,(int)current_user()['id'],$remarks,$action==='writeoff_post');
            if($action==='writeoff_approve') notify_role($pdo,'warehouse_personnel','Write-off approved for posting',(string)$woRow['writeoff_no'].' is approved and ready to post.','workflow_control.php','success');
            flash('success','Write-off workflow updated.');
        } else throw new RuntimeException('Unsupported workflow action.');
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); flash('danger',$e->getMessage()); }
    redirect('workflow_control.php');
}

$cancellations=workflow_cancellation_rows($pdo); $supplierReturns=return_to_supplier_rows($pdo); $writeoffs=disposal_writeoff_rows($pdo);
$materials=$pdo->query("SELECT id,material_code,material_name FROM materials WHERE is_active=1 AND approval_status='approved' ORDER BY material_name")->fetchAll();
$locations=$pdo->query("SELECT id,name FROM locations WHERE is_active=1 ORDER BY type,name")->fetchAll();
$warehouses=$pdo->query("SELECT id,name FROM locations WHERE is_active=1 AND type='warehouse' ORDER BY is_default DESC,name")->fetchAll();
$suppliers=$pdo->query("SELECT id,company FROM suppliers WHERE is_active=1 ORDER BY company")->fetchAll();
$activePage='workflow_control'; $title='Workflow Control'; $eyebrow='GOVERNANCE';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__.'/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__.'/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__.'/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">WORKFLOW CONTROL</span><h2>Governed exception workflows</h2><p>Cancellation/void, supplier returns, and disposal/write-off now require explicit decisions and apply inventory effects only at the controlled posting step.</p></div></section>
<?php if($canManage || $canRequest): ?><section class="split-grid"><?php if($canManage): ?><article class="form-card"><div class="card-heading"><div><span class="eyebrow">VOID</span><h2>Request Cancel / Void</h2></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_request"><div class="form-grid"><label>Record Type<select name="entity_type"><?php foreach(['request','purchase_order','transfer','return','damage_report','stock_count'] as $t): ?><option value="<?= e($t) ?>"><?= e(ucwords(str_replace('_',' ',$t))) ?></option><?php endforeach; ?></select></label><label>Record ID<input type="number" min="1" name="entity_id" required></label><label class="full">Reason<textarea name="reason" required></textarea></label></div><button class="btn primary">Submit Void Request</button></form></article><?php endif; ?>
<?php if($canRequest): ?><article class="form-card"><div class="card-heading"><div><span class="eyebrow">SUPPLIER</span><h2>Return to Supplier</h2></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="return_supplier"><div class="form-grid"><label>Supplier<select name="supplier_id"><?php foreach($suppliers as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['company']) ?></option><?php endforeach; ?></select></label><label>Source Warehouse<select name="location_id"><?php foreach($warehouses as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?></select></label><label>Material<select name="material_id"><?php foreach($materials as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['material_code'].' - '.$x['material_name']) ?></option><?php endforeach; ?></select></label><label>Quantity<input type="number" min="0.01" step="0.01" name="quantity" required></label><label class="full">Reason<textarea name="reason" required></textarea></label></div><button class="btn primary">Submit Supplier Return</button></form></article><?php endif; ?></section><?php endif; ?>
<?php if($canRequest): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">WRITE-OFF</span><h2>Disposal / Write-Off</h2></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="writeoff"><div class="form-grid three"><label>Material<select name="material_id"><?php foreach($materials as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['material_code'].' - '.$x['material_name']) ?></option><?php endforeach; ?></select></label><label>Location<select name="location_id"><?php foreach($locations as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?></select></label><label>Quantity<input type="number" min="0.01" step="0.01" name="quantity" required></label><label>Estimated Cost<input type="number" min="0" step="0.01" name="estimated_cost" value="0"></label><label class="full">Reason<textarea name="reason" required></textarea></label></div><button class="btn primary">Submit Write-Off</button></form></section><?php endif; ?>

<?php if($canManage): ?><section class="content-card"><div class="card-heading"><div><span class="eyebrow">VOID QUEUE</span><h2>Cancellation / Void Decisions</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Record</th><th>Reason</th><th>Status</th><th>Requester</th><th>Decision</th></tr></thead><tbody><?php if(!$cancellations): ?><tr><td colspan="5" class="muted">No cancellation requests.</td></tr><?php endif; ?><?php foreach($cancellations as $r): ?><tr><td><?= e($r['entity_type']) ?> #<?= (int)$r['entity_id'] ?></td><td><?= e($r['reason']) ?></td><td><?= ui_status_badge((string)$r['status']) ?></td><td><?= e($r['requested_by_name']) ?></td><td><?php if((string)$r['status']==='requested'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="remarks" placeholder="Decision remarks"><button class="btn primary" name="action" value="cancel_approve">Approve & Void</button><button class="btn danger" name="action" value="cancel_reject">Reject</button></form><?php else: ?><?= e($r['decision_remarks'] ?? '') ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>

<section class="split-grid"><article class="content-card"><div class="card-heading"><div><span class="eyebrow">RTS</span><h2>Supplier Return Queue</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Supplier / Material</th><th>Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$supplierReturns): ?><tr><td colspan="5" class="muted">No supplier returns.</td></tr><?php endif; ?><?php foreach($supplierReturns as $r): ?><tr><td><?= e($r['return_no']) ?></td><td><?= e($r['company']) ?><small><?= e($r['material_code'].' - '.$r['material_name']) ?></small></td><td><?= money_or_qty($r['quantity']) ?></td><td><?= ui_status_badge((string)$r['status']) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="remarks" placeholder="Remarks"><?php if((string)$r['status']==='submitted' && $canManage): ?><button class="btn primary" name="action" value="rts_approve">Approve</button><button class="btn danger" name="action" value="rts_reject">Reject</button><?php elseif((string)$r['status']==='approved' && $canPost): ?><button class="btn primary" name="action" value="rts_ship">Ship / Deduct Stock</button><?php elseif((string)$r['status']==='shipped' && $canManage): ?><button class="btn primary" name="action" value="rts_credit">Mark Credited / Replaced</button><button class="btn secondary" name="action" value="rts_close">Close Without Credit</button><?php elseif((string)$r['status']==='credited' && $canManage): ?><button class="btn secondary" name="action" value="rts_close">Close</button><?php else: ?>—<?php endif; ?></form></td></tr><?php endforeach; ?></tbody></table></div></article>
<article class="content-card"><div class="card-heading"><div><span class="eyebrow">WRITE-OFF</span><h2>Write-Off Queue</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Material / Location</th><th>Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$writeoffs): ?><tr><td colspan="5" class="muted">No write-offs.</td></tr><?php endif; ?><?php foreach($writeoffs as $r): ?><tr><td><?= e($r['writeoff_no']) ?></td><td><?= e($r['material_code'].' - '.$r['material_name']) ?><small><?= e($r['location_name']) ?></small></td><td><?= money_or_qty($r['quantity']) ?></td><td><?= ui_status_badge((string)$r['status']) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="remarks" placeholder="Remarks"><?php if((string)$r['status']==='submitted' && $canManage): ?><button class="btn primary" name="action" value="writeoff_approve">Approve</button><button class="btn danger" name="action" value="writeoff_reject">Reject</button><?php elseif((string)$r['status']==='approved' && $canPost): ?><button class="btn primary" name="action" value="writeoff_post">Post & Deduct</button><?php else: ?>—<?php endif; ?></form></td></tr><?php endforeach; ?></tbody></table></div></article></section>
</div></main></div></body></html>
