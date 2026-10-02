<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_permission('view_notifications');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/notification_rules.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
$summary = generate_needed_notifications($pdo);
$userId = (int) ($user['id'] ?? 0);

echo json_encode([
    'unread' => unread_notification_count($pdo, $userId),
    'latest' => latest_unread_notifications($pdo, $userId, 5),
    'generated' => $summary,
], JSON_THROW_ON_ERROR);
