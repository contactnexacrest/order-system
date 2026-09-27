<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DataExportController;
use App\Tests\Support\DbTestCase;

/**
 * Point 6 — end-to-end check that the controller actually streams a real
 * dump (not a stub) and, just as importantly, logs every export to the
 * audit log — this is the one feature in the app that can hand someone
 * every record in the database, including password hashes, so a silent,
 * untracked download would be its own gap.
 */
final class DataExportControllerTest extends DbTestCase
{
    public function testIndexRendersWithoutError(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        $controller = new DataExportController();
        ob_start();
        $controller->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('Data Export', $output);
        self::assertStringContainsString('/admin/data-export/schema', $output);
        self::assertStringContainsString('/admin/data-export/data', $output);
    }

    public function testDownloadSchemaStreamsRealDumpAndLogsAudit(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        $controller = new DataExportController();
        ob_start();
        $controller->downloadSchema([]);
        $output = ob_get_clean();

        self::assertStringContainsString('CREATE TABLE', $output);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'DATA_EXPORT_SCHEMA' AND user_id = {$userId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testDownloadDataStreamsRealDumpAndLogsAudit(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->createTestClient();
        $_SESSION['_auth_user_id'] = $userId;

        $controller = new DataExportController();
        ob_start();
        $controller->downloadData([]);
        $output = ob_get_clean();

        self::assertStringContainsString('INSERT INTO', $output);
        self::assertStringContainsString('PHPUnit Test Buyer Ltd', $output);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'DATA_EXPORT_DATA' AND user_id = {$userId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }
}
