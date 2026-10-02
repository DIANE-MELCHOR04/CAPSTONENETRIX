<?php
require_once __DIR__ . '/lib/auth.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';
require_once __DIR__ . '/lib/dashboard_charts.php';
require_once __DIR__ . '/lib/dashboard_workspaces.php';
require_once __DIR__ . '/lib/flow_upgrade.php';

$activePage = 'dashboard';
$title = 'Inventory Dashboard';
$eyebrow = 'METRICCON MATERIALS';
$role = (string) ($_SESSION['role'] ?? '');
$forecastSummary = null;
$liveSnapshot = dashboard_live_snapshot($pdo);

if (role_can($role, 'view_reports') || role_can($role, 'view_forecast_reports')) {
    try {
        $forecastSummary = forecast_dashboard_summary($pdo);
    } catch (Throwable $exception) {
        $forecastSummary = null;
    }
}

function dashboard_count(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function dashboard_chart_attr(array $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES));
}

$stats = [
    'materials' => dashboard_count($pdo, "SELECT COUNT(*) FROM materials WHERE is_active = 1 AND approval_status = 'approved'"),
    'locations' => dashboard_count($pdo, 'SELECT COUNT(*) FROM locations WHERE is_active = 1'),
    'low_stock' => dashboard_count($pdo, 'SELECT COUNT(*) FROM inventory_balances b JOIN materials m ON m.id = b.material_id WHERE (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point'),
    'pending_requests' => dashboard_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE status IN ('submitted','under_review','partially_reserved','for_procurement')"),
];

if ($role === 'site_engineer') {
    $siteId = current_user_site_id() ?? 0;
    $userId = (int) current_user()['id'];
    $stats['locations'] = dashboard_count($pdo, 'SELECT COUNT(*) FROM locations WHERE is_active = 1 AND site_id = ' . $siteId);
    $stats['low_stock'] = dashboard_count($pdo, 'SELECT COUNT(*) FROM inventory_balances b JOIN materials m ON m.id = b.material_id JOIN locations l ON l.id = b.location_id WHERE l.site_id = ' . $siteId . ' AND (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point');
    $stats['pending_requests'] = dashboard_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE requested_by = {$userId} AND status IN ('submitted','under_review','partially_reserved','for_procurement')");
    $liveSnapshot['metrics']['available_stock'] = (float) $pdo->query('SELECT COALESCE(SUM(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity), 0) FROM inventory_balances b JOIN locations l ON l.id = b.location_id WHERE l.site_id = ' . $siteId)->fetchColumn();
}

$recentMovements = $pdo->query(
    "SELECT im.*, m.material_code, m.material_name, l.name AS location_name, u.name AS unit_name
     FROM inventory_movements im
     JOIN materials m ON m.id = im.material_id
     JOIN units u ON u.id = m.unit_id
     JOIN locations l ON l.id = im.location_id
     ORDER BY im.created_at DESC
     LIMIT 8"
)->fetchAll();

$siteDashboards = $pdo->query(
    "SELECT s.id, s.code, s.name,
            COALESCE(req.open_requests, 0) AS open_requests,
            ROUND(COALESCE(stock.available_stock, 0), 2) AS available_stock
     FROM sites s
     LEFT JOIN (
         SELECT site_id, COUNT(*) AS open_requests
         FROM material_requests
         WHERE status NOT IN ('completed','cancelled','rejected')
         GROUP BY site_id
     ) req ON req.site_id = s.id
     LEFT JOIN (
         SELECT l.site_id, SUM(GREATEST(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity, 0)) AS available_stock
         FROM locations l
         JOIN inventory_balances b ON b.location_id = l.id
         WHERE l.is_active = 1
         GROUP BY l.site_id
     ) stock ON stock.site_id = s.id
     WHERE s.status = 'active'
     ORDER BY open_requests DESC, s.name
     LIMIT 8"
)->fetchAll();

$operationalQueues = [
    ['label' => 'Material Approvals', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE status IN ('submitted','under_review')"), 'url' => 'requests.php', 'permissions' => ['approve_requests']],
    ['label' => 'Ready For Release', 'count' => dashboard_count($pdo, "SELECT COUNT(DISTINCT r.id) FROM material_requests r JOIN material_request_items i ON i.request_id = r.id JOIN material_reservations mr ON mr.request_item_id = i.id WHERE mr.status = 'active' AND mr.reserved_qty > mr.released_qty"), 'url' => 'requests.php', 'permissions' => ['release_materials']],
    ['label' => 'Receipts To Confirm', 'count' => dashboard_count($pdo, $role === 'site_engineer' ? "SELECT COUNT(*) FROM material_releases rel JOIN material_requests r ON r.id = rel.request_id WHERE r.requested_by = " . (int) current_user()['id'] . " AND rel.status IN ('released','partially_received')" : "SELECT COUNT(*) FROM material_releases WHERE status IN ('released','partially_received')"), 'url' => 'releases.php', 'permissions' => ['confirm_receipt']],
    ['label' => 'Transfer Queue', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM material_transfers WHERE status IN ('submitted','approved','in_transit','partially_received','discrepancy')"), 'url' => 'transfers.php', 'permissions' => ['view_transfers', 'create_transfers', 'dispatch_transfers', 'receive_transfers', 'approve_transfers']],
    ['label' => 'Damage Review', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM damage_reports WHERE status = 'submitted'"), 'url' => 'damage_reports.php', 'permissions' => ['manage_damage', 'approve_damage']],
    ['label' => 'Adjustment Review', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'submitted'"), 'url' => 'adjustments.php', 'permissions' => ['manage_adjustments', 'approve_adjustments']],
    ['label' => 'Stock Counts', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM stock_counts WHERE status IN ('submitted','approved','adjustment_created')"), 'url' => 'stock_counts.php', 'permissions' => ['view_stock_counts', 'manage_stock_counts', 'approve_stock_counts']],
];

// Accountability queues added by the flow upgrade. These are conditional so the
// dashboard still loads before the migration is imported.
if (db_table_exists($pdo, 'receipt_discrepancies')) {
    $operationalQueues[] = [
        'label' => 'Receipt Discrepancies',
        'count' => open_receipt_discrepancy_count($pdo, $role === 'site_engineer' ? (current_user_site_id() ?? 0) : null),
        'url' => 'releases.php',
        'permissions' => ['resolve_receipt_discrepancies'],
    ];
}
if (db_table_exists($pdo, 'manual_stock_out_requests')) {
    $operationalQueues[] = [
        'label' => 'Emergency Stock-Out Approvals',
        'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM manual_stock_out_requests WHERE status='submitted'"),
        'url' => 'stock_out.php',
        'permissions' => ['approve_stock_out'],
    ];
}
if (db_table_exists($pdo, 'workflow_cancellations') && db_table_exists($pdo, 'return_to_supplier_records') && db_table_exists($pdo, 'disposal_writeoffs')) {
    $operationalQueues[] = [
        'label' => 'Workflow Exceptions',
        'count' => dashboard_count($pdo, "SELECT (SELECT COUNT(*) FROM workflow_cancellations WHERE status='requested') + (SELECT COUNT(*) FROM return_to_supplier_records WHERE status IN ('submitted','approved')) + (SELECT COUNT(*) FROM disposal_writeoffs WHERE status IN ('submitted','approved'))"),
        'url' => 'workflow_control.php',
        'permissions' => ['manage_workflow_control'],
    ];
}
if (db_table_exists($pdo, 'return_to_supplier_records') && db_table_exists($pdo, 'disposal_writeoffs')) {
    $operationalQueues[] = [
        'label' => 'Approved Exceptions To Post',
        'count' => dashboard_count($pdo, "SELECT (SELECT COUNT(*) FROM return_to_supplier_records WHERE status='approved') + (SELECT COUNT(*) FROM disposal_writeoffs WHERE status='approved')"),
        'url' => 'workflow_control.php',
        'permissions' => ['post_workflow_control'],
    ];
}
$operationalQueues[] = [
    'label' => 'Overdue Site Receipts',
    'count' => dashboard_count($pdo, $role === 'site_engineer' ? "SELECT COUNT(*) FROM material_releases rel JOIN material_requests r ON r.id=rel.request_id WHERE r.requested_by=" . (int)current_user()['id'] . " AND rel.status IN ('released','partially_received') AND rel.release_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)" : "SELECT COUNT(*) FROM material_releases WHERE status IN ('released','partially_received') AND release_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
    'url' => 'releases.php',
    'permissions' => ['confirm_receipt','resolve_receipt_discrepancies'],
];

$procurementQueues = [
    ['label' => 'Shortage Lines', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM material_request_items i WHERE i.shortage_qty > 0 AND NOT EXISTS (SELECT 1 FROM purchase_request_items pri JOIN purchase_requests pr ON pr.id = pri.purchase_request_id WHERE pri.source_type = 'material_request' AND pri.source_item_id = i.id AND pri.status NOT IN ('rejected','cancelled','completed') AND pr.status NOT IN ('rejected','cancelled','completed'))"), 'url' => 'purchase_request_create.php', 'permissions' => ['manage_procurement']],
    ['label' => 'Requests Awaiting President', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'submitted'"), 'url' => 'purchase_requests.php?status=submitted', 'permissions' => ['approve_procurement']],
    ['label' => 'Active Purchase Requests', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM purchase_requests WHERE status IN ('draft','submitted','approved','partially_ordered')"), 'url' => 'purchase_requests.php', 'permissions' => ['manage_procurement']],
    ['label' => 'Orders Awaiting President', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'submitted'"), 'url' => 'purchase_orders.php?status=submitted', 'permissions' => ['approve_procurement']],
    ['label' => 'Open Purchase Orders', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('open','partially_received')"), 'url' => 'purchase_orders.php', 'permissions' => ['manage_procurement']],
    ['label' => 'Supplier Deliveries', 'count' => dashboard_count($pdo, "SELECT COUNT(*) FROM supplier_deliveries WHERE received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"), 'url' => 'purchase_orders.php', 'permissions' => ['manage_procurement', 'receive_supplier_deliveries']],
];

$queueVisible = static function (array $queue) use ($role): bool {
    foreach ($queue['permissions'] as $permission) {
        if (role_can($role, (string) $permission)) {
            return true;
        }
    }
    return false;
};

$visibleOperationalQueues = array_values(array_filter($operationalQueues, $queueVisible));
$visibleProcurementQueues = array_values(array_filter($procurementQueues, $queueVisible));

$forecastConfidence = 0;
try {
    $forecastConfidence = (int) round((float) $pdo->query('SELECT COALESCE(AVG(confidence_score), 0) FROM forecast_model_results WHERE is_selected = 1')->fetchColumn());
} catch (Throwable $exception) {
    $forecastConfidence = 0;
}

$overduePurchaseOrders = dashboard_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('open','partially_received') AND expected_delivery IS NOT NULL AND expected_delivery < CURDATE()");
$readyForRelease = dashboard_count($pdo, "SELECT COUNT(DISTINCT r.id) FROM material_requests r JOIN material_request_items i ON i.request_id = r.id JOIN material_reservations mr ON mr.request_item_id = i.id WHERE mr.status = 'active' AND mr.reserved_qty > mr.released_qty");
$receiptsToConfirm = dashboard_count($pdo, "SELECT COUNT(*) FROM material_releases WHERE status IN ('released','partially_received')");
$shortageLines = dashboard_count($pdo, "SELECT COUNT(*) FROM material_request_items i WHERE i.shortage_qty > 0 AND NOT EXISTS (SELECT 1 FROM purchase_request_items pri JOIN purchase_requests pr ON pr.id = pri.purchase_request_id WHERE pri.source_type = 'material_request' AND pri.source_item_id = i.id AND pri.status NOT IN ('rejected','cancelled','completed') AND pr.status NOT IN ('rejected','cancelled','completed'))");
$openPurchaseOrders = dashboard_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('open','partially_received')");
$movementsToday = dashboard_count($pdo, 'SELECT COUNT(*) FROM inventory_movements WHERE DATE(created_at) = CURDATE()');
$pendingApprovals = dashboard_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE status IN ('submitted','under_review')");
$pendingProcurementApprovals = dashboard_count($pdo, "SELECT (SELECT COUNT(*) FROM purchase_requests WHERE status = 'submitted') + (SELECT COUNT(*) FROM purchase_orders WHERE status = 'submitted')");

if ($role === 'site_engineer') {
    $userId = (int) current_user()['id'];
    $readyForRelease = dashboard_count($pdo, "SELECT COUNT(DISTINCT r.id) FROM material_requests r JOIN material_request_items i ON i.request_id = r.id JOIN material_reservations mr ON mr.request_item_id = i.id WHERE r.requested_by = {$userId} AND mr.status = 'active' AND mr.reserved_qty > mr.released_qty");
    $receiptsToConfirm = dashboard_count($pdo, "SELECT COUNT(*) FROM material_releases rel JOIN material_requests r ON r.id = rel.request_id WHERE r.requested_by = {$userId} AND rel.status IN ('released','partially_received')");
}

$taskGroups = [];
if ($visibleOperationalQueues) {
    $taskGroups[] = ['title' => 'Operational Queues', 'items' => $visibleOperationalQueues];
}
if ($visibleProcurementQueues) {
    $taskGroups[] = ['title' => 'Procurement Queue', 'items' => $visibleProcurementQueues];
}

$snapshot = [
    'active_materials' => $stats['materials'],
    'active_locations' => $stats['locations'],
    'active_users' => dashboard_count($pdo, 'SELECT COUNT(*) FROM users WHERE is_active = 1'),
    'configuration_entries' => dashboard_count($pdo, 'SELECT COUNT(*) FROM app_settings'),
    'successful_backups' => dashboard_count($pdo, "SELECT COUNT(*) FROM backup_logs WHERE status = 'completed'"),
    'audit_events_today' => dashboard_count($pdo, 'SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()'),
    'critical_stock' => $stats['low_stock'],
    'pending_requests' => $stats['pending_requests'],
    'pending_approvals' => $pendingApprovals,
    'pending_procurement_approvals' => $pendingProcurementApprovals,
    'overdue_purchase_orders' => $overduePurchaseOrders,
    'forecast_confidence' => $forecastConfidence,
    'ready_for_release' => $readyForRelease,
    'receipts_to_confirm' => $receiptsToConfirm,
    'shortage_lines' => $shortageLines,
    'open_purchase_orders' => $openPurchaseOrders,
    'movements_today' => $movementsToday,
    'available_stock' => (float) ($liveSnapshot['metrics']['available_stock'] ?? 0),
    'open_ai_actions' => (int) ($liveSnapshot['metrics']['open_ai_actions'] ?? 0),
    'task_groups' => $taskGroups,
    'ai_briefing' => [
        'count' => (int) ($liveSnapshot['metrics']['open_ai_actions'] ?? 0),
        'latest' => (array) ($liveSnapshot['ai_focus']['latest'] ?? []),
    ],
    'recent_activity' => $recentMovements,
];
$profile = dashboard_workspace_profile($role, $snapshot);
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
        <div class="page-content ops-dashboard-workspace">
            <?php require __DIR__ . '/partials/dashboard/workspace.php'; ?>
        </div>
    </main>
</div>
</body>
</html>
