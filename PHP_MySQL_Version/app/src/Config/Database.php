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

    /**
     * Runs $work in one database transaction: everything it writes is kept,
     * or — when it throws — nothing is (QA Round 6, R6-N22). Inside an
     * already open transaction it simply runs, and the outer one decides.
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

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
            // User-reported batch item 6: the connection's session time
            // zone defaulted to SYSTEM (UTC on this host), so NOW()/
            // CURRENT_TIMESTAMP wrote UTC wall-clock values into every
            // TIMESTAMP column while bootstrap.php's own
            // date_default_timezone_set('Asia/Kolkata') meant every
            // PHP-computed date()/DateTimeImmutable() value (token
            // expiries, the OC-acknowledgment deadline, etc.) was ALREADY
            // IST wall-clock — a split-timezone app, not just a display
            // bug: documents re-rendered from a stored generated_at could
            // show the wrong calendar date, and a TIMESTAMP comparison
            // against NOW() (e.g. "has this link expired?",
            // "is the 48-hour acknowledgment window up?") was off by the
            // full 5.5-hour gap between UTC and IST. Fixing the session to
            // the same Asia/Kolkata offset makes every TIMESTAMP column
            // consistent with PHP's own clock on both reads and writes.
            // Safe for data already stored: a TIMESTAMP column keeps its
            // true UTC instant internally regardless of session time
            // zone — only the wall-clock string MySQL converts it to/from
            // changes, so existing rows now correctly display as IST with
            // no migration needed. A fixed offset (not a named zone) is
            // used so this works even when the host's MySQL has no
            // time zone tables loaded.
            self::$connection->exec("SET time_zone = '+05:30'");
        } catch (PDOException $e) {
            // Never leak DSN/credentials in the exception surface to the client.
            error_log('[DB CONNECT FAILURE] ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed. Check server error log.');
        }

        return self::$connection;
    }
}
