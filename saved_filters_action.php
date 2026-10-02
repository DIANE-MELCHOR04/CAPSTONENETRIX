<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/high_value.php';
require_permission('view_dashboard');
require_once __DIR__ . '/config/db.php';

verify_csrf();

$name = filter_text($_POST, 'filter_name', 120);
$pageKey = filter_text($_POST, 'page_key', 80);
$queryString = filter_text($_POST, 'query_string', 800);
$redirectTarget = (string) ($_POST['redirect'] ?? 'global_search.php');

if ($name === '') {
    flash('danger', 'Filter name is required.');
    redirect('global_search.php');
}

$path = parse_url($redirectTarget, PHP_URL_PATH);
$query = parse_url($redirectTarget, PHP_URL_QUERY);
$base = basename((string) $path);
if ($base === '' || !str_ends_with($base, '.php')) {
    $base = 'global_search.php';
}

save_filter($pdo, (int) current_user()['id'], $pageKey ?: $base, $name, $queryString);
flash('success', 'Saved filter created.');
redirect($base . ($query ? '?' . $query : ''));
