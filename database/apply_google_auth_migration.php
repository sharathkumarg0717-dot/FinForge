<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';

try {
    $db = \FinSync\Core\Database::connection();
    echo "Connected to MySQL successfully.\n";

    // 1. Check existing columns in users table
    $stmt = $db->query("SHOW COLUMNS FROM users");
    $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (!in_array('google_id', $columns, true)) {
        echo "Adding google_id, avatar_url, auth_provider columns to users table...\n";
        $db->exec("ALTER TABLE users 
            ADD COLUMN google_id VARCHAR(100) NULL UNIQUE AFTER email,
            ADD COLUMN avatar_url VARCHAR(500) NULL AFTER google_id,
            ADD COLUMN auth_provider VARCHAR(20) NOT NULL DEFAULT 'local' AFTER avatar_url");
        echo "Added google_id, avatar_url, auth_provider columns successfully.\n";
    } else {
        echo "google_id column already exists in users table.\n";
    }

    // Allow password_hash to be NULL for OAuth users
    $db->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL");
    echo "Updated password_hash to allow NULL for OAuth logins.\n";

    // 2. Update currency in financial_accounts to INR
    $accountCols = array_column($db->query("SHOW COLUMNS FROM financial_accounts")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (in_array('currency', $accountCols, true)) {
        $db->exec("UPDATE financial_accounts SET currency = 'INR' WHERE currency = 'USD' OR currency IS NULL");
        echo "Updated financial_accounts currency to INR.\n";
    }

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
