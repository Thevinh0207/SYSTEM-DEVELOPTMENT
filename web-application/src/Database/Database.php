<?php

declare(strict_types=1);

namespace App\Database;

use App\Config;
use PDO;
use PDOException;


/**
 * Database — MySQL Connection Singleton
 * =======================================
 * Creates a single PDO connection to the MySQL database and reuses it for
 * every subsequent call within the same request (Singleton pattern).
 *
 * Why singleton? Opening a new database connection is slow. By keeping one
 * open for the life of the request, all models share it without the overhead.
 *
 * Connection settings come from config/config.php → 'database' key.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $config = []): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $defaults = Config::get('database', []);
        $config  += $defaults;

        $host    = $config['host']    ?? '127.0.0.1';
        $port    = $config['port']    ?? 3306;
        $dbname  = $config['dbname']  ?? 'web_application';
        $user    = $config['user']    ?? 'root';
        $pass    = $config['pass']    ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // PHP 8.5+ moved this constant; fall back to the legacy one on older PHP.
        $initCmd = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
            ? \Pdo\Mysql::ATTR_INIT_COMMAND
            : PDO::MYSQL_ATTR_INIT_COMMAND;
        $options[$initCmd] = "SET NAMES {$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
