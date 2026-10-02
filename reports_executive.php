<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/exports.php';
require_permission('view_executive_dashboard');
require_once __DIR__ . '/config/db.php';

function report_count(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

$metrics = [
    ['Active Materials', report_count($pdo, "SELECT COUNT(*) FROM materials WHERE is_active = 1 AND approval_status = 'approved'")],
    ['Low Stock Items', report_count($pdo, 'SELECT COUNT(*) FROM inventory_balances b JOIN materials m ON m.id = b.material_id WHERE (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point')],
    ['Pending Requests', report_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE status IN ('submitted','under_review','partially_reserved','for_procurement')")],
    ['Open Purchase Orders', report_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('open','partially_received')")],
    ['Transfers In Motion', report_count($pdo, "SELECT COUNT(*) FROM material_transfers WHERE status IN ('approved','in_transit','partially_received','discrepancy')")],
    ['Damage For Review', report_count($pdo, "SELECT COUNT(*) FROM damage_reports WHERE status = 'submitted'")],
    ['Adjustments For Review', report_count($pdo, "SELECT COUNT(*) FROM inventory_adjustments WHERE status = 'submitted'")],
    ['Stock Counts Open', report_count($pdo, "SELECT COUNT(*) FROM stock_counts WHERE status IN ('submitted','approved','adjustment_created')")],
];

$criticalRows = $pdo->query(
    "SELECT l.name AS location_name, m.material_code, m.material_name, u.name AS unit_name, m.reorder_point,
            (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) AS available_qty
     FROM inventory_balances b
     JOIN materials m ON m.id = b.material_id
     JOIN units u ON u.id = m.unit_id
     JOIN locations l ON l.id = b.location_id
     WHERE (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point
     ORDER BY available_qty ASC, m.material_name
     LIMIT 10"
)->fetchAll();

$movementTotals = $pdo->query(
    "SELECT movement_type, COALESCE(SUM(quantity_in), 0) AS qty_in, COALESCE(SUM(quantity_out), 0) AS qty_out, COUNT(*) AS total_rows
     FROM inventory_movements
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY movement_type
     ORDER BY total_rows DESC"
)->fetchAll();

$procurementStatus = $pdo->query(
    "SELECT status, COUNT(*) AS total
     FROM purchase_orders
     GROUP BY status
     ORDER BY total DESC"
)->fetchAll();

$executiveMetricChart = array_map(
    static fn(array $metric): array => ['label' => $metric[0], 'value' => (float) $metric[1]],
    $metrics
);
$movementChart = array_map(
    static fn(array $row): array => ['label' => str_replace('_', ' ', (string) $row['movement_type']), 'value' => (float) $row['total_rows']],
    $movementTotals
);

$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this report.');
    }

    send_csv('executive-report-' . date('Ymd') . '.csv', ['Metric', 'Value'], $metrics);
}

$activePage = 'reports_executive';
$title = 'Executive Report';
$eyebrow = 'REPORTS';
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
        <div class="page-content">
            <section class="dashboard-hero monitoring-top-card">
                <div>
                    <span class="eyebrow light">EXECUTIVE VIEW</span>
                    <h2>Inventory, procurement, and movement health</h2>
                    <p>Review the current operational load across stock risk, approvals, supplier orders, transfers, and physical count controls.</p>
                    <div class="hero-actions">
                        <?php if ($canExport): ?><a class="btn glass" href="?export=csv">Export CSV</a><?php endif; ?>
                        <a class="btn glass subtle" href="reports_movements.php">Movement Report</a>
                    </div>
                </div>
            </section>
            <section class="metric-grid compact">
                <?php foreach ($metrics as $metric): ?>
                    <article class="metric-card"><span><?= e($metric[0]) ?></span><strong><?= number_format((float) $metric[1]) ?></strong></article>
                <?php endforeach; ?>
            </section>
            <section class="live-dashboard">
                <article class="live-card diagram-card wide">
                    <div class="live-card-head"><div><span class="eyebrow">OPERATING LOAD</span><h2>Executive Operations Mix</h2></div><a class="ops-chart-link" href="reports_inventory.php">Inventory report</a></div>
                    <canvas class="chart-canvas" width="720" height="230" data-chart="executive-bars" data-drilldown-url="reports_inventory.php" data-chart-data="<?= e(json_encode($executiveMetricChart, JSON_UNESCAPED_SLASHES)) ?>" aria-label="Executive operations mix chart"></canvas>
                </article>
                <article class="live-card diagram-card">
                    <div class="live-card-head"><div><span class="eyebrow">30 DAY FLOW</span><h2>Movement Activity</h2></div><a class="ops-chart-link" href="reports_movements.php">Movement report</a></div>
                    <?php if ($movementChart): ?><canvas class="chart-canvas" width="380" height="230" data-chart="movement-bars" data-drilldown-url="reports_movements.php" data-chart-data="<?= e(json_encode($movementChart, JSON_UNESCAPED_SLASHES)) ?>" aria-label="Movement activity chart"></canvas><?php else: ?><div class="ops-chart-empty">No chart data is available for this period.</div><?php endif; ?>
                </article>
            </section>
            <section class="content-card">
                <div class="card-heading"><div><span class="eyebrow">CRITICAL STOCK</span><h2>Lowest Available Balances</h2></div></div>
                <div class="table-wrap">
                    <table class="data modern-table">
                        <thead><tr><th>Material</th><th>Location</th><th>Available</th><th>Reorder Point</th></tr></thead>
                        <tbody>
                        <?php if (!$criticalRows): ?><tr><td colspan="4" class="muted">No critical stock records found.</td></tr><?php endif; ?>
                        <?php foreach ($criticalRows as $row): ?>
                            <tr>
                                <td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td>
                                <td><?= e($row['location_name']) ?></td>
                                <td><b><?= money_or_qty($row['available_qty']) ?></b> <?= e($row['unit_name']) ?></td>
                                <td><?= money_or_qty($row['reorder_point']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="content-card">
                <div class="card-heading"><div><span class="eyebrow">30 DAY LEDGER</span><h2>Movement Totals</h2></div></div>
                <div class="status-strip">
                    <?php if (!$movementTotals): ?><div><span class="muted">No movement records found.</span></div><?php endif; ?>
                    <?php foreach ($movementTotals as $row): ?>
                        <div><span><?= e(str_replace('_', ' ', $row['movement_type'])) ?></span><b><?= money_or_qty($row['qty_in']) ?> in / <?= money_or_qty($row['qty_out']) ?> out</b><small><?= (int) $row['total_rows'] ?> entries</small></div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="content-card">
                <div class="card-heading"><div><span class="eyebrow">SUPPLIER ORDERS</span><h2>Purchase Order Status</h2></div></div>
                <div class="status-strip">
                    <?php if (!$procurementStatus): ?><div><span class="muted">No purchase orders found.</span></div><?php endif; ?>
                    <?php foreach ($procurementStatus as $row): ?>
                        <div><span><?= e(str_replace('_', ' ', $row['status'])) ?></span><b><?= number_format((int) $row['total']) ?></b><small>purchase orders</small></div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
