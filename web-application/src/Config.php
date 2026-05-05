<?php

declare(strict_types=1);

namespace App;

/**
 * Config — Application Configuration Loader
 * ===========================================
 * Reads config/config.php once, caches the result in memory,
 * and provides a dot-notation getter so you can access nested values easily.
 *
 * Usage examples:
 *   Config::get('database.host')      → '127.0.0.1'
 *   Config::get('app.debug')          → true
 *   Config::get('missing', 'default') → 'default'
 *
 * The class is never instantiated — all methods are static.
 */
class Config
{
    private static ?array $config = null;

    public static function load(?string $path = null): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path ??= __DIR__ . '/../config/config.php';

        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new \RuntimeException('Config file must return an array.');
        }

        self::$config = $loaded;
        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $config = self::load();
        $segments = explode('.', $key);
        $value = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function reset(): void
    {
        self::$config = null;
    }
}
