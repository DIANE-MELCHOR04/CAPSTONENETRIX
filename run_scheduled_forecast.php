<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/forecasting.php';
require_once __DIR__ . '/lib/settings.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Scheduled forecast runner is CLI-only.');
}

if (setting_value($pdo, 'forecast_daily_enabled', '1') !== '1') {
    echo "Scheduled forecast is disabled." . PHP_EOL;
    exit(0);
}

$settings = forecast_settings($pdo);
$userId = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
$runId = create_forecast_run($pdo, $userId, (int) $settings['horizon_days'], (int) $settings['history_days'], 'scheduled');
$result = execute_forecast_run($pdo, $runId);

echo 'Scheduled forecast completed: run #' . $runId . ', items ' . count($result['items']) . PHP_EOL;
