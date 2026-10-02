<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/high_value.php';
require_permission('manage_settings');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'password_change') {
        $policy = password_policy_settings($pdo);
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) current_user()['id']]);
        $hash = (string) $stmt->fetchColumn();
        $complex = preg_match('/[A-Z]/', $newPassword) && preg_match('/[a-z]/', $newPassword) && preg_match('/[0-9]/', $newPassword);
        if (!password_verify($currentPassword, $hash)) {
            flash('danger', 'Current password is incorrect.');
        } elseif ($newPassword !== $confirmPassword) {
            flash('danger', 'New password confirmation does not match.');
        } elseif (strlen($newPassword) < (int) $policy['min_length']) {
            flash('danger', 'New password does not meet the minimum length.');
        } elseif (!empty($policy['complexity_required']) && !$complex) {
            flash('danger', 'New password must include uppercase, lowercase, and number characters.');
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) current_user()['id']]);
            flash('success', 'Password changed.');
        }
    } else {
        save_setting($pdo, 'session_timeout_minutes', (string) max(5, min(480, (int) ($_POST['session_timeout_minutes'] ?? 45))));
        save_setting($pdo, 'password_min_length', (string) max(6, min(32, (int) ($_POST['password_min_length'] ?? 8))));
        save_setting($pdo, 'password_require_complexity', !empty($_POST['password_require_complexity']) ? '1' : '0');
        save_setting($pdo, 'two_factor_optional', !empty($_POST['two_factor_optional']) ? '1' : '0');
        save_setting($pdo, 'forecast_daily_enabled', !empty($_POST['forecast_daily_enabled']) ? '1' : '0');
        save_setting($pdo, 'forecast_daily_time', filter_text($_POST, 'forecast_daily_time', 5) ?: '06:00');
        save_setting($pdo, 'auto_backup_enabled', !empty($_POST['auto_backup_enabled']) ? '1' : '0');
        save_setting($pdo, 'auto_backup_time', filter_text($_POST, 'auto_backup_time', 5) ?: '22:00');
        flash('success', 'Security and setup settings saved.');
    }
    redirect('security_center.php');
}

$policy = password_policy_settings($pdo);
$healthRows = system_health_rows($pdo);
$activityRows = user_activity_rows($pdo);
$backupSchedules = backup_schedule_rows($pdo);
$departments = high_value_table_exists($pdo, 'departments') ? $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll() : [];
$users = $pdo->query(
    "SELECT u.*, d.name AS department_name, s.name AS site_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN sites s ON s.id = u.site_id
     ORDER BY u.role, u.full_name"
)->fetchAll();
$settings = [
    'forecast_daily_enabled' => setting_value($pdo, 'forecast_daily_enabled', '1'),
    'forecast_daily_time' => setting_value($pdo, 'forecast_daily_time', '06:00'),
    'auto_backup_enabled' => setting_value($pdo, 'auto_backup_enabled', '1'),
    'auto_backup_time' => setting_value($pdo, 'auto_backup_time', '22:00'),
];
$permissions = permission_map();

$activePage = 'security_center';
$title = 'Security Center';
$eyebrow = 'ADMINISTRATION';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content ops-admin-workspace">
<section class="monitoring-top-card ai-gradient-card"><div><span class="eyebrow light">SECURITY CENTER</span><h2>Security Center and System Health</h2><p>Password policy, optional two-factor login, session timeout settings, fine-grained permissions, teams, warehouse/site assignment, setup wizard, database backup schedule, restore readiness, and service health.</p></div><div class="status-chip">Timeout <?= (int) $policy['session_timeout_minutes'] ?> min</div></section>
<div class="ops-health-context"><strong>Service coverage</strong><span>Database / Python forecasting / Gemini assistance / Backup storage</span></div>
<section class="split-grid"><article class="form-card"><div class="card-heading"><div><span class="eyebrow">SETUP</span><h2>Setup Wizard for API Keys and Forecast Settings</h2></div></div><form method="post" class="form-grid" data-block-on-submit="Saving setup"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Session Timeout<input type="number" name="session_timeout_minutes" value="<?= (int) $policy['session_timeout_minutes'] ?>"></label><label>Password Min Length<input type="number" name="password_min_length" value="<?= (int) $policy['min_length'] ?>"></label><label>Daily Forecast Time<input type="time" name="forecast_daily_time" value="<?= e($settings['forecast_daily_time']) ?>"></label><label>Backup Time<input type="time" name="auto_backup_time" value="<?= e($settings['auto_backup_time']) ?>"></label><label class="check-row"><input type="checkbox" name="password_require_complexity" <?= $policy['complexity_required'] ? 'checked' : '' ?>> Password complexity required</label><label class="check-row"><input type="checkbox" name="two_factor_optional" <?= $policy['two_factor_optional'] ? 'checked' : '' ?>> Optional two-factor login</label><label class="check-row"><input type="checkbox" name="forecast_daily_enabled" <?= $settings['forecast_daily_enabled'] === '1' ? 'checked' : '' ?>> Scheduled daily forecast run</label><label class="check-row"><input type="checkbox" name="auto_backup_enabled" <?= $settings['auto_backup_enabled'] === '1' ? 'checked' : '' ?>> Auto database backup schedule</label><div class="form-actions"><button class="btn primary" type="submit">Save Settings</button></div></form></article><article class="content-card"><div class="card-heading"><div><span class="eyebrow">HEALTH</span><h2>System Health</h2></div></div><div class="health-grid"><?php foreach ($healthRows as $row): ?><div class="health-tile <?= e($row['status']) ?>"><b><?= e($row['check_key']) ?></b><span><?= e($row['message']) ?></span><small><?= e(date('M d, Y h:i A', strtotime($row['checked_at']))) ?></small></div><?php endforeach; ?></div></article></section>
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">PASSWORD</span><h2>Password Change</h2></div></div><form method="post" class="form-grid three" data-block-on-submit="Changing password"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="password_change"><label>Current Password<input type="password" name="current_password" autocomplete="current-password"></label><label>New Password<input type="password" name="new_password" autocomplete="new-password"></label><label>Confirm Password<input type="password" name="confirm_password" autocomplete="new-password"></label><div class="form-actions"><button class="btn primary" type="submit" data-confirm="Change your account password?" data-confirm-title="Password change">Change Password</button></div></form><p class="muted">Policy: minimum <?= (int) $policy['min_length'] ?> characters<?= $policy['complexity_required'] ? ', with uppercase, lowercase, and number characters.' : '.' ?></p></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">USERS</span><h2>Department/Team Assignment and Multi-Warehouse Permissions</h2></div><a class="btn secondary compact" href="users.php">Manage Users</a></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>User</th><th>Role</th><th>Department</th><th>Site/Warehouse</th><th>2FA</th><th>Status</th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td><b><?= e($user['full_name']) ?></b><small><?= e($user['username']) ?></small></td><td><?= e(role_label($user['role'])) ?></td><td><?= e($user['department_name'] ?? 'Unassigned') ?></td><td><?= e($user['site_name'] ?? 'Company-wide') ?></td><td><?= (int) $user['two_factor_enabled'] === 1 ? 'Enabled' : 'Optional' ?></td><td><span class="workflow-badge"><?= (int) $user['is_active'] === 1 ? 'active' : 'inactive' ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">PERMISSIONS</span><h2>Fine-Grained Permissions per Role</h2></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Role</th><th>Permission Count</th><th>Permissions</th></tr></thead><tbody><?php foreach ($permissions as $role => $items): ?><tr><td><b><?= e(role_label($role)) ?></b><small><?= e($role) ?></small></td><td><?= number_format(count($items)) ?></td><td><small><?= e(implode(', ', $items)) ?></small></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="split-grid"><article class="content-card"><div class="card-heading"><div><span class="eyebrow">ACTIVITY</span><h2>User Activity Report</h2></div><a class="btn secondary compact" href="audit_logs.php">Audit Search</a></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Date</th><th>User</th><th>Role</th><th>Logins</th><th>Actions</th></tr></thead><tbody><?php foreach ($activityRows as $row): ?><tr><td><?= e($row['activity_date']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['role']) ?></td><td><?= (int) $row['login_count'] ?></td><td><?= (int) $row['action_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></article><article class="content-card"><div class="card-heading"><div><span class="eyebrow">BACKUP</span><h2>Data Backup Restore Screen</h2></div><a class="btn secondary compact" href="backups.php">Backups</a></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Schedule</th><th>Frequency</th><th>Run Time</th><th>Retention</th><th>Status</th></tr></thead><tbody><?php foreach ($backupSchedules as $row): ?><tr><td><?= e($row['schedule_name']) ?></td><td><?= e($row['frequency']) ?></td><td><?= e(substr((string) $row['run_time'], 0, 5)) ?></td><td><?= (int) $row['retention_days'] ?> days</td><td><?= (int) $row['is_active'] === 1 ? 'Active' : 'Paused' ?></td></tr><?php endforeach; ?></tbody></table></div><p class="muted">Restore is intentionally manual-confirmation first: select a known backup file, verify it, then restore through the local administrator workflow.</p></article></section>
</div></main></div></body></html>
