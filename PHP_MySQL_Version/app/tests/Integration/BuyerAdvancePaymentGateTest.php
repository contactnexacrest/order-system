<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\BuyerActionController;
use App\Controllers\ClientPortalController;
use App\Helpers\Flash;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #19/#48.
 *
 * #19: BuyerActionService::uploadBuyerPo() used to be callable "at any
 * time" on both the client-portal and no-login Buyer Action Link channels
 * — this adds a gate so a further client self-upload is refused once the
 * advance payment has already cleared (the Buyer PO's whole job is done
 * by then).
 *
 * #48: the client portal's own payment self-report
 * (ClientPortalController::reportPayment()) only exists for a buyer who
 * can log in, but the portal login itself isn't provisioned until the
 * advance payment clears — so the advance leg specifically could never
 * actually be self-reported through it. This adds a narrow no-login
 * equivalent (BuyerActionService::reportAdvancePayment(), scoped to the
 * advance leg only) on the same Buyer Action Link page, which itself
 * closes once the advance clears (there is nothing left to report).
 */
final class BuyerAdvancePaymentGateTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        http_response_code(200);
    }

    private function markAdvanceCleared(int $orderId): void
    {
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, date('Y-m-d H:i:s'), 1);
    }

    // ---------------------------------------------------------------
    // #19 — uploadBuyerPo, via the no-login Buyer Action Link
    // ---------------------------------------------------------------

    public function testUploadBuyerPoStillReachesTheUploadPathBeforeAdvanceClears(): void
    {
        // No order_payment_status row at all yet (same as a brand-new
        // order) — must behave exactly as before this fix: reach
        // FileUploadService's own "No file was uploaded" guard, not our
        // new gate.
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);

        (new BuyerActionController())->uploadBuyerPo(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('No file was uploaded', $flash[0]['message']);
    }

    public function testUploadBuyerPoViaTokenIsRefusedOnceAdvanceHasCleared(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->markAdvanceCleared($orderId);
        $token = OrderRepository::generateBuyerActionToken($orderId);

        (new BuyerActionController())->uploadBuyerPo(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('already cleared', $flash[0]['message']);
        self::assertSame([], OrderBuyerPoDocumentRepository::forOrder($orderId), 'no document row must be attached once refused');
    }

    public function testUploadBuyerPoViaThePortalIsAlsoRefusedOnceAdvanceHasCleared(): void
    {
        // Proves the gate lives in the shared service, not duplicated (or
        // missed) on just one of the two channels.
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markAdvanceCleared($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        (new ClientPortalController())->uploadBuyerPo(['id' => $orderId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('already cleared', $flash[0]['message']);
    }

    // ---------------------------------------------------------------
    // #48 — reportAdvancePayment, via the no-login Buyer Action Link
    // ---------------------------------------------------------------

    public function testReportAdvancePaymentViaTokenRecordsItBeforeAdvanceClears(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);
        $_POST['transaction_ref'] = 'UTR123456789';
        $_POST['amount'] = '5000.00';
        $_POST['payment_date'] = '2026-10-10';
        $_POST['payer_bank_details'] = 'Sent from HSBC UK';

        (new BuyerActionController())->reportPayment(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);

        $reports = ClientPaymentReportRepository::forOrder($orderId);
        self::assertCount(1, $reports);
        self::assertSame('advance', $reports[0]['payment_type']);
        self::assertSame('UTR123456789', $reports[0]['transaction_ref']);
    }

    public function testReportAdvancePaymentViaTokenRequiresATransactionReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);
        // $_POST['transaction_ref'] deliberately left unset.

        (new BuyerActionController())->reportPayment(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame([], ClientPaymentReportRepository::forOrder($orderId));
    }

    public function testReportAdvancePaymentViaTokenIsRefusedOnceAdvanceHasCleared(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->markAdvanceCleared($orderId);
        $token = OrderRepository::generateBuyerActionToken($orderId);
        $_POST['transaction_ref'] = 'UTR999';

        (new BuyerActionController())->reportPayment(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('already been marked cleared', $flash[0]['message']);
        self::assertSame([], ClientPaymentReportRepository::forOrder($orderId));
    }

    public function testReportAdvancePaymentWithAnInvalidTokenIsRefused(): void
    {
        ob_start();
        (new BuyerActionController())->reportPayment(['token' => 'not-a-real-token']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Link No Longer Valid', $html);
    }

    // ---------------------------------------------------------------
    // View rendering — buyer_action/show.php
    // ---------------------------------------------------------------

    public function testShowRendersTheAdvancePaymentSectionAndTheUploadFormBeforeAdvanceClears(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);

        ob_start();
        (new BuyerActionController())->show(['token' => $token]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Advance Payment', $html);
        self::assertStringContainsString('Submit Payment Details', $html);
        self::assertStringContainsString('name="document"', $html);
    }

    public function testShowOmitsTheAdvancePaymentSectionAndTheUploadFormOnceAdvanceHasCleared(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->markAdvanceCleared($orderId);
        $token = OrderRepository::generateBuyerActionToken($orderId);

        ob_start();
        (new BuyerActionController())->show(['token' => $token]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('Advance Payment', $html);
        self::assertStringNotContainsString('Submit Payment Details', $html);
        self::assertStringNotContainsString('name="document"', $html);
        self::assertStringContainsString('already cleared', $html);
    }

    // ---------------------------------------------------------------
    // External QA report L-30/M-07: neither buyer self-service payment
    // report ever told staff at all — the only way to notice was to
    // happen to reopen the order and see the row in clientPaymentReports.
    // ---------------------------------------------------------------

    private function latestLogFor(string $actionType): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM audit_log WHERE action_type = :action_type ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['action_type' => $actionType]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function testReportAdvancePaymentViaTokenIsAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);
        $_POST['transaction_ref'] = 'UTR123456789';

        (new BuyerActionController())->reportPayment(['token' => $token]);

        $log = $this->latestLogFor('CLIENT_PAYMENT_REPORTED');
        self::assertNotNull($log, 'a client payment self-report via the Buyer Action Link must be audit-logged (staff are notified directly — see AuditLogSendsNoEmailTest)');
        self::assertSame($orderId, (int) $log['entity_id']);
        self::assertSame('advance', $log['new_value']);
        self::assertStringContainsString('UTR123456789', (string) $log['reason']);
    }

    public function testReportPaymentViaThePortalIsAuditLogged(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['payment_type' => 'balance', 'transaction_ref' => 'UTR999888777'];

        (new ClientPortalController())->reportPayment(['id' => $orderId]);

        $log = $this->latestLogFor('CLIENT_PAYMENT_REPORTED');
        self::assertNotNull($log, 'a client payment self-report via the logged-in portal must be audit-logged (and so notified)');
        self::assertSame($orderId, (int) $log['entity_id']);
        self::assertSame('balance', $log['new_value']);
        self::assertStringContainsString('UTR999888777', (string) $log['reason']);
    }
}
