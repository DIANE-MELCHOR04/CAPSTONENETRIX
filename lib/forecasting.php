<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/python_runner.php';
require_once __DIR__ . '/numbering.php';

function forecast_app_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'forecast_arima.py';
    }

    if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
        return $path;
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function parse_arima_order(string $order): array
{
    $parts = array_map('trim', explode(',', $order));
    if (count($parts) !== 3) {
        return [1, 1, 1];
    }

    $parsed = [];
    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part)) {
            return [1, 1, 1];
        }
        $parsed[] = max(0, min(5, (int) $part));
    }

    return $parsed;
}

function forecast_settings(PDO $pdo): array
{
    return [
        'horizon_days' => max(7, min(365, (int) setting_value($pdo, 'forecast_horizon_days', '30'))),
        'history_days' => max(30, min(730, (int) setting_value($pdo, 'forecast_history_days', '180'))),
        'arima_order' => parse_arima_order(setting_value($pdo, 'forecast_arima_order', '1,1,1')),
        'python_path' => setting_value($pdo, 'forecast_python_path', 'python'),
        'script_path' => forecast_app_path(setting_value($pdo, 'forecast_script_path', 'scripts/forecast_arima.py')),
    ];
}

function next_forecast_run_no(PDO $pdo): string
{
    return next_internal_document_no($pdo, 'forecast_run');
}

function create_forecast_run(PDO $pdo, int $userId, int $horizonDays, int $historyDays, string $runType = 'manual'): int
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    $runType = in_array($runType, ['manual', 'scheduled', 'test'], true) ? $runType : 'manual';
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO forecast_runs (run_no, run_type, horizon_days, history_days, status, started_by, engine)
             VALUES (?, ?, ?, ?, 'running', ?, 'statsmodels_arima')"
        );
        $stmt->execute([
            next_forecast_run_no($pdo),
            $runType,
            max(7, min(365, $horizonDays)),
            max(30, min(730, $historyDays)),
            $userId,
        ]);
        $runId = (int) $pdo->lastInsertId();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $runId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function forecast_run_by_id(PDO $pdo, int $runId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT fr.*, u.full_name AS started_by_name
         FROM forecast_runs fr
         JOIN users u ON u.id = fr.started_by
         WHERE fr.id = ?"
    );
    $stmt->execute([$runId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function latest_forecast_run(PDO $pdo): ?array
{
    $row = $pdo->query(
        "SELECT fr.*, u.full_name AS started_by_name
         FROM forecast_runs fr
         JOIN users u ON u.id = fr.started_by
         ORDER BY fr.started_at DESC, fr.id DESC
         LIMIT 1"
    )->fetch();

    return $row ?: null;
}

function forecast_active_materials(PDO $pdo): array
{
    return $pdo->query(
        "SELECT m.id, m.material_code, m.material_name, m.reorder_point, m.safety_stock, m.lead_time_days,
                u.name AS unit_name,
                s.company AS supplier_name,
                COALESCE(SUM(b.quantity_on_hand), 0) AS quantity_on_hand,
                COALESCE(SUM(b.reserved_quantity), 0) AS reserved_quantity,
                COALESCE(SUM(b.damaged_quantity), 0) AS damaged_quantity,
                COALESCE(SUM(b.in_transit_quantity), 0) AS in_transit_quantity
         FROM materials m
         JOIN units u ON u.id = m.unit_id
         LEFT JOIN suppliers s ON s.id = m.preferred_supplier_id
         LEFT JOIN inventory_balances b ON b.material_id = m.id
         WHERE m.is_active = 1 AND m.approval_status = 'approved'
         GROUP BY m.id, m.material_code, m.material_name, m.reorder_point, m.safety_stock,
                  m.lead_time_days, u.name, s.company
         ORDER BY m.material_name"
    )->fetchAll();
}

function forecast_demand_history(PDO $pdo, int $materialId, ?int $locationId, int $historyDays): array
{
    $params = [$materialId, max(1, $historyDays)];
    $locationSql = '';
    if ($locationId !== null) {
        $locationSql = ' AND im.location_id = ?';
        $params[] = $locationId;
    }

    $stmt = $pdo->prepare(
        "SELECT DATE(im.created_at) AS date, COALESCE(SUM(im.quantity_out), 0) AS quantity
         FROM inventory_movements im
         WHERE im.material_id = ?
           AND im.movement_type IN ('release', 'stock_out')
           AND im.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           {$locationSql}
         GROUP BY DATE(im.created_at)
         ORDER BY DATE(im.created_at)"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($rows) {
        return array_map(static fn(array $row): array => [
            'date' => (string) $row['date'],
            'quantity' => round((float) $row['quantity'], 2),
        ], $rows);
    }

    return [[
        'date' => date('Y-m-d'),
        'quantity' => 0.0,
    ]];
}

function local_forecast_fallback(array $history, int $horizonDays): array
{
    $lastRows = array_slice($history, -14);
    $quantities = array_map(static fn(array $row): float => max(0.0, (float) $row['quantity']), $lastRows);
    $mean = $quantities ? array_sum($quantities) / count($quantities) : 0.0;
    $lastDate = new DateTimeImmutable((string) ($history[array_key_last($history)]['date'] ?? date('Y-m-d')));

    $forecast = [];
    for ($i = 1; $i <= $horizonDays; $i++) {
        $forecast[] = [
            'date' => $lastDate->modify("+{$i} days")->format('Y-m-d'),
            'quantity' => round($mean, 2),
        ];
    }

    return [
        'ok' => true,
        'engine' => 'arima',
        'method' => 'moving_average',
        'confidence' => 'low',
        'forecast' => $forecast,
        'metrics' => [
            'history_days' => count($history),
            'non_zero_days' => count(array_filter($quantities, static fn(float $value): bool => $value > 0)),
            'mean_daily' => round($mean, 2),
            'last_actual_date' => $lastDate->format('Y-m-d'),
        ],
        'warnings' => ['Local moving-average fallback was used.'],
    ];
}

function projected_stockout_date(float $availableStock, float $averageDailyDemand): ?string
{
    if ($availableStock <= 0 && $averageDailyDemand > 0) {
        return date('Y-m-d');
    }

    if ($averageDailyDemand <= 0) {
        return null;
    }

    $days = max(0, (int) floor($availableStock / $averageDailyDemand));
    return (new DateTimeImmutable())->modify("+{$days} days")->format('Y-m-d');
}

function forecast_risk_level(float $availableStock, float $reorderPoint, ?string $stockoutDate, float $recommendedOrderQty): string
{
    if ($availableStock <= $reorderPoint) {
        return 'critical';
    }

    if ($stockoutDate !== null) {
        $today = new DateTimeImmutable('today');
        $stockout = new DateTimeImmutable($stockoutDate);
        $days = (int) $today->diff($stockout)->format('%r%a');
        if ($days <= 7) {
            return 'critical';
        }
        if ($days <= 14) {
            return 'high';
        }
    }

    if ($recommendedOrderQty > 0) {
        return 'medium';
    }

    return 'stable';
}

function forecast_method_label(string $method): string
{
    return match ($method) {
        'statsmodels_arima' => 'Local demand model',
        'moving_average' => 'Moving average',
        default => 'Demand model',
    };
}

function execute_forecast_run(PDO $pdo, int $runId): array
{
    $run = forecast_run_by_id($pdo, $runId);
    if (!$run) {
        throw new RuntimeException('Forecast run not found.');
    }

    $settings = forecast_settings($pdo);
    $horizonDays = (int) $run['horizon_days'];
    $historyDays = (int) $run['history_days'];
    $materials = forecast_active_materials($pdo);
    $items = [];
    $warnings = [];

    try {
        foreach ($materials as $material) {
            $history = forecast_demand_history($pdo, (int) $material['id'], null, $historyDays);
            $payload = [
                'material_id' => (int) $material['id'],
                'material_code' => (string) $material['material_code'],
                'history' => $history,
                'horizon_days' => $horizonDays,
                'order' => $settings['arima_order'],
            ];

            try {
                $engineResult = run_python_json($settings['python_path'], $settings['script_path'], $payload, 45);
            } catch (Throwable $error) {
                $engineResult = local_forecast_fallback($history, $horizonDays);
                $engineResult['warnings'][] = 'Python engine message: ' . $error->getMessage();
            }

            $forecastRows = $engineResult['forecast'] ?? [];
            $forecastQty = round(array_sum(array_map(static fn(array $row): float => (float) ($row['quantity'] ?? 0), $forecastRows)), 2);
            $averageDailyDemand = $horizonDays > 0 ? round($forecastQty / $horizonDays, 2) : 0.0;
            $availableStock = max(0.0, round(
                (float) $material['quantity_on_hand'] - (float) $material['reserved_quantity'] - (float) $material['damaged_quantity'],
                2
            ));
            $projectedStockoutDate = projected_stockout_date($availableStock, $averageDailyDemand);
            $recommendedOrderQty = max(0.0, round(
                (float) $material['reorder_point'] + (float) $material['safety_stock'] + $forecastQty - $availableStock,
                2
            ));
            $riskLevel = forecast_risk_level($availableStock, (float) $material['reorder_point'], $projectedStockoutDate, $recommendedOrderQty);
            $materialWarnings = $engineResult['warnings'] ?? [];
            foreach ($materialWarnings as $warning) {
                $warnings[] = (string) $material['material_code'] . ': ' . (string) $warning;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO forecast_items
                    (forecast_run_id, material_id, location_id, available_stock, reorder_point, safety_stock,
                     forecast_qty, average_daily_demand, projected_stockout_date, recommended_order_qty,
                     confidence, risk_level, method, metrics_json, forecast_json)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $runId,
                (int) $material['id'],
                $availableStock,
                (float) $material['reorder_point'],
                (float) $material['safety_stock'],
                $forecastQty,
                $averageDailyDemand,
                $projectedStockoutDate,
                $recommendedOrderQty,
                (string) ($engineResult['confidence'] ?? 'low'),
                $riskLevel,
                (string) ($engineResult['method'] ?? 'moving_average'),
                json_encode($engineResult['metrics'] ?? [], JSON_THROW_ON_ERROR),
                json_encode($forecastRows, JSON_THROW_ON_ERROR),
            ]);

            $items[] = [
                'id' => (int) $pdo->lastInsertId(),
                'material_id' => (int) $material['id'],
                'material_code' => (string) $material['material_code'],
                'material_name' => (string) $material['material_name'],
                'unit_name' => (string) $material['unit_name'],
                'supplier_name' => $material['supplier_name'] !== null ? (string) $material['supplier_name'] : '',
                'available_stock' => $availableStock,
                'reorder_point' => (float) $material['reorder_point'],
                'safety_stock' => (float) $material['safety_stock'],
                'forecast_qty' => $forecastQty,
                'average_daily_demand' => $averageDailyDemand,
                'projected_stockout_date' => $projectedStockoutDate,
                'recommended_order_qty' => $recommendedOrderQty,
                'confidence' => (string) ($engineResult['confidence'] ?? 'low'),
                'risk_level' => $riskLevel,
                'method' => (string) ($engineResult['method'] ?? 'moving_average'),
                'metrics_json' => json_encode($engineResult['metrics'] ?? [], JSON_THROW_ON_ERROR),
                'forecast_json' => json_encode($forecastRows, JSON_THROW_ON_ERROR),
            ];
        }

        $summary = [
            'items' => count($items),
            'critical' => count(array_filter($items, static fn(array $item): bool => $item['risk_level'] === 'critical')),
            'high' => count(array_filter($items, static fn(array $item): bool => $item['risk_level'] === 'high')),
            'medium' => count(array_filter($items, static fn(array $item): bool => $item['risk_level'] === 'medium')),
            'stable' => count(array_filter($items, static fn(array $item): bool => $item['risk_level'] === 'stable')),
            'warnings' => $warnings,
        ];

        $stmt = $pdo->prepare(
            "UPDATE forecast_runs
             SET status = 'completed', completed_at = NOW(), raw_summary_json = ?
             WHERE id = ?"
        );
        $stmt->execute([json_encode($summary, JSON_THROW_ON_ERROR), $runId]);

        return ['run_id' => $runId, 'items' => $items, 'summary' => $summary];
    } catch (Throwable $error) {
        $stmt = $pdo->prepare(
            "UPDATE forecast_runs
             SET status = 'failed', completed_at = NOW(), notes = ?
             WHERE id = ?"
        );
        $stmt->execute([$error->getMessage(), $runId]);
        throw $error;
    }
}

function forecast_items_for_run(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare(
        "SELECT fi.*, m.material_code, m.material_name, m.lead_time_days, u.name AS unit_name, s.company AS supplier_name
         FROM forecast_items fi
         JOIN materials m ON m.id = fi.material_id
         JOIN units u ON u.id = m.unit_id
         LEFT JOIN suppliers s ON s.id = m.preferred_supplier_id
         WHERE fi.forecast_run_id = ?
         ORDER BY FIELD(fi.risk_level, 'critical', 'high', 'medium', 'stable'), fi.recommended_order_qty DESC, m.material_name"
    );
    $stmt->execute([$runId]);

    return $stmt->fetchAll();
}

function forecast_dashboard_summary(PDO $pdo): array
{
    $latest = latest_forecast_run($pdo);
    if (!$latest) {
        return [
            'latest_run' => null,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'stable' => 0,
            'forecast_qty' => 0.0,
            'recommended_qty' => 0.0,
            'open_recommendations' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT
            SUM(risk_level = 'critical') AS critical_count,
            SUM(risk_level = 'high') AS high_count,
            SUM(risk_level = 'medium') AS medium_count,
            SUM(risk_level = 'stable') AS stable_count,
            COALESCE(SUM(forecast_qty), 0) AS forecast_qty,
            COALESCE(SUM(recommended_order_qty), 0) AS recommended_qty
         FROM forecast_items
         WHERE forecast_run_id = ?"
    );
    $stmt->execute([(int) $latest['id']]);
    $row = $stmt->fetch() ?: [];

    $openStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_recommendations WHERE forecast_run_id = ? AND status = 'open'");
    $openStmt->execute([(int) $latest['id']]);

    return [
        'latest_run' => $latest,
        'critical' => (int) ($row['critical_count'] ?? 0),
        'high' => (int) ($row['high_count'] ?? 0),
        'medium' => (int) ($row['medium_count'] ?? 0),
        'stable' => (int) ($row['stable_count'] ?? 0),
        'forecast_qty' => (float) ($row['forecast_qty'] ?? 0),
        'recommended_qty' => (float) ($row['recommended_qty'] ?? 0),
        'open_recommendations' => (int) $openStmt->fetchColumn(),
    ];
}

function rule_based_recommendation(array $forecastItem, array $material): array
{
    $urgency = (string) ($forecastItem['risk_level'] ?? 'medium');
    if (!in_array($urgency, ['critical', 'high', 'medium', 'stable'], true)) {
        $urgency = 'medium';
    }

    $materialName = (string) ($material['material_name'] ?? $material['name'] ?? 'Material');
    $materialCode = (string) ($material['material_code'] ?? $material['code'] ?? '');
    $unit = (string) ($material['unit_name'] ?? $material['unit'] ?? 'unit');
    $recommended = money_or_qty((float) ($forecastItem['recommended_order_qty'] ?? 0));
    $forecastQty = money_or_qty((float) ($forecastItem['forecast_qty'] ?? 0));
    $stockout = $forecastItem['projected_stockout_date'] ?: 'not projected inside the current horizon';

    $title = $urgency === 'stable'
        ? "Monitor {$materialName}"
        : "Replenish {$materialName}";

    return [
        'title' => trim($title),
        'urgency' => $urgency,
        'recommendation' => "Review {$materialCode} and prepare {$recommended} {$unit} for replenishment if the site schedule is still active.",
        'reason' => "Forecast demand is {$forecastQty} {$unit}, projected stockout is {$stockout}, and the current risk level is {$urgency}.",
        'suggested_action' => $urgency === 'stable'
            ? 'Keep monitoring consumption and retain the current reorder settings.'
            : 'Validate open purchase orders, confirm site demand, and create a purchase request for the recommended quantity.',
        'source' => 'rules',
    ];
}

function forecast_item_context(array $item): array
{
    return [
        'material' => [
            'id' => (int) $item['material_id'],
            'code' => (string) $item['material_code'],
            'name' => (string) $item['material_name'],
            'unit' => (string) $item['unit_name'],
            'supplier' => (string) ($item['supplier_name'] ?? ''),
            'lead_time_days' => (int) ($item['lead_time_days'] ?? 0),
        ],
        'inventory' => [
            'available_stock' => (float) $item['available_stock'],
            'reorder_point' => (float) $item['reorder_point'],
            'safety_stock' => (float) $item['safety_stock'],
        ],
        'forecast' => [
            'forecast_item_id' => (int) $item['id'],
            'forecast_qty' => (float) $item['forecast_qty'],
            'average_daily_demand' => (float) $item['average_daily_demand'],
            'projected_stockout_date' => $item['projected_stockout_date'],
            'recommended_order_qty' => (float) $item['recommended_order_qty'],
            'confidence' => (string) $item['confidence'],
            'risk_level' => (string) $item['risk_level'],
            'method' => (string) $item['method'],
        ],
    ];
}

function forecast_recommendations_for_run(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare(
        "SELECT ar.*, m.material_code, m.material_name, u.name AS unit_name
         FROM ai_recommendations ar
         JOIN materials m ON m.id = ar.material_id
         JOIN units u ON u.id = m.unit_id
         WHERE ar.forecast_run_id = ?
         ORDER BY FIELD(ar.urgency, 'critical', 'high', 'medium', 'stable'), ar.created_at DESC, ar.id DESC"
    );
    $stmt->execute([$runId]);

    return $stmt->fetchAll();
}

function latest_ai_recommendations(PDO $pdo, int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    return $pdo->query(
        "SELECT ar.*, fr.run_no, m.material_code, m.material_name, u.name AS unit_name
         FROM ai_recommendations ar
         JOIN forecast_runs fr ON fr.id = ar.forecast_run_id
         JOIN materials m ON m.id = ar.material_id
         JOIN units u ON u.id = m.unit_id
         ORDER BY FIELD(ar.status, 'open', 'acknowledged', 'converted', 'dismissed'),
                  FIELD(ar.urgency, 'critical', 'high', 'medium', 'stable'),
                  ar.created_at DESC, ar.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function update_ai_recommendation_status(PDO $pdo, int $recommendationId, string $status): void
{
    if (!in_array($status, ['open', 'acknowledged', 'converted', 'dismissed'], true)) {
        throw new InvalidArgumentException('Unsupported recommendation status.');
    }

    if ($status === 'converted') {
        throw new InvalidArgumentException('A recommendation can only be converted by creating or linking a Purchase Request.');
    }

    $linkedStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_requests WHERE ai_recommendation_id = ?');
    $linkedStmt->execute([$recommendationId]);
    if ((int) $linkedStmt->fetchColumn() > 0) {
        throw new RuntimeException('This recommendation is linked to a Purchase Request and must remain converted.');
    }

    $stmt = $pdo->prepare('UPDATE ai_recommendations SET status = ?, reviewed_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $recommendationId]);
}
