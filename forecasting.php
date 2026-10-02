<?php
require_once __DIR__ . '/lib/auth.php';
require_any_permission(['view_reports', 'view_forecast_reports']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';
require_once __DIR__ . '/lib/gemini.php';

$settings = forecast_settings($pdo);
$summary = forecast_dashboard_summary($pdo);
$latestRun = $summary['latest_run'];
$items = $latestRun ? forecast_items_for_run($pdo, (int) $latestRun['id']) : [];
$geminiReady = gemini_is_configured();

function forecast_sparkline_payload(?string $json): string
{
    $rows = json_decode((string) $json, true);
    if (!is_array($rows)) {
        return '[]';
    }

    $values = array_map(static fn(array $row): float => round((float) ($row['quantity'] ?? 0), 2), $rows);
    return e(json_encode($values));
}

function forecast_chart_attr(array $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES));
}

$forecastRiskChart = [
    'critical' => (int) $summary['critical'],
    'high' => (int) $summary['high'],
    'medium' => (int) $summary['medium'],
    'stable' => (int) $summary['stable'],
];
$forecastDemandChart = array_map(static fn(array $item): array => [
    'label' => (string) $item['material_code'],
    'value' => (float) $item['forecast_qty'],
    'risk' => (string) $item['risk_level'],
], array_slice($items, 0, 8));
$forecastOrderChart = array_map(static fn(array $item): array => [
    'label' => (string) $item['material_code'],
    'value' => (float) $item['recommended_order_qty'],
    'risk' => (string) $item['risk_level'],
], array_slice($items, 0, 8));

$activePage = 'forecasting';
$title = 'Forecast Command Center';
$eyebrow = 'MATERIAL DEMAND';
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
            <section class="forecast-terminal">
                <div>
                    <span class="eyebrow light">DEMAND ENGINE</span>
                    <h2>Forecast Command Center</h2>
                    <p>Project material demand from movement history, surface stockout risk, and turn the signal into replenishment recommendations.</p>
                    <div class="hero-actions">
                        <?php if ($latestRun): ?><a class="btn glass" href="forecast_view.php?id=<?= (int) $latestRun['id'] ?>">Latest Run</a><?php endif; ?>
                        <a class="btn glass subtle" href="ai_recommendations.php">AI Recommendations</a>
                        <a class="btn glass subtle" href="reports_forecast.php">Forecast Report</a>
                    </div>
                </div>
                <form class="forecast-run-panel" method="post" action="forecast_run.php" data-forecast-run data-block-on-submit="Running forecast model">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Horizon Days
                        <input type="number" name="horizon_days" min="7" max="365" value="<?= (int) $settings['horizon_days'] ?>">
                    </label>
                    <label>History Days
                        <input type="number" name="history_days" min="30" max="730" value="<?= (int) $settings['history_days'] ?>">
                    </label>
                    <button class="btn primary" type="submit" data-confirm="Run a new material demand forecast?" data-confirm-title="Run Forecast">Run Forecast</button>
                    <small><?= $geminiReady ? 'AI recommendations are enabled.' : 'Planning rules will prepare recommendations.' ?></small>
                </form>
            </section>

            <section class="forecast-grid">
                <article class="metric-card"><span>Latest Run</span><strong><?= $latestRun ? e($latestRun['run_no']) : 'None' ?></strong><small><?= $latestRun ? e(ucfirst((string) $latestRun['status'])) : 'Ready to start' ?></small></article>
                <article class="metric-card risk-critical"><span>Critical Risks</span><strong><?= number_format((int) $summary['critical']) ?></strong><small>Immediate review</small></article>
                <article class="metric-card risk-high"><span>High Risks</span><strong><?= number_format((int) $summary['high']) ?></strong><small>Next planning window</small></article>
                <article class="metric-card"><span>Forecast Demand</span><strong><?= money_or_qty($summary['forecast_qty']) ?></strong><small>Latest run total</small></article>
                <article class="metric-card"><span>Open AI Actions</span><strong><?= number_format((int) $summary['open_recommendations']) ?></strong><small>Recommendation board</small></article>
            </section>

            <section class="live-dashboard forecast-live-board">
                <article class="live-card ai-pulse-card">
                    <div class="live-card-head">
                        <div><span class="eyebrow">AI SIGNAL</span><h2>Recommendation Focus</h2></div>
                        <span class="signal-led">Planning</span>
                    </div>
                    <div class="pulse-number"><strong><?= number_format((int) $summary['open_recommendations']) ?></strong><span>open actions</span></div>
                    <p>Critical and high-risk materials are pushed toward the recommendation board for warehouse and procurement review.</p>
                </article>
                <article class="live-card diagram-card">
                    <div class="live-card-head"><div><span class="eyebrow">RISK</span><h2>Forecast Risk Mix</h2></div></div>
                    <canvas class="chart-canvas" width="320" height="210" data-chart="forecast-risk" data-chart-data="<?= forecast_chart_attr($forecastRiskChart) ?>"></canvas>
                </article>
                <article class="live-card diagram-card">
                    <div class="live-card-head"><div><span class="eyebrow">DEMAND</span><h2>Forecast Demand</h2></div></div>
                    <canvas class="chart-canvas" width="360" height="210" data-chart="forecast-demand" data-chart-data="<?= forecast_chart_attr($forecastDemandChart) ?>"></canvas>
                </article>
                <article class="live-card diagram-card">
                    <div class="live-card-head"><div><span class="eyebrow">ORDER</span><h2>Replenishment Load</h2></div></div>
                    <canvas class="chart-canvas" width="360" height="210" data-chart="forecast-orders" data-chart-data="<?= forecast_chart_attr($forecastOrderChart) ?>"></canvas>
                </article>
            </section>

            <section class="content-card">
                <div class="card-heading">
                    <div><span class="eyebrow">LATEST SIGNAL</span><h2>Forecasted Material Risk</h2></div>
                    <?php if ($latestRun): ?>
                        <div class="header-actions">
                            <a class="btn secondary compact" href="forecast_view.php?id=<?= (int) $latestRun['id'] ?>">Open Detail</a>
                            <a class="btn primary compact" href="reports_forecast.php?run_id=<?= (int) $latestRun['id'] ?>">Report</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table class="data modern-table forecast-table">
                        <thead>
                        <tr>
                            <th>Material</th>
                            <th>Trend</th>
                            <th>Available</th>
                            <th>Forecast Qty</th>
                            <th>Stockout</th>
                            <th>Recommended</th>
                            <th>Risk</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$items): ?><tr><td colspan="7" class="muted">Run a forecast to build the first demand signal.</td></tr><?php endif; ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td>
                                <td><canvas class="forecast-sparkline" width="150" height="42" data-sparkline="<?= forecast_sparkline_payload($item['forecast_json']) ?>"></canvas><small><?= e(forecast_method_label((string) $item['method'])) ?> / <?= e($item['confidence']) ?></small></td>
                                <td><b><?= money_or_qty($item['available_stock']) ?></b> <?= e($item['unit_name']) ?><small>Reorder <?= money_or_qty($item['reorder_point']) ?></small></td>
                                <td><?= money_or_qty($item['forecast_qty']) ?> <?= e($item['unit_name']) ?><small><?= money_or_qty($item['average_daily_demand']) ?> daily avg</small></td>
                                <td><?= $item['projected_stockout_date'] ? e(date('M d, Y', strtotime($item['projected_stockout_date']))) : 'No stockout' ?></td>
                                <td><b><?= money_or_qty($item['recommended_order_qty']) ?></b> <?= e($item['unit_name']) ?></td>
                                <td><span class="workflow-badge risk-<?= e($item['risk_level']) ?>"><?= e($item['risk_level']) ?></span></td>
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
