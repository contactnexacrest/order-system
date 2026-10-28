<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\BenefitSettlementService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-27, Option A): government benefits are recorded
 * at Step 13g, just before 13h Mark Fully Settled — every eligible scheme
 * must be received, or skipped with a reason, before the order settles.
 */
final class BenefitsAtSettlementTest extends DbTestCase
{
    private int $userId;
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        Flash::pull();

        $this->orderId = $this->createTestOrder($this->createTestClient());
        foreach (OrderComplianceTaskRepository::forOrder($this->orderId) as $ct) {
            OrderComplianceTaskRepository::setStatus($this->orderId, (int) $ct['task_type_id'], 'skipped', 'not applicable to this test order', $this->userId);
        }
        foreach (OrderExportBenefitSchemeRepository::forOrder($this->orderId) as $s) {
            $eligible = $s['scheme_name'] === 'RODTEP';
            OrderExportBenefitSchemeRepository::setStatus($this->orderId, $s['scheme_name'], $eligible ? 'eligible' : 'not_eligible', $eligible ? null : 'not applicable to this test order', $this->userId);
        }
        OrderShippingRepository::upsert($this->orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($this->orderId, 'MSCU1234567', '2026-06-15');
        for ($stage = 1; $stage <= 8; $stage++) {
            StageGateService::passAndUnlockNext($this->orderId, $stage, null);
        }
        $this->createTestDocument($this->orderId, 'BLI', 'approved');
        $this->createTestDocument($this->orderId, 'COOPREP', 'sent');
        $_POST = ['courier_tracking_number' => 'DHL-13G-1'];
        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $this->orderId]);
        ob_end_clean();
        Flash::pull();
    }

    private function settle(): array
    {
        $_POST = [];
        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $this->orderId]);
        ob_end_clean();
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    private function skip(array $post): array
    {
        $_POST = $post;
        (new OrderFinancialsController())->skipExportBenefitClaim(['id' => (string) $this->orderId]);
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    public function testAnUnclaimedEligibleSchemeBlocksSettlingUntilSkipped(): void
    {
        self::assertSame(['RODTEP'], BenefitSettlementService::state($this->orderId)['unclaimed']);
        $refused = $this->settle();
        self::assertSame('error', $refused['type']);
        self::assertStringContainsString('has not been claimed yet (RODTEP)', $refused['message']);

        self::assertSame('error', $this->skip(['scheme_name' => 'RODTEP', 'reason' => ''])['type'], 'a reason is required');
        self::assertSame('success', $this->skip(['scheme_name' => 'RODTEP', 'reason' => 'Below the minimum claim amount'])['type']);
        self::assertTrue(BenefitSettlementService::state($this->orderId)['done']);

        self::assertSame('success', $this->skip(['scheme_name' => 'RODTEP', 'action' => 'undo'])['type']);
        self::assertFalse(BenefitSettlementService::state($this->orderId)['done']);

        $this->skip(['scheme_name' => '', 'reason' => 'Company decided not to file any claims']);
        self::assertSame('success', $this->settle()['type']);
        self::assertSame('complete', OrderRepository::find($this->orderId)['status']);
    }

    /** Owner decision (2026-10-28): every settled order gets its final CAFIN automatically. */
    public function testSettlingGeneratesTheFinalCafinAutomatically(): void
    {
        $this->skip(['scheme_name' => '', 'reason' => 'Company decided not to file any claims']);
        $_POST = [];
        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $this->orderId]);
        ob_end_clean();
        $messages = array_column(Flash::pull(), 'message');

        self::assertSame('complete', OrderRepository::find($this->orderId)['status']);
        $cafin = \App\Repositories\DocumentRepository::findLatestForOrderAndTypeCode($this->orderId, 'CAFIN');
        self::assertNotNull($cafin);
        self::assertSame([
            'Order fully settled and closed.',
            "Final internal financial annexure generated automatically: {$cafin['document_reference']}.",
        ], $messages);
        $stmt = \App\Config\Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_AUTO_GENERATED' AND entity_id = :id");
        $stmt->execute(['id' => $this->orderId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testAReceivedClaimSettlesAndAWaitingOneBlocks(): void
    {
        $claimId = CaExportBenefitRepository::record($this->orderId, 'RODTEP', 'SB-1', 9000, '2026-06-20', 'INR', null, $this->userId);
        self::assertSame(['RODTEP'], BenefitSettlementService::state($this->orderId)['waiting']);
        self::assertStringContainsString('still awaiting receipt (RODTEP)', $this->settle()['message']);

        CaExportBenefitRepository::markReceived($claimId, 9000, '2026-07-01');
        self::assertTrue(BenefitSettlementService::state($this->orderId)['done']);
        self::assertSame('success', $this->settle()['type']);
    }

    public function testTheOrderPageShows13gBefore13h(): void
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Step 13g &middot; Government Benefits', $html);
        self::assertStringContainsString('Step 13h &middot; Mark Fully Settled', $html);
        self::assertLessThan(strpos($html, 'id="step-despatch"'), strpos($html, 'id="step-13g-benefits"'));
        self::assertStringContainsString('Skip — not claiming', $html);
        self::assertStringNotContainsString('Step 12d', $html);
    }
}
