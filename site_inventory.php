<?php
require_once __DIR__ . '/lib/flow_upgrade.php';
require_permission('manage_site_inventory');
require_once __DIR__ . '/config/db.php';

$siteId = current_user_site_id();
if ($siteId === null) {
    http_response_code(403);
    die('Your account is not assigned to a project site.');
}
$locationId = site_stock_location_id($pdo, $siteId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $materialId = (int) ($_POST['material_id'] ?? 0);
        $qty = (float) ($_POST['quantity'] ?? 0);
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($qty <= 0) throw new RuntimeException('Consumption quantity must be greater than zero.');
        $pdo->beginTransaction();
        $balance = ensure_inventory_balance($pdo, $materialId, $locationId);
        $available = available_stock((float) $balance['quantity_on_hand'], (float) $balance['reserved_quantity'], (float) $balance['damaged_quantity']);
        if ($qty > $available) throw new RuntimeException('Consumption quantity exceeds available site stock.');
        post_inventory_movement($pdo, [
            'movement_type' => 'stock_out',
            'material_id' => $materialId,
            'location_id' => $locationId,
            'quantity_in' => 0,
            'quantity_out' => $qty,
            'reference_type' => 'site_consumption',
            'reference_id' => $siteId,
            'remarks' => $remarks !== '' ? $remarks : 'Project-site material consumption',
            'created_by' => (int) current_user()['id'],
        ]);
        write_audit($pdo, (int) current_user()['id'], 'consume', 'site_inventory', $siteId, 'Site material consumed.');
        $pdo->commit();
        flash('success', 'Site consumption posted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
    redirect('site_inventory.php');
}

$siteStmt = $pdo->prepare('SELECT name FROM sites WHERE id=?');
$siteStmt->execute([$siteId]);
$siteName = (string) $siteStmt->fetchColumn();
$stmt = $pdo->prepare("SELECT b.*,m.material_code,m.material_name,u.name AS unit_name, GREATEST(b.quantity_on_hand-b.reserved_quantity-b.damaged_quantity,0) AS available_qty FROM inventory_balances b JOIN materials m ON m.id=b.material_id JOIN units u ON u.id=m.unit_id WHERE b.location_id=? AND (b.quantity_on_hand<>0 OR b.reserved_quantity<>0 OR b.damaged_quantity<>0) ORDER BY m.material_name");
$stmt->execute([$locationId]);
$rows = $stmt->fetchAll();
$movementStmt = $pdo->prepare("SELECT im.*,m.material_code,m.material_name,u.name AS unit_name FROM inventory_movements im JOIN materials m ON m.id=im.material_id JOIN units u ON u.id=m.unit_id WHERE im.location_id=? ORDER BY im.created_at DESC, im.id DESC LIMIT 25");
$movementStmt->execute([$locationId]);
$recentSiteMovements = $movementStmt->fetchAll();

$activePage='site_inventory'; $title='Site Inventory'; $eyebrow='PROJECT STOCK';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="monitoring-top-card"><div><span class="eyebrow light">SITE INVENTORY</span><h2><?= e($siteName) ?></h2><p>Materials confirmed as received from warehouse releases are held here until consumed, returned, transferred, or reported damaged.</p></div><div class="hero-actions"><a class="btn glass" href="return_create.php">Return Materials</a><a class="btn glass" href="transfer_create.php">Transfer Materials</a><a class="btn glass" href="damage_report_create.php">Report Damage</a></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">ON SITE</span><h2>Current project stock</h2></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Material</th><th>On Hand</th><th>Available</th><th>Damaged</th><th>Unit</th><th>Actions</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="6" class="muted">No project-site stock has been received yet.</td></tr><?php endif; ?><?php foreach($rows as $row): ?><tr><td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td><td><?= money_or_qty($row['quantity_on_hand']) ?></td><td><?= money_or_qty($row['available_qty']) ?></td><td><?= money_or_qty($row['damaged_quantity']) ?></td><td><?= e($row['unit_name']) ?></td><td><a class="table-action" href="return_create.php?material_id=<?= (int)$row['material_id'] ?>">Return</a> <a class="table-action" href="transfer_create.php?material_id=<?= (int)$row['material_id'] ?>">Transfer</a> <a class="table-action" href="damage_report_create.php?material_id=<?= (int)$row['material_id'] ?>">Damage</a></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">LEDGER</span><h2>Recent Site Inventory Movements</h2><p class="muted">Receipt, consumption, return, transfer, and damage activity affecting this project stockroom.</p></div></div><div class="table-wrap"><table class="data modern-table" data-sortable-table><thead><tr><th>Date</th><th>Material</th><th>Movement</th><th>In</th><th>Out</th><th>Remarks</th></tr></thead><tbody><?php if(!$recentSiteMovements): ?><tr><td colspan="6" class="muted">No site inventory movements yet.</td></tr><?php endif; ?><?php foreach($recentSiteMovements as $movement): ?><tr><td><?= e(date('M d, Y h:i A',strtotime($movement['created_at']))) ?></td><td><b><?= e($movement['material_code']) ?></b><small><?= e($movement['material_name']) ?></small></td><td><?= ui_status_badge((string)$movement['movement_type']) ?></td><td><?= money_or_qty($movement['quantity_in']) ?> <?= e($movement['unit_name']) ?></td><td><?= money_or_qty($movement['quantity_out']) ?> <?= e($movement['unit_name']) ?></td><td><?= e($movement['remarks'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">CONSUMPTION</span><h2>Record materials used at the project</h2><p>This deducts only from your assigned project stockroom.</p></div></div><form method="post" data-block-on-submit="Posting site consumption"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="form-grid"><label>Material<select name="material_id" required><?php foreach($rows as $row): ?><option value="<?= (int)$row['material_id'] ?>"><?= e($row['material_code'].' - '.$row['material_name']) ?> (<?= money_or_qty($row['available_qty']) ?> available)</option><?php endforeach; ?></select></label><label>Quantity Used<input type="number" min="0.01" step="0.01" name="quantity" required></label><label class="full">Usage / Work Reference<textarea name="remarks" placeholder="e.g. Column works - Grid A3"></textarea></label></div><div class="form-actions"><button class="btn primary" type="submit" <?= !$rows?'disabled':'' ?>>Post Consumption</button></div></form></section>
</div></main></div></body></html>
