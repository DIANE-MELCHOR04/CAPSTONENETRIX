<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

$configPath = __DIR__ . '/config/config.local.php';
$message = '';
$error = '';

$defaults = [
    'base_url' => '/inventory_full_upgrade/',
    'db_host' => 'localhost',
    'db_name' => 'inventory_forecasting_system',
    'db_user' => 'root',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',
];

if (is_file($configPath)) {
    $existing = require $configPath;
    if (is_array($existing)) {
        foreach ($defaults as $key => $value) {
            if (isset($existing[$key]) && is_string($existing[$key])) {
                $defaults[$key] = $existing[$key];
            }
        }
        // Never echo an existing database password back into HTML.
        $defaults['db_pass'] = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $baseUrl = trim((string)($_POST['base_url'] ?? '/'));
    $host = trim((string)($_POST['db_host'] ?? 'localhost'));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');

    if ($baseUrl === '') {
        $baseUrl = '/';
    }
    $baseUrl = '/' . trim($baseUrl, '/') . '/';
    if ($baseUrl === '//') {
        $baseUrl = '/';
    }

    $defaults['base_url'] = $baseUrl;
    $defaults['db_host'] = $host;
    $defaults['db_name'] = $name;
    $defaults['db_user'] = $user;

    if ($host === '' || $name === '' || $user === '') {
        $error = 'Database host, database name, and database user are required.';
    } else {
        try {
            $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->query('SELECT 1');

            $requiredTables = ['users', 'materials', 'sites', 'inventory_movements'];
            $missing = [];
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
            foreach ($requiredTables as $table) {
                $stmt->execute([$name, $table]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $missing[] = $table;
                }
            }

            if ($missing) {
                throw new RuntimeException(
                    'Connection succeeded, but the selected local inventory database is missing table(s): ' . implode(', ', $missing) . '.'
                );
            }

            $content = "<?php\n";
            $content .= "declare(strict_types=1);\n\n";
            $content .= "return [\n";
            $content .= "    'base_url' => " . var_export($baseUrl, true) . ",\n";
            $content .= "    'db_host' => " . var_export($host, true) . ",\n";
            $content .= "    'db_name' => " . var_export($name, true) . ",\n";
            $content .= "    'db_user' => " . var_export($user, true) . ",\n";
            $content .= "    'db_pass' => " . var_export($pass, true) . ",\n";
            $content .= "    'db_charset' => 'utf8mb4',\n";
            $content .= "];\n";

            if (@file_put_contents($configPath, $content, LOCK_EX) === false) {
                throw new RuntimeException('The database connection works, but PHP could not write config/config.local.php. Create that file manually using config/config.local.example.php.');
            }

            @chmod($configPath, 0600);
            header('Location: ' . $baseUrl . 'index.php?database_configured=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Database Setup</title>
<style>
body{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:760px;margin:48px auto;padding:24px}.card{background:#fff;border:1px solid #e5eaf2;border-radius:18px;padding:28px;box-shadow:0 16px 45px rgba(27,39,67,.08)}h1{margin:0 0 8px;font-size:28px}p{line-height:1.55;color:#536079}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:700;font-size:13px;margin-bottom:7px}input{box-sizing:border-box;width:100%;padding:12px 13px;border:1px solid #ccd5e3;border-radius:10px;font-size:15px}button{border:0;border-radius:10px;padding:13px 18px;background:#273c75;color:#fff;font-weight:800;font-size:15px;cursor:pointer}.error{padding:12px 14px;border-radius:10px;background:#fff0f0;border:1px solid #f3bbbb;color:#9b2525;margin:16px 0;white-space:pre-wrap}.note{padding:13px 15px;border-radius:10px;background:#f1f5ff;border:1px solid #d9e2ff;margin:18px 0;font-size:14px}.small{font-size:13px;color:#68758d}@media(max-width:640px){.grid{grid-template-columns:1fr}.wrap{margin:0;padding:16px}.card{padding:20px}}
</style>
</head>
<body><div class="wrap"><div class="card">
<h1>Inventory Database Setup</h1>
<p>This page tests your local XAMPP MariaDB credentials and creates <code>config/config.local.php</code> only after the connection and core inventory tables are verified.</p>
<div class="note"><strong>Before saving:</strong> Start MariaDB in XAMPP and select your existing inventory database.</div>
<?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
<form method="post" autocomplete="off">
<div class="grid">
<div class="full"><label>Application path / Base URL</label><input name="base_url" value="<?= h($defaults['base_url']) ?>" placeholder="/inventory_full_upgrade/"><div class="small">Use the application folder name under <code>C:\xampp\htdocs</code>.</div></div>
<div><label>Database host</label><input name="db_host" value="<?= h($defaults['db_host']) ?>" required></div>
<div><label>Database name</label><input name="db_name" value="<?= h($defaults['db_name']) ?>" required></div>
<div><label>Database user</label><input name="db_user" value="<?= h($defaults['db_user']) ?>" required></div>
<div><label>Database password</label><input type="password" name="db_pass" value="" required autocomplete="new-password"></div>
<div class="full"><button type="submit">Test Connection &amp; Save Configuration</button></div>
</div>
</form>
<p class="small">After the system works, you may rename or remove <code>setup_database.php</code>. Your database password is never displayed by this page.</p>
</div></div></body></html>
