<?php
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/flow_upgrade.php';
require_permission('confirm_receipt');
require_once __DIR__ . '/config/db.php';

verify_csrf();
$releaseId = (int) ($_POST['release_id'] ?? 0);

try {
    require_flow_upgrade($pdo, ['receipt_discrepancies']);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT rel.*, r.site_id, r.requested_by FROM material_releases rel JOIN material_requests r ON r.id = rel.request_id WHERE rel.id = ? FOR UPDATE');
    $stmt->execute([$releaseId]);
    $release = $stmt->fetch();
    if (!$release) {
        throw new RuntimeException('Release record not found.');
    }
    require_site_engineer_record_access((int) $release['site_id'], (int) $release['requested_by']);
    $siteLocationId = site_stock_location_id($pdo, (int) $release['site_id']);
    $changedAny = false;

    foreach (($_POST['received_qty'] ?? []) as $releaseItemId => $receivedQtyRaw) {
        $receivedQty = max(0, (float) $receivedQtyRaw);
        $itemStmt = $pdo->prepare('SELECT * FROM material_release_items WHERE id = ? AND release_id = ? FOR UPDATE');
        $itemStmt->execute([(int) $releaseItemId, $releaseId]);
        $releaseItem = $itemStmt->fetch();
        if (!$releaseItem) {
            continue;
        }

        $releasedQty = (float) $releaseItem['released_qty'];
        $previouslyReceived = (float) $releaseItem['received_qty'];
        $receivedQty = min($receivedQty, $releasedQty);
        if ($receivedQty + 0.00001 < $previouslyReceived) {
            throw new RuntimeException('A previously confirmed receipt quantity cannot be reduced. Resolve any variance through the discrepancy workflow instead.');
        }
        $delta = max(0.0, $receivedQty - $previouslyReceived);
        if ($delta > 0) {
            post_inventory_movement($pdo, [
                'movement_type' => 'receipt',
                'material_id' => (int) $releaseItem['material_id'],
                'location_id' => $siteLocationId,
                'quantity_in' => $delta,
                'quantity_out' => 0,
                'reference_type' => 'release',
                'reference_id' => $releaseId,
                'remarks' => 'Confirmed project-site receipt from warehouse release',
                'created_by' => (int) current_user()['id'],
            ]);
            $changedAny = true;
        }

        $pdo->prepare('UPDATE material_release_items SET received_qty = ? WHERE id = ?')->execute([$receivedQty, (int) $releaseItemId]);

        $requestItemStmt = $pdo->prepare('SELECT * FROM material_request_items WHERE id = ? FOR UPDATE');
        $requestItemStmt->execute([(int) $releaseItem['request_item_id']]);
        $requestItem = $requestItemStmt->fetch();
        if (!$requestItem) {
            throw new RuntimeException('Linked material request item not found.');
        }
        $newReceivedTotal = min((float) $requestItem['approved_qty'], (float) $requestItem['received_qty'] + $delta);
        $status = request_item_status((float) $requestItem['requested_qty'], (float) $requestItem['approved_qty'], (float) $requestItem['reserved_qty'], (float) $requestItem['released_qty'], $newReceivedTotal);
        $pdo->prepare('UPDATE material_request_items SET received_qty = ?, item_status = ? WHERE id = ?')
            ->execute([$newReceivedTotal, $status, (int) $releaseItem['request_item_id']]);

        $variance = max(0.0, $releasedQty - $receivedQty);
        $reasonCode = (string) (($_POST['discrepancy_reason'] ?? [])[$releaseItemId] ?? 'short_delivery');
        $allowedReasons = ['short_delivery','damaged_in_transit','missing','still_in_transit','other'];
        if (!in_array($reasonCode, $allowedReasons, true)) {
            $reasonCode = 'other';
        }
        $reasonDetails = trim((string) (($_POST['discrepancy_details'] ?? [])[$releaseItemId] ?? ''));

        $existing = $pdo->prepare('SELECT id,status FROM receipt_discrepancies WHERE release_item_id=? FOR UPDATE');
        $existing->execute([(int) $releaseItemId]);
        $discrepancy = $existing->fetch();
        if ($variance > 0) {
            if ($reasonDetails === '' && $reasonCode === 'other') {
                throw new RuntimeException('Explain the discrepancy when Other is selected.');
            }
            if ($discrepancy) {
                $pdo->prepare("UPDATE receipt_discrepancies SET expected_qty=?, received_qty=?, variance_qty=?, reason_code=?, reason_details=?, status='open', reported_by=?, resolved_by=NULL, resolved_at=NULL, resolution_remarks=NULL WHERE id=?")
                    ->execute([$releasedQty,$receivedQty,$variance,$reasonCode,$reasonDetails,(int) current_user()['id'],(int) $discrepancy['id']]);
            } else {
                $pdo->prepare('INSERT INTO receipt_discrepancies (release_id,release_item_id,request_item_id,material_id,expected_qty,received_qty,variance_qty,reason_code,reason_details,status,reported_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$releaseId,(int) $releaseItemId,(int) $releaseItem['request_item_id'],(int) $releaseItem['material_id'],$releasedQty,$receivedQty,$variance,$reasonCode,$reasonDetails,'open',(int) current_user()['id']]);
            }
            notify_role($pdo, 'warehouse_manager', 'Receipt discrepancy requires review', 'A project receipt for release #' . $releaseId . ' has a variance of ' . money_or_qty($variance) . '.', 'release_view.php?id=' . $releaseId);
        } elseif ($discrepancy && (string) $discrepancy['status'] === 'open') {
            $pdo->prepare("UPDATE receipt_discrepancies SET received_qty=?, variance_qty=0, status='resolved_received', resolved_by=?, resolved_at=NOW(), resolution_remarks='Fully received on subsequent confirmation.' WHERE id=?")
                ->execute([$receivedQty,(int) current_user()['id'],(int) $discrepancy['id']]);
        }
    }

    $allItems = $pdo->prepare('SELECT COUNT(*) FROM material_release_items WHERE release_id = ? AND received_qty < released_qty');
    $allItems->execute([$releaseId]);
    $releaseStatus = ((int) $allItems->fetchColumn()) === 0 ? 'received' : 'partially_received';
    $pdo->prepare('UPDATE material_releases SET status = ? WHERE id = ?')->execute([$releaseStatus, $releaseId]);
    refresh_request_status($pdo, (int) $release['request_id']);
    write_audit($pdo, (int) current_user()['id'], 'confirm_receipt', 'material_release', $releaseId, $changedAny ? 'Site receipt posted into project inventory.' : 'Receipt confirmation reviewed without additional quantity.');
    $pdo->commit();
    flash('success', 'Receipt confirmed. Received quantities are now reflected in Site Inventory; any variance is tracked as a discrepancy.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', $exception->getMessage());
}

redirect('release_view.php?id=' . $releaseId);
