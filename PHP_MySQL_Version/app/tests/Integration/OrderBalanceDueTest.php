<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner report (2026-10-27, blocker): the Order Confirmation printed the
 * advance actually received under "40% Advance" while the balance was
 * still FOB × 60% — the two did not add up to the order value. The balance
 * due is now FOB − advance actually received, and a mistyped advance is
 * caught when it is recorded.
 */
final class OrderBalanceDueTest extends DbTestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($this->orderId, 1, 'Absolute black granite', null, null, '50', false, 'PCS', '150.00');
        OrderPaymentStatusRepository::initializeForOrder($this->orderId);
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Accounts Executive')];
        StageGateService::passAndUnlockNext($this->orderId, 1, null);
        StageGateService::passAndUnlockNext($this->orderId, 2, null);
        Flash::pull();
    }

    private function recordAdvance(array $post): array
    {
        $_POST = $post + ['advance_received_at' => '2026-10-27', 'advance_exchange_rate' => '83.5', 'advance_bank_reference' => 'UTR-TEST-0001'];
        ob_start();
        (new OrderController())->recordAdvancePayment(['id' => (string) $this->orderId]);
        ob_end_clean();
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    public function testAnAdvanceAboveTheOrderValueIsRefused(): void
    {
        $result = $this->recordAdvance(['advance_amount' => '7600', 'confirm_amount_differs' => '1']);
        self::assertSame('error', $result['type']);
        self::assertStringContainsString('more than the whole order value', $result['message']);
        self::assertNull(OrderPaymentStatusRepository::find($this->orderId)['advance_amount']);
    }

    public function testAnAdvanceThatDiffersFromTheAdvanceDueMustBeConfirmed(): void
    {
        $result = $this->recordAdvance(['advance_amount' => '7300']);
        self::assertSame('error', $result['type']);
        self::assertStringContainsString('The advance due on this order is USD 3,000.00 (40% of USD 7,500.00)', $result['message']);
        self::assertNull(OrderPaymentStatusRepository::find($this->orderId)['advance_amount']);

        self::assertSame('success', $this->recordAdvance(['advance_amount' => '2985'])['type'], 'within 1% (bank charges) needs no confirmation');
        self::assertSame('success', $this->recordAdvance(['advance_amount' => '3500', 'confirm_amount_differs' => '1'])['type']);
        self::assertEqualsWithDelta(3500, (float) OrderPaymentStatusRepository::find($this->orderId)['advance_amount'], 0.001);
    }

    public function testBalanceDueIsTheOrderValueMinusTheAdvanceActuallyReceived(): void
    {
        $this->recordAdvance(['advance_amount' => '3500', 'confirm_amount_differs' => '1']);
        $this->createTestDocument($this->orderId, 'PI', 'approved');
        $_POST = ['advance_cleared_at' => '2026-10-27'];
        ob_start();
        (new OrderController())->clearAdvancePayment(['id' => (string) $this->orderId]);
        ob_end_clean();
        self::assertEqualsWithDelta(4000, (float) OrderPaymentStatusRepository::find($this->orderId)['balance_amount'], 0.001);

        $context = DocumentDataAssembler::assemble($this->orderId, 'OC');
        self::assertSame('7,500.00', $context['financial']['total_value']);
        self::assertSame('3,000.00', $context['financial']['advance_amount'], 'the advance due by the terms');
        self::assertSame('3,500.00', $context['payment_status']['advance_amount'], 'the advance actually received');
        self::assertSame('4,000.00', $context['payment_status']['balance_amount']);
    }

    public function testAStaleStoredBalanceIsCorrectedOnTheDocuments(): void
    {
        OrderPaymentStatusRepository::recordAdvanceReceived($this->orderId, 7300, '2026-10-27');
        OrderPaymentStatusRepository::setBalanceAmount($this->orderId, 4500, null);
        self::assertSame('200.00', DocumentDataAssembler::assemble($this->orderId, 'OC')['payment_status']['balance_amount']);
    }
}
