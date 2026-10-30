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
        if (!is_file($configFile) && is_file(dirname(__DIR__, 2) . '/public/config.local.php')) {
            $configFile = dirname(__DIR__, 2) . '/public/config.local.php';
        }
        $settings = is_file($configFile) ? (array)require $configFile : [];
        $db = $settings['db'] ?? [];
        $host = getenv('DB_HOST') ?: (string)($db['host'] ?? '127.0.0.1');
        $port = (int)(getenv('DB_PORT') ?: ($db['port'] ?? 3306));
        $name = getenv('DB_NAME') ?: (string)($db['database'] ?? 'finsync');
        $user = getenv('DB_USER') ?: (string)($db['username'] ?? 'root');
        $pass = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : (string)($db['password'] ?? '');
        $charset = (string)($db['charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ]);
        } catch (Throwable $exception) {
            error_log('FinSync database connection failed: ' . $exception->getMessage());
            throw new RuntimeException("Database connection failed to {$host} as user '{$user}': " . $exception->getMessage());
        }

        return self::$connection;
    }

    public static function config(): array
    {
        $file = dirname(__DIR__, 2) . '/config.local.php';
        if (!is_file($file) && is_file(dirname(__DIR__, 2) . '/public/config.local.php')) {
            $file = dirname(__DIR__, 2) . '/public/config.local.php';
        }
        $settings = is_file($file) ? (array)require $file : [];
        if (getenv('GOOGLE_CLIENT_ID')) {
            $settings['google']['client_id'] = (string)getenv('GOOGLE_CLIENT_ID');
        }
        if (getenv('APP_ENV')) {
            $settings['app']['environment'] = (string)getenv('APP_ENV');
        }
        return $settings;
    }
}
