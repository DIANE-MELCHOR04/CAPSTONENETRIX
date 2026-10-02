<?php
require_once __DIR__ . '/lib/master_data.php';
require_once __DIR__ . '/lib/search_filters.php';
require_once __DIR__ . '/lib/numbering.php';
require_permission('manage_master_data');
require_once __DIR__ . '/config/db.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    try {
        if ($name === '') {
            throw new InvalidArgumentException('Unit name is required.');
        }
        $pdo->beginTransaction();
        $code = next_master_code($pdo, 'unit');
        $pdo->prepare('INSERT INTO units (code, name, is_active) VALUES (?, ?, 1)')->execute([$code, $name]);
        $pdo->commit();
        flash('success', $code . ' created successfully.');
        redirect('units.php');
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $error->getMessage();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unit creation failed: ' . $error->getMessage());
        $errors[] = 'The unit could not be created. Please try again.';
    }
}

$q = filter_text($_GET, 'q');
$statusFilter = bool_filter_value($_GET, 'status');
$where = [];
$params = [];
filter_where_like($where, $params, ['code', 'name'], $q);
$activeValue = filter_bool_condition($statusFilter);
if ($activeValue !== null) {
    $where[] = 'is_active = ?';
    $params[] = $activeValue;
}
$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare("SELECT * FROM units {$whereSql} ORDER BY is_active DESC, name");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$activePage = 'units';
$title = 'Units of Measurement';
$eyebrow = 'MASTER DATA';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?php if ($errors): ?><div class="alert danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">STANDARD UNITS</span><h2>Add Unit</h2></div><span class="workflow-badge pending">Code generated automatically</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Name<input name="name" placeholder="Bag" required></label><div class="form-actions full"><button class="btn primary" type="submit" data-confirm="Create this unit of measurement?" data-confirm-title="Create unit">Create Unit</button></div></form></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Code or unit name"></label><label>Status<select name="status"><option value="">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="units.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
<section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><b><?= e($row['code']) ?></b></td><td><?= e($row['name']) ?></td><td><?= bool_badge($row['is_active']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
