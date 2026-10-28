<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DocumentController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\DocumentRepository;
use App\Services\EmailDispatchService;
use App\Services\PaymentTermsApprovalService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-03 (owner decision 2026-10-28, Option A): on a payment
 * preset marked "Requires MD approval", MD/ED (holders of "Approve payment
 * terms") approve the order's terms before the Quotation can be generated
 * or sent. "Extended Terms — Before Shipment" no longer needs approval.
 */
final class PaymentTermsApprovalTest extends DbTestCase
{
    protected function tearDown(): void
    {
        \App\Helpers\Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
    }

    private function actAs(int $userId): void
    {
        $_SESSION['_auth_user_id'] = $userId;
        PermissionService::resetCache();
    }

    public function testOnlyThePresetsMarkedForApprovalNeedIt(): void
    {
        $order = fn (string $preset): int => $this->createTestOrder($this->createTestClient(), 'FOB', $preset);
        self::assertFalse(PaymentTermsApprovalService::isPending($order('Standard — New Buyer')));
        self::assertFalse(PaymentTermsApprovalService::isPending($order('Extended Terms — Before Shipment')));
        self::assertTrue(PaymentTermsApprovalService::isPending($order('Established Buyer — Post-BL')));
        self::assertTrue(PaymentTermsApprovalService::isPending($order('Irrevocable Letter of Credit at Sight')));
    }

    public function testTheQuotationCannotBeGeneratedUntilTheTermsAreApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL');
        $this->actAs($this->createTestUser('Export Executive'));

        $_POST = ['document_type' => 'QT'];
        (new DocumentController())->generate(['id' => (string) $orderId]);

        self::assertNull(DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'QT'));
        self::assertStringContainsString('need MD/ED approval', Flash::pull()[0]['message']);
    }

    public function testApprovingRecordsWhoAndWhenAndLiftsTheBlock(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL');
        $mdId = $this->createTestUser('Managing Director');
        $this->actAs($mdId);

        (new OrderController())->approvePaymentTerms(['id' => (string) $orderId]);

        $state = PaymentTermsApprovalService::state($orderId);
        self::assertTrue($state['approved']);
        self::assertSame('PHPUnit Test User', $state['approved_by_name']);
        self::assertFalse(PaymentTermsApprovalService::isPending($orderId));
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PAYMENT_TERMS_APPROVED' AND entity_id = :id AND user_id = :u");
        $stmt->execute(['id' => $orderId, 'u' => $mdId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testAnUnapprovedQuotationCannotBeSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL');
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('need MD/ED approval');
        EmailDispatchService::buildPreview($orderId, $documentId, 'send_qt', $this->createTestUser('Admin'));
    }

    public function testTheOrderPageOffersTheApprovalOnlyToApprovers(): void
    {
        $exportId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL', 'USD', null, null, $exportId);

        $this->actAs($exportId);
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Payment terms need MD/ED approval first', $html);
        self::assertStringContainsString('Waiting for MD/ED approval.', $html);
        self::assertStringNotContainsString('Approve payment terms</button>', $html);
        self::assertStringNotContainsString('<input type="hidden" name="document_type" value="QT">', $html);

        $this->actAs($this->createTestUser('Executive Director'));
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Approve payment terms</button>', $html);
    }
}
