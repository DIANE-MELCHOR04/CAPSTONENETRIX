<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/search_filters.php';

function high_value_registry(): array
{
    return [
        'Global search across requests, materials, suppliers, users, reports, and movements',
        'Table pagination, column sorting, and saved filters',
        'Dashboard drill-downs from charts into filtered records',
        'Request timeline per document: submitted, approved, reserved, released, received',
        'Approval remarks history per line item',
        'Notification preferences per user/role',
        'Escalation rules for overdue approvals, overdue POs, and low stock',
        'Scheduled daily forecast run',
        'Forecast accuracy tracking',
        'Forecast vs actual consumption report',
        'ARIMA model comparison and confidence scoring',
        'Demand anomaly detection',
        'Reorder point auto-suggestion',
        'AI-generated replenishment explanation per material',
        'AI summary for managers/executives',
        'What-if stock simulation',
        'Project/site consumption trend report',
        'Supplier lead-time performance report',
        'Supplier delivery reliability score',
        'Purchase order overdue tracker',
        'Partial delivery variance report',
        'Inventory valuation report',
        'Dead stock / slow-moving materials report',
        'Fast-moving materials report',
        'ABC classification for materials',
        'Critical materials watchlist',
        'Batch/lot tracking',
        'Expiry tracking for materials that need it',
        'Barcode scanning for stock-in, stock-out, releases, and counts',
        'Attachment uploads for requests, POs, damage reports, and returns',
        'Photo evidence for damaged materials',
        'PDF templates for all documents',
        'Printable receiving slip',
        'Printable transfer slip',
        'Printable stock count sheet',
        'Email export or send-to-user function',
        'Excel import for beginning balances and material master',
        'Bulk upload for materials, suppliers, and sites',
        'Bulk stock adjustment import with approval',
        'Data backup restore screen',
        'Auto database backup schedule',
        'Audit log advanced search and export',
        'User activity report',
        'Password change and password policy',
        'Optional two-factor login',
        'Session timeout settings',
        'Fine-grained permissions per role',
        'Department/team assignment',
        'Multi-warehouse permissions',
        'Site-specific dashboards',
        'PWA/offline draft mode for site engineers',
        'Calendar view for expected deliveries and needed dates',
        'Procurement workload board',
        'Kanban-style request board',
        'Stock count reconciliation wizard',
        'Cycle count scheduling',
        'Inventory aging report',
        'Cancel/void workflow with approval',
        'Return-to-supplier workflow',
        'Disposal/write-off workflow',
        'Budget/cost tracking per project',
        'Material price history',
        'Supplier quotation comparison',
        'Low-stock auto-create purchase request draft',
        'System health page for Python, Gemini, database, and backup status',
        'Setup wizard for API keys and forecast settings',
    ];
}

function high_value_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$key];
}

function pagination_state(array $source, int $totalRows, int $defaultPerPage = 15): array
{
    $allowed = [10, 15, 20, 30, 50, 100];
    $perPage = (int) ($source['per_page'] ?? $defaultPerPage);
    if (!in_array($perPage, $allowed, true)) {
        $perPage = $defaultPerPage;
    }
    $perPage = max(1, min(100, $perPage));
    $pages = max(1, (int) ceil(max(0, $totalRows) / $perPage));
    $page = max(1, min($pages, (int) ($source['page'] ?? 1)));

    return [
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
        'total' => max(0, $totalRows),
        'offset' => ($page - 1) * $perPage,
    ];
}

function sort_state(array $source, array $allowedColumns, string $defaultColumn, string $defaultDirection = 'DESC'): array
{
    $column = (string) ($source['sort'] ?? $defaultColumn);
    if (!in_array($column, $allowedColumns, true)) {
        $column = $defaultColumn;
    }

    $direction = strtoupper((string) ($source['dir'] ?? $defaultDirection));
    if (!in_array($direction, ['ASC', 'DESC'], true)) {
        $direction = strtoupper($defaultDirection) === 'ASC' ? 'ASC' : 'DESC';
    }

    return [
        'column' => $column,
        'direction' => $direction,
        'sql' => $column . ' ' . $direction,
    ];
}

function high_value_report_catalog(): array
{
    return [
        ['title' => 'Inventory Report', 'type' => 'Report', 'description' => 'Material stock by warehouse and site.', 'url' => 'reports_inventory.php'],
        ['title' => 'Low Stock Report', 'type' => 'Report', 'description' => 'Materials at or below reorder point.', 'url' => 'reports_low_stock.php'],
        ['title' => 'Forecast Report', 'type' => 'Report', 'description' => 'Forecast risk, demand, and recommended replenishment.', 'url' => 'reports_forecast.php'],
        ['title' => 'Request Report', 'type' => 'Report', 'description' => 'Request approval, reservation, release, and receipt status.', 'url' => 'reports_requests.php'],
        ['title' => 'Movement Report', 'type' => 'Report', 'description' => 'Inventory ledger movement history.', 'url' => 'reports_movements.php'],
        ['title' => 'Procurement Report', 'type' => 'Report', 'description' => 'Purchase requests, orders, supplier deliveries, and variances.', 'url' => 'reports_procurement.php'],
        ['title' => 'Inventory Intelligence', 'type' => 'Advanced', 'description' => 'ABC, valuation, aging, watchlist, lots, and expiry.', 'url' => 'inventory_intelligence.php'],
        ['title' => 'Forecast Intelligence', 'type' => 'Advanced', 'description' => 'Forecast accuracy, model comparison, anomalies, suggestions, and what-if simulation.', 'url' => 'advanced_forecasting.php'],
        ['title' => 'Procurement Intelligence', 'type' => 'Advanced', 'description' => 'Supplier reliability, lead time, overdue orders, quotation comparison, and workload.', 'url' => 'procurement_intelligence.php'],
    ];
}

function global_search_results(PDO $pdo, string $query, int $limit = 12): array
{
    $query = filter_text(['q' => $query], 'q', 100);
    if ($query === '') {
        return [];
    }

    $limit = max(3, min(30, $limit));
    $pattern = filter_like_pattern($query);
    $results = [];

    $sources = [
        [
            'type' => 'Material',
            'sql' => "SELECT material_code AS code, material_name AS title, description AS subtitle, CONCAT('materials.php?q=', material_code) AS url
                      FROM materials
                      WHERE material_code LIKE ? ESCAPE '\\\\' OR material_name LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\'
                      ORDER BY material_name LIMIT {$limit}",
            'params' => [$pattern, $pattern, $pattern],
        ],
        [
            'type' => 'Supplier',
            'sql' => "SELECT company AS title, contact_person AS subtitle, phone AS code, CONCAT('suppliers.php?q=', company) AS url
                      FROM suppliers
                      WHERE company LIKE ? ESCAPE '\\\\' OR contact_person LIKE ? ESCAPE '\\\\' OR phone LIKE ? ESCAPE '\\\\'
                      ORDER BY company LIMIT {$limit}",
            'params' => [$pattern, $pattern, $pattern],
        ],
        [
            'type' => 'User',
            'sql' => "SELECT username AS code, full_name AS title, role AS subtitle, CONCAT('users.php?q=', username) AS url
                      FROM users
                      WHERE username LIKE ? ESCAPE '\\\\' OR full_name LIKE ? ESCAPE '\\\\' OR role LIKE ? ESCAPE '\\\\'
                      ORDER BY full_name LIMIT {$limit}",
            'params' => [$pattern, $pattern, $pattern],
        ],
        [
            'type' => 'Request',
            'sql' => "SELECT request_no AS code, request_no AS title, CONCAT(status, ' / ', priority) AS subtitle, CONCAT('request_view.php?id=', id) AS url
                      FROM material_requests
                      WHERE request_no LIKE ? ESCAPE '\\\\' OR status LIKE ? ESCAPE '\\\\' OR remarks LIKE ? ESCAPE '\\\\'
                      ORDER BY created_at DESC LIMIT {$limit}",
            'params' => [$pattern, $pattern, $pattern],
        ],
        [
            'type' => 'Movement',
            'sql' => "SELECT im.movement_no AS code, m.material_name AS title, CONCAT(im.movement_type, ' / ', l.name) AS subtitle, CONCAT('movements.php?q=', im.movement_no) AS url
                      FROM inventory_movements im
                      JOIN materials m ON m.id = im.material_id
                      JOIN locations l ON l.id = im.location_id
                      WHERE im.movement_no LIKE ? ESCAPE '\\\\' OR m.material_code LIKE ? ESCAPE '\\\\' OR m.material_name LIKE ? ESCAPE '\\\\' OR l.name LIKE ? ESCAPE '\\\\'
                      ORDER BY im.created_at DESC LIMIT {$limit}",
            'params' => [$pattern, $pattern, $pattern, $pattern],
        ],
    ];

    foreach ($sources as $source) {
        $stmt = $pdo->prepare($source['sql']);
        $stmt->execute($source['params']);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = [
                'type' => $source['type'],
                'code' => (string) ($row['code'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'url' => (string) ($row['url'] ?? '#'),
            ];
        }
    }

    foreach (high_value_report_catalog() as $report) {
        $haystack = strtolower($report['title'] . ' ' . $report['type'] . ' ' . $report['description']);
        if (str_contains($haystack, strtolower($query))) {
            $results[] = [
                'type' => $report['type'],
                'code' => 'REPORT',
                'title' => $report['title'],
                'subtitle' => $report['description'],
                'url' => $report['url'],
            ];
        }
    }

    return array_slice($results, 0, $limit * 6);
}

function saved_filters_for_page(PDO $pdo, int $userId, string $pageKey): array
{
    if (!high_value_table_exists($pdo, 'saved_filters')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM saved_filters
         WHERE user_id = ? AND page_key = ?
         ORDER BY is_default DESC, filter_name'
    );
    $stmt->execute([$userId, $pageKey]);

    return $stmt->fetchAll();
}

function save_filter(PDO $pdo, int $userId, string $pageKey, string $name, string $queryString, bool $isDefault = false): void
{
    if (!high_value_table_exists($pdo, 'saved_filters')) {
        return;
    }

    if ($isDefault) {
        $pdo->prepare('UPDATE saved_filters SET is_default = 0 WHERE user_id = ? AND page_key = ?')->execute([$userId, $pageKey]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO saved_filters (user_id, page_key, filter_name, query_string, is_default)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $pageKey, substr($name, 0, 120), $queryString, $isDefault ? 1 : 0]);
}

function record_request_timeline_event(PDO $pdo, int $requestId, string $type, string $title, string $details, ?int $userId): void
{
    if (!high_value_table_exists($pdo, 'request_timeline_events')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO request_timeline_events (request_id, event_type, title, details, created_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$requestId, $type, $title, $details, $userId]);
}

function record_line_approval_remark(PDO $pdo, int $requestItemId, int $userId, string $decision, float $approvedQty, string $remarks): void
{
    if (!high_value_table_exists($pdo, 'line_approval_remarks')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO line_approval_remarks (request_item_id, user_id, decision, approved_qty, remarks)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$requestItemId, $userId, substr($decision, 0, 40), $approvedQty, $remarks]);
}

function request_timeline(PDO $pdo, array $request): array
{
    $events = [];
    if (!empty($request['created_at'])) {
        $events[] = ['type' => 'created', 'title' => 'Request created', 'details' => 'Material request was encoded.', 'created_at' => (string) $request['created_at'], 'user_name' => (string) ($request['full_name'] ?? '')];
    }
    if (!empty($request['submitted_at'])) {
        $events[] = ['type' => 'submitted', 'title' => 'Request submitted', 'details' => 'Submitted for warehouse manager review.', 'created_at' => (string) $request['submitted_at'], 'user_name' => (string) ($request['full_name'] ?? '')];
    }
    if (!empty($request['reviewed_at'])) {
        $events[] = ['type' => 'approved', 'title' => 'Approval decision completed', 'details' => (string) ($request['review_remarks'] ?? 'Request reviewed.'), 'created_at' => (string) $request['reviewed_at'], 'user_name' => 'Manager'];
    }

    $stmt = $pdo->prepare(
        "SELECT rel.release_no, rel.release_date, rel.status, u.full_name
         FROM material_releases rel
         JOIN users u ON u.id = rel.released_by
         WHERE rel.request_id = ?
         ORDER BY rel.release_date"
    );
    $stmt->execute([(int) $request['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $events[] = [
            'type' => 'released',
            'title' => 'Release slip ' . (string) $row['release_no'],
            'details' => 'Release status: ' . str_replace('_', ' ', (string) $row['status']) . '.',
            'created_at' => (string) $row['release_date'],
            'user_name' => (string) $row['full_name'],
        ];
    }

    if (high_value_table_exists($pdo, 'request_timeline_events')) {
        $stmt = $pdo->prepare(
            "SELECT e.*, u.full_name AS user_name
             FROM request_timeline_events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.request_id = ?
             ORDER BY e.created_at"
        );
        $stmt->execute([(int) $request['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $events[] = [
                'type' => (string) $row['event_type'],
                'title' => (string) $row['title'],
                'details' => (string) ($row['details'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'user_name' => (string) ($row['user_name'] ?? ''),
            ];
        }
    }

    usort($events, static fn(array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));
    $deduped = [];
    foreach ($events as $event) {
        $key = $event['type'] . '|' . $event['title'] . '|' . $event['created_at'];
        $deduped[$key] = $event;
    }

    return array_values($deduped);
}

function line_approval_remarks_for_request(PDO $pdo, int $requestId): array
{
    if (!high_value_table_exists($pdo, 'line_approval_remarks')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT lar.*, m.material_code, m.material_name, u.full_name
         FROM line_approval_remarks lar
         JOIN material_request_items i ON i.id = lar.request_item_id
         JOIN materials m ON m.id = i.material_id
         JOIN users u ON u.id = lar.user_id
         WHERE i.request_id = ?
         ORDER BY lar.created_at DESC, lar.id DESC"
    );
    $stmt->execute([$requestId]);

    return $stmt->fetchAll();
}

function notification_in_app_enabled(PDO $pdo, int $userId, string $type): bool
{
    if (!high_value_table_exists($pdo, 'notification_preferences')) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT in_app_enabled
         FROM notification_preferences
         WHERE user_id = ? AND notification_type IN (?, 'all')
         ORDER BY notification_type = ? DESC
         LIMIT 1"
    );
    $stmt->execute([$userId, $type, $type]);
    $value = $stmt->fetchColumn();

    return $value === false ? true : (int) $value === 1;
}

function notification_preferences_for_user(PDO $pdo, int $userId): array
{
    if (!high_value_table_exists($pdo, 'notification_preferences')) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = ? ORDER BY notification_type');
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function save_notification_preferences(PDO $pdo, int $userId, array $types, array $inApp, array $email): void
{
    if (!high_value_table_exists($pdo, 'notification_preferences')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notification_preferences (user_id, notification_type, in_app_enabled, email_enabled)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE in_app_enabled = VALUES(in_app_enabled), email_enabled = VALUES(email_enabled)'
    );
    foreach ($types as $type) {
        $type = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $type)) ?? '', 0, 80);
        if ($type === '') {
            continue;
        }
        $stmt->execute([$userId, $type, !empty($inApp[$type]) ? 1 : 0, !empty($email[$type]) ? 1 : 0]);
    }
}

function forecast_accuracy_summary(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'forecast_accuracy')) {
        return ['rows' => 0, 'average_accuracy' => 0.0, 'average_mape' => 0.0];
    }

    $row = $pdo->query(
        'SELECT COUNT(*) AS rows_count, AVG(accuracy_score) AS avg_accuracy, AVG(absolute_percentage_error) AS avg_mape
         FROM forecast_accuracy'
    )->fetch() ?: [];

    return [
        'rows' => (int) ($row['rows_count'] ?? 0),
        'average_accuracy' => round((float) ($row['avg_accuracy'] ?? 0), 2),
        'average_mape' => round((float) ($row['avg_mape'] ?? 0), 2),
    ];
}

function forecast_vs_actual_rows(PDO $pdo, int $limit = 30): array
{
    if (!high_value_table_exists($pdo, 'forecast_accuracy')) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    return $pdo->query(
        "SELECT fa.*, m.material_code, m.material_name, u.name AS unit_name
         FROM forecast_accuracy fa
         JOIN materials m ON m.id = fa.material_id
         JOIN units u ON u.id = m.unit_id
         ORDER BY fa.period_end DESC, fa.accuracy_score
         LIMIT {$limit}"
    )->fetchAll();
}

function forecast_model_comparison_rows(PDO $pdo, int $limit = 60): array
{
    if (!high_value_table_exists($pdo, 'forecast_model_results')) {
        return [];
    }

    $limit = max(1, min(120, $limit));
    return $pdo->query(
        "SELECT fmr.*, m.material_code, m.material_name, fr.run_no
         FROM forecast_model_results fmr
         JOIN materials m ON m.id = fmr.material_id
         LEFT JOIN forecast_runs fr ON fr.id = fmr.forecast_run_id
         ORDER BY fmr.created_at DESC, fmr.is_selected DESC, fmr.confidence_score DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function demand_anomaly_rows(PDO $pdo, int $limit = 30): array
{
    if (!high_value_table_exists($pdo, 'demand_anomalies')) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    return $pdo->query(
        "SELECT da.*, m.material_code, m.material_name, l.name AS location_name
         FROM demand_anomalies da
         JOIN materials m ON m.id = da.material_id
         LEFT JOIN locations l ON l.id = da.location_id
         ORDER BY FIELD(da.severity, 'critical', 'high', 'medium', 'low'), da.anomaly_date DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function reorder_suggestion_rows(PDO $pdo, int $limit = 40): array
{
    if (!high_value_table_exists($pdo, 'reorder_suggestions')) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    return $pdo->query(
        "SELECT rs.*, m.material_code, m.material_name, m.reorder_point, m.safety_stock, u.name AS unit_name
         FROM reorder_suggestions rs
         JOIN materials m ON m.id = rs.material_id
         JOIN units u ON u.id = m.unit_id
         ORDER BY FIELD(rs.confidence, 'high', 'medium', 'low'), rs.created_at DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function ai_manager_summaries(PDO $pdo, int $limit = 5): array
{
    if (!high_value_table_exists($pdo, 'ai_manager_summaries')) {
        return [];
    }

    $limit = max(1, min(20, $limit));
    return $pdo->query(
        "SELECT s.*, fr.run_no
         FROM ai_manager_summaries s
         LEFT JOIN forecast_runs fr ON fr.id = s.forecast_run_id
         ORDER BY s.created_at DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function what_if_stock_projection(float $startingStock, float $dailyDemand, int $leadTimeDays, float $incomingQty, int $horizonDays): array
{
    $stock = max(0.0, $startingStock);
    $dailyDemand = max(0.0, $dailyDemand);
    $leadTimeDays = max(0, $leadTimeDays);
    $incomingQty = max(0.0, $incomingQty);
    $horizonDays = max(1, min(365, $horizonDays));
    $points = [];
    $stockoutDay = null;

    for ($day = 1; $day <= $horizonDays; $day++) {
        if ($day === $leadTimeDays && $incomingQty > 0) {
            $stock += $incomingQty;
        }
        $stock = max(0.0, $stock - $dailyDemand);
        if ($stockoutDay === null && $stock <= 0 && $dailyDemand > 0) {
            $stockoutDay = $day;
        }
        $points[] = ['day' => $day, 'stock' => round($stock, 2)];
    }

    return [
        'stockout_day' => $stockoutDay,
        'ending_stock' => round($stock, 2),
        'points' => $points,
        'message' => $stockoutDay === null ? 'Stock remains available during the simulated horizon.' : 'Projected stockout on day ' . $stockoutDay . '.',
    ];
}

function abc_classification(array $rows): array
{
    usort($rows, static fn(array $a, array $b): int => ((float) $b['annual_value']) <=> ((float) $a['annual_value']));
    $total = array_sum(array_map(static fn(array $row): float => max(0.0, (float) $row['annual_value']), $rows));
    $running = 0.0;

    foreach ($rows as &$row) {
        $running += max(0.0, (float) $row['annual_value']);
        $share = $total > 0 ? ($running / $total) * 100 : 0.0;
        $row['cumulative_percent'] = round($share, 2);
        if ($share <= 80) {
            $row['abc_class'] = 'A';
        } elseif ($share <= 95) {
            $row['abc_class'] = 'B';
        } else {
            $row['abc_class'] = 'C';
        }
    }
    unset($row);

    return $rows;
}

function inventory_valuation_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT m.id, m.material_code, m.material_name, u.name AS unit_name,
                COALESCE(SUM(b.quantity_on_hand), 0) AS on_hand,
                COALESCE(SUM(b.reserved_quantity), 0) AS reserved_qty,
                COALESCE(SUM(b.damaged_quantity), 0) AS damaged_qty,
                COALESCE(ph.unit_cost, m.standard_cost, 0) AS unit_cost,
                COALESCE(SUM(b.quantity_on_hand), 0) * COALESCE(ph.unit_cost, m.standard_cost, 0) AS total_value
         FROM materials m
         JOIN units u ON u.id = m.unit_id
         LEFT JOIN inventory_balances b ON b.material_id = m.id
         LEFT JOIN material_price_history ph ON ph.id = (
             SELECT ph2.id FROM material_price_history ph2
             WHERE ph2.material_id = m.id
             ORDER BY ph2.effective_date DESC, ph2.id DESC
             LIMIT 1
         )
         WHERE m.is_active = 1 AND m.approval_status = 'approved'
         GROUP BY m.id, m.material_code, m.material_name, u.name, ph.unit_cost, m.standard_cost
         ORDER BY total_value DESC"
    )->fetchAll();
}

function abc_inventory_rows(PDO $pdo): array
{
    $rows = [];
    foreach (inventory_valuation_rows($pdo) as $row) {
        $rows[] = [
            'material_code' => (string) $row['material_code'],
            'material_name' => (string) $row['material_name'],
            'annual_value' => (float) $row['total_value'],
            'unit_name' => (string) $row['unit_name'],
            'on_hand' => (float) $row['on_hand'],
            'unit_cost' => (float) $row['unit_cost'],
        ];
    }

    return abc_classification($rows);
}

function movement_velocity_rows(PDO $pdo, string $mode, int $limit = 20): array
{
    $direction = $mode === 'slow' ? 'ASC' : 'DESC';
    $limit = max(1, min(50, $limit));
    return $pdo->query(
        "SELECT m.material_code, m.material_name, u.name AS unit_name,
                COALESCE(SUM(CASE WHEN im.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN im.quantity_out ELSE 0 END), 0) AS ninety_day_usage,
                MAX(im.created_at) AS last_movement_at
         FROM materials m
         JOIN units u ON u.id = m.unit_id
         LEFT JOIN inventory_movements im ON im.material_id = m.id AND im.movement_type IN ('release','stock_out')
         WHERE m.is_active = 1 AND m.approval_status = 'approved'
         GROUP BY m.id, m.material_code, m.material_name, u.name
         ORDER BY ninety_day_usage {$direction}, last_movement_at ASC
         LIMIT {$limit}"
    )->fetchAll();
}

function inventory_aging_rows(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    return $pdo->query(
        "SELECT m.material_code, m.material_name, l.name AS location_name,
                b.quantity_on_hand,
                DATEDIFF(CURDATE(), COALESCE(MAX(im.created_at), b.updated_at)) AS age_days
         FROM inventory_balances b
         JOIN materials m ON m.id = b.material_id
         JOIN locations l ON l.id = b.location_id
         LEFT JOIN inventory_movements im ON im.material_id = b.material_id AND im.location_id = b.location_id
         WHERE b.quantity_on_hand > 0
         GROUP BY m.material_code, m.material_name, l.name, b.quantity_on_hand, b.updated_at
         ORDER BY age_days DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function watchlist_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'material_watchlist')) {
        return [];
    }

    return $pdo->query(
        "SELECT w.*, m.material_code, m.material_name, u.full_name
         FROM material_watchlist w
         JOIN materials m ON m.id = w.material_id
         LEFT JOIN users u ON u.id = w.created_by
         WHERE w.resolved_at IS NULL
         ORDER BY FIELD(w.severity, 'danger', 'warning', 'info'), w.created_at DESC
         LIMIT 40"
    )->fetchAll();
}

function material_lot_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'material_lots')) {
        return [];
    }

    return $pdo->query(
        "SELECT ml.*, m.material_code, m.material_name, l.name AS location_name
         FROM material_lots ml
         JOIN materials m ON m.id = ml.material_id
         JOIN locations l ON l.id = ml.location_id
         ORDER BY COALESCE(ml.expiry_date, DATE_ADD(CURDATE(), INTERVAL 9999 DAY)), ml.created_at DESC
         LIMIT 60"
    )->fetchAll();
}

function supplier_performance_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'supplier_scorecards')) {
        return [];
    }

    return $pdo->query(
        "SELECT sc.*, s.company
         FROM supplier_scorecards sc
         JOIN suppliers s ON s.id = sc.supplier_id
         ORDER BY sc.reliability_score DESC, s.company
         LIMIT 40"
    )->fetchAll();
}

function purchase_order_overdue_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT po.*, s.company,
                DATEDIFF(CURDATE(), po.expected_delivery) AS overdue_days
         FROM purchase_orders po
         JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.status IN ('open','partially_received') AND po.expected_delivery < CURDATE()
         ORDER BY po.expected_delivery
         LIMIT 50"
    )->fetchAll();
}

function partial_delivery_variance_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT po.po_no, s.company, m.material_code, m.material_name, poi.ordered_qty, poi.received_qty,
                (poi.ordered_qty - poi.received_qty) AS variance_qty
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.id = poi.purchase_order_id
         JOIN suppliers s ON s.id = po.supplier_id
         JOIN materials m ON m.id = poi.material_id
         WHERE poi.ordered_qty > poi.received_qty
         ORDER BY variance_qty DESC
         LIMIT 50"
    )->fetchAll();
}

function procurement_workload_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT 'Purchase Request' AS lane, status, COUNT(*) AS total FROM purchase_requests GROUP BY status
         UNION ALL
         SELECT 'Purchase Order' AS lane, status, COUNT(*) AS total FROM purchase_orders GROUP BY status
         UNION ALL
         SELECT 'Supplier Delivery' AS lane, status, COUNT(*) AS total FROM supplier_deliveries GROUP BY status
         ORDER BY lane, total DESC"
    )->fetchAll();
}

function quotation_comparison_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'supplier_quotations')) {
        return [];
    }

    return $pdo->query(
        "SELECT q.*, m.material_code, m.material_name, s.company
         FROM supplier_quotations q
         JOIN materials m ON m.id = q.material_id
         JOIN suppliers s ON s.id = q.supplier_id
         ORDER BY m.material_code, q.unit_price, q.lead_time_days
         LIMIT 80"
    )->fetchAll();
}

function project_consumption_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT s.name AS site_name, DATE_FORMAT(im.created_at, '%Y-%m') AS period_label,
                ROUND(SUM(im.quantity_out), 2) AS consumed_qty
         FROM inventory_movements im
         JOIN locations l ON l.id = im.location_id
         LEFT JOIN sites s ON s.id = l.site_id
         WHERE im.movement_type IN ('release','stock_out')
           AND im.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY s.name, DATE_FORMAT(im.created_at, '%Y-%m')
         ORDER BY period_label DESC, consumed_qty DESC
         LIMIT 60"
    )->fetchAll();
}

function project_budget_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'project_budgets')) {
        return [];
    }

    return $pdo->query(
        "SELECT pb.*, s.name AS site_name
         FROM project_budgets pb
         JOIN sites s ON s.id = pb.site_id
         ORDER BY pb.period_end DESC, s.name"
    )->fetchAll();
}

function barcode_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'barcode_registry')) {
        return [];
    }

    return $pdo->query(
        "SELECT *
         FROM barcode_registry
         ORDER BY entity_type, label_text
         LIMIT 80"
    )->fetchAll();
}

function backup_schedule_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'backup_schedules')) {
        return [];
    }

    return $pdo->query('SELECT * FROM backup_schedules ORDER BY is_active DESC, run_time')->fetchAll();
}

function system_health_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'system_health_checks')) {
        return [];
    }

    return $pdo->query('SELECT * FROM system_health_checks ORDER BY FIELD(status, "danger", "warning", "ok"), check_key')->fetchAll();
}

function user_activity_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'user_activity_snapshots')) {
        return [];
    }

    return $pdo->query(
        "SELECT uas.*, u.full_name, u.role
         FROM user_activity_snapshots uas
         JOIN users u ON u.id = uas.user_id
         ORDER BY uas.activity_date DESC, uas.action_count DESC
         LIMIT 80"
    )->fetchAll();
}

function cycle_count_schedule_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'cycle_count_schedules')) {
        return [];
    }

    return $pdo->query(
        "SELECT ccs.*, l.name AS location_name, m.material_code, m.material_name, u.full_name
         FROM cycle_count_schedules ccs
         JOIN locations l ON l.id = ccs.location_id
         LEFT JOIN materials m ON m.id = ccs.material_id
         LEFT JOIN users u ON u.id = ccs.assigned_to
         ORDER BY ccs.scheduled_date, ccs.status
         LIMIT 60"
    )->fetchAll();
}

function delivery_calendar_rows(PDO $pdo, ?int $siteId = null, ?int $requestedBy = null): array
{
    $rows = [];
    $poRows = $siteId === null ? $pdo->query(
        "SELECT 'Expected Delivery' AS event_type, po.po_no AS code, s.company AS title, po.expected_delivery AS event_date, po.status, CONCAT('purchase_order_view.php?id=', po.id) AS url
         FROM purchase_orders po
         JOIN suppliers s ON s.id = po.supplier_id
         WHERE po.expected_delivery >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         ORDER BY po.expected_delivery
         LIMIT 50"
    )->fetchAll() : [];
    $requestSql = "SELECT 'Needed Date' AS event_type, request_no AS code, status AS title, date_needed AS event_date, priority AS status, CONCAT('request_view.php?id=', id) AS url
         FROM material_requests
         WHERE date_needed >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)" . ($siteId !== null ? ' AND site_id = ?' : '') . ($requestedBy !== null ? ' AND requested_by = ?' : '') . "
         ORDER BY date_needed
         LIMIT 50";
    $requestStmt = $pdo->prepare($requestSql);
    $requestParams = [];
    if ($siteId !== null) { $requestParams[] = $siteId; }
    if ($requestedBy !== null) { $requestParams[] = $requestedBy; }
    $requestStmt->execute($requestParams);
    $requestRows = $requestStmt->fetchAll();
    $rows = array_merge($poRows, $requestRows);

    if ($siteId === null && high_value_table_exists($pdo, 'cycle_count_schedules')) {
        $cycleRows = $pdo->query(
            "SELECT 'Cycle Count' AS event_type, CONCAT('CC-', id) AS code, status AS title, scheduled_date AS event_date, status, 'stock_counts.php' AS url
             FROM cycle_count_schedules
             WHERE scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
             ORDER BY scheduled_date
             LIMIT 30"
        )->fetchAll();
        $rows = array_merge($rows, $cycleRows);
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['event_date'], (string) $b['event_date']));

    return $rows;
}

function workflow_cancellation_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'workflow_cancellations')) {
        return [];
    }

    return $pdo->query(
        "SELECT wc.*, requester.full_name AS requested_by_name, approver.full_name AS approved_by_name
         FROM workflow_cancellations wc
         JOIN users requester ON requester.id = wc.requested_by
         LEFT JOIN users approver ON approver.id = wc.approved_by
         ORDER BY wc.created_at DESC
         LIMIT 50"
    )->fetchAll();
}

function return_to_supplier_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'return_to_supplier_records')) {
        return [];
    }

    return $pdo->query(
        "SELECT rts.*, s.company, m.material_code, m.material_name, l.name AS location_name, u.full_name
         FROM return_to_supplier_records rts
         JOIN suppliers s ON s.id = rts.supplier_id
         JOIN materials m ON m.id = rts.material_id
         LEFT JOIN locations l ON l.id = rts.location_id
         JOIN users u ON u.id = rts.created_by
         ORDER BY rts.created_at DESC
         LIMIT 50"
    )->fetchAll();
}

function disposal_writeoff_rows(PDO $pdo): array
{
    if (!high_value_table_exists($pdo, 'disposal_writeoffs')) {
        return [];
    }

    return $pdo->query(
        "SELECT dw.*, m.material_code, m.material_name, l.name AS location_name, requester.full_name AS requested_by_name, approver.full_name AS approved_by_name
         FROM disposal_writeoffs dw
         JOIN materials m ON m.id = dw.material_id
         JOIN locations l ON l.id = dw.location_id
         JOIN users requester ON requester.id = dw.requested_by
         LEFT JOIN users approver ON approver.id = dw.approved_by
         ORDER BY dw.created_at DESC
         LIMIT 50"
    )->fetchAll();
}

function password_policy_settings(PDO $pdo): array
{
    return [
        'min_length' => (int) setting_value($pdo, 'password_min_length', '8'),
        'complexity_required' => setting_value($pdo, 'password_require_complexity', '1') === '1',
        'session_timeout_minutes' => (int) setting_value($pdo, 'session_timeout_minutes', '45'),
        'two_factor_optional' => setting_value($pdo, 'two_factor_optional', '1') === '1',
    ];
}

function high_value_summary_counts(PDO $pdo): array
{
    $counts = [];
    foreach (['saved_filters', 'request_timeline_events', 'forecast_accuracy', 'demand_anomalies', 'reorder_suggestions', 'material_lots', 'barcode_registry', 'system_health_checks'] as $table) {
        $counts[$table] = high_value_table_exists($pdo, $table) ? (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() : 0;
    }

    return $counts;
}
