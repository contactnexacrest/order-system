<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AmendmentController;
use App\Repositories\AmendmentRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster F: the Amendment document-generate
 * action never called AuditLogRepository::log(), so it never notified
 * anyone either. DisputeController::uploadDocument wraps
 * FileUploadService::handleUpload(), whose move_uploaded_file() call
 * cannot succeed outside a real HTTP upload (same constraint documented
 * in BlCopyUploadTest.php) — verified by code review instead; see the
 * Node mirror for its coverage.
 */
final class N08AuditLogCoverageClusterFTest extends DbTestCase
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

    public function testGenerateDocumentIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = AmendmentRepository::create(
            'AMD-N08-TEST',
            $orderId,
            'N08 test amendment',
            'importer',
            ['note' => 'snapshot'],
            null, null, null, null, null, null, null
        );
        $mdUserId = $this->createTestUser('Managing Director');
        AmendmentRepository::approveByMd($amendmentId, $mdUserId);
        $_SESSION['_auth_user_id'] = $mdUserId;

        ob_start();
        (new AmendmentController())->generateDocument(['amendmentId' => (string) $amendmentId]);
        ob_end_clean();

        self::assertSame('AMENDMENT_DOCUMENT_GENERATED', $this->lastAuditLogAction()['action_type']);
    }
}
