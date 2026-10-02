<?php
require_once __DIR__ . '/lib/adjustments.php';
require_any_permission(['view_stock_counts', 'manage_stock_counts', 'approve_stock_counts']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT c.*, l.name AS location_name, u.full_name FROM stock_counts c JOIN locations l ON l.id = c.location_id JOIN users u ON u.id = c.counted_by WHERE c.id = ?');
$stmt->execute([$id]);
$count = $stmt->fetch();
if (!$count) die('Stock count not found.');
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM stock_count_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.stock_count_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($count['count_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Stock Count Variance Report</h1><p><b>No:</b> <?= e($count['count_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $count['status'])) ?></p><p><b>Location:</b> <?= e($count['location_name']) ?> | <b>Counted By:</b> <?= e($count['full_name']) ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>System</th><th>Counted</th><th>Variance</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['system_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['counted_qty']) ?></td><td><?= money_or_qty($item['variance_qty']) ?></td><td><?= e(str_replace('_', ' ', $item['status'])) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>
