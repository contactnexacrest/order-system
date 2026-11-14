<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner D-371 (13 Nov 2026): the live site checks its migrations with the read-only
 * docs/checks/check_all_migrations.sql in phpMyAdmin. Every migration file must have a row in it,
 * so a new migration cannot be forgotten there. Node twin: migrationCheckScriptCoverage.test.js.
 */
final class MigrationCheckScriptCoverageTest extends TestCase
{
    public function testEveryMigrationFileHasARowInTheCheckScript(): void
    {
        $root = dirname(__DIR__, 4);
        $check = (string) file_get_contents($root . '/docs/checks/check_all_migrations.sql');
        $missing = [];
        foreach (glob($root . '/docs/migrations/*.sql') ?: [] as $file) {
            if (!preg_match('/^(\d{4}-\d{2})-(\d{2}[a-z]+)-/', basename($file), $m)) {
                continue;
            }
            // Rows are written either as the full id '2026-11-13g' or, in the grouped part, as '13g'.
            if (!str_contains($check, "'{$m[1]}-{$m[2]}'") && !str_contains($check, "'{$m[2]}'")) {
                $missing[] = basename($file);
            }
        }
        self::assertSame([], $missing, 'Add a row for each of these migrations to docs/checks/check_all_migrations.sql (D-371).');
    }
}
