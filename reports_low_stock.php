<?php
require_once __DIR__ . '/lib/inventory.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_reports', 'view_inventory_reports']);
require_once __DIR__ . '/config/db.php';

$q = filter_text($_GET, 'q');
$locationId = filter_int_choice($_GET, 'location_id');
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();
$where = ['(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point'];
$params = [];
filter_where_like($where, $params, ['l.name', 'm.material_code', 'm.material_name', 'u.name'], $q);
filter_where_equals($where, $params, 'b.location_id', $locationId);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT l.name AS location_name, m.material_code, m.material_name, m.reorder_point, m.safety_stock, u.name AS unit_name, b.quantity_on_hand, b.reserved_quantity, b.damaged_quantity, (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) AS available_qty FROM inventory_balances b JOIN materials m ON m.id = b.material_id JOIN units u ON u.id = m.unit_id JOIN locations l ON l.id = b.location_id {$whereSql} ORDER BY available_qty ASC, m.material_name");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this report.');
    }
    send_csv('low-stock-report-' . date('Ymd') . '.csv', ['Material Code', 'Material Name', 'Location', 'Available', 'Reorder Point', 'Safety Stock', 'Unit'], array_map(static fn($row): array => [$row['material_code'], $row['material_name'], $row['location_name'], $row['available_qty'], $row['reorder_point'], $row['safety_stock'], $row['unit_name']], $rows));
}
$activePage = 'reports_low_stock';
$title = 'Low Stock Report';
$eyebrow = 'REPORTS';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content"><section class="monitoring-top-card"><div><span class="eyebrow light">REPLENISHMENT WATCH</span><h2>Materials at or below reorder point</h2><p>Prioritize critical balances, verify incoming supply, and coordinate replenishment before site demand is affected.</p></div><?php if ($canExport): ?><a class="btn glass" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></section><form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Material, code, location, unit"></label><label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="reports_low_stock.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form><section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Location</th><th>Available</th><th>Reorder Point</th><th>Safety Stock</th><th>Status</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="6" class="muted">No low-stock materials found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td><td><?= e($row['location_name']) ?></td><td><?= money_or_qty($row['available_qty']) ?> <?= e($row['unit_name']) ?></td><td><?= money_or_qty($row['reorder_point']) ?></td><td><?= money_or_qty($row['safety_stock']) ?></td><td><span class="workflow-badge for_procurement">Needs review</span></td></tr><?php endforeach; ?></tbody></table></div></section></div></main></div></body></html>
