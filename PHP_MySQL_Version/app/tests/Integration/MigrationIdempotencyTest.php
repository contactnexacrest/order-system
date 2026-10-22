<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Env;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-32: "6 migrations are not safe to re-run
 * (duplicate permission/column/table errors) while others are guarded."
 * MigrationFileConventionsTest (Unit) checks each file's SQL text
 * against a set of known-safe guard patterns, but that static check has
 * a real blind spot it doesn't claim to close: a guard pattern anywhere
 * in the file makes the WHOLE file look "guarded" even if one of its
 * OTHER statements has none (exactly how
 * 2026-10-21-client-order-history-visibility.sql's unguarded ALTER TABLE
 * ADD COLUMN slipped past that check — only caught here, by actually
 * running it). This test is the authoritative one: it applies every
 * migration file in docs/migrations TWICE, in order, against the real
 * (already fully-migrated) shared test database and asserts neither
 * pass errors — the exact "was this already applied?" scenario an ops
 * person re-running migrations by accident would hit.
 */
final class MigrationIdempotencyTest extends DbTestCase
{
    /** @return array<int,string> */
    private function migrationFiles(): array
    {
        $dir = __DIR__ . '/../../../../docs/migrations';
        $files = glob($dir . '/*.sql');
        sort($files);
        self::assertNotEmpty($files, 'sanity check: the migrations folder must actually be found and non-empty');
        return $files;
    }

    private function applyFile(string $path): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = [
            'mysql',
            '-h', Env::get('DB_HOST', '127.0.0.1'),
            '-P', Env::get('DB_PORT', '3306'),
            '-u', Env::get('DB_USERNAME'),
            '-p' . Env::get('DB_PASSWORD', ''),
            'nexacrest_phpunit_test',
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start mysql CLI.');
        }
        fwrite($pipes[0], (string) file_get_contents($path));
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return $exitCode === 0 ? '' : "exit {$exitCode}: {$stderr}";
    }

    public function testEveryMigrationFileCanBeAppliedTwiceWithoutError(): void
    {
        $files = $this->migrationFiles();

        // First pass: the shared test DB is already fully migrated (it's
        // built from the current schema.sql/seed.sql), so this pass is
        // itself already exercising "re-run against an up-to-date DB" —
        // but run it explicitly before the real second pass so a failure
        // here is reported distinctly from one on the second pass.
        foreach ($files as $path) {
            $error = $this->applyFile($path);
            self::assertSame('', $error, basename($path) . ' failed on the FIRST pass: ' . $error);
        }

        foreach ($files as $path) {
            $error = $this->applyFile($path);
            self::assertSame('', $error, basename($path) . ' failed on the SECOND pass (not idempotent): ' . $error);
        }
    }
}
