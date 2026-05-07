<?php

declare(strict_types=1);

/**
 * Quick MariaDB connectivity check.
 * Run from project root:  php db/test-connection.php
 *
 * Reports:
 *   1. Can we reach the MariaDB server?
 *   2. Does the configured database exist?
 *   3. Are the schema tables present?
 *   4. Sample row counts.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use RedBeanPHP\R;

$cfg = Config::get('database', []);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  MariaDB connection test                                       ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Config:\n";
printf("  host    = %s\n", $cfg['host']    ?? '(missing)');
printf("  port    = %s\n", $cfg['port']    ?? '(missing)');
printf("  dbname  = %s\n", $cfg['dbname']  ?? '(missing)');
printf("  user    = %s\n", $cfg['user']    ?? '(missing)');
printf("  pass    = %s\n", empty($cfg['pass']) ? '(empty)' : '(set, length ' . strlen($cfg['pass']) . ')');
printf("  charset = %s\n\n", $cfg['charset'] ?? 'utf8mb4');

// ── 1. Server reachable? ────────────────────────────────────────────────
echo "[1/4] Connecting to server (no database selected)...\n";
try {
    $serverDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset'] ?? 'utf8mb4');
    $serverPdo = new PDO($serverDsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version   = $serverPdo->query('SELECT VERSION()')->fetchColumn();
    echo "      ✓ Connected. Server version: {$version}\n\n";
} catch (PDOException $e) {
    echo "      ✗ Cannot reach server.\n";
    echo "        " . $e->getMessage() . "\n\n";
    echo "      Likely fixes:\n";
    echo "        • Start MariaDB/MySQL in Wampoon control panel.\n";
    echo "        • Check the host/port in config/config.php.\n";
    echo "        • Check your username/password.\n";
    exit(1);
}

// ── 2. Database exists? ─────────────────────────────────────────────────
echo "[2/4] Checking database '{$cfg['dbname']}' exists...\n";
$exists = (bool) $serverPdo
    ->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name')
    ->execute([':name' => $cfg['dbname']])
    ? $serverPdo->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $serverPdo->quote($cfg['dbname']))->fetchColumn()
    : false;

if (!$exists) {
    echo "      ✗ Database does not exist.\n";
    echo "        Creating it now...\n";
    try {
        $serverPdo->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '``', $cfg['dbname'])
        ));
        echo "      ✓ Database '{$cfg['dbname']}' created.\n\n";
    } catch (PDOException $e) {
        echo "      ✗ Failed to create database: " . $e->getMessage() . "\n";
        echo "        Create it manually:\n";
        echo "          CREATE DATABASE {$cfg['dbname']} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        exit(1);
    }
} else {
    echo "      ✓ Database exists.\n\n";
}

// ── 3. Bootstrap RedBeanPHP using R::setup ──────────────────────────────
echo "[3/4] Connecting via App\\Database\\Database...\n";
try {
    R::setup(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=" . ($cfg['charset'] ?? 'utf8mb4'),
        $cfg['user'],
        $cfg['pass']
    );
    R::freeze(false);
    $pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
    echo "      ✓ App connection OK.\n\n";
} catch (Throwable $e) {
    echo "      ✗ App connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// ── 4. Schema tables present + row counts ───────────────────────────────
echo "[4/4] Inspecting schema...\n";
$expected = ['user', 'services', 'appointment', 'reviews', 'payments'];

$found = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = " . $pdo->quote($cfg['dbname'])
)->fetchAll(PDO::FETCH_COLUMN);

$found = array_map('strtolower', $found);
$missing = array_diff($expected, $found);

if ($missing) {
    echo "      ✗ Missing tables: " . implode(', ', $missing) . "\n";
    echo "        Run the seed to create them:  php db/seed.php\n";
    exit(1);
}

echo "      ✓ All tables present.\n\n";
echo "      Row counts:\n";
foreach ($expected as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    printf("        %-15s %d\n", $table, $count);
}

echo "\n✓ All checks passed. The app can talk to MariaDB.\n";
