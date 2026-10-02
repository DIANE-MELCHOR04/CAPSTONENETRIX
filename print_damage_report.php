<?php
require_once __DIR__ . '/lib/damage.php';
require_permission(role_can((string) ($_SESSION['role'] ?? ''), 'manage_damage') ? 'manage_damage' : 'approve_damage');
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT d.*, l.name AS location_name, u.full_name FROM damage_reports d JOIN locations l ON l.id = d.location_id JOIN users u ON u.id = d.reported_by WHERE d.id = ?');
$stmt->execute([$id]);
$report = $stmt->fetch();
if (!$report) die('Damage report not found.');
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM damage_report_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.damage_report_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($report['damage_no']) ?></title><link rel="stylesheet" href="assets/css/app.css"></head><body class="print-body"><main class="print-sheet"><h1>Damaged Material Report</h1><p><b>No:</b> <?= e($report['damage_no']) ?> | <b>Status:</b> <?= e(str_replace('_', ' ', $report['status'])) ?></p><p><b>Location:</b> <?= e($report['location_name']) ?> | <b>Reported By:</b> <?= e($report['full_name']) ?></p><table class="data modern-table"><thead><tr><th>Material</th><th>Reported Qty</th><th>Decision Qty</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['damaged_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['decision_qty']) ?></td><td><?= e($item['status']) ?></td></tr><?php endforeach; ?></tbody></table><script>window.print();</script></main></body></html>

