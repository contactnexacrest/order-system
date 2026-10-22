<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-35 (OOS-01): "Actions are accepted out of
 * sequence: on an order still at Stage 1 the app stores shipping
 * details (vessel, container), a freight payment, and the advance FIRC,
 * exchange rate and INR-credited amount (the advance itself was never
 * recorded)." Each handler below now checks either the stage it
 * actually belongs to (recordFreightPayment/savePacking/saveShipping,
 * matching the gate checks clearFreightPayment/recordBlIssued already
 * use) or the prerequisite record it depends on
 * (recordAdvanceFirc/recordLegExchangeRate/recordAdvanceInrActual and
 * their balance/freight siblings — the leg's own *_amount must be
 * recorded first).
 */
final class OrderOutOfSequenceActionsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    public function testRecordFreightPaymentRefusesOnAnOrderStillAtStage1(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Initialized (not left absent) so the UPDATE below would actually
        // succeed if the stage check didn't block it first — otherwise
        // this test would pass vacuously (the UPDATE is a no-op against a
        // nonexistent row regardless of the fix).
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $_POST = ['freight_amount' => '500'];

        ob_start();
        (new OrderController())->recordFreightPayment(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderPaymentStatusRepository::find($orderId)['freight_amount'] ?? null, 'freight payment must not be recorded before Stage 6 is unlocked');
    }

    public function testSaveShippingRefusesOnAnOrderStillAtStage1(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['vessel_name' => 'MV Test Vessel'];

        ob_start();
        (new OrderController())->saveShipping(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderShippingRepository::find($orderId)['vessel_name'] ?? null, 'shipping details must not be stored before Stage 7 is unlocked');
    }

    public function testSavePackingRefusesOnAnOrderStillAtStage1(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['crate_count' => '3'];

        ob_start();
        (new OrderController())->savePacking(['id' => (string) $orderId]);
        ob_end_clean();

        $packing = \App\Repositories\OrderPackingRepository::find($orderId);
        self::assertNull($packing, 'packing figures must not be stored before Stage 7 is unlocked');
    }

    public function testRecordAdvanceFircRefusesWhenTheAdvanceItselfWasNeverRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Initialized but advance_amount left NULL — see
        // testRecordFreightPaymentRefusesOnAnOrderStillAtStage1's comment
        // for why this matters.
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $_POST = ['advance_firc_reference' => 'FIRC-12345'];

        ob_start();
        (new OrderController())->recordAdvanceFirc(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_firc_reference'] ?? null);
    }

    public function testRecordAdvanceFircSucceedsOnceTheAdvanceIsRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-01-10');
        $_POST = ['advance_firc_reference' => 'FIRC-12345'];

        ob_start();
        (new OrderController())->recordAdvanceFirc(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('FIRC-12345', OrderPaymentStatusRepository::find($orderId)['advance_firc_reference'] ?? null);
    }

    public function testRecordFreightExchangeRateRefusesWhenTheFreightPaymentWasNeverRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $_POST = ['freight_exchange_rate' => '83.25'];

        ob_start();
        (new OrderController())->recordFreightExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderPaymentStatusRepository::find($orderId)['freight_exchange_rate'] ?? null);
    }

    public function testRecordFreightExchangeRateSucceedsOnceTheFreightPaymentIsRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordFreightReceived($orderId, 800.0, '2026-01-10');
        $_POST = ['freight_exchange_rate' => '83.25'];

        ob_start();
        (new OrderController())->recordFreightExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('83.2500', OrderPaymentStatusRepository::find($orderId)['freight_exchange_rate'] ?? null);
    }

    public function testRecordBalanceInrActualRefusesWhenTheBalancePaymentWasNeverRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $_POST = ['balance_inr_actual' => '415000'];

        ob_start();
        (new OrderController())->recordBalanceInrActual(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderPaymentStatusRepository::find($orderId)['balance_inr_actual'] ?? null);
    }

    public function testRecordBalanceInrActualSucceedsOnceTheBalancePaymentIsRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 5000.0, '2026-01-10');
        $_POST = ['balance_inr_actual' => '415000'];

        ob_start();
        (new OrderController())->recordBalanceInrActual(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('415000.00', OrderPaymentStatusRepository::find($orderId)['balance_inr_actual'] ?? null);
    }
}
