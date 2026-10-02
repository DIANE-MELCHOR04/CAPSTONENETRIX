<?php
require_once __DIR__ . '/lib/inventory.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission(role_can((string) ($_SESSION['role'] ?? ''), 'view_movements') ? 'view_movements' : 'view_inventory');
require_once __DIR__ . '/config/db.php';

$movementTypes = ['opening','stock_in','stock_out','reservation','release','receipt','adjustment','transfer_dispatch','transfer_receipt','return','damage','supplier_delivery'];
$q = filter_text($_GET, 'q');
$materialId = filter_int_choice($_GET, 'material_id');
$locationId = filter_int_choice($_GET, 'location_id');
$type = filter_choice($_GET, 'type', $movementTypes);
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$materials = $pdo->query("SELECT id, CONCAT(material_code, ' - ', material_name) AS label FROM materials WHERE is_active = 1 AND approval_status = 'approved' ORDER BY material_name")->fetchAll();
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
filter_where_like($where, $params, ['im.movement_no', 'm.material_code', 'm.material_name', 'l.name', 'usr.full_name', 'im.reference_type', 'im.remarks'], $q);
filter_where_equals($where, $params, 'im.material_id', $materialId);
filter_where_equals($where, $params, 'im.location_id', $locationId);
filter_where_equals($where, $params, 'im.movement_type', $type);
filter_where_between_dates($where, $params, 'im.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT im.*, m.material_code, m.material_name, u.name AS unit_name, l.name AS location_name, usr.full_name FROM inventory_movements im JOIN materials m ON m.id = im.material_id JOIN units u ON u.id = m.unit_id JOIN locations l ON l.id = im.location_id JOIN users usr ON usr.id = im.created_by {$whereSql} ORDER BY im.created_at DESC, im.id DESC LIMIT 500");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'movements';
$title = 'Movement History';
$eyebrow = 'AUDIT LEDGER';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Movement no., material, location, user, remarks"></label><label>Material<select name="material_id"><option value="0">All materials</option><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= $materialId === (int) $material['id'] ? 'selected' : '' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></label><label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>Type<select name="type"><?php render_status_options($movementTypes, $type, 'All types'); ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="movements.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">PERMANENT RECORD</span><h2>Inventory Movement Ledger</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Date</th><th>Material</th><th>Location</th><th>Type</th><th>In</th><th>Out</th><th>Balance</th><th>Encoded By</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="9" class="muted">No movements match the current filters.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['movement_no']) ?></b></td><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td><td><?= e($row['material_code']) ?><small><?= e($row['material_name']) ?></small></td><td><?= e($row['location_name']) ?></td><td><span class="workflow-badge <?= e($row['movement_type']) ?>"><?= e(str_replace('_', ' ', $row['movement_type'])) ?></span><small><?= e($row['remarks']) ?></small></td><td class="qty-positive"><?= money_or_qty($row['quantity_in']) ?> <?= e($row['unit_name']) ?></td><td class="qty-negative"><?= money_or_qty($row['quantity_out']) ?> <?= e($row['unit_name']) ?></td><td><b><?= money_or_qty($row['balance_after']) ?></b></td><td><?= e($row['full_name']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
