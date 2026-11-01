<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\PaymentMilestoneController;
use App\Helpers\Flash;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Services\PartPaymentService;
use App\Services\PaymentDescription;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Gap list G-5 (owner, 1 Nov 2026: "G-1 to G-5 yes"): a buyer payment may
 * arrive in parts — each receipt is kept, the leg keeps the total, the
 * page shows what is still owed, and a leg is cleared short only by a
 * manager with "Approve short payment" and a written reason, which is
 * recorded. The Node suite has the same test (gapPartPayments.test.js).
 */
final class GapPartPaymentsTest extends DbTestCase
{
    private int $admin;
    private int $md;
    private int $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $this->md = $this->createTestUser('Managing Director');
        $this->accounts = $this->createTestUser('Accounts Executive');
        $this->as($this->admin);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function as(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
    }

    private function call(string $action, int $orderId, array $post = [], array $params = []): array
    {
        $_POST = $post;
        ob_start();
        $controller = $action === 'record' || $action === 'clear' ? new PaymentMilestoneController() : new OrderController();
        $controller->{$action}(['id' => (string) $orderId] + $params);
        ob_end_clean();
        return Flash::pull()[0] ?? ['type' => '', 'message' => ''];
    }

    /** A USD order at Stage 3 with its PI approved: products USD 4,500, advance 40% = USD 1,800 due. */
    private function orderAtAdvance(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        Database::connection()->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        Database::connection()->prepare('UPDATE orders SET advance_pct = 40 WHERE id = :o')->execute(['o' => $orderId]);
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        $this->createTestDocument($orderId, 'PI', 'approved');
        Database::connection()->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        return $orderId;
    }

    private function receipt(string $amount, string $date, string $rate, string $ref, bool $differs = true): array
    {
        return ['advance_amount' => $amount, 'advance_received_at' => $date, 'advance_exchange_rate' => $rate, 'advance_bank_reference' => $ref] + ($differs ? ['confirm_amount_differs' => '1'] : []);
    }

    public function testTwoReceiptsAddUpAndTheLegKeepsTheTotal(): void
    {
        $orderId = $this->orderAtAdvance();
        $first = $this->call('recordAdvancePayment', $orderId, $this->receipt('1000', '2026-10-20', '84', 'UTR0001'));
        self::assertSame('success', $first['type'], $first['message']);
        $again = $this->call('recordAdvancePayment', $orderId, $this->receipt('1000', '2026-10-20', '84', 'UTR0001'));
        self::assertSame('Advance remittance already recorded with these details — nothing changed.', $again['message'], 'a second click records nothing more');
        $second = $this->call('recordAdvancePayment', $orderId, $this->receipt('800', '2026-10-24', '85', 'UTR0002', false));
        self::assertSame('success', $second['type'], 'a further receipt needs no "differs" tick: ' . $second['message']);

        $ops = OrderPaymentStatusRepository::find($orderId);
        self::assertSame(['1800.00', '2026-10-24', '84.4444', 'UTR0001, UTR0002'], [$ops['advance_amount'], substr((string) $ops['advance_remittance_received_at'], 0, 10), $ops['advance_exchange_rate'], $ops['advance_bank_reference']]);
        $s = PartPaymentService::summary($orderId, 'advance');
        self::assertSame([1800.0, 1800.0, 0.0, 2], [$s['expected'], $s['received'], $s['owed'], count($s['receipts'])]);
        self::assertStringContainsString('bank ref UTR0001, UTR0002', PaymentDescription::buyerPayment($orderId, 'advance', null), 'the description names both bank references (D-212)');
    }

    public function testAShortLegIsClearedOnlyByAManagerWithAReason(): void
    {
        $orderId = $this->orderAtAdvance();
        $this->call('recordAdvancePayment', $orderId, $this->receipt('1500', '2026-10-20', '84', 'UTR0101'));
        $ordersPage = '';
        $s = PartPaymentService::summary($orderId, 'advance');
        self::assertSame(300.0, $s['owed']);

        $this->as($this->accounts);
        $refused = $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21']);
        self::assertSame('Advance received so far: USD 1,500.00 of USD 1,800.00 due — USD 300.00 is still owed. Record the rest when it arrives, or ask a manager with "Approve short payment" to clear it with a written reason.', $refused['message']);
        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_cleared_at']);

        $this->as($this->md);
        $noReason = $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21', 'short_reason' => 'short']);
        self::assertSame('USD 300.00 of the advance is still owed. To clear it short, give the reason (10 to 500 characters) — it is recorded and shown on the order.', $noReason['message']);
        $ok = $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21', 'short_reason' => 'Buyer confirmed the rest comes with the balance.']);
        self::assertStringContainsString('Short by USD 300.00: approved by PHPUnit Test User — carried into the balance.', $ok['message']);
        $row = Database::connection()->query("SELECT * FROM order_payment_shortfalls WHERE order_id = {$orderId}")->fetch();
        self::assertSame(['advance', '1800.00', '1500.00', '300.00', 'carried_to_balance'], [$row['leg'], $row['expected_amount'], $row['received_amount'], $row['shortfall_amount'], $row['treatment']]);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PAYMENT_SHORTFALL_APPROVED' AND entity_id = {$orderId}")->fetchColumn());
        self::assertSame(3000.0, PartPaymentService::expected($orderId, 'balance'), 'the advance shortfall is part of the balance (order value less the advance received)');

        $after = $this->call('recordAdvancePayment', $orderId, $this->receipt('300', '2026-10-25', '84', 'UTR0102'));
        self::assertSame('The advance is already cleared — no more receipts can be added to it.', $after['message']);
    }

    public function testAnExcessIsShownAndDoesNotBlock(): void
    {
        $orderId = $this->orderAtAdvance();
        $this->call('recordAdvancePayment', $orderId, $this->receipt('2000', '2026-10-20', '84', 'UTR0201'));
        self::assertSame(-200.0, PartPaymentService::summary($orderId, 'advance')['owed']);
        $this->as($this->accounts);
        $cleared = $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21']);
        self::assertStringStartsWith('Advance payment cleared.', $cleared['message']);
        $this->as($this->admin);
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('paid USD 200.00 more than due', $page);
    }

    public function testAMilestonePaidShortIsWrittenOffWithTheManagersReason(): void
    {
        $orderId = $this->orderAtAdvance();
        PaymentMilestoneRepository::replaceSchedule($orderId, [['pct' => 20.0, 'due_text' => 'when production starts']], 40.0);
        $m = PaymentMilestoneRepository::forOrder($orderId)[0];
        $rec = $this->call('record', $orderId, ['amount' => '700', 'exchange_rate' => '84', 'bank_reference' => 'UTR0301', 'received_on' => '2026-10-22', 'confirm_amount_differs' => '1'], ['milestoneId' => (string) $m['id']]);
        self::assertSame('success', $rec['type'], $rec['message']);
        self::assertCount(1, PartPaymentService::receipts($orderId, 'milestone', (int) $m['id']), 'the milestone receipt is in the history');

        $this->as($this->accounts);
        $refused = $this->call('clear', $orderId, ['cleared_on' => '2026-10-23'], ['milestoneId' => (string) $m['id']]);
        self::assertSame('Milestone 2 received so far: USD 700.00 of USD 900.00 due — USD 200.00 is still owed. Record the rest when it arrives, or ask a manager with "Approve short payment" to clear it with a written reason.', $refused['message']);
        $this->as($this->md);
        $ok = $this->call('clear', $orderId, ['cleared_on' => '2026-10-23', 'short_reason' => 'Bank charges taken by the buyer\'s bank, agreed.'], ['milestoneId' => (string) $m['id']]);
        self::assertSame('Milestone 2 cleared. It goes to Zoho Books with the next revenue sync. Short by USD 200.00: approved by PHPUnit Test User — written off.', $ok['message']);
        self::assertSame('written_off', Database::connection()->query("SELECT treatment FROM order_payment_shortfalls WHERE order_id = {$orderId} AND leg = 'milestone'")->fetchColumn());
    }

    public function testASingleReceiptLeavesTheLegExactlyAsBefore(): void
    {
        // Zoho, CAFIN, reports and documents read the leg's columns: one receipt must leave them as the old single-amount recording did.
        $orderId = $this->orderAtAdvance();
        $this->call('recordAdvancePayment', $orderId, $this->receipt('1800', '2026-10-20', '83.25', 'HDFCN26304123456', false));
        $ops = OrderPaymentStatusRepository::find($orderId);
        self::assertSame(['1800.00', '2026-10-20', '83.2500', 'HDFCN26304123456'], [$ops['advance_amount'], substr((string) $ops['advance_remittance_received_at'], 0, 10), $ops['advance_exchange_rate'], $ops['advance_bank_reference']]);
        $description = PaymentDescription::buyerPayment($orderId, 'advance', null);
        self::assertStringContainsString(' · USD 1,800.00 at ₹83.25 · ', $description);
        self::assertStringEndsWith(' · bank ref HDFCN26304123456', $description);
    }

    public function testTheMigrationBackFillsOneReceiptPerRecordedLeg(): void
    {
        $orderId = $this->orderAtAdvance();
        Database::connection()->prepare("UPDATE order_payment_status SET advance_amount = 1800, advance_remittance_received_at = '2026-10-01 10:00:00', advance_exchange_rate = 83.5, advance_bank_reference = 'OLD0001' WHERE order_id = :o")->execute(['o' => $orderId]);
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/migrations/2026-11-02d-gap-part-payments.sql');
        $backfill = substr($sql, (int) strpos($sql, '-- Existing orders'));
        foreach (array_filter(array_map('trim', explode(';', $backfill))) as $statement) {
            if (str_contains($statement, 'INSERT')) {
                Database::connection()->exec($statement);
                Database::connection()->exec($statement); // safe to run twice
            }
        }
        $receipts = PartPaymentService::receipts($orderId, 'advance');
        self::assertCount(1, $receipts);
        self::assertSame(['1800.00', '2026-10-01', '83.5000', 'OLD0001'], [$receipts[0]['amount'], $receipts[0]['received_on'], $receipts[0]['exchange_rate'], $receipts[0]['bank_reference']]);
    }
}
