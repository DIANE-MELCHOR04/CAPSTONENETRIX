<?php
require_once __DIR__ . '/lib/damage.php';
require_once __DIR__ . '/lib/attachments.php';
require_any_permission(['manage_damage','report_site_damage','approve_damage']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        if (!role_can((string) ($_SESSION['role'] ?? ''), 'approve_damage')) {
            throw new RuntimeException('You do not have permission to approve damage reports.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'approve') {
            approve_damage_report($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Damage report approved.');
        } elseif ($action === 'reject') {
            reject_damage_report($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            flash('success', 'Damage report rejected.');
        } else {
            throw new RuntimeException('Unsupported damage report action.');
        }
        $pdo->commit();
        redirect('damage_report_view.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('damage_report_view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare('SELECT d.*, l.name AS location_name, u.full_name, a.full_name AS approver_name FROM damage_reports d JOIN locations l ON l.id = d.location_id JOIN users u ON u.id = d.reported_by LEFT JOIN users a ON a.id = d.approved_by WHERE d.id = ?');
$stmt->execute([$id]);
$report = $stmt->fetch();
if (!$report) {
    http_response_code(404);
    die('Damage report not found.');
}
if ((string) ($_SESSION['role'] ?? '') === 'site_engineer' && (int) $report['reported_by'] !== (int) current_user()['id']) { http_response_code(403); die('You may only access damage reports you created for your site.'); }
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM damage_report_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.damage_report_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$timeline = entity_timeline($pdo, 'damage_report', $id);
$attachments = attachment_rows($pdo, 'damage_report', $id);
$activePage = 'damage_reports';
$title = 'Damage Report ' . $report['damage_no'];
$eyebrow = 'DAMAGE CONTROL';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light"><?= e($report['damage_no']) ?></span><h2><?= e($report['location_name']) ?></h2><p>Reported by <?= e($report['full_name']) ?>. Status: <?= e(str_replace('_', ' ', $report['status'])) ?>.</p></div><a class="btn glass" href="print_damage_report.php?id=<?= (int) $id ?>" target="_blank">Print</a></section>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Reported Qty</th><th>Decision Qty</th><th>Status</th><th>Remarks</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['damaged_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['decision_qty']) ?></td><td><span class="workflow-badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td><td><?= e($item['remarks']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if (role_can($_SESSION['role'], 'approve_damage') && $report['status'] === 'submitted'): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">MANAGER DECISION</span><h2>Approve inventory write-off</h2></div></div><form method="post" data-block-on-submit="Saving decision"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><label>Remarks<textarea name="remarks"></textarea></label><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="approve" data-confirm="Approve this damage report and update inventory?" data-confirm-title="Approve damage">Approve</button><button class="btn danger" name="action" value="reject" data-confirm="Reject this damage report?" data-confirm-title="Reject damage">Reject</button></div></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">EVIDENCE</span><h2>Damage Evidence</h2><p class="muted">Attach PDF, JPG, PNG, XLSX, or XLS evidence. Maximum 8 MB per file.</p></div></div>
<div class="attachment-list"><?php if (!$attachments): ?><p class="muted">No attachments yet.</p><?php endif; ?><?php foreach ($attachments as $attachment): ?><div class="attachment-row"><div><b><?= e($attachment['file_name']) ?></b><small>Uploaded by <?= e($attachment['full_name']) ?> · <?= e(date('M d, Y h:i A', strtotime($attachment['created_at']))) ?></small></div><a class="btn secondary small" href="attachment_download.php?id=<?= (int) $attachment['id'] ?>">Download</a></div><?php endforeach; ?></div>
<form method="post" action="attachment_upload.php" enctype="multipart/form-data" class="inline-form" style="margin-top:16px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="entity_type" value="damage_report"><input type="hidden" name="entity_id" value="<?= (int) $id ?>"><input type="hidden" name="return_url" value="damage_report_view.php?id=<?= (int) $id ?>"><label>Upload evidence<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" required></label><button class="btn secondary" type="submit">Upload Evidence</button></form></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TIMELINE</span><h2>Activity History</h2></div></div><ul class="timeline-list"><?php if (!$timeline): ?><li class="timeline-item"><span class="muted">No activity yet.</span></li><?php endif; ?><?php foreach ($timeline as $event): ?><li class="timeline-item"><span><?= e(date('M d, h:i A', strtotime($event['created_at']))) ?></span><div><b><?= e(ucfirst($event['action'])) ?></b><small><?= e($event['full_name'] ?? 'System') ?> - <?= e($event['details']) ?></small></div></li><?php endforeach; ?></ul></section>
</div></main></div></body></html>
