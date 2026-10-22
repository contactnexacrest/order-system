<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #52/#53: neither confirmSupplierSigned() (Stage 5->6,
 * "the supplier signed and returned their PO") nor recordBalancePayment()
 * (Stage 8, "the buyer remitted the balance against the Commercial
 * Invoice") ever checked that the commercial document they're implicitly
 * about — the SUPPO / CI PDF actually generated and handed to the
 * counterparty — existed at all. A supplier could be marked "signed" and
 * a balance "remitted" purely from internal data, with no SUPPO/CI
 * document on record. Fixed with the same "generated" bar
 * recordOcAcknowledgment() already uses for the OC document
 * (DocumentRepository::findLatestForOrderAndTypeCode()), not the stricter
 * "sent" state nothing else in the codebase checks for yet.
 *
 * Investigation also found recordBalancePayment() had no Stage 8 gate at
 * all (unlike its neighbour clearBalancePayment(), which does check) —
 * fixed alongside the CI-document check since both are the same method.
 */
final class CommercialDocumentGateTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        http_response_code(200);
        // Wet-signature guard is a separate concern (item #106); turn it
        // off so these tests isolate the SUPPO-document-generated check.
        CompanySettingsRepository::set('wet_signature_required_supplier_po', '0');
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('wet_signature_required_supplier_po', '1');
        parent::tearDown();
    }

    private function unlockThroughStage5(int $orderId): void
    {
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    /** Mirrors WetSignatureGuardTest's helper — a minimal, direct order_supplier_po row. */
    private function createTestSupplierPo(int $orderId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO suppliers (supplier_legal_name) VALUES (:name)');
        $stmt->execute(['name' => 'Commercial Gate Test Supplier Co']);
        $supplierId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference)
             VALUES (:order_id, :supplier_id, 'COMMGATE-SPO-TEST')"
        );
        $stmt->execute(['order_id' => $orderId, 'supplier_id' => $supplierId]);
        return (int) $pdo->lastInsertId();
    }

    // ---------------------------------------------------------------
    // #52 — confirmSupplierSigned()
    // ---------------------------------------------------------------

    public function testConfirmSupplierSignedRefusesWithoutAGeneratedSuppoDocument(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage5($orderId);
        $this->createTestSupplierPo($orderId);

        (new OrderController())->confirmSupplierSigned(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Generate the Supplier PO document', $flash[0]['message']);
        self::assertNotSame('signed', OrderSupplierPoRepository::findLatestForOrder($orderId)['status']);
        self::assertFalse(StageGateService::isUnlocked($orderId, 6), 'Stage 6 must not unlock without a real SUPPO document');
    }

    public function testConfirmSupplierSignedSucceedsOnceTheSuppoDocumentExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage5($orderId);
        $this->createTestSupplierPo($orderId);
        $this->createTestDocument($orderId, 'SUPPO', 'approved');
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        (new OrderController())->confirmSupplierSigned(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertSame('signed', OrderSupplierPoRepository::findLatestForOrder($orderId)['status']);
    }

    // ---------------------------------------------------------------
    // #53 — recordBalancePayment()
    // ---------------------------------------------------------------

    private function unlockThroughStage8(int $orderId): void
    {
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        StageGateService::maybeAutoSkipFreightStage($orderId, $this->createTestUser('Admin'));
        StageGateService::passAndUnlockNext($orderId, 7, null);
    }

    public function testRecordBalancePaymentRefusesWhileStage8IsLocked(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $_POST = ['balance_amount' => '1000'];

        (new OrderController())->recordBalancePayment(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertTrue(!$payment || $payment['balance_remittance_received_at'] === null, 'must not record anything while Stage 8 is locked');
    }

    public function testRecordBalancePaymentRefusesWithoutAGeneratedCiDocument(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage8($orderId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $_POST = ['balance_amount' => '1000'];

        (new OrderController())->recordBalancePayment(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Generate the Commercial Invoice', $flash[0]['message']);
        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertTrue(!$payment || $payment['balance_remittance_received_at'] === null, 'must not record a remittance against an invoice never sent');
    }

    public function testRecordBalancePaymentSucceedsOnceTheCiDocumentExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage8($orderId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $this->createTestDocument($orderId, 'CI', 'approved');
        $_POST = ['balance_amount' => '1000', 'balance_received_at' => '2026-05-01'];

        (new OrderController())->recordBalancePayment(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertNotNull($payment['balance_remittance_received_at']);
        self::assertSame(1000.0, (float) $payment['balance_amount']);
    }
}
