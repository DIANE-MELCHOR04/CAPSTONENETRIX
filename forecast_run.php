<?php
require_once __DIR__ . '/lib/auth.php';
require_any_permission(['view_reports', 'view_forecast_reports']);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';
require_once __DIR__ . '/lib/gemini.php';

set_time_limit(300);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('forecasting.php');
}

verify_csrf();

$horizonDays = max(7, min(365, (int) ($_POST['horizon_days'] ?? 30)));
$historyDays = max(30, min(730, (int) ($_POST['history_days'] ?? 180)));
$user = current_user();

try {
    $runId = create_forecast_run($pdo, (int) ($user['id'] ?? 1), $horizonDays, $historyDays);
    execute_forecast_run($pdo, $runId);
    $items = forecast_items_for_run($pdo, $runId);
    $created = 0;

    foreach ($items as $item) {
        if (!in_array((string) $item['risk_level'], ['critical', 'high', 'medium'], true)) {
            continue;
        }
        if ((float) $item['recommended_order_qty'] <= 0) {
            continue;
        }
        $item['horizon_days'] = $horizonDays;
        create_recommendation_for_forecast_item($pdo, $item, (int) ($user['id'] ?? 1));
        $created++;
        if ($created >= 12) {
            break;
        }
    }

    flash('success', "Forecast run completed with {$created} recommendation actions.");
    redirect('forecast_view.php?id=' . $runId);
} catch (Throwable $exception) {
    flash('danger', 'Forecast run stopped: ' . $exception->getMessage());
    redirect('forecasting.php');
}
