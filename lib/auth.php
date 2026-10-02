<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function permission_map(): array
{
    return [
        'admin' => [
            'view_dashboard', 'use_global_search', 'manage_users', 'manage_master_data',
            'view_notifications', 'manage_settings', 'manage_backups', 'view_audit_logs',
            'manage_workflow_control',
        ],
        'warehouse_manager' => [
            'view_dashboard', 'use_global_search', 'manage_master_data', 'view_inventory', 'approve_requests',
            'view_inventory_intelligence',
            'view_requests', 'view_reports', 'view_movements',
            'view_transfers', 'approve_transfers', 'approve_damage', 'approve_adjustments',
            'view_stock_counts', 'approve_stock_counts', 'approve_stock_out', 'manage_workflow_control',
            'resolve_receipt_discrepancies', 'view_notifications', 'export_reports',
            'view_delivery_calendar', 'view_live_dashboard',
            'review_materials',
        ],
        'site_engineer' => [
            'view_dashboard', 'create_requests', 'view_own_requests', 'confirm_receipt', 'manage_site_inventory',
            'create_site_transfers', 'report_site_damage', 'view_inventory', 'view_returns', 'create_returns', 'view_notifications', 'view_delivery_calendar',
            'view_live_dashboard',
        ],
        'warehouse_personnel' => [
            'view_dashboard', 'view_inventory', 'post_stock_in', 'request_stock_out',
            'request_workflow_control', 'post_workflow_control',
            'release_materials', 'view_requests', 'view_movements', 'view_transfers',
            'create_transfers', 'dispatch_transfers', 'receive_transfers',
            'view_returns', 'create_returns', 'post_returns', 'receive_supplier_deliveries',
            'manage_damage', 'manage_adjustments', 'view_stock_counts', 'manage_stock_counts',
            'view_notifications', 'view_delivery_calendar', 'view_live_dashboard',
            'create_materials',
        ],
        'procurement' => [
            'view_dashboard', 'view_inventory', 'view_requests', 'manage_suppliers',
            'view_inventory_intelligence',
            'view_inventory_reports', 'view_procurement_reports', 'view_forecast_reports', 'view_ai_recommendations',
            'view_procurement', 'view_procurement_intelligence', 'manage_procurement', 'manage_ai_recommendations',
            'view_notifications', 'export_reports', 'view_delivery_calendar', 'view_live_dashboard',
        ],
        'president' => [
            'view_dashboard', 'view_inventory', 'view_inventory_reports', 'view_procurement_reports',
            'view_inventory_intelligence',
            'view_forecast_reports', 'view_ai_recommendations', 'view_procurement_intelligence', 'view_executive_dashboard',
            'approve_procurement', 'manage_workflow_control',
            'view_notifications', 'export_reports', 'view_delivery_calendar', 'view_live_dashboard',
        ],
    ];
}

function current_user_site_id(): ?int
{
    $siteId = current_user()['site_id'] ?? null;
    return $siteId !== null && (int) $siteId > 0 ? (int) $siteId : null;
}

function require_site_engineer_record_access(?int $siteId, ?int $ownerId = null): void
{
    if ((string) ($_SESSION['role'] ?? '') !== 'site_engineer') {
        return;
    }
    $user = current_user();
    $allowed = $siteId !== null && current_user_site_id() === (int) $siteId;
    if ($ownerId !== null) {
        $allowed = $allowed && (int) $ownerId === (int) ($user['id'] ?? 0);
    }
    if (!$allowed) {
        http_response_code(403);
        die('You may only access records assigned to your site.');
    }
}

function role_can(string $role, string $permission): bool
{
    $permissions = permission_map();
    return in_array($permission, $permissions[$role] ?? [], true);
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
        'full_name' => (string) ($_SESSION['full_name'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
        'site_id' => isset($_SESSION['site_id']) ? (int) $_SESSION['site_id'] : null,
    ];
}

function role_label(?string $role = null): string
{
    $role = $role ?? (string) ($_SESSION['role'] ?? '');
    $labels = [
        'admin' => 'IT / System Administrator',
        'warehouse_manager' => 'General Warehouse Manager',
        'site_engineer' => 'Site Engineer',
        'warehouse_personnel' => 'Warehouse Personnel',
        'procurement' => 'Procurement Department',
        'president' => 'President / Executive',
    ];
    return $labels[$role] ?? 'User';
}

function require_login(): void
{
    if (!current_user()) {
        redirect('index.php');
    }
}

function require_permission(string $permission): void
{
    require_login();
    $role = (string) ($_SESSION['role'] ?? '');
    if (!role_can($role, $permission)) {
        http_response_code(403);
        die('You do not have permission to access this page.');
    }
}

function require_any_permission(array $permissions): void
{
    require_login();
    $role = (string) ($_SESSION['role'] ?? '');
    foreach ($permissions as $permission) {
        if (role_can($role, (string) $permission)) {
            return;
        }
    }

    http_response_code(403);
    die('You do not have permission to access this page.');
}

function login_user(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare(
        'SELECT u.*, s.name AS site_name
         FROM users u
         LEFT JOIN sites s ON s.id = u.site_id
         WHERE u.username = ?
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['full_name'] = (string) $user['full_name'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['site_id'] = $user['site_id'] !== null ? (int) $user['site_id'] : null;

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
         VALUES (?, 'login', 'user', ?, ?)"
    )->execute([(int) $user['id'], (int) $user['id'], 'User signed in']);

    return true;
}
