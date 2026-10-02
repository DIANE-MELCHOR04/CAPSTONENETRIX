<?php
require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/lib/master_data.php';
require_once __DIR__ . '/lib/search_filters.php';
require_once __DIR__ . '/lib/numbering.php';
require_once __DIR__ . '/lib/duplicate_guard.php';
require_permission('manage_master_data');
require_once __DIR__ . '/config/db.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mode = (string) ($_POST['mode'] ?? 'site');
    try {
        $pdo->beginTransaction();
        if ($mode === 'site') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Site name is required.');
            }
            assert_unique_site_name($pdo, $name);
            $code = next_master_code($pdo, 'site');
            $pdo->prepare('INSERT INTO sites (code, name, address, status) VALUES (?, ?, ?, ?)')
                ->execute([$code, $name, trim((string) ($_POST['address'] ?? '')), (string) ($_POST['status'] ?? 'active')]);
            flash('success', $code . ' created successfully.');
        } else {
            $name = trim((string) ($_POST['location_name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Location name is required.');
            }
            $siteId = ($_POST['site_id'] ?? '') !== '' ? (int) $_POST['site_id'] : null;
            assert_unique_location_name($pdo, $name);
            $code = next_master_code($pdo, 'location');
            $pdo->prepare('INSERT INTO locations (site_id, code, name, type, is_active) VALUES (?, ?, ?, ?, 1)')
                ->execute([$siteId, $code, $name, (string) ($_POST['type'] ?? 'site')]);
            flash('success', $code . ' created successfully.');
        }
        $pdo->commit();
        redirect('sites.php');
    } catch (DomainException|InvalidArgumentException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $error->getMessage();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Site/location creation failed: ' . $error->getMessage());
        $errors[] = 'The site or location could not be created. Please try again.';
    }
}

$siteStatuses = ['active','planning','suspended','completed','closed'];
$locationTypes = ['warehouse','site','quarantine'];
$q = filter_text($_GET, 'q');
$siteStatus = filter_choice($_GET, 'site_status', $siteStatuses);
$locationType = filter_choice($_GET, 'location_type', $locationTypes);
$locationStatus = bool_filter_value($_GET, 'location_status');
$allSites = $pdo->query('SELECT id, name FROM sites ORDER BY name')->fetchAll();

$siteWhere = [];
$siteParams = [];
filter_where_like($siteWhere, $siteParams, ['code', 'name', 'address'], $q);
filter_where_equals($siteWhere, $siteParams, 'status', $siteStatus);
$siteWhereSql = filter_where_sql($siteWhere);
$siteStmt = $pdo->prepare("SELECT * FROM sites {$siteWhereSql} ORDER BY status, name");
$siteStmt->execute($siteParams);
$sites = $siteStmt->fetchAll();

$locationWhere = [];
$locationParams = [];
filter_where_like($locationWhere, $locationParams, ['l.code', 'l.name', 's.name'], $q);
filter_where_equals($locationWhere, $locationParams, 'l.type', $locationType);
$activeValue = filter_bool_condition($locationStatus);
if ($activeValue !== null) {
    $locationWhere[] = 'l.is_active = ?';
    $locationParams[] = $activeValue;
}
$locationWhereSql = filter_where_sql($locationWhere);
$locationStmt = $pdo->prepare("SELECT l.*, s.name AS site_name FROM locations l LEFT JOIN sites s ON s.id = l.site_id {$locationWhereSql} ORDER BY l.is_active DESC, l.name");
$locationStmt->execute($locationParams);
$locations = $locationStmt->fetchAll();
$resultCount = count($sites) + count($locations);
$activePage = 'sites';
$title = 'Sites And Locations';
$eyebrow = 'MASTER DATA';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
<?= bulk_import_panel('sites') ?>
<?= bulk_import_panel('locations') ?>

<?php if ($errors): ?><div class="alert danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">PROJECT SITE</span><h2>Add Site</h2></div><span class="workflow-badge pending">Code generated automatically</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="mode" value="site"><label>Name<input name="name" required></label><label>Address<input name="address"></label><label>Status<select name="status"><option value="active">Active</option><option value="planning">Planning</option><option value="suspended">Suspended</option><option value="completed">Completed</option><option value="closed">Closed</option></select></label><div class="form-actions full"><button class="btn primary" type="submit" data-confirm="Create this site?" data-confirm-title="Create site">Create Site</button></div></form></section>
<section class="form-card"><div class="card-heading"><div><span class="eyebrow">LOCATION</span><h2>Add Location</h2></div><span class="workflow-badge pending">Code generated automatically</span></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="mode" value="location"><label>Site<select name="site_id"><option value="">No site</option><?php foreach ($allSites as $site): ?><option value="<?= (int) $site['id'] ?>"><?= e($site['name']) ?></option><?php endforeach; ?></select></label><label>Name<input name="location_name" required></label><label>Type<select name="type"><option value="warehouse">Warehouse</option><option value="site">Site</option><option value="quarantine">Quarantine</option></select></label><div class="form-actions full"><button class="btn primary" type="submit" data-confirm="Create this inventory location?" data-confirm-title="Create location">Create Location</button></div></form></section>
<form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Site, location, code, address"></label><label>Site Status<select name="site_status"><?php render_status_options($siteStatuses, $siteStatus); ?></select></label><label>Location Type<select name="location_type"><?php render_status_options($locationTypes, $locationType, 'All types'); ?></select></label><label>Location Status<select name="location_status"><option value="">All statuses</option><option value="active" <?= $locationStatus === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $locationStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="sites.php" data-clear-filters>Clear</a></div><?= filter_badge($resultCount) ?></form>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">SITES</span><h2>Project Sites</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Code</th><th>Name</th><th>Address</th><th>Status</th></tr></thead><tbody><?php foreach ($sites as $site): ?><tr><td><b><?= e($site['code']) ?></b></td><td><?= e($site['name']) ?></td><td><?= e($site['address']) ?></td><td><span class="workflow-badge <?= e($site['status']) ?>"><?= e($site['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="content-card"><div class="card-heading"><div><span class="eyebrow">LOCATIONS</span><h2>Inventory Locations</h2></div></div><div class="table-wrap"><table class="data modern-table"><thead><tr><th>Code</th><th>Name</th><th>Site</th><th>Type</th><th>Status</th></tr></thead><tbody><?php foreach ($locations as $row): ?><tr><td><b><?= e($row['code']) ?></b></td><td><?= e($row['name']) ?></td><td><?= e($row['site_name'] ?? 'No site') ?></td><td><?= e($row['type']) ?></td><td><?= bool_badge($row['is_active']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></main></div></body></html>
