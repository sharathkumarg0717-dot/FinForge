<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/Database.php';

try {
    $db = FinSync\Core\Database::connection();
    echo "Connected to database.\n";

    // Check existing columns of investment_holdings
    $stmt = $db->query("SHOW COLUMNS FROM investment_holdings");
    $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (!in_array('buy_price', $columns, true)) {
        $db->exec("ALTER TABLE investment_holdings ADD COLUMN buy_price DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER price");
        echo "Added 'buy_price' column to investment_holdings.\n";
    } else {
        echo "'buy_price' column already exists.\n";
    }

    if (!in_array('pnl', $columns, true)) {
        $db->exec("ALTER TABLE investment_holdings ADD COLUMN pnl DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER market_value");
        echo "Added 'pnl' column to investment_holdings.\n";
    } else {
        echo "'pnl' column already exists.\n";
    }

    // Check financial_accounts
    $stmtAcc = $db->query("SHOW COLUMNS FROM financial_accounts");
    $accColumns = array_column($stmtAcc->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('client_id', $accColumns, true)) {
        $db->exec("ALTER TABLE financial_accounts ADD COLUMN client_id VARCHAR(60) NULL AFTER masked_number");
        echo "Added 'client_id' column to financial_accounts.\n";
    } else {
        echo "'client_id' column already exists.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}

