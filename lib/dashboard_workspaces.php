<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function dashboard_workspace_profile(string $role, array $snapshot): array
{
    $metricDefinitions = [
        'active_materials' => ['label' => 'Active materials', 'detail' => 'Master catalog', 'tone' => 'info', 'url' => 'materials.php'],
        'active_locations' => ['label' => 'Active locations', 'detail' => 'Warehouses and sites', 'tone' => 'neutral', 'url' => 'inventory.php'],
        'active_users' => ['label' => 'Active users', 'detail' => 'Enabled system accounts', 'tone' => 'info', 'url' => 'users.php'],
        'configuration_entries' => ['label' => 'Configuration entries', 'detail' => 'Saved system settings', 'tone' => 'neutral', 'url' => 'settings.php'],
        'successful_backups' => ['label' => 'Completed backups', 'detail' => 'Successful database copies', 'tone' => 'success', 'url' => 'backups.php'],
        'audit_events_today' => ['label' => 'Audit events today', 'detail' => 'Recorded user activity', 'tone' => 'warning', 'url' => 'audit_logs.php'],
        'critical_stock' => ['label' => 'Critical stock items', 'detail' => 'At or below safety stock', 'tone' => 'danger', 'url' => 'reports_low_stock.php'],
        'pending_approvals' => ['label' => 'Pending approvals', 'detail' => 'Requests awaiting review', 'tone' => 'warning', 'url' => 'requests.php?status=submitted'],
        'pending_procurement_approvals' => ['label' => 'Procurement approvals', 'detail' => 'Requests and orders awaiting your decision', 'tone' => 'warning', 'url' => 'purchase_requests.php?status=submitted'],
        'overdue_purchase_orders' => ['label' => 'Overdue purchase orders', 'detail' => 'Supplier follow-up required', 'tone' => 'danger', 'url' => 'procurement_intelligence.php'],
        'forecast_confidence' => ['label' => 'Forecast confidence', 'detail' => 'Latest model confidence score', 'tone' => 'success', 'url' => 'advanced_forecasting.php', 'suffix' => '%'],
        'pending_requests' => ['label' => 'Open requests', 'detail' => 'Active material demand', 'tone' => 'warning', 'url' => 'requests.php'],
        'ready_for_release' => ['label' => 'Ready for release', 'detail' => 'Reserved stock awaiting issue', 'tone' => 'success', 'url' => 'releases.php'],
        'receipts_to_confirm' => ['label' => 'Receipts to confirm', 'detail' => 'Released materials at site', 'tone' => 'info', 'url' => 'releases.php'],
        'shortage_lines' => ['label' => 'Shortage lines', 'detail' => 'Demand requiring procurement', 'tone' => 'danger', 'url' => 'purchase_request_create.php'],
        'open_purchase_orders' => ['label' => 'Open purchase orders', 'detail' => 'Orders in the supply line', 'tone' => 'info', 'url' => 'purchase_orders.php'],
        'movements_today' => ['label' => 'Movements today', 'detail' => 'Posted inventory activity', 'tone' => 'neutral', 'url' => 'movements.php'],
        'available_stock' => ['label' => 'Available stock', 'detail' => 'Usable quantity across locations', 'tone' => 'success', 'url' => 'inventory.php'],
        'open_ai_actions' => ['label' => 'Open AI actions', 'detail' => 'Forecast recommendations to review', 'tone' => 'accent', 'url' => 'ai_recommendations.php'],
    ];

    $profiles = [
        'admin' => [
            'eyebrow' => 'System operations',
            'title' => 'IT Administration Console',
            'description' => 'Manage accounts, configuration, security, backups, audit activity, and system master data.',
            'metric_keys' => ['active_users', 'configuration_entries', 'successful_backups', 'audit_events_today'],
            'actions' => [
                ['label' => 'System Health', 'url' => 'security_center.php', 'permission' => 'manage_settings', 'class' => 'secondary'],
                ['label' => 'Manage Users', 'url' => 'users.php', 'permission' => 'manage_users', 'class' => 'secondary'],
                ['label' => 'Backups', 'url' => 'backups.php', 'permission' => 'manage_backups', 'class' => 'primary'],
            ],
            'chart_keys' => [],
            'show_tasks' => false,
            'show_ai_briefing' => false,
            'show_site_dashboards' => false,
            'show_recent_activity' => false,
        ],
        'warehouse_manager' => [
            'eyebrow' => 'Warehouse management',
            'title' => 'Warehouse Management Overview',
            'description' => 'Prioritize approvals, stock risk, release readiness, and supplier delays across all locations.',
            'metric_keys' => ['critical_stock', 'pending_approvals', 'overdue_purchase_orders', 'forecast_confidence'],
            'actions' => [
                ['label' => 'Review Requests', 'url' => 'requests.php?status=submitted', 'permission' => 'approve_requests', 'class' => 'primary'],
                ['label' => 'Stock Counts', 'url' => 'stock_counts.php', 'permission' => 'view_stock_counts', 'class' => 'secondary'],
                ['label' => 'Exception Approvals', 'url' => 'workflow_control.php', 'permission' => 'manage_workflow_control', 'class' => 'secondary'],
                ['label' => 'Emergency Stock-Out', 'url' => 'stock_out.php', 'permission' => 'approve_stock_out', 'class' => 'secondary'],
                ['label' => 'Forecast Risks', 'url' => 'advanced_forecasting.php', 'permission' => 'view_reports', 'class' => 'secondary'],
            ],
            'chart_keys' => ['risk_distribution', 'movement_flow', 'location_stock'],
        ],
        'site_engineer' => [
            'eyebrow' => 'Site workspace',
            'title' => 'Site Materials Workspace',
            'description' => 'Create material requests, track fulfillment, confirm receipts, and review available stock for your site.',
            'metric_keys' => ['pending_requests', 'ready_for_release', 'receipts_to_confirm', 'available_stock'],
            'actions' => [
                ['label' => 'Create Request', 'url' => 'request_create.php', 'permission' => 'create_requests', 'class' => 'primary'],
                ['label' => 'My Requests', 'url' => 'requests.php', 'permission' => 'view_own_requests', 'class' => 'secondary'],
                ['label' => 'Confirm Receipts', 'url' => 'releases.php', 'permission' => 'confirm_receipt', 'class' => 'secondary'],
                ['label' => 'Site Inventory', 'url' => 'site_inventory.php', 'permission' => 'manage_site_inventory', 'class' => 'secondary'],
            ],
            'chart_keys' => [],
            'show_ai_briefing' => false,
            'show_site_dashboards' => false,
        ],
        'warehouse_personnel' => [
            'eyebrow' => 'Warehouse floor',
            'title' => 'Warehouse Operations',
            'description' => 'Handle releases, receipts, movements, transfers, and counts from one focused queue.',
            'metric_keys' => ['ready_for_release', 'receipts_to_confirm', 'movements_today', 'critical_stock'],
            'actions' => [
                ['label' => 'Record Stock-In', 'url' => 'stock_in.php', 'permission' => 'post_stock_in', 'class' => 'primary'],
                ['label' => 'Release Materials', 'url' => 'releases.php', 'permission' => 'release_materials', 'class' => 'secondary'],
                ['label' => 'Emergency Stock-Out', 'url' => 'stock_out.php', 'permission' => 'request_stock_out', 'class' => 'secondary'],
                ['label' => 'Exception Posting', 'url' => 'workflow_control.php', 'permission' => 'post_workflow_control', 'class' => 'secondary'],
                ['label' => 'Stock Counts', 'url' => 'stock_counts.php', 'permission' => 'view_stock_counts', 'class' => 'secondary'],
            ],
            'chart_keys' => ['movement_flow', 'location_stock'],
        ],
        'procurement' => [
            'eyebrow' => 'Supply workspace',
            'title' => 'Procurement Control Desk',
            'description' => 'Turn shortages into purchase actions and keep supplier commitments, deliveries, and risk visible.',
            'metric_keys' => ['shortage_lines', 'open_purchase_orders', 'overdue_purchase_orders', 'forecast_confidence'],
            'actions' => [
                ['label' => 'Create Purchase Request', 'url' => 'purchase_request_create.php', 'permission' => 'manage_procurement', 'class' => 'primary'],
                ['label' => 'Purchase Orders', 'url' => 'purchase_orders.php', 'permission' => 'manage_procurement', 'class' => 'secondary'],
                ['label' => 'Supplier Performance', 'url' => 'procurement_intelligence.php', 'permission' => 'view_procurement_intelligence', 'class' => 'secondary'],
            ],
            'chart_keys' => ['risk_distribution', 'procurement_flow', 'demand_trend'],
        ],
        'president' => [
            'eyebrow' => 'Executive overview',
            'title' => 'Executive Inventory Overview',
            'description' => 'Review company-wide inventory exposure, demand risk, supply delays, and the actions requiring leadership attention.',
            'metric_keys' => ['pending_procurement_approvals', 'critical_stock', 'forecast_confidence', 'open_ai_actions'],
            'actions' => [
                ['label' => 'Approve Procurement', 'url' => 'purchase_requests.php?status=submitted', 'permission' => 'approve_procurement', 'class' => 'primary'],
                ['label' => 'Executive Report', 'url' => 'reports_executive.php', 'permission' => 'view_executive_dashboard', 'class' => 'primary'],
                ['label' => 'AI Summary', 'url' => 'ai_recommendations.php', 'permission' => 'view_ai_recommendations', 'class' => 'secondary'],
                ['label' => 'Inventory Valuation', 'url' => 'inventory_intelligence.php', 'permission' => 'view_inventory_intelligence', 'class' => 'secondary'],
            ],
            'chart_keys' => ['risk_distribution', 'demand_trend', 'location_stock', 'procurement_flow'],
        ],
    ];

    if (!isset($profiles[$role])) {
        return [
            'eyebrow' => 'Inventory workspace',
            'title' => 'Inventory Overview',
            'description' => 'Your authorized inventory information appears here.',
            'metrics' => [],
            'tasks' => [],
            'actions' => [],
            'charts' => [],
            'ai_briefing' => null,
            'recent_activity' => [],
            'show_site_dashboards' => false,
            'show_recent_activity' => false,
        ];
    }

    $profile = $profiles[$role];
    $metrics = [];
    foreach ($profile['metric_keys'] as $key) {
        $definition = $metricDefinitions[$key];
        $definition['key'] = $key;
        $definition['value'] = array_key_exists($key, $snapshot) ? $snapshot[$key] : null;
        $metrics[] = $definition;
    }

    $actions = array_values(array_filter(
        $profile['actions'],
        static fn(array $action): bool => role_can($role, (string) $action['permission'])
    ));

    $chartDefinitions = [
        'risk_distribution' => ['key' => 'risk_distribution', 'title' => 'Forecast Risk', 'eyebrow' => 'Risk mix', 'type' => 'risk-donut', 'url' => 'reports_forecast.php', 'width' => 360, 'height' => 230],
        'demand_trend' => ['key' => 'demand_trend', 'title' => '14 Day Usage', 'eyebrow' => 'Demand', 'type' => 'demand-line', 'url' => 'reports_movements.php?movement_type=release', 'width' => 420, 'height' => 230],
        'movement_flow' => ['key' => 'movement_flow', 'title' => 'Ledger Flow', 'eyebrow' => 'Movements', 'type' => 'movement-bars', 'url' => 'movements.php', 'width' => 420, 'height' => 230],
        'location_stock' => ['key' => 'location_stock', 'title' => 'Available Stock by Location', 'eyebrow' => 'Stock map', 'type' => 'location-stock', 'url' => 'inventory.php', 'width' => 780, 'height' => 260, 'class' => 'wide extra-wide location-map-card'],
        'procurement_flow' => ['key' => 'procurement_flow', 'title' => 'Procurement Flow', 'eyebrow' => 'Supply line', 'type' => 'procurement-bars', 'url' => 'procurement_intelligence.php', 'width' => 420, 'height' => 230],
    ];

    return [
        'eyebrow' => $profile['eyebrow'],
        'title' => $profile['title'],
        'description' => $profile['description'],
        'metrics' => $metrics,
        'tasks' => ($profile['show_tasks'] ?? true) ? (array) ($snapshot['task_groups'] ?? []) : [],
        'actions' => $actions,
        'charts' => array_values(array_map(static fn(string $key): array => $chartDefinitions[$key], $profile['chart_keys'])),
        'ai_briefing' => ($profile['show_ai_briefing'] ?? true) ? ($snapshot['ai_briefing'] ?? null) : null,
        'recent_activity' => (array) ($snapshot['recent_activity'] ?? []),
        'show_site_dashboards' => (bool) ($profile['show_site_dashboards'] ?? (role_can($role, 'view_requests') || role_can($role, 'view_own_requests'))),
        'show_recent_activity' => (bool) ($profile['show_recent_activity'] ?? role_can($role, 'view_movements')),
    ];
}
