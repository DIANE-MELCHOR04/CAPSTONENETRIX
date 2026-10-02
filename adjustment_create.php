<?php
require_once __DIR__ . '/lib/adjustments.php';
require_permission('manage_adjustments');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $locationId = (int) $_POST['location_id'];
        $pdo->beginTransaction();
        $adjustmentNo = next_named_document_no($pdo, 'inventory_adjustments', 'adjustment_no', setting_value($pdo, 'adjustment_prefix', 'ADJ'));
        $pdo->prepare('INSERT INTO inventory_adjustments (adjustment_no, location_id, adjustment_type, requested_by, status, reason) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$adjustmentNo, $locationId, (string) $_POST['adjustment_type'], (int) current_user()['id'], 'submitted', trim((string) $_POST['reason'])]);
        $adjustmentId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO inventory_adjustment_items (adjustment_id, material_id, before_qty, adjustment_qty, after_qty, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $lineCount = 0;
        $seenMaterials = [];
        foreach (($_POST['material_id'] ?? []) as $index => $materialId) {
            $qty = (float) ($_POST['adjustment_qty'][$index] ?? 0);
            if ((int) $materialId <= 0 || $qty == 0.0) {
                continue;
            }
            $materialKey = (int) $materialId;
            if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Add each material only once per adjustment.'); }
            $seenMaterials[$materialKey] = true;
            assert_operational_material($pdo, $materialKey);
            $balance = ensure_inventory_balance($pdo, (int) $materialId, $locationId);
            $before = (float) $balance['quantity_on_hand'];
            $after = adjustment_after_quantity($before, $qty);
            $insert->execute([$adjustmentId, (int) $materialId, $before, $qty, $after, 'submitted', trim((string) ($_POST['item_remarks'][$index] ?? ''))]);
            $lineCount++;
        }
        if ($lineCount === 0) {
            throw new RuntimeException('Enter at least one adjustment quantity.');
        }
        write_audit($pdo, (int) current_user()['id'], 'create', 'inventory_adjustment', $adjustmentId, 'Inventory adjustment submitted.');
        notify_role($pdo, 'warehouse_manager', 'Adjustment ready for review', $adjustmentNo . ' needs approval.', 'adjustment_view.php?id=' . $adjustmentId);
        $pdo->commit();
        flash('success', 'Inventory adjustment submitted.');
        redirect('adjustment_view.php?id=' . $adjustmentId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('adjustment_create.php');
    }
}

$materials = inventory_options($pdo)['materials'];
$locations = $pdo->query("SELECT id, name AS label FROM locations WHERE is_active = 1 ORDER BY type, name")->fetchAll();
$activePage = 'adjustments';
$title = 'New Adjustment';
$eyebrow = 'BALANCE CONTROL';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">ADJUSTMENT DETAILS</span><h2>Prepare inventory correction</h2></div></div><form method="post" data-block-on-submit="Submitting adjustment"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-grid"><label>Location<select name="location_id"><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['label']) ?></option><?php endforeach; ?></select></label><label>Type<select name="adjustment_type"><?php foreach (['positive','negative','physical_count','encoding_correction','damage','loss','found_stock','other'] as $type): ?><option value="<?= e($type) ?>"><?= e(str_replace('_', ' ', ucfirst($type))) ?></option><?php endforeach; ?></select></label><label class="full">Reason<textarea name="reason" required></textarea></label></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Adjustment Qty</th><th>Remarks</th></tr></thead><tbody id="adjustment-lines"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" name="adjustment_qty[]" required></td><td><input name="item_remarks[]"></td></tr></tbody></table></div><template id="adjustment-row"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" name="adjustment_qty[]" required></td><td><input name="item_remarks[]"></td></tr></template><button class="btn secondary" type="button" data-add-row="#adjustment-lines" data-template="#adjustment-row">Add Material</button><div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Submit this inventory adjustment for approval?" data-confirm-title="Submit adjustment">Submit Adjustment</button><a class="btn secondary" href="adjustments.php">Back</a></div></form></section>
</div></main></div></body></html>
