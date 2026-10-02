<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/damage.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['manage_damage','report_site_damage','approve_damage']);
require_once __DIR__ . '/config/db.php';

$damageStatuses = ['draft','submitted','approved','rejected','cancelled'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $damageStatuses);
$locationId = filter_int_choice($_GET, 'location_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
if ((string) ($_SESSION['role'] ?? '') === 'site_engineer') { $where[]='d.reported_by = ?'; $params[]=(int) current_user()['id']; }
filter_where_like($where, $params, ['d.damage_no', 'l.name', 'u.full_name', 'd.reason', 'd.approval_remarks'], $q);
filter_where_equals($where, $params, 'd.status', $status);
filter_where_equals($where, $params, 'd.location_id', $locationId);
filter_where_between_dates($where, $params, 'd.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT d.*, l.name AS location_name, u.full_name, COUNT(i.id) AS item_count, COALESCE(SUM(i.damaged_qty), 0) AS total_qty
     FROM damage_reports d
     JOIN locations l ON l.id = d.location_id
     JOIN users u ON u.id = d.reported_by
     LEFT JOIN damage_report_items i ON i.damage_report_id = d.id
     {$whereSql}
     GROUP BY d.id
     ORDER BY d.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this list.');
    }
    send_csv('damage-reports-' . date('Ymd') . '.csv', ['Damage No.', 'Location', 'Reported By', 'Items', 'Total Qty', 'Status', 'Created At'], array_map(static fn($row): array => [$row['damage_no'], $row['location_name'], $row['full_name'], $row['item_count'], $row['total_qty'], str_replace('_', ' ', $row['status']), $row['created_at']], $rows));
}
$activePage = 'damage_reports';
$title = 'Damaged Materials';
$eyebrow = 'DAMAGE CONTROL';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('damage_reports') ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">DAMAGE WATCH</span><h2>Reported damaged quantities for manager review</h2><p>Document damaged materials and approve authorized inventory write-off only after review.</p></div><div class="hero-actions"><?php if (role_can((string)$_SESSION['role'], 'manage_damage') || role_can((string)$_SESSION['role'], 'report_site_damage')): ?><a class="btn glass" href="damage_report_create.php">New Damage Report</a><?php endif; ?><?php if ($canExport): ?><a class="btn glass subtle" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Damage no., location, user, reason"></label><label>Status<select name="status"><?php render_status_options($damageStatuses, $status); ?></select></label><label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="damage_reports.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Location</th><th>Reported By</th><th>Items</th><th>Total Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No damage reports found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['damage_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['location_name']) ?></td><td><?= e($row['full_name']) ?></td><td><?= (int) $row['item_count'] ?></td><td><?= money_or_qty($row['total_qty']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td><a class="table-action" href="damage_report_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
