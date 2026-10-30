<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once dirname(__DIR__) . '/app/Core/Database.php';

try {
    $db = \FinSync\Core\Database::connection();
    $messages = [];

    // 1. Check client_id in financial_accounts
    $colsAcc = $db->query("SHOW COLUMNS FROM financial_accounts LIKE 'client_id'")->fetchAll();
    if (empty($colsAcc)) {
        $db->exec("ALTER TABLE financial_accounts ADD COLUMN client_id VARCHAR(60) NULL AFTER masked_number");
        $messages[] = "Added column 'client_id' to financial_accounts.";
    } else {
        $messages[] = "Column 'client_id' already exists in financial_accounts.";
    }

    // 2. Check buy_price in investment_holdings
    $colsBuy = $db->query("SHOW COLUMNS FROM investment_holdings LIKE 'buy_price'")->fetchAll();
    if (empty($colsBuy)) {
        $db->exec("ALTER TABLE investment_holdings ADD COLUMN buy_price DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER price");
        $messages[] = "Added column 'buy_price' to investment_holdings.";
    } else {
        $messages[] = "Column 'buy_price' already exists in investment_holdings.";
    }

    // 3. Check pnl in investment_holdings
    $colsPnl = $db->query("SHOW COLUMNS FROM investment_holdings LIKE 'pnl'")->fetchAll();
    if (empty($colsPnl)) {
        $db->exec("ALTER TABLE investment_holdings ADD COLUMN pnl DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER market_value");
        $messages[] = "Added column 'pnl' to investment_holdings.";
    } else {
        $messages[] = "Column 'pnl' already exists in investment_holdings.";
    }

    // 4. Check users table columns
    $colsGid = $db->query("SHOW COLUMNS FROM users LIKE 'google_id'")->fetchAll();
    if (empty($colsGid)) {
        $db->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(100) NULL UNIQUE AFTER email");
        $messages[] = "Added column 'google_id' to users.";
    }
    $colsAvatar = $db->query("SHOW COLUMNS FROM users LIKE 'avatar_url'")->fetchAll();
    if (empty($colsAvatar)) {
        $db->exec("ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL AFTER google_id");
        $messages[] = "Added column 'avatar_url' to users.";
    }
    $colsProvider = $db->query("SHOW COLUMNS FROM users LIKE 'auth_provider'")->fetchAll();
    if (empty($colsProvider)) {
        $db->exec("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(20) NOT NULL DEFAULT 'local' AFTER avatar_url");
        $messages[] = "Added column 'auth_provider' to users.";
    }

    $success = true;
} catch (\Throwable $e) {
    $success = false;
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FinSync Database Migration</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 30px; }
        .card { max-width: 600px; margin: 40px auto; background: #1e293b; padding: 25px; border-radius: 12px; border: 1px solid #334155; }
        h1 { font-size: 20px; margin-top: 0; }
        .status-success { color: #34d399; }
        .status-fail { color: #f87171; }
        ul { padding-left: 20px; line-height: 1.7; font-size: 14px; }
        .btn { display: inline-block; background: #3b82f6; color: white; padding: 10px 16px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: 15px; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <h1>
            <?php if ($success): ?>
                <span class="status-success">✓ Database Schema Updated Successfully!</span>
            <?php else: ?>
                <span class="status-fail">✗ Database Migration Failed</span>
            <?php endif; ?>
        </h1>

        <?php if ($success): ?>
            <p style="color: #94a3b8; font-size: 14px;">All required Zerodha Demat and Google authentication columns are now active in your MySQL database:</p>
            <ul>
                <?php foreach ($messages as $msg): ?>
                    <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="index.php" class="btn">Return to FinSync Dashboard</a>
        <?php else: ?>
            <p style="color: #f87171;"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
