<?php
require_once __DIR__ . '/lib/requests.php';
require_permission('release_materials');
require_once __DIR__ . '/config/db.php';

$requestId = (int) ($_GET['id'] ?? $_POST['request_id'] ?? 0);
if ($requestId <= 0) {
    http_response_code(400);
    die('Material request is required.');
}

$releasableLocations = request_releasable_locations($pdo, $requestId);
$allowedLocationIds = array_map(static fn(array $row): int => (int) $row['id'], $releasableLocations);
$selectedLocationId = (int) ($_GET['location_id'] ?? $_POST['source_location_id'] ?? ($allowedLocationIds[0] ?? 0));
if ($selectedLocationId > 0 && !in_array($selectedLocationId, $allowedLocationIds, true)) {
    $selectedLocationId = (int) ($allowedLocationIds[0] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();

        $locationId = (int) ($_POST['source_location_id'] ?? 0);
        if ($locationId <= 0) {
            throw new RuntimeException('No releasable reservation location is available for this request.');
        }

        // Build the release from reservation records, not from the request-item total.
        // This prevents stock reserved in Warehouse A from being released from Warehouse B.
        $planned = [];
        foreach (($_POST['release_qty'] ?? []) as $reservationId => $releaseQtyRaw) {
            $releaseQty = (float) $releaseQtyRaw;
            if ($releaseQty <= 0) {
                continue;
            }

            $reservationStmt = $pdo->prepare(
                "SELECT mr.*, i.request_id, i.requested_qty, i.approved_qty,
                        i.reserved_qty AS item_reserved_qty, i.released_qty AS item_released_qty,
                        i.received_qty AS item_received_qty
                 FROM material_reservations mr
                 JOIN material_request_items i ON i.id = mr.request_item_id
                 WHERE mr.id = ?
                   AND i.request_id = ?
                   AND mr.location_id = ?
                 FOR UPDATE"
            );
            $reservationStmt->execute([(int) $reservationId, $requestId, $locationId]);
            $reservation = $reservationStmt->fetch();
            if (!$reservation) {
                throw new RuntimeException('A selected reservation does not belong to this request and source location.');
            }
            if ((string) $reservation['status'] === 'cancelled') {
                throw new RuntimeException('One of the selected reservations has been cancelled. Refresh the page and try again.');
            }

            $remainingReservation = (float) $reservation['reserved_qty'] - (float) $reservation['released_qty'];
            if (!can_release_reserved($remainingReservation, $releaseQty)) {
                throw new RuntimeException('Release quantity exceeds the quantity reserved at the selected location.');
            }

            $planned[] = [
                'reservation' => $reservation,
                'qty' => $releaseQty,
            ];
        }

        // Server-side empty-release protection. Do not create a release header until
        // at least one positive, valid reservation quantity has been supplied.
        if (!$planned) {
            throw new RuntimeException('Enter at least one release quantity greater than zero.');
        }

        $locationCheck = $pdo->prepare(
            "SELECT COUNT(*)
             FROM material_reservations mr
             JOIN material_request_items i ON i.id = mr.request_item_id
             WHERE i.request_id = ? AND mr.location_id = ?"
        );
        $locationCheck->execute([$requestId, $locationId]);
        if ((int) $locationCheck->fetchColumn() === 0) {
            throw new RuntimeException('The source location is not tied to this request\'s reservations.');
        }

        $releaseNo = next_document_no($pdo, 'REL');
        $pdo->prepare(
            'INSERT INTO material_releases (release_no, request_id, source_location_id, released_by, released_to, remarks)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $releaseNo,
            $requestId,
            $locationId,
            (int) current_user()['id'],
            trim((string) ($_POST['released_to'] ?? '')),
            trim((string) ($_POST['remarks'] ?? '')),
        ]);
        $releaseId = (int) $pdo->lastInsertId();

        $byRequestItem = [];
        foreach ($planned as $entry) {
            $reservation = $entry['reservation'];
            $releaseQty = (float) $entry['qty'];
            $newReservationReleased = (float) $reservation['released_qty'] + $releaseQty;
            $reservationStatus = $newReservationReleased + 0.00001 >= (float) $reservation['reserved_qty'] ? 'released' : 'active';

            $pdo->prepare('UPDATE material_reservations SET released_qty = ?, status = ? WHERE id = ?')
                ->execute([$newReservationReleased, $reservationStatus, (int) $reservation['id']]);

            $requestItemId = (int) $reservation['request_item_id'];
            if (!isset($byRequestItem[$requestItemId])) {
                $byRequestItem[$requestItemId] = [
                    'material_id' => (int) $reservation['material_id'],
                    'qty' => 0.0,
                ];
            }
            $byRequestItem[$requestItemId]['qty'] += $releaseQty;
        }

        foreach ($byRequestItem as $requestItemId => $releaseData) {
            $itemStmt = $pdo->prepare('SELECT * FROM material_request_items WHERE id = ? AND request_id = ? FOR UPDATE');
            $itemStmt->execute([(int) $requestItemId, $requestId]);
            $item = $itemStmt->fetch();
            if (!$item) {
                throw new RuntimeException('Material request item was not found.');
            }

            $releaseQty = (float) $releaseData['qty'];
            post_inventory_movement($pdo, [
                'movement_type' => 'release',
                'material_id' => (int) $releaseData['material_id'],
                'location_id' => $locationId,
                'quantity_in' => 0,
                'quantity_out' => $releaseQty,
                'reference_type' => 'release',
                'reference_id' => $releaseId,
                'remarks' => 'Released for material request from reserved stock',
                'created_by' => (int) current_user()['id'],
            ]);

            $pdo->prepare(
                'UPDATE inventory_balances
                 SET reserved_quantity = GREATEST(0, reserved_quantity - ?)
                 WHERE material_id = ? AND location_id = ?'
            )->execute([$releaseQty, (int) $releaseData['material_id'], $locationId]);

            $pdo->prepare(
                'INSERT INTO material_release_items (release_id, request_item_id, material_id, released_qty)
                 VALUES (?, ?, ?, ?)'
            )->execute([$releaseId, (int) $requestItemId, (int) $releaseData['material_id'], $releaseQty]);

            $releasedTotal = (float) $item['released_qty'] + $releaseQty;
            $status = request_item_status(
                (float) $item['requested_qty'],
                (float) $item['approved_qty'],
                (float) $item['reserved_qty'],
                $releasedTotal,
                (float) $item['received_qty']
            );
            $pdo->prepare('UPDATE material_request_items SET released_qty = ?, item_status = ? WHERE id = ?')
                ->execute([$releasedTotal, $status, (int) $requestItemId]);
        }

        refresh_request_status($pdo, $requestId);
        $pdo->commit();
        flash('success', 'Reserved materials released from their recorded source location.');
        redirect('release_view.php?id=' . $releaseId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect('release_create.php?id=' . $requestId . ($selectedLocationId > 0 ? '&location_id=' . $selectedLocationId : ''));
    }
}

$items = [];
if ($selectedLocationId > 0) {
    $itemsStmt = $pdo->prepare(
        "SELECT mr.id AS reservation_id,
                mr.reserved_qty AS reservation_reserved_qty,
                mr.released_qty AS reservation_released_qty,
                i.id AS request_item_id, i.reserved_qty, i.released_qty, i.shortage_qty,
                m.material_code, m.material_name, u.name AS unit_name
         FROM material_reservations mr
         JOIN material_request_items i ON i.id = mr.request_item_id
         JOIN materials m ON m.id = mr.material_id
         JOIN units u ON u.id = m.unit_id
         WHERE i.request_id = ?
           AND mr.location_id = ?
           AND mr.status <> 'cancelled'
           AND mr.reserved_qty > mr.released_qty
         ORDER BY m.material_name, mr.id"
    );
    $itemsStmt->execute([$requestId, $selectedLocationId]);
    $items = $itemsStmt->fetchAll();
}

$selectedLocationLabel = '';
foreach ($releasableLocations as $location) {
    if ((int) $location['id'] === $selectedLocationId) {
        $selectedLocationLabel = (string) $location['label'];
        break;
    }
}

$activePage = 'requests';
$title = 'Release Reserved Materials';
$eyebrow = 'WAREHOUSE RELEASE';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content ops-guided-page">
<?= ui_page_intro('Warehouse release', 'Release Reserved Materials', 'Release only quantities that are actually reserved. The source location comes directly from the reservation record.') ?>

<?php if (!$releasableLocations): ?>
    <section class="form-card ops-section"><?= ui_empty_state('Nothing ready for release', 'This request has no active reserved quantity remaining. Procurement may still be working on shortages.', ['label' => 'Back to Request', 'url' => 'request_view.php?id=' . $requestId, 'class' => 'secondary']) ?></section>
<?php else: ?>
    <?php if (count($releasableLocations) > 1): ?>
        <section class="form-card ops-section">
            <div class="ops-form-step-heading"><span>Reservation source</span><h2>Choose a reserved location</h2><p>Only locations that currently hold reserved stock for this request are available.</p></div>
            <form method="get" class="form-grid">
                <input type="hidden" name="id" value="<?= (int) $requestId ?>">
                <label>Reserved Location
                    <select name="location_id" onchange="this.form.submit()">
                        <?php foreach ($releasableLocations as $location): ?>
                            <option value="<?= (int) $location['id'] ?>" <?= (int) $location['id'] === $selectedLocationId ? 'selected' : '' ?>><?= e($location['label']) ?> — <?= money_or_qty($location['releasable_qty']) ?> reserved</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </section>
    <?php endif; ?>

    <section class="form-card ops-section ops-form-section"><form method="post" data-block-on-submit="Posting release"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_id" value="<?= (int) $requestId ?>"><input type="hidden" name="source_location_id" value="<?= (int) $selectedLocationId ?>">
        <div class="ops-form-step-heading"><span>Release details</span><h2>Source and recipient</h2><p>Source location: <strong><?= e($selectedLocationLabel) ?></strong>. This is locked to the actual reservation location.</p></div>
        <div class="form-grid"><label>Source Location<input value="<?= e($selectedLocationLabel) ?>" readonly></label><label>Released To<input name="released_to" placeholder="Receiving site representative"></label><label class="full">Remarks<textarea name="remarks"></textarea></label></div>
        <div class="ops-section-heading"><div><span class="eyebrow">Reserved stock</span><h2>Release quantities</h2></div></div>
        <div class="ops-table-shell"><div class="table-wrap"><table class="data modern-table ops-table"><thead><tr><th>Material</th><th>Reserved at this location</th><th>Request shortage</th><th>Release Qty</th></tr></thead><tbody>
            <?php foreach ($items as $item): ?><?php $remaining = (float) $item['reservation_reserved_qty'] - (float) $item['reservation_released_qty']; ?><tr><td><b><?= e($item['material_code']) ?></b><small><?= e($item['material_name']) ?></small></td><td><?= money_or_qty($remaining) ?> <?= e($item['unit_name']) ?></td><td><?= money_or_qty($item['shortage_qty']) ?></td><td><input type="number" min="0" max="<?= e($remaining) ?>" step="0.01" name="release_qty[<?= (int) $item['reservation_id'] ?>]" value="<?= e($remaining) ?>"></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <div class="form-actions ops-sticky-actions"><a class="btn secondary" href="request_view.php?id=<?= (int) $requestId ?>">Back</a><button class="btn primary" type="submit" data-confirm="Post this release from the recorded reservation location?" data-confirm-title="Post material release">Release Reserved Portion</button></div>
    </form></section>
<?php endif; ?>
</div></main></div></body></html>
