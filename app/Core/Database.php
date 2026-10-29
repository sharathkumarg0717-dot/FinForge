<?php
declare(strict_types=1);

namespace FinSync\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $configFile = dirname(__DIR__, 2) . '/config.local.php';
        if (!is_file($configFile)) {
            throw new RuntimeException('Local database configuration is missing. Copy config.local.example.php to config.local.php.');
        }
        $settings = require $configFile;
        $db = $settings['db'] ?? [];
        $host = (string)($db['host'] ?? '127.0.0.1');
        $port = (int)($db['port'] ?? 3306);
        $name = (string)($db['database'] ?? 'finsync');
        $charset = (string)($db['charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$connection = new PDO($dsn, (string)($db['username'] ?? 'root'), (string)($db['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ]);
        } catch (Throwable $exception) {
            error_log('FinSync database connection failed: ' . $exception->getMessage());
            throw new RuntimeException('Unable to connect to the local database. Check config.local.php and confirm MySQL is running.');
        }

        return self::$connection;
    }

    public static function config(): array
    {
        $file = dirname(__DIR__, 2) . '/config.local.php';
        return is_file($file) ? (array)require $file : [];
    }
}
