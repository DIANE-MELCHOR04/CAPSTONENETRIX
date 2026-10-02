<?php
require_once __DIR__ . '/lib/procurement.php';
require_permission('manage_procurement');
require_once __DIR__ . '/config/db.php';

$stats = [
    'shortages' => (int) $pdo->query("SELECT COUNT(*) FROM material_request_items i WHERE i.shortage_qty > 0 AND NOT EXISTS (SELECT 1 FROM purchase_request_items pri JOIN purchase_requests pr ON pr.id = pri.purchase_request_id WHERE pri.source_type = 'material_request' AND pri.source_item_id = i.id AND pri.status NOT IN ('rejected','cancelled','completed') AND pr.status NOT IN ('rejected','cancelled','completed'))")->fetchColumn(),
    'purchase_requests' => (int) $pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('draft','submitted','approved','partially_ordered')")->fetchColumn(),
    'purchase_orders' => (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('submitted','open','partially_received')")->fetchColumn(),
    'deliveries' => (int) $pdo->query("SELECT COUNT(*) FROM supplier_deliveries WHERE DATE(received_at) = CURDATE()")->fetchColumn(),
];
$recentPrs = $pdo->query(
    "SELECT pr.*, u.full_name
     FROM purchase_requests pr
     JOIN users u ON u.id = pr.requested_by
     ORDER BY pr.created_at DESC
     LIMIT 8"
)->fetchAll();
$recentOrders = $pdo->query(
    "SELECT po.*, s.company
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     ORDER BY po.created_at DESC
     LIMIT 8"
)->fetchAll();
$activePage = 'procurement';
$title = 'Procurement Desk';
$eyebrow = 'PROCUREMENT CONTROL';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content ops-procurement-workspace">
<section class="monitoring-top-card"><div><span class="eyebrow light">SUPPLY QUEUE</span><h2>Shortages, purchase requests, orders, and deliveries</h2><p>Track procurement work from approved material shortage through supplier delivery and warehouse receiving.</p></div><div class="hero-actions"><a class="btn glass" href="purchase_request_create.php">New Purchase Request</a><a class="btn glass subtle" href="reports_procurement.php">Report</a></div></section>
<section class="stat-grid"><?php foreach ($stats as $label => $value): ?><div class="stat-card"><span><?= e(str_replace('_', ' ', strtoupper($label))) ?></span><strong><?= (int) $value ?></strong></div><?php endforeach; ?></section>
<section class="content-grid two"><div class="content-card"><div class="card-heading"><div><span class="eyebrow">REQUESTS</span><h2>Recent Purchase Requests</h2></div><a class="table-action" href="purchase_requests.php">Open</a></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Requested By</th><th>Status</th><th>Date</th></tr></thead><tbody><?php if (!$recentPrs): ?><tr><td colspan="4" class="muted">No purchase requests yet.</td></tr><?php endif; ?><?php foreach ($recentPrs as $row): ?><tr><td><a href="purchase_request_view.php?id=<?= (int) $row['id'] ?>"><?= e($row['pr_no']) ?></a></td><td><?= e($row['full_name']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><?= e(date('M d, Y', strtotime($row['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="content-card"><div class="card-heading"><div><span class="eyebrow">ORDERS</span><h2>Recent Supplier Orders</h2></div><a class="table-action" href="purchase_orders.php">Open</a></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Supplier</th><th>Status</th><th>Expected</th></tr></thead><tbody><?php if (!$recentOrders): ?><tr><td colspan="4" class="muted">No purchase orders yet.</td></tr><?php endif; ?><?php foreach ($recentOrders as $row): ?><tr><td><a href="purchase_order_view.php?id=<?= (int) $row['id'] ?>"><?= e($row['po_no']) ?></a></td><td><?= e($row['company']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><?= $row['expected_delivery'] ? e(date('M d, Y', strtotime($row['expected_delivery']))) : 'Open' ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>
</div></main></div></body></html>
