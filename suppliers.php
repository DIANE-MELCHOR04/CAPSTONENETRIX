<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/master_data.php';
require_once __DIR__ . '/lib/duplicate_guard.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission('manage_suppliers');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $company = trim((string) ($_POST['company'] ?? ''));
    if ($company !== '') {
        try {
            assert_unique_supplier_company($pdo, $company);
            $pdo->prepare('INSERT INTO suppliers (company, contact_person, phone, address, is_active) VALUES (?, ?, ?, ?, 1)')
                ->execute([$company, trim((string) $_POST['contact_person']), trim((string) $_POST['phone']), trim((string) $_POST['address'])]);
            flash('success', 'Supplier saved.');
        } catch (DomainException $error) {
            flash('warning', $error->getMessage());
        }
    }
    redirect('suppliers.php');
}

$q = filter_text($_GET, 'q');
$statusFilter = bool_filter_value($_GET, 'status');
$where = [];
$params = [];
filter_where_like($where, $params, ['company', 'contact_person', 'phone', 'address'], $q);
$activeValue = filter_bool_condition($statusFilter);
if ($activeValue !== null) {
    $where[] = 'is_active = ?';
    $params[] = $activeValue;
}
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT * FROM suppliers {$whereSql} ORDER BY is_active DESC, company");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'suppliers';
$title = 'Suppliers';
$eyebrow = 'PROCUREMENT DATA';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('suppliers') ?>

<section class="form-card"><div class="card-heading"><div><span class="eyebrow">SUPPLIER MASTER</span><h2>Add Supplier</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Company<input name="company" required></label><label>Contact Person<input name="contact_person"></label><label>Phone<input name="phone"></label><label>Address<input name="address"></label><div class="form-actions full"><button class="btn primary" type="submit">Save Supplier</button></div></form></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Company, contact, phone, address"></label><label>Status<select name="status"><option value="">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="suppliers.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Company</th><th>Contact</th><th>Phone</th><th>Status</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['company']) ?></b><small><?= e($row['address']) ?></small></td><td><?= e($row['contact_person']) ?></td><td><?= e($row['phone']) ?></td><td><?= bool_badge($row['is_active']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
