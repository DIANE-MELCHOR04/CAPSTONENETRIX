<?php
require_once __DIR__ . '/lib/inventory.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/search_filters.php';
require_permission('view_reports');
require_once __DIR__ . '/config/db.php';

$types = ['opening','stock_in','stock_out','reservation','release','receipt','adjustment','transfer_dispatch','transfer_receipt','return','damage','supplier_delivery'];
$materialId = filter_int_choice($_GET, 'material_id');
$locationId = filter_int_choice($_GET, 'location_id');
$type = filter_choice($_GET, 'type', $types);
$from = filter_date($_GET, 'from');
$to = filter_date($_GET, 'to');
$reference = filter_text($_GET, 'reference');

$where = [];
$params = [];
filter_where_equals($where, $params, 'im.material_id', $materialId);
filter_where_equals($where, $params, 'im.location_id', $locationId);
filter_where_equals($where, $params, 'im.movement_type', $type);
filter_where_between_dates($where, $params, 'im.created_at', $from, $to);
filter_where_like($where, $params, ['im.reference_type', 'CAST(im.reference_id AS CHAR)', 'im.movement_no', 'im.remarks', 'm.material_code', 'm.material_name', 'l.name'], $reference);

$whereSql = filter_where_sql($where);
$stmt = $pdo->prepare(
    "SELECT im.*, m.material_code, m.material_name, u.name AS unit_name, l.name AS location_name, creator.full_name
     FROM inventory_movements im
     JOIN materials m ON m.id = im.material_id
     JOIN units u ON u.id = m.unit_id
     JOIN locations l ON l.id = im.location_id
     JOIN users creator ON creator.id = im.created_by
     {$whereSql}
     ORDER BY im.created_at DESC, im.id DESC
     LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$materials = $pdo->query("SELECT id, CONCAT(material_code, ' - ', material_name) AS label FROM materials WHERE is_active = 1 AND approval_status = 'approved' ORDER BY material_name")->fetchAll();
$locations = $pdo->query('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY type, name')->fetchAll();

$canExport = role_can((string) ($_SESSION['role'] ?? ''), 'export_reports');
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        die('You do not have permission to export this report.');
    }

    send_csv('movement-report-' . date('Ymd') . '.csv', ['Movement No.', 'Date', 'Material Code', 'Material Name', 'Location', 'Type', 'Qty In', 'Qty Out', 'Balance After', 'Reference Type', 'Reference ID', 'Created By'], array_map(static fn($row): array => [$row['movement_no'], $row['created_at'], $row['material_code'], $row['material_name'], $row['location_name'], str_replace('_', ' ', $row['movement_type']), $row['quantity_in'], $row['quantity_out'], $row['balance_after'], $row['reference_type'] ?? '', $row['reference_id'] ?? '', $row['full_name']], $rows));
}

$exportUrl = filter_export_url(['export' => 'csv']);
$activePage = 'reports_movements';
$title = 'Movement Report';
$eyebrow = 'REPORTS';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php require __DIR__ . '/partials/assets.php'; ?>
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/partials/header.php'; ?>
        <div class="page-content">
            <section class="monitoring-top-card">
                <div>
                    <span class="eyebrow light">MOVEMENT REPORT</span>
                    <h2>Inventory ledger filters and export</h2>
                    <p>Trace stock movement by material, location, movement type, date range, and reference.</p>
                </div>
                <?php if ($canExport): ?><a class="btn glass" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?>
            </section>
            <section class="content-card">
                <form class="filter-card" method="get" data-filter-form>
                    <label>Material<select name="material_id"><option value="0">All materials</option><?php foreach ($materials as $material): ?><option value="<?= (int) $material['id'] ?>" <?= $materialId === (int) $material['id'] ? 'selected' : '' ?>><?= e($material['label']) ?></option><?php endforeach; ?></select></label>
                    <label>Location<select name="location_id"><option value="0">All locations</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $locationId === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option><?php endforeach; ?></select></label>
                    <label>Type<select name="type"><option value="">All types</option><?php foreach ($types as $option): ?><option value="<?= e($option) ?>" <?= $type === $option ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst($option))) ?></option><?php endforeach; ?></select></label>
                    <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
                    <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
                    <label class="filter-search">Search<input name="reference" value="<?= e($reference) ?>" placeholder="Reference, movement no., material, remarks"></label>
                    <div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="reports_movements.php" data-clear-filters>Clear</a><?php if ($canExport): ?><a class="btn secondary" href="<?= e($exportUrl) ?>">Export CSV</a><?php endif; ?></div>
                    <?= filter_badge(count($rows)) ?>
                </form>
                <div class="table-wrap">
                    <table class="data modern-table">
                        <thead><tr><th>No.</th><th>Date</th><th>Material</th><th>Location</th><th>Type</th><th>In</th><th>Out</th><th>Balance</th><th>Reference</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="9" class="muted">No movements match the current filters.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><b><?= e($row['movement_no']) ?></b><small><?= e($row['full_name']) ?></small></td>
                                <td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td>
                                <td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name']) ?></small></td>
                                <td><?= e($row['location_name']) ?></td>
                                <td><span class="workflow-badge <?= e($row['movement_type']) ?>"><?= e(str_replace('_', ' ', $row['movement_type'])) ?></span></td>
                                <td class="qty-positive"><?= money_or_qty($row['quantity_in']) ?> <?= e($row['unit_name']) ?></td>
                                <td class="qty-negative"><?= money_or_qty($row['quantity_out']) ?> <?= e($row['unit_name']) ?></td>
                                <td><b><?= money_or_qty($row['balance_after']) ?></b></td>
                                <td><?= e($row['reference_type'] ?? '') ?><small><?= e($row['reference_id'] ?? '') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
