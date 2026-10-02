<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/high_value.php';
require_any_permission(['view_reports', 'view_forecast_reports']);
require_once __DIR__ . '/config/db.php';

$materials = $pdo->query("SELECT id, material_code, material_name FROM materials WHERE is_active = 1 AND approval_status = 'approved' ORDER BY material_name")->fetchAll();
$locations = $pdo->query("SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();
$whatIfResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $materialId = max(1, (int) ($_POST['material_id'] ?? 1));
    $locationId = (int) ($_POST['location_id'] ?? 0);
    $startingStock = max(0, (float) ($_POST['starting_stock'] ?? 0));
    $dailyDemand = max(0, (float) ($_POST['daily_demand'] ?? 0));
    $leadTimeDays = max(0, (int) ($_POST['lead_time_days'] ?? 0));
    $incomingQty = max(0, (float) ($_POST['incoming_qty'] ?? 0));
    $horizonDays = max(7, min(365, (int) ($_POST['horizon_days'] ?? 30)));
    $whatIfResult = what_if_stock_projection($startingStock, $dailyDemand, $leadTimeDays, $incomingQty, $horizonDays);

    if (high_value_table_exists($pdo, 'what_if_scenarios')) {
        $stmt = $pdo->prepare(
            'INSERT INTO what_if_scenarios (name, user_id, material_id, location_id, starting_stock, daily_demand, lead_time_days, incoming_qty, horizon_days, result_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            filter_text($_POST, 'scenario_name', 160) ?: 'What-if stock simulation',
            (int) current_user()['id'],
            $materialId,
            $locationId > 0 ? $locationId : null,
            $startingStock,
            $dailyDemand,
            $leadTimeDays,
            $incomingQty,
            $horizonDays,
            json_encode($whatIfResult, JSON_THROW_ON_ERROR),
        ]);
    }
    flash('success', 'What-if scenario calculated.');
}

$accuracy = forecast_accuracy_summary($pdo);
$actualRows = forecast_vs_actual_rows($pdo, 20);
$modelRows = forecast_model_comparison_rows($pdo, 36);
$anomalies = demand_anomaly_rows($pdo, 20);
$suggestions = reorder_suggestion_rows($pdo, 24);
$summaries = ai_manager_summaries($pdo, 4);
$chartActual = array_map(static fn(array $row): array => ['label' => $row['material_code'], 'value' => (float) $row['actual_qty']], array_slice($actualRows, 0, 8));
$chartForecast = array_map(static fn(array $row): array => ['label' => $row['material_code'], 'value' => (float) $row['forecast_qty']], array_slice($actualRows, 0, 8));

$activePage = 'advanced_forecasting';
$title = 'Forecast Intelligence';
$eyebrow = 'AI PLANNING';
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head>
<body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card ai-gradient-card"><div><span class="eyebrow light">FORECAST INTELLIGENCE</span><h2>Forecast Intelligence</h2><p>Forecast Accuracy Tracking, forecast versus actual consumption, model comparison, anomaly detection, reorder suggestions, AI summaries, and What-if Stock Simulation.</p></div><div class="status-chip">Average accuracy <?= money_or_qty($accuracy['average_accuracy']) ?>%</div></section>

<section class="stats-grid">
    <article class="stat-card"><div class="stat-icon">A</div><div><span>Accuracy Rows</span><strong><?= number_format($accuracy['rows']) ?></strong><small>Forecast vs actual checks</small></div></article>
    <article class="stat-card"><div class="stat-icon">M</div><div><span>Model Results</span><strong><?= number_format(count($modelRows)) ?></strong><small>ARIMA comparisons</small></div></article>
    <article class="stat-card"><div class="stat-icon">!</div><div><span>Anomalies</span><strong><?= number_format(count($anomalies)) ?></strong><small>Demand spikes</small></div></article>
    <article class="stat-card"><div class="stat-icon">R</div><div><span>Suggestions</span><strong><?= number_format(count($suggestions)) ?></strong><small>Reorder point drafts</small></div></article>
</section>

<section class="live-dashboard">
    <article class="live-card diagram-card"><div class="live-card-head"><div><span class="eyebrow">ACTUAL</span><h2>Actual Consumption</h2></div></div><canvas class="chart-canvas" width="360" height="210" data-chart="actual-bars" data-chart-data="<?= e(json_encode($chartActual, JSON_UNESCAPED_SLASHES)) ?>"></canvas></article>
    <article class="live-card diagram-card"><div class="live-card-head"><div><span class="eyebrow">FORECAST</span><h2>Forecast Demand</h2></div></div><canvas class="chart-canvas" width="360" height="210" data-chart="forecast-bars" data-chart-data="<?= e(json_encode($chartForecast, JSON_UNESCAPED_SLASHES)) ?>"></canvas></article>
    <article class="live-card ai-pulse-card"><div class="live-card-head"><div><span class="eyebrow">AI SUMMARY</span><h2>AI Summary for Managers/Executives</h2></div></div><?php foreach ($summaries as $summary): ?><p><b><?= e($summary['title']) ?></b><br><?= e($summary['summary']) ?></p><?php endforeach; ?><?php if (!$summaries): ?><p>No manager summary yet. Run a forecast to create planning signals.</p><?php endif; ?></article>
</section>

<section class="form-card">
    <div class="card-heading"><div><span class="eyebrow">SIMULATION</span><h2>What-if Stock Simulation</h2></div></div>
    <form method="post" class="form-grid three" data-block-on-submit="Calculating simulation">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Scenario Name<input name="scenario_name" value="What-if replenishment test"></label>
        <label>Material<select name="material_id"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['material_code']) ?> - <?= e($material['material_name']) ?></option><?php endforeach; ?></select></label>
        <label>Location<select name="location_id"><option value="">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['name']) ?></option><?php endforeach; ?></select></label>
        <label>Starting Stock<input type="number" step="0.01" name="starting_stock" value="120"></label>
        <label>Daily Demand<input type="number" step="0.01" name="daily_demand" value="9"></label>
        <label>Lead Time Days<input type="number" name="lead_time_days" value="7"></label>
        <label>Incoming Quantity<input type="number" step="0.01" name="incoming_qty" value="30"></label>
        <label>Horizon Days<input type="number" name="horizon_days" value="30"></label>
        <div class="form-actions"><button class="btn primary" type="submit">Run Simulation</button></div>
    </form>
    <?php if ($whatIfResult): ?><div class="status-strip" style="margin-top:16px"><a><span>Result</span><b><?= e($whatIfResult['message']) ?></b><small>Ending stock <?= money_or_qty($whatIfResult['ending_stock']) ?></small></a></div><?php endif; ?>
</section>

<section class="content-card"><div class="card-heading"><div><span class="eyebrow">ACCURACY</span><h2>Forecast vs Actual Consumption Report</h2></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Material</th><th>Period</th><th>Forecast</th><th>Actual</th><th>APE</th><th>Accuracy</th></tr></thead><tbody><?php foreach ($actualRows as $row): ?><tr><td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td><td><?= e($row['period_start']) ?> to <?= e($row['period_end']) ?></td><td><?= money_or_qty($row['forecast_qty']) ?> <?= e($row['unit_name']) ?></td><td><?= money_or_qty($row['actual_qty']) ?></td><td><?= money_or_qty($row['absolute_percentage_error']) ?>%</td><td><b><?= money_or_qty($row['accuracy_score']) ?>%</b></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">MODELS</span><h2>ARIMA Model Comparison and Confidence Scoring</h2></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Run</th><th>Material</th><th>Model</th><th>Forecast</th><th>MAE</th><th>RMSE</th><th>MAPE</th><th>Confidence</th><th>Selected</th></tr></thead><tbody><?php foreach ($modelRows as $row): ?><tr><td><?= e($row['run_no'] ?? 'Local') ?></td><td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td><td><?= e($row['model_name']) ?></td><td><?= money_or_qty($row['forecast_qty']) ?></td><td><?= money_or_qty($row['mae']) ?></td><td><?= money_or_qty($row['rmse']) ?></td><td><?= money_or_qty($row['mape']) ?>%</td><td><?= money_or_qty($row['confidence_score']) ?>%</td><td><?= (int) $row['is_selected'] === 1 ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">SIGNALS</span><h2>Demand Anomaly Detection and Reorder Suggestions</h2></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Signal</th><th>Material</th><th>Date / Confidence</th><th>Actual / Suggested</th><th>Explanation</th></tr></thead><tbody><?php foreach ($anomalies as $row): ?><tr><td><span class="workflow-badge risk-<?= e($row['severity']) ?>">Anomaly</span></td><td><?= e($row['material_code']) ?><small><?= e($row['material_name']) ?></small></td><td><?= e($row['anomaly_date']) ?></td><td><?= money_or_qty($row['actual_qty']) ?> vs <?= money_or_qty($row['baseline_qty']) ?></td><td><?= e($row['explanation']) ?></td></tr><?php endforeach; ?><?php foreach ($suggestions as $row): ?><tr><td><span class="workflow-badge">Reorder</span></td><td><?= e($row['material_code']) ?><small><?= e($row['material_name']) ?></small></td><td><?= e($row['confidence']) ?></td><td>ROP <?= money_or_qty($row['suggested_reorder_point']) ?> / Safety <?= money_or_qty($row['suggested_safety_stock']) ?></td><td>Based on <?= money_or_qty($row['average_daily_demand']) ?> <?= e($row['unit_name']) ?> daily demand and <?= (int) $row['lead_time_days'] ?> lead days.</td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
