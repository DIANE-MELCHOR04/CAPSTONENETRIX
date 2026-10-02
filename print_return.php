<?php
require_once __DIR__ . '/lib/returns.php';
require_any_permission(['view_returns', 'create_returns', 'post_returns']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT r.*, s.name AS site_name, l.name AS location_name, u.full_name FROM material_returns r JOIN sites s ON s.id = r.source_site_id JOIN locations l ON l.id = r.receiving_location_id JOIN users u ON u.id = r.returned_by WHERE r.id = ?');
$stmt->execute([$id]);
$return = $stmt->fetch();
if (!$return) die('Return not found.');
require_site_engineer_record_access((int) $return['source_site_id'], (int) $return['returned_by']);
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM material_return_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.return_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($return['return_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Material Return Slip</h1><p><b>No:</b> <?= e($return['return_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $return['status'])) ?></p><p><b>Source:</b> <?= e($return['site_name']) ?> | <b>Receiving:</b> <?= e($return['location_name']) ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>Qty</th><th>Condition</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['returned_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= e(str_replace('_', ' ', $item['condition_status'])) ?></td><td><?= e($item['status']) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>
