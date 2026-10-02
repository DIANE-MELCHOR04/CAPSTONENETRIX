<?php
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/high_value.php';
require_once __DIR__ . '/lib/procurement.php';
require_once __DIR__ . '/lib/attachments.php';
require_permission(role_can((string) ($_SESSION['role'] ?? ''), 'view_requests') ? 'view_requests' : 'view_own_requests');
require_once __DIR__ . '/config/db.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT r.*, u.full_name, s.name AS site_name FROM material_requests r JOIN users u ON u.id = r.requested_by JOIN sites s ON s.id = r.site_id WHERE r.id = ?');
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(404);
    die('Request not found.');
}
if ((string) $_SESSION['role'] === 'site_engineer' && (int) $request['requested_by'] !== (int) current_user()['id']) {
    http_response_code(403);
    die('You may only view your own requests.');
}

$itemsStmt = $pdo->prepare('SELECT i.*, m.material_code, m.material_name, u.name AS unit_name FROM material_request_items i JOIN materials m ON m.id = i.material_id JOIN units u ON u.id = m.unit_id WHERE i.request_id = ? ORDER BY i.id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();
$itemIds = array_map(static fn(array $item): int => (int) $item['id'], $items);
$activeProcurementLinks = active_procurement_links_for_request_items($pdo, $itemIds);
$reservationSources = [];
if ($itemIds) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $sourceStmt = $pdo->prepare("SELECT mr.request_item_id, l.name AS location_name, mr.reserved_qty, mr.released_qty FROM material_reservations mr JOIN locations l ON l.id=mr.location_id WHERE mr.request_item_id IN ($placeholders) AND mr.status <> 'cancelled' ORDER BY mr.request_item_id, l.name");
    $sourceStmt->execute($itemIds);
    foreach ($sourceStmt->fetchAll() as $sourceRow) {
        $reservationSources[(int)$sourceRow['request_item_id']][] = $sourceRow;
    }
}

$hasShortages = count(array_filter($items, static fn($item) => (float) $item['shortage_qty'] > 0)) > 0;
$hasUnlinkedShortages = count(array_filter($items, static function ($item) use ($activeProcurementLinks): bool {
    return (float) $item['shortage_qty'] > 0 && !isset($activeProcurementLinks[(int) $item['id']]);
})) > 0;
$hasReleasableReservedStock = request_has_releasable_reservations($pdo, $id);
$releases = $pdo->prepare('SELECT * FROM material_releases WHERE request_id = ? ORDER BY release_date DESC');
$releases->execute([$id]);
$releaseRows = $releases->fetchAll();
$timelineRows = request_timeline($pdo, $request);
$approvalRemarks = line_approval_remarks_for_request($pdo, $id);
$attachments = attachment_rows($pdo, 'request', $id);

$activePage = 'requests';
$title = 'Request ' . $request['request_no'];
$eyebrow = 'MATERIAL REQUEST';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php require __DIR__ . '/partials/assets.php'; ?>
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/partials/header.php'; ?>
        <div class="page-content ops-document-page">
            <section class="ops-document-summary">
                <div class="ops-document-identity">
                    <span class="eyebrow"><?= e($request['request_no']) ?></span>
                    <div><h2><?= e($request['site_name']) ?></h2><?= ui_status_badge((string) $request['status']) ?></div>
                    <p><?= e($request['remarks'] ?: 'No request remarks were entered.') ?></p>
                </div>
                <dl class="ops-document-meta">
                    <div><dt>Requested by</dt><dd><?= e($request['full_name']) ?></dd></div>
                    <div><dt>Request date</dt><dd><?= e(date('M d, Y', strtotime($request['request_date']))) ?></dd></div>
                    <div><dt>Date needed</dt><dd><?= e(date('M d, Y', strtotime($request['date_needed']))) ?></dd></div>
                    <div><dt>Priority</dt><dd><?= ui_status_badge((string) $request['priority']) ?></dd></div>
                </dl>
            </section>

            <div class="ops-sticky-actions ops-document-actions" aria-label="Request actions">
                <a class="btn secondary" href="requests.php">Back to Requests</a>
                <a class="btn secondary" href="print_request.php?id=<?= (int) $id ?>" target="_blank" rel="noopener">Print Request</a>
                <?php if (role_can((string) $_SESSION['role'], 'manage_procurement') && $hasUnlinkedShortages): ?><a class="btn secondary" href="purchase_request_create.php?request_id=<?= (int) $id ?>">Create Purchase Request</a><?php endif; ?>
                <?php if (role_can((string) $_SESSION['role'], 'release_materials')): ?>
                    <?php if ($hasReleasableReservedStock): ?>
                        <a class="btn primary" href="release_create.php?id=<?= (int) $id ?>">Release Reserved Portion</a>
                    <?php else: ?>
                        <span class="btn secondary" aria-disabled="true" title="This request has no unreleased reserved stock yet.">No Reserved Stock to Release</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <section class="ops-section">
                <div class="ops-section-heading"><div><span class="eyebrow">Request contents</span><h2>Material lines</h2></div><span class="ops-section-count"><?= number_format(count($items)) ?> line(s)</span></div>
                <?php if (!$items): ?>
                    <?= ui_empty_state('No material lines', 'This request does not contain any material quantities.') ?>
                <?php else: ?>
                    <div class="ops-table-shell"><div class="table-wrap">
                        <table class="data modern-table ops-table" data-sortable-table>
                            <thead><tr><th>Material</th><th>Requested</th><th>Approved</th><th>Reserved</th><th>Shortage</th><th>Released</th><th>Received</th><th>Status</th><th>Procurement Trace</th></tr></thead>
                            <tbody><?php foreach ($items as $item): ?><?php
                                $itemId = (int) $item['id'];
                                $procurementLink = $activeProcurementLinks[$itemId] ?? null;
                                $remainingReserved = max(0, (float) $item['reserved_qty'] - (float) $item['released_qty']);
                            ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($item['requested_qty']) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['approved_qty']) ?></td><td><?= money_or_qty($item['reserved_qty']) ?><?php if (!empty($reservationSources[$itemId])): ?><?php foreach ($reservationSources[$itemId] as $source): ?><small><?= e($source['location_name']) ?>: <?= money_or_qty($source['reserved_qty']) ?><?php if ((float)$source['released_qty'] > 0): ?> (<?= money_or_qty($source['released_qty']) ?> released)<?php endif; ?></small><?php endforeach; ?><?php endif; ?></td><td><?= money_or_qty($item['shortage_qty']) ?></td><td><?= money_or_qty($item['released_qty']) ?></td><td><?= money_or_qty($item['received_qty']) ?></td><td>
                                <?= ui_status_badge((string) $item['item_status']) ?>
                                <?php if ((float) $item['shortage_qty'] > 0 && $procurementLink): ?>
                                    <div style="margin-top:6px"><?= ui_status_badge('for_procurement', 'Procurement In Progress — ' . (string) $procurementLink['pr_no']) ?></div>
                                <?php elseif ((float) $item['shortage_qty'] > 0 && $remainingReserved <= 0 && (string) $item['item_status'] !== 'for_procurement'): ?>
                                    <div style="margin-top:6px"><?= ui_status_badge('for_procurement') ?></div>
                                <?php endif; ?>
                            </td><td><?php if ($procurementLink): ?><b><?= e($procurementLink['pr_no']) ?></b><?php if (!empty($procurementLink['po_no'])): ?><small>PO: <?= e($procurementLink['po_no']) ?></small><?php endif; ?><?php if (!empty($procurementLink['supplier_name'])): ?><small>Supplier: <?= e($procurementLink['supplier_name']) ?></small><?php endif; ?><?php if (!empty($procurementLink['expected_delivery'])): ?><small>ETA: <?= e(date('M d, Y', strtotime($procurementLink['expected_delivery']))) ?></small><?php endif; ?><small>Ordered <?= money_or_qty($procurementLink['procurement_ordered_qty']) ?> / Received <?= money_or_qty($procurementLink['procurement_received_qty']) ?></small><?php else: ?><span class="muted"><?= (float)$item['shortage_qty'] > 0 ? 'Not yet procured' : '—' ?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody>
                        </table>
                    </div></div>
                <?php endif; ?>
            </section>

            <section class="ops-section ops-document-timeline">
                <div class="ops-section-heading"><div><span class="eyebrow">Lifecycle</span><h2>Request Timeline</h2></div></div>
                <?php if (!$timelineRows): ?>
                    <?= ui_empty_state('No timeline events yet', 'Workflow events will appear as this request moves through approval and fulfillment.') ?>
                <?php else: ?>
                    <ol class="ops-timeline-list">
                        <?php foreach ($timelineRows as $event): ?>
                            <li>
                                <span class="ops-timeline-mark" aria-hidden="true"></span>
                                <div><small><?= e(date('M d, Y h:i A', strtotime($event['created_at']))) ?></small><strong><?= e($event['title']) ?></strong><p><?= e($event['details']) ?><?= $event['user_name'] ? ' - ' . e($event['user_name']) : '' ?></p></div>
                                <?= ui_status_badge((string) $event['type']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="ops-section">
                <div class="ops-section-heading"><div><span class="eyebrow">History</span><h2>Activity and remarks</h2></div></div>
                <div class="ops-detail-grid">
                    <div>
                        <h3>Approval Remarks History</h3>
                        <?php if (!$approvalRemarks): ?>
                            <?= ui_empty_state('No line-level remarks', 'Approval decisions and comments will appear here.') ?>
                        <?php else: ?>
                            <div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table" data-sortable-table><thead><tr><th>Material</th><th>Decision</th><th>Approved</th><th>Remarks</th><th>User</th><th>Date</th></tr></thead><tbody><?php foreach ($approvalRemarks as $remark): ?><tr><td><b><?= e($remark['material_code']) ?></b><small><?= e($remark['material_name']) ?></small></td><td><?= ui_status_badge((string) $remark['decision']) ?></td><td><?= money_or_qty($remark['approved_qty']) ?></td><td><?= e($remark['remarks']) ?></td><td><?= e($remark['full_name']) ?></td><td><?= e(date('M d, Y h:i A', strtotime($remark['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3>Attachments</h3>
                        <?php if (!$attachments): ?>
                            <?= ui_empty_state('No attachments', 'No supporting documents are attached to this request.') ?>
                        <?php else: ?>
                            <ul class="ops-attachment-list"><?php foreach ($attachments as $attachment): ?><li><div><strong><a href="attachment_download.php?id=<?= (int)$attachment['id'] ?>"><?= e($attachment['file_name']) ?></a></strong><small><?= e($attachment['mime_type']) ?> / <?= e($attachment['full_name']) ?></small></div><span><?= e(date('M d, Y', strtotime($attachment['created_at']))) ?></span></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                        <form method="post" action="attachment_upload.php" enctype="multipart/form-data" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="entity_type" value="request"><input type="hidden" name="entity_id" value="<?= (int)$id ?>"><input type="hidden" name="return_url" value="request_view.php?id=<?= (int)$id ?>"><label>Attach supporting file<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" required><small>PDF, image, or Excel up to 8 MB.</small></label><button class="btn secondary" type="submit">Upload Attachment</button></form>
                    </div>
                </div>
            </section>

            <?php if (role_can((string) $_SESSION['role'], 'approve_requests') && in_array($request['status'], ['submitted','under_review'], true)): ?>
                <section class="ops-section ops-decision-section">
                    <div class="ops-section-heading"><div><span class="eyebrow">Manager decision</span><h2>Approve and reserve stock</h2></div></div>
                    <form method="post" action="request_action.php" data-block-on-submit="Saving decision">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int) $id ?>">
                        <div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table"><thead><tr><th>Material</th><th>Requested</th><th>Approved Qty</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['material_code']) ?> - <?= e($item['material_name']) ?></td><td><?= money_or_qty($item['requested_qty']) ?></td><td><input type="number" min="0" step="0.01" name="approved_qty[<?= (int) $item['id'] ?>]" value="<?= e($item['requested_qty']) ?>"></td></tr><?php endforeach; ?></tbody></table></div></div>
                        <label class="ops-decision-remarks">Review remarks<textarea name="review_remarks"></textarea></label>
                        <div class="ops-step-actions"><button class="btn primary" name="decision" value="approve" type="submit" data-confirm="Approve this request and reserve available stock?" data-confirm-title="Approve request">Approve / Reserve</button><button class="btn danger" name="decision" value="reject" type="submit" data-confirm="Reject this material request?" data-confirm-title="Reject request">Reject Request</button></div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="ops-section">
                <div class="ops-section-heading"><div><span class="eyebrow">Related documents</span><h2>Release records</h2></div></div>
                <?php if (!$releaseRows): ?>
                    <?= ui_empty_state('No release records', 'Release documents will be linked after stock is issued for this request.') ?>
                <?php else: ?>
                    <div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table" data-sortable-table><thead><tr><th>Release No.</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($releaseRows as $release): ?><tr><td><b><?= e($release['release_no']) ?></b></td><td><?= e(date('M d, Y h:i A', strtotime($release['release_date']))) ?></td><td><?= ui_status_badge((string) $release['status']) ?></td><td><a class="table-action" href="release_view.php?id=<?= (int) $release['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div></div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
