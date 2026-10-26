<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "exchange-rate-at-clearing-time": docs/SOP/
 * ca-02-revenue-reports.md says a leg's Exchange Rate is "typically
 * entered once that leg is about to clear, whenever your accounts team
 * fixes a working rate for it" — i.e. BEFORE the leg is marked cleared,
 * as an anticipatory estimate, separate from the real INR actual
 * recorded AT clearing. The Exchange Rate widget used to only ever
 * render from inside the INR Actual widget, which itself returns early
 * until cleared_at is set — so the rate box simply didn't exist yet for
 * any leg that had been received but not yet cleared, contradicting the
 * documented workflow.
 */
final class ExchangeRateBeforeClearingTest extends DbTestCase
{
    private function renderOrderShow(int $orderId): string
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testAdvanceExchangeRateFormIsOfferedOnceReceivedButBeforeClearing(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-01-10');

        $output = $this->renderOrderShow($orderId);

        self::assertStringContainsString('name="advance_exchange_rate"', $output, 'the rate box must be offered as soon as the advance is received, not only after it clears');
    }

    public function testAdvanceExchangeRateFormIsNotOfferedBeforeAnyAmountIsRecorded(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $output = $this->renderOrderShow($orderId);

        // The remittance form itself now asks for the day's rate alongside
        // the amount; what must not appear yet is the separate CA rate box.
        self::assertStringNotContainsString('/payment/advance/exchange-rate"', $output, 'there is nothing to fix a rate against until an amount is actually on record');
    }

    public function testAdvanceExchangeRateFormStillWorksAfterClearing(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-12', $this->createTestUser('Admin'));

        $output = $this->renderOrderShow($orderId);

        self::assertStringContainsString('name="advance_exchange_rate"', $output, 'clearing a leg must never make the rate box disappear');
    }
}
