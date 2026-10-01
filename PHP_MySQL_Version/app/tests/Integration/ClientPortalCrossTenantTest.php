<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderCommentRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P0.2 (docs/QA/TEST_PLAN.md Section 6): every /client/orders/{id}/...
 * and /client/documents/{id}/download route must refuse a client hitting
 * another client's order/document, never a silent cross-tenant read or an
 * existence-leaking 404. ClientPortalController already puts the same
 * ownership check ("!$order || $order['client_id'] !== $clientId" -> 404)
 * as the very first thing every one of these 10 methods does — this suite
 * pins that behavior directly against the real controller code (not a
 * duplicated re-implementation), calling each method with Client A's
 * session against Client B's resources and asserting the block, with a
 * genuinely existing, real resource behind each URL so a regression that
 * removed the check would make the test fail, not coincidentally still
 * pass via some other check.
 */
final class ClientPortalCrossTenantTest extends DbTestCase
{
    private ClientPortalController $controller;
    private int $clientA;
    private int $clientB;
    private int $orderB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ClientPortalController();
        $this->clientA = $this->createTestClient();
        $this->clientB = $this->createTestClient();
        $this->orderB = $this->createTestOrder($this->clientB);
        $_SESSION['_client_portal_client_id'] = $this->clientA;
        $_POST = [];
        $_FILES = [];
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_client_portal_client_id']);
        parent::tearDown();
    }

    private function call(string $method, array $params): string
    {
        ob_start();
        $this->controller->{$method}($params);
        return (string) ob_get_clean();
    }

    public function testShowOrderBlocksAnotherClientsOrder(): void
    {
        $output = $this->call('showOrder', ['id' => $this->orderB]);
        self::assertSame(404, http_response_code());
        self::assertStringContainsString('not found', strtolower($output));
    }

    public function testShowReorderFormBlocksAnotherClientsOrder(): void
    {
        $output = $this->call('showReorderForm', ['id' => $this->orderB]);
        self::assertSame(404, http_response_code());
        self::assertStringContainsString('not found', strtolower($output));
    }

    public function testSubmitReorderBlocksAnotherClientsOrderAndCreatesNoRequest(): void
    {
        $pdo = Database::connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM order_reorder_requests')->fetchColumn();

        $_POST = ['product_description' => ['Attempted cross-tenant reorder']];
        $this->call('submitReorder', ['id' => $this->orderB]);

        self::assertSame(404, http_response_code());
        $after = (int) $pdo->query('SELECT COUNT(*) FROM order_reorder_requests')->fetchColumn();
        self::assertSame($before, $after, 'a blocked cross-tenant reorder attempt must create nothing');
    }

    public function testReportPaymentBlocksAnotherClientsOrderAndCreatesNoReport(): void
    {
        $pdo = Database::connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM client_payment_reports')->fetchColumn();

        $_POST = ['payment_type' => 'advance', 'transaction_ref' => 'HACK-UTR-001'];
        $this->call('reportPayment', ['id' => $this->orderB]);

        self::assertSame(404, http_response_code());
        $after = (int) $pdo->query('SELECT COUNT(*) FROM client_payment_reports')->fetchColumn();
        self::assertSame($before, $after, 'a blocked cross-tenant payment report must create nothing');
    }

    public function testAcknowledgeOcBlocksAnotherClientsOrderAndDoesNotAdvanceStage(): void
    {
        $before = $this->stageStatuses($this->orderB);
        $this->call('acknowledgeOc', ['id' => $this->orderB]);

        self::assertSame(404, http_response_code());
        $after = $this->stageStatuses($this->orderB);
        self::assertSame($before, $after, 'a blocked cross-tenant acknowledgement must not touch the real order\'s stage gates');
    }

    public function testUploadBuyerPoBlocksAnotherClientsOrderAndCreatesNoDocument(): void
    {
        $pdo = Database::connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM order_buyer_po_documents')->fetchColumn();

        $this->call('uploadBuyerPo', ['id' => $this->orderB]);

        self::assertSame(404, http_response_code());
        $after = (int) $pdo->query('SELECT COUNT(*) FROM order_buyer_po_documents')->fetchColumn();
        self::assertSame($before, $after, 'a blocked cross-tenant Buyer PO upload must create nothing');
    }

    public function testRaiseDisputeBlocksAnotherClientsOrderAndCreatesNoDispute(): void
    {
        $pdo = Database::connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM disputes')->fetchColumn();

        $_POST = ['description' => 'Attempted cross-tenant dispute'];
        $this->call('raiseDispute', ['id' => $this->orderB]);

        self::assertSame(404, http_response_code());
        $after = (int) $pdo->query('SELECT COUNT(*) FROM disputes')->fetchColumn();
        self::assertSame($before, $after, 'a blocked cross-tenant dispute attempt must create nothing');
    }

    public function testPostCommentBlocksAnotherClientsOrderAndCreatesNoComment(): void
    {
        $pdo = Database::connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM order_comments')->fetchColumn();

        $_POST = ['body' => 'Attempted cross-tenant message'];
        $this->call('postComment', ['id' => $this->orderB]);

        self::assertSame(404, http_response_code());
        $after = (int) $pdo->query('SELECT COUNT(*) FROM order_comments')->fetchColumn();
        self::assertSame($before, $after, 'a blocked cross-tenant comment attempt must create nothing');
    }

    public function testDownloadCommentAttachmentBlocksAnotherClientsRealAttachment(): void
    {
        // A genuinely real attachment on Client B's order — proves the
        // block is the ownership check, not just "file doesn't exist".
        $fileId = $this->createTestFile($this->orderB, $this->clientB);
        $commentId = OrderCommentRepository::create($this->orderB, 'client', null, $this->clientB, 'Client B\'s own message');
        OrderCommentRepository::attachFile($commentId, $fileId);

        $output = $this->call('downloadCommentAttachment', ['id' => $this->orderB, 'fileId' => $fileId]);

        self::assertSame(404, http_response_code());
        self::assertStringNotContainsString('test-file.pdf', $output, 'Client A must never receive Client B\'s file bytes/headers');
    }

    public function testDownloadPaymentScreenshotBlocksAnotherClientsRealReport(): void
    {
        $fileId = $this->createTestFile($this->orderB, $this->clientB);
        $reportId = ClientPaymentReportRepository::create($this->orderB, 'advance', 'REAL-UTR-001', null, 500.0, '2026-01-01', $fileId);

        $this->call('downloadPaymentScreenshot', ['id' => $this->orderB, 'reportId' => $reportId]);

        self::assertSame(404, http_response_code());
    }

    public function testDownloadDocumentBlocksAnotherClientsRealSentDocument(): void
    {
        $pdo = Database::connection();
        $docTypeId = (int) $pdo->query("SELECT id FROM document_types WHERE code = 'QT'")->fetchColumn();
        $fileId = $this->createTestFile($this->orderB, $this->clientB);

        $stmt = $pdo->prepare(
            "INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
             VALUES (:order_id, :dt, 'SC/QT/TEST/001', 'sent', :pdf)"
        );
        $stmt->execute(['order_id' => $this->orderB, 'dt' => $docTypeId, 'pdf' => $fileId]);
        $documentId = (int) $pdo->lastInsertId();

        $output = $this->call('downloadDocument', ['id' => $documentId]);

        self::assertSame(404, http_response_code(), 'a real, sent, customer-facing document belonging to another client must still be refused');
        self::assertStringNotContainsString('test-file.pdf', $output);
    }
}
