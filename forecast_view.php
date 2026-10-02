<?php
require_once __DIR__ . '/lib/auth.php';
require_any_permission(['view_reports', 'view_forecast_reports']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';

$runId = (int) ($_GET['id'] ?? 0);
$run = $runId > 0 ? forecast_run_by_id($pdo, $runId) : null;
if (!$run) {
    http_response_code(404);
    die('Forecast run not found.');
}

$items = forecast_items_for_run($pdo, $runId);
$recommendations = forecast_recommendations_for_run($pdo, $runId);
$summary = json_decode((string) ($run['raw_summary_json'] ?? ''), true);
$warnings = is_array($summary['warnings'] ?? null) ? $summary['warnings'] : [];

function forecast_view_sparkline(?string $json): string
{
    $rows = json_decode((string) $json, true);
    if (!is_array($rows)) {
        return '[]';
    }
    return e(json_encode(array_map(static fn(array $row): float => round((float) ($row['quantity'] ?? 0), 2), $rows)));
}

$activePage = 'forecasting';
$title = 'Forecast Run ' . $run['run_no'];
$eyebrow = 'FORECAST DETAIL';
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
                    <span class="eyebrow light">RUN <?= e($run['status']) ?></span>
                    <h2><?= e($run['run_no']) ?></h2>
                    <p><?= (int) $run['horizon_days'] ?> day forecast built from <?= (int) $run['history_days'] ?> days of material movement history.</p>
                    <div class="hero-actions">
                        <a class="btn glass" href="forecasting.php">Forecasting</a>
                        <a class="btn glass subtle" href="ai_recommendations.php">Recommendations</a>
                        <a class="btn glass subtle" href="reports_forecast.php?run_id=<?= (int) $run['id'] ?>">Report</a>
                    </div>
                </div>
                <div class="forecast-run-panel">
                    <span>Started By</span>
                    <strong><?= e($run['started_by_name']) ?></strong>
                    <span>Started</span>
                    <strong><?= e(date('M d, Y h:i A', strtotime($run['started_at']))) ?></strong>
                    <span>Model</span>
                    <strong>Local demand model</strong>
                </div>
            </section>

            <?php if ($warnings): ?>
                <section class="content-card">
                    <div class="card-heading"><div><span class="eyebrow">ENGINE NOTES</span><h2>Model Warnings</h2></div></div>
                    <ul class="timeline-list">
                        <?php foreach (array_slice($warnings, 0, 8) as $warning): ?>
                            <li class="timeline-item"><span>Warning</span><small><?= e($warning) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <section class="content-card">
                <div class="card-heading"><div><span class="eyebrow">MATERIALS</span><h2>Forecast Items</h2></div></div>
                <div class="table-wrap">
                    <table class="data modern-table forecast-table">
                        <thead><tr><th>Material</th><th>Trend</th><th>Available</th><th>Forecast</th><th>Recommended</th><th>Stockout</th><th>Risk</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td>
                                <td><canvas class="forecast-sparkline" width="150" height="42" data-sparkline="<?= forecast_view_sparkline($item['forecast_json']) ?>"></canvas><small><?= e(forecast_method_label((string) $item['method'])) ?></small></td>
                                <td><?= money_or_qty($item['available_stock']) ?> <?= e($item['unit_name']) ?></td>
                                <td><b><?= money_or_qty($item['forecast_qty']) ?></b><small><?= money_or_qty($item['average_daily_demand']) ?> daily avg</small></td>
                                <td><?= money_or_qty($item['recommended_order_qty']) ?> <?= e($item['unit_name']) ?></td>
                                <td><?= $item['projected_stockout_date'] ? e(date('M d, Y', strtotime($item['projected_stockout_date']))) : 'No stockout' ?></td>
                                <td><span class="workflow-badge risk-<?= e($item['risk_level']) ?>"><?= e($item['risk_level']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="content-card">
                <div class="card-heading"><div><span class="eyebrow">AI BOARD</span><h2>Recommendations From This Run</h2></div><a class="btn primary compact" href="ai_recommendations.php">Open Board</a></div>
                <div class="recommendation-board">
                    <?php if (!$recommendations): ?><p class="muted">No recommendation actions were created for this run.</p><?php endif; ?>
                    <?php foreach ($recommendations as $rec): ?>
                        <article class="ai-card risk-<?= e($rec['urgency']) ?>">
                            <span><?= e($rec['urgency']) ?> / <?= e($rec['source'] === 'gemini' ? 'AI-assisted' : 'Rule-based analysis') ?></span>
                            <h3><?= e($rec['title']) ?></h3>
                            <p><?= e($rec['recommendation']) ?></p>
                            <small><?= e($rec['material_code']) ?> - <?= e($rec['status']) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
