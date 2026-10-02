<?php
require_once __DIR__ . '/lib/procurement.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'manage_procurement') && !role_can($role, 'approve_procurement') && !role_can($role, 'receive_supplier_deliveries')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT po.*, s.company, s.contact_person, s.phone, u.full_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN users u ON u.id = po.ordered_by WHERE po.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) die('Purchase order not found.');
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM purchase_order_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.purchase_order_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($order['po_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Purchase Order</h1><p><b>No:</b> <?= e($order['po_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $order['status'])) ?></p><p><b>Supplier:</b> <?= e($order['company']) ?> | <b>Prepared By:</b> <?= e($order['full_name']) ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>Ordered</th><th>Received</th><th>Remaining</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['ordered_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td><?= money_or_qty((float) $item['ordered_qty'] - (float) $item['received_qty']) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>
