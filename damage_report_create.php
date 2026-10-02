<?php
require_once __DIR__ . '/lib/damage.php';
require_any_permission(['manage_damage','report_site_damage']);
require_once __DIR__ . '/config/db.php';
$preselectedMaterialId = (int) ($_GET['material_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $locationId = (int) $_POST['location_id'];
        if ((string) ($_SESSION['role'] ?? '') === 'site_engineer') {
            $siteId = current_user_site_id();
            $scope = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE id=? AND site_id=? AND type='site' AND is_active=1");
            $scope->execute([$locationId, $siteId]);
            if ((int) $scope->fetchColumn() !== 1) { throw new RuntimeException('You may only report damage from your assigned project stockroom.'); }
        }
        $pdo->beginTransaction();
        $damageNo = next_named_document_no($pdo, 'damage_reports', 'damage_no', setting_value($pdo, 'damage_prefix', 'DMG'));
        $pdo->prepare('INSERT INTO damage_reports (damage_no, location_id, reported_by, status, reason) VALUES (?, ?, ?, ?, ?)')
            ->execute([$damageNo, $locationId, (int) current_user()['id'], 'submitted', trim((string) $_POST['reason'])]);
        $damageId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO damage_report_items (damage_report_id, material_id, damaged_qty, decision_qty, status, remarks) VALUES (?, ?, ?, ?, ?, ?)');
        $lineCount = 0;
        $seenMaterials = [];
        foreach (($_POST['material_id'] ?? []) as $index => $materialId) {
            $qty = (float) ($_POST['damaged_qty'][$index] ?? 0);
            if ((int) $materialId <= 0 || $qty <= 0) {
                continue;
            }
            $materialKey = (int) $materialId;
            if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Add each material only once per damage report.'); }
            $seenMaterials[$materialKey] = true;
            assert_operational_material($pdo, $materialKey);
            $insert->execute([$damageId, (int) $materialId, $qty, $qty, 'submitted', trim((string) ($_POST['item_remarks'][$index] ?? ''))]);
            $lineCount++;
        }
        if ($lineCount === 0) {
            throw new RuntimeException('Enter at least one damaged material.');
        }
        write_audit($pdo, (int) current_user()['id'], 'create', 'damage_report', $damageId, 'Damage report submitted.');
        notify_role($pdo, 'warehouse_manager', 'Damage report ready for review', $damageNo . ' needs approval.', 'damage_report_view.php?id=' . $damageId);
        $pdo->commit();
        flash('success', 'Damage report submitted.');
        redirect('damage_report_view.php?id=' . $damageId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('damage_report_create.php');
    }
}

$materials = inventory_options($pdo)['materials'];
$locations = $pdo->query("SELECT id, site_id, type, name AS label FROM locations WHERE is_active = 1 ORDER BY type, name")->fetchAll();
if ((string) ($_SESSION['role'] ?? '') === 'site_engineer') { $siteId=current_user_site_id(); $locations=array_values(array_filter($locations, static fn(array $r): bool => (int)($r['site_id']??0)===(int)$siteId && (string)$r['type']==='site')); }
$activePage = 'damage_reports';
$title = 'New Damage Report';
$eyebrow = 'DAMAGE CONTROL';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">DAMAGE DETAILS</span><h2>Report damaged materials</h2></div></div><form method="post" data-block-on-submit="Submitting damage report"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-grid"><label>Location<select name="location_id"><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>"><?= e($location['label']) ?></option><?php endforeach; ?></select></label><label class="full">Reason<textarea name="reason" required></textarea></label></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Damaged Qty</th><th>Remarks</th></tr></thead><tbody id="damage-lines"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= (int)$material['id']===$preselectedMaterialId?'selected':'' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="damaged_qty[]" required></td><td><input name="item_remarks[]"></td></tr></tbody></table></div><template id="damage-row"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= (int)$material['id']===$preselectedMaterialId?'selected':'' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="damaged_qty[]" required></td><td><input name="item_remarks[]"></td></tr></template><button class="btn secondary" type="button" data-add-row="#damage-lines" data-template="#damage-row">Add Material</button><div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Submit this damage report?" data-confirm-title="Submit damage report">Submit Report</button><a class="btn secondary" href="damage_reports.php">Back</a></div></form></section>
</div></main></div></body></html>
