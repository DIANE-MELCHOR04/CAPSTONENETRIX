<?php
declare(strict_types=1);

$localConfigPath = __DIR__ . '/config.local.php';
$hasEnvironmentConfig = getenv('APP_DB_NAME') !== false && trim((string)getenv('APP_DB_NAME')) !== ''
    && getenv('APP_DB_USER') !== false && trim((string)getenv('APP_DB_USER')) !== '';

if (!is_file($localConfigPath) && !$hasEnvironmentConfig) {
    $setupUrl = '/setup_database.php';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    if ($directory !== '') {
        $setupUrl = $directory . '/setup_database.php';
    }
    header('Location: ' . $setupUrl);
    exit;
}

require_once __DIR__ . '/config.php';

$dsn = 'mysql:host=' . APP_DB_HOST . ';dbname=' . APP_DB_NAME . ';charset=' . APP_DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, APP_DB_USER, APP_DB_PASS, $options);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $exception) {
    error_log('Inventory database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    $setupPath = dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $setupPath = rtrim(str_replace('\\', '/', $setupPath), '/.');
    $setupHref = ($setupPath !== '' ? $setupPath : '') . '/setup_database.php';
    die('The inventory database is unavailable. <a href="' . htmlspecialchars($setupHref, ENT_QUOTES, 'UTF-8') . '">Open Database Setup</a>.');
}
