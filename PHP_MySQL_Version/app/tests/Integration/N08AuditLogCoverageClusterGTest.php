<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ReportController;
use App\Repositories\ReportDefinitionRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster G: the 3 saved-report actions never
 * called AuditLogRepository::log(), so none of them ever notified
 * anyone either.
 */
final class N08AuditLogCoverageClusterGTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    private function lastAuditLogAction(): ?array
    {
        $stmt = Database::connection()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        return $stmt->fetch() ?: null;
    }

    public function testSaveDefinitionIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['name' => 'N08 Test Report', 'report_type' => 'aggregate'];

        ob_start();
        (new ReportController())->saveDefinition([]);
        ob_end_clean();

        self::assertSame('REPORT_DEFINITION_SAVED', $this->lastAuditLogAction()['action_type']);
    }

    public function testUpdateDefinitionIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $reportId = ReportDefinitionRepository::create('Original Name', 'aggregate', $userId, 'private', [], []);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['name' => 'Updated Name'];

        ob_start();
        (new ReportController())->updateDefinition(['reportId' => (string) $reportId]);
        ob_end_clean();

        self::assertSame('REPORT_DEFINITION_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testDeleteDefinitionIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $reportId = ReportDefinitionRepository::create('To Delete', 'aggregate', $userId, 'private', [], []);
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new ReportController())->deleteDefinition(['reportId' => (string) $reportId]);
        ob_end_clean();

        self::assertSame('REPORT_DEFINITION_DELETED', $this->lastAuditLogAction()['action_type']);
    }
}
