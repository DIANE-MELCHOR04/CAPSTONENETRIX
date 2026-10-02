<?php
require_once __DIR__ . '/lib/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/config/db.php';
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (login_user($pdo, $username, $password)) {
        redirect('dashboard.php');
    }
    $error = 'Invalid username/password or inactive account.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <?php require __DIR__ . '/partials/assets.php'; ?>
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-brand-showcase" aria-label="Metriccon Inventory Hub">
        <div class="login-brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">Metriccon Co., Inc.</p>
        <h1>Know what you have.<br><span>Plan what comes next.</span></h1>
        <p class="login-copy">One operational workspace for construction materials, site requests, stock movement, purchasing, forecasting, and inventory decisions.</p>
        <div class="login-signals" aria-label="System capabilities">
            <span><b>Live</b> stock visibility</span>
            <span><b>ARIMA</b> demand forecasting</span>
            <span><b>Gemini</b> inventory intelligence</span>
        </div>
    </section>

    <section class="login-panel">
        <div class="login-card">
            <div class="login-mobile-brand">
                <div class="login-brand-mark" aria-hidden="true">M</div>
                <strong>Metriccon Inventory Hub</strong>
            </div>
            <p class="eyebrow">Secure access</p>
            <h2>Welcome back</h2>
            <p class="login-copy">Sign in with your assigned Metriccon account.</p>

            <?php if ($error): ?>
                <div class="alert danger" role="alert" data-notify="danger" data-notify-fallback><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form" data-block-on-submit="Signing in" aria-label="Sign in">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Username
                    <input name="username" autocomplete="username" required autofocus placeholder="Enter username">
                </label>
                <label>Password
                    <input type="password" name="password" autocomplete="current-password" required placeholder="Enter password">
                </label>
                <button class="btn primary login-submit" type="submit">Sign in securely <span aria-hidden="true">→</span></button>
            </form>
            <small class="login-help">Authorized personnel only. Account activity is recorded for security and accountability.</small>
        </div>
    </section>
</main>
</body>
</html>
