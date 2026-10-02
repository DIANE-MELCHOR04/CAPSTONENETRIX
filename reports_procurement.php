<?php
require_once __DIR__ . '/lib/procurement.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_reports', 'view_procurement_reports']);
require_once __DIR__ . '/config/db.php';

$purchaseRequestStatuses = ['draft','submitted','approved','rejected','ordered','partially_ordered','partially_received','completed','cancelled'];
$purchaseOrderStatuses = ['open','partially_received','received','cancelled'];
$q = filter_text($_GET, 'q');
$prStatus = filter_choice($_GET, 'pr_status', $purchaseRequestStatuses);
$poStatus = filter_choice($_GET, 'po_status', $purchaseOrderStatuses);
$supplierId = filter_int_choice($_GET, 'supplier_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$suppliers = $pdo->query('SELECT id, company FROM suppliers WHERE is_active = 1 ORDER BY company')->fetchAll();
$where = [];
$params = [];
filter_where_like($where, $params, ['pr.pr_no', 'po.po_no', 's.company', 'pr.remarks', 'po.remarks'], $q);
filter_where_equals($where, $params, 'pr.status', $prStatus);
filter_where_equals($where, $params, 'po.status', $poStatus);
filter_where_equals($where, $params, 'po.supplier_id', $supplierId);
filter_where_between_dates($where, $params, 'pr.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT pr.pr_no, pr.status AS pr_status, po.po_no, po.status AS po_status, s.company, COALESCE(SUM(poi.ordered_qty), 0) AS ordered_qty, COALESCE(SUM(poi.received_qty), 0) AS received_qty
     FROM purchase_requests pr
     LEFT JOIN purchase_orders po ON po.purchase_request_id = pr.id
     LEFT JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
     {$whereSql}
     GROUP BY pr.id, po.id
     ORDER BY pr.created_at DESC, po.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this report.');
    }
    send_csv('procurement-report-' . date('Ymd') . '.csv', ['PR No.', 'PR Status', 'PO No.', 'Supplier', 'Ordered Qty', 'Received Qty', 'PO Status'], array_map(static fn($row): array => [$row['pr_no'], str_replace('_', ' ', $row['pr_status']), $row['po_no'] ?? '', $row['company'] ?? '', $row['ordered_qty'], $row['received_qty'], $row['po_status'] ? str_replace('_', ' ', $row['po_status']) : ''], $rows));
}
$activePage = 'reports_procurement';
$title = 'Procurement Report';
$eyebrow = 'REPORTS';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">PROCUREMENT REPORT</span><h2>Purchase request, order, and delivery status</h2><p>Track procurement quantities from approved need to supplier receipt.</p></div><?php if ($canExport): ?><a class="btn glass" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="PR no., PO no., supplier, remarks"></label><label>PR Status<select name="pr_status"><?php render_status_options($purchaseRequestStatuses, $prStatus); ?></select></label><label>PO Status<select name="po_status"><?php render_status_options($purchaseOrderStatuses, $poStatus); ?></select></label><label>Supplier<select name="supplier_id"><option value="0">All suppliers</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= $supplierId === (int) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['company']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="reports_procurement.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>PR No.</th><th>PR Status</th><th>PO No.</th><th>Supplier</th><th>Ordered</th><th>Received</th><th>PO Status</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No procurement records found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><?= e($row['pr_no']) ?></td><td><span class="workflow-badge <?= e($row['pr_status']) ?>"><?= e(str_replace('_', ' ', $row['pr_status'])) ?></span></td><td><?= e($row['po_no'] ?? '') ?></td><td><?= e($row['company'] ?? '') ?></td><td><?= money_or_qty($row['ordered_qty']) ?></td><td><?= money_or_qty($row['received_qty']) ?></td><td><?= $row['po_status'] ? '<span class="workflow-badge ' . e($row['po_status']) . '">' . e(str_replace('_', ' ', $row['po_status'])) . '</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
