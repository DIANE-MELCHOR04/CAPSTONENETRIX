<?php
require_once __DIR__ . '/lib/adjustments.php';
require_permission(role_can((string) ($_SESSION['role'] ?? ''), 'manage_adjustments') ? 'manage_adjustments' : 'approve_adjustments');
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT a.*, l.name AS location_name, u.full_name FROM inventory_adjustments a JOIN locations l ON l.id = a.location_id JOIN users u ON u.id = a.requested_by WHERE a.id = ?');
$stmt->execute([$id]);
$adjustment = $stmt->fetch();
if (!$adjustment) die('Adjustment not found.');
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM inventory_adjustment_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.adjustment_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($adjustment['adjustment_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Inventory Adjustment Form</h1><p><b>No:</b> <?= e($adjustment['adjustment_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $adjustment['status'])) ?></p><p><b>Location:</b> <?= e($adjustment['location_name']) ?> | <b>Prepared By:</b> <?= e($adjustment['full_name']) ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>Before</th><th>Adjustment</th><th>After</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['before_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['adjustment_qty']) ?></td><td><?= money_or_qty($item['after_qty']) ?></td><td><?= e($item['status']) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>

