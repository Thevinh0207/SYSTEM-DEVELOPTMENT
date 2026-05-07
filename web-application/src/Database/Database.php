<?php

declare(strict_types=1);

namespace App\Database;

use App\Config;
use PDO;
use PDOException;
use RedBeanPHP\R;

/**
 * Database — MySQL connection + RedBeanPHP bootstrap
 * ====================================================
 * Centralised database wiring. We register one connection with RedBeanPHP
 * (R::setup) and keep a reference to the underlying PDO so model code can
 * still pull it out if it wants to.
 *
 * Why keep PDO around? — The existing model methods continue to receive a
 * PDO via their constructors. Underneath, all queries now go through
 * RedBean's adapter (R::exec / R::getAll / R::getRow / R::dispense /
 * R::store / R::find), which is what makes this an ORM-backed layer.
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

        try {
            // ── 1. Bootstrap RedBeanPHP ─────────────────────────────────
            // R::setup creates the ORM adapter + its own PDO under the hood.
            if (!R::testConnection()) {
                R::setup($dsn, $user, $pass);
                // Fluid mode lets RedBean auto-create columns/tables when
                // dispensing new beans. We never rely on that since the
                // schema is fixed via db/schema.sql, but leaving it on means
                // we don't crash on minor mismatches in development.
                R::freeze(false);
            }

            // ── 2. Pull the same PDO out so model constructors that still
            //   accept ?PDO keep working. We tweak attributes for safety.
            self::$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
        try {
            R::close();
        } catch (\Throwable $e) {
            // ignore — adapter may not have been initialised yet
        }
    }
}
