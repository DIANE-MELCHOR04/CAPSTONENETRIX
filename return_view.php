<?php
require_once __DIR__ . '/lib/returns.php';
require_once __DIR__ . '/lib/attachments.php';
require_any_permission(['view_returns', 'create_returns', 'post_returns']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!role_can((string) ($_SESSION['role'] ?? ''), 'post_returns')) {
            throw new RuntimeException('Only warehouse personnel can post a return into inventory.');
        }
        $pdo->beginTransaction();
        post_material_return($pdo, $id, (int) current_user()['id']);
        $pdo->commit();
        flash('success', 'Material return posted.');
        redirect('return_view.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('return_view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare('SELECT r.*, s.name AS site_name, l.name AS location_name, u.full_name, p.full_name AS posted_name FROM material_returns r JOIN sites s ON s.id = r.source_site_id JOIN locations l ON l.id = r.receiving_location_id JOIN users u ON u.id = r.returned_by LEFT JOIN users p ON p.id = r.posted_by WHERE r.id = ?');
$stmt->execute([$id]);
$return = $stmt->fetch();
if (!$return) {
    http_response_code(404);
    die('Return not found.');
}
require_site_engineer_record_access((int) $return['source_site_id'], (int) $return['returned_by']);
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM material_return_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.return_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$timeline = entity_timeline($pdo, 'material_return', $id);
$attachments = attachment_rows($pdo, 'return', $id);
$activePage = 'returns';
$title = 'Return ' . $return['return_no'];
$eyebrow = 'RETURN CONTROL';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light"><?= e($return['return_no']) ?></span><h2><?= e($return['site_name']) ?> to <?= e($return['location_name']) ?></h2><p>Returned by <?= e($return['full_name']) ?>. Status: <?= e(str_replace('_', ' ', $return['status'])) ?>.</p></div><div class="hero-actions"><a class="btn glass" href="print_return.php?id=<?= (int) $id ?>" target="_blank">Print</a></div></section>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Qty</th><th>Condition</th><th>Status</th><th>Remarks</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['returned_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= e(str_replace('_', ' ', $item['condition_status'])) ?></td><td><span class="workflow-badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td><td><?= e($item['remarks']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if (role_can((string) ($_SESSION['role'] ?? ''), 'post_returns') && in_array($return['status'], ['draft','submitted'], true)): ?><section class="form-card"><form method="post" data-block-on-submit="Posting return"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><button class="btn primary" type="submit" data-confirm="Post this return to inventory?" data-confirm-title="Post return">Post Return</button></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">EVIDENCE</span><h2>Return Evidence</h2><p class="muted">Attach PDF, JPG, PNG, XLSX, or XLS evidence. Maximum 8 MB per file.</p></div></div>
<div class="attachment-list"><?php if (!$attachments): ?><p class="muted">No attachments yet.</p><?php endif; ?><?php foreach ($attachments as $attachment): ?><div class="attachment-row"><div><b><?= e($attachment['file_name']) ?></b><small>Uploaded by <?= e($attachment['full_name']) ?> · <?= e(date('M d, Y h:i A', strtotime($attachment['created_at']))) ?></small></div><a class="btn secondary small" href="attachment_download.php?id=<?= (int) $attachment['id'] ?>">Download</a></div><?php endforeach; ?></div>
<form method="post" action="attachment_upload.php" enctype="multipart/form-data" class="inline-form" style="margin-top:16px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="entity_type" value="return"><input type="hidden" name="entity_id" value="<?= (int) $id ?>"><input type="hidden" name="return_url" value="return_view.php?id=<?= (int) $id ?>"><label>Upload evidence<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" required></label><button class="btn secondary" type="submit">Upload Evidence</button></form></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TIMELINE</span><h2>Activity History</h2></div></div><ul class="timeline-list"><?php if (!$timeline): ?><li class="timeline-item"><span class="muted">No activity yet.</span></li><?php endif; ?><?php foreach ($timeline as $event): ?><li class="timeline-item"><span><?= e(date('M d, h:i A', strtotime($event['created_at']))) ?></span><div><b><?= e(ucfirst($event['action'])) ?></b><small><?= e($event['full_name'] ?? 'System') ?> - <?= e($event['details']) ?></small></div></li><?php endforeach; ?></ul></section>
</div></main></div></body></html>
