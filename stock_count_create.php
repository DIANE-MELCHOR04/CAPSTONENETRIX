<?php
require_once __DIR__ . '/lib/adjustments.php';
require_permission('manage_stock_counts');
require_once __DIR__ . '/config/db.php';
$recountOfId = (int) ($_GET['recount_of'] ?? $_POST['recount_of'] ?? 0);
$recountSource = null;
if ($recountOfId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM stock_counts WHERE id=? AND status='rejected'"); $stmt->execute([$recountOfId]); $recountSource=$stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $locationId = (int) $_POST['location_id'];
        if ($recountOfId > 0 && (!$recountSource || (int) $recountSource['location_id'] !== $locationId)) { throw new RuntimeException('Recount location must match the rejected stock count.'); }
        $pdo->beginTransaction();
        $countNo = next_named_document_no($pdo, 'stock_counts', 'count_no', setting_value($pdo, 'stock_count_prefix', 'SC'));
        $pdo->prepare('INSERT INTO stock_counts (count_no, location_id, recount_of_id, counted_by, status, remarks) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$countNo, $locationId, $recountOfId > 0 ? $recountOfId : null, (int) current_user()['id'], 'submitted', trim((string) $_POST['remarks'])]);
        $countId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO stock_count_items (stock_count_id, material_id, system_qty, counted_qty, variance_qty, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $lineCount = 0;
        $seenMaterials = [];
        foreach (($_POST['material_id'] ?? []) as $index => $materialId) {
            $counted = (float) ($_POST['counted_qty'][$index] ?? 0);
            if ((int) $materialId <= 0 || $counted < 0) {
                continue;
            }
            $materialKey = (int) $materialId;
            if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Count each material only once per stock count.'); }
            $seenMaterials[$materialKey] = true;
            assert_operational_material($pdo, $materialKey);
            $balance = ensure_inventory_balance($pdo, (int) $materialId, $locationId);
            $system = (float) $balance['quantity_on_hand'];
            $variance = stock_count_variance($system, $counted);
            $insert->execute([$countId, (int) $materialId, $system, $counted, $variance, 'submitted', trim((string) ($_POST['item_remarks'][$index] ?? ''))]);
            $lineCount++;
        }
        if ($lineCount === 0) {
            throw new RuntimeException('Enter at least one counted material.');
        }
        write_audit($pdo, (int) current_user()['id'], 'create', 'stock_count', $countId, 'Stock count submitted.');
        notify_role($pdo, 'warehouse_manager', 'Stock count ready for review', $countNo . ' needs approval.', 'stock_count_view.php?id=' . $countId);
        $pdo->commit();
        flash('success', 'Stock count submitted.');
        redirect('stock_count_view.php?id=' . $countId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('stock_count_create.php');
    }
}

$materials = inventory_options($pdo)['materials'];
$locations = $pdo->query("SELECT id, name AS label FROM locations WHERE is_active = 1 ORDER BY type, name")->fetchAll();
$activePage = 'stock_counts';
$title = 'New Stock Count';
$eyebrow = 'PHYSICAL COUNT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">COUNT DETAILS</span><h2>Record physical count</h2></div></div><form method="post" data-block-on-submit="Submitting stock count"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="recount_of" value="<?= (int)$recountOfId ?>"><div class="form-grid"><label>Location<select name="location_id"><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $recountSource && (int)$recountSource['location_id']===(int)$location['id'] ? 'selected' : '' ?>><?= e($location['label']) ?></option><?php endforeach; ?></select></label><label class="full">Remarks<textarea name="remarks"></textarea></label></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Counted Qty</th><th>Remarks</th></tr></thead><tbody id="stock-count-lines"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="counted_qty[]" required></td><td><input name="item_remarks[]"></td></tr></tbody></table></div><template id="stock-count-row"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0" name="counted_qty[]" required></td><td><input name="item_remarks[]"></td></tr></template><button class="btn secondary" type="button" data-add-row="#stock-count-lines" data-template="#stock-count-row">Add Material</button><div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Submit this stock count for review?" data-confirm-title="Submit stock count">Submit Count</button><a class="btn secondary" href="stock_counts.php">Back</a></div></form></section>
</div></main></div></body></html>
