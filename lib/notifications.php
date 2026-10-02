<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/high_value.php';
require_once __DIR__ . '/auth.php';

function notification_table_has_column(PDO $pdo, string $column): bool
{
    static $columns = [];
    $cacheKey = spl_object_id($pdo);
    if (!isset($columns[$cacheKey])) {
        $columns[$cacheKey] = [];
        foreach ($pdo->query('SHOW COLUMNS FROM notifications')->fetchAll() as $row) {
            $columns[$cacheKey][(string) $row['Field']] = true;
        }
    }

    return isset($columns[$cacheKey][$column]);
}



/**
 * Return the minimum page-level permissions required by a notification link.
 * The notification system uses this as a final safety gate so users are never
 * shown an "Open" action for a page their role cannot access.
 */
function notification_link_permissions(string $page): array
{
    return match ($page) {
        'request_view.php', 'requests.php' => ['view_requests', 'view_own_requests'],
        'release_view.php' => ['release_materials', 'confirm_receipt', 'resolve_receipt_discrepancies', 'view_requests'],
        'releases.php' => ['release_materials', 'confirm_receipt', 'view_requests'],
        'purchase_request_create.php', 'procurement.php' => ['manage_procurement'],
        'purchase_request_view.php', 'purchase_requests.php' => ['manage_procurement', 'approve_procurement'],
        'purchase_order_view.php', 'purchase_orders.php' => ['manage_procurement', 'approve_procurement', 'receive_supplier_deliveries'],
        'supplier_delivery_create.php' => ['receive_supplier_deliveries'],
        'transfer_view.php', 'transfers.php' => ['view_transfers', 'create_transfers', 'create_site_transfers', 'dispatch_transfers', 'receive_transfers', 'approve_transfers'],
        'damage_report_view.php', 'damage_reports.php' => ['manage_damage', 'report_site_damage', 'approve_damage'],
        'stock_count_view.php', 'stock_counts.php' => ['view_stock_counts', 'manage_stock_counts', 'approve_stock_counts'],
        'adjustment_view.php', 'adjustments.php' => ['manage_adjustments', 'approve_adjustments'],
        'return_view.php', 'returns.php' => ['view_returns', 'create_returns', 'post_returns'],
        'reports_low_stock.php' => ['view_reports', 'view_inventory_reports'],
        'ai_recommendations.php' => ['view_reports', 'view_ai_recommendations'],
        'workflow_control.php' => ['manage_workflow_control', 'request_workflow_control', 'post_workflow_control'],
        'stock_out.php' => ['request_stock_out', 'approve_stock_out'],
        'material_view.php' => ['create_materials', 'manage_master_data', 'review_materials'],
        'notifications.php' => ['view_notifications'],
        default => [],
    };
}

function notification_user_context(PDO $pdo, int $userId): ?array
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $userId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare('SELECT id, role, site_id, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['is_active'] !== 1) {
        return $cache[$key] = null;
    }
    return $cache[$key] = $user;
}

function notification_user_can_open_link(PDO $pdo, int $userId, ?string $link): bool
{
    if ($link === null || trim($link) === '') {
        return true;
    }

    $user = notification_user_context($pdo, $userId);
    if (!$user) {
        return false;
    }

    $parts = parse_url($link);
    if ($parts === false) {
        return false;
    }
    $page = basename((string) ($parts['path'] ?? ''));
    if ($page === '') {
        return true;
    }

    $permissions = notification_link_permissions($page);
    if ($permissions) {
        $allowed = false;
        foreach ($permissions as $permission) {
            if (role_can((string) $user['role'], $permission)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return false;
        }
    }

    parse_str((string) ($parts['query'] ?? ''), $query);
    $recordId = isset($query['id']) ? (int) $query['id'] : 0;

    // Site Engineers may only receive record-specific alerts for their own site/work.
    if ((string) $user['role'] === 'site_engineer' && $recordId > 0) {
        if ($page === 'request_view.php') {
            $stmt = $pdo->prepare('SELECT requested_by, site_id FROM material_requests WHERE id = ? LIMIT 1');
            $stmt->execute([$recordId]);
            $record = $stmt->fetch();
            return $record
                && (int) $record['requested_by'] === $userId
                && (int) $record['site_id'] === (int) ($user['site_id'] ?? 0);
        }
        if ($page === 'release_view.php') {
            $stmt = $pdo->prepare('SELECT r.requested_by, r.site_id FROM material_releases rel JOIN material_requests r ON r.id = rel.request_id WHERE rel.id = ? LIMIT 1');
            $stmt->execute([$recordId]);
            $record = $stmt->fetch();
            return $record
                && (int) $record['requested_by'] === $userId
                && (int) $record['site_id'] === (int) ($user['site_id'] ?? 0);
        }
        if ($page === 'damage_report_view.php') {
            $stmt = $pdo->prepare('SELECT dr.reported_by, l.site_id FROM damage_reports dr LEFT JOIN locations l ON l.id = dr.location_id WHERE dr.id = ? LIMIT 1');
            $stmt->execute([$recordId]);
            $record = $stmt->fetch();
            return $record
                && (int) $record['reported_by'] === $userId
                && (int) ($record['site_id'] ?? 0) === (int) ($user['site_id'] ?? 0);
        }
    }

    return true;
}

function purge_inaccessible_notifications(PDO $pdo, int $userId): void
{
    static $purged = [];
    $cacheKey = spl_object_id($pdo) . ':' . $userId;
    if (isset($purged[$cacheKey])) {
        return;
    }
    $purged[$cacheKey] = true;

    $stmt = $pdo->prepare('SELECT id, link_url FROM notifications WHERE user_id = ? AND link_url IS NOT NULL');
    $stmt->execute([$userId]);
    $delete = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
    foreach ($stmt->fetchAll() as $row) {
        if (!notification_user_can_open_link($pdo, $userId, (string) $row['link_url'])) {
            $delete->execute([(int) $row['id'], $userId]);
        }
    }
}

function notification_severity(string $severity): string
{
    $severity = strtolower(trim($severity));

    return in_array($severity, ['info', 'success', 'warning', 'danger'], true) ? $severity : 'info';
}

function notification_type_from_key(?string $notificationKey, string $title): string
{
    $notificationKey = (string) $notificationKey;
    if (str_starts_with($notificationKey, 'system:')) {
        $parts = explode(':', $notificationKey);
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($parts[1] ?? 'all'))) ?: 'all';
        return match ($type) {
            'low_stock_escalation' => 'low_stock',
            'ai_recommendation' => 'ai_recommendations',
            'request_approval' => 'approval_overdue',
            'purchase_order_due' => 'po_overdue',
            default => $type,
        };
    }

    $title = strtolower($title);
    if (str_contains($title, 'low stock')) {
        return 'low_stock';
    }
    if (str_contains($title, 'overdue')) {
        return str_contains($title, 'order') ? 'po_overdue' : 'approval_overdue';
    }
    if (str_contains($title, 'ai')) {
        return 'ai_recommendations';
    }

    return 'all';
}

function notify_user(PDO $pdo, int $userId, string $title, string $message, ?string $link = null, string $severity = 'info'): void
{
    if (!notification_user_can_open_link($pdo, $userId, $link)) {
        return;
    }
    if (!notification_in_app_enabled($pdo, $userId, notification_type_from_key(null, $title))) {
        return;
    }

    if (notification_table_has_column($pdo, 'severity')) {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, link_url, severity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $message, $link, notification_severity($severity)]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $message, $link]);
}

function notify_role(PDO $pdo, string $role, string $title, string $message, ?string $link = null, string $severity = 'info'): void
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ? AND is_active = 1');
    $stmt->execute([$role]);

    foreach ($stmt->fetchAll() as $user) {
        notify_user($pdo, (int) $user['id'], $title, $message, $link, $severity);
    }
}

function notify_user_once(PDO $pdo, int $userId, string $notificationKey, string $title, string $message, ?string $link = null, string $severity = 'info'): bool
{
    $notificationKey = substr(trim($notificationKey), 0, 190);
    if (!notification_user_can_open_link($pdo, $userId, $link)) {
        return false;
    }
    if (!notification_in_app_enabled($pdo, $userId, notification_type_from_key($notificationKey, $title))) {
        return false;
    }

    if ($notificationKey === '') {
        notify_user($pdo, $userId, $title, $message, $link, $severity);
        return true;
    }

    if (notification_table_has_column($pdo, 'notification_key')) {
        $lookup = $pdo->prepare('SELECT id FROM notifications WHERE user_id = ? AND notification_key = ? LIMIT 1');
        $lookup->execute([$userId, $notificationKey]);
        $existingId = $lookup->fetchColumn();

        if ($existingId !== false) {
            $sql = 'UPDATE notifications SET title = ?, message = ?, link_url = ?';
            $params = [$title, $message, $link];
            if (notification_table_has_column($pdo, 'severity')) {
                $sql .= ', severity = ?';
                $params[] = notification_severity($severity);
            }
            $sql .= ' WHERE id = ?';
            $params[] = (int) $existingId;
            $pdo->prepare($sql)->execute($params);

            return false;
        }

        if (notification_table_has_column($pdo, 'severity')) {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, notification_key, title, message, link_url, severity)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $notificationKey, $title, $message, $link, notification_severity($severity)]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, notification_key, title, message, link_url)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $notificationKey, $title, $message, $link]);
        }

        return true;
    }

    $lookup = $pdo->prepare('SELECT id FROM notifications WHERE user_id = ? AND title = ? AND link_url <=> ? AND read_at IS NULL LIMIT 1');
    $lookup->execute([$userId, $title, $link]);
    if ($lookup->fetchColumn() !== false) {
        return false;
    }

    notify_user($pdo, $userId, $title, $message, $link, $severity);
    return true;
}

function notify_role_once(PDO $pdo, string $role, string $notificationKey, string $title, string $message, ?string $link = null, string $severity = 'info'): int
{
    $created = 0;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ? AND is_active = 1');
    $stmt->execute([$role]);

    foreach ($stmt->fetchAll() as $user) {
        if (notify_user_once($pdo, (int) $user['id'], $notificationKey, $title, $message, $link, $severity)) {
            $created++;
        }
    }

    return $created;
}

function unread_notification_count(PDO $pdo, int $userId): int
{
    purge_inaccessible_notifications($pdo, $userId);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn();
}

function user_notifications(PDO $pdo, int $userId, int $limit = 40): array
{
    purge_inaccessible_notifications($pdo, $userId);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        "SELECT *
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function latest_unread_notifications(PDO $pdo, int $userId, int $limit = 5): array
{
    purge_inaccessible_notifications($pdo, $userId);
    $limit = max(1, min(10, $limit));
    $severitySelect = notification_table_has_column($pdo, 'severity') ? 'severity' : "'info' AS severity";
    $stmt = $pdo->prepare(
        "SELECT id, title, message, link_url, {$severitySelect}, created_at
         FROM notifications
         WHERE user_id = ? AND read_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function mark_notification_read(PDO $pdo, int $notificationId, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$notificationId, $userId]);
}
