<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderBlEndorsementRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierFactoryAddressRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster D: 15 of the 18 order financial/
 * shipping/BL actions never called AuditLogRepository::log(), so none of
 * them ever notified anyone either. Three (uploadBuyerPoDocument,
 * uploadSupplierPoDocument, attachBlCopy) wrap FileUploadService::
 * handleUpload(), whose move_uploaded_file() call cannot succeed outside
 * a real HTTP upload (see BlCopyUploadTest's own
 * testHandleUploadResolvesTheBlCopyContextInsteadOfFailingUnknownContext)
 * — those three were verified by code review instead; see the Node
 * mirror for their coverage, since multer's req.file has no such
 * restriction.
 */
final class N08AuditLogCoverageClusterDTest extends DbTestCase
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

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    /** @return array<string,mixed> */
    private function fullSupplierPoData(array $overrides = []): array
    {
        return array_merge([
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => null,
            'unit' => null, 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => null, 'basic_value_inr' => null, 'gst_rate_pct' => null,
            'gst_amount_inr' => null, 'total_payable_inr' => null, 'advance_pct' => null,
            'advance_amount_inr' => null, 'balance_amount_inr' => null,
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], $overrides);
    }

    private function createTestSupplierPo(int $orderId): int
    {
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'N08 Test Supplier']);
        return OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-N08-1', $this->fullSupplierPoData());
    }

    public function testMarkPaymentReportReviewedIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $reportId = ClientPaymentReportRepository::create($orderId, 'advance', 'REF123', null, 1000.0, '2026-10-20', null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->markPaymentReportReviewed(['id' => (string) $orderId, 'reportId' => (string) $reportId]);
        ob_end_clean();

        self::assertSame('CLIENT_PAYMENT_REPORT_REVIEWED', $this->lastAuditLogAction()['action_type']);
    }

    public function testCreateSupplierIsLogged(): void
    {
        // Suppliers are now added in the Suppliers directory, not from the order page.
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['supplier_legal_name' => 'N08 New Supplier', 'types' => ['Trader']];

        ob_start();
        (new \App\Controllers\SupplierController())->create([]);
        ob_end_clean();

        self::assertSame('SUPPLIER_CREATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testCreateSupplierFactoryAddressIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'N08 Test Supplier']);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['address' => '123 Factory Road'];

        ob_start();
        (new OrderController())->createSupplierFactoryAddress(['id' => (string) $orderId, 'supplierId' => (string) $supplierId]);
        ob_end_clean();

        self::assertSame('SUPPLIER_FACTORY_ADDRESS_CREATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testDeleteSupplierFactoryAddressIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'N08 Test Supplier']);
        $addressId = SupplierFactoryAddressRepository::create($supplierId, '123 Factory Road', null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->deleteSupplierFactoryAddress(['id' => (string) $orderId, 'addressId' => (string) $addressId]);
        ob_end_clean();

        self::assertSame('SUPPLIER_FACTORY_ADDRESS_DELETED', $this->lastAuditLogAction()['action_type']);
    }

    public function testMarkSupplierAdvancePaidIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Order-page polish round: markSupplierAdvancePaid() now requires
        // Stage 5 unlocked — see OrderPageStageGateFixesTest for the
        // dedicated coverage of that gate itself.
        $this->unlockThrough($orderId, 4);
        $this->createTestSupplierPo($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['amount_paid_inr' => '20000'];

        ob_start();
        (new OrderController())->markSupplierAdvancePaid(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('SUPPLIER_ADVANCE_PAID', $this->lastAuditLogAction()['action_type']);
    }

    public function testMarkSupplierBalancePaidIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Order-page polish round: markSupplierBalancePaid() now requires
        // Stage 5 unlocked — see OrderPageStageGateFixesTest.
        $this->unlockThrough($orderId, 4);
        $this->createTestSupplierPo($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['amount_paid_inr' => '30000'];

        ob_start();
        (new OrderController())->markSupplierBalancePaid(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('SUPPLIER_BALANCE_PAID', $this->lastAuditLogAction()['action_type']);
    }

    public function testSaveFreightTermsIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Order-page polish round: saveFreightTerms() now requires Stage 6
        // unlocked — see OrderPageStageGateFixesTest.
        $this->unlockThrough($orderId, 5);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['confirmed_freight_rate' => '500'];

        ob_start();
        (new OrderController())->saveFreightTerms(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_FREIGHT_TERMS_SAVED', $this->lastAuditLogAction()['action_type']);
    }

    public function testRecordFreightPaymentIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 5);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['freight_amount' => '750', 'freight_exchange_rate' => '83.5'];

        ob_start();
        (new OrderController())->recordFreightPayment(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('FREIGHT_PAYMENT_RECORDED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSavePackingIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 6);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['crate_count' => '5'];

        ob_start();
        (new OrderController())->savePacking(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_PACKING_SAVED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSaveShippingIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 6);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['shipping_line' => 'Maersk'];

        ob_start();
        (new OrderController())->saveShipping(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_SHIPPING_DETAILS_SAVED', $this->lastAuditLogAction()['action_type']);
    }

    public function testRecordScannedBlSentIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->recordScannedBlSent(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_SCANNED_BL_SENT', $this->lastAuditLogAction()['action_type']);
    }

    public function testRecordBlOriginalsReceivedIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Order-page polish round: this action now requires Stage 9
        // unlocked — see OrderPageStageGateFixesTest.
        $this->unlockThrough($orderId, 8);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['bl_originals_count' => '3'];

        ob_start();
        (new OrderController())->recordBlOriginalsReceived(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_BL_ORIGINALS_RECEIVED', $this->lastAuditLogAction()['action_type']);
    }

    public function testRecordBlEndorsedIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 8);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->recordBlEndorsed(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_BL_ENDORSED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSaveBlEndorsementIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 8);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['bl_number' => 'MSCU1234567'];

        ob_start();
        (new OrderController())->saveBlEndorsement(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_BL_ENDORSEMENT_SAVED', $this->lastAuditLogAction()['action_type']);
    }

    public function testGenerateBlEndorsementIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 8);
        $userId = $this->createTestUser('Admin');
        OrderBlEndorsementRepository::upsert($orderId, ['bl_number' => 'MSCU1234567'], $userId);
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->generateBlEndorsement(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ORDER_BL_ENDORSEMENT_GENERATED', $this->lastAuditLogAction()['action_type']);
    }
}
