<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/high_value.php';
require_permission('view_delivery_calendar');
require_once __DIR__ . '/config/db.php';

$siteScoped = (string) ($_SESSION['role'] ?? '') === 'site_engineer';
$rows = delivery_calendar_rows($pdo, $siteScoped ? (current_user_site_id() ?? 0) : null, $siteScoped ? (int) current_user()['id'] : null);
$activePage = 'calendar';
$title = 'Delivery Calendar';
$eyebrow = 'SCHEDULE';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card spectrum-card"><div><span class="eyebrow light">CALENDAR</span><h2>Delivery Calendar</h2><p>Expected Deliveries, request needed dates, and cycle count schedules in one planning view.</p></div><div class="status-chip"><?= number_format(count($rows)) ?> events</div></section>
<section class="calendar-board">
<?php if (!$rows): ?><article class="content-card"><p class="muted">No calendar events found.</p></article><?php endif; ?>
<?php foreach ($rows as $row): ?>
    <a class="calendar-item" href="<?= e($row['url']) ?>">
        <time><?= e(date('M d', strtotime($row['event_date']))) ?></time>
        <div><span><?= e($row['event_type']) ?></span><b><?= e($row['code']) ?></b><small><?= e($row['title']) ?> / <?= e($row['status']) ?></small></div>
    </a>
<?php endforeach; ?>
</section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TABLE</span><h2>Expected Deliveries and Needed Dates</h2></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Date</th><th>Type</th><th>Code</th><th>Title</th><th>Status</th><th>Open</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= e($row['event_date']) ?></td><td><?= e($row['event_type']) ?></td><td><?= e($row['code']) ?></td><td><?= e($row['title']) ?></td><td><span class="workflow-badge"><?= e($row['status']) ?></span></td><td><a class="table-action" href="<?= e($row['url']) ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
