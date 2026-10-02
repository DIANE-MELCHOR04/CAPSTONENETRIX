<?php
require_once __DIR__ . '/lib/transfers.php';
require_any_permission(['create_transfers','create_site_transfers']);
require_once __DIR__ . '/config/db.php';
$preselectedMaterialId = (int) ($_GET['material_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $source = (int) $_POST['source_location_id'];
        $destination = (int) $_POST['destination_location_id'];
        if ((string) ($_SESSION['role'] ?? '') === 'site_engineer') {
            $siteId = current_user_site_id();
            if ($siteId === null) { throw new RuntimeException('Your account is not assigned to a project site.'); }
            $scope = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE id=? AND site_id=? AND type='site' AND is_active=1");
            $scope->execute([$source, $siteId]);
            if ((int) $scope->fetchColumn() !== 1) { throw new RuntimeException('Site Engineers may only transfer materials from their assigned project stockroom.'); }
        }
        if (!validate_transfer_locations($source, $destination)) {
            throw new RuntimeException('Source and destination must be different.');
        }
        $pdo->beginTransaction();
        $transferNo = next_named_document_no($pdo, 'material_transfers', 'transfer_no', setting_value($pdo, 'transfer_prefix', 'TR'));
        $pdo->prepare('INSERT INTO material_transfers (transfer_no, source_location_id, destination_location_id, requested_by, status, remarks) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$transferNo, $source, $destination, (int) current_user()['id'], 'submitted', trim((string) $_POST['remarks'])]);
        $transferId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO material_transfer_items (transfer_id, material_id, requested_qty, status, remarks) VALUES (?, ?, ?, ?, ?)');
        $lineCount = 0;
        $seenMaterials = [];
        foreach (($_POST['material_id'] ?? []) as $index => $materialId) {
            $qty = (float) ($_POST['requested_qty'][$index] ?? 0);
            if ((int) $materialId <= 0 || $qty <= 0) {
                continue;
            }
            $materialKey = (int) $materialId;
            if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Add each material only once per transfer.'); }
            $seenMaterials[$materialKey] = true;
            assert_operational_material($pdo, $materialKey);
            $insert->execute([$transferId, (int) $materialId, $qty, 'submitted', trim((string) ($_POST['item_remarks'][$index] ?? ''))]);
            $lineCount++;
        }
        if ($lineCount === 0) {
            throw new RuntimeException('Enter at least one transfer material.');
        }
        write_audit($pdo, (int) current_user()['id'], 'create', 'material_transfer', $transferId, 'Transfer submitted.');
        notify_role($pdo, 'warehouse_manager', 'Transfer ready for review', $transferNo . ' is ready for approval.', 'transfer_view.php?id=' . $transferId);
        $pdo->commit();
        flash('success', 'Transfer submitted.');
        redirect('transfer_view.php?id=' . $transferId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('transfer_create.php');
    }
}

$materials = inventory_options($pdo)['materials'];
$allLocations = $pdo->query("SELECT id, site_id, type, name AS label FROM locations WHERE is_active = 1 ORDER BY type, name")->fetchAll();
$locations = $allLocations;
if ((string) ($_SESSION['role'] ?? '') === 'site_engineer') {
    $siteId = current_user_site_id();
    $locations = array_values(array_filter($allLocations, static fn(array $row): bool => (int) ($row['site_id'] ?? 0) === (int) $siteId && (string) $row['type'] === 'site'));
}
$destinationLocations = $allLocations;
$activePage = 'transfers';
$title = 'New Transfer';
$eyebrow = 'INVENTORY TRANSFER';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">TRANSFER DETAILS</span><h2>Move materials between locations</h2></div></div><form method="post" data-block-on-submit="Submitting transfer"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-grid"><label>Source Location<select name="source_location_id"><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['label']) ?></option><?php endforeach; ?></select></label><label>Destination Location<select name="destination_location_id"><?php foreach ($destinationLocations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['label']) ?></option><?php endforeach; ?></select></label><label class="full">Remarks<textarea name="remarks"></textarea></label></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Qty</th><th>Remarks</th></tr></thead><tbody id="transfer-lines"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= (int)$material['id']===$preselectedMaterialId?'selected':'' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="requested_qty[]" required></td><td><input name="item_remarks[]"></td></tr></tbody></table></div><template id="transfer-row"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= (int)$material['id']===$preselectedMaterialId?'selected':'' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="requested_qty[]" required></td><td><input name="item_remarks[]"></td></tr></template><button class="btn secondary" type="button" data-add-row="#transfer-lines" data-template="#transfer-row">Add Material</button><div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Submit this transfer for review?" data-confirm-title="Submit transfer">Submit Transfer</button><a class="btn secondary" href="transfers.php">Back</a></div></form></section>
</div></main></div></body></html>
