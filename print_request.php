<?php
require_once __DIR__ . '/lib/requests.php';
require_login();
require_once __DIR__ . '/config/db.php';
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT r.*, u.full_name, s.name AS site_name FROM material_requests r JOIN users u ON u.id = r.requested_by JOIN sites s ON s.id = r.site_id WHERE r.id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) die('Request not found.');
require_site_engineer_record_access((int) $request['site_id'], (int) $request['requested_by']);
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, un.name AS unit_name FROM material_request_items i JOIN materials m ON m.id = i.material_id JOIN units un ON un.id = m.unit_id WHERE i.request_id = ?');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title><?= e($request['request_no'] ?? 'Request') ?></title><?php require __DIR__ . '/partials/assets.php'; ?><style>
body{background:#eef2f3}.print-doc{max-width:900px;margin:28px auto;padding:38px;color:#17232c;background:#fff;border:1px solid #d6e0e4}.print-brand{display:flex;align-items:center;justify-content:space-between;padding-bottom:18px;border-bottom:3px solid #111820}.print-brand strong{display:block;font-size:18px}.print-brand span{color:#64727b;font-size:11px}.print-title{display:flex;align-items:end;justify-content:space-between;margin:24px 0}.print-title h1{margin:0;font-size:25px}.print-title b{font-family:monospace}.print-meta{display:grid;grid-template-columns:repeat(4,1fr);margin-bottom:20px;border:1px solid #d6e0e4}.print-meta div{padding:10px;border-right:1px solid #d6e0e4}.print-meta div:last-child{border-right:0}.print-meta small,.print-meta strong{display:block}.print-meta small{color:#64727b;font-size:9px;text-transform:uppercase}.print-meta strong{margin-top:3px;font-size:11px}.no-print{margin-bottom:16px}@media print{body{background:#fff}.no-print{display:none}.print-doc{margin:0;padding:0;border:0}.print-meta{break-inside:avoid}thead{display:table-header-group}}
</style></head><body><main class="print-doc"><button class="btn secondary no-print" onclick="window.print()">Print</button><header class="print-brand"><div><strong>Metriccon Co., Inc.</strong><span>Centralized Inventory Forecasting System</span></div><span>Material Control Document</span></header><div class="print-title"><h1>Material Request Slip</h1><b><?= e($request['request_no']) ?></b></div><section class="print-meta"><div><small>Site</small><strong><?= e($request['site_name']) ?></strong></div><div><small>Requested by</small><strong><?= e($request['full_name']) ?></strong></div><div><small>Date needed</small><strong><?= e(date('M d, Y', strtotime($request['date_needed']))) ?></strong></div><div><small>Status</small><strong><?= e(str_replace('_', ' ', $request['status'])) ?></strong></div></section><table class="data modern-table"><thead><tr><th>Material</th><th>Requested</th><th>Approved</th><th>Status</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['requested_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['approved_qty']) ?></td><td><?= e(str_replace('_', ' ', $item['item_status'])) ?></td></tr><?php endforeach; ?></tbody></table></main></body></html>
