<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BU — export process steps 11a-11e: closing an
 * order is now two phases. 'shipped_docs_sent' (documents couriered —
 * client sees it as done, stays unlocked internally) -> 'complete'
 * (government export benefits received or never applicable — the real
 * final lock).
 */
final class OrderClosureTwoPhaseTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
    }

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    /** Every order now gets real 'not_started' compliance-task rows at creation — resolve them so they don't block closure in tests unrelated to that gate. */
    private function resolveAllComplianceTasks(int $orderId, int $userId): void
    {
        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $ct) {
            OrderComplianceTaskRepository::setStatus($orderId, (int) $ct['task_type_id'], 'skipped', 'not applicable to this test order', $userId);
        }
    }

    /** Every order now gets real 'pending' export-benefit-scheme rows at creation — resolve them so they don't block closure in tests unrelated to that gate. */
    private function resolveAllExportBenefitSchemes(int $orderId, int $userId): void
    {
        foreach (OrderExportBenefitSchemeRepository::forOrder($orderId) as $s) {
            OrderExportBenefitSchemeRepository::setStatus($orderId, $s['scheme_name'], 'not_eligible', 'not applicable to this test order', $userId);
        }
    }

    private function closeToShippedDocsSent(int $orderId, int $userId): void
    {
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        $this->unlockThrough($orderId, 8);
        $this->createTestDocument($orderId, 'BLI', 'approved');
        $this->createTestDocument($orderId, 'COOPREP', 'sent');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['courier_tracking_number' => 'DHL-TWO-PHASE-1'];

        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();
        Flash::pull();
    }

    // ---------------------------------------------------------------
    // closeOrder() — phase 1
    // ---------------------------------------------------------------

    public function testCloseOrderReachesShippedDocsSentWithoutLockingTheOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);

        $order = OrderRepository::find($orderId);
        self::assertSame('shipped_docs_sent', $order['status']);
        self::assertSame(0, (int) $order['is_locked'], 'documents sent must NOT lock the order — benefits still need recording');
    }

    // ---------------------------------------------------------------
    // Export benefits now claimable once shipped_docs_sent, not only 'complete'
    // ---------------------------------------------------------------

    public function testExportBenefitCanBeClaimedOnceShippedDocsSentWithoutAnyLockOverride(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 9000.0, '2026-06-01');
        $this->closeToShippedDocsSent($orderId, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['scheme_name' => 'RODTEP', 'claimed_amount' => '9000', 'claimed_at' => '2026-06-01'];
        ob_start();
        (new OrderFinancialsController())->addExportBenefit(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertCount(1, CaExportBenefitRepository::forOrder($orderId), 'a benefit claim must be recordable in the shipped_docs_sent phase, with no edit_locked_data override needed since the order is not locked');
        self::assertSame('success', Flash::pull()[0]['type']);
    }

    // ---------------------------------------------------------------
    // markFullySettled() — phase 2
    // ---------------------------------------------------------------

    public function testMarkFullySettledRefusesAnOrderNotYetShippedDocsSent(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('active', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledReturns404ForAnUnknownOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->markFullySettled(['id' => '999999999']);
        $output = ob_get_clean();

        self::assertStringContainsString('not found', strtolower($output));
    }

    public function testMarkFullySettledSucceedsWithNoBenefitClaimsAtAll(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        $this->resolveAllComplianceTasks($orderId, $userId);
        $this->resolveAllExportBenefitSchemes($orderId, $userId);

        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('success', Flash::pull()[0]['type']);
        $order = OrderRepository::find($orderId);
        self::assertSame('complete', $order['status']);
        self::assertSame(1, (int) $order['is_locked']);
    }

    public function testMarkFullySettledBlocksWhileAComplianceTaskIsStillOutstanding(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        // Deliberately does NOT call resolveAllComplianceTasks() — every
        // order now gets real 'not_started' rows at creation, and this is
        // the test that confirms closure is actually blocked on them, not
        // just visually flagged.

        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledBlocksWhileAnExportBenefitSchemeIsStillPending(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        $this->resolveAllComplianceTasks($orderId, $userId);
        // Deliberately does NOT call resolveAllExportBenefitSchemes() —
        // every order now gets real 'pending' rows at creation, and this
        // is the test that confirms closure is actually blocked on them.

        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledBlocksWhileABenefitClaimIsStillAwaitingReceipt(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-001', 5000.0, '2026-06-01', 'INR', null, $userId);

        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledSucceedsOnceTheBenefitIsReceived(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        $benefitId = CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-002', 5000.0, '2026-06-01', 'INR', null, $userId);
        CaExportBenefitRepository::markReceived($benefitId, 5100.0, '2026-07-01');
        $this->resolveAllComplianceTasks($orderId, $userId);
        $this->resolveAllExportBenefitSchemes($orderId, $userId);

        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertSame('complete', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledOverrideRequiresEditLockedDataPermission(): void
    {
        $userId = $this->createTestUser('Export Executive'); // holds close_orders, not edit_locked_data
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-003', 5000.0, '2026-06-01', 'INR', null, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['override_gate' => '1', 'override_reason' => 'Trying to force it through.'];
        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledOverrideRequiresAReason(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-004', 5000.0, '2026-06-01', 'INR', null, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['override_gate' => '1', 'override_reason' => 'short'];
        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    public function testMarkFullySettledOverrideSucceedsAndIsAuditLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);
        CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-005', 5000.0, '2026-06-01', 'INR', null, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['override_gate' => '1', 'override_reason' => 'Buyer confirms RoDTEP will never be claimed for this shipment.'];
        ob_start();
        (new OrderController())->markFullySettled(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertSame('complete', OrderRepository::find($orderId)['status']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ORDER_FULLY_SETTLED' AND entity_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    // ---------------------------------------------------------------
    // Cancellation request now blocked at shipped_docs_sent too
    // ---------------------------------------------------------------

    public function testRequestCancellationIsRefusedOnceShippedDocsSent(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Buyer wants to cancel after shipment, trying anyway.'];
        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
    }

    // ---------------------------------------------------------------
    // View — "Mark Fully Settled" button + client-facing status label
    // ---------------------------------------------------------------

    public function testOrderPageOffersMarkFullySettledOnceShippedDocsSent(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->closeToShippedDocsSent($orderId, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('/mark-fully-settled', $output);
        self::assertStringContainsString('Documents sent to buyer', $output);
    }

    public function testClientDashboardShowsCompleteForAShippedDocsSentOrder(): void
    {
        $clientId = $this->createTestClient('client-dashboard-test@example.test');
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');
        $this->closeToShippedDocsSent($orderId, $userId);

        $_SESSION['_client_portal_client_id'] = $clientId;
        ob_start();
        (new \App\Controllers\ClientPortalController())->dashboard([]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('Shipped docs sent', $output, 'the client must never see the internal status wording');
        self::assertStringContainsString('Complete', $output);
    }
}
