<?php
require_once __DIR__ . '/lib/procurement.php';
require_once __DIR__ . '/lib/attachments.php';
require_permission('receive_supplier_deliveries');
require_once __DIR__ . '/config/db.php';

$userSiteId = current_user_site_id();

$purchaseOrderId = (int) ($_GET['id'] ?? $_POST['purchase_order_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $lockedLocation = $pdo->prepare('SELECT receiving_location_id FROM purchase_orders WHERE id=?'); $lockedLocation->execute([$purchaseOrderId]);
        $locationId = (int) $lockedLocation->fetchColumn();
        if ($locationId <= 0) { throw new RuntimeException('This PO has no intended receiving warehouse.'); }
        if ($userSiteId !== null) {
            $locationCheck = $pdo->prepare('SELECT COUNT(*) FROM locations WHERE id = ? AND site_id = ? AND is_active = 1');
            $locationCheck->execute([$locationId, $userSiteId]);
            if ((int) $locationCheck->fetchColumn() !== 1) { throw new RuntimeException('You may only receive deliveries at your assigned site.'); }
        }
        $pdo->beginTransaction();
        $deliveryId = post_supplier_delivery(
            $pdo,
            $purchaseOrderId,
            $locationId,
            $_POST['received_qty'] ?? [],
            (int) current_user()['id'],
            trim((string) $_POST['remarks']),
            trim((string) ($_POST['delivery_reference'] ?? ''))
        );
        $pdo->commit();
        $evidenceWarning = '';
        if (isset($_FILES['delivery_evidence']) && (int)($_FILES['delivery_evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                store_attachment($pdo, 'supplier_delivery', $deliveryId, $_FILES['delivery_evidence'], (int) current_user()['id']);
            } catch (Throwable $attachmentException) {
                $evidenceWarning = ' Delivery was posted, but evidence upload failed: ' . $attachmentException->getMessage();
            }
        }
        flash($evidenceWarning === '' ? 'success' : 'warning', 'Supplier delivery posted.' . $evidenceWarning);
        redirect('purchase_order_view.php?id=' . $purchaseOrderId . '#delivery-' . $deliveryId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('supplier_delivery_create.php?id=' . $purchaseOrderId);
    }
}

$orderStmt = $pdo->prepare('SELECT po.*, s.company, l.name AS receiving_location_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN locations l ON l.id=po.receiving_location_id WHERE po.id = ?');
$orderStmt->execute([$purchaseOrderId]);
$order = $orderStmt->fetch();
if (!$order) {
    http_response_code(404);
    die('Purchase order not found.');
}
if (empty($order['receiving_location_id'])) { throw new RuntimeException('This purchase order has no intended receiving warehouse. Edit/recreate the PO after running database/flow_accountability_upgrade.sql.'); }
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM purchase_order_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.purchase_order_id = ? AND i.ordered_qty > i.received_qty ORDER BY i.id');
$itemsStmt->execute([$purchaseOrderId]);
$items = $itemsStmt->fetchAll();
$locations = [['id'=>(int)$order['receiving_location_id'],'label'=>(string)$order['receiving_location_name']]];
$activePage = 'purchase_orders';
$title = 'Receive Supplier Delivery';
$eyebrow = 'PROCUREMENT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow"><?= e($order['po_no']) ?></span><h2><?= e($order['company']) ?></h2></div></div><form method="post" enctype="multipart/form-data" data-block-on-submit="Posting delivery"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="purchase_order_id" value="<?= (int) $purchaseOrderId ?>"><div class="form-grid"><label>Receiving Warehouse<input value="<?= e($order['receiving_location_name'] ?: 'Not configured') ?>" readonly><input type="hidden" name="location_id" value="<?= (int)$order['receiving_location_id'] ?>"><small>Locked from the approved purchase order.</small></label><label>Delivery Reference<input name="delivery_reference" maxlength="120" placeholder="DR no., invoice no., or supplier reference"><small>The same reference cannot be posted twice for this PO.</small></label><label class="full">Remarks<textarea name="remarks"></textarea></label><label class="full">Delivery Evidence (optional)<input type="file" name="delivery_evidence" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls"><small>Attach the supplier DR, invoice, receiving photo, or similar evidence. Maximum 8 MB.</small></label></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Remaining</th><th>Received Qty</th></tr></thead><tbody><?php foreach ($items as $item): ?><?php $remaining = (float) $item['ordered_qty'] - (float) $item['received_qty']; ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($remaining) ?> <?= e($item['unit_name']) ?></td><td><input type="number" step="0.01" min="0" max="<?= e($remaining) ?>" name="received_qty[<?= (int) $item['id'] ?>]" value="<?= e($remaining) ?>"></td></tr><?php endforeach; ?></tbody></table></div><div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Post this supplier delivery into inventory?" data-confirm-title="Receive delivery">Post Delivery</button><a class="btn secondary" href="purchase_order_view.php?id=<?= (int) $purchaseOrderId ?>">Back</a></div></form></section>
</div></main></div></body></html>
