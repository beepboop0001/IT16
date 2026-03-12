<?php
// config/Database.php

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}

    /**
     * Load a value from environment variables.
     * Falls back to parsing a .env file in the project root if needed.
     */
    public static function env(string $key, string $default = ''): string {
        // 1. Check actual environment variables first (set by server/OS)
        $val = getenv($key);
        if ($val !== false) return $val;

        // 2. Check $_ENV superglobal
        if (isset($_ENV[$key])) return $_ENV[$key];

        // 3. Fall back to parsing .env file
        static $parsed = null;
        if ($parsed === null) {
            $parsed = [];
            $envFile = __DIR__ . '/../.env';
            if (!file_exists($envFile)) {
                throw new RuntimeException(
                    '.env file not found. Copy .env.example to .env and configure it.'
                );
            }
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                // Skip comments and invalid lines
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$envKey, $envVal] = explode('=', $line, 2);
                $parsed[trim($envKey)] = trim($envVal);
            }
        }

        return $parsed[$key] ?? $default;
    }

    public static function get(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host   = self::env('DB_HOST', 'localhost');
        $dbname = self::env('DB_NAME');
        $user   = self::env('DB_USER');
        $pass   = self::env('DB_PASS', '');

        if (empty($dbname) || empty($user)) {
            throw new RuntimeException(
                'Database configuration is incomplete. Check DB_NAME and DB_USER in your .env file.'
            );
        }

        try {
            self::$instance = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // Use real prepared statements
                ]
            );
        } catch (PDOException $e) {
            // Log the real error internally, but never expose DB details to the browser
            error_log('[Database] Connection failed: ' . $e->getMessage());
            throw new RuntimeException(
                'Unable to connect to the database. Please contact your system administrator.'
            );
        }

        return self::$instance;
    }
}