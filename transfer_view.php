<?php
require_once __DIR__ . '/lib/transfers.php';
require_any_permission(['view_transfers', 'create_transfers', 'create_site_transfers', 'dispatch_transfers', 'receive_transfers', 'approve_transfers']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'approve' && role_can($_SESSION['role'], 'approve_transfers')) {
            approve_transfer($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Transfer approved.');
        } elseif ($action === 'reject' && role_can($_SESSION['role'], 'approve_transfers')) {
            reject_transfer($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Transfer rejected.');
        } elseif ($action === 'dispatch' && role_can((string) ($_SESSION['role'] ?? ''), 'dispatch_transfers')) {
            dispatch_transfer($pdo, $id, (int) current_user()['id']);
            flash('success', 'Transfer dispatched.');
        } elseif ($action === 'receive' && role_can((string) ($_SESSION['role'] ?? ''), 'receive_transfers')) {
            receive_transfer($pdo, $id, $_POST['received_qty'] ?? [], (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Transfer receipt recorded.');
        } else {
            throw new RuntimeException('You do not have permission to perform this transfer action.');
        }
        $pdo->commit();
        redirect('transfer_view.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('transfer_view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare('SELECT t.*, src.name AS source_name, dst.name AS destination_name, u.full_name, a.full_name AS approver_name FROM material_transfers t JOIN locations src ON src.id = t.source_location_id JOIN locations dst ON dst.id = t.destination_location_id JOIN users u ON u.id = t.requested_by LEFT JOIN users a ON a.id = t.approved_by WHERE t.id = ?');
$stmt->execute([$id]);
$transfer = $stmt->fetch();
if (!$transfer) {
    http_response_code(404);
    die('Transfer not found.');
}
if ((string) ($_SESSION['role'] ?? '') === 'site_engineer' && (int) $transfer['requested_by'] !== (int) current_user()['id']) { http_response_code(403); die('You may only access transfers you created for your project site.'); }
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM material_transfer_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.transfer_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$timeline = entity_timeline($pdo, 'material_transfer', $id);
$activePage = 'transfers';
$title = 'Transfer ' . $transfer['transfer_no'];
$eyebrow = 'INVENTORY TRANSFER';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light"><?= e($transfer['transfer_no']) ?></span><h2><?= e($transfer['source_name']) ?> to <?= e($transfer['destination_name']) ?></h2><p>Requested by <?= e($transfer['full_name']) ?>. Status: <?= e(str_replace('_', ' ', $transfer['status'])) ?>.</p></div><div class="hero-actions"><a class="btn glass" href="print_transfer.php?id=<?= (int) $id ?>" target="_blank">Print</a></div></section>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Requested</th><th>Dispatched</th><th>Received</th><th>Discrepancy</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['requested_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['dispatched_qty']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td><?= money_or_qty($item['discrepancy_qty']) ?></td><td><span class="workflow-badge <?= e($item['status']) ?>"><?= e(str_replace('_', ' ', $item['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if (role_can($_SESSION['role'], 'approve_transfers') && in_array($transfer['status'], ['draft','submitted'], true)): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">MANAGER DECISION</span><h2>Review transfer</h2></div></div><form method="post" data-block-on-submit="Saving decision"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><label>Remarks<textarea name="remarks"></textarea></label><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="approve" data-confirm="Approve this transfer?" data-confirm-title="Approve transfer">Approve</button><button class="btn danger" name="action" value="reject" data-confirm="Reject this transfer?" data-confirm-title="Reject transfer">Reject</button></div></form></section><?php endif; ?>
<?php if (role_can((string) ($_SESSION['role'] ?? ''), 'dispatch_transfers') && $transfer['status'] === 'approved'): ?><section class="form-card"><form method="post" data-block-on-submit="Dispatching transfer"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><button class="btn primary" name="action" value="dispatch" data-confirm="Dispatch this transfer and deduct source stock?" data-confirm-title="Dispatch transfer">Dispatch Transfer</button></form></section><?php endif; ?>
<?php if (role_can((string) ($_SESSION['role'] ?? ''), 'receive_transfers') && (int) ($transfer['dispatched_by'] ?? 0) !== (int) current_user()['id'] && in_array($transfer['status'], ['in_transit','partially_received','discrepancy'], true)): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">RECEIPT</span><h2>Confirm destination quantities</h2></div></div><form method="post" data-block-on-submit="Recording receipt"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Remaining In Transit</th><th>Receive Qty</th></tr></thead><tbody><?php foreach ($items as $item): ?><?php $remaining = (float) $item['dispatched_qty'] - (float) $item['received_qty']; if ($remaining <= 0) continue; ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($remaining) ?> <?= e($item['unit_name']) ?></td><td><input type="number" step="0.01" min="0" max="<?= e($remaining) ?>" name="received_qty[<?= (int) $item['id'] ?>]" value="<?= e($remaining) ?>"></td></tr><?php endforeach; ?></tbody></table></div><label style="margin-top:14px">Receipt / discrepancy remarks<textarea name="remarks"></textarea></label><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="receive" data-confirm="Record this transfer receipt?" data-confirm-title="Receive transfer">Record Receipt</button></div></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TIMELINE</span><h2>Activity History</h2></div></div><ul class="timeline-list"><?php if (!$timeline): ?><li class="timeline-item"><span class="muted">No activity yet.</span></li><?php endif; ?><?php foreach ($timeline as $event): ?><li class="timeline-item"><span><?= e(date('M d, h:i A', strtotime($event['created_at']))) ?></span><div><b><?= e(ucfirst($event['action'])) ?></b><small><?= e($event['full_name'] ?? 'System') ?> - <?= e($event['details']) ?></small></div></li><?php endforeach; ?></ul></section>
</div></main></div></body></html>
