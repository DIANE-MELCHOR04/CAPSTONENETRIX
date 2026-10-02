<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission(role_can((string) ($_SESSION['role'] ?? ''), 'view_requests') ? 'view_requests' : 'view_own_requests');
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
$scopeWhere = [];
$scopeParams = [];
if ((string) $_SESSION['role'] === 'site_engineer') {
    $where[] = 'r.requested_by = ?';
    $params[] = (int) current_user()['id'];
    $scopeWhere[] = 'requested_by = ?';
    $scopeParams[] = (int) current_user()['id'];
}
filter_where_like($where, $params, ['r.request_no', 'u.full_name', 's.name', 'r.remarks'], $q);
filter_where_equals($where, $params, 'r.status', $status);
filter_where_equals($where, $params, 'r.priority', $priority);
filter_where_equals($where, $params, 'r.site_id', $siteId);
filter_where_between_dates($where, $params, 'r.request_date', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT r.*, u.full_name, s.name AS site_name, COUNT(i.id) AS item_count, COALESCE(SUM(i.requested_qty), 0) AS total_qty,
        COALESCE((
            SELECT SUM(GREATEST(0, mr.reserved_qty - mr.released_qty))
            FROM material_reservations mr
            JOIN material_request_items ri ON ri.id = mr.request_item_id
            WHERE ri.request_id = r.id
              AND mr.status <> 'cancelled'
              AND mr.reserved_qty > mr.released_qty
        ), 0) AS releasable_qty
    FROM material_requests r
    JOIN users u ON u.id = r.requested_by
    JOIN sites s ON s.id = r.site_id
    LEFT JOIN material_request_items i ON i.request_id = r.id
    {$whereSql}
    GROUP BY r.id
    ORDER BY r.created_at DESC
    LIMIT 300");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$scopeSql = $scopeWhere ? ' WHERE ' . implode(' AND ', $scopeWhere) : '';
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM material_requests{$scopeSql}");
$totalStmt->execute($scopeParams);
$totalRecords = (int) $totalStmt->fetchColumn();

$statusCounts = array_fill_keys($requestStatuses, 0);
foreach ($rows as $row) {
    $rowStatus = (string) $row['status'];
    if (array_key_exists($rowStatus, $statusCounts)) {
        $statusCounts[$rowStatus]++;
    }
}

$filterValues = [$q, $status, $priority, $siteId > 0 ? (string) $siteId : '', $from, $to];
$activeFilterCount = count(array_filter($filterValues, static fn($value): bool => $value !== ''));
$activePage = 'requests';
$title = 'Material Requests';
$eyebrow = 'REQUEST MONITORING';
$canCreate = role_can((string) $_SESSION['role'], 'create_requests');
$introAction = $canCreate ? ['label' => 'New Request', 'url' => 'request_create.php', 'class' => 'primary'] : null;

$statusViews = [
    '' => ['label' => 'All requests', 'count' => count($rows)],
    'submitted' => ['label' => 'Pending review', 'count' => $statusCounts['submitted'] + $statusCounts['under_review']],
    'approved' => ['label' => 'Approved', 'count' => $statusCounts['approved'] + $statusCounts['partially_approved']],
    'ready_for_release' => ['label' => 'Ready for release', 'count' => $statusCounts['ready_for_release'] + $statusCounts['reserved']],
    'for_procurement' => ['label' => 'For procurement', 'count' => $statusCounts['for_procurement']],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php require __DIR__ . '/partials/assets.php'; ?>
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/partials/header.php'; ?>
        <div class="page-content ops-monitoring-page">
<?= bulk_import_panel('material_requests') ?>

            <?= ui_page_intro('Request monitoring', 'Material Requests', 'Requests by approval, reservation, release, and receipt status.', $introAction) ?>

            <nav class="ops-status-views" aria-label="Request status views">
                <?php foreach ($statusViews as $viewStatus => $view): ?>
                    <?php
                    $viewQuery = $_GET;
                    if ($viewStatus === '') {
                        unset($viewQuery['status']);
                    } else {
                        $viewQuery['status'] = $viewStatus;
                    }
                    $viewUrl = 'requests.php' . ($viewQuery ? '?' . http_build_query($viewQuery) : '');
                    ?>
                    <a class="<?= $status === $viewStatus ? 'active' : '' ?>" href="<?= e($viewUrl) ?>"<?= $status === $viewStatus ? ' aria-current="page"' : '' ?>>
                        <span><?= e($view['label']) ?></span><strong><?= number_format((int) $view['count']) ?></strong>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="ops-filter-bar">
                <div class="ops-filter-summary">
                    <div><strong>Find requests</strong><span>Search and narrow the workflow queue.</span></div>
                    <button class="btn secondary compact" type="button" data-filter-toggle aria-expanded="<?= $activeFilterCount > 0 ? 'true' : 'false' ?>" aria-controls="request-filter-drawer">
                        Filters <span class="ops-filter-count" data-active-filter-count><?= $activeFilterCount ?></span>
                    </button>
                </div>
                <div id="request-filter-drawer" class="ops-filter-drawer" data-filter-drawer<?= $activeFilterCount > 0 ? '' : ' hidden' ?>>
                    <form class="filter-card" method="get" data-filter-form>
                        <label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Request no., site, requester, remarks"></label>
                        <label>Status<select name="status"><?php render_status_options($requestStatuses, $status); ?></select></label>
                        <label>Priority<select name="priority"><option value="">All priorities</option><?php foreach ($priorities as $option): ?><option value="<?= e($option) ?>" <?= $priority === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></label>
                        <label>Site<select name="site_id"><option value="0">All sites</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>" <?= $siteId === (int) $site['id'] ? 'selected' : '' ?>><?= e($site['label']) ?></option><?php endforeach; ?></select></label>
                        <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
                        <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
                        <div class="filter-actions"><button class="btn primary" type="submit">Apply Filters</button><a class="btn secondary" href="requests.php" data-clear-filters>Clear</a></div>
                        <?= filter_badge(count($rows)) ?>
                    </form>
                </div>
            </section>

            <section class="ops-results-section">
                <div class="ops-results-heading">
                    <div><span class="eyebrow">Request register</span><h2>Workflow Records</h2></div>
                    <span><?= number_format(count($rows)) ?> result(s)</span>
                </div>
                <?php if (!$rows): ?>
                    <?php if ($totalRecords > 0 && $activeFilterCount > 0): ?>
                        <?= ui_empty_state('No requests match these filters', 'Change the search terms or clear the filters to return to the full request register.', ['label' => 'Clear filters', 'url' => 'requests.php', 'class' => 'secondary']) ?>
                    <?php else: ?>
                        <?= ui_empty_state('No material requests yet', 'Create the first request to begin approval and fulfillment tracking.', $introAction) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="ops-table-shell">
                        <div class="table-wrap">
                            <table class="data modern-table ops-table" data-sortable-table data-page-size="15">
                                <thead><tr><th>Request</th><th>Site</th><th>Requested By</th><th>Needed</th><th>Priority</th><th>Status</th><th>Items</th><th>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><b><?= e($row['request_no']) ?></b><small><?= e(date('M d, Y', strtotime($row['request_date']))) ?></small></td>
                                        <td><?= e($row['site_name']) ?></td>
                                        <td><?= e($row['full_name']) ?></td>
                                        <td><?= e(date('M d, Y', strtotime($row['date_needed']))) ?></td>
                                        <td><?= ui_status_badge((string) $row['priority']) ?></td>
                                        <td><?= ui_status_badge((string) $row['status']) ?></td>
                                        <td><?= (int) $row['item_count'] ?> line(s)<small><?= money_or_qty($row['total_qty']) ?> total qty</small></td>
                                        <td>
                                            <a class="table-action" href="request_view.php?id=<?= (int) $row['id'] ?>">Open</a>
                                            <?php if (role_can((string) $_SESSION['role'], 'release_materials') && (float) $row['releasable_qty'] > 0): ?>
                                                <a class="table-action" href="release_create.php?id=<?= (int) $row['id'] ?>">Release Reserved Portion</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ops-pagination" data-table-pagination aria-live="polite"><span>Showing up to 15 rows per page</span></div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
