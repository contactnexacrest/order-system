<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\PaymentActionController;
use App\Helpers\Flash;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\EmailDispatchService;
use App\Services\PaymentActionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27): a no-login Payment Action Link — the client
 * sends payment details for the advance (PI), freight (Debit Note) and
 * balance (CI), one payment at a time; payment only, never the Buyer PO.
 * The link also goes out inside the PI / Debit Note / CI emails.
 */
final class PaymentActionLinkTest extends DbTestCase
{
    private int $orderId;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        Flash::pull();
        $this->orderId = $this->createTestOrder($this->createTestClient('buyer-' . bin2hex(random_bytes(3)) . '@example.test'));
        Database::connection()->prepare("UPDATE orders SET payment_method = 'CASH_ADVANCE' WHERE id = :id")->execute(['id' => $this->orderId]);
        OrderProductRepository::add($this->orderId, 1, 'Absolute black granite', null, null, '50', false, 'PCS', '150.00');
        OrderPaymentStatusRepository::initializeForOrder($this->orderId);
        $this->token = OrderRepository::generatePaymentActionToken($this->orderId);
    }

    private function page(): string
    {
        ob_start();
        (new PaymentActionController())->show(['token' => $this->token]);
        return (string) ob_get_clean();
    }

    public function testShowsOnlyThePaymentDueOneAtATime(): void
    {
        $waiting = $this->page();
        self::assertStringContainsString('Nothing to pay right now', $waiting);
        self::assertStringNotContainsString('name="transaction_ref"', $waiting);
        self::assertStringNotContainsString('buyers_po_ref', $waiting, 'payment only — never the Buyer PO');

        $this->createTestDocument($this->orderId, 'PI', 'approved', $this->createTestFile($this->orderId));
        $due = $this->page();
        self::assertStringContainsString('Advance Payment', $due);
        self::assertStringContainsString('USD 3,000.00', $due);
        self::assertStringContainsString('/payment-action/' . $this->token . '/document', $due);
        self::assertStringContainsString('name="screenshot"', $due);
        self::assertStringNotContainsString('Acknowledge', $due);

        ClientPaymentReportRepository::create($this->orderId, 'advance', 'UTR-1', null, 3000, '2026-10-27', null);
        self::assertStringContainsString("we're checking your advance payment", $this->page());

        OrderPaymentStatusRepository::markAdvanceCleared($this->orderId, '2026-10-27', 1);
        $next = $this->page();
        self::assertStringContainsString('balance payment', $next);
        self::assertStringContainsString('Nothing to pay right now', $next, 'the CI is not issued yet');
        self::assertSame(['advance' => 'done', 'balance' => 'current'], PaymentActionService::current(OrderRepository::find($this->orderId))['legs']);
    }

    public function testAReportNeedsTheDetailsAndAScreenshot(): void
    {
        $this->createTestDocument($this->orderId, 'PI', 'approved', $this->createTestFile($this->orderId));
        $order = OrderRepository::find($this->orderId);
        $_POST = ['transaction_ref' => 'UTR-1', 'amount' => '3000', 'payment_date' => '2026-10-27'];
        $result = PaymentActionService::report($order);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('screenshot', $result['message']);
        $_POST['amount'] = '';
        self::assertStringContainsString('amount you paid', PaymentActionService::report($order)['message']);
        self::assertSame([], ClientPaymentReportRepository::forOrder($this->orderId));
    }

    public function testAnInvalidTokenShowsTheExpiredPage(): void
    {
        $this->token = 'not-a-real-token';
        self::assertStringContainsString('Link No Longer Valid', $this->page());
    }

    public function testThePiEmailCarriesThePaymentLink(): void
    {
        $docId = $this->createTestDocument($this->orderId, 'PI', 'approved', $this->createTestFile($this->orderId));
        $preview = EmailDispatchService::buildPreview($this->orderId, $docId, 'send_pi', $this->createTestUser('Admin'));
        self::assertStringContainsString('upload the payment screenshot', $preview['body']);
        self::assertStringContainsString('/payment-action/' . $this->token, $preview['body'], 'the same live link, not a new one');
    }

    public function testStaffGenerateTheLinkFromTheOrderPage(): void
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Payment Action Link', $html);
        self::assertStringContainsString('/orders/' . $this->orderId . '/payment-action-link', $html);

        ob_start();
        (new OrderController())->generatePaymentActionLink(['id' => (string) $this->orderId]);
        ob_end_clean();
        $flash = Flash::pull();
        self::assertStringContainsString('/payment-action/', end($flash)['message']);
        self::assertNull(OrderRepository::findByValidPaymentActionToken($this->token), 'regenerating replaces the old link');
    }
}
