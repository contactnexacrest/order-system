<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\ClientPortalController;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report M-02: "Portal visibility of history/downloads
 * configurable globally and per client, independently." The
 * order-history half of this already existed (see
 * ClientOrderHistoryVisibilityTest) — this covers the document-downloads
 * half added to close the gap: a global default
 * (company_settings.client_portal_show_document_downloads) with a
 * per-client override (clients.document_downloads_visibility_override,
 * NULL = inherit), gated both on the page (the Documents section) and
 * server-side (downloadDocument() itself, so the link being hidden isn't
 * the only thing stopping a direct URL guess/replay).
 */
final class ClientDocumentDownloadsVisibilityTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    /** @var string[] temp files created by createSentDocumentWithRealPdf(), cleaned up in tearDown() */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** Unlike DbTestCase::createTestFile(), this writes a real file at server_path too, so a "downloads are visible" test can reach an actual success instead of 404-ing on "file missing from storage". */
    private function createSentDocumentWithRealPdf(int $orderId): int
    {
        $pdo = Database::connection();
        $docTypeId = (int) $pdo->query("SELECT id FROM document_types WHERE code = 'QT'")->fetchColumn();

        $path = sys_get_temp_dir() . '/phpunit-m02-test-file-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, '%PDF-1.4 test');
        $this->tempFiles[] = $path;

        $stmt = $pdo->prepare(
            'INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
             VALUES (:order_id, \'RECEIVED\', :path, :uuid, :orig, 13, \'application/pdf\')'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'path'     => $path,
            'uuid'     => bin2hex(random_bytes(16)) . '.pdf',
            'orig'     => 'test-file.pdf',
        ]);
        $fileId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
             VALUES (:order_id, :dt, 'SC/QT/TEST/001', 'sent', :pdf)"
        );
        $stmt->execute(['order_id' => $orderId, 'dt' => $docTypeId, 'pdf' => $fileId]);
        return (int) $pdo->lastInsertId();
    }

    public function testOverrideNullFollowsGlobalDefault(): void
    {
        $clientId = $this->createTestClient();
        $client = ClientRepository::find($clientId);
        self::assertNull($client['document_downloads_visibility_override']);

        CompanySettingsRepository::set('client_portal_show_document_downloads', '1');
        self::assertTrue(ClientRepository::documentDownloadsVisible(ClientRepository::find($clientId)));

        CompanySettingsRepository::set('client_portal_show_document_downloads', '0');
        self::assertFalse(ClientRepository::documentDownloadsVisible(ClientRepository::find($clientId)));
    }

    public function testPerClientOverrideWinsOverTheGlobalDefault(): void
    {
        $clientId = $this->createTestClient();

        CompanySettingsRepository::set('client_portal_show_document_downloads', '0');
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, true);
        self::assertTrue(ClientRepository::documentDownloadsVisible(ClientRepository::find($clientId)), 'client override forces downloads on even though the global default is off');

        CompanySettingsRepository::set('client_portal_show_document_downloads', '1');
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, false);
        self::assertFalse(ClientRepository::documentDownloadsVisible(ClientRepository::find($clientId)), 'client override forces downloads off even though the global default is on');
    }

    public function testOrderPageHidesTheDocumentsSectionWhenDownloadsAreHidden(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->createSentDocumentWithRealPdf($orderId);
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, false);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('Download PDF', $html);
    }

    public function testOrderPageShowsTheDocumentsSectionWhenDownloadsAreVisible(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->createSentDocumentWithRealPdf($orderId);
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, true);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Download PDF', $html);
    }

    public function testDownloadDocumentIsRefusedServerSideWhenDownloadsAreHiddenEvenWithARealDocument(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createSentDocumentWithRealPdf($orderId);
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, false);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->downloadDocument(['id' => (string) $documentId]);
        ob_end_clean();

        self::assertSame(404, http_response_code(), 'hiding downloads must refuse the direct download URL too, not just hide the link');
    }

    public function testDownloadDocumentStillWorksWhenDownloadsAreVisible(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createSentDocumentWithRealPdf($orderId);
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, true);
        $_SESSION['_client_portal_client_id'] = $clientId;

        http_response_code(200);
        ob_start();
        (new ClientPortalController())->downloadDocument(['id' => (string) $documentId]);
        ob_end_clean();

        self::assertNotSame(404, http_response_code());
    }

    public function testSetOverrideControllerActionWritesTheValueAndAuditLog(): void
    {
        $clientId = $this->createTestClient();
        $_POST = ['document_downloads_visibility_override' => '0'];

        ob_start();
        (new ClientController())->setDocumentDownloadsVisibilityOverride(['id' => (string) $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertSame(0, (int) $client['document_downloads_visibility_override']);

        $stmt = Database::connection()->prepare(
            "SELECT * FROM audit_log WHERE action_type = 'CLIENT_DOCUMENT_DOWNLOADS_VISIBILITY_CHANGED' AND entity_type = 'clients' AND entity_id = :id"
        );
        $stmt->execute(['id' => $clientId]);
        self::assertNotFalse($stmt->fetch());
    }

    public function testSetOverrideControllerActionClearsBackToInheritOnEmptyPost(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setDocumentDownloadsVisibilityOverride($clientId, true);

        $_POST = ['document_downloads_visibility_override' => ''];
        ob_start();
        (new ClientController())->setDocumentDownloadsVisibilityOverride(['id' => (string) $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertNull($client['document_downloads_visibility_override']);
    }
}
