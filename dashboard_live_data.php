<?php
require_once __DIR__ . '/lib/auth.php';
require_permission('view_live_dashboard');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/dashboard_charts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(dashboard_live_snapshot($pdo), JSON_THROW_ON_ERROR);
