<?php
require_once __DIR__ . '/lib/transfers.php';
require_any_permission(['view_transfers', 'create_transfers', 'dispatch_transfers', 'receive_transfers', 'approve_transfers']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT t.*, src.name AS source_name, dst.name AS destination_name, u.full_name FROM material_transfers t JOIN locations src ON src.id = t.source_location_id JOIN locations dst ON dst.id = t.destination_location_id JOIN users u ON u.id = t.requested_by WHERE t.id = ?');
$stmt->execute([$id]);
$transfer = $stmt->fetch();
if (!$transfer) die('Transfer not found.');
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM material_transfer_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.transfer_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($transfer['transfer_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Stock Transfer Slip</h1><p><b>No:</b> <?= e($transfer['transfer_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $transfer['status'])) ?></p><p><b>Route:</b> <?= e($transfer['source_name']) ?> to <?= e($transfer['destination_name']) ?> | <b>Prepared By:</b> <?= e($transfer['full_name']) ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>Requested</th><th>Dispatched</th><th>Received</th><th>Discrepancy</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['requested_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['dispatched_qty']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td><?= money_or_qty($item['discrepancy_qty']) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>
