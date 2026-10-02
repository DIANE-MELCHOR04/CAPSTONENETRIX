<?php
require_once __DIR__ . '/lib/attachments.php';
require_login();
require_once __DIR__ . '/config/db.php';
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM attachments WHERE id=?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); die('Attachment not found.'); }
assert_attachment_record_access($pdo, (string)$row['entity_type'], (int)$row['entity_id']);
$root = realpath(__DIR__ . '/storage/attachments');
$path = realpath(__DIR__ . '/' . (string) $row['stored_path']);
if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); die('Attachment file is unavailable.'); }
header('Content-Type: ' . ((string) $row['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . addslashes((string) $row['file_name']) . '"');
readfile($path);
exit;
