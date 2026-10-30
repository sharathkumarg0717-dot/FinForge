<?php
declare(strict_types=1);

namespace FinSync\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function locateConfigFile(): ?string
    {
        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/') : '';
        $scriptDir = !empty($_SERVER['SCRIPT_FILENAME']) ? rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_FILENAME'])), '/') : '';
        $candidates = [
            dirname(__DIR__, 2) . '/config.local.php',
            dirname(__DIR__, 2) . '/public/config.local.php',
            dirname(__DIR__, 3) . '/config.local.php',
            dirname(__DIR__, 3) . '/htdocs/config.local.php',
            dirname(__DIR__, 3) . '/htdocs/FinSync-main/config.local.php',
            $scriptDir . '/config.local.php',
            $scriptDir . '/../config.local.php',
        ];
        if ($docRoot !== '') {
            $candidates[] = $docRoot . '/config.local.php';
            $candidates[] = $docRoot . '/FinSync-main/config.local.php';
            $candidates[] = $docRoot . '/FinSync-main/public/config.local.php';
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $configFile = self::locateConfigFile();
        if ($configFile === null && getenv('DB_HOST') === false) {
            throw new RuntimeException("Database configuration file (config.local.php) is missing! Create 'config.local.php' in your FinSync-main folder with your InfinityFree MySQL credentials.");
        }
        $settings = $configFile !== null ? (array)require $configFile : [];
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
        $file = self::locateConfigFile();
        $settings = $file !== null ? (array)require $file : [];
        if (getenv('GOOGLE_CLIENT_ID')) {
            $settings['google']['client_id'] = (string)getenv('GOOGLE_CLIENT_ID');
        }
        if (getenv('APP_ENV')) {
            $settings['app']['environment'] = (string)getenv('APP_ENV');
        }
        return $settings;
    }
}
