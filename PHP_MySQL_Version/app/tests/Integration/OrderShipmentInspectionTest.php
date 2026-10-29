<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderInspectionFeeRepository;
use App\Repositories\OrderRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

require_once __DIR__ . '/../../cron/check_alerts.php';

/**
 * docs/schema.sql Section BT — export process step 8 (estimated
 * shipment date update notifies the client, visible in their portal)
 * and step 8a (7-day pre-shipment inspection reminder + inspection fee
 * payer/amount recorded as an order expense when NexaCrest pays).
 */
final class OrderShipmentInspectionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull(); // drain any flash left over from a previous test in this process
    }

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    // ---------------------------------------------------------------
    // OrderController::updateProductionStatus() — step 8
    // ---------------------------------------------------------------

    public function testUpdatingTheShipmentTextAndDateNotifiesTheClientViaOrderComment(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient('client@example.test'));
        $this->unlockThrough($orderId, 3);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['est_shipment_date_text' => 'Week of 15 November 2026', 'est_shipment_date' => '2026-11-15'];

        ob_start();
        (new OrderController())->updateProductionStatus(['id' => (string) $orderId]);
        ob_end_clean();

        $order = OrderRepository::find($orderId);
        self::assertSame('Week of 15 November 2026', $order['est_shipment_date_text']);
        self::assertSame('2026-11-15', $order['est_shipment_date']);

        $comments = OrderCommentRepository::forOrder($orderId);
        self::assertNotEmpty($comments, 'the client must see this as an order update in their portal thread');
        self::assertStringContainsString('15 Nov 2026', $comments[0]['body']);
        self::assertSame('staff', $comments[0]['author_type']);
    }

    public function testUpdatingOnlyTheTextDoesNotClobberAPreviouslySetStructuredDate(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 3);
        OrderRepository::setEstShipmentDate($orderId, 'Week of 1 November 2026', '2026-11-01');
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['est_shipment_date_text' => 'Week of 1 November 2026 — confirmed', 'est_shipment_date' => ''];
        ob_start();
        (new OrderController())->updateProductionStatus(['id' => (string) $orderId]);
        ob_end_clean();

        $order = OrderRepository::find($orderId);
        self::assertSame('Week of 1 November 2026 — confirmed', $order['est_shipment_date_text']);
        self::assertSame('2026-11-01', $order['est_shipment_date'], 'leaving the date field blank must keep the previously-set structured date, not null it out');
    }

    public function testNoUpdateIsMadeWhenNeitherFieldActuallyChanges(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 3);
        OrderRepository::setEstShipmentDate($orderId, 'Week of 1 November 2026', '2026-11-01');
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['est_shipment_date_text' => 'Week of 1 November 2026', 'est_shipment_date' => '2026-11-01'];
        ob_start();
        (new OrderController())->updateProductionStatus(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame([], OrderCommentRepository::forOrder($orderId), 'nothing actually changed — the client must not be notified for a no-op submit');
    }

    // ---------------------------------------------------------------
    // OrderInspectionFeeRepository
    // ---------------------------------------------------------------

    public function testUpsertCreatesThenUpdatesTheSameRow(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        OrderInspectionFeeRepository::upsert($orderId, ['fee_payer' => 'client', 'fee_amount' => null, 'cost_entry_id' => null, 'recorded_by' => $userId]);
        $first = OrderInspectionFeeRepository::find($orderId);
        self::assertSame('client', $first['fee_payer']);

        OrderInspectionFeeRepository::upsert($orderId, ['fee_payer' => 'nexacrest', 'fee_amount' => 1500.0, 'cost_entry_id' => null, 'recorded_by' => $userId]);
        $second = OrderInspectionFeeRepository::find($orderId);
        self::assertSame('nexacrest', $second['fee_payer']);
        self::assertSame($first['id'], $second['id']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM order_inspection_fees WHERE order_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    // ---------------------------------------------------------------
    // OrderFinancialsController::saveInspectionFee()
    // ---------------------------------------------------------------

    public function testSaveInspectionFeeRejectsAnInvalidPayer(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['fee_payer' => 'someone-else'];

        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertNull(OrderInspectionFeeRepository::find($orderId));
    }

    public function testNexacrestPaidFeeMustSayWhatItWasPaidFrom(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['fee_payer' => 'nexacrest', 'fee_amount' => '2500'];

        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('Choose what the inspection fee was paid from.', Flash::pull()[0]['message']);
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId));
    }

    public function testNexacrestPayerWithAnAmountCreatesAMatchingCostEntry(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['fee_payer' => 'nexacrest', 'fee_amount' => '2500', 'paid_from' => 'company_account'];

        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('success', Flash::pull()[0]['type']);
        $entries = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $entries);
        self::assertSame('inspection_fee', $entries[0]['category']);
        self::assertSame('2500.00', $entries[0]['amount_inr']);
        self::assertSame('company_account', $entries[0]['paid_from'], 'owner decision 2026-10-29: what it was paid from, for Zoho Books');

        $saved = OrderInspectionFeeRepository::find($orderId);
        self::assertSame((int) $entries[0]['id'], (int) $saved['cost_entry_id']);
    }

    public function testClientPayerNeverCreatesACostEntryAndRemovesAnExistingOne(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['fee_payer' => 'nexacrest', 'fee_amount' => '2500', 'paid_from' => 'company_account'];
        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();
        self::assertCount(1, OrderCostEntryRepository::forOrder($orderId));

        $_POST = ['fee_payer' => 'client'];
        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame([], OrderCostEntryRepository::forOrder($orderId), 'the client is paying directly now — NexaCrest incurred no cost');
        self::assertNull(OrderInspectionFeeRepository::find($orderId)['cost_entry_id']);
    }

    public function testReSavingNexacrestWithADifferentAmountUpdatesTheSameCostEntry(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['fee_payer' => 'nexacrest', 'fee_amount' => '2500', 'paid_from' => 'company_account'];
        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();
        $firstEntryId = (int) OrderCostEntryRepository::forOrder($orderId)[0]['id'];

        $_POST = ['fee_payer' => 'nexacrest', 'fee_amount' => '3000', 'paid_from' => 'company_account'];
        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => (string) $orderId]);
        ob_end_clean();

        $entries = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $entries);
        self::assertSame($firstEntryId, (int) $entries[0]['id']);
        self::assertSame('3000.00', $entries[0]['amount_inr']);
    }

    public function testSaveInspectionFeeReturns404ForAnUnknownOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderFinancialsController())->saveInspectionFee(['id' => '999999999']);
        $output = ob_get_clean();

        self::assertStringContainsString('not found', strtolower($output));
    }

    // ---------------------------------------------------------------
    // View — form/section gating
    // ---------------------------------------------------------------

    public function testOrderPageOffersTheStructuredShipmentDateField(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 3);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="est_shipment_date"', $output);
    }

    public function testInspectionFeeSectionIsHiddenFromARoleWithoutManageOrderFinancials(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 3);
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('name="fee_payer"', $output, 'inspection fee figures are financial/sensitive — only manage_order_financials roles may see this form');
    }

    public function testInspectionFeeSectionIsHiddenOnceTheTaskIsMarkedSkipped(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 3);
        $taskType = Database::connection()->query("SELECT id FROM compliance_task_types WHERE name = 'Pre-Shipment Inspection'")->fetch();
        OrderComplianceTaskRepository::setStatus($orderId, (int) $taskType['id'], 'skipped', 'No 3rd-party inspection required for this order', $userId);
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('name="fee_payer"', $output, 'marked not-applicable — no inspection fee to track');
    }

    // ---------------------------------------------------------------
    // check_alerts.php — the 7-day reminder itself
    // ---------------------------------------------------------------

    public function testAlertsWhenEstimatedShipmentIsWithinTheNext7DaysAndInspectionIsNotSkipped(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        Database::connection()->prepare("UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 5 DAY) WHERE id = :id")->execute(['id' => $orderId]);

        \run();

        self::assertTrue(NotificationRepository::existsToday($adminId, 'pre_shipment_inspection_reminder', $orderId));
    }

    public function testDoesNotAlertWhenEstimatedShipmentIsMoreThan7DaysOut(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        Database::connection()->prepare("UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 10 DAY) WHERE id = :id")->execute(['id' => $orderId]);

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'pre_shipment_inspection_reminder', $orderId));
    }

    public function testDoesNotAlertOnceTheEstimatedShipmentDateHasAlreadyPassed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        Database::connection()->prepare("UPDATE orders SET est_shipment_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :id")->execute(['id' => $orderId]);

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'pre_shipment_inspection_reminder', $orderId));
    }

    public function testDoesNotAlertWhenThePreShipmentInspectionTaskIsMarkedSkipped(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY) WHERE id = :id")->execute(['id' => $orderId]);
        $taskType = Database::connection()->query("SELECT id FROM compliance_task_types WHERE name = 'Pre-Shipment Inspection'")->fetch();
        OrderComplianceTaskRepository::setStatus($orderId, (int) $taskType['id'], 'skipped', 'No 3rd-party inspection required', $userId);

        \run();

        self::assertFalse(NotificationRepository::existsToday($userId, 'pre_shipment_inspection_reminder', $orderId), 'marked not-applicable — no reminder needed');
    }

    public function testDoesNotAlertForALockedOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        Database::connection()->prepare("UPDATE orders SET est_shipment_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY), is_locked = 1, status = 'lost' WHERE id = :id")->execute(['id' => $orderId]);

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'pre_shipment_inspection_reminder', $orderId));
    }

    public function testDoesNotAlertWhenNoEstimatedShipmentDateIsSet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        // createTestOrder() leaves est_shipment_date NULL by default.

        \run();

        self::assertFalse(NotificationRepository::existsToday($adminId, 'pre_shipment_inspection_reminder', $orderId));
    }

    /**
     * External QA report N-09: notifyMdAndAdmin() used to call
     * EmailService::sendPlainText() directly right after
     * NotificationRepository::create(), which already sends its own
     * email via emailNotification() internally — every recipient of
     * every alert type in this cron job (not just this one reminder)
     * got two differently-worded emails for the same event. Whitebox,
     * same technique AuditLogNotificationHookTest already uses for
     * "is this wiring actually gone" checks that aren't otherwise
     * observable without mocking EmailService.
     */
    public function testNotifyMdAndAdminNoLongerSendsADuplicateEmailDirectly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/cron/check_alerts.php');
        $functionBody = substr($source, (int) strpos($source, 'function notifyMdAndAdmin'), 400);

        self::assertStringNotContainsString(
            'EmailService::sendPlainText',
            $functionBody,
            'notifyMdAndAdmin() must rely solely on NotificationRepository::create()\'s own email, not also send one directly'
        );
    }
}
