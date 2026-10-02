<?php
require_once __DIR__ . '/lib/notification_rules.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/search_filters.php';
require_once __DIR__ . '/lib/high_value.php';
require_permission('view_notifications');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'preferences') {
        $types = ['all', 'low_stock', 'approval_overdue', 'po_overdue', 'request_release', 'ai_recommendations'];
        save_notification_preferences($pdo, (int) current_user()['id'], $types, $_POST['in_app'] ?? [], $_POST['email'] ?? []);
        flash('success', 'Notification Preferences saved.');
    } else {
        foreach (($_POST['notification_id'] ?? []) as $notificationId) {
            mark_notification_read($pdo, (int) $notificationId, (int) current_user()['id']);
        }
        flash('success', 'Notifications updated.');
    }
    redirect('notifications.php');
}

$ruleSummary = generate_needed_notifications($pdo);
purge_inaccessible_notifications($pdo, (int) current_user()['id']);
$severities = ['info','success','warning','danger'];
$readStates = ['unread','read'];
$q = filter_text($_GET, 'q');
$severity = filter_choice($_GET, 'severity', $severities);
$readState = filter_choice($_GET, 'read_state', $readStates);
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');

$where = ['user_id = ?'];
$params = [(int) current_user()['id']];
filter_where_like($where, $params, ['title', 'message', 'link_url'], $q);
filter_where_equals($where, $params, 'severity', $severity);
if ($readState === 'unread') {
    $where[] = 'read_at IS NULL';
} elseif ($readState === 'read') {
    $where[] = 'read_at IS NOT NULL';
}
filter_where_between_dates($where, $params, 'created_at', $from, $to);
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT * FROM notifications {$whereSql} ORDER BY created_at DESC, id DESC LIMIT 120");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$preferences = notification_preferences_for_user($pdo, (int) current_user()['id']);
$preferencesByType = [];
foreach ($preferences as $preference) {
    $preferencesByType[(string) $preference['notification_type']] = $preference;
}
$preferenceTypes = [
    'all' => 'All Notifications',
    'low_stock' => 'Low Stock',
    'approval_overdue' => 'Overdue Approvals',
    'po_overdue' => 'Overdue Purchase Orders',
    'request_release' => 'Request Releases',
    'ai_recommendations' => 'AI Recommendations',
];
$activePage = 'notifications';
$title = 'Notifications';
$eyebrow = 'MESSAGE CENTER';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">NOTIFICATIONS</span><h2>Approvals, receipts, stock alerts, and operational messages</h2><p>Keep track of work that needs attention across inventory, procurement, and site workflows.</p></div><div class="status-chip">Generated <?= number_format((int) $ruleSummary['created']) ?> new alert(s)</div></section>
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">PREFERENCES</span><h2>Notification Preferences</h2></div></div><form method="post" data-block-on-submit="Saving preferences"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="preferences"><div class="table-wrap"><table class="data modern-table notification-preference-table"><thead><tr><th>Notification Type</th><th>In-App</th><th>Email Draft</th></tr></thead><tbody><?php foreach ($preferenceTypes as $type => $label): ?><?php $pref = $preferencesByType[$type] ?? ['in_app_enabled' => 1, 'email_enabled' => 0]; ?><tr><td><b><?= e($label) ?></b><small><?= e($type) ?></small></td><td class="notification-preference-check"><input type="checkbox" name="in_app[<?= e($type) ?>]" value="1" <?= (int) $pref['in_app_enabled'] === 1 ? 'checked' : '' ?>></td><td class="notification-preference-check"><input type="checkbox" name="email[<?= e($type) ?>]" value="1" <?= (int) $pref['email_enabled'] === 1 ? 'checked' : '' ?>></td></tr><?php endforeach; ?></tbody></table></div><div class="form-actions" style="margin-top:14px"><button class="btn primary" type="submit">Save Preferences</button></div></form></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Title, message, link"></label><label>Priority<select name="severity"><?php render_status_options($severities, $severity, 'All priorities'); ?></select></label><label>Read<select name="read_state"><option value="">All messages</option><option value="unread" <?= $readState === 'unread' ? 'selected' : '' ?>>Unread</option><option value="read" <?= $readState === 'read' ? 'selected' : '' ?>>Read</option></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="notifications.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><form method="post" data-block-on-submit="Updating notifications"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Read</th><th>Priority</th><th>Message</th><th>Date</th><th>Open</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="5" class="muted">No notifications yet.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><?php if (!$row['read_at']): ?><input type="checkbox" name="notification_id[]" value="<?= (int) $row['id'] ?>"><?php else: ?><span class="muted">Read</span><?php endif; ?></td><td><span class="workflow-badge notify-<?= e($row['severity'] ?? 'info') ?>"><?= e($row['severity'] ?? 'info') ?></span></td><td><b><?= e($row['title']) ?></b><small><?= e($row['message']) ?></small></td><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td><td><?= $row['link_url'] ? '<a class="table-action" href="' . e($row['link_url']) . '">Open</a>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div><div class="form-actions" style="margin-top:14px"><button class="btn primary" type="submit" data-confirm="Mark selected notifications as read?" data-confirm-title="Update notifications">Mark Read</button></div></form></section>
</div></main></div></body></html>
