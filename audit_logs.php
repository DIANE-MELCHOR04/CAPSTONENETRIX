<?php
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/search_filters.php';
require_once __DIR__ . '/lib/exports.php';
require_permission('view_audit_logs');
require_once __DIR__ . '/config/db.php';

$q = filter_text($_GET, 'q');
$action = filter_text($_GET, 'action', 80);
$entity = filter_text($_GET, 'entity', 80);
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$where = [];
$params = [];
filter_where_like($where, $params, ['a.action', 'a.entity_type', 'a.details', 'u.full_name'], $q);
filter_where_equals($where, $params, 'a.action', $action);
filter_where_equals($where, $params, 'a.entity_type', $entity);
filter_where_between_dates($where, $params, 'a.created_at', $from, $to);
$whereSql = filter_where_sql($where);
$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll();
$entities = $pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll();
$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     {$whereSql}
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 250"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export audit records.');
    }
    send_csv(
        'audit-log-' . date('Ymd') . '.csv',
        ['Date', 'User', 'Action', 'Record Type', 'Record ID', 'Details'],
        array_map(static fn(array $row): array => [
            $row['created_at'],
            $row['full_name'] ?? 'System',
            $row['action'],
            $row['entity_type'],
            $row['entity_id'],
            $row['details'],
        ], $rows)
    );
}
$exportUrl = filter_export_url(['export' => 'csv']);
$activePage = 'audit_logs';
$title = 'Audit Logs';
$eyebrow = 'SYSTEM HISTORY';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">AUDIT TRAIL</span><h2>Recorded user and transaction activity</h2><p>Review who acted, what record was affected, and when the action happened.</p></div></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="User, action, record, details"></label><label>Action<select name="action"><option value="">All actions</option><?php foreach ($actions as $option): ?><option value="<?= e($option['action']) ?>" <?= $action === (string) $option['action'] ? 'selected' : '' ?>><?= e($option['action']) ?></option><?php endforeach; ?></select></label><label>Record Type<select name="entity"><option value="">All records</option><?php foreach ($entities as $option): ?><option value="<?= e($option['entity_type']) ?>" <?= $entity === (string) $option['entity_type'] ? 'selected' : '' ?>><?= e($option['entity_type']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="audit_logs.php" data-clear-filters>Clear</a><?php if ($canExport): ?><a class="btn secondary" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="5" class="muted">No audit records found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td><td><?= e($row['full_name'] ?? 'System') ?></td><td><?= e($row['action']) ?></td><td><?= e($row['entity_type']) ?> #<?= e($row['entity_id']) ?></td><td><?= e($row['details']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
