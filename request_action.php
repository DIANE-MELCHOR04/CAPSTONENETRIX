<?php
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/high_value.php';
require_permission('approve_requests');
require_once __DIR__ . '/config/db.php';

verify_csrf();
$requestId = (int) ($_POST['request_id'] ?? 0);
$decision = (string) ($_POST['decision'] ?? '');
$currentUserId = (int) current_user()['id'];
$reviewRemarks = trim((string) ($_POST['review_remarks'] ?? ''));

try {
    $pdo->beginTransaction();
    $requestStmt = $pdo->prepare('SELECT status FROM material_requests WHERE id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $currentStatus = (string) $requestStmt->fetchColumn();
    if ($currentStatus === '') {
        throw new RuntimeException('Material request not found.');
    }
    if (!in_array($currentStatus, ['submitted','under_review'], true)) {
        throw new RuntimeException('This material request has already moved beyond manager review and cannot be approved or rejected again.');
    }
    if (!in_array($decision, ['approve','reject'], true)) {
        throw new RuntimeException('Unsupported request decision.');
    }
    if ($decision === 'reject') {
        $pdo->prepare("UPDATE material_request_items SET approved_qty = 0, item_status = 'rejected' WHERE request_id = ?")->execute([$requestId]);
        $items = $pdo->prepare('SELECT id FROM material_request_items WHERE request_id = ?');
        $items->execute([$requestId]);
        foreach ($items->fetchAll() as $item) {
            record_line_approval_remark($pdo, (int) $item['id'], $currentUserId, 'rejected', 0.0, $reviewRemarks);
        }
        $pdo->prepare("UPDATE material_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?")
            ->execute([$currentUserId, $reviewRemarks, $requestId]);
        record_request_timeline_event($pdo, $requestId, 'rejected', 'Request rejected', $reviewRemarks ?: 'Request was rejected by the warehouse manager.', $currentUserId);
    } else {
        foreach (($_POST['approved_qty'] ?? []) as $itemId => $approvedQty) {
            $approvedQty = max(0, (float) $approvedQty);
            $stmt = $pdo->prepare('SELECT requested_qty FROM material_request_items WHERE id = ? AND request_id = ?');
            $stmt->execute([(int) $itemId, $requestId]);
            $requested = (float) $stmt->fetchColumn();
            $approvedQty = min($approvedQty, $requested);
            $status = $approvedQty <= 0 ? 'rejected' : ($approvedQty < $requested ? 'partially_approved' : 'approved');
            $pdo->prepare('UPDATE material_request_items SET approved_qty = ?, item_status = ? WHERE id = ?')
                ->execute([$approvedQty, $status, (int) $itemId]);
            record_line_approval_remark($pdo, (int) $itemId, $currentUserId, $status, $approvedQty, $reviewRemarks);
        }
        $pdo->prepare("UPDATE material_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?")
            ->execute([$currentUserId, $reviewRemarks, $requestId]);
        record_request_timeline_event($pdo, $requestId, 'approved', 'Request approved', $reviewRemarks ?: 'Manager approval completed and reservation started.', $currentUserId);
        reserve_approved_request($pdo, $requestId);
        record_request_timeline_event($pdo, $requestId, 'reserved', 'Reservation processed', 'Available stock was reserved where possible; shortages remain visible for procurement.', $currentUserId);
    }
    $pdo->commit();
    flash('success', 'Request decision saved.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', $exception->getMessage());
}

redirect('request_view.php?id=' . $requestId);
