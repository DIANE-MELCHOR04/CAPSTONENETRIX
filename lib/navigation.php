<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Shared navigation registry. Permissions may be a single capability or a list
 * where any matching capability makes the destination visible.
 */
function navigation_groups(): array
{
    return [
        [
            'key' => 'workspace',
            'label' => 'Workspace',
            'links' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'fallback' => 'H', 'url' => 'dashboard.php', 'permissions' => ['view_dashboard']],
                ['key' => 'global_search', 'label' => 'Global Search', 'fallback' => 'GS', 'url' => 'global_search.php', 'permissions' => ['use_global_search']],
                ['key' => 'requests', 'label' => 'Material Requests', 'fallback' => 'MR', 'url' => 'requests.php', 'permissions' => ['view_requests', 'view_own_requests']],
                ['key' => 'request_create', 'label' => 'New Request', 'fallback' => '+', 'url' => 'request_create.php', 'permissions' => ['create_requests']],
                ['key' => 'inventory', 'label' => 'Current Inventory', 'fallback' => 'I', 'url' => 'inventory.php', 'permissions' => ['view_inventory']],
                ['key' => 'site_inventory', 'label' => 'Site Inventory', 'fallback' => 'SI', 'url' => 'site_inventory.php', 'permissions' => ['manage_site_inventory']],
            ],
        ],
        [
            'key' => 'intelligence',
            'label' => 'Intelligence',
            'links' => [
                ['key' => 'advanced_forecasting', 'label' => 'Forecast Intelligence', 'fallback' => 'FI', 'url' => 'advanced_forecasting.php', 'permissions' => ['view_reports', 'view_forecast_reports']],
                ['key' => 'inventory_intelligence', 'label' => 'Inventory Intelligence', 'fallback' => 'II', 'url' => 'inventory_intelligence.php', 'permissions' => ['view_inventory_intelligence']],
                ['key' => 'procurement_intelligence', 'label' => 'Procurement Intelligence', 'fallback' => 'PI', 'url' => 'procurement_intelligence.php', 'permissions' => ['view_procurement_intelligence']],
                ['key' => 'calendar', 'label' => 'Delivery Calendar', 'fallback' => 'DC', 'url' => 'calendar.php', 'permissions' => ['view_delivery_calendar']],
                ['key' => 'workflow_control', 'label' => 'Workflow Control', 'fallback' => 'WC', 'url' => 'workflow_control.php', 'permissions' => ['manage_workflow_control','request_workflow_control','post_workflow_control']],
            ],
        ],
        [
            'key' => 'inventory_operations',
            'label' => 'Inventory',
            'links' => [
                ['key' => 'stock_in', 'label' => 'Stock-In', 'fallback' => 'IN', 'url' => 'stock_in.php', 'permissions' => ['post_stock_in']],
                ['key' => 'stock_out', 'label' => 'Emergency Stock-Out', 'fallback' => 'OUT', 'url' => 'stock_out.php', 'permissions' => ['request_stock_out', 'approve_stock_out']],
                ['key' => 'releases', 'label' => 'Releases', 'fallback' => 'RL', 'url' => 'releases.php', 'permissions' => ['release_materials', 'confirm_receipt', 'view_requests']],
                ['key' => 'transfers', 'label' => 'Transfers', 'fallback' => 'TR', 'url' => 'transfers.php', 'permissions' => ['view_transfers', 'create_transfers', 'create_site_transfers', 'dispatch_transfers', 'receive_transfers', 'approve_transfers']],
                ['key' => 'returns', 'label' => 'Returns', 'fallback' => 'RT', 'url' => 'returns.php', 'permissions' => ['view_returns', 'create_returns', 'post_returns']],
                ['key' => 'damage_reports', 'label' => 'Damaged Materials', 'fallback' => 'DM', 'url' => 'damage_reports.php', 'permissions' => ['manage_damage', 'report_site_damage', 'approve_damage']],
                ['key' => 'adjustments', 'label' => 'Adjustments', 'fallback' => 'AD', 'url' => 'adjustments.php', 'permissions' => ['manage_adjustments', 'approve_adjustments']],
                ['key' => 'stock_counts', 'label' => 'Stock Counts', 'fallback' => 'SC', 'url' => 'stock_counts.php', 'permissions' => ['view_stock_counts', 'manage_stock_counts', 'approve_stock_counts']],
                ['key' => 'movements', 'label' => 'Movement History', 'fallback' => 'MV', 'url' => 'movements.php', 'permissions' => ['view_movements']],
            ],
        ],
        [
            'key' => 'procurement',
            'label' => 'Procurement',
            'links' => [
                ['key' => 'procurement', 'label' => 'Procurement Desk', 'fallback' => 'PD', 'url' => 'procurement.php', 'permissions' => ['manage_procurement']],
                ['key' => 'purchase_requests', 'label' => 'Purchase Requests', 'fallback' => 'PR', 'url' => 'purchase_requests.php', 'permissions' => ['manage_procurement', 'approve_procurement']],
                ['key' => 'purchase_orders', 'label' => 'Purchase Orders', 'fallback' => 'PO', 'url' => 'purchase_orders.php', 'permissions' => ['manage_procurement', 'approve_procurement', 'receive_supplier_deliveries']],
            ],
        ],
        [
            'key' => 'master_data',
            'label' => 'Master Data',
            'links' => [
                ['key' => 'materials', 'label' => 'Materials', 'fallback' => 'M', 'url' => 'materials.php', 'permissions' => ['manage_master_data', 'create_materials', 'review_materials']],
                ['key' => 'categories', 'label' => 'Categories', 'fallback' => 'C', 'url' => 'categories.php', 'permissions' => ['manage_master_data']],
                ['key' => 'units', 'label' => 'Units', 'fallback' => 'U', 'url' => 'units.php', 'permissions' => ['manage_master_data']],
                ['key' => 'sites', 'label' => 'Sites / Locations', 'fallback' => 'S', 'url' => 'sites.php', 'permissions' => ['manage_master_data']],
                ['key' => 'suppliers', 'label' => 'Suppliers', 'fallback' => 'SP', 'url' => 'suppliers.php', 'permissions' => ['manage_suppliers']],
                ['key' => 'users', 'label' => 'Users', 'fallback' => 'US', 'url' => 'users.php', 'permissions' => ['manage_users']],
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'links' => [
                ['key' => 'reports_inventory', 'label' => 'Inventory Report', 'fallback' => 'RI', 'url' => 'reports_inventory.php', 'permissions' => ['view_reports', 'view_inventory_reports']],
                ['key' => 'reports_low_stock', 'label' => 'Low Stock', 'fallback' => 'LS', 'url' => 'reports_low_stock.php', 'permissions' => ['view_reports', 'view_inventory_reports']],
                ['key' => 'reports_requests', 'label' => 'Request Report', 'fallback' => 'RR', 'url' => 'reports_requests.php', 'permissions' => ['view_reports']],
                ['key' => 'reports_movements', 'label' => 'Movement Report', 'fallback' => 'RM', 'url' => 'reports_movements.php', 'permissions' => ['view_reports']],
                ['key' => 'reports_procurement', 'label' => 'Procurement Report', 'fallback' => 'RP', 'url' => 'reports_procurement.php', 'permissions' => ['view_reports', 'view_procurement_reports']],
                ['key' => 'reports_transfers', 'label' => 'Transfer Report', 'fallback' => 'RT', 'url' => 'reports_transfers.php', 'permissions' => ['view_reports']],
                ['key' => 'reports_executive', 'label' => 'Executive Report', 'fallback' => 'EX', 'url' => 'reports_executive.php', 'permissions' => ['view_executive_dashboard']],
                ['key' => 'forecasting', 'label' => 'Forecasting', 'fallback' => 'F', 'url' => 'forecasting.php', 'permissions' => ['view_reports', 'view_forecast_reports']],
                ['key' => 'ai_recommendations', 'label' => 'AI Recommendations', 'fallback' => 'AI', 'url' => 'ai_recommendations.php', 'permissions' => ['view_reports', 'view_ai_recommendations']],
                ['key' => 'reports_forecast', 'label' => 'Forecast Report', 'fallback' => 'FR', 'url' => 'reports_forecast.php', 'permissions' => ['view_reports', 'view_forecast_reports']],
            ],
        ],
        [
            'key' => 'administration',
            'label' => 'Administration',
            'links' => [
                ['key' => 'notifications', 'label' => 'Notifications', 'fallback' => 'N', 'url' => 'notifications.php', 'permissions' => ['view_notifications']],
                ['key' => 'security_center', 'label' => 'Security Center', 'fallback' => 'SEC', 'url' => 'security_center.php', 'permissions' => ['manage_settings']],
                ['key' => 'settings', 'label' => 'Settings', 'fallback' => 'ST', 'url' => 'settings.php', 'permissions' => ['manage_settings']],
                ['key' => 'backups', 'label' => 'Backups', 'fallback' => 'BK', 'url' => 'backups.php', 'permissions' => ['manage_backups']],
                ['key' => 'audit_logs', 'label' => 'Audit Logs', 'fallback' => 'AL', 'url' => 'audit_logs.php', 'permissions' => ['view_audit_logs']],
            ],
        ],
    ];
}

function visible_navigation_groups(string $role): array
{
    $visibleGroups = [];

    foreach (navigation_groups() as $group) {
        $visibleLinks = array_values(array_filter(
            $group['links'],
            static function (array $link) use ($role): bool {
                foreach ($link['permissions'] as $permission) {
                    if (role_can($role, (string) $permission)) {
                        return true;
                    }
                }
                return false;
            }
        ));

        if ($visibleLinks !== []) {
            $group['links'] = $visibleLinks;
            $visibleGroups[] = $group;
        }
    }

    return $visibleGroups;
}

function sidebar_quick_actions(string $role): array
{
    $actionsByRole = [
        'admin' => [
            ['key' => 'security_center', 'label' => 'System Health', 'fallback' => 'SH', 'url' => 'security_center.php', 'permission' => 'manage_settings'],
            ['key' => 'users', 'label' => 'Manage Users', 'fallback' => 'US', 'url' => 'users.php', 'permission' => 'manage_users'],
        ],
        'warehouse_manager' => [
            ['key' => 'requests', 'label' => 'Review Requests', 'fallback' => 'MR', 'url' => 'requests.php?status=submitted', 'permission' => 'approve_requests'],
            ['key' => 'reports_inventory', 'label' => 'Inventory Report', 'fallback' => 'RI', 'url' => 'reports_inventory.php', 'permission' => 'view_reports'],
        ],
        'site_engineer' => [
            ['key' => 'request_create', 'label' => 'New Request', 'fallback' => '+', 'url' => 'request_create.php', 'permission' => 'create_requests'],
            ['key' => 'requests', 'label' => 'My Requests', 'fallback' => 'MR', 'url' => 'requests.php', 'permission' => 'view_own_requests'],
        ],
        'warehouse_personnel' => [
            ['key' => 'stock_in', 'label' => 'Post Stock-In', 'fallback' => 'IN', 'url' => 'stock_in.php', 'permission' => 'post_stock_in'],
            ['key' => 'releases', 'label' => 'Release Materials', 'fallback' => 'RL', 'url' => 'releases.php', 'permission' => 'release_materials'],
        ],
        'procurement' => [
            ['key' => 'procurement', 'label' => 'Procurement Desk', 'fallback' => 'PD', 'url' => 'procurement.php', 'permission' => 'manage_procurement'],
            ['key' => 'purchase_orders', 'label' => 'Purchase Orders', 'fallback' => 'PO', 'url' => 'purchase_orders.php', 'permission' => 'manage_procurement'],
        ],
        'president' => [
            ['key' => 'purchase_requests', 'label' => 'Approve Procurement', 'fallback' => 'PR', 'url' => 'purchase_requests.php?status=submitted', 'permission' => 'approve_procurement'],
            ['key' => 'reports_executive', 'label' => 'Executive Report', 'fallback' => 'EX', 'url' => 'reports_executive.php', 'permission' => 'view_executive_dashboard'],
            ['key' => 'reports_forecast', 'label' => 'Forecast Report', 'fallback' => 'FR', 'url' => 'reports_forecast.php', 'permission' => 'view_forecast_reports'],
        ],
    ];

    return array_values(array_filter(
        $actionsByRole[$role] ?? [],
        static fn(array $action): bool => role_can($role, (string) $action['permission'])
    ));
}
