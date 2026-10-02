<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/transfers.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_transfers', 'create_transfers', 'create_site_transfers', 'dispatch_transfers', 'receive_transfers', 'approve_transfers']);
require_once __DIR__ . '/config/db.php';

$transferStatuses = ['draft','submitted','approved','rejected','in_transit','partially_received','received','discrepancy','cancelled'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $transferStatuses);
$sourceId = filter_int_choice($_GET, 'source_location_id');
$destinationId = filter_int_choice($_GET, 'destination_location_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
if ((string) ($_SESSION['role'] ?? '') === 'site_engineer') { $where[] = 't.requested_by = ?'; $params[] = (int) current_user()['id']; }
filter_where_like($where, $params, ['t.transfer_no', 'src.name', 'dst.name', 'u.full_name', 't.remarks'], $q);
filter_where_equals($where, $params, 't.status', $status);
filter_where_equals($where, $params, 't.source_location_id', $sourceId);
filter_where_equals($where, $params, 't.destination_location_id', $destinationId);
filter_where_between_dates($where, $params, 't.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT t.*, src.name AS source_name, dst.name AS destination_name, u.full_name,
            COUNT(i.id) AS item_count, COALESCE(SUM(i.requested_qty), 0) AS total_qty
     FROM material_transfers t
     JOIN locations src ON src.id = t.source_location_id
     JOIN locations dst ON dst.id = t.destination_location_id
     JOIN users u ON u.id = t.requested_by
     LEFT JOIN material_transfer_items i ON i.transfer_id = t.id
     {$whereSql}
     GROUP BY t.id
     ORDER BY t.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'transfers';
$title = 'Material Transfers';
$eyebrow = 'INVENTORY TRANSFER';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?php if ((role_can((string) ($_SESSION['role'] ?? ''), 'create_transfers') || role_can((string) ($_SESSION['role'] ?? ''), 'create_site_transfers'))): ?><?= bulk_import_panel('transfers') ?><?php endif; ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">LOCATION MOVEMENT</span><h2>Transfers between warehouses and project sites</h2><p>Dispatch available materials from a source location, track in-transit quantities, and confirm destination receipt.</p></div><?php if ((role_can((string) ($_SESSION['role'] ?? ''), 'create_transfers') || role_can((string) ($_SESSION['role'] ?? ''), 'create_site_transfers'))): ?><a class="btn glass" href="transfer_create.php">New Transfer</a><?php endif; ?></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Transfer no., route, requester, remarks"></label><label>Status<select name="status"><?php render_status_options($transferStatuses, $status); ?></select></label><label>Source<select name="source_location_id"><option value="0">All sources</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $sourceId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>Destination<select name="destination_location_id"><option value="0">All destinations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $destinationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="transfers.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Route</th><th>Requested By</th><th>Items</th><th>Total Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No transfers found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['transfer_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['source_name']) ?><small>to <?= e($row['destination_name']) ?></small></td><td><?= e($row['full_name']) ?></td><td><?= (int) $row['item_count'] ?></td><td><?= money_or_qty($row['total_qty']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><a class="table-action" href="transfer_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
