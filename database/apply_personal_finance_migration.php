<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';

$migration = file_get_contents(__DIR__ . '/personal_finance_migration.sql');
if ($migration === false) {
    throw new RuntimeException('Unable to read the personal finance database migration.');
}

\FinSync\Core\Database::connection()->exec($migration);
$recordCount = \FinSync\Core\Database::connection()
    ->query('SELECT COUNT(*) FROM personal_finance_records')
    ->fetchColumn();
fwrite(STDOUT, "Personal finance database table is ready ({$recordCount} saved records).\n");
