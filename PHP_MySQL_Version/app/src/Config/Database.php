<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. One connection per request lifecycle (no pooling needed —
 * PHP is share-nothing per request on both Bluehost and locally).
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $db   = Env::get('DB_DATABASE');
        $user = Env::get('DB_USERNAME');
        $pass = Env::get('DB_PASSWORD', '');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never leak DSN/credentials in the exception surface to the client.
            error_log('[DB CONNECT FAILURE] ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed. Check server error log.');
        }

        return self::$connection;
    }
}
