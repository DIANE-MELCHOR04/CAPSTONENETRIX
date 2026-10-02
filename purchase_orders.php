<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/procurement.php';
require_once __DIR__ . '/lib/search_filters.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'manage_procurement') && !role_can($role, 'approve_procurement') && !role_can($role, 'receive_supplier_deliveries')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$purchaseOrderStatuses = ['submitted','open','partially_received','received','rejected','cancelled'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $purchaseOrderStatuses);
$supplierId = filter_int_choice($_GET, 'supplier_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$suppliers = $pdo->query('SELECT id, company FROM suppliers WHERE is_active = 1 ORDER BY company')->fetchAll();

$where = [];
$params = [];
filter_where_like($where, $params, ['po.po_no', 's.company', 'po.remarks'], $q);
filter_where_equals($where, $params, 'po.status', $status);
filter_where_equals($where, $params, 'po.supplier_id', $supplierId);
filter_where_between_dates($where, $params, 'po.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT po.*, s.company, COALESCE(SUM(i.ordered_qty), 0) AS ordered_total, COALESCE(SUM(i.received_qty), 0) AS received_total
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN purchase_order_items i ON i.purchase_order_id = po.id
     {$whereSql}
     GROUP BY po.id
     ORDER BY po.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'purchase_orders';
$title = 'Purchase Orders';
$eyebrow = 'PROCUREMENT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?php if (role_can($role, 'receive_supplier_deliveries')): ?><?= bulk_import_panel('supplier_deliveries') ?><?php endif; ?>

<section class="monitoring-top-card"><div><span class="eyebrow light">SUPPLIER ORDERS</span><h2>Purchase orders and executive approvals</h2><p>Monitor orders awaiting presidential approval, supplier commitments, expected deliveries, and received quantities.</p></div></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="PO no., supplier, remarks"></label><label>Status<select name="status"><?php render_status_options($purchaseOrderStatuses, $status); ?></select></label><label>Supplier<select name="supplier_id"><option value="0">All suppliers</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= $supplierId === (int) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['company']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="purchase_orders.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>Supplier</th><th>Ordered</th><th>Received</th><th>Status</th><th>Expected</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="7" class="muted">No purchase orders found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['po_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['created_at']))) ?></small></td><td><?= e($row['company']) ?></td><td><?= money_or_qty($row['ordered_total']) ?></td><td><?= money_or_qty($row['received_total']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e(str_replace('_', ' ', $row['status'])) ?></span></td><td><?= $row['expected_delivery'] ? e(date('M d, Y', strtotime($row['expected_delivery']))) : 'Open' ?></td><td><a class="table-action" href="purchase_order_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
