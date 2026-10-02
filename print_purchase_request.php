<?php
require_once __DIR__ . '/lib/procurement.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'manage_procurement') && !role_can($role, 'approve_procurement')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT pr.*, u.full_name, a.full_name AS approver_name FROM purchase_requests pr JOIN users u ON u.id = pr.requested_by LEFT JOIN users a ON a.id = pr.approved_by WHERE pr.id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) die('Purchase request not found.');
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM purchase_request_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.purchase_request_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($request['pr_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Purchase Request</h1><p><b>No:</b> <?= e($request['pr_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $request['status'])) ?></p><p><b>Requested By:</b> <?= e($request['full_name']) ?> | <b>Approved By:</b> <?= e($request['approver_name'] ?? '') ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>Requested</th><th>Ordered</th><th>Received</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['requested_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['ordered_qty']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td><?= e(str_replace('_', ' ', $item['status'])) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>
