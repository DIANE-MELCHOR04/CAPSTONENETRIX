<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/high_value.php';
require_permission('use_global_search');
require_once __DIR__ . '/config/db.php';

$q = filter_text($_GET, 'q');
$type = filter_text($_GET, 'type', 40);
$results = global_search_results($pdo, $q, 14);
if ($type !== '') {
    $results = array_values(array_filter($results, static fn(array $row): bool => strtolower((string) $row['type']) === strtolower($type)));
}
$savedFilters = saved_filters_for_page($pdo, (int) current_user()['id'], 'global_search');
$types = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['type'], $results)));
sort($types);

$activePage = 'global_search';
$title = 'Global Search';
$eyebrow = 'INTELLIGENCE';
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
        <div class="page-content ops-support-workspace">
            <section class="monitoring-top-card spectrum-card">
                <div>
                    <span class="eyebrow light">GLOBAL FINDER</span>
                    <h2>Global Search</h2>
                    <p>Find requests, materials, suppliers, users, reports, and movement ledger records from one fast workspace.</p>
                </div>
                <div class="hero-actions">
                    <a class="btn glass" href="dashboard.php">Dashboard</a>
                    <a class="btn glass subtle" href="movements.php">Movement Ledger</a>
                </div>
            </section>

            <section class="filter-card">
                <form class="report-toolbar" method="get" data-filter-form>
                    <label class="filter-search">Search
                        <input name="q" value="<?= e($q) ?>" placeholder="Material, request no., supplier, user, movement">
                    </label>
                    <label>Type
                        <select name="type">
                            <option value="">All result types</option>
                            <?php foreach (['Material','Supplier','User','Request','Movement','Report','Advanced'] as $option): ?>
                                <option value="<?= e($option) ?>" <?= strtolower($type) === strtolower($option) ? 'selected' : '' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button class="btn primary" type="submit">Search</button>
                        <a class="btn secondary" href="global_search.php" data-clear-filters>Clear</a>
                    </div>
                    <?= filter_badge(count($results)) ?>
                </form>
                <form method="post" action="saved_filters_action.php" class="saved-filter-row" data-saved-filter-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="page_key" value="global_search">
                    <input type="hidden" name="redirect" value="global_search.php<?= e(filter_export_url()) ?>">
                    <input type="hidden" name="query_string" value="<?= e(http_build_query($_GET)) ?>">
                    <label>Save current filter
                        <input name="filter_name" placeholder="Example: Critical cement search">
                    </label>
                    <button class="btn secondary" type="submit">Save Filter</button>
                    <?php if ($savedFilters): ?>
                        <label>Saved
                            <select onchange="if(this.value) window.location.href='global_search.php?' + this.value">
                                <option value="">Open saved filter</option>
                                <?php foreach ($savedFilters as $filter): ?>
                                    <option value="<?= e($filter['query_string']) ?>"><?= e($filter['filter_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                </form>
            </section>

            <section class="content-card">
                <div class="card-heading">
                    <div><span class="eyebrow">RESULTS</span><h2>Search Results</h2></div>
                </div>
                <div class="table-wrap">
                    <table class="data modern-table" data-sortable-table data-table-page-size="12">
                        <thead><tr><th>Record Type</th><th>Code</th><th>Record</th><th>Context</th><th>Open</th></tr></thead>
                        <tbody>
                        <?php if (!$results): ?>
                            <tr><td colspan="5" class="muted"><?= $q === '' ? 'Enter a keyword to begin searching.' : 'No matching records found.' ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><span class="workflow-badge"><?= e($row['type']) ?></span></td>
                                <td><?= e($row['code']) ?></td>
                                <td><b><?= e($row['title']) ?></b></td>
                                <td><small><?= e($row['subtitle']) ?></small></td>
                                <td><a class="table-action" href="<?= e($row['url']) ?>">Open</a></td>
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
