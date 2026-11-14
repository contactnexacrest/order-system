<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Env;
use PHPUnit\Framework\TestCase;

/**
 * Owner request (11 Nov 2026): "make sure schema and seed will always be
 * ready for a fresh install". A fresh install is docs/schema.sql then
 * docs/seed.sql and NOTHING else — the files in docs/migrations (repo root,
 * shared by both stacks) exist only to bring an existing live database up
 * to date, so every change they make must already be in schema.sql/seed.sql.
 *
 * This test proves it: it builds a throwaway database from this stack's
 * schema.sql + seed.sql, takes a deterministic mysqldump snapshot, applies
 * every migration file in filename order (each must succeed), snapshots
 * again and asserts both snapshots are identical (timestamp literals
 * normalised, since a migration may touch updated_at). A difference means a
 * migration changes something a fresh install does not have — add it to
 * schema.sql/seed.sql (both stacks).
 *
 * Parity: NODE_MySQL_Version/app/tests/integration/freshInstallComplete.test.js.
 * Never touches nexacrest_phpunit_test or any dev database.
 */
final class FreshInstallCompleteTest extends TestCase
{
    private string $dbName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbName = 'zz_freshcheck_php_' . getmypid();
        $this->runMysql('', "DROP DATABASE IF EXISTS `{$this->dbName}`; CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
    }

    protected function tearDown(): void
    {
        try {
            $this->runMysql('', "DROP DATABASE IF EXISTS `{$this->dbName}`;");
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<int,string> */
    private function credentialArgs(): array
    {
        return [
            '-h', Env::get('DB_HOST', '127.0.0.1'),
            '-P', Env::get('DB_PORT', '3306'),
            '-u', Env::get('DB_USERNAME'),
            '-p' . Env::get('DB_PASSWORD', ''),
        ];
    }

    /**
     * @param array<int,string> $cmd
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function runProcess(array $cmd, string $stdin = ''): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start ' . $cmd[0] . '.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** Runs SQL through the mysql CLI (schema.sql uses DELIMITER, which only the CLI understands). */
    private function runMysql(string $database, string $sql, bool $plainAlter = false): string
    {
        $cmd = array_merge(['mysql'], $this->credentialArgs());
        if ($plainAlter) {
            // Owner D-371 (13 Nov 2026): run every statement of a "PLAIN ALTER" file, as phpMyAdmin
            // would after "Duplicate column name", so the rest of the file is still checked.
            $cmd[] = '--force';
        }
        if ($database !== '') {
            $cmd[] = $database;
        }
        [$exit, , $stderr] = $this->runProcess($cmd, $sql);
        // A "PLAIN ALTER" file on a fresh install may only fail with 1060 Duplicate column name
        // (its columns are already in schema.sql) — any other error still fails the test.
        if ($exit !== 0 && $plainAlter && preg_match('/ERROR 1060 \(42S21\)/', $stderr)
            && !preg_match('/ERROR (?!1060)\d+/', $stderr)) {
            return '';
        }
        return $exit === 0 ? '' : "exit {$exit}: {$stderr}";
    }

    private function loadFile(string $path): void
    {
        $sql = (string) file_get_contents($path);
        $error = $this->runMysql($this->dbName, $sql, str_contains($sql, 'PLAIN ALTER (owner D-371'));
        self::assertSame('', $error, basename($path) . ' failed to load: ' . $error);
    }

    private function snapshot(): string
    {
        $cmd = array_merge(['mysqldump'], $this->credentialArgs(), [
            '--skip-comments', '--skip-dump-date', '--order-by-primary', '--skip-extended-insert',
            '--routines', '--triggers', $this->dbName,
        ]);
        [$exit, $stdout, $stderr] = $this->runProcess($cmd);
        self::assertSame(0, $exit, 'mysqldump failed: ' . $stderr);
        // A migration may legitimately touch updated_at, and a guarded insert that finds its row
        // already there still moves a table's AUTO_INCREMENT counter; neither is content.
        $normalised = (string) preg_replace("/'\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}'/", "'TS'", $stdout);
        return (string) preg_replace('/ AUTO_INCREMENT=\\d+/', '', $normalised);
    }

    private function tableCount(): int
    {
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'), $this->dbName),
            (string) Env::get('DB_USERNAME'),
            (string) Env::get('DB_PASSWORD', ''),
        );
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
        $stmt->execute([$this->dbName]);
        return (int) $stmt->fetchColumn();
    }

    /** First ~20 lines that differ, as "-" (fresh) / "+" (after migrations). */
    private static function firstDifferences(string $fresh, string $migrated, int $limit = 20): string
    {
        $a = explode("\n", $fresh);
        $b = explode("\n", $migrated);
        $onlyFresh = array_diff($a, $b);
        $onlyMigrated = array_diff($b, $a);
        $out = [];
        foreach ($onlyFresh as $line) {
            $out[] = '- ' . $line;
        }
        foreach ($onlyMigrated as $line) {
            $out[] = '+ ' . $line;
        }
        if ($out === []) {
            $out[] = '(same lines, different order or count)';
        }
        $total = count($out);
        $shown = array_slice($out, 0, $limit);
        $shown = array_map(static fn (string $l): string => mb_strimwidth($l, 0, 300, '…'), $shown);
        return implode("\n", $shown) . ($total > $limit ? "\n… and " . ($total - $limit) . ' more' : '');
    }

    public function testMigrationsChangeNothingOnAFreshInstall(): void
    {
        $stackDocs = __DIR__ . '/../../../docs';
        $migrationsDir = __DIR__ . '/../../../../docs/migrations';
        self::assertFileExists($stackDocs . '/schema.sql');
        self::assertFileExists($stackDocs . '/seed.sql');
        self::assertDirectoryExists($migrationsDir);

        $this->loadFile($stackDocs . '/schema.sql');
        $this->loadFile($stackDocs . '/seed.sql');

        $fresh = $this->snapshot();
        self::assertSame(
            $this->tableCount(),
            preg_match_all('/^CREATE TABLE /m', $fresh),
            'sanity check: the snapshot must hold every table of the fresh database'
        );

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $path) {
            $this->loadFile($path);
        }

        $migrated = $this->snapshot();
        self::assertTrue(
            $fresh === $migrated,
            "A migration in docs/migrations changes a fresh install (schema.sql + seed.sql), so schema.sql/seed.sql "
            . "are missing that change. Put it into both stacks' docs/schema.sql / docs/seed.sql.\n"
            . "Lines only in the fresh install (-) / only after the migrations (+):\n"
            . self::firstDifferences($fresh, $migrated)
        );
    }
}
