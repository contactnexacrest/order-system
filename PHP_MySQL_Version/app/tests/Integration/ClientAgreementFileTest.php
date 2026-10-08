<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Helpers\Flash;
use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Item 2 — the actual signed-agreement file, expiry date, force-expire,
 * and renew. move_uploaded_file() never succeeds under PHPUnit (not a real
 * HTTP upload), so the upload/renew-with-file controller paths are covered
 * only up to FileUploadService's own "no file selected" guard (same
 * established pattern as ClientBuyerPoUploadTest); the actual file-fields
 * persistence is covered directly against ClientRepository, and the
 * force-expire/renew-without-file paths are covered end-to-end through
 * the controller.
 */
final class ClientAgreementFileTest extends DbTestCase
{
    private ClientController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ClientController();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        http_response_code(200);
    }

    private function auditLogCount(string $actionType, string $field, int $clientId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM audit_log WHERE action_type = :action_type AND entity_type = \'clients\'
             AND entity_id = :entity_id AND field_name = :field'
        );
        $stmt->execute(['action_type' => $actionType, 'entity_id' => $clientId, 'field' => $field]);
        return (int) $stmt->fetchColumn();
    }

    public function testUploadAgreementWithNoFileSelectedShowsErrorAndChangesNothing(): void
    {
        $clientId = $this->createTestClient();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->uploadAgreement(['id' => $clientId]);
        ob_end_clean();

        self::assertNotSame(404, http_response_code());
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('No file was uploaded', $flash[0]['message']);
        self::assertNull(ClientRepository::find($clientId)['agreement_file_path']);
    }

    public function testUploadAgreement404sForMissingClient(): void
    {
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->uploadAgreement(['id' => 999999999]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testSetAgreementFilePersistsPathAndClearsForceExpired(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setAgreementForceExpired($clientId, true);
        self::assertSame(1, (int) ClientRepository::find($clientId)['agreement_force_expired']);

        ClientRepository::setAgreementFile($clientId, '/tmp/fake-agreement.pdf', 'Signed Agreement.pdf', '2030-01-01');

        $client = ClientRepository::find($clientId);
        self::assertSame('/tmp/fake-agreement.pdf', $client['agreement_file_path']);
        self::assertSame('Signed Agreement.pdf', $client['agreement_file_original_name']);
        self::assertSame('2030-01-01', $client['agreement_expiry_date']);
        self::assertSame(0, (int) $client['agreement_force_expired']);
        self::assertNotNull($client['agreement_uploaded_at']);
    }

    public function testSetAgreementFileWithNullExpiryMeansNoAutomaticExpiry(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setAgreementFile($clientId, '/tmp/fake.pdf', 'fake.pdf', null);

        self::assertNull(ClientRepository::find($clientId)['agreement_expiry_date']);
    }

    public function testForceExpireAgreementSetsFlagAndLogsAudit(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setAgreementFile($clientId, '/tmp/fake.pdf', 'fake.pdf', '2030-01-01');
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->forceExpireAgreement(['id' => $clientId]);
        ob_end_clean();

        self::assertSame(1, (int) ClientRepository::find($clientId)['agreement_force_expired']);
        self::assertSame(1, $this->auditLogCount('CLIENT_AGREEMENT_FORCE_EXPIRED', 'agreement_force_expired', $clientId));
    }

    public function testForceExpireAgreement404sForMissingClient(): void
    {
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->forceExpireAgreement(['id' => 999999999]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testRenewAgreementWithoutNewFileUpdatesExpiryAndClearsForceExpired(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setAgreementFile($clientId, '/tmp/fake.pdf', 'fake.pdf', '2025-01-01');
        ClientRepository::setAgreementForceExpired($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['agreement_expiry_date'] = '2030-06-15';

        ob_start();
        $this->controller->renewAgreement(['id' => $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertSame('2030-06-15', $client['agreement_expiry_date']);
        self::assertSame(0, (int) $client['agreement_force_expired']);
        // The file itself is untouched when renewing without a new upload.
        self::assertSame('/tmp/fake.pdf', $client['agreement_file_path']);
        self::assertSame(1, $this->auditLogCount('CLIENT_AGREEMENT_RENEWED', 'agreement_expiry_date', $clientId));
    }

    public function testRenewAgreement404sForMissingClient(): void
    {
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['agreement_expiry_date'] = '2030-01-01';

        ob_start();
        $this->controller->renewAgreement(['id' => 999999999]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testDownloadAgreement404sWhenNoFileOnRecord(): void
    {
        $clientId = $this->createTestClient();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->downloadAgreement(['id' => $clientId]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testDownloadAgreement404sWhenFileMissingFromDisk(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setAgreementFile($clientId, '/tmp/does-not-exist-' . bin2hex(random_bytes(8)) . '.pdf', 'fake.pdf', null);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->downloadAgreement(['id' => $clientId]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    /**
     * The gating logic: resolveAgreementFooter() only lets the text through
     * on a generated document while the agreement is genuinely active.
     */
    public function testResolveAgreementFooterShowsTextWhenActiveWithNoExpiry(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::updateAgreementFooterText($clientId, 'Active clause.');
        $orderId = $this->createTestOrder($clientId);

        $context = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertSame('Active clause.', $context['buyer']['agreement_footer_text'] ?? null);
    }

    public function testResolveAgreementFooterShowsTextWhenExpiryIsInTheFuture(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::updateAgreementFooterText($clientId, 'Future clause.');
        ClientRepository::setAgreementFile($clientId, '/tmp/fake.pdf', 'fake.pdf', date('Y-m-d', strtotime('+10 days')));
        $orderId = $this->createTestOrder($clientId);

        $context = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertSame('Future clause.', $context['buyer']['agreement_footer_text'] ?? null);
    }

    public function testResolveAgreementFooterHidesTextWhenExpiryIsInThePast(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::updateAgreementFooterText($clientId, 'Expired clause.');
        ClientRepository::setAgreementFile($clientId, '/tmp/fake.pdf', 'fake.pdf', date('Y-m-d', strtotime('-1 day')));
        $orderId = $this->createTestOrder($clientId);

        $context = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertArrayHasKey('agreement_footer_text', $context['buyer']);
        self::assertNull($context['buyer']['agreement_footer_text']);
    }

    public function testResolveAgreementFooterHidesTextWhenForceExpiredEvenWithFutureExpiry(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::updateAgreementFooterText($clientId, 'Force-expired clause.');
        ClientRepository::setAgreementFile($clientId, '/tmp/fake.pdf', 'fake.pdf', date('Y-m-d', strtotime('+30 days')));
        ClientRepository::setAgreementForceExpired($clientId, true);
        $orderId = $this->createTestOrder($clientId);

        $context = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertArrayHasKey('agreement_footer_text', $context['buyer']);
        self::assertNull($context['buyer']['agreement_footer_text']);
    }
}
