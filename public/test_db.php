<?php
declare(strict_types=1);

// FinSync Database Diagnostic Tool for InfinityFree & Hosting Providers
header('Content-Type: text/html; charset=utf-8');

$configFile = dirname(__DIR__) . '/config.local.php';
if (!is_file($configFile) && is_file(__DIR__ . '/config.local.php')) {
    $configFile = __DIR__ . '/config.local.php';
}
$hasConfig = is_file($configFile);
$config = $hasConfig ? (array)require $configFile : [];
$db = $config['db'] ?? [];

$host = (string)($db['host'] ?? 'NOT SET (defaulting to 127.0.0.1)');
$port = (int)($db['port'] ?? 3306);
$name = (string)($db['database'] ?? 'NOT SET (defaulting to finsync)');
$user = (string)($db['username'] ?? 'NOT SET (defaulting to root)');
$pass = (string)($db['password'] ?? '');

$connectionStatus = 'Pending';
$connectionError = null;
$tables = [];

if ($hasConfig) {
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $connectionStatus = 'SUCCESS';
        
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $connectionStatus = 'FAILED';
        $connectionError = $e->getMessage();
    }
} else {
    $connectionStatus = 'CONFIG_MISSING';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinSync Database Diagnostic</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e2e8f0; padding: 30px; margin: 0; }
        .card { max-width: 680px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); border: 1px solid #334155; }
        h1 { margin-top: 0; font-size: 22px; color: #f8fafc; display: flex; align-items: center; gap: 10px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .badge-success { background: #065f46; color: #34d399; }
        .badge-danger { background: #991b1b; color: #f87171; }
        .badge-warning { background: #854d0e; color: #fde047; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-weight: 500; }
        td code { background: #0f172a; padding: 2px 6px; border-radius: 4px; color: #38bdf8; font-family: monospace; }
        .error-box { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 14px; border-radius: 8px; margin: 15px 0; font-size: 14px; word-break: break-all; }
        .tip-box { background: rgba(56, 189, 248, 0.12); border: 1px solid #0284c7; color: #bae6fd; padding: 14px; border-radius: 8px; margin: 15px 0; font-size: 13px; line-height: 1.5; }
        .tip-box b { color: #ffffff; }
        ul { margin: 8px 0; padding-left: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>
            <span>FinSync Database Connection Test</span>
            <?php if ($connectionStatus === 'SUCCESS'): ?>
                <span class="badge badge-success">CONNECTED</span>
            <?php elseif ($connectionStatus === 'CONFIG_MISSING'): ?>
                <span class="badge badge-warning">CONFIG FILE MISSING</span>
            <?php else: ?>
                <span class="badge badge-danger">CONNECTION FAILED</span>
            <?php endif; ?>
        </h1>

        <table>
            <tr>
                <th>Configuration File (config.local.php)</th>
                <td><?= $hasConfig ? '<code>Found & Loaded</code>' : '<span style="color:#f87171;">MISSING in root folder</span>' ?></td>
            </tr>
            <tr>
                <th>MySQL Host</th>
                <td><code><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></code></td>
            </tr>
            <tr>
                <th>MySQL Port</th>
                <td><code><?= $port ?></code></td>
            </tr>
            <tr>
                <th>Database Name</th>
                <td><code><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></code></td>
            </tr>
            <tr>
                <th>Username</th>
                <td><code><?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?></code></td>
            </tr>
            <tr>
                <th>Password</th>
                <td><?= $pass !== '' ? '<code>' . str_repeat('•', min(8, strlen($pass))) . ' (set)</code>' : '<span style="color:#f87171;">Not Set / Empty</span>' ?></td>
            </tr>
        </table>

        <?php if ($connectionStatus === 'SUCCESS'): ?>
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #a7f3d0; padding: 14px; border-radius: 8px;">
                <b>MySQL Connected Successfully!</b><br>
                Found <b><?= count($tables) ?></b> tables in database:
                <code><?= implode(', ', $tables) ?: 'No tables found! (Please import database/schema.sql via phpMyAdmin)' ?></code>
            </div>
        <?php elseif ($connectionStatus === 'CONFIG_MISSING'): ?>
            <div class="tip-box">
                <b>Why is config.local.php missing?</b><br>
                Because <code>config.local.php</code> contains passwords, Git automatically ignores it for security.<br><br>
                <b>How to fix on InfinityFree:</b>
                <ol>
                    <li>Log into your <b>InfinityFree cPanel -> Online File Manager</b>.</li>
                    <li>Go to your website folder (e.g. <code>htdocs/</code> or <code>htdocs/FinSync/</code>).</li>
                    <li>Create a file named <b><code>config.local.php</code></b> directly alongside the <code>app/</code> and <code>public/</code> folders.</li>
                    <li>Paste your InfinityFree MySQL details into it.</li>
                </ol>
            </div>
        <?php else: ?>
            <div class="error-box">
                <b>MySQL Error:</b><br>
                <?= htmlspecialchars((string)$connectionError, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="tip-box">
                <b>Troubleshooting on InfinityFree:</b>
                <ul>
                    <li><b>Host Name:</b> In InfinityFree, it is NEVER <code>localhost</code>. Look in your InfinityFree control panel under <b>MySQL Databases</b> for your exact host (e.g. <code>sql107.infinityfree.com</code> or <code>sql200.epizy.com</code>).</li>
                    <li><b>Database Name:</b> Must have your prefix (e.g. <code>if0_43306084_finsync</code>, NOT just <code>finsync</code>).</li>
                    <li><b>Username:</b> Must be your InfinityFree account username (e.g. <code>if0_43306084</code>).</li>
                    <li><b>Password:</b> Use your InfinityFree <i>Hosting Account Password</i> (shown in Client Area -> Account Details -> Password), not your email account password.</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

