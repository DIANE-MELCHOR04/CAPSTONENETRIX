<?php
require_once __DIR__ . '/lib/procurement.php';
require_once __DIR__ . '/lib/attachments.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'manage_procurement') && !role_can($role, 'approve_procurement') && !role_can($role, 'receive_supplier_deliveries')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!role_can($role, 'approve_procurement')) {
            throw new RuntimeException('Only the president can approve or reject purchase orders.');
        }
        $pdo->beginTransaction();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'approve') {
            approve_purchase_order($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Purchase order approved.');
        } elseif ($action === 'reject') {
            reject_purchase_order($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Purchase order rejected.');
        } else {
            throw new RuntimeException('Unsupported purchase order action.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
    }
    redirect('purchase_order_view.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT po.*, s.company, u.full_name, a.full_name AS approver_name, rl.name AS receiving_location_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN users u ON u.id = po.ordered_by LEFT JOIN users a ON a.id = po.approved_by LEFT JOIN locations rl ON rl.id=po.receiving_location_id WHERE po.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    die('Purchase order not found.');
}
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM purchase_order_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.purchase_order_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$deliveriesStmt = $pdo->prepare('SELECT d.*, l.name AS location_name FROM supplier_deliveries d JOIN locations l ON l.id = d.location_id WHERE d.purchase_order_id = ? ORDER BY d.received_at DESC');
$deliveriesStmt->execute([$id]);
$deliveries = $deliveriesStmt->fetchAll();
$deliveryAttachments = [];
foreach ($deliveries as $deliveryRow) {
    $deliveryAttachments[(int)$deliveryRow['id']] = attachment_rows($pdo, 'supplier_delivery', (int)$deliveryRow['id']);
}
$timeline = entity_timeline($pdo, 'purchase_order', $id);
$attachments = attachment_rows($pdo, 'purchase_order', $id);
$activePage = 'purchase_orders';
$title = 'Purchase Order ' . $order['po_no'];
$eyebrow = 'PROCUREMENT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light"><?= e($order['po_no']) ?></span><h2><?= e($order['company']) ?></h2><p>Prepared by <?= e($order['full_name']) ?>. Receiving warehouse: <?= e($order['receiving_location_name'] ?: 'Not configured') ?>. Status: <?= e(str_replace('_', ' ', $order['status'])) ?>.<?= $order['approver_name'] ? ' Executive decision by ' . e($order['approver_name']) . '.' : '' ?></p></div><div class="hero-actions"><a class="btn glass" href="print_purchase_order.php?id=<?= (int) $id ?>" target="_blank">Print</a><?php if (role_can($role, 'receive_supplier_deliveries') && in_array($order['status'], ['open','partially_received'], true)): ?><a class="btn glass" href="supplier_delivery_create.php?id=<?= (int) $id ?>">Receive Delivery</a><?php endif; ?></div></section>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Ordered</th><th>Received</th><th>Remaining</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['ordered_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td><?= money_or_qty((float) $item['ordered_qty'] - (float) $item['received_qty']) ?></td><td><span class="workflow-badge <?= e($item['status']) ?>"><?= e(str_replace('_', ' ', $item['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if (role_can($role, 'approve_procurement') && $order['status'] === 'submitted'): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">PRESIDENT DECISION</span><h2>Review purchase order</h2></div></div><form method="post" data-block-on-submit="Saving executive decision"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><label>Executive remarks<textarea name="remarks"></textarea></label><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="approve" data-confirm="Approve this purchase order as President?" data-confirm-title="Approve purchase order">Approve Order</button><button class="btn danger" name="action" value="reject" data-confirm="Reject this purchase order as President?" data-confirm-title="Reject purchase order">Reject Order</button></div></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">EVIDENCE</span><h2>Purchase Order Attachments</h2><p>Attach quotations, approved PO copies, supplier confirmations, invoices, or delivery documents.</p></div></div><?php if(!$attachments): ?><p class="muted">No attachments yet.</p><?php else: ?><ul class="ops-attachment-list"><?php foreach($attachments as $a): ?><li><div><strong><a href="attachment_download.php?id=<?= (int)$a['id'] ?>"><?= e($a['file_name']) ?></a></strong><small><?= e($a['full_name']) ?> / <?= e(date('M d, Y',strtotime($a['created_at']))) ?></small></div></li><?php endforeach; ?></ul><?php endif; ?><form method="post" action="attachment_upload.php" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="entity_type" value="purchase_order"><input type="hidden" name="entity_id" value="<?= (int)$id ?>"><input type="hidden" name="return_url" value="purchase_order_view.php?id=<?= (int)$id ?>"><label>Upload supporting file<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" required></label><button class="btn secondary" type="submit">Upload Attachment</button></form></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">DELIVERIES</span><h2>Supplier Receipts</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Location</th><th>Status</th><th>Date</th><th>Evidence</th></tr></thead><tbody><?php if (!$deliveries): ?><tr><td colspan="5" class="muted">No deliveries recorded.</td></tr><?php endif; ?><?php foreach ($deliveries as $delivery): ?><tr id="delivery-<?= (int)$delivery['id'] ?>"><td><?= e($delivery['delivery_no']) ?></td><td><?= e($delivery['location_name']) ?></td><td><span class="workflow-badge <?= e($delivery['status']) ?>"><?= e($delivery['status']) ?></span></td><td><?= e(date('M d, Y h:i A', strtotime($delivery['received_at']))) ?></td><td><?php $deliveryFiles=$deliveryAttachments[(int)$delivery['id']]??[]; ?><?php if(!$deliveryFiles): ?><span class="muted">None</span><?php else: ?><?php foreach($deliveryFiles as $file): ?><a class="table-action" href="attachment_download.php?id=<?= (int)$file['id'] ?>"><?= e($file['file_name']) ?></a><?php endforeach; ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TIMELINE</span><h2>Activity History</h2></div></div><ul class="timeline-list"><?php if (!$timeline): ?><li class="timeline-item"><span class="muted">No activity yet.</span></li><?php endif; ?><?php foreach ($timeline as $event): ?><li class="timeline-item"><span><?= e(date('M d, h:i A', strtotime($event['created_at']))) ?></span><div><b><?= e(ucfirst($event['action'])) ?></b><small><?= e($event['full_name'] ?? 'System') ?> - <?= e($event['details']) ?></small></div></li><?php endforeach; ?></ul></section>
</div></main></div></body></html>
