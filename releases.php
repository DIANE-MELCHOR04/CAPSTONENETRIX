<?php
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/search_filters.php';
require_login();
$role = (string) ($_SESSION['role'] ?? '');
if (!role_can($role, 'release_materials') && !role_can($role, 'confirm_receipt') && !role_can($role, 'view_requests')) {
    http_response_code(403);
    die('You do not have permission to access this page.');
}
require_once __DIR__ . '/config/db.php';

$releaseStatuses = ['released','partially_received','received'];
$q = filter_text($_GET, 'q');
$status = filter_choice($_GET, 'status', $releaseStatuses);
$locationId = filter_int_choice($_GET, 'location_id');
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$where = [];
$params = [];
if ($role === 'site_engineer') {
    $where[] = 'r.requested_by = ?';
    $params[] = (int) current_user()['id'];
}
filter_where_like($where, $params, ['rel.release_no', 'r.request_no', 's.name', 'l.name', 'u.full_name', 'rel.released_to', 'rel.remarks'], $q);
filter_where_equals($where, $params, 'rel.status', $status);
filter_where_equals($where, $params, 'rel.source_location_id', $locationId);
filter_where_between_dates($where, $params, 'rel.release_date', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT rel.*, r.request_no, s.name AS site_name, l.name AS location_name, u.full_name AS released_by_name,
            COUNT(ri.id) AS item_count, COALESCE(SUM(ri.released_qty), 0) AS released_total, COALESCE(SUM(ri.received_qty), 0) AS received_total
     FROM material_releases rel
     JOIN material_requests r ON r.id = rel.request_id
     JOIN sites s ON s.id = r.site_id
     JOIN locations l ON l.id = rel.source_location_id
     JOIN users u ON u.id = rel.released_by
     LEFT JOIN material_release_items ri ON ri.release_id = rel.id
     {$whereSql}
     GROUP BY rel.id
     ORDER BY rel.release_date DESC, rel.id DESC
     LIMIT 300"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$readyRequests = [];
if (role_can($role, 'release_materials')) {
    $readyStmt = $pdo->prepare(
        "SELECT r.id, r.request_no, r.status, r.date_needed, r.priority,
                s.name AS site_name, u.full_name AS requested_by_name,
                SUM(GREATEST(0, mr.reserved_qty - mr.released_qty)) AS releasable_qty,
                COUNT(DISTINCT mr.id) AS reservation_count
         FROM material_requests r
         JOIN sites s ON s.id = r.site_id
         JOIN users u ON u.id = r.requested_by
         JOIN material_request_items i ON i.request_id = r.id
         JOIN material_reservations mr ON mr.request_item_id = i.id
         WHERE mr.status <> 'cancelled'
           AND mr.reserved_qty > mr.released_qty
         GROUP BY r.id, r.request_no, r.status, r.date_needed, r.priority, s.name, u.full_name
         ORDER BY r.date_needed ASC, r.created_at ASC
         LIMIT 100"
    );
    $readyStmt->execute();
    $readyRequests = $readyStmt->fetchAll();
}
$activePage = 'releases';
$title = 'Material Releases';
$eyebrow = 'RECEIPT MONITORING';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content ops-monitoring-page">
<?= ui_page_intro('Release queue', 'Material Releases', 'Release quantities already reserved for approved requests, then track posted releases awaiting receipt confirmation.', ['label' => 'Material Requests', 'url' => 'requests.php', 'class' => 'secondary']) ?>
<?php if (role_can($role, 'release_materials')): ?>
<section class="content-card ops-results-section">
    <div class="ops-results-heading"><div><span class="eyebrow">WAREHOUSE ACTION</span><h2>Requests Ready for Release</h2></div><span><?= number_format(count($readyRequests)) ?> request(s)</span></div>
    <?php if (!$readyRequests): ?>
        <?= ui_empty_state('No reserved materials waiting for release', 'A request appears here as soon as it has an unreleased reservation. Requests may still have a separate procurement shortage.') ?>
    <?php else: ?>
        <div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table" data-sortable-table><thead><tr><th>Request</th><th>Site</th><th>Requested By</th><th>Needed</th><th>Status</th><th>Reserved to Release</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($readyRequests as $ready): ?><tr>
            <td><b><?= e($ready['request_no']) ?></b><small><?= e(ucfirst((string) $ready['priority'])) ?> priority</small></td>
            <td><?= e($ready['site_name']) ?></td>
            <td><?= e($ready['requested_by_name']) ?></td>
            <td><?= e(date('M d, Y', strtotime($ready['date_needed']))) ?></td>
            <td><?= ui_status_badge((string) $ready['status']) ?></td>
            <td><b><?= money_or_qty($ready['releasable_qty']) ?></b><small><?= (int) $ready['reservation_count'] ?> reservation(s)</small></td>
            <td><a class="btn primary compact" href="release_create.php?id=<?= (int) $ready['id'] ?>">Release Reserved Portion</a></td>
        </tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<form class="filter-card" method="get" data-filter-form data-filter-drawer><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Release no., request no., site, recipient"></label><label>Status<select name="status"><?php render_status_options($releaseStatuses, $status); ?></select></label><label>Source<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="releases.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card ops-results-section"><div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table" data-sortable-table data-page-size="15"><thead><tr><th>Release</th><th>Request</th><th>Site</th><th>Source</th><th>Released</th><th>Received</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="8" class="muted">No releases match the current filters.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['release_no']) ?></b><small><?= e(date('M d, Y h:i A', strtotime($row['release_date']))) ?></small></td><td><?= e($row['request_no']) ?><small><?= e($row['released_by_name']) ?></small></td><td><?= e($row['site_name']) ?><small><?= e($row['released_to'] ?? '') ?></small></td><td><?= e($row['location_name']) ?></td><td><?= money_or_qty($row['released_total']) ?><small><?= (int) $row['item_count'] ?> line(s)</small></td><td><?= money_or_qty($row['received_total']) ?></td><td><?= ui_status_badge((string) $row['status']) ?></td><td><a class="table-action" href="release_view.php?id=<?= (int) $row['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div><div class="ops-pagination" data-table-pagination></div></div></section>
</div></main></div></body></html>
