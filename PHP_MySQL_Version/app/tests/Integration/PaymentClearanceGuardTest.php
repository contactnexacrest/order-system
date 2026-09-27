<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (GATE-04/GATE-05/GATE-10 — external QA report cross-verification):
 * a payment must actually be recorded (a non-NULL amount) before it can be
 * marked cleared. The stage-unlock check alone only proves the *previous*
 * gate passed — it says nothing about whether the money itself was ever
 * recorded, so clearing a NULL advance/balance used to still unlock the
 * next stage (and, for the advance, provision client portal access) with
 * zero money having actually been received.
 */
final class PaymentClearanceGuardTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testClearAdvancePaymentRefusesAnUnrecordedAdvance(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        $userId = $this->createTestUser('Accounts Executive');
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        self::assertTrue(StageGateService::isUnlocked($orderId, 3));
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->clearAdvancePayment(['id' => $orderId]);
        ob_end_clean();

        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_cleared_at']);
        self::assertFalse(StageGateService::isUnlocked($orderId, 4), 'Stage 4 must not unlock from an unrecorded advance');
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
    }

    public function testClearAdvancePaymentSucceedsOnceRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        $userId = $this->createTestUser('Accounts Executive');
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->clearAdvancePayment(['id' => $orderId]);
        ob_end_clean();

        self::assertNotNull(OrderPaymentStatusRepository::find($orderId)['advance_cleared_at']);
        self::assertTrue(StageGateService::isUnlocked($orderId, 4));
    }

    public function testClearBalancePaymentRefusesAnUnrecordedBalance(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        $userId = $this->createTestUser('Accounts Executive');
        // Reach Stage 8 WITHOUT going through clearAdvancePayment() (which
        // would auto-populate balance_amount as a side effect) — this
        // mirrors an admin stage override reaching Stage 8 some other way,
        // the exact gap GATE-04 describes.
        for ($stage = 1; $stage <= 7; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        self::assertTrue(StageGateService::isUnlocked($orderId, 8));
        self::assertNull(OrderPaymentStatusRepository::find($orderId)['balance_amount']);
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->clearBalancePayment(['id' => $orderId]);
        ob_end_clean();

        self::assertNull(OrderPaymentStatusRepository::find($orderId)['balance_cleared_at']);
        self::assertFalse(StageGateService::isUnlocked($orderId, 9), 'Stage 9 must not unlock from an unrecorded balance');
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
    }

    public function testClearBalancePaymentSucceedsOnceRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        $userId = $this->createTestUser('Accounts Executive');
        for ($stage = 1; $stage <= 7; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        OrderPaymentStatusRepository::setBalanceAmount($orderId, 1500.00, '2026-03-01');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->clearBalancePayment(['id' => $orderId]);
        ob_end_clean();

        self::assertNotNull(OrderPaymentStatusRepository::find($orderId)['balance_cleared_at']);
        self::assertTrue(StageGateService::isUnlocked($orderId, 9));
    }

    public function testClearAdvancePaymentOnANonexistentOrderNeverCrashes(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->clearAdvancePayment(['id' => 999999]);
        $output = ob_get_clean();

        // The real assertion is simply that this didn't throw/500 — a
        // graceful error flash + redirect is all that's required here.
        self::assertIsString($output);
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
    }
}
