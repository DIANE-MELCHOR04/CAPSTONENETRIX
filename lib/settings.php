<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function setting_value(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function save_settings_batch(PDO $pdo, array $values): void
{
    $started = !$pdo->inTransaction();
    if ($started) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($values as $key => $value) {
            save_setting($pdo, (string) $key, (string) $value);
        }
        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function settings_defaults(): array
{
    return [
        'company_name' => 'Metriccon Co., Inc.',
        'default_warehouse_id' => '1',
        'critical_stock_threshold_percent' => '25',
        'reorder_alert_days' => '7',
        'report_footer_text' => 'Metriccon Inventory Control Hub',
        'request_prefix' => 'MR',
        'release_prefix' => 'REL',
        'movement_prefix' => 'MOV',
        'purchase_request_prefix' => 'PR',
        'purchase_order_prefix' => 'PO',
        'delivery_prefix' => 'DEL',
        'transfer_prefix' => 'TR',
        'return_prefix' => 'RET',
        'damage_prefix' => 'DMG',
        'adjustment_prefix' => 'ADJ',
        'stock_count_prefix' => 'SC',
        'backup_prefix' => 'BACKUP',
        'material_code_prefix' => 'MAT',
        'site_code_prefix' => 'SITE',
        'location_code_prefix' => 'LOC',
        'unit_code_prefix' => 'UOM',
        'forecast_horizon_days' => '30',
        'forecast_history_days' => '180',
        'forecast_arima_order' => '1,1,1',
        'forecast_python_path' => 'python',
        'forecast_script_path' => 'scripts/forecast_arima.py',
        'gemini_enabled' => '1',
        'gemini_model' => 'gemini-2.0-flash',
    ];
}
