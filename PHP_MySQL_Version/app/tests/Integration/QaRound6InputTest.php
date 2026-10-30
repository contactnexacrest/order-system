<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Controllers\HolidayController;
use App\Controllers\HsCodeController;
use App\Controllers\IncentiveController;
use App\Controllers\OrderController;
use App\Helpers\FieldValidator;
use App\Helpers\Flash;
use App\Helpers\OrderLineValidator;
use App\Helpers\Router;
use App\Services\OrderAccessService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 6, Batch 2 (owner approved the plan 2026-10-30): bad ids and
 * records that do not exist (R6-N04/N05/N07), incentive payouts (R6-N10,
 * R6-N29), product line limits (R6-N14/N15), the buyer's payment report
 * (R6-N19/N20/N21), the customs rate (R6-N23) and inverted ranges
 * (R6-N28). The Node suite has the same test (qaRound6Input.test.js).
 */
final class QaRound6InputTest extends DbTestCase
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
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
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

    /** R6-N05/N07: "-1", "0", "1.5", "abc" and a 20-digit id reach no route. */
    public function testAnIdThatIsNotAPositiveWholeNumberMatchesNoRoute(): void
    {
        $router = new Router();
        $router->post('/hs-codes/{id}/toggle', static function (array $p): void {
            echo 'toggled ' . $p['id'];
        });
        $router->get('/buyer-action/{token}', static function (array $p): void {
            echo 'token ' . $p['token'];
        });
        foreach (['-1', '0', '1.5', "1'", 'abc', '99999999999999999999'] as $bad) {
            ob_start();
            $router->dispatch('POST', "/hs-codes/{$bad}/toggle");
            self::assertSame('404 Not Found', (string) ob_get_clean(), $bad);
        }
        ob_start();
        $router->dispatch('POST', '/hs-codes/42/toggle');
        self::assertSame('toggled 42', (string) ob_get_clean());
        ob_start();
        $router->dispatch('GET', '/buyer-action/abc123');
        self::assertSame('token abc123', (string) ob_get_clean(), 'a token is not an id');
    }

    /** R6-N04: an order, document or order-linked record that does not exist answers "not found". */
    public function testAPathToAMissingOrderOrRecordIsNotFound(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame('Order not found.', OrderAccessService::missingRecordForPath('/orders/999999999/annexure/terms'));
        self::assertSame('Order not found.', OrderAccessService::missingRecordForPath('/orders/999999999/production-status'));
        self::assertSame('Not found.', OrderAccessService::missingRecordForPath('/disputes/999999999/replies'));
        self::assertSame('Not found.', OrderAccessService::missingRecordForPath('/documents/999999999/download'));
        self::assertNull(OrderAccessService::missingRecordForPath("/orders/{$orderId}/annexure/terms"));
        self::assertNull(OrderAccessService::missingRecordForPath('/hs-codes/1/toggle'), 'not an order path');
    }

    /**
     * R6-N04, through the real routes: the "not found" answer comes after
     * the route's own checks — an Admin gets it, a user without the
     * permission is refused as before and never learns whether the order
     * exists.
     */
    public function testTheRoutesAnswerNotFoundOnlyAfterTheirOwnChecks(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/__qa-round6-build__';
        ob_start();
        require dirname(__DIR__, 3) . '/public_html/index.php';
        ob_end_clean();
        restore_exception_handler();
        $dispatch = function (int $userId, string $path) use ($router): array {
            $_SESSION = ['_auth_user_id' => $userId];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = $path;
            http_response_code(200);
            \App\Services\PermissionService::resetCache();
            ob_start();
            $router->dispatch('GET', $path);
            return [(int) http_response_code(), (string) ob_get_clean()];
        };
        self::assertSame([404, 'Order not found.'], $dispatch($this->admin, '/orders/999999999/annexure'));
        [$code, $body] = $dispatch($this->createTestUser('Viewer / Auditor'), '/orders/999999999/annexure');
        self::assertSame(403, $code, 'Viewer lacks manage_orders: refused by the permission check, as before');
        self::assertStringNotContainsString('not found', $body);
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame(200, $dispatch($this->admin, "/orders/{$orderId}/annexure")[0]);
    }

    /** R6-N04: actions on records that do not exist change nothing and write no audit row. */
    public function testActionsOnMissingRecordsReportNotFoundAndLogNothing(): void
    {
        (new HolidayController())->delete(['id' => '999999999']);
        self::assertSame('error', $this->flash()['type']);
        self::assertSame(0, $this->auditCount('HOLIDAY_REMOVED', 999999999));

        (new HsCodeController())->toggleActive(['id' => '999999999']);
        self::assertStringContainsString('was not found', (string) $this->flash()['message']);
        self::assertSame(0, $this->auditCount('HS_CODE_TOGGLED', 999999999));

        $orderId = $this->createTestOrder($this->createTestClient());
        (new OrderController())->deleteAdvanceInrActual(['id' => (string) $orderId]);
        self::assertStringContainsString('nothing was removed', (string) $this->flash()['message']);
        self::assertSame(0, $this->auditCount('CA_INR_ACTUAL_DELETED', $orderId));

        ob_start();
        (new IncentiveController())->associates(['id' => '999999999']);
        self::assertSame('Associate not found.', (string) ob_get_clean());
        self::assertSame(404, http_response_code());
    }

    private function payout(float $amount, string $month): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare(
            "INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status) VALUES (:o, 'staff', :u, 'sales', :a, :m, 'due')"
        )->execute(['o' => $orderId, 'u' => $this->admin, 'a' => $amount, 'm' => $month]);
        return (int) Database::connection()->lastInsertId();
    }

    /** R6-N10: a payout is never marked paid above its amount or before its month. */
    public function testAPayoutCannotBePaidAboveItsAmountOrBeforeItsMonth(): void
    {
        $month = date('Y-m');
        $id = $this->payout(5000, $month);
        $status = static fn (): string => (string) Database::connection()->query("SELECT status FROM incentive_payouts WHERE id = {$id}")->fetchColumn();

        $_POST = ['month' => $month, 'paid_on' => date('Y-m-d'), 'paid_inr' => '5000000'];
        (new IncentiveController())->markPaid(['payoutId' => (string) $id]);
        self::assertStringContainsString('cannot be more than the payout', (string) $this->flash()['message']);
        self::assertSame('due', $status());

        $_POST = ['month' => $month, 'paid_on' => '1900-01-01', 'paid_inr' => '5000'];
        (new IncentiveController())->markPaid(['payoutId' => (string) $id]);
        self::assertStringContainsString('cannot be before', (string) $this->flash()['message']);
        self::assertSame('due', $status());

        $_POST = ['month' => $month, 'paid_on' => date('Y-m-d'), 'paid_inr' => '5000'];
        (new IncentiveController())->markPaid(['payoutId' => (string) $id]);
        self::assertSame('success', $this->flash()['type']);
        self::assertSame('paid', $status());
    }

    /** R6-N29: a month 13-99 shows the latest month instead of an error. */
    public function testAnImpossibleMonthOnThePayoutsPageIsIgnored(): void
    {
        $_GET = ['month' => '2026-13'];
        ob_start();
        (new IncentiveController())->payouts([]);
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('2026-13', $html);
        self::assertTrue(\App\Services\IncentiveService::isMonth('2026-12'));
        self::assertFalse(\App\Services\IncentiveService::isMonth('2026-00'));
    }

    /** R6-N14/N15: quantity, unit price and line value within what can be stored. */
    public function testProductLinesStayWithinWhatCanBeStored(): void
    {
        self::assertStringContainsString('at most 2 decimals', (string) OrderLineValidator::checkLine('10', false, '0.0001'));
        self::assertStringContainsString('can be at most', (string) OrderLineValidator::checkLine('10', false, '1000000000000000'));
        self::assertStringContainsString('line value', (string) OrderLineValidator::checkLine('1000000', false, '9999999'));
        self::assertStringContainsString('at most 3 decimals', (string) OrderLineValidator::checkLine('1.0001', false, '5'));
        self::assertNull(OrderLineValidator::checkLine('100', false, '45.50'));
        self::assertNull(OrderLineValidator::checkLine('', true, '45.50'), 'a quantity to be confirmed');

        $orderId = $this->createTestOrder($this->createTestClient());
        $hs = (string) Database::connection()->query('SELECT code FROM hs_codes WHERE is_active = 1 LIMIT 1')->fetchColumn();
        $lines = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM order_products WHERE order_id = {$orderId}")->fetchColumn();
        $before = $lines();
        $_POST = ['description' => 'PHPUnit granite', 'hs_code' => $hs, 'quantity' => '10', 'unit_price' => '0.0001'];
        (new OrderController())->addProduct(['id' => (string) $orderId]);
        self::assertStringContainsString('at most 2 decimals', (string) $this->flash()['message']);
        self::assertSame($before, $lines(), 'no zero-value line was added');
    }

    /** R6-N19/N20/N21: the buyer's payment report needs a real amount and a date that is not in the future. */
    public function testThePaymentTheBuyerReportsIsChecked(): void
    {
        self::assertSame('The amount paid must be at least 0.01.', FieldValidator::reportedPayment('-500', ''));
        self::assertStringContainsString('too large', (string) FieldValidator::reportedPayment('100000000000000000000', ''));
        self::assertSame('Enter the date you paid (today or earlier).', FieldValidator::reportedPayment('100', '2099-01-01'));
        self::assertSame('Enter the date you paid (today or earlier).', FieldValidator::reportedPayment('100', '2026-02-31'));
        self::assertNull(FieldValidator::reportedPayment('12500.00', date('Y-m-d')));
        self::assertNull(FieldValidator::reportedPayment('', ''), 'both stay optional');

        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION = ['_client_portal_client_id' => $clientId];
        $reports = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM client_payment_reports WHERE order_id = {$orderId}")->fetchColumn();
        foreach (['-500' => date('Y-m-d'), '100' => '2099-01-01', '100000000000000000000' => ''] as $amount => $date) {
            $_POST = ['payment_type' => 'advance', 'transaction_ref' => 'UTR-PHPUNIT', 'amount' => (string) $amount, 'payment_date' => $date];
            (new ClientPortalController())->reportPayment(['id' => (string) $orderId]);
            self::assertSame('error', $this->flash()['type'], "{$amount} / {$date}");
        }
        self::assertSame(0, $reports(), 'nothing was stored');
    }

    /** R6-N23: a customs rate that would be stored as 0.0000 is refused. */
    public function testATinyCustomsRateIsRefused(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['shipping_bill_no' => 'SB-PHPUNIT', 'shipping_bill_date' => date('Y-m-d'), 'customs_exchange_rate' => '0.00001'];
        (new OrderController())->recordShippingBill(['id' => (string) $orderId]);
        self::assertSame('error', $this->flash()['type']);
        self::assertNotNull(FieldValidator::exchangeRate(0.00001));
    }

    /** R6-N28: a range's minimum above its maximum, or net weight above gross weight, is refused. */
    public function testInvertedRangesAreRefused(): void
    {
        self::assertStringContainsString('lead time minimum', (string) FieldValidator::orderNumbers(['lead_time_min_days' => '30', 'lead_time_max_days' => '10']));
        self::assertStringContainsString('sailing time minimum', (string) FieldValidator::orderNumbers(['sailing_lead_time_min_days' => '40', 'sailing_lead_time_max_days' => '5']));
        self::assertStringContainsString('net weight', (string) FieldValidator::orderNumbers(['estimated_net_weight_kg' => '5000', 'estimated_gross_weight_kg' => '100']));
        self::assertNull(FieldValidator::orderNumbers(['lead_time_min_days' => '10', 'lead_time_max_days' => '30', 'estimated_net_weight_kg' => '90', 'estimated_gross_weight_kg' => '100']));

        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['lead_time_min_days' => '30', 'lead_time_max_days' => '10'];
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        self::assertSame('error', $this->flash()['type']);
        self::assertNotSame(30, (int) Database::connection()->query("SELECT lead_time_min_days FROM orders WHERE id = {$orderId}")->fetchColumn());
    }
}
