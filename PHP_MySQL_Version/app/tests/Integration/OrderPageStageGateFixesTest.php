<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Order detail page polish round: investigation while fixing the step-
 * card UI found three real server-side gaps, independent of any view
 * change — a direct POST (holding only the ordinary manage_shipping/
 * manage_supplier_payments permission) on an order still at Stage 1
 * could save freight terms, mark the supplier paid, or record/endorse
 * BL originals, fully skipping Stages 2-8. Every other stage-advancing
 * action in OrderController already called StageGateService::isUnlocked()
 * before writing (see CommercialDocumentGateTest/N08AuditLogCoverageClusterDTest
 * for that existing coverage) — these seven actions did not. This test
 * covers the "refuses while locked" half of the fix for all seven;
 * the "still works once unlocked" half is already exercised by the
 * (now-updated) existing tests in SupplierPaymentDatesTest,
 * N08AuditLogCoverageClusterDTest, and BlEndorsementTest.
 */
final class OrderPageStageGateFixesTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull(); // drain any leftover flash from an earlier test in this process
    }

    private function createTestSupplierPo(int $orderId): void
    {
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Stage Gate Test Supplier Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'STAGEGATE-SPO-TEST', [
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => null,
            'unit' => null, 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => null, 'basic_value_inr' => null, 'gst_rate_pct' => null,
            'gst_amount_inr' => null, 'total_payable_inr' => null, 'advance_pct' => null,
            'advance_amount_inr' => '20000', 'balance_amount_inr' => '30000',
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'issued');
    }

    // ---------------------------------------------------------------
    // saveFreightTerms() — Stage 6
    // ---------------------------------------------------------------

    public function testSaveFreightTermsRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['confirmed_freight_rate' => '500'];

        (new OrderController())->saveFreightTerms(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 6', $flash[0]['message']);
        self::assertNull(OrderFreightRepository::find($orderId));
    }

    // ---------------------------------------------------------------
    // markSupplierAdvancePaid() / markSupplierBalancePaid() — Stage 5
    // ---------------------------------------------------------------

    public function testMarkSupplierAdvancePaidRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestSupplierPo($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['paid_date' => '2026-10-20', 'amount_paid_inr' => '20000'];

        (new OrderController())->markSupplierAdvancePaid(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 5', $flash[0]['message']);
        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['advance_paid_at']);
    }

    public function testMarkSupplierBalancePaidRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestSupplierPo($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['paid_date' => '2026-10-20', 'amount_paid_inr' => '30000'];

        (new OrderController())->markSupplierBalancePaid(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 5', $flash[0]['message']);
        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['balance_paid_at']);
    }

    // ---------------------------------------------------------------
    // recordBlOriginalsReceived() / recordBlEndorsed() / saveBlEndorsement() / generateBlEndorsement() — Stage 9
    // ---------------------------------------------------------------

    public function testRecordBlOriginalsReceivedRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['bl_originals_count' => '3'];

        (new OrderController())->recordBlOriginalsReceived(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 9', $flash[0]['message']);
        $shipping = OrderShippingRepository::find($orderId);
        self::assertTrue(!$shipping || $shipping['bl_originals_received_at'] === null);
    }

    public function testRecordBlEndorsedRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        (new OrderController())->recordBlEndorsed(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 9', $flash[0]['message']);
        $shipping = OrderShippingRepository::find($orderId);
        self::assertTrue(!$shipping || $shipping['bl_endorsed_at'] === null);
    }

    public function testSaveBlEndorsementRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['bl_number' => 'MSCU1234567'];

        (new OrderController())->saveBlEndorsement(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 9', $flash[0]['message']);
        self::assertNull(\App\Repositories\OrderBlEndorsementRepository::find($orderId));
    }

    public function testGenerateBlEndorsementRefusesOnAFreshOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        \App\Repositories\OrderBlEndorsementRepository::upsert($orderId, ['bl_number' => 'MSCU1234567'], $userId);
        $_SESSION['_auth_user_id'] = $userId;

        (new OrderController())->generateBlEndorsement(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 9', $flash[0]['message']);
        self::assertNull(\App\Repositories\DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'BLE'));
    }

    // ---------------------------------------------------------------
    // Sanity check — the gate itself resolves true once genuinely unlocked
    // ---------------------------------------------------------------

    public function testAllThreeGatesResolveUnlockedOnceTheirStageIsReached(): void
    {
        // isUnlocked() means "this stage is the order's current, in-progress
        // stage" — not "has been reached and passed" — so each stage is
        // checked on a fresh order advanced to exactly that point, not one
        // order walked straight through to the end (by which point the
        // earlier stages have already passed, not just unlocked).
        $stage5Order = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($stage5Order, $stage, null);
        }
        self::assertTrue(StageGateService::isUnlocked($stage5Order, 5));

        $stage6Order = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($stage6Order, $stage, null);
        }
        self::assertTrue(StageGateService::isUnlocked($stage6Order, 6));

        $stage9Order = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 8; $stage++) {
            StageGateService::passAndUnlockNext($stage9Order, $stage, null);
        }
        self::assertTrue(StageGateService::isUnlocked($stage9Order, 9));
    }
}
