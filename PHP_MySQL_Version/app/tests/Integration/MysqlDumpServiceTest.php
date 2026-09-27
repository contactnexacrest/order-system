<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\MysqlDumpService;
use App\Tests\Support\DbTestCase;

/**
 * Point 6 — the data-export/migration tool. These tests actually shell
 * out to the real mysqldump binary against the disposable
 * nexacrest_phpunit_test database (never a mock), the same way
 * DbTestCase::runSql() already shells out to the real mysql CLI to set
 * that database up — if mysqldump isn't on PATH or the command-building
 * is wrong, this fails loudly instead of a mock silently agreeing with
 * whatever the service claims to have done.
 */
final class MysqlDumpServiceTest extends DbTestCase
{
    public function testDumpSchemaContainsStructureButNoRowData(): void
    {
        $clientId = $this->createTestClient();

        $path = MysqlDumpService::dumpSchema();
        try {
            self::assertFileExists($path);
            $contents = (string) file_get_contents($path);

            self::assertStringContainsString('CREATE TABLE', $contents);
            self::assertStringContainsString('`clients`', $contents);
            self::assertStringNotContainsString('INSERT INTO', $contents);
            self::assertStringNotContainsString('PHPUnit Test Buyer Ltd', $contents);
        } finally {
            @unlink($path);
        }

        self::assertGreaterThan(0, $clientId);
    }

    public function testDumpDataContainsRowDataButNoCreateTable(): void
    {
        $this->createTestClient();

        $path = MysqlDumpService::dumpData();
        try {
            self::assertFileExists($path);
            $contents = (string) file_get_contents($path);

            self::assertStringContainsString('INSERT INTO', $contents);
            self::assertStringContainsString('PHPUnit Test Buyer Ltd', $contents);
            self::assertStringNotContainsString('CREATE TABLE', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function testDumpDataUsesCompleteInsertWithExplicitColumnNames(): void
    {
        $path = MysqlDumpService::dumpData();
        try {
            $contents = (string) file_get_contents($path);
            // --complete-insert makes every INSERT spell out its column
            // list, so a later import stays correct even if the importing
            // database's column order ever differs from the exporting one.
            self::assertMatchesRegularExpression('/INSERT INTO `clients` \([^)]+\) VALUES/', $contents);
        } finally {
            @unlink($path);
        }
    }
}
