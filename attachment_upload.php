<?php
require_once __DIR__ . '/lib/attachments.php';
require_login();
require_once __DIR__ . '/config/db.php';
verify_csrf();
$entityType = (string) ($_POST['entity_type'] ?? '');
$entityId = (int) ($_POST['entity_id'] ?? 0);
$returnUrl = trim((string) ($_POST['return_url'] ?? 'dashboard.php'));

assert_attachment_record_access($pdo, $entityType, $entityId);
if ($returnUrl === '' || preg_match('#^(?:https?:)?//#i', $returnUrl)) {
    $returnUrl = 'dashboard.php';
}
try {
    store_attachment($pdo, $entityType, $entityId, $_FILES['attachment'] ?? [], (int) current_user()['id']);
    flash('success', 'Attachment uploaded.');
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}
redirect($returnUrl);
