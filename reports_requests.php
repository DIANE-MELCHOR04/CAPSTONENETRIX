<?php
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission('view_reports');
require_once __DIR__ . '/config/db.php';

$requestStatuses = ['draft','submitted','under_review','approved','partially_approved','rejected','checking_stock','partially_reserved','reserved','ready_for_release','partially_released','released','received','completed','cancelled','on_hold','for_procurement'];
$priorities = ['low','normal','high','urgent'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $requestStatuses);
$priority = filter_choice($_GET, 'priority', $priorities);
$siteId = filter_int_choice($_GET, 'site_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$sites = $pdo->query("SELECT id, name AS label FROM sites WHERE status = 'active' ORDER BY name")->fetchAll();
$where = [];
$params = [];
filter_where_like($where, $params, ['r.request_no', 'u.full_name', 's.name', 'r.remarks'], $q);
filter_where_equals($where, $params, 'r.status', $status);
filter_where_equals($where, $params, 'r.priority', $priority);
filter_where_equals($where, $params, 'r.site_id', $siteId);
filter_where_between_dates($where, $params, 'r.request_date', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT r.status, COUNT(DISTINCT r.id) AS total_requests, COUNT(i.id) AS total_lines, COALESCE(SUM(i.requested_qty),0) AS requested_qty, COALESCE(SUM(i.approved_qty),0) AS approved_qty, COALESCE(SUM(i.released_qty),0) AS released_qty FROM material_requests r JOIN users u ON u.id = r.requested_by JOIN sites s ON s.id = r.site_id LEFT JOIN material_request_items i ON i.request_id = r.id {$whereSql} GROUP BY r.status ORDER BY total_requests DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this report.');
    }
    send_csv('request-report-' . date('Ymd') . '.csv', ['Status', 'Requests', 'Lines', 'Requested Qty', 'Approved Qty', 'Released Qty'], array_map(static fn($row): array => [str_replace('_', ' ', $row['status']), $row['total_requests'], $row['total_lines'], $row['requested_qty'], $row['approved_qty'], $row['released_qty']], $rows));
}
$activePage = 'reports_requests';
$title = 'Request Report';
$eyebrow = 'REPORTS';
$exportUrl = filter_export_url(['export' => 'csv']);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head>
<body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?>
<div class="page-content ops-monitoring-page">
    <?= ui_page_intro('Request analytics', 'Request Report', 'Compare request volume and material quantities across workflow statuses.') ?>
    <form class="filter-card" method="get" data-filter-form data-filter-drawer>
        <label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Request no., requester, site, remarks"></label>
        <label>Status<select name="status"><?php render_status_options($requestStatuses, $status); ?></select></label>
        <label>Priority<select name="priority"><option value="">All priorities</option><?php foreach ($priorities as $option): ?><option value="<?= e($option) ?>" <?= $priority === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></label>
        <label>Site<select name="site_id"><option value="0">All sites</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>" <?= $siteId === (int) $site['id'] ? 'selected' : '' ?>><?= e($site['label']) ?></option><?php endforeach; ?></select></label>
        <label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
        <div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="reports_requests.php" data-clear-filters>Clear</a><?php if ($canExport): ?><a class="btn secondary" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div>
        <?= filter_badge(array_sum(array_map(static fn($row): int => (int) $row['total_requests'], $rows))) ?>
    </form>
    <section class="content-card ops-results-section">
        <div class="ops-results-heading"><div><span class="eyebrow">Request summary</span><h2>Requests by Status</h2></div></div>
        <?php if (!$rows): ?>
            <?= ui_empty_state('No request records found', 'Change the filters or return after requests have entered the workflow.', ['label' => 'Clear filters', 'url' => 'reports_requests.php', 'class' => 'secondary']) ?>
        <?php else: ?>
            <div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table" data-sortable-table data-page-size="15"><thead><tr><th>Status</th><th>Requests</th><th>Lines</th><th>Requested Qty</th><th>Approved Qty</th><th>Released Qty</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= ui_status_badge((string) $row['status']) ?></td><td><b><?= (int) $row['total_requests'] ?></b></td><td><?= (int) $row['total_lines'] ?></td><td><?= money_or_qty($row['requested_qty']) ?></td><td><?= money_or_qty($row['approved_qty']) ?></td><td><?= money_or_qty($row['released_qty']) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="ops-pagination" data-table-pagination></div></div>
        <?php endif; ?>
    </section>
</div></main></div></body></html>
