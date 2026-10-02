<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/backups.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Scheduled backup runner is CLI-only.');
}

if (setting_value($pdo, 'auto_backup_enabled', '1') !== '1') {
    echo "Scheduled backup is disabled." . PHP_EOL;
    exit(0);
}

$userId = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
$backup = create_database_backup($pdo, $userId);

echo 'Scheduled backup completed: ' . $backup['file_name'] . PHP_EOL;
