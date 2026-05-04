<?php

declare(strict_types=1);

/**
 * Application configuration.
 * Edit this file to match your local environment, then commit a sample
 * (config.example.php) and add config.php to .gitignore for production.
 */

return [
    // ── Database (MariaDB / MySQL) ──────────────────────────────────────
    'database' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'dbname'  => 'web_application',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ── App ─────────────────────────────────────────────────────────────
    'app' => [
        'name'         => 'Nail Salon',
        'env'          => 'development',   // development | production
        'debug'        => true,
        'default_lang' => 'en',
    ],

    // ── Auth: emails listed here automatically get the 'admin' role on
    //   signup, and existing users with these emails are upgraded to admin
    //   when UserModel::isAdminEmail() is checked.
    'admin_emails' => [
        'admin@example.com',
        'vuongthevinh07@gmail.com',
    ],
];
