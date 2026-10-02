<?php
declare(strict_types=1);

function dashboard_chart_count(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function dashboard_chart_number(PDO $pdo, string $sql): float
{
    return (float) $pdo->query($sql)->fetchColumn();
}

function dashboard_latest_forecast_run_id(PDO $pdo): ?int
{
    $value = $pdo->query('SELECT id FROM forecast_runs ORDER BY started_at DESC, id DESC LIMIT 1')->fetchColumn();

    return $value === false ? null : (int) $value;
}

function dashboard_risk_distribution(PDO $pdo, ?int $runId): array
{
    $distribution = ['critical' => 0, 'high' => 0, 'medium' => 0, 'stable' => 0];
    if ($runId === null) {
        return $distribution;
    }

    $stmt = $pdo->prepare('SELECT risk_level, COUNT(*) AS total FROM forecast_items WHERE forecast_run_id = ? GROUP BY risk_level');
    $stmt->execute([$runId]);
    foreach ($stmt->fetchAll() as $row) {
        $risk = (string) $row['risk_level'];
        if (array_key_exists($risk, $distribution)) {
            $distribution[$risk] = (int) $row['total'];
        }
    }

    return $distribution;
}

function dashboard_demand_trend(PDO $pdo, int $days = 14): array
{
    $days = max(7, min(60, $days));
    $stmt = $pdo->prepare(
        "SELECT DATE(created_at) AS movement_date, COALESCE(SUM(quantity_out), 0) AS total_out
         FROM inventory_movements
         WHERE movement_type IN ('release', 'stock_out')
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(created_at)"
    );
    $stmt->execute([$days - 1]);
    $indexed = [];
    foreach ($stmt->fetchAll() as $row) {
        $indexed[(string) $row['movement_date']] = (float) $row['total_out'];
    }

    $trend = [];
    $start = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');
    for ($i = 0; $i < $days; $i++) {
        $date = $start->modify("+{$i} days")->format('Y-m-d');
        $trend[] = [
            'label' => date('M d', strtotime($date)),
            'value' => round($indexed[$date] ?? 0, 2),
        ];
    }

    return $trend;
}

function dashboard_movement_flow(PDO $pdo): array
{
    return $pdo->query(
        "SELECT movement_type AS label,
                COALESCE(SUM(quantity_in), 0) AS qty_in,
                COALESCE(SUM(quantity_out), 0) AS qty_out,
                ROUND(COALESCE(SUM(quantity_in + quantity_out), 0), 2) AS value,
                COUNT(*) AS movement_count
         FROM inventory_movements
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY movement_type
         ORDER BY value DESC
         LIMIT 8"
    )->fetchAll();
}

function dashboard_location_stock(PDO $pdo): array
{
    return $pdo->query(
        "SELECT l.name AS label,
                ROUND(COALESCE(SUM(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity), 0), 2) AS value
         FROM locations l
         LEFT JOIN inventory_balances b ON b.location_id = l.id
         WHERE l.is_active = 1
         GROUP BY l.id, l.name
         ORDER BY value DESC, l.name
         LIMIT 8"
    )->fetchAll();
}

function dashboard_procurement_flow(PDO $pdo): array
{
    return [
        ['label' => 'Material Requests', 'value' => dashboard_chart_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE status IN ('submitted','under_review','approved','for_procurement')")],
        ['label' => 'Purchase Requests', 'value' => dashboard_chart_count($pdo, "SELECT COUNT(*) FROM purchase_requests WHERE status IN ('draft','submitted','approved','ordered','partially_ordered')")],
        ['label' => 'Purchase Orders', 'value' => dashboard_chart_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('open','partially_received')")],
        ['label' => 'Deliveries', 'value' => dashboard_chart_count($pdo, "SELECT COUNT(*) FROM supplier_deliveries WHERE received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")],
    ];
}

function dashboard_ai_focus(PDO $pdo): array
{
    $urgency = ['critical' => 0, 'high' => 0, 'medium' => 0, 'stable' => 0];
    foreach ($pdo->query('SELECT urgency, COUNT(*) AS total FROM ai_recommendations GROUP BY urgency')->fetchAll() as $row) {
        $key = (string) $row['urgency'];
        if (array_key_exists($key, $urgency)) {
            $urgency[$key] = (int) $row['total'];
        }
    }

    $status = ['open' => 0, 'acknowledged' => 0, 'converted' => 0, 'dismissed' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM ai_recommendations GROUP BY status')->fetchAll() as $row) {
        $key = (string) $row['status'];
        if (array_key_exists($key, $status)) {
            $status[$key] = (int) $row['total'];
        }
    }

    $latest = $pdo->query(
        "SELECT ar.title, ar.urgency, ar.status, m.material_code
         FROM ai_recommendations ar
         JOIN materials m ON m.id = ar.material_id
         ORDER BY ar.created_at DESC, ar.id DESC
         LIMIT 4"
    )->fetchAll();

    return [
        'urgency' => $urgency,
        'status' => $status,
        'latest' => $latest,
    ];
}

function dashboard_live_snapshot(PDO $pdo): array
{
    $latestRunId = dashboard_latest_forecast_run_id($pdo);
    $openAiActions = dashboard_chart_count($pdo, "SELECT COUNT(*) FROM ai_recommendations WHERE status = 'open'");

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'metrics' => [
            'active_materials' => dashboard_chart_count($pdo, "SELECT COUNT(*) FROM materials WHERE is_active = 1 AND approval_status = 'approved'"),
            'active_locations' => dashboard_chart_count($pdo, 'SELECT COUNT(*) FROM locations WHERE is_active = 1'),
            'low_stock' => dashboard_chart_count($pdo, 'SELECT COUNT(*) FROM inventory_balances b JOIN materials m ON m.id = b.material_id WHERE (b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity) <= m.reorder_point'),
            'pending_requests' => dashboard_chart_count($pdo, "SELECT COUNT(*) FROM material_requests WHERE status IN ('submitted','under_review','partially_reserved','for_procurement')"),
            'open_ai_actions' => $openAiActions,
            'forecast_runs' => dashboard_chart_count($pdo, 'SELECT COUNT(*) FROM forecast_runs'),
            'available_stock' => round(dashboard_chart_number($pdo, 'SELECT COALESCE(SUM(quantity_on_hand - reserved_quantity - damaged_quantity), 0) FROM inventory_balances'), 2),
            'in_transit_stock' => round(dashboard_chart_number($pdo, 'SELECT COALESCE(SUM(in_transit_quantity), 0) FROM inventory_balances'), 2),
        ],
        'risk_distribution' => dashboard_risk_distribution($pdo, $latestRunId),
        'demand_trend' => dashboard_demand_trend($pdo, 14),
        'movement_flow' => dashboard_movement_flow($pdo),
        'location_stock' => dashboard_location_stock($pdo),
        'procurement_flow' => dashboard_procurement_flow($pdo),
        'ai_focus' => dashboard_ai_focus($pdo),
    ];
}
