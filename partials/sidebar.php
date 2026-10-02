<?php
require_once __DIR__ . '/../lib/navigation.php';

$activePage = $activePage ?? '';
$user = current_user();
$initial = strtoupper(substr((string) ($user['full_name'] ?? 'U'), 0, 1));
$role = (string) ($user['role'] ?? '');
$groups = visible_navigation_groups($role);
$quickActions = sidebar_quick_actions($role);

$sidebarIcon = static function (string $key, string $fallback): string {
    $iconNames = [
        'dashboard' => 'dashboard',
        'global_search' => 'search',
        'requests' => 'clipboard',
        'request_create' => 'plus',
        'inventory' => 'boxes',
        'advanced_forecasting' => 'trend',
        'inventory_intelligence' => 'gauge',
        'procurement_intelligence' => 'network',
        'calendar' => 'calendar',
        'workflow_control' => 'workflow',
        'stock_in' => 'arrow_in',
        'stock_out' => 'arrow_out',
        'releases' => 'send',
        'transfers' => 'swap',
        'returns' => 'return',
        'damage_reports' => 'alert',
        'adjustments' => 'sliders',
        'stock_counts' => 'checklist',
        'movements' => 'ledger',
        'procurement' => 'cart',
        'purchase_requests' => 'receipt',
        'purchase_orders' => 'truck',
        'materials' => 'box',
        'categories' => 'folder',
        'units' => 'ruler',
        'sites' => 'pin',
        'suppliers' => 'building',
        'users' => 'users',
        'reports_inventory' => 'report',
        'reports_low_stock' => 'warning',
        'reports_requests' => 'report',
        'reports_movements' => 'ledger',
        'reports_procurement' => 'cart',
        'reports_transfers' => 'swap',
        'reports_executive' => 'chart',
        'forecasting' => 'trend',
        'ai_recommendations' => 'spark',
        'reports_forecast' => 'chart',
        'notifications' => 'bell',
        'security_center' => 'shield',
        'settings' => 'settings',
        'backups' => 'database',
        'audit_logs' => 'audit',
        'logout' => 'logout',
        'workspace' => 'dashboard',
        'intelligence' => 'spark',
        'inventory_operations' => 'boxes',
        'procurement' => 'cart',
        'master_data' => 'folder',
        'reports' => 'chart',
        'administration' => 'shield',
    ];

    $icons = [
        'dashboard' => '<path d="M3 4h7v7H3zM14 4h7v4h-7zM14 12h7v8h-7zM3 15h7v5H3z"/>',
        'search' => '<circle cx="10" cy="10" r="5"/><path d="M14 14l5 5"/>',
        'clipboard' => '<path d="M8 4h8l1 3h3v14H4V7h3z"/><path d="M8 11h8M8 15h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'boxes' => '<path d="M4 8l8-4 8 4-8 4zM4 8v8l8 4 8-4V8M12 12v8"/>',
        'trend' => '<path d="M4 17l5-5 4 3 7-8"/><path d="M15 7h5v5"/>',
        'gauge' => '<path d="M4 15a8 8 0 1116 0"/><path d="M12 15l4-5"/><path d="M8 19h8"/>',
        'network' => '<circle cx="6" cy="7" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="12" cy="18" r="2"/><path d="M8 8l3 8M16 8l-3 8M8 7h8"/>',
        'calendar' => '<path d="M5 6h14v14H5zM5 10h14M8 4v4M16 4v4"/>',
        'workflow' => '<path d="M5 6h5v5H5zM14 13h5v5h-5zM10 8h3a3 3 0 013 3v2M14 16h-3a3 3 0 01-3-3v-2"/>',
        'arrow_in' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'arrow_out' => '<path d="M12 20V9M7 14l5-5 5 5M5 4h14"/>',
        'send' => '<path d="M4 12l16-7-7 16-2-7zM11 14l4-4"/>',
        'swap' => '<path d="M7 7h12l-3-3M17 17H5l3 3"/>',
        'return' => '<path d="M9 7H5v4M5 11h10a4 4 0 010 8h-3"/>',
        'alert' => '<path d="M12 4l9 16H3zM12 9v5M12 17h.01"/>',
        'sliders' => '<path d="M5 7h14M5 17h14M9 4v6M15 14v6"/>',
        'checklist' => '<path d="M6 7l2 2 3-4M13 8h5M6 16l2 2 3-4M13 17h5"/>',
        'ledger' => '<path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/>',
        'cart' => '<path d="M4 5h2l2 10h9l3-7H7M9 20h.01M17 20h.01"/>',
        'receipt' => '<path d="M6 4h12v17l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h4"/>',
        'truck' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7zM7 19a2 2 0 100-4 2 2 0 000 4zM18 19a2 2 0 100-4 2 2 0 000 4z"/>',
        'box' => '<path d="M4 8l8-4 8 4v9l-8 4-8-4zM4 8l8 4 8-4M12 12v9"/>',
        'folder' => '<path d="M3 6h7l2 3h9v10H3z"/>',
        'ruler' => '<path d="M5 19L19 5l2 2L7 21zM9 15l2 2M12 12l2 2M15 9l2 2"/>',
        'pin' => '<path d="M12 21s7-6 7-12a7 7 0 10-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2"/>',
        'building' => '<path d="M5 21V5h14v16M8 8h2M14 8h2M8 12h2M14 12h2M8 16h2M14 16h2"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="10" r="2"/><path d="M3 20a6 6 0 0112 0M14 18a4 4 0 017 0"/>',
        'report' => '<path d="M6 4h12v16H6zM9 15l2-3 3 2 2-5M9 8h6"/>',
        'warning' => '<path d="M12 4l9 16H3zM12 9v5M12 17h.01"/>',
        'chart' => '<path d="M5 20V5M5 20h15M9 17V9M13 17V6M17 17v-5"/>',
        'spark' => '<path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/>',
        'bell' => '<path d="M6 16h12l-2-3V9a4 4 0 00-8 0v4zM10 19h4"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3 8-8 9-5-1-8-4-8-9V6zM9 12l2 2 4-5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
        'database' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
        'audit' => '<path d="M6 4h10l2 2v14H6zM9 9h6M9 13h6M9 17h4"/>',
        'logout' => '<path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/>',
    ];

    $iconName = $iconNames[$key] ?? '';
    $path = $icons[$iconName] ?? '';
    if ($path === '') {
        return '<span class="side-fallback">' . e($fallback) . '</span>';
    }

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
};
?>
<aside class="sidebar" id="app-sidebar" data-sidebar aria-label="Application navigation">
    <div class="sidebar-mobile-header">
        <span>Navigation</span>
        <button type="button" data-sidebar-close aria-label="Close navigation" title="Close navigation">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <div class="sidebar-brand">
        <img src="assets/logo.png" alt="Metriccon logo">
        <div class="sidebar-brand-text">
            <strong>Metriccon</strong>
            <small>Inventory Control Hub</small>
        </div>
    </div>

    <button class="sidebar-collapse-toggle" type="button" data-sidebar-collapse-toggle aria-label="Collapse sidebar" aria-expanded="true" aria-controls="main-navigation" title="Collapse sidebar">
        <span class="sidebar-collapse-icon" aria-hidden="true"></span>
        <span data-sidebar-collapse-label>Collapse</span>
    </button>

    <div class="sidebar-user">
        <div class="user-avatar"><?= e($initial) ?></div>
        <div class="sidebar-user-info">
            <strong><?= e($user['full_name'] ?? 'User') ?></strong>
            <small><?= e(role_label($role)) ?></small>
        </div>
    </div>

    <?php if ($quickActions): ?>
        <section class="sidebar-quick-actions" aria-label="Quick actions">
            <div class="sidebar-quick-heading"><span>Quick Actions</span><small><?= e(strtoupper(str_replace('_', ' ', $role))) ?></small></div>
            <div class="sidebar-quick-grid">
                <?php foreach ($quickActions as $action): ?>
                    <a class="sidebar-quick-link" href="<?= e($action['url']) ?>" data-quick-action data-sidebar-label="<?= e($action['label']) ?>" aria-label="<?= e($action['label']) ?>" title="<?= e($action['label']) ?>">
                        <span class="side-icon" aria-hidden="true"><?= $sidebarIcon((string) $action['key'], (string) $action['fallback']) ?></span>
                        <span class="side-label"><?= e($action['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <nav class="sidebar-nav" id="main-navigation" aria-label="Main navigation">
        <?php foreach ($groups as $group): ?>
            <?php
            $groupKey = (string) $group['key'];
            $links = (array) $group['links'];
            $containsActive = count(array_filter($links, static fn(array $link): bool => $activePage === $link['key'])) > 0;
            $expanded = $containsActive || $groupKey === 'workspace';
            ?>
            <section class="nav-group <?= $containsActive ? 'contains-active' : '' ?>" data-nav-group="<?= e($groupKey) ?>">
                <button class="nav-group-toggle" type="button" data-nav-group-toggle data-sidebar-label="<?= e($group['label']) ?>" aria-label="Toggle <?= e($group['label']) ?> navigation" aria-expanded="<?= $expanded ? 'true' : 'false' ?>" aria-controls="nav-group-<?= e($groupKey) ?>" title="<?= e($group['label']) ?>">
                    <span class="nav-group-title"><span class="nav-group-icon" aria-hidden="true"><?= $sidebarIcon($groupKey, strtoupper(substr((string) $group['label'], 0, 1))) ?></span><span class="nav-section-text"><?= e($group['label']) ?></span></span>
                    <span class="nav-group-meta"><span data-nav-count><?= count($links) ?></span><span class="nav-group-chevron" aria-hidden="true"></span></span>
                </button>
                <div class="nav-group-links" id="nav-group-<?= e($groupKey) ?>" data-nav-group-links<?= $expanded ? '' : ' hidden' ?>>
                    <?php foreach ($links as $link): ?>
                        <a class="side-link <?= $activePage === $link['key'] ? 'active' : '' ?>" href="<?= e($link['url']) ?>" aria-label="<?= e($link['label']) ?>" title="<?= e($link['label']) ?>" data-sidebar-label="<?= e($link['label']) ?>"<?= $activePage === $link['key'] ? ' aria-current="page"' : '' ?>>
                            <span class="side-icon" data-sidebar-icon="<?= e($link['key']) ?>" aria-hidden="true"><?= $sidebarIcon((string) $link['key'], (string) $link['fallback']) ?></span><span class="side-label"><?= e($link['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <span class="sidebar-session-state"><i aria-hidden="true"></i><span>Local session</span></span>
        <a class="sidebar-logout" href="logout.php" aria-label="Logout" title="Logout" data-sidebar-label="Logout" data-confirm="Sign out of Inventory Hub?" data-confirm-title="End session"><span class="side-icon" data-sidebar-icon="logout" aria-hidden="true"><?= $sidebarIcon('logout', 'X') ?></span><span class="side-label">Logout</span></a>
    </div>
</aside>
<div class="sidebar-tooltip" role="tooltip" data-sidebar-tooltip hidden></div>
<button class="mobile-nav-overlay" type="button" data-mobile-nav-overlay aria-label="Close navigation"></button>
