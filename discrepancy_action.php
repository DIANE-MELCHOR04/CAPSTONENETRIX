<?php
require_once __DIR__ . '/lib/flow_upgrade.php';
require_permission('resolve_receipt_discrepancies');
require_once __DIR__ . '/config/db.php';
verify_csrf();
$releaseId = (int) ($_POST['release_id'] ?? 0);
try {
    $pdo->beginTransaction();
    resolve_receipt_discrepancy($pdo, (int) ($_POST['discrepancy_id'] ?? 0), (string) ($_POST['resolution'] ?? ''), (int) current_user()['id'], trim((string) ($_POST['remarks'] ?? '')));
    $pdo->commit();
    flash('success', 'Receipt discrepancy resolved.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', $e->getMessage());
}
redirect('release_view.php?id=' . $releaseId);
