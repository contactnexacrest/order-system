<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-26): Step 12 is four guided sub-steps — 12a
 * costs (marked complete), 12b scheme eligibility, 12c benefits claimed
 * and received in one table with one Save, 12d review & complete.
 */
final class OrderStep12SubStepsTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        Flash::pull();
    }

    private function lastFlash(): array
    {
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    private function resolveSchemes(int $orderId, string $eligibleScheme): void
    {
        foreach (OrderExportBenefitSchemeRepository::forOrder($orderId) as $row) {
            $eligible = $row['scheme_name'] === $eligibleScheme;
            OrderExportBenefitSchemeRepository::setStatus($orderId, $row['scheme_name'], $eligible ? 'eligible' : 'not_eligible', $eligible ? null : 'Not applicable to this order', $this->userId);
        }
    }

    public function testStep12CompletesOnlyAfterCostsAndEligibility(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $page = fn (): string => (function () use ($orderId) {
            ob_start();
            (new OrderController())->show(['id' => (string) $orderId]);
            return (string) ob_get_clean();
        })();
        foreach (['financials-12a', 'financials-12b', 'financials-12c', 'financials-12d'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $page());
        }

        (new OrderFinancialsController())->completeFinancials(['id' => (string) $orderId]);
        $refused = $this->lastFlash();
        self::assertSame('error', $refused['type']);
        self::assertStringContainsString('12a', $refused['message']);
        self::assertStringContainsString('12b', $refused['message']);

        (new OrderFinancialsController())->confirmCosts(['id' => (string) $orderId]);
        self::assertNotNull(OrderRepository::find($orderId)['financials_costs_confirmed_at']);
        $this->resolveSchemes($orderId, 'RODTEP');

        (new OrderFinancialsController())->completeFinancials(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type']);
        self::assertNotNull(OrderRepository::find($orderId)['financials_completed_at']);
        self::assertStringContainsString('&#10003; Step 12 completed on', $page());

        $_POST = ['action' => 'reopen'];
        (new OrderFinancialsController())->completeFinancials(['id' => (string) $orderId]);
        (new OrderFinancialsController())->confirmCosts(['id' => (string) $orderId]);
        $order = OrderRepository::find($orderId);
        self::assertNull($order['financials_completed_at']);
        self::assertNull($order['financials_costs_confirmed_at']);
    }

    public function testOneSaveRecordsClaimsAndTheMoneyReceived(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->resolveSchemes($orderId, 'RODTEP');
        $waitingId = CaExportBenefitRepository::record($orderId, 'Duty Drawback', 'SB-1', 9800, '2026-10-01', 'INR', null, $this->userId);

        $_POST = ['benefits' => [
            ['benefit_id' => (string) $waitingId, 'received_amount' => '9800', 'received_at' => '2026-10-20'],
            ['scheme_name' => 'RODTEP', 'reference_number' => 'SB-2', 'claimed_amount' => '18450', 'claimed_at' => '2026-10-05', 'received_amount' => '', 'received_at' => '2026-10-26'],
        ]];
        (new OrderFinancialsController())->saveExportBenefits(['id' => (string) $orderId]);
        self::assertStringContainsString('documents are sent', $this->lastFlash()['message'], 'claims wait until docs are sent and the balance is in');
        self::assertCount(1, CaExportBenefitRepository::forOrder($orderId), 'nothing saved');

        Database::connection()->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $orderId]);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        Database::connection()->prepare('UPDATE order_payment_status SET balance_remittance_received_at = NOW() WHERE order_id = :id')->execute(['id' => $orderId]);

        $_POST['benefits'][1]['claimed_at'] = 'not-a-date';
        (new OrderFinancialsController())->saveExportBenefits(['id' => (string) $orderId]);
        self::assertStringContainsString('Nothing was saved', $this->lastFlash()['message']);
        self::assertNull(CaExportBenefitRepository::find($waitingId)['received_amount'], 'all-or-nothing: the valid receipt is not saved either');

        $_POST['benefits'][1]['claimed_at'] = '2026-10-05';
        (new OrderFinancialsController())->saveExportBenefits(['id' => (string) $orderId]);
        self::assertSame('Benefits saved — 1 claim(s) recorded, 1 receipt(s) recorded.', $this->lastFlash()['message']);
        $claims = array_column(CaExportBenefitRepository::forOrder($orderId), null, 'scheme_name');
        self::assertEqualsWithDelta(9800, (float) $claims['Duty Drawback']['received_amount'], 0.001);
        self::assertEqualsWithDelta(18450, (float) $claims['RODTEP']['claimed_amount'], 0.001);
        self::assertNull($claims['RODTEP']['received_amount']);
    }
}
