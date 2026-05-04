<?php

declare(strict_types=1);

namespace App\Database;

use App\Config;
use PDO;
use PDOException;

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

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ]);
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
