<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Controllers\DropdownOptionController;
use App\Controllers\IncentiveController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderRepository;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 6, Batch 4 (owner approved the plan 2026-10-30): an unexpected
 * file on the client's message (R6-N03), "Mark all paid" with nothing
 * selected (R6-N11), duplicate reward thresholds (R6-N12), payment terms on
 * a cancelled order (R6-N13), a payment report before the PI or on a
 * cancelled order (R6-N24), invalid dropdown options refused alike on both
 * stacks (R6-N25), self-approval of payment terms by someone other than
 * Admin/MD/ED (D-117, Q-R6-1) and insurance only on CIF (D-049, Q-R5-2).
 * The Node suite has the same test (qaRound6SmallFixes.test.js).
 */
final class QaRound6SmallFixesTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        Flash::pullOld();
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        parent::tearDown();
    }

    private function flash(): array
    {
        return Flash::pull()[0] ?? ['type' => null, 'message' => null];
    }

    /** An order on a preset that needs MD/ED approval of its payment terms. */
    private function orderNeedingApproval(?int $createdBy = null): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $presetId = (int) $pdo->query('SELECT id FROM payment_presets WHERE requires_md_approval = 1 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $presetId, 'seed has a preset needing approval');
        $pdo->prepare('UPDATE orders SET payment_preset_id = :p, payment_terms_approved_at = NULL, created_by = :u WHERE id = :o')
            ->execute(['p' => $presetId, 'u' => $createdBy, 'o' => $orderId]);
        return $orderId;
    }

    private function approvedAt(int $orderId): ?string
    {
        $v = Database::connection()->query("SELECT payment_terms_approved_at FROM orders WHERE id = {$orderId}")->fetchColumn();
        return $v === false ? null : $v;
    }

    /** R6-N13: payment terms of a cancelled (or lost) order cannot be approved. */
    public function testPaymentTermsOfACancelledOrderCannotBeApproved(): void
    {
        $orderId = $this->orderNeedingApproval();
        Database::connection()->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$orderId}");
        (new OrderController())->approvePaymentTerms(['id' => (string) $orderId]);
        self::assertStringContainsString('cannot be approved', (string) $this->flash()['message']);
        self::assertNull($this->approvedAt($orderId));
    }

    /** D-117 (Q-R6-1): only Admin, MD and ED may approve the payment terms of an order they created. */
    public function testOnlyAdminMdOrEdMayApproveTheirOwnOrdersPaymentTerms(): void
    {
        $exporter = $this->createTestUser('Export Executive');
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = 'approve_payment_terms'")->fetchColumn();
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, 1)')->execute(['u' => $exporter, 'p' => $permissionId]);

        $own = $this->orderNeedingApproval($exporter);
        $_SESSION = ['_auth_user_id' => $exporter];
        PermissionService::resetCache();
        (new OrderController())->approvePaymentTerms(['id' => (string) $own]);
        self::assertStringContainsString('must be approved by someone else', (string) $this->flash()['message']);
        self::assertNull($this->approvedAt($own));

        $someoneElses = $this->orderNeedingApproval($this->admin);
        (new OrderController())->approvePaymentTerms(['id' => (string) $someoneElses]);
        self::assertSame('success', $this->flash()['type'], 'an order someone else created');

        $_SESSION = ['_auth_user_id' => $this->admin];
        PermissionService::resetCache();
        $adminsOwn = $this->orderNeedingApproval($this->admin);
        (new OrderController())->approvePaymentTerms(['id' => (string) $adminsOwn]);
        self::assertSame('success', $this->flash()['type'], 'Admin may approve their own (D-117)');
    }

    /** R6-N11: "Mark all paid" with nothing selected is a warning, not a green "0 payments recorded". */
    public function testMarkAllPaidWithNothingSelectedIsAWarning(): void
    {
        $_POST = ['month' => date('Y-m'), 'paid_on' => date('Y-m-d'), 'paid_via' => 'salary', 'paid_reference' => 'Payroll TEST'];
        (new IncentiveController())->markPaid([]);
        $flash = $this->flash();
        self::assertSame('warning', $flash['type']);
        self::assertStringContainsString('Nothing was recorded', (string) $flash['message']);
    }

    /** R6-N12: two reward table rows may not start at the same profit. */
    public function testTheRewardTableRefusesTwoRowsWithTheSameProfit(): void
    {
        $before = \App\Services\IncentiveService::settings()['table'];
        $_POST = [
            'min_profit_inr' => '100000', 'new_client_bonus_inr' => '0', 'cutoff_day' => '20',
            'table_from' => ['300000', '300000'], 'table_reward' => ['40000', '90000'],
            'split' => ['sales' => '40', 'export' => '20', 'marketing' => '15', 'logistics' => '15', 'accounts' => '10'],
        ];
        (new IncentiveController())->saveSettings([]);
        self::assertStringContainsString('same order profit', (string) $this->flash()['message']);
        self::assertSame($before, \App\Services\IncentiveService::settings()['table'], 'nothing saved');
    }

    /** R6-N24: no payment report on a cancelled order, nor before the Proforma Invoice. */
    public function testThePaymentReportWaitsForThePiAndClosesOnACancelledOrder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION = ['_client_portal_client_id' => $clientId];
        $reports = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM client_payment_reports WHERE order_id = {$orderId}")->fetchColumn();
        $report = function () use ($orderId): void {
            $_POST = ['payment_type' => 'advance', 'transaction_ref' => 'UTR-PHPUNIT', 'amount' => '100', 'payment_date' => date('Y-m-d')];
            (new ClientPortalController())->reportPayment(['id' => (string) $orderId]);
        };

        $report();
        self::assertStringContainsString('once we have sent you the Proforma Invoice', (string) $this->flash()['message']);
        $this->createTestDocument($orderId, 'PI', 'sent');
        $report();
        self::assertSame('success', $this->flash()['type']);
        self::assertSame(1, $reports());

        Database::connection()->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$orderId}");
        $report();
        self::assertStringContainsString('cancelled', (string) $this->flash()['message']);
        self::assertSame(1, $reports());
        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();
        self::assertStringNotContainsString('/report-payment"', $page, 'the form is not offered');
    }

    /** R6-N03: a file under another field name is refused, not dropped; the typed message is kept. */
    public function testAnUnexpectedFileOnTheClientsMessageIsRefused(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION = ['_client_portal_client_id' => $clientId];
        $_POST = ['body' => 'Please confirm the crate count.'];
        $_FILES = ['unexpected_field' => ['name' => 'x.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/none', 'error' => UPLOAD_ERR_OK, 'size' => 10]];
        (new ClientPortalController())->postComment(['id' => (string) $orderId]);
        self::assertStringContainsString('could not be accepted', (string) $this->flash()['message']);
        self::assertSame('Please confirm the crate count.', Flash::pullOld()['body'] ?? null, 'what was typed is kept');
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM order_comments WHERE order_id = {$orderId}")->fetchColumn());
    }

    /** R6-N25: a blank option name or a text sort order is refused (Node now does the same). */
    public function testInvalidDropdownOptionsAreRefused(): void
    {
        $row = Database::connection()->query("SELECT id, option_value FROM dropdown_options WHERE list_key = 'unit' ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $_POST = ['option_value' => [(int) $row['id'] => ''], 'sort_order' => [(int) $row['id'] => 'abc']];
        (new DropdownOptionController())->update(['listKey' => 'unit']);
        self::assertStringContainsString('nothing was saved', (string) $this->flash()['message']);
        self::assertSame($row['option_value'], Database::connection()->query("SELECT option_value FROM dropdown_options WHERE id = {$row['id']}")->fetchColumn());
    }

    /** D-049 (Q-R5-2): only a CIF order keeps an insurance amount; CFR and FOB orders store none. */
    public function testOnlyACifOrderKeepsAnInsuranceAmount(): void
    {
        $pdo = Database::connection();
        foreach (['CIF' => '120.00', 'CFR' => null, 'FOB' => null] as $incoterm => $expected) {
            $orderId = $this->createTestOrder($this->createTestClient(), $incoterm);
            $order = OrderRepository::find($orderId);
            $_POST = ['incoterm_id' => (string) $order['incoterm_id'], 'currency_id' => (string) $order['currency_id'], 'indicative_freight_amount' => '950', 'indicative_insurance_amount' => '120'];
            (new OrderController())->updateDetails(['id' => (string) $orderId]);
            self::assertSame('success', $this->flash()['type'], $incoterm);
            $stored = $pdo->query("SELECT indicative_insurance_amount FROM orders WHERE id = {$orderId}")->fetchColumn();
            self::assertSame($expected, $stored === null ? null : (string) $stored, $incoterm);
        }
    }
}
