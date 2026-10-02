<?php
require_once __DIR__ . '/lib/master_data.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission('manage_users');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username !== '' && $password !== '') {
        $pdo->prepare(
            "INSERT INTO users (username, full_name, email, password_hash, role, site_id, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), email = VALUES(email), role = VALUES(role), site_id = VALUES(site_id), is_active = 1"
        )->execute([
            $username,
            trim((string) $_POST['full_name']),
            trim((string) $_POST['email']) ?: null,
            password_hash($password, PASSWORD_DEFAULT),
            (string) $_POST['role'],
            ($_POST['site_id'] ?? '') !== '' ? (int) $_POST['site_id'] : null,
        ]);
        flash('success', 'User saved.');
    }
    redirect('users.php');
}

$sites = active_options($pdo, 'sites');
$roles = ['admin', 'warehouse_manager', 'site_engineer', 'warehouse_personnel', 'procurement', 'president'];
$q = filter_text($_GET, 'q');
$roleFilter = filter_choice($_GET, 'role', $roles);
$statusFilter = bool_filter_value($_GET, 'status');
$siteId = filter_int_choice($_GET, 'site_id');
$where = [];
$params = [];
filter_where_like($where, $params, ['u.username', 'u.full_name', 'u.email', 's.name'], $q);
filter_where_equals($where, $params, 'u.role', $roleFilter);
filter_where_equals($where, $params, 'u.site_id', $siteId);
$activeValue = filter_bool_condition($statusFilter);
if ($activeValue !== null) {
    $where[] = 'u.is_active = ?';
    $params[] = $activeValue;
}
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT u.*, s.name AS site_name FROM users u LEFT JOIN sites s ON s.id = u.site_id {$whereSql} ORDER BY u.is_active DESC, u.role, u.full_name");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'users';
$title = 'User Accounts';
$eyebrow = 'ADMINISTRATION';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">ACCOUNT</span><h2>Add / Reset User</h2></div></div><form method="post" class="form-grid three"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Username<input name="username" required></label><label>Full Name<input name="full_name" required></label><label>Email<input type="email" name="email"></label><label>Password<input type="password" name="password" required></label><label>Role<select name="role"><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"><?= e(role_label($role)) ?></option><?php endforeach; ?></select></label><label>Assigned Site<select name="site_id"><option value="">No site</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>"><?= e($site['label']) ?></option><?php endforeach; ?></select></label><div class="form-actions full"><button class="btn primary" type="submit">Save User</button></div></form></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Username, full name, email, site"></label><label>Role<select name="role"><option value="">All roles</option><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= e(role_label($role)) ?></option><?php endforeach; ?></select></label><label>Site<select name="site_id"><option value="0">All sites</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>" <?= $siteId === (int) $site['id'] ? 'selected' : '' ?>><?= e($site['label']) ?></option><?php endforeach; ?></select></label><label>Status<select name="status"><option value="">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="users.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>User</th><th>Role</th><th>Site</th><th>Last Login</th><th>Status</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['full_name']) ?></b><small><?= e($row['username']) ?> | <?= e($row['email']) ?></small></td><td><?= e(role_label($row['role'])) ?></td><td><?= e($row['site_name'] ?? '-') ?></td><td><?= $row['last_login_at'] ? e(date('M d, Y h:i A', strtotime($row['last_login_at']))) : '-' ?></td><td><?= bool_badge($row['is_active']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
