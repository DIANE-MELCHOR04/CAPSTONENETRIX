<?php
require_once __DIR__ . '/lib/master_data.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission('manage_master_data');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name !== '') {
        $pdo->prepare('INSERT INTO material_categories (name, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1')->execute([$name]);
        flash('success', 'Category saved.');
    }
    redirect('categories.php');
}

$q = filter_text($_GET, 'q');
$statusFilter = bool_filter_value($_GET, 'status');
$where = [];
$params = [];
filter_where_like($where, $params, ['name'], $q);
$activeValue = filter_bool_condition($statusFilter);
if ($activeValue !== null) {
    $where[] = 'is_active = ?';
    $params[] = $activeValue;
}
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT * FROM material_categories {$whereSql} ORDER BY is_active DESC, name");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'categories';
$title = 'Material Categories';
$eyebrow = 'MASTER DATA';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">CATALOG</span><h2>Add Category</h2></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Name<input name="name" required></label><div class="form-actions"><button class="btn primary" type="submit">Save Category</button></div></form></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Category name"></label><label>Status<select name="status"><option value="">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="categories.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">LIST</span><h2>Categories</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Name</th><th>Status</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['name']) ?></b></td><td><?= bool_badge($row['is_active']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
