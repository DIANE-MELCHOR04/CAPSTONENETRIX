<?php
require_once __DIR__ . '/lib/inventory.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission('view_inventory');
require_once __DIR__ . '/config/db.php';

$q = filter_text($_GET, 'q');
$locationId = filter_int_choice($_GET, 'location_id');
$condition = filter_choice($_GET, 'condition', ['low', 'healthy']);
$role = (string) ($_SESSION['role'] ?? '');
$userSiteId = current_user_site_id();
$locations = $role === 'site_engineer'
    ? (($userSiteId === null) ? [] : $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 AND site_id = ' . (int) $userSiteId . ' ORDER BY type, name')->fetchAll())
    : $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
if ($role === 'site_engineer') {
    $where[] = 'l.site_id = ?';
    $params[] = $userSiteId ?? 0;
}
filter_where_like($where, $params, ['m.material_code', 'm.material_name', 'l.name'], $q);
filter_where_equals($where, $params, 'b.location_id', $locationId);
if ($condition === 'low') {
    $where[] = '(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point';
} elseif ($condition === 'healthy') {
    $where[] = '(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) > m.reorder_point';
}
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT b.*, m.material_code, m.material_name, m.reorder_point, u.name AS unit_name, l.name AS location_name
     FROM inventory_balances b
     JOIN materials m ON m.id = b.material_id
     JOIN units u ON u.id = m.unit_id
     JOIN locations l ON l.id = b.location_id
     {$whereSql}
     ORDER BY l.name, m.material_name"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'inventory';
$title = 'Current Inventory';
$eyebrow = 'INVENTORY';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">LOCATION BALANCES</span><h2>Material stock by warehouse and site</h2><p>Available stock is calculated as on-hand minus reserved and damaged quantities.</p></div><?php if (role_can($_SESSION['role'], 'post_stock_in')): ?><a class="btn glass" href="stock_in.php">Record Stock-In</a><?php endif; ?></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Material, code, or location"></label><label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>Condition<select name="condition"><option value="">All stock</option><option value="low" <?= $condition === 'low' ? 'selected' : '' ?>>Low stock</option><option value="healthy" <?= $condition === 'healthy' ? 'selected' : '' ?>>Healthy</option></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="inventory.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Location</th><th>On Hand</th><th>Reserved</th><th>Damaged</th><th>Available</th><th>Condition</th></tr></thead><tbody><?php foreach ($rows as $row): ?><?php $available = available_stock((float) $row['quantity_on_hand'], (float) $row['reserved_quantity'], (float) $row['damaged_quantity']); ?><tr><td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td><td><?= e($row['location_name']) ?></td><td><?= money_or_qty($row['quantity_on_hand']) ?> <?= e($row['unit_name']) ?></td><td><?= money_or_qty($row['reserved_quantity']) ?></td><td><?= money_or_qty($row['damaged_quantity']) ?></td><td><b><?= money_or_qty($available) ?></b></td><td><span class="workflow-badge <?= $available <= (float) $row['reorder_point'] ? 'for_procurement' : 'completed' ?>"><?= $available <= (float) $row['reorder_point'] ? 'Low stock' : 'Healthy' ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
