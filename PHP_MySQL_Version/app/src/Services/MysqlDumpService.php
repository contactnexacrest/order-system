<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Point 6 of the "fresh install must never lose data" request: a Super
 * Admin (or a permission-gated staff member) needs to pull the live
 * database out as two separate, portable files before a reinstall —
 * structure and data kept apart on purpose.
 *
 * Why two files instead of one combined dump: after a reinstall, the new
 * code's own docs/schema.sql already creates the up-to-date structure
 * (including any columns/tables added since this export was taken). The
 * structure file exported here is a point-in-time reference an operator
 * (or Claude) diffs against that new schema.sql before importing data, to
 * confirm nothing the old data depends on was renamed, dropped, or
 * shrunk — see docs/SOP/18-data-export-migration.md for the full restore
 * procedure. The data file is what actually gets imported, straight into
 * the freshly-installed (structurally current) database.
 *
 * Shells out to the real `mysqldump` binary rather than hand-rolling a
 * dumper — this is a maintenance tool used rarely and deliberately, not a
 * hot path, and mysqldump already handles every edge case (escaping,
 * generated columns, FK-safe ordering) correctly. The DB password is
 * passed via the MYSQL_PWD environment variable (proc_open's own env
 * argument, not putenv()) rather than a command-line flag, so it never
 * appears in `ps` output; the command itself is built as an array so
 * proc_open never invokes a shell and no argument needs escaping.
 */
final class MysqlDumpService
{
    /** @return string absolute path to a temp file the caller must unlink() after streaming it */
    public static function dumpSchema(): string
    {
        return self::run([
            '--no-data',
            '--skip-triggers',
            '--skip-comments',
            '--skip-add-locks',
        ]);
    }

    /** @return string absolute path to a temp file the caller must unlink() after streaming it */
    public static function dumpData(): string
    {
        return self::run([
            '--no-create-info',
            '--skip-triggers',
            '--single-transaction',
            '--complete-insert',
            '--hex-blob',
            '--skip-comments',
        ]);
    }

    /** @param string[] $extraArgs */
    private static function run(array $extraArgs): string
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $db   = Env::get('DB_DATABASE');
        $user = Env::get('DB_USERNAME');
        $pass = Env::get('DB_PASSWORD', '');

        $cmd = array_merge(
            ['mysqldump', '-h', $host, '-P', $port, '-u', $user],
            $extraArgs,
            [$db]
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'nexacrest_dump_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not create a temp file for the database export.');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $tmpPath, 'w'], 2 => ['pipe', 'w']];
        // $_SERVER (unlike $_ENV) can hold non-string entries in CLI SAPI
        // (argv is an array) — proc_open's $env builds "KEY=VALUE" strings
        // from every entry, so those must be filtered out first or it
        // throws an "Array to string conversion" warning.
        $env = $pass !== '' ? array_merge(array_filter($_SERVER, 'is_string'), ['MYSQL_PWD' => $pass]) : null;
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Could not start mysqldump.');
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            @unlink($tmpPath);
            throw new \RuntimeException('mysqldump failed (exit ' . $exitCode . '): ' . $stderr);
        }

        return $tmpPath;
    }
}
