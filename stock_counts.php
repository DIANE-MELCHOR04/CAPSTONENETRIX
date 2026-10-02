<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/adjustments.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_stock_counts', 'manage_stock_counts', 'approve_stock_counts']);
require_once __DIR__ . '/config/db.php';

$countStatuses = ['draft','submitted','approved','adjustment_created','rejected','cancelled'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $countStatuses);
$locationId = filter_int_choice($_GET, 'location_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
filter_where_like($where, $params, ['c.count_no', 'l.name', 'u.full_name', 'c.remarks', 'c.approval_remarks'], $q);
filter_where_equals($where, $params, 'c.status', $status);
filter_where_equals($where, $params, 'c.location_id', $locationId);
filter_where_between_dates($where, $params, 'c.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT c.*, l.name AS location_name, u.full_name, COUNT(i.id) AS item_count, COALESCE(SUM(ABS(i.variance_qty)), 0) AS variance_total
     FROM stock_counts c
     JOIN locations l ON l.id = c.location_id
     JOIN users u ON u.id = c.counted_by
     LEFT JOIN stock_count_items i ON i.stock_count_id = c.id
     {$whereSql}
     GROUP BY c.id
     ORDER BY c.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this list.');
    }
    send_csv('stock-counts-' . date('Ymd') . '.csv', ['Count No.', 'Location', 'Counted By', 'Items', 'Variance Total', 'Status', 'Created At'], array_map(static fn($row): array => [$row['count_no'], $row['location_name'], $row['full_name'], $row['item_count'], $row['variance_total'], str_replace('_', ' ', $row['status']), $row['created_at']], $rows));
}
$activePage = 'stock_counts';
$title = 'Stock Counts';
$eyebrow = 'PHYSICAL COUNT';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('stock_counts') ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">COUNT SESSIONS</span><h2>Physical counts and variance correction</h2><p>Capture counted quantities by location, compare with system balances, and prepare approved corrections.</p></div><div class="hero-actions"><?php if (role_can((string) ($_SESSION['role'] ?? ''), 'manage_stock_counts')): ?><a class="btn glass" href="stock_count_create.php">New Stock Count</a><?php endif; ?><?php if ($canExport): ?><a class="btn glass subtle" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Count no., location, counted by, remarks"></label><label>Status<select name="status"><?php render_status_options($countStatuses, $status); ?></select></label><label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="stock_counts.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Location</th><th>Counted By</th><th>Items</th><th>Variance</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No stock counts found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['count_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['location_name']) ?></td><td><?= e($row['full_name']) ?></td><td><?= (int) $row['item_count'] ?></td><td><?= money_or_qty($row['variance_total']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><a class="table-action" href="stock_count_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
