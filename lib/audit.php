<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function write_audit(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId, string $details): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $entityType, $entityId, $details]);
}

function entity_timeline(PDO $pdo, string $entityType, int $entityId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, u.full_name
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type = ? AND a.entity_id = ?
         ORDER BY a.created_at DESC, a.id DESC'
    );
    $stmt->execute([$entityType, $entityId]);

    return $stmt->fetchAll();
}

