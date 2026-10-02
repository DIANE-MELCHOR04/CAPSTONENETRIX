<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('METRICCON_INVENTORY_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . APP_BASE_URL . ltrim($path, '/'));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION[APP_CSRF_SESSION_KEY])) {
        $_SESSION[APP_CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION[APP_CSRF_SESSION_KEY];
}

function verify_csrf(): void
{
    $posted = (string) ($_POST['csrf_token'] ?? '');
    $session = (string) ($_SESSION[APP_CSRF_SESSION_KEY] ?? '');
    if ($posted === '' || $session === '' || !hash_equals($session, $posted)) {
        http_response_code(419);
        die('The form expired. Please go back and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function money_or_qty($value): string
{
    $number = (float) $value;
    return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
}

require_once __DIR__ . '/ui.php';
