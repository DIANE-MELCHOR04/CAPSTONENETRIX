<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/returns.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_returns', 'create_returns', 'post_returns']);
require_once __DIR__ . '/config/db.php';

$returnStatuses = ['draft','submitted','posted','cancelled'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $returnStatuses);
$siteId = filter_int_choice($_GET, 'site_id');
$locationId = filter_int_choice($_GET, 'location_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$role = (string) ($_SESSION['role'] ?? '');
$userSiteId = current_user_site_id();
$sites = $role === 'site_engineer'
    ? (($userSiteId === null) ? [] : $pdo->query("SELECT id, name AS label FROM sites WHERE status = 'active' AND id = " . (int) $userSiteId)->fetchAll())
    : $pdo->query("SELECT id, name AS label FROM sites WHERE status = 'active' ORDER BY name")->fetchAll();
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
if ($role === 'site_engineer') {
    $where[] = 'r.source_site_id = ? AND r.returned_by = ?';
    $params[] = $userSiteId ?? 0;
    $params[] = (int) current_user()['id'];
}
filter_where_like($where, $params, ['r.return_no', 's.name', 'l.name', 'u.full_name', 'r.remarks'], $q);
filter_where_equals($where, $params, 'r.status', $status);
filter_where_equals($where, $params, 'r.source_site_id', $siteId);
filter_where_equals($where, $params, 'r.receiving_location_id', $locationId);
filter_where_between_dates($where, $params, 'r.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT r.*, s.name AS site_name, l.name AS location_name, u.full_name,
            COUNT(i.id) AS item_count, COALESCE(SUM(i.returned_qty), 0) AS total_qty
     FROM material_returns r
     JOIN sites s ON s.id = r.source_site_id
     JOIN locations l ON l.id = r.receiving_location_id
     JOIN users u ON u.id = r.returned_by
     LEFT JOIN material_return_items i ON i.return_id = r.id
     {$whereSql}
     GROUP BY r.id
     ORDER BY r.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this list.');
    }
    send_csv('material-returns-' . date('Ymd') . '.csv', ['Return No.', 'Source Site', 'Receiving Location', 'Returned By', 'Items', 'Total Qty', 'Status', 'Created At'], array_map(static fn($row): array => [$row['return_no'], $row['site_name'], $row['location_name'], $row['full_name'], $row['item_count'], $row['total_qty'], str_replace('_', ' ', $row['status']), $row['created_at']], $rows));
}
$activePage = 'returns';
$title = 'Material Returns';
$eyebrow = 'RETURN CONTROL';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?php if (role_can($role, 'post_returns')): ?><?= bulk_import_panel('returns') ?><?php endif; ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">SITE RETURNS</span><h2>Unused materials returned to warehouse control</h2><p>Classify returned materials as reusable, damaged, partially damaged, or for inspection before posting them to inventory.</p></div><div class="hero-actions"><?php if (role_can($role, 'create_returns')): ?><a class="btn glass" href="return_create.php">New Return</a><?php endif; ?><?php if ($canExport): ?><a class="btn glass subtle" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Return no., site, location, user, remarks"></label><label>Status<select name="status"><?php render_status_options($returnStatuses, $status); ?></select></label><label>Source Site<select name="site_id"><option value="0">All sites</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>" <?= $siteId === (int) $site['id'] ? 'selected' : '' ?>><?= e($site['label']) ?></option><?php endforeach; ?></select></label><label>Receiving<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="returns.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Source Site</th><th>Receiving Location</th><th>Items</th><th>Total Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No returns found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['return_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['site_name']) ?></td><td><?= e($row['location_name']) ?></td><td><?= (int) $row['item_count'] ?></td><td><?= money_or_qty($row['total_qty']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><a class="table-action" href="return_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
