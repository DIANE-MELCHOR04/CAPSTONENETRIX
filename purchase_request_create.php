<?php
require_once __DIR__ . '/lib/procurement.php';
require_permission('manage_procurement');
require_once __DIR__ . '/config/db.php';

$requestId = (int) ($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$recommendationId = (int) ($_GET['recommendation_id'] ?? $_POST['recommendation_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        $sourceItemIds = array_map('intval', $_POST['source_item_id'] ?? []);
        $sourceItemIds = array_values(array_unique(array_filter($sourceItemIds)));
        if ($sourceItemIds) {
            $purchaseRequestId = create_purchase_request_from_shortages($pdo, $sourceItemIds, (int) current_user()['id']);
        } elseif ($requestId > 0) {
            throw new RuntimeException('Select at least one shortage that is not already under procurement.');
        } else {
            $prNo = next_named_document_no($pdo, 'purchase_requests', 'pr_no', setting_value($pdo, 'purchase_request_prefix', 'PR'));
            $pdo->prepare('INSERT INTO purchase_requests (pr_no, requested_by, needed_by, priority, source_type, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$prNo, (int) current_user()['id'], $_POST['needed_by'] ?: null, (string) $_POST['priority'], 'manual', 'submitted', trim((string) $_POST['remarks'])]);
            $purchaseRequestId = (int) $pdo->lastInsertId();
            $seenMaterials = [];
            $insert = $pdo->prepare('INSERT INTO purchase_request_items (purchase_request_id, material_id, requested_qty, source_type, status, remarks) VALUES (?, ?, ?, ?, ?, ?)');
            foreach (($_POST['material_id'] ?? []) as $index => $materialId) {
                $qty = (float) ($_POST['requested_qty'][$index] ?? 0);
                if ((int) $materialId <= 0 || $qty <= 0) {
                    continue;
                }
                $materialKey = (int) $materialId;
                if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Add each material only once per purchase request.'); }
                $seenMaterials[$materialKey] = true;
                assert_operational_material($pdo, $materialKey);
                $insert->execute([$purchaseRequestId, (int) $materialId, $qty, 'manual', 'submitted', trim((string) ($_POST['item_remarks'][$index] ?? ''))]);
            }
            if ($recommendationId > 0) {
                link_ai_recommendation_to_purchase_request($pdo, $recommendationId, $purchaseRequestId, (int) current_user()['id']);
            }
            write_audit($pdo, (int) current_user()['id'], 'create', 'purchase_request', $purchaseRequestId, 'Purchase request submitted.');
            notify_role($pdo, 'president', 'Purchase request ready for approval', $prNo . ' is awaiting executive approval.', 'purchase_request_view.php?id=' . $purchaseRequestId);
        }
        $pdo->commit();
        flash('success', 'Purchase request submitted.');
        redirect('purchase_request_view.php?id=' . $purchaseRequestId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $exception->getMessage());
        redirect('purchase_request_create.php' . ($recommendationId > 0 ? '?recommendation_id=' . $recommendationId : ''));
    }
}

$materials = $pdo->query("SELECT m.id, CONCAT(m.material_code, ' - ', m.material_name, ' (', u.name, ')') AS label FROM materials m JOIN units u ON u.id = m.unit_id WHERE m.is_active = 1 AND m.approval_status = 'approved' ORDER BY m.material_name")->fetchAll();
$recommendation = null;
$prefillMaterialId = 0;
$prefillQuantity = '';
$prefillRemarks = '';
if ($recommendationId > 0) {
    $recommendationStmt = $pdo->prepare(
        "SELECT ar.*, fi.recommended_order_qty, fi.forecast_qty, m.material_code, m.material_name,
                pr.id AS linked_request_id, pr.pr_no AS linked_request_no
         FROM ai_recommendations ar
         LEFT JOIN forecast_items fi ON fi.id = ar.forecast_item_id
         JOIN materials m ON m.id = ar.material_id
         LEFT JOIN purchase_requests pr ON pr.ai_recommendation_id = ar.id
         WHERE ar.id = ?"
    );
    $recommendationStmt->execute([$recommendationId]);
    $recommendation = $recommendationStmt->fetch();
    if (!$recommendation) {
        flash('danger', 'AI recommendation was not found.');
        redirect('ai_recommendations.php');
    }
    if (!empty($recommendation['linked_request_id'])) {
        flash('info', 'This recommendation is already linked to ' . (string) $recommendation['linked_request_no'] . '.');
        redirect('purchase_request_view.php?id=' . (int) $recommendation['linked_request_id']);
    }
    $prefillMaterialId = (int) $recommendation['material_id'];
    $recommendedQty = (float) ($recommendation['recommended_order_qty'] ?? 0);
    $prefillQuantity = $recommendedQty > 0 ? (string) $recommendedQty : '';
    $prefillRemarks = 'Converted from AI recommendation #' . $recommendationId . ': ' . (string) $recommendation['title'] . "\n" . (string) $recommendation['reason'];
}
$shortages = [];
$shortageProcurementLinks = [];
if ($requestId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.*, m.material_code, m.material_name, u.name AS unit_name
         FROM material_request_items i
         JOIN materials m ON m.id = i.material_id
         JOIN units u ON u.id = m.unit_id
         WHERE i.request_id = ? AND i.shortage_qty > 0"
    );
    $stmt->execute([$requestId]);
    $shortages = $stmt->fetchAll();
    $shortageProcurementLinks = active_procurement_links_for_request_items(
        $pdo,
        array_map(static fn(array $row): int => (int) $row['id'], $shortages)
    );
}
$availableShortageCount = count(array_filter($shortages, static fn(array $row): bool => !isset($shortageProcurementLinks[(int) $row['id']])));
$activePage = 'purchase_requests';
$title = 'New Purchase Request';
$eyebrow = 'PROCUREMENT';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow"><?= $recommendation ? 'AI CONVERSION' : 'REQUEST DETAILS' ?></span><h2><?= $recommendation ? 'Review AI recommendation before submission' : 'Prepare materials for purchase' ?></h2><?php if ($recommendation): ?><p><?= e($recommendation['material_code']) ?> — <?= e($recommendation['title']) ?>. Adjust any field before submitting.</p><?php endif; ?></div></div><form method="post" data-block-on-submit="Saving purchase request"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?php if ($requestId > 0): ?><input type="hidden" name="request_id" value="<?= (int) $requestId ?>"><?php endif; ?><?php if ($recommendationId > 0): ?><input type="hidden" name="recommendation_id" value="<?= (int) $recommendationId ?>"><?php endif; ?><div class="form-grid"><label>Needed By<input type="date" name="needed_by"></label><label>Priority<select name="priority"><?php foreach (['low','normal','high','urgent'] as $priority): ?><option value="<?= e($priority) ?>" <?= $recommendation && in_array($recommendation['urgency'], ['critical','high'], true) && $priority === 'urgent' ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option><?php endforeach; ?></select></label><label class="full">Remarks<textarea name="remarks"><?= e($prefillRemarks) ?></textarea></label></div>
<?php if ($shortages): ?><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Use</th><th>Material</th><th>Shortage</th><th>Status</th></tr></thead><tbody><?php foreach ($shortages as $row): ?><?php $existingLink = $shortageProcurementLinks[(int) $row['id']] ?? null; ?><tr><td><?php if ($existingLink): ?><input type="checkbox" disabled title="Already under procurement"><?php else: ?><input type="checkbox" name="source_item_id[]" value="<?= (int) $row['id'] ?>" checked><?php endif; ?></td><td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td><td><?= money_or_qty($row['shortage_qty']) ?> <?= e($row['unit_name']) ?></td><td><?php if ($existingLink): ?><?= ui_status_badge('for_procurement', 'Procurement In Progress — ' . (string) $existingLink['pr_no']) ?><?php else: ?><?= ui_status_badge('for_procurement') ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php if ($availableShortageCount === 0): ?><div class="callout info" style="margin-top:14px">All remaining shortages in this Material Request already have an active Purchase Request. Duplicate procurement is blocked.</div><?php endif; ?><?php else: ?><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Material</th><th>Qty</th><th>Remarks</th></tr></thead><tbody id="purchase-request-lines"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= $prefillMaterialId === (int) $material['id'] ? 'selected' : '' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="requested_qty[]" value="<?= e($prefillQuantity) ?>" required></td><td><input name="item_remarks[]" value="<?= $recommendation ? e('AI recommendation #' . $recommendationId) : '' ?>"></td></tr></tbody></table></div><template id="purchase-request-row"><tr><td><select name="material_id[]"><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="requested_qty[]" required></td><td><input name="item_remarks[]"></td></tr></template><button class="btn secondary" type="button" data-add-row="#purchase-request-lines" data-template="#purchase-request-row">Add Material</button><?php endif; ?>
<div class="form-actions" style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="Submit this purchase request?" data-confirm-title="Submit purchase request" <?= $requestId > 0 && $shortages && $availableShortageCount === 0 ? 'disabled' : '' ?>>Submit</button><a class="btn secondary" href="purchase_requests.php">Back</a></div></form></section>
</div></main></div></body></html>
