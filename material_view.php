<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/materials.php';
require_any_permission(['create_materials', 'manage_master_data', 'review_materials']);
require_once __DIR__ . '/config/db.php';

$user = current_user();
$role = (string) $user['role'];
$materialId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$material = $materialId > 0 ? material_by_id($pdo, $materialId) : null;
if (!$material) {
    http_response_code(404);
    die('Material record not found.');
}

$canSeeAll = in_array($role, ['admin', 'warehouse_manager'], true);
$isSubmitter = (int) ($material['submitted_by'] ?? 0) === (int) $user['id'];
if (!$canSeeAll && $material['approval_status'] !== 'approved' && !$isSubmitter) {
    http_response_code(403);
    die('You do not have permission to view this material.');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (in_array($action, ['approve', 'reject'], true)) {
            if (!role_can($role, 'review_materials')) {
                throw new DomainException('You do not have permission to review materials.');
            }
            review_material($pdo, $materialId, (int) $user['id'], $action === 'approve' ? 'approved' : 'rejected', (string) ($_POST['review_remarks'] ?? ''));
            flash('success', $action === 'approve' ? 'Material approved and activated.' : 'Material rejected with review remarks.');
        } elseif ($action === 'resubmit') {
            resubmit_material($pdo, $materialId, $_POST, (int) $user['id'], isset($_POST['confirm_possible_duplicate']));
            flash('success', 'Material corrections submitted for review.');
        } elseif ($action === 'update') {
            if (!role_can($role, 'manage_master_data')) {
                throw new DomainException('You do not have permission to maintain approved materials.');
            }
            update_approved_material($pdo, $materialId, $_POST, (int) $user['id'], isset($_POST['is_active']));
            flash('success', 'Material master updated.');
        } else {
            throw new InvalidArgumentException('Unsupported material action.');
        }
        redirect('material_view.php?id=' . $materialId);
    } catch (InvalidArgumentException | DomainException $error) {
        $errors[] = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Material action failed: ' . $error->getMessage());
        $errors[] = 'The material action could not be completed. Please try again.';
    }
    $material = material_by_id($pdo, $materialId) ?? $material;
}

$categories = active_options($pdo, 'material_categories');
$units = active_options($pdo, 'units');
$suppliers = active_options($pdo, 'suppliers', 'company');
$canReview = role_can($role, 'review_materials') && $material['approval_status'] === 'pending';
$canResubmit = $isSubmitter && $material['approval_status'] === 'rejected';
$canUpdate = role_can($role, 'manage_master_data') && $material['approval_status'] === 'approved';
$showEditForm = $canResubmit || $canUpdate;
$activePage = 'materials';
$title = (string) $material['material_code'];
$eyebrow = 'MATERIAL RECORD';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body>
<div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content ops-material-record">
<?php if ($errors): ?><div class="alert danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<section class="monitoring-top-card material-record-hero">
    <div class="material-record-hero-copy">
        <span class="eyebrow light">MATERIAL RECORD</span>
        <h2><?= e($material['material_name']) ?></h2>
        <p class="material-code-kicker"><?= e($material['material_code']) ?> · <?= e($material['unit_name']) ?></p>
        <p><?= e($material['description'] ?: 'Permanent catalog identity, stock policy, and approval history.') ?></p>
    </div>
    <div class="material-record-hero-actions">
        <span class="workflow-badge <?= e($material['approval_status']) ?>"><?= e(ucfirst($material['approval_status'])) ?></span>
        <span class="material-activity-state <?= (int) $material['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>"><?= (int) $material['is_active'] === 1 ? 'Active for operations' : 'Inactive' ?></span>
        <a class="btn glass" href="materials.php">Back to Materials</a>
    </div>
</section>

<section class="content-card material-detail-card">
    <div class="card-heading material-detail-heading"><div><span class="eyebrow">MASTER DATA</span><h2>Material Details</h2><p>Core identity, replenishment settings, and ownership at a glance.</p></div></div>
    <div class="material-detail-sections">
        <article class="material-detail-group">
            <div class="material-detail-group-head"><span>01</span><h3>Identity</h3></div>
            <dl class="material-definition-list">
                <div><dt>Permanent Code</dt><dd class="material-code-value"><?= e($material['material_code']) ?></dd></div>
                <div><dt>Category</dt><dd><?= e($material['category_name'] ?? 'Uncategorized') ?></dd></div>
                <div><dt>Unit</dt><dd><?= e($material['unit_name']) ?></dd></div>
                <div><dt>Brand / Specification</dt><dd><?= e($material['brand_spec'] ?: 'Not specified') ?></dd></div>
                <div><dt>Preferred Supplier</dt><dd><?= e($material['supplier_name'] ?: 'Not assigned') ?></dd></div>
            </dl>
        </article>

        <article class="material-detail-group">
            <div class="material-detail-group-head"><span>02</span><h3>Stock Policy</h3></div>
            <dl class="material-definition-list material-policy-list">
                <div><dt>Minimum Stock</dt><dd><?= e(number_format((float) $material['min_stock'], 2)) ?></dd></div>
                <div><dt>Reorder Point</dt><dd><?= e(number_format((float) $material['reorder_point'], 2)) ?></dd></div>
                <div><dt>Safety Stock</dt><dd><?= e(number_format((float) $material['safety_stock'], 2)) ?></dd></div>
                <div><dt>Maximum Stock</dt><dd><?= e(number_format((float) $material['max_stock'], 2)) ?></dd></div>
                <div><dt>Lead Time</dt><dd><?= (int) $material['lead_time_days'] ?> days</dd></div>
            </dl>
        </article>

        <article class="material-detail-group">
            <div class="material-detail-group-head"><span>03</span><h3>Governance</h3></div>
            <dl class="material-definition-list">
                <div><dt>Submitted By</dt><dd><?= e($material['submitted_by_name'] ?? 'Existing record') ?></dd></div>
                <div><dt>Reviewed By</dt><dd><?= e($material['reviewed_by_name'] ?? 'Not reviewed') ?></dd></div>
                <div><dt>Approval Status</dt><dd><?= e(ucfirst((string) $material['approval_status'])) ?></dd></div>
                <div><dt>Operational State</dt><dd><?= (int) $material['is_active'] === 1 ? 'Active' : 'Inactive' ?></dd></div>
                <div><dt>Review Remarks</dt><dd><?= e($material['review_remarks'] ?: 'No remarks recorded') ?></dd></div>
            </dl>
        </article>
    </div>
</section>

<?php if ($canReview): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow">REVIEW</span><h2>Approval Decision</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $materialId ?>"><label class="full">Review Remarks<textarea name="review_remarks" placeholder="Required when rejecting"></textarea></label><div class="form-actions full"><button class="btn primary" name="action" value="approve" data-confirm="Approve and activate this material?" data-confirm-title="Approve material">Approve</button><button class="btn danger" name="action" value="reject" data-confirm="Reject this submission? Review remarks are required." data-confirm-title="Reject material">Reject</button></div></form></section><?php endif; ?>

<?php if ($showEditForm): ?><section class="form-card"><div class="card-heading"><div><span class="eyebrow"><?= $canResubmit ? 'CORRECTION' : 'MAINTENANCE' ?></span><h2><?= $canResubmit ? 'Correct And Resubmit' : 'Update Approved Material' ?></h2></div><span class="workflow-badge pending">Code generated automatically</span></div>
<form method="post" class="form-grid three" data-block-on-submit="Saving material"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $materialId ?>"><input type="hidden" name="action" value="<?= $canResubmit ? 'resubmit' : 'update' ?>">
<label>Material Name<input name="material_name" value="<?= e($material['material_name']) ?>" required></label><label>Unit<select name="unit_id" required><?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= (int) $material['unit_id'] === (int) $unit['id'] ? 'selected' : '' ?>><?= e($unit['label']) ?></option><?php endforeach; ?></select></label><label>Category<select name="category_id"><option value="">No category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $material['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['label']) ?></option><?php endforeach; ?></select></label>
<label>Brand / Specification<input name="brand_spec" value="<?= e($material['brand_spec']) ?>"></label><label>Preferred Supplier<select name="preferred_supplier_id"><option value="">No supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= (int) $material['preferred_supplier_id'] === (int) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['label']) ?></option><?php endforeach; ?></select></label>
<label>Minimum Stock<input type="number" step="0.01" min="0" name="min_stock" value="<?= e($material['min_stock']) ?>"></label><label>Maximum Stock<input type="number" step="0.01" min="0" name="max_stock" value="<?= e($material['max_stock']) ?>"></label><label>Reorder Point<input type="number" step="0.01" min="0" name="reorder_point" value="<?= e($material['reorder_point']) ?>"></label><label>Safety Stock<input type="number" step="0.01" min="0" name="safety_stock" value="<?= e($material['safety_stock']) ?>"></label><label>Lead Time Days<input type="number" min="0" name="lead_time_days" value="<?= e($material['lead_time_days']) ?>"></label><label class="full">Description<textarea name="description"><?= e($material['description']) ?></textarea></label>
<?php if ($canResubmit): ?><label class="full"><input type="checkbox" name="confirm_possible_duplicate" value="1"> Confirm this remains a separate material variant if another material has the same name.</label><?php else: ?><label><input type="checkbox" name="is_active" value="1" <?= (int) $material['is_active'] === 1 ? 'checked' : '' ?>> Active for operational use</label><?php endif; ?>
<div class="form-actions full"><button class="btn primary" type="submit" data-confirm="Save these material changes?" data-confirm-title="Save material"><?= $canResubmit ? 'Resubmit Material' : 'Save Changes' ?></button></div></form></section><?php endif; ?>

</div></main></div></body></html>
