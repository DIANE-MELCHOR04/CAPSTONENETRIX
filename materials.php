<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/materials.php';
require_once __DIR__ . '/lib/search_filters.php';
require_any_permission(['create_materials', 'manage_master_data', 'review_materials']);
require_once __DIR__ . '/config/db.php';

$user = current_user();
$role = (string) $user['role'];
$errors = [];
$old = $_POST;
$possibleDuplicate = false;
$canCreateMaterials = role_can($role, 'create_materials');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canCreateMaterials) {
        http_response_code(403);
        die('You do not have permission to submit materials.');
    }

    try {
        $created = create_material($pdo, $_POST, (int) $user['id'], $role, isset($_POST['confirm_possible_duplicate']));
        $message = $created['status'] === 'pending'
            ? $created['code'] . ' submitted and is pending approval.'
            : $created['code'] . ' created and is ready for use.';
        flash('success', $message);
        redirect('material_view.php?id=' . (int) $created['id']);
    } catch (InvalidArgumentException | DomainException $error) {
        $errors[] = $error->getMessage();
        $possibleDuplicate = str_contains($error->getMessage(), 'same name');
    } catch (Throwable $error) {
        error_log('Material creation failed: ' . $error->getMessage());
        $errors[] = 'The material could not be saved. Please try again or contact the system administrator.';
    }
}

$categories = active_options($pdo, 'material_categories');
$units = active_options($pdo, 'units');
$suppliers = active_options($pdo, 'suppliers', 'company');
$q = filter_text($_GET, 'q');
$approvalStatus = filter_choice($_GET, 'approval_status', ['approved', 'pending', 'rejected']);
$statusFilter = bool_filter_value($_GET, 'status');
$categoryId = filter_int_choice($_GET, 'category_id');
$unitId = filter_int_choice($_GET, 'unit_id');
$supplierId = filter_int_choice($_GET, 'supplier_id');

$visibility = material_visibility_clause($role, (int) $user['id']);
$where = [$visibility['sql']];
$params = $visibility['params'];
filter_where_like($where, $params, ['m.material_code', 'm.material_name', 'm.brand_spec', 'm.description', 'c.name', 'u.name', 's.company', 'm.approval_status'], $q);
filter_where_equals($where, $params, 'm.approval_status', $approvalStatus);
filter_where_equals($where, $params, 'm.category_id', $categoryId);
filter_where_equals($where, $params, 'm.unit_id', $unitId);
filter_where_equals($where, $params, 'm.preferred_supplier_id', $supplierId);
$activeValue = filter_bool_condition($statusFilter);
if ($activeValue !== null) {
    $where[] = 'm.is_active = ?';
    $params[] = $activeValue;
}

$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT m.*, c.name AS category_name, u.name AS unit_name, s.company AS supplier_name, submitter.full_name AS submitted_by_name
     FROM materials m
     LEFT JOIN material_categories c ON c.id = m.category_id
     JOIN units u ON u.id = m.unit_id
     LEFT JOIN suppliers s ON s.id = m.preferred_supplier_id
     LEFT JOIN users submitter ON submitter.id = m.submitted_by
     {$whereSql}
     ORDER BY FIELD(m.approval_status, 'pending', 'approved', 'rejected'), m.material_name"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$activePage = 'materials';
$title = 'Material Master';
$eyebrow = 'MASTER DATA';
$submitLabel = $role === 'warehouse_personnel' ? 'Submit Material' : 'Create Material';

function material_form_value(array $old, string $key, string $default = ''): string
{
    return (string) ($old[$key] ?? $default);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body>
<div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('materials') ?>

<?php if ($errors): ?><div class="alert danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<?php if ($canCreateMaterials): ?><section class="form-card">
    <div class="card-heading"><div><span class="eyebrow">CATALOG</span><h2><?= e($submitLabel) ?></h2></div><span class="workflow-badge pending">Code generated automatically</span></div>
    <form method="post" class="form-grid three" data-block-on-submit="Saving material">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Material Name<input name="material_name" value="<?= e(material_form_value($old, 'material_name')) ?>" required></label>
        <label>Unit<select name="unit_id" required><option value="">Select unit</option><?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= material_form_value($old, 'unit_id') === (string) $unit['id'] ? 'selected' : '' ?>><?= e($unit['label']) ?></option><?php endforeach; ?></select></label>
        <label>Category<select name="category_id"><option value="">No category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= material_form_value($old, 'category_id') === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['label']) ?></option><?php endforeach; ?></select></label>
        <label>Brand / Specification<input name="brand_spec" value="<?= e(material_form_value($old, 'brand_spec')) ?>"></label>
        <label>Preferred Supplier<select name="preferred_supplier_id"><option value="">No supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= material_form_value($old, 'preferred_supplier_id') === (string) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['label']) ?></option><?php endforeach; ?></select></label>
        <label>Minimum Stock<input type="number" step="0.01" min="0" name="min_stock" value="<?= e(material_form_value($old, 'min_stock', '0')) ?>"></label>
        <label>Maximum Stock<input type="number" step="0.01" min="0" name="max_stock" value="<?= e(material_form_value($old, 'max_stock', '0')) ?>"></label>
        <label>Reorder Point<input type="number" step="0.01" min="0" name="reorder_point" value="<?= e(material_form_value($old, 'reorder_point', '0')) ?>"></label>
        <label>Safety Stock<input type="number" step="0.01" min="0" name="safety_stock" value="<?= e(material_form_value($old, 'safety_stock', '0')) ?>"></label>
        <label>Lead Time Days<input type="number" min="0" name="lead_time_days" value="<?= e(material_form_value($old, 'lead_time_days', '0')) ?>"></label>
        <label class="full">Description<textarea name="description"><?= e(material_form_value($old, 'description')) ?></textarea></label>
        <?php if ($possibleDuplicate): ?><label class="full"><input type="checkbox" name="confirm_possible_duplicate" value="1" required> I confirmed this is a separate material variant.</label><?php endif; ?>
        <div class="form-actions full"><button class="btn primary" type="submit" data-confirm="Submit this material record?" data-confirm-title="<?= e($submitLabel) ?>"><?= e($submitLabel) ?></button></div>
    </form>
</section><?php endif; ?>

<form class="filter-card" method="get" data-filter-form>
    <label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Code, material, brand, supplier"></label>
    <label>Approval<select name="approval_status"><option value="">All approvals</option><?php foreach (['approved', 'pending', 'rejected'] as $status): ?><option value="<?= e($status) ?>" <?= $approvalStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
    <label>Activity<select name="status"><option value="">All activity</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
    <label>Category<select name="category_id"><option value="0">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['label']) ?></option><?php endforeach; ?></select></label>
    <label>Unit<select name="unit_id"><option value="0">All units</option><?php foreach ($units as $unit): ?><option value="<?= (int) $unit['id'] ?>" <?= $unitId === (int) $unit['id'] ? 'selected' : '' ?>><?= e($unit['label']) ?></option><?php endforeach; ?></select></label>
    <label>Supplier<select name="supplier_id"><option value="0">All suppliers</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= $supplierId === (int) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['label']) ?></option><?php endforeach; ?></select></label>
    <div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="materials.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?>
</form>

<section class="content-card"><div class="card-heading"><div><span class="eyebrow">MATERIALS</span><h2>Central Material Master</h2></div></div><div class="table-wrap"><table class="data modern-table" data-table-tools><thead><tr><th>Code</th><th>Material</th><th>Category</th><th>Unit</th><th>Reorder</th><th>Supplier</th><th>Approval</th><th>Activity</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="muted">No materials match the selected filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td><b><?= e($row['material_code']) ?></b></td><td><?= e($row['material_name']) ?><small><?= e($row['brand_spec']) ?></small></td><td><?= e($row['category_name'] ?? '-') ?></td><td><?= e($row['unit_name']) ?></td><td><?= money_or_qty($row['reorder_point']) ?></td><td><?= e($row['supplier_name'] ?? '-') ?></td><td><span class="workflow-badge <?= e($row['approval_status']) ?>"><?= e(ucfirst($row['approval_status'])) ?></span></td><td><?= bool_badge($row['is_active']) ?></td><td><a class="btn secondary" href="material_view.php?id=<?= (int) $row['id'] ?>">View</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</div></main></div></body></html>
