<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/adjustments.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission(role_can((string) ($_SESSION['role'] ?? ''), 'manage_adjustments') ? 'manage_adjustments' : 'approve_adjustments');
require_once __DIR__ . '/config/db.php';

$adjustmentStatuses = ['draft','submitted','approved','rejected','cancelled'];
$adjustmentTypes = ['positive','negative','physical_count','encoding_correction','damage','loss','found_stock','other'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $adjustmentStatuses);
$type = filter_choice($_GET, 'type', $adjustmentTypes);
$locationId = filter_int_choice($_GET, 'location_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
filter_where_like($where, $params, ['a.adjustment_no', 'l.name', 'u.full_name', 'a.reason', 'a.approval_remarks'], $q);
filter_where_equals($where, $params, 'a.status', $status);
filter_where_equals($where, $params, 'a.adjustment_type', $type);
filter_where_equals($where, $params, 'a.location_id', $locationId);
filter_where_between_dates($where, $params, 'a.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT a.*, l.name AS location_name, u.full_name, COUNT(i.id) AS item_count, COALESCE(SUM(i.adjustment_qty), 0) AS net_qty
     FROM inventory_adjustments a
     JOIN locations l ON l.id = a.location_id
     JOIN users u ON u.id = a.requested_by
     LEFT JOIN inventory_adjustment_items i ON i.adjustment_id = a.id
     {$whereSql}
     GROUP BY a.id
     ORDER BY a.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this list.');
    }
    send_csv('inventory-adjustments-' . date('Ymd') . '.csv', ['Adjustment No.', 'Location', 'Type', 'Requested By', 'Items', 'Net Qty', 'Status', 'Created At'], array_map(static fn($row): array => [$row['adjustment_no'], $row['location_name'], str_replace('_', ' ', $row['adjustment_type']), $row['full_name'], $row['item_count'], $row['net_qty'], str_replace('_', ' ', $row['status']), $row['created_at']], $rows));
}
$activePage = 'adjustments';
$title = 'Inventory Adjustments';
$eyebrow = 'BALANCE CONTROL';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('adjustments') ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">ADJUSTMENTS</span><h2>Approved corrections to recorded inventory</h2><p>Document balance corrections with before and after quantities, reason, approval, and movement ledger entries.</p></div><div class="hero-actions"><?php if (role_can($_SESSION['role'], 'manage_adjustments')): ?><a class="btn glass" href="adjustment_create.php">New Adjustment</a><?php endif; ?><?php if ($canExport): ?><a class="btn glass subtle" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Adjustment no., location, user, reason"></label><label>Status<select name="status"><?php render_status_options($adjustmentStatuses, $status); ?></select></label><label>Type<select name="type"><?php render_status_options($adjustmentTypes, $type, 'All types'); ?></select></label><label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="adjustments.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Location</th><th>Type</th><th>Items</th><th>Net Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No adjustments found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['adjustment_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['location_name']) ?></td><td><?= e(str_replace('_', ' ', $row['adjustment_type'])) ?></td><td><?= (int) $row['item_count'] ?></td><td><?= money_or_qty($row['net_qty']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><a class="table-action" href="adjustment_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
