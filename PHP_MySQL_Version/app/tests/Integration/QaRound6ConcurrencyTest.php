<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Config\Env;
use App\Controllers\ClientController;
use App\Controllers\DocumentController;
use App\Controllers\IncentiveController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Services\PaymentTermsApprovalService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 6, Batch 3 (owner approved the plan 2026-10-30): the same
 * client email saved twice at once (R6-N16), repeated clicks recorded
 * several times (R6-N17), several document revisions from one double click
 * (R6-N18) and one person's save silently undoing another's (R6-N27). The
 * Node suite has the same test (qaRound6Concurrency.test.js).
 */
final class QaRound6ConcurrencyTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function flash(): array
    {
        return Flash::pull()[0] ?? ['type' => null, 'message' => null];
    }

    private function auditCount(string $action, int $entityId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM audit_log WHERE action_type = :a AND entity_id = :e');
        $stmt->execute(['a' => $action, 'e' => $entityId]);
        return (int) $stmt->fetchColumn();
    }

    /** R6-N16: the database refuses a second client with the same email, and the user sees why. */
    public function testTheSameClientEmailCannotBeSavedTwice(): void
    {
        $email = 'phpunit-r6-' . bin2hex(random_bytes(4)) . '@example.test';
        $first = $this->createTestClient($email);
        $second = $this->createTestClient();
        $router = new Router();
        $router->post('/clients/{id}/update', static function () use ($email, $second): void {
            // What the slower of two simultaneous saves does once both passed the "already used" check.
            Database::connection()->prepare('UPDATE clients SET email = :e WHERE id = :id')->execute(['e' => strtoupper($email), 'id' => $second]);
        });
        $_SERVER['HTTP_REFERER'] = '/clients/' . $second . '/edit';
        ob_start();
        $router->dispatch('POST', "/clients/{$second}/update");
        ob_end_clean();
        self::assertSame(Router::CLIENT_EMAIL_TAKEN, $this->flash()['message'], 'refused by the unique index, case-insensitively, with a plain message');
        self::assertNull(Database::connection()->query("SELECT email FROM clients WHERE id = {$second}")->fetchColumn());
        self::assertSame($email, Database::connection()->query("SELECT email FROM clients WHERE id = {$first}")->fetchColumn());
    }

    /** R6-N16: a blank email is "no email", so any number of clients can have none. */
    public function testClientsWithoutAnEmailNeverClash(): void
    {
        $ids = [];
        foreach ([1, 2] as $n) {
            $ids[] = \App\Repositories\ClientRepository::create(['company_legal_name' => "PHPUnit No Email {$n}", 'billing_address' => '1 Test Street', 'email' => '  '], $this->admin, 'NC/SC/TEST/' . bin2hex(random_bytes(4)));
        }
        foreach ($ids as $id) {
            self::assertNull(Database::connection()->query("SELECT email FROM clients WHERE id = {$id}")->fetchColumn());
        }
        \App\Repositories\ClientRepository::update($ids[0], ['company_legal_name' => 'PHPUnit No Email 1', 'billing_address' => '1 Test Street', 'email' => '']);
        self::assertNull(Database::connection()->query("SELECT email FROM clients WHERE id = {$ids[0]}")->fetchColumn());
    }

    /** R6-N17: a second click with the same details records and logs nothing more. */
    public function testARepeatedPaymentIsRecordedAndLoggedOnce(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Every real order has its payment-status row from creation.
        Database::connection()->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $_POST = ['advance_amount' => '1020', 'advance_exchange_rate' => '83.25', 'advance_bank_reference' => 'UTR-TEST-0001', 'advance_received_at' => date('Y-m-d')];
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        self::assertSame('success', $this->flash()['type']);
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        self::assertStringContainsString('already recorded with these details', (string) $this->flash()['message']);
        self::assertSame(1, $this->auditCount('ADVANCE_PAYMENT_RECORDED', $orderId));

        $_POST['advance_amount'] = '1030';
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        self::assertSame('success', $this->flash()['type'], 'a corrected amount is still recorded');
        self::assertSame(2, $this->auditCount('ADVANCE_PAYMENT_RECORDED', $orderId));
    }

    /** R6-N17: a payout paid twice, or payment terms approved twice, is logged once. */
    public function testRepeatedPayoutAndApprovalAreLoggedOnce(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $month = date('Y-m');
        Database::connection()->prepare(
            "INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status) VALUES (:o, 'staff', :u, 'sales', 5000, :m, 'due')"
        )->execute(['o' => $orderId, 'u' => $this->admin, 'm' => $month]);
        $payoutId = (int) Database::connection()->lastInsertId();
        foreach ([1, 2] as $click) {
            $_POST = ['month' => $month, 'paid_on' => date('Y-m-d'), 'paid_inr' => '5000', 'paid_via' => 'salary', 'paid_reference' => 'Payroll TEST'];
            (new IncentiveController())->markPaid(['payoutId' => (string) $payoutId]);
        }
        self::assertSame(1, $this->auditCount('INCENTIVE_PAID', $payoutId));

        self::assertTrue(PaymentTermsApprovalService::approve($orderId, $this->admin));
        self::assertFalse(PaymentTermsApprovalService::approve($orderId, $this->admin), 'already approved');
        self::assertSame(1, $this->auditCount('PAYMENT_TERMS_APPROVED', $orderId));
    }

    /** R6-N18: while one generation of a document runs, a second one of the same order and type is refused. */
    public function testOneGenerationOfADocumentAtATime(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $other = new \PDO(
            'mysql:host=' . Env::get('DB_HOST', '127.0.0.1') . ';port=' . Env::get('DB_PORT', '3306') . ';dbname=' . Env::get('DB_DATABASE'),
            Env::get('DB_USERNAME'),
            Env::get('DB_PASSWORD', '')
        );
        $other->query("SELECT GET_LOCK('nx_docgen_{$orderId}_QT', 0)");
        $count = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM documents WHERE order_id = {$orderId}")->fetchColumn();
        try {
            $before = $count();
            $_POST = ['document_type' => 'QT'];
            (new DocumentController())->generate(['id' => (string) $orderId]);
            self::assertSame('This document is being generated right now — wait a moment, then refresh the page.', $this->flash()['message']);
            self::assertSame($before, $count(), 'no revision made');
        } finally {
            $other->query("SELECT RELEASE_LOCK('nx_docgen_{$orderId}_QT')");
        }
        self::assertSame(1, (int) Database::connection()->query("SELECT IS_FREE_LOCK('nx_docgen_{$orderId}_QT')")->fetchColumn());
    }

    private function fingerprintFrom(string $html): string
    {
        self::assertMatchesRegularExpression('#name="edit_fingerprint" value="([0-9a-f]{40})"#', $html);
        preg_match('#name="edit_fingerprint" value="([0-9a-f]{40})"#', $html, $m);
        return $m[1];
    }

    /** R6-N27: a save on a form opened before someone else's save is refused, not silently undone. */
    public function testASaveOnAStaleOrderFormIsRefused(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = \App\Repositories\OrderRepository::find($orderId);
        ob_start();
        (new OrderController())->editDetails(['id' => (string) $orderId]);
        $fingerprint = $this->fingerprintFrom((string) ob_get_clean());
        $form = static fn (array $over): array => $over + [
            'incoterm_id' => (string) $order['incoterm_id'], 'currency_id' => (string) $order['currency_id'], 'edit_fingerprint' => $fingerprint,
        ];

        // A saves first.
        $_POST = $form(['port_of_discharge_text' => 'Rotterdam, Netherlands']);
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        self::assertSame('success', $this->flash()['type']);

        // B, on the page loaded before A's save, changes something else.
        $_POST = $form(['est_lead_time_text' => '45 days']);
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        $message = (string) $this->flash()['message'];
        self::assertStringContainsString('was changed by', $message);
        self::assertStringContainsString('Nothing was saved', $message);
        $saved = \App\Repositories\OrderRepository::find($orderId);
        self::assertSame('Rotterdam, Netherlands', $saved['port_of_discharge_text'], "A's change is kept");
        self::assertNotSame('45 days', $saved['est_lead_time_text']);

        // Reloaded, B saves normally.
        ob_start();
        (new OrderController())->editDetails(['id' => (string) $orderId]);
        $_POST = ['incoterm_id' => (string) $order['incoterm_id'], 'currency_id' => (string) $order['currency_id'], 'port_of_discharge_text' => 'Rotterdam, Netherlands', 'est_lead_time_text' => '45 days', 'edit_fingerprint' => $this->fingerprintFrom((string) ob_get_clean())];
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        self::assertSame('success', $this->flash()['type']);
    }

    /** R6-N27: the same on the client edit form. */
    public function testASaveOnAStaleClientFormIsRefused(): void
    {
        $clientId = $this->createTestClient();
        ob_start();
        (new ClientController())->editForm(['id' => (string) $clientId]);
        $fingerprint = $this->fingerprintFrom((string) ob_get_clean());
        $base = ['company_legal_name' => 'PHPUnit Buyer Ltd', 'billing_address' => '1 Test Street', 'edit_fingerprint' => $fingerprint];

        $_POST = $base + ['contact_person' => 'Person A'];
        (new ClientController())->update(['id' => (string) $clientId]);
        self::assertNotSame('error', $this->flash()['type']);
        $_POST = $base + ['contact_person' => 'Person B'];
        (new ClientController())->update(['id' => (string) $clientId]);
        self::assertStringContainsString('was changed by', (string) $this->flash()['message']);
        self::assertSame('Person A', Database::connection()->query("SELECT contact_person FROM clients WHERE id = {$clientId}")->fetchColumn());
    }
}
