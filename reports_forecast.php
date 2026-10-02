<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['view_reports', 'view_forecast_reports']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';

$runs = $pdo->query("SELECT id, run_no, started_at, status FROM forecast_runs ORDER BY started_at DESC, id DESC LIMIT 40")->fetchAll();
$latestRun = latest_forecast_run($pdo);
$selectedRunId = (int) ($_GET['run_id'] ?? ($latestRun['id'] ?? 0));
$riskFilter = filter_choice($_GET, 'risk', ['critical', 'high', 'medium', 'stable']);
$confidenceFilter = filter_choice($_GET, 'confidence', ['low', 'medium', 'high']);
$q = filter_text($_GET, 'q');
$run = $selectedRunId > 0 ? forecast_run_by_id($pdo, $selectedRunId) : null;
$rows = $run ? forecast_items_for_run($pdo, (int) $run['id']) : [];

if ($riskFilter !== '') {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) $row['risk_level'] === $riskFilter));
}
if ($confidenceFilter !== '') {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => (string) $row['confidence'] === $confidenceFilter));
}
if ($q !== '') {
    $needle = strtolower($q);
    $rows = array_values(array_filter($rows, static fn(array $row): bool => str_contains(strtolower((string) $row['material_code'] . ' ' . (string) $row['material_name'] . ' ' . (string) ($row['supplier_name'] ?? '')), $needle)));
}

function report_forecast_chart_attr(array $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES));
}

$reportRiskChart = ['critical' => 0, 'high' => 0, 'medium' => 0, 'stable' => 0];
foreach ($rows as $row) {
    $risk = (string) $row['risk_level'];
    if (isset($reportRiskChart[$risk])) {
        $reportRiskChart[$risk]++;
    }
}
$reportOrderChart = array_map(static fn(array $row): array => [
    'label' => (string) $row['material_code'],
    'value' => (float) $row['recommended_order_qty'],
    'risk' => (string) $row['risk_level'],
], array_slice($rows, 0, 8));

$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this report.');
    }

    send_csv('forecast-report-' . date('Ymd') . '.csv', ['Run', 'Material Code', 'Material Name', 'Available', 'Forecast Qty', 'Recommended Qty', 'Stockout Date', 'Risk', 'Confidence'], array_map(static fn(array $row): array => [
        $run['run_no'] ?? '',
        $row['material_code'],
        $row['material_name'],
        $row['available_stock'],
        $row['forecast_qty'],
        $row['recommended_order_qty'],
        $row['projected_stockout_date'],
        $row['risk_level'],
        $row['confidence'],
    ], $rows));
}

$activePage = 'reports_forecast';
$title = 'Forecast Report';
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
            <section class="forecast-terminal monitoring-top-card">
                <div>
                    <span class="eyebrow light">DEMAND REPORT</span>
                    <h2>Forecast Report</h2>
                    <p>Inspect forecast demand, replenishment quantities, stockout dates, and model confidence for planning and procurement review.</p>
                </div>
                <div class="hero-actions">
                    <a class="btn glass" href="forecasting.php">Run Forecast</a>
                    <?php if ($canExport && $run): ?><a class="btn glass subtle" href="<?= e(filter_export_url(['export' => 'csv'])) ?>">Export CSV</a><?php endif; ?>
                    <button class="btn glass subtle" onclick="window.print()">Print</button>
                </div>
            </section>

            <section class="filter-card">
                <form class="report-toolbar" method="get" data-filter-form>
                    <label class="filter-search">Search
                        <input name="q" value="<?= e($q) ?>" placeholder="Material, code, supplier">
                    </label>
                    <label>Forecast Run
                        <select name="run_id">
                            <?php foreach ($runs as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= $selectedRunId === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['run_no']) ?> - <?= e(date('M d, Y', strtotime($option['started_at']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Risk
                        <select name="risk">
                            <?php render_status_options(['critical', 'high', 'medium', 'stable'], $riskFilter, 'All risks'); ?>
                        </select>
                    </label>
                    <label>Confidence
                        <select name="confidence">
                            <?php render_status_options(['low', 'medium', 'high'], $confidenceFilter, 'All confidence'); ?>
                        </select>
                    </label>
                    <div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="reports_forecast.php" data-clear-filters>Clear</a></div>
                    <?= filter_badge(count($rows)) ?>
                </form>
            </section>

            <section class="live-dashboard forecast-live-board">
                <article class="live-card diagram-card">
                    <div class="live-card-head"><div><span class="eyebrow">REPORT MIX</span><h2>Risk Distribution</h2></div></div>
                    <canvas class="chart-canvas" width="320" height="210" data-chart="report-risk" data-chart-data="<?= report_forecast_chart_attr($reportRiskChart) ?>"></canvas>
                </article>
                <article class="live-card diagram-card wide">
                    <div class="live-card-head"><div><span class="eyebrow">ORDER LOAD</span><h2>Recommended Replenishment</h2></div></div>
                    <canvas class="chart-canvas" width="720" height="220" data-chart="report-order-bars" data-chart-data="<?= report_forecast_chart_attr($reportOrderChart) ?>"></canvas>
                </article>
            </section>

            <section class="content-card">
                <div class="card-heading"><div><span class="eyebrow">FORECAST ITEMS</span><h2><?= $run ? e($run['run_no']) : 'No Forecast Run' ?></h2></div></div>
                <div class="table-wrap">
                    <table class="data modern-table">
                        <thead><tr><th>Material</th><th>Available</th><th>Forecast</th><th>Recommended</th><th>Stockout</th><th>Confidence</th><th>Risk</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No forecast rows match the selected filters.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td>
                                <td><?= money_or_qty($row['available_stock']) ?> <?= e($row['unit_name']) ?></td>
                                <td><?= money_or_qty($row['forecast_qty']) ?> <?= e($row['unit_name']) ?></td>
                                <td><b><?= money_or_qty($row['recommended_order_qty']) ?></b> <?= e($row['unit_name']) ?></td>
                                <td><?= $row['projected_stockout_date'] ? e(date('M d, Y', strtotime($row['projected_stockout_date']))) : 'No stockout' ?></td>
                                <td><?= e($row['confidence']) ?></td>
                                <td><span class="workflow-badge risk-<?= e($row['risk_level']) ?>"><?= e($row['risk_level']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
