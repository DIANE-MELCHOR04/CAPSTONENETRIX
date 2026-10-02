<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/procurement.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'manage_procurement') && !role_can($role, 'approve_procurement')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$purchaseRequestStatuses = ['draft','submitted','approved','rejected','ordered','partially_ordered','partially_received','completed','cancelled'];
$priorities = ['low','normal','high','urgent'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $purchaseRequestStatuses);
$priority = filter_choice($_GET, 'priority', $priorities);
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$params = [];
$where = [];
filter_where_like($where, $params, ['pr.pr_no', 'u.full_name', 'pr.source_type', 'pr.remarks'], $q);
filter_where_equals($where, $params, 'pr.status', $status);
filter_where_equals($where, $params, 'pr.priority', $priority);
filter_where_between_dates($where, $params, 'pr.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT pr.*, u.full_name, COUNT(i.id) AS item_count, COALESCE(SUM(i.requested_qty), 0) AS total_qty
     FROM purchase_requests pr
     JOIN users u ON u.id = pr.requested_by
     LEFT JOIN purchase_request_items i ON i.purchase_request_id = pr.id
     {$whereSql}
     GROUP BY pr.id
     ORDER BY pr.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canManageProcurement = role_can($role, 'manage_procurement');
$canExport = role_can($role, 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this list.');
    }
    send_csv('purchase-requests-' . date('Ymd') . '.csv', ['PR No.', 'Requested By', 'Priority', 'Items', 'Total Qty', 'Status', 'Created At'], array_map(static fn($row): array => [$row['pr_no'], $row['full_name'], $row['priority'], $row['item_count'], $row['total_qty'], str_replace('_', ' ', $row['status']), $row['created_at']], $rows));
}
$exportUrl = filter_export_url(['export' => 'csv']);
$activePage = 'purchase_requests';
$title = 'Purchase Requests';
$eyebrow = 'PROCUREMENT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('purchase_requests') ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">PURCHASE REQUESTS</span><h2>Materials prepared for supplier ordering</h2><p>Review procurement needs from shortages, reorder monitoring, and manual management requests.</p></div><?php if ($canManageProcurement): ?><a class="btn glass" href="purchase_request_create.php">New Purchase Request</a><?php endif; ?></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="PR no., requester, source, remarks"></label><label>Status<select name="status"><?php render_status_options($purchaseRequestStatuses, $status); ?></select></label><label>Priority<select name="priority"><option value="">All priorities</option><?php foreach ($priorities as $option): ?><option value="<?= e($option) ?>" <?= $priority === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="purchase_requests.php" data-clear-filters>Clear</a><?php if ($canExport): ?><a class="btn secondary" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Requested By</th><th>Priority</th><th>Items</th><th>Total Qty</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No purchase requests found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['pr_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['full_name']) ?></td><td><?= e(ucfirst($row['priority'])) ?></td><td><?= (int) $row['item_count'] ?></td><td><?= money_or_qty($row['total_qty']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><a class="table-action" href="purchase_request_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
