<?php
$workspaceHasData = count(array_filter($profile['metrics'], static fn(array $metric): bool => $metric['value'] !== null)) > 0;
?>
<section class="ops-workspace-intro">
    <div class="ops-workspace-copy">
        <span class="eyebrow"><?= e($profile['eyebrow']) ?></span>
        <h2><?= e($profile['title']) ?></h2>
        <p><?= e($profile['description']) ?></p>
    </div>
    <?php if ($profile['actions']): ?>
        <div class="ops-workspace-actions" aria-label="Workspace actions">
            <?php foreach ($profile['actions'] as $action): ?>
                <a class="btn <?= e($action['class']) ?>" href="<?= e($action['url']) ?>"><?= e($action['label']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!$workspaceHasData): ?>
    <?= ui_empty_state('No live data available', 'Operational information will appear here when records are available.') ?>
<?php else: ?>
    <section class="ops-metric-grid" aria-label="Workspace metrics">
        <?php foreach ($profile['metrics'] as $metric): ?>
            <a class="ops-metric-card tone-<?= e($metric['tone']) ?>" href="<?= e($metric['url']) ?>">
                <span><?= e($metric['label']) ?></span>
                <strong data-live-value="<?= e($metric['key']) ?>"><?= $metric['value'] === null ? 'Unavailable' : number_format((float) $metric['value']) . e($metric['suffix'] ?? '') ?></strong>
                <small><?= e($metric['detail']) ?></small>
            </a>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($profile['show_site_dashboards'] && $siteDashboards): ?>
    <section class="ops-section">
        <div class="ops-section-heading">
            <div><span class="eyebrow">Locations</span><h2>Site-Specific Dashboards</h2></div>
            <a class="btn secondary compact" href="calendar.php">Delivery Calendar</a>
        </div>
        <div class="ops-site-strip">
            <?php foreach ($siteDashboards as $site): ?>
                <a href="requests.php?site_id=<?= (int) $site['id'] ?>">
                    <span><?= e($site['code']) ?></span>
                    <strong><?= e($site['name']) ?></strong>
                    <small><?= number_format((int) $site['open_requests']) ?> open / <?= money_or_qty($site['available_stock']) ?> available</small>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($profile['ai_briefing'] || $profile['charts']): ?>
<section class="live-dashboard ops-live-dashboard" data-live-dashboard data-live-url="dashboard_live_data.php" data-refresh-ms="45000">
    <?php if ($profile['ai_briefing']): ?>
        <article class="live-card ai-pulse-card ops-ai-briefing">
            <div class="live-card-head">
                <div><span class="eyebrow">AI-assisted priorities</span><h2>AI Demand Pulse</h2></div>
                <span class="signal-led">Live</span>
            </div>
            <div class="pulse-number"><strong data-live-value="open_ai_actions"><?= number_format((int) ($profile['ai_briefing']['count'] ?? 0)) ?></strong><span>open actions</span></div>
            <p>Forecast signals, stock risk, and procurement queues ranked for operational review.</p>
            <div class="ai-mini-feed">
                <?php foreach ((array) ($profile['ai_briefing']['latest'] ?? []) as $note): ?>
                    <span><?= e($note['material_code'] ?? 'Material') ?> / <?= e($note['urgency'] ?? 'review') ?></span>
                <?php endforeach; ?>
            </div>
            <a class="ops-text-link" href="ai_recommendations.php">Review recommendations</a>
        </article>
    <?php endif; ?>

    <?php foreach ($profile['charts'] as $chart): ?>
        <?php $chartData = (array) ($liveSnapshot[$chart['key']] ?? []); ?>
        <article class="live-card diagram-card <?= e($chart['class'] ?? '') ?>">
            <div class="live-card-head">
                <div><span class="eyebrow"><?= e($chart['eyebrow']) ?></span><h2><?= e($chart['title']) ?></h2></div>
                <a class="ops-chart-link" href="<?= e($chart['url']) ?>">View details</a>
            </div>
            <?php if ($chartData): ?>
                <canvas class="chart-canvas" width="<?= (int) $chart['width'] ?>" height="<?= (int) $chart['height'] ?>" data-chart="<?= e($chart['type']) ?>" data-drilldown-url="<?= e($chart['url']) ?>" data-chart-data="<?= dashboard_chart_attr($chartData) ?>" aria-label="<?= e($chart['title']) ?> chart"></canvas>
            <?php else: ?>
                <div class="ops-chart-empty">No chart data is available for this period.</div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php foreach ($profile['tasks'] as $taskGroup): ?>
    <?php if (!empty($taskGroup['items'])): ?>
        <section class="ops-section">
            <div class="ops-section-heading"><div><span class="eyebrow">Work queue</span><h2><?= e($taskGroup['title']) ?></h2></div></div>
            <div class="ops-queue-grid">
                <?php foreach ($taskGroup['items'] as $task): ?>
                    <a href="<?= e($task['url']) ?>"><span><?= e($task['label']) ?></span><strong><?= number_format((int) $task['count']) ?></strong><small><?= e($task['detail'] ?? 'Open records') ?></small></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($forecastSummary): ?>
    <section class="ops-section">
        <div class="ops-section-heading">
            <div><span class="eyebrow">Forecast signal</span><h2>Demand Risk Snapshot</h2></div>
            <a class="btn primary compact" href="forecasting.php">Run Forecast</a>
        </div>
        <div class="ops-queue-grid">
            <a href="forecasting.php"><span>Latest run</span><strong><?= $forecastSummary['latest_run'] ? e($forecastSummary['latest_run']['run_no']) : 'None' ?></strong><small><?= $forecastSummary['latest_run'] ? e(date('M d, Y', strtotime($forecastSummary['latest_run']['started_at']))) : 'Start a model run' ?></small></a>
            <a href="reports_forecast.php?risk=critical"><span>Critical risks</span><strong><?= number_format((int) $forecastSummary['critical']) ?></strong><small>Immediate replenishment review</small></a>
            <a href="reports_forecast.php?risk=high"><span>High risks</span><strong><?= number_format((int) $forecastSummary['high']) ?></strong><small>Next planning window</small></a>
            <a href="ai_recommendations.php"><span>Open AI actions</span><strong><?= number_format((int) $forecastSummary['open_recommendations']) ?></strong><small>Recommendation board</small></a>
        </div>
    </section>
<?php endif; ?>

<?php if ($profile['show_recent_activity']): ?>
<section class="ops-section">
    <div class="ops-section-heading">
        <div><span class="eyebrow">Recent ledger</span><h2>Latest Inventory Movements</h2></div>
        <a class="btn secondary compact" href="movements.php">View History</a>
    </div>
    <?php if (!$recentMovements): ?>
        <?= ui_empty_state('No inventory movements yet', 'Posted stock activity will appear in this ledger.') ?>
    <?php else: ?>
        <div class="ops-table-shell"><div class="table-wrap">
            <table class="data modern-table ops-table">
                <thead><tr><th>No.</th><th>Material</th><th>Location</th><th>Type</th><th>In</th><th>Out</th><th>Balance</th></tr></thead>
                <tbody>
                <?php foreach ($recentMovements as $row): ?>
                    <tr>
                        <td><b><?= e($row['movement_no']) ?></b><small><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></small></td>
                        <td><?= e($row['material_code']) ?><small><?= e($row['material_name']) ?></small></td>
                        <td><?= e($row['location_name']) ?></td>
                        <td><?= ui_status_badge((string) $row['movement_type']) ?></td>
                        <td class="qty-positive"><?= money_or_qty($row['quantity_in']) ?> <?= e($row['unit_name']) ?></td>
                        <td class="qty-negative"><?= money_or_qty($row['quantity_out']) ?> <?= e($row['unit_name']) ?></td>
                        <td><b><?= money_or_qty($row['balance_after']) ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>
</section>
<?php endif; ?>
