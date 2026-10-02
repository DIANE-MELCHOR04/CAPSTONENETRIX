<?php
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/numbering.php';
require_once __DIR__ . '/lib/audit.php';
require_permission('manage_settings');
require_once __DIR__ . '/config/db.php';

$defaults = settings_defaults();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submitted = [];
    foreach ($defaults as $key => $default) {
        $submitted[$key] = trim((string) ($_POST[$key] ?? $default));
    }
    foreach (code_prefix_setting_keys() as $key) {
        $submitted[$key] = normalize_code_prefix((string) ($submitted[$key] ?? ''));
    }
    $errors = validate_code_prefixes($submitted);

    if ($errors === []) {
        $changes = [];
        foreach (code_prefix_setting_keys() as $key) {
            $oldValue = setting_value($pdo, $key, (string) $defaults[$key]);
            if ($oldValue !== $submitted[$key]) {
                $changes[] = $key . ': ' . $oldValue . ' -> ' . $submitted[$key];
            }
        }

        save_settings_batch($pdo, $submitted);
        if ($changes !== []) {
            write_audit($pdo, (int) current_user()['id'], 'number_prefix.updated', 'app_settings', null, implode('; ', $changes));
        }
        flash('success', 'Settings saved.');
        redirect('settings.php');
    }
}

$values = [];
foreach ($defaults as $key => $default) {
    $values[$key] = setting_value($pdo, $key, $default);
}

$settingGroups = [
    'Company' => ['company_name', 'default_warehouse_id', 'critical_stock_threshold_percent', 'reorder_alert_days', 'report_footer_text'],
    'Documents' => ['request_prefix', 'release_prefix', 'movement_prefix', 'purchase_request_prefix', 'purchase_order_prefix', 'delivery_prefix', 'transfer_prefix', 'return_prefix', 'damage_prefix', 'adjustment_prefix', 'stock_count_prefix', 'backup_prefix'],
    'Numbering' => ['material_code_prefix', 'site_code_prefix', 'location_code_prefix', 'unit_code_prefix'],
    'Forecasting' => ['forecast_horizon_days', 'forecast_history_days', 'forecast_arima_order', 'forecast_python_path', 'forecast_script_path'],
    'AI Recommendations' => ['gemini_enabled', 'gemini_model'],
];

function setting_label(string $key): string
{
    $labels = [
        'forecast_arima_order' => 'Forecast model order',
        'forecast_python_path' => 'Local engine command',
        'forecast_script_path' => 'Local engine script',
        'gemini_enabled' => 'AI assistance',
        'gemini_model' => 'Recommendation model',
        'material_code_prefix' => 'Material code prefix',
        'site_code_prefix' => 'Site code prefix',
        'location_code_prefix' => 'Location code prefix',
        'unit_code_prefix' => 'Unit code prefix',
    ];
    if (isset($labels[$key])) {
        return $labels[$key];
    }

    return str_replace('_', ' ', ucwords($key, '_'));
}

$activePage = 'settings';
$title = 'System Settings';
$eyebrow = 'ADMINISTRATION';
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
            <?php if ($errors): ?><div class="alert danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
            <section class="monitoring-top-card">
                <div>
                    <span class="eyebrow light">CONTROL SETTINGS</span>
                    <h2>Company, stock thresholds, documents, forecast engine, and AI model controls</h2>
                    <p>Configure operational defaults, forecasting windows, local Python execution, and recommendation behavior.</p>
                </div>
            </section>
            <form method="post" data-block-on-submit="Saving settings">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <?php foreach ($settingGroups as $groupTitle => $keys): ?>
                    <section class="form-card">
                        <div class="card-heading"><div><span class="eyebrow"><?= e(strtoupper($groupTitle)) ?></span><h2><?= e($groupTitle) ?></h2></div></div>
                        <div class="form-grid three">
                            <?php foreach ($keys as $key): ?>
                                <label><?= e(setting_label($key)) ?>
                                    <?php if ($key === 'gemini_enabled'): ?>
                                        <select name="<?= e($key) ?>">
                                            <option value="1" <?= $values[$key] === '1' ? 'selected' : '' ?>>Enabled</option>
                                            <option value="0" <?= $values[$key] === '0' ? 'selected' : '' ?>>Rules only</option>
                                        </select>
                                    <?php elseif (in_array($key, ['forecast_horizon_days', 'forecast_history_days', 'critical_stock_threshold_percent', 'reorder_alert_days', 'default_warehouse_id'], true)): ?>
                                        <input type="number" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
                                    <?php elseif (in_array($key, code_prefix_setting_keys(), true)): ?>
                                        <input name="<?= e($key) ?>" value="<?= e($values[$key]) ?>" maxlength="10" pattern="[A-Za-z][A-Za-z0-9]{1,9}" style="text-transform:uppercase" required>
                                    <?php else: ?>
                                        <input name="<?= e($key) ?>" value="<?= e($values[$key]) ?>">
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
                <div class="form-actions" style="margin-top:16px">
                    <button class="btn primary" type="submit" data-confirm="Save these system settings?" data-confirm-title="Save settings">Save Settings</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
