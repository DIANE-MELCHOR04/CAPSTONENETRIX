<?php
require_once __DIR__ . '/lib/inventory.php';
require_once __DIR__ . '/lib/flow_upgrade.php';
require_once __DIR__ . '/lib/audit.php';
require_any_permission(['request_stock_out','approve_stock_out']);
require_once __DIR__ . '/config/db.php';

require_flow_upgrade($pdo, ['manual_stock_out_requests']);
$role = (string) ($_SESSION['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'submit');
    try {
        $pdo->beginTransaction();
        if ($action === 'submit') {
            if (!role_can($role, 'request_stock_out')) throw new RuntimeException('You do not have permission to request an emergency stock-out.');
            $qty = (float) ($_POST['quantity'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($qty <= 0) throw new RuntimeException('Quantity must be greater than zero.');
            if ($reason === '') throw new RuntimeException('A business reason is required for an emergency/manual stock-out.');
            $materialId = (int) ($_POST['material_id'] ?? 0);
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $locationCheck = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE id=? AND type='warehouse' AND is_active=1");
            $locationCheck->execute([$locationId]);
            if ((int)$locationCheck->fetchColumn() !== 1) throw new RuntimeException('Emergency stock-out can only be posted from an active warehouse.');
            assert_operational_material($pdo, $materialId);
            $balance = ensure_inventory_balance($pdo, $materialId, $locationId);
            $available = available_stock((float)$balance['quantity_on_hand'],(float)$balance['reserved_quantity'],(float)$balance['damaged_quantity']);
            if ($qty > $available) throw new RuntimeException('Requested stock-out exceeds current available stock.');
            $requestNo = 'ESO-' . date('YmdHis') . '-' . random_int(100,999);
            $pdo->prepare("INSERT INTO manual_stock_out_requests (request_no,material_id,location_id,quantity,reference_no,reason,status,requested_by) VALUES (?,?,?,?,?,?,'submitted',?)")
                ->execute([$requestNo,$materialId,$locationId,$qty,trim((string)($_POST['reference_no']??'')),$reason,(int)current_user()['id']]);
            $id=(int)$pdo->lastInsertId();
            write_audit($pdo,(int)current_user()['id'],'request','manual_stock_out',$id,'Emergency stock-out submitted for manager approval.');
            notify_role($pdo,'warehouse_manager','Emergency stock-out awaiting approval',$requestNo.' requires review.','stock_out.php');
            flash('success','Emergency stock-out submitted for Warehouse Manager approval.');
        } elseif (in_array($action,['approve','reject'],true)) {
            if (!role_can($role,'approve_stock_out')) throw new RuntimeException('You do not have permission to decide emergency stock-outs.');
            $id=(int)($_POST['id']??0);
            $stmt=$pdo->prepare("SELECT * FROM manual_stock_out_requests WHERE id=? FOR UPDATE"); $stmt->execute([$id]); $row=$stmt->fetch();
            if(!$row || (string)$row['status']!=='submitted') throw new RuntimeException('This stock-out request is no longer awaiting approval.');
            $remarks=trim((string)($_POST['approval_remarks']??''));
            if($action==='reject') {
                $pdo->prepare("UPDATE manual_stock_out_requests SET status='rejected',approved_by=?,approved_at=NOW(),approval_remarks=? WHERE id=?")->execute([(int)current_user()['id'],$remarks,$id]);
                write_audit($pdo,(int)current_user()['id'],'reject','manual_stock_out',$id,'Emergency stock-out rejected.');
                flash('success','Emergency stock-out rejected.');
            } else {
                $balance=ensure_inventory_balance($pdo,(int)$row['material_id'],(int)$row['location_id']);
                $available=available_stock((float)$balance['quantity_on_hand'],(float)$balance['reserved_quantity'],(float)$balance['damaged_quantity']);
                if((float)$row['quantity']>$available) throw new RuntimeException('Available stock has changed and is no longer enough for this stock-out.');
                $movementId=post_inventory_movement($pdo,[
                    'movement_type'=>'stock_out','material_id'=>(int)$row['material_id'],'location_id'=>(int)$row['location_id'],
                    'quantity_in'=>0,'quantity_out'=>(float)$row['quantity'],'reference_type'=>'manual_stock_out','reference_id'=>$id,
                    'remarks'=>'Approved emergency stock-out. Ref: '.((string)$row['reference_no'] ?: 'none').' / '.(string)$row['reason'],'created_by'=>(int)current_user()['id'],
                ]);
                $pdo->prepare("UPDATE manual_stock_out_requests SET status='posted',approved_by=?,approved_at=NOW(),approval_remarks=?,posted_movement_id=? WHERE id=?")->execute([(int)current_user()['id'],$remarks,$movementId,$id]);
                write_audit($pdo,(int)current_user()['id'],'approve_post','manual_stock_out',$id,'Emergency stock-out approved and posted.');
                flash('success','Emergency stock-out approved and inventory deducted.');
            }
        } else throw new RuntimeException('Unsupported stock-out action.');
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        flash('danger',$e->getMessage());
    }
    redirect('stock_out.php');
}

$options=inventory_options($pdo);
$warehouseOptions=$pdo->query("SELECT id, name AS label FROM locations WHERE is_active=1 AND type='warehouse' ORDER BY is_default DESC,name")->fetchAll();
$where=$role==='warehouse_manager' ? '' : 'WHERE r.requested_by='.(int)current_user()['id'];
$rows=$pdo->query("SELECT r.*,m.material_code,m.material_name,l.name AS location_name,u.full_name AS requester,a.full_name AS approver FROM manual_stock_out_requests r JOIN materials m ON m.id=r.material_id JOIN locations l ON l.id=r.location_id JOIN users u ON u.id=r.requested_by LEFT JOIN users a ON a.id=r.approved_by {$where} ORDER BY r.created_at DESC LIMIT 100")->fetchAll();
$activePage='stock_out'; $title='Emergency Stock-Out'; $eyebrow='CONTROLLED EXCEPTION';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__.'/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__.'/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__.'/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">CONTROLLED EXCEPTION</span><h2>Emergency / Miscellaneous Stock-Out</h2><p>Use this only when Release, Transfer, Return, Damage, or Adjustment does not apply. Warehouse Personnel submits; Warehouse Manager approval is required before stock is deducted.</p></div></section>
<?php if(role_can($role,'request_stock_out')): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">REQUEST</span><h2>Request exceptional stock deduction</h2></div></div><form method="post" data-block-on-submit="Submitting stock-out"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit"><div class="form-grid"><label>Material<select name="material_id" required><?php foreach($options['materials'] as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['label']) ?></option><?php endforeach; ?></select></label><label>Source Location<select name="location_id" required><?php foreach($warehouseOptions as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['label']) ?></option><?php endforeach; ?></select></label><label>Quantity<input type="number" min="0.01" step="0.01" name="quantity" required></label><label>Reference No.<input name="reference_no" placeholder="Issue slip / work order"></label><label class="full">Reason<textarea name="reason" required placeholder="Explain why a normal controlled workflow cannot be used."></textarea></label></div><div class="form-actions"><button class="btn primary" type="submit">Submit for Approval</button></div></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">HISTORY</span><h2><?= role_can($role,'approve_stock_out')?'Emergency stock-out approval queue':'My emergency stock-out requests' ?></h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Material</th><th>Location</th><th>Qty</th><th>Reason</th><th>Requester</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="8" class="muted">No emergency stock-out requests.</td></tr><?php endif; ?><?php foreach($rows as $r): ?><tr><td><b><?= e($r['request_no']) ?></b><small><?= e(date('M d, Y h:i A',strtotime($r['created_at']))) ?></small></td><td><?= e($r['material_code']) ?><small><?= e($r['material_name']) ?></small></td><td><?= e($r['location_name']) ?></td><td><?= money_or_qty($r['quantity']) ?></td><td><?= e($r['reason']) ?></td><td><?= e($r['requester']) ?></td><td><?= ui_status_badge((string)$r['status']) ?></td><td><?php if(role_can($role,'approve_stock_out') && (string)$r['status']==='submitted'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="approval_remarks" placeholder="Manager remarks"><button class="btn primary" name="action" value="approve">Approve & Post</button><button class="btn danger" name="action" value="reject">Reject</button></form><?php else: ?><?= e($r['approver'] ?: '—') ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
