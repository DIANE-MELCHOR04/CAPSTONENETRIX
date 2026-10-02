<?php
require_once __DIR__ . '/lib/adjustments.php';
require_once __DIR__ . '/lib/attachments.php';
require_any_permission(['view_stock_counts', 'manage_stock_counts', 'approve_stock_counts']);
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        if ((string) $_POST['action'] === 'approve') {
            if (!role_can((string) ($_SESSION['role'] ?? ''), 'approve_stock_counts')) {
                throw new RuntimeException('You do not have permission to approve stock counts.');
            }
            approve_stock_count($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            $adjustmentId = create_adjustment_from_stock_count($pdo, $id, (int) current_user()['id']);
            $pdo->commit();
            flash('success', 'Stock count approved and adjustment prepared.');
            redirect('adjustment_view.php?id=' . $adjustmentId);
        } elseif ((string) $_POST['action'] === 'reject_recount') {
            if (!role_can((string) ($_SESSION['role'] ?? ''), 'approve_stock_counts')) { throw new RuntimeException('You do not have permission to reject stock counts.'); }
            reject_stock_count($pdo, $id, (int) current_user()['id'], trim((string) $_POST['remarks']));
            $pdo->commit();
            flash('success', 'Stock count returned for recount.');
            redirect('stock_count_view.php?id=' . $id);
        } else {
            throw new RuntimeException('Unsupported stock count action.');
        }
        $pdo->commit();
        redirect('stock_count_view.php?id=' . $id);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('stock_count_view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare('SELECT c.*, l.name AS location_name, u.full_name, ap.full_name AS approver_name FROM stock_counts c JOIN locations l ON l.id = c.location_id JOIN users u ON u.id = c.counted_by LEFT JOIN users ap ON ap.id = c.approved_by WHERE c.id = ?');
$stmt->execute([$id]);
$count = $stmt->fetch();
if (!$count) {
    http_response_code(404);
    die('Stock count not found.');
}
$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM stock_count_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.stock_count_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$timeline = entity_timeline($pdo, 'stock_count', $id);
$attachments = attachment_rows($pdo, 'stock_count', $id);
$activePage = 'stock_counts';
$title = 'Stock Count ' . $count['count_no'];
$eyebrow = 'PHYSICAL COUNT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light"><?= e($count['count_no']) ?></span><h2><?= e($count['location_name']) ?></h2><p>Counted by <?= e($count['full_name']) ?>. Status: <?= e(str_replace('_', ' ', $count['status'])) ?>.</p></div><a class="btn glass" href="print_stock_count.php?id=<?= (int) $id ?>" target="_blank">Print</a></section>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>System</th><th>Counted</th><th>Variance</th><th>Status</th><th>Remarks</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['system_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['counted_qty']) ?></td><td><?= money_or_qty($item['variance_qty']) ?></td><td><span class="workflow-badge <?= e($item['status']) ?>"><?= e(str_replace('_', ' ', $item['status'])) ?></span></td><td><?= e($item['remarks']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php if (role_can((string) ($_SESSION['role'] ?? ''), 'manage_stock_counts') && $count['status'] === 'rejected'): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">RECOUNT</span><h2>Manager requested a recount</h2><p><?= e($count['approval_remarks'] ?: 'Recount required.') ?></p></div></div><a class="btn primary" href="stock_count_create.php?recount_of=<?= (int)$id ?>">Create Recount</a></section><?php endif; ?>
<?php if (role_can((string) ($_SESSION['role'] ?? ''), 'approve_stock_counts') && $count['status'] === 'submitted'): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">MANAGER DECISION</span><h2>Approve count variance</h2></div></div><form method="post" data-block-on-submit="Approving stock count"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>"><label>Remarks<textarea name="remarks"></textarea></label><div class="form-actions" style="margin-top:14px"><button class="btn primary" name="action" value="approve" data-confirm="Approve this stock count and prepare an adjustment?" data-confirm-title="Approve stock count">Approve Count</button><button class="btn danger" name="action" value="reject_recount" data-confirm="Return this count to Warehouse Personnel for recount?" data-confirm-title="Require recount">Reject / Require Recount</button></div></form></section><?php endif; ?>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">EVIDENCE</span><h2>Count Sheet / Evidence</h2><p class="muted">Attach signed count sheets, photos, or spreadsheets supporting this physical count.</p></div></div><div class="attachment-list"><?php if (!$attachments): ?><p class="muted">No attachments yet.</p><?php endif; ?><?php foreach ($attachments as $attachment): ?><div class="attachment-row"><div><b><?= e($attachment['file_name']) ?></b><small>Uploaded by <?= e($attachment['full_name']) ?> · <?= e(date('M d, Y h:i A', strtotime($attachment['created_at']))) ?></small></div><a class="btn secondary small" href="attachment_download.php?id=<?= (int)$attachment['id'] ?>">Download</a></div><?php endforeach; ?></div><form method="post" action="attachment_upload.php" enctype="multipart/form-data" class="inline-form" style="margin-top:16px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="entity_type" value="stock_count"><input type="hidden" name="entity_id" value="<?= (int)$id ?>"><input type="hidden" name="return_url" value="stock_count_view.php?id=<?= (int)$id ?>"><label>Upload evidence<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" required></label><button class="btn secondary" type="submit">Upload Evidence</button></form></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">TIMELINE</span><h2>Activity History</h2></div></div><ul class="timeline-list"><?php if (!$timeline): ?><li class="timeline-item"><span class="muted">No activity yet.</span></li><?php endif; ?><?php foreach ($timeline as $event): ?><li class="timeline-item"><span><?= e(date('M d, h:i A', strtotime($event['created_at']))) ?></span><div><b><?= e(ucfirst($event['action'])) ?></b><small><?= e($event['full_name'] ?? 'System') ?> - <?= e($event['details']) ?></small></div></li><?php endforeach; ?></ul></section>
</div></main></div></body></html>
