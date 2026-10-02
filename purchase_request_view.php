<?php
require_once __DIR__ . '/lib/procurement.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'manage_procurement') && !role_can($role, 'approve_procurement')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'approve') {
            if (!role_can($role, 'approve_procurement')) {
                throw new RuntimeException('You do not have permission to approve purchase requests.');
            }
            approve_purchase_request($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Purchase request approved.');
        } elseif ($action === 'reject') {
            if (!role_can($role, 'approve_procurement')) {
                throw new RuntimeException('You do not have permission to approve purchase requests.');
            }
            reject_purchase_request($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Purchase request rejected.');
        } elseif ($action === 'order') {
            if (!role_can($role, 'manage_procurement')) {
                throw new RuntimeException('You do not have permission to create purchase orders.');
            }
            $orderId = create_purchase_order($pdo, $id, (int) $_POST['supplier_id'], (int) $_POST['receiving_location_id'], (string) $_POST['expected_delivery'], (int) current_user()['id']);
            $pdo->commit();
            flash('success', 'Purchase order created.');
            redirect('purchase_order_view.php?id=' . $orderId);
        } else {
            throw new RuntimeException('Unsupported purchase request action.');
        }
        $pdo->commit();
        redirect('purchase_request_view.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('purchase_request_view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare('SELECT pr.*, u.full_name, a.full_name AS approver_name, ar.title AS ai_recommendation_title FROM purchase_requests pr JOIN users u ON u.id = pr.requested_by LEFT JOIN users a ON a.id = pr.approved_by LEFT JOIN ai_recommendations ar ON ar.id = pr.ai_recommendation_id WHERE pr.id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(404);
    die('Purchase request not found.');
}
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM purchase_request_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.purchase_request_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$suppliers = $pdo->query('SELECT id, company FROM suppliers WHERE is_active = 1 ORDER BY company')->fetchAll();
$receivingLocations = $pdo->query("SELECT id, name FROM locations WHERE is_active=1 AND type='warehouse' ORDER BY is_default DESC, name")->fetchAll();
$timeline = entity_timeline($pdo, 'purchase_request', $id);
$activePage = 'purchase_requests';
$title = 'Purchase Request ' . $request['pr_no'];
$eyebrow = 'PROCUREMENT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light"><?= e($request['pr_no']) ?></span><h2><?= e(str_replace('_', ' ', ucfirst($request['status']))) ?></h2><p>Requested by <?= e($request['full_name']) ?>. <?= $request['needed_by'] ? 'Needed by ' . e(date('M d, Y', strtotime($request['needed_by']))) . '.' : 'Need date open.' ?><?php if ($request['ai_recommendation_id']): ?> Converted from <a href="ai_recommendations.php#recommendation-<?= (int) $request['ai_recommendation_id'] ?>"><?= e($request['ai_recommendation_title'] ?: 'AI recommendation #' . $request['ai_recommendation_id']) ?></a>.<?php endif; ?></p></div><div class="hero-actions"><a class="btn glass" href="print_purchase_request.php?id=<?= (int) $id ?>" target="_blank">Print</a></div></section>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Requested</th><th>Ordered</th><th>Received</th><th>Status</th><th>Remarks</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['requested_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['ordered_qty']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td><span class="workflow-badge <?= e($item['status']) ?>"><?= e(str_replace('_', ' ', $item['status'])) ?></span></td><td><?= e($item['remarks']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if (role_can($_SESSION['role'], 'approve_procurement') && in_array($request['status'], ['draft','submitted'], true)): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">PRESIDENT DECISION</span><h2>Review procurement request</h2></div></div><form method="post" data-block-on-submit="Saving decision"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><label>Executive remarks<textarea name="remarks"></textarea></label><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="approve" data-confirm="Approve this purchase request as President?" data-confirm-title="Approve purchase request">Approve</button><button class="btn danger" name="action" value="reject" data-confirm="Reject this purchase request as President?" data-confirm-title="Reject purchase request">Reject</button></div></form></section><?php endif; ?>
<?php if (role_can($_SESSION['role'], 'manage_procurement') && in_array($request['status'], ['approved','partially_ordered'], true)): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">SUPPLIER ORDER</span><h2>Create purchase order</h2></div></div><form method="post" data-block-on-submit="Creating order"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><div class="form-grid"><label>Supplier<select name="supplier_id"><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= e($supplier['company']) ?></option><?php endforeach; ?></select></label><label>Receiving Warehouse<select name="receiving_location_id" required><?php foreach ($receivingLocations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>Expected Delivery<input type="date" name="expected_delivery"></label></div><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="order" data-confirm="Create a purchase order for remaining approved quantities?" data-confirm-title="Create purchase order">Create Order</button></div></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TIMELINE</span><h2>Activity History</h2></div></div><ul class="timeline-list"><?php if (!$timeline): ?><li class="timeline-item"><span class="muted">No activity yet.</span></li><?php endif; ?><?php foreach ($timeline as $event): ?><li class="timeline-item"><span><?= e(date('M d, h:i A', strtotime($event['created_at']))) ?></span><div><b><?= e(ucfirst($event['action'])) ?></b><small><?= e($event['full_name'] ?? 'System') ?> - <?= e($event['details']) ?></small></div></li><?php endforeach; ?></ul></section>
</div></main></div></body></html>
