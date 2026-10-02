<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const APP_NAME = 'Metriccon Inventory Forecasting';
const APP_SHORT_NAME = 'Inventory Hub';
const APP_CSRF_SESSION_KEY = 'inventory_forecasting_csrf';

$appLocalConfig = [];
$appLocalConfigPath = __DIR__ . '/config.local.php';
if (is_file($appLocalConfigPath)) {
    $loadedConfig = require $appLocalConfigPath;
    if (is_array($loadedConfig)) {
        $appLocalConfig = $loadedConfig;
    }
}

$appConfigValue = static function (string $key, string $environmentKey, string $default) use ($appLocalConfig): string {
    $environmentValue = getenv($environmentKey);
    if ($environmentValue !== false && trim($environmentValue) !== '') {
        return trim($environmentValue);
    }

    $localValue = $appLocalConfig[$key] ?? null;
    return is_string($localValue) && trim($localValue) !== '' ? trim($localValue) : $default;
};

$appBaseUrl = '/' . trim($appConfigValue('base_url', 'APP_BASE_URL', '/'), '/') . '/';
if ($appBaseUrl === '//') {
    $appBaseUrl = '/';
}

define('APP_BASE_URL', $appBaseUrl);
define('APP_DB_HOST', $appConfigValue('db_host', 'APP_DB_HOST', 'localhost'));
define('APP_DB_NAME', $appConfigValue('db_name', 'APP_DB_NAME', 'inventory_forecasting_system'));
define('APP_DB_USER', $appConfigValue('db_user', 'APP_DB_USER', 'root'));
define('APP_DB_PASS', $appConfigValue('db_pass', 'APP_DB_PASS', ''));
define('APP_DB_CHARSET', $appConfigValue('db_charset', 'APP_DB_CHARSET', 'utf8mb4'));

unset($loadedConfig, $appBaseUrl, $appConfigValue, $appLocalConfig, $appLocalConfigPath);
