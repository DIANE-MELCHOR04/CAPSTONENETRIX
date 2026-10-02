<?php
require_once __DIR__ . '/lib/returns.php';
require_permission('create_returns');
require_once __DIR__ . '/config/db.php';
$preselectedMaterialId = (int) ($_GET['material_id'] ?? 0);

$role = (string) ($_SESSION['role'] ?? '');
$userSiteId = current_user_site_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        $sourceSiteId = $role === 'site_engineer' ? ($userSiteId ?? 0) : (int) $_POST['source_site_id'];
        if ($sourceSiteId <= 0) { throw new RuntimeException('Your account must be assigned to a site before creating a return.'); }
        $returnNo = next_named_document_no($pdo, 'material_returns', 'return_no', setting_value($pdo, 'return_prefix', 'RET'));
        $pdo->prepare('INSERT INTO material_returns (return_no, source_site_id, receiving_location_id, returned_by, status, remarks) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$returnNo, $sourceSiteId, (int) $_POST['receiving_location_id'], (int) current_user()['id'], 'submitted', trim((string) $_POST['remarks'])]);
        $returnId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO material_return_items (return_id, material_id, returned_qty, condition_status, status, remarks) VALUES (?, ?, ?, ?, ?, ?)');
        $lineCount = 0;
        $seenMaterials = [];
        foreach (($_POST['material_id'] ?? []) as $index => $materialId) {
            $qty = (float) ($_POST['returned_qty'][$index] ?? 0);
            if ((int) $materialId <= 0 || $qty <= 0) {
                continue;
            }
            $materialKey = (int) $materialId;
            if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Add each material only once per return.'); }
            $seenMaterials[$materialKey] = true;
            assert_operational_material($pdo, $materialKey);
            $condition = (string) ($_POST['condition_status'][$index] ?? 'reusable');
            return_item_effect($condition);
            $insert->execute([$returnId, (int) $materialId, $qty, $condition, 'submitted', trim((string) ($_POST['item_remarks'][$index] ?? ''))]);
            $lineCount++;
        }
        if ($lineCount === 0) {
            throw new RuntimeException('Enter at least one returned material.');
        }
        write_audit($pdo, (int) current_user()['id'], 'create', 'material_return', $returnId, 'Material return submitted.');
        notify_role($pdo, 'warehouse_personnel', 'Material return ready for posting', $returnNo . ' is ready for warehouse receiving.', 'return_view.php?id=' . $returnId);
        $pdo->commit();
        flash('success', 'Material return submitted.');
        redirect('return_view.php?id=' . $returnId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('return_create.php');
    }
}

$materials = inventory_options($pdo)['materials'];
$sites = $role === 'site_engineer'
    ? (($userSiteId === null) ? [] : $pdo->query("SELECT id, name AS label FROM sites WHERE status = 'active' AND id = " . (int) $userSiteId)->fetchAll())
    : $pdo->query("SELECT id, name AS label FROM sites WHERE status = 'active' ORDER BY name")->fetchAll();
$locations = $pdo->query("SELECT id, name AS label FROM locations WHERE is_active = 1 AND type = 'warehouse' ORDER BY is_default DESC, name")->fetchAll();
$activePage = 'returns';
$title = 'New Material Return';
$eyebrow = 'RETURN CONTROL';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">RETURN DETAILS</span><h2>Record unused materials</h2></div></div><form method="post" data-block-on-submit="Submitting return"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-grid"><label>Source Site<select name="source_site_id"><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>"><?= e($site['label']) ?></option><?php endforeach; ?></select></label><label>Receiving Location<select name="receiving_location_id"><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['label']) ?></option><?php endforeach; ?></select></label><label class="full">Remarks<textarea name="remarks"></textarea></label></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Qty</th><th>Condition</th><th>Remarks</th></tr></thead><tbody id="return-lines"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= (int)$material['id']===$preselectedMaterialId?'selected':'' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="returned_qty[]" required></td><td><select name="condition_status[]"><?php foreach (['reusable','partially_damaged','damaged','for_inspection'] as $condition): ?><option value="<?= e($condition) ?>"><?= e(str_replace('_', ' ', ucfirst($condition))) ?></option><?php endforeach; ?></select></td><td><input name="item_remarks[]"></td></tr></tbody></table></div><template id="return-row"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= (int)$material['id']===$preselectedMaterialId?'selected':'' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="returned_qty[]" required></td><td><select name="condition_status[]"><?php foreach (['reusable','partially_damaged','damaged','for_inspection'] as $condition): ?><option value="<?= e($condition) ?>"><?= e(str_replace('_', ' ', ucfirst($condition))) ?></option><?php endforeach; ?></select></td><td><input name="item_remarks[]"></td></tr></template><button class="btn secondary" type="button" data-add-row="#return-lines" data-template="#return-row">Add Material</button><div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Submit this material return?" data-confirm-title="Submit return">Submit Return</button><a class="btn secondary" href="returns.php">Back</a></div></form></section>
</div></main></div></body></html>
