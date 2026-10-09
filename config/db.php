<?php
/**
 * Database Singleton & Configuration Loader
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

final class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    private function __construct()
    {
        $this->loadEnvironment();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $dbname = getenv('DB_NAME') ?: 'smart_parking_db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        // Connect first without DB name to ensure database exists
        try {
            $tempDsn = "mysql:host={$host};port={$port};charset={$charset}";
            $tempPdo = new PDO($tempDsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        } catch (\PDOException $e) {
            // If already created or connection error, proceed to main DSN
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci"
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database Connection Error. Please verify database server is running.");
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    private function loadEnvironment(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (!file_exists($envFile)) {
            $envFile = __DIR__ . '/../.env.example';
        }

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $value = trim($value, '"\'');
                    if (getenv($key) === false) {
                        putenv("{$key}={$value}");
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
    }

    // Prevent cloning and serialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}
