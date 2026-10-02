<?php
require_once __DIR__ . '/lib/inventory.php';
require_once __DIR__ . '/lib/duplicate_guard.php';
require_permission('post_stock_in');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $qty = (float) ($_POST['quantity'] ?? 0);
    if ($qty <= 0) {
        flash('danger', 'Quantity must be greater than zero.');
        redirect('stock_in.php');
    }
    $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $confirmReferenceReuse = isset($_POST['confirm_reference_reuse']);
    if ($referenceNo !== '') {
        $remarks = trim('Reference: ' . $referenceNo . ($remarks !== '' ? ' / ' . $remarks : ''));
    }
    try {
        $pdo->beginTransaction();
        assert_manual_inventory_not_duplicate($pdo, 'stock_in', (int) $_POST['material_id'], (int) $_POST['location_id'], $qty, $referenceNo, $confirmReferenceReuse);
        post_inventory_movement($pdo, [
            'movement_type' => 'stock_in',
            'material_id' => (int) $_POST['material_id'],
            'location_id' => (int) $_POST['location_id'],
            'quantity_in' => $qty,
            'quantity_out' => 0,
            'reference_type' => 'stock_in',
            'remarks' => $remarks,
            'created_by' => (int) current_user()['id'],
        ]);
        $pdo->commit();
        flash('success', 'Stock-in posted and ledger updated.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
    }
    redirect('stock_in.php');
}

$options = inventory_options($pdo);
$activePage = 'stock_in';
$title = 'Record Stock-In';
$eyebrow = 'INVENTORY TRANSACTION';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content ops-guided-page">
<?= ui_page_intro('Inventory transaction', 'Record Stock-In', 'Post incoming material to a specific location with a traceable reference.') ?>
<section class="form-card ops-section ops-form-section"><form method="post" data-block-on-submit="Posting stock-in"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="ops-form-step-heading"><span>Receiving</span><h2>Incoming material details</h2><p>Confirm the material, destination, quantity, and source document before posting.</p></div><div class="form-grid"><label>Material<select name="material_id" required><?php foreach ($options['materials'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= e($row['label']) ?></option><?php endforeach; ?></select></label><label>Receiving Location<select name="location_id" required><?php foreach ($options['locations'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= e($row['label']) ?></option><?php endforeach; ?></select></label><label>Quantity<input type="number" min="0.01" step="0.01" name="quantity" required></label><label>Reference No.<input name="reference_no" placeholder="Delivery receipt, PO, or transfer no."><small>Exact repeated transactions with the same reference are blocked.</small></label><label class="full duplicate-confirm"><input type="checkbox" name="confirm_reference_reuse" value="1"> Confirm intentional reference reuse for a different material/line under the same document.</label><label class="full">Remarks<textarea name="remarks" placeholder="Supplier delivery, initial inventory, return, or adjustment context"></textarea></label></div><div class="form-actions ops-sticky-actions"><a class="btn secondary" href="movements.php">Movement History</a><button class="btn primary" type="submit" data-confirm="Post this stock-in to inventory?" data-confirm-title="Post stock-in">Post Stock-In</button></div></form></section>
<section class="form-card ops-section stock-import-panel"><div class="card-heading"><div><span class="eyebrow">BULK ENTRY</span><h2>Import Stock-In</h2><p>Use the template columns: material code, location code, quantity, reference, and remarks.</p></div><a class="btn secondary" href="inventory_import.php?action=template&amp;type=stock_in">Download Template</a></div><form class="stock-import-upload" action="inventory_import.php" method="post" enctype="multipart/form-data" data-block-on-submit="Validating spreadsheet"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="batch_type" value="stock_in"><label>Excel or CSV File<input type="file" name="import_file" accept=".xlsx,.csv" required><small>Maximum 5 MB and 1,000 data rows.</small></label><button class="btn primary" type="submit">Preview Import</button></form></section>
</div></main></div></body></html>
