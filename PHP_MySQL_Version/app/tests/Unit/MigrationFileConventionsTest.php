<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * External QA report L-31 (Low, Both): "Migration files corrupt special
 * characters: none of the 28 files starts with SET NAMES utf8mb4 (seed.sql
 * does). '2026-10-21-extended-terms-payment-preset.sql' stores 'Extended
 * Terms â€” 30/70', which then prints on PI/OC." Reproduced directly
 * against a scratch database: applying that migration without a
 * session-level `SET NAMES utf8mb4;` first stores the em dash as
 * double-encoded UTF-8 (the mysql CLI's connection falls back to its own
 * default character set, which isn't utf8mb4, for a bare `mysql db <
 * file.sql` invocation — exactly how every migration here is applied).
 * Every migration file now starts with `SET NAMES utf8mb4;`, matching
 * seed.sql's own convention, so this scans the whole folder to make sure
 * a future migration can't reintroduce the gap.
 */
final class MigrationFileConventionsTest extends TestCase
{
    /** @return array<int,string> */
    private function migrationFiles(): array
    {
        $dir = __DIR__ . '/../../../../docs/migrations';
        $files = glob($dir . '/*.sql');
        self::assertNotEmpty($files, 'sanity check: the migrations folder must actually be found and non-empty');
        return $files;
    }

    public function testEveryMigrationFileSetsUtf8mb4BeforeAnyStatement(): void
    {
        foreach ($this->migrationFiles() as $path) {
            $contents = (string) file_get_contents($path);
            // Strip leading `--` comment lines and blank lines, exactly
            // the region seed.sql's own header occupies before its
            // SET NAMES line, so a SET NAMES anywhere further down
            // (after a real statement) doesn't count.
            $lines = preg_split('/\R/', $contents);
            $firstRealLine = null;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }
                $firstRealLine = $trimmed;
                break;
            }
            self::assertNotNull($firstRealLine, basename($path) . ' must contain at least one real statement');
            self::assertSame(
                'SET NAMES utf8mb4;',
                $firstRealLine,
                basename($path) . " must start with SET NAMES utf8mb4; (right after its header comment) — otherwise a bare `mysql db < file.sql` run can silently corrupt any non-ASCII character (em dash, curly quotes, ₹, ...) it inserts."
            );
        }
    }

    /**
     * External QA report L-32 (Low, Both): "6 migrations are not safe to
     * re-run (duplicate permission/column/table errors) while others are
     * guarded." A migration only needs one of these guards somewhere in
     * its body to be safe to re-run twice; this doesn't try to verify the
     * guard is applied to every statement, only that the file is making
     * a deliberate attempt at idempotency rather than a bare INSERT/
     * ALTER with no guard at all.
     */
    public function testEveryMigrationFileHasSomeIdempotencyGuard(): void
    {
        $guardPatterns = [
            '/\bNOT EXISTS\b/i',
            '/\bIF NOT EXISTS\b/i',
            '/\bINSERT IGNORE\b/i',
            '/\bON DUPLICATE KEY UPDATE\b/i',
            '/\bINFORMATION_SCHEMA\b/i',
            '/\bDROP .* IF EXISTS\b/i',
            '/\bCREATE OR REPLACE\b/i',
        ];

        $unguarded = [];
        foreach ($this->migrationFiles() as $path) {
            $contents = (string) file_get_contents($path);
            $hasGuard = false;
            foreach ($guardPatterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hasGuard = true;
                    break;
                }
            }
            // A migration whose only statement type is UPDATE or DELETE
            // is safe to re-run by construction: its own WHERE clause
            // matches a specific row/value, so a second run simply finds
            // nothing left to match and is a clean no-op — the same
            // property these explicit guards exist to provide for
            // INSERT/CREATE/ALTER. (SET NAMES is not a statement that
            // can conflict.)
            if (!$hasGuard) {
                // Strip every comment line BEFORE splitting on ';' — a
                // header comment's own prose can easily contain a
                // semicolon (as one does here), which would otherwise
                // split the file in the wrong place.
                $withoutComments = implode("\n", array_filter(
                    preg_split('/\R/', $contents),
                    static fn (string $line): bool => !str_starts_with(trim($line), '--')
                ));
                $statements = array_filter(
                    array_map('trim', explode(';', $withoutComments)),
                    static fn (string $s): bool => $s !== '' && !preg_match('/^SET NAMES/i', $s)
                );
                $hasGuard = $statements !== [] && array_reduce(
                    $statements,
                    static fn (bool $carry, string $s): bool => $carry && (bool) preg_match('/^\s*(UPDATE|DELETE)\b/i', $s),
                    true
                );
            }
            if (!$hasGuard) {
                $unguarded[] = basename($path);
            }
        }

        self::assertSame([], $unguarded, 'every migration file must guard its statements so the file is safe to re-run (idempotent) — see the fix direction for L-32');
    }
}
