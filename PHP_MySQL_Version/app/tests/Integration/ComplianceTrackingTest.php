<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ComplianceTrackingController;
use App\Repositories\CompanySettingsRepository;
use App\Services\ComplianceTrackingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2 Nov 2026, gap-list Q-6 "go with your recommendation"):
 * (a) a missing eBRC / FIRC never blocks settling — it is listed (13h warning,
 *     monthly report + reminder); (b) ECGC buyer limit, exposure warning and the
 *     monthly declaration; (c) incentive claims get sanctioned amount, deadline
 *     and status, with reminders 30 / 7 days before. Node twin: complianceTracking.test.js.
 */
final class ComplianceTrackingTest extends DbTestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function exec(string $sql, array $p = []): void
    {
        Database::connection()->prepare($sql)->execute($p);
    }

    private function setting(string $key, string $value): void
    {
        $this->exec('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', ['v' => $value, 'k' => $key]);
        if (method_exists(CompanySettingsRepository::class, 'clearCache')) {
            CompanySettingsRepository::clearCache();
        }
    }

    private function orderWithFob(int $clientId, float $fob, string $ccy = 'USD'): int
    {
        $orderId = $this->createTestOrder($clientId);
        $this->exec("UPDATE orders SET currency_code = :c, status = 'active' WHERE id = :id", ['c' => $ccy, 'id' => $orderId]);
        \App\Repositories\OrderProductRepository::add($orderId, 1, 'ECGC test line', null, null, '1', false, 'PCS', number_format($fob, 2, '.', ''));
        return $orderId;
    }

    private function receiveAdvance(int $orderId, float $amount, ?string $firc = null, string $on = '2026-01-15 10:00:00'): void
    {
        $this->exec('INSERT INTO order_payment_status (order_id) SELECT :o FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM order_payment_status WHERE order_id = :o2)', ['o' => $orderId, 'o2' => $orderId]);
        $this->exec('UPDATE order_payment_status SET advance_amount = :a, advance_remittance_received_at = :d, advance_firc_reference = :f WHERE order_id = :o', ['a' => $amount, 'd' => $on, 'f' => $firc, 'o' => $orderId]);
    }

    public function testMissingFircIsListedNotBlocking(): void
    {
        $orderId = $this->orderWithFob($this->createTestClient(), 10000);
        $this->receiveAdvance($orderId, 3000.0);
        $legs = ComplianceTrackingService::missingFircLegs($orderId);
        self::assertCount(1, $legs);
        self::assertSame('Advance', $legs[0]['label']);
        self::assertSame(3000.0, $legs[0]['amount']);
        self::assertContains($orderId, array_column(ComplianceTrackingService::missingFircReport(), 'order_id'));

        $this->exec('UPDATE order_payment_status SET advance_firc_reference = :f WHERE order_id = :o', ['f' => 'FIRC/HDFC/1', 'o' => $orderId]);
        self::assertSame([], ComplianceTrackingService::missingFircLegs($orderId));

        // The settle gate itself never looks at FIRC (warning only).
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DocumentGenerationService.php') . file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/OrderController.php');
        self::assertDoesNotMatchRegularExpression('/function markFullySettled[\s\S]{0,4000}missingFirc/', $src);
    }

    public function testReportHidesAmountsWithoutBuyerPayments(): void
    {
        $orderId = $this->orderWithFob($this->createTestClient(), 10000);
        $this->receiveAdvance($orderId, 3456.78);
        $render = function (int $userId): string {
            $_SESSION = ['_auth_user_id' => $userId];
            \App\Services\PermissionService::resetCache();
            ob_start();
            (new ComplianceTrackingController())->missingEbrcReport([]);
            return (string) ob_get_clean();
        };
        $compliance = $render($this->createTestUser('Export Compliance Executive'));
        self::assertStringContainsString('Missing eBRC / FIRC', $compliance);
        self::assertStringNotContainsString('3,456.78', $compliance, 'no buyer-payment amounts without view_buyer_payments');
        $accounts = $render($this->createTestUser('Accounts Executive'));
        self::assertStringContainsString('3,456.78', $accounts);
    }

    public function testEcgcExposureAgainstTheLimit(): void
    {
        $this->setting('ecgc_policy_no', 'ECGC/SCR/2026/77');
        $clientId = $this->createTestClient();
        $this->orderWithFob($clientId, 15000);
        $p = ComplianceTrackingService::ecgcPosition($clientId);
        self::assertNull($p['limit']);
        self::assertSame('No ECGC credit limit is recorded for this buyer — shipments to this buyer are not covered until ECGC approves a limit.', $p['warning']);

        $this->exec("UPDATE clients SET ecgc_limit_amount = 10000, ecgc_limit_currency = 'USD', ecgc_limit_valid_to = '2099-12-31' WHERE id = :id", ['id' => $clientId]);
        $p = ComplianceTrackingService::ecgcPosition($clientId);
        self::assertTrue($p['over_limit']);
        self::assertSame(5000.0, $p['over_by']);
        self::assertSame('Open orders for this buyer (USD 15,000.00) are above the ECGC-approved credit limit (USD 10,000.00) by USD 5,000.00 — the amount above the limit is not covered.', $p['warning']);

        // Money received no longer counts as exposure.
        $orderId = (int) Database::connection()->query("SELECT id FROM orders WHERE client_id = {$clientId}")->fetchColumn();
        $this->receiveAdvance($orderId, 6000.0, 'FIRC/1');
        $p = ComplianceTrackingService::ecgcPosition($clientId);
        self::assertFalse($p['over_limit']);
        self::assertNull($p['warning']);

        $this->exec("UPDATE clients SET ecgc_limit_valid_to = '2020-01-01' WHERE id = :id", ['id' => $clientId]);
        self::assertSame('The ECGC credit limit for this buyer expired on 2020-01-01 — ask ECGC to renew it before shipping.', ComplianceTrackingService::ecgcPosition($clientId)['warning']);
    }

    public function testDeclarationDueByTheTenthOfTheNextMonth(): void
    {
        $this->setting('ecgc_declaration_day', '10');
        self::assertSame('2026-11-10', ComplianceTrackingService::declarationDue('2026-10-15'));
        self::assertSame('2027-01-10', ComplianceTrackingService::declarationDue('2026-12-31'));
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame('not_due', ComplianceTrackingService::declarationStatus($orderId)['state']);
        $this->exec('INSERT INTO order_shipping (order_id, bl_date) VALUES (:o, :d) ON DUPLICATE KEY UPDATE bl_date = :d2', ['o' => $orderId, 'd' => '2026-10-15', 'd2' => '2026-10-15']);
        self::assertSame('due', ComplianceTrackingService::declarationStatus($orderId, '2026-11-05')['state']);
        $over = ComplianceTrackingService::declarationStatus($orderId, '2026-11-11');
        self::assertSame('overdue', $over['state']);
        self::assertSame('Not declared to ECGC — it was due by 2026-11-10. A shipment not declared on time is not covered.', $over['text']);
        $this->exec("UPDATE orders SET ecgc_declared_on = '2026-11-03', ecgc_declaration_ref = 'D-1' WHERE id = :id", ['id' => $orderId]);
        self::assertSame('Declared to ECGC on 2026-11-03 (D-1).', ComplianceTrackingService::declarationStatus($orderId, '2026-11-11')['text']);
    }

    public function testClaimDeadlineSanctionAndStatus(): void
    {
        $this->setting('benefit_deadline_days_rodtep', '365');
        $this->setting('benefit_deadline_days_duty_drawback', '0');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->exec('INSERT INTO order_shipping (order_id, shipping_bill_date, bl_date) VALUES (:o, :s, :b) ON DUPLICATE KEY UPDATE shipping_bill_date = :s2', ['o' => $orderId, 's' => '2026-03-01', 'b' => '2026-03-05', 's2' => '2026-03-01']);
        self::assertSame('2027-03-01', ComplianceTrackingService::defaultDeadline($orderId, 'RODTEP'));
        self::assertNull(ComplianceTrackingService::defaultDeadline($orderId, 'Duty Drawback'), 'drawback has no deadline');

        $user = $this->createTestUser('Admin');
        $this->exec("INSERT INTO ca_export_benefits (order_id, scheme_name, claimed_amount, claimed_at, currency_code, recorded_by) VALUES (:o, 'RODTEP', 1000, '2026-04-01', 'INR', :u)", ['o' => $orderId, 'u' => $user]);
        $id = (int) Database::connection()->lastInsertId();
        $claim = Database::connection()->query("SELECT * FROM ca_export_benefits WHERE id = {$id}")->fetch();
        $v = ComplianceTrackingService::claimView($claim);
        self::assertSame('filed', $v['effective_status']);
        self::assertSame('2027-03-01', $v['effective_deadline']);
        self::assertTrue($v['deadline_is_default']);

        self::assertSame('Enter the amount sanctioned.', ComplianceTrackingService::saveClaimTracking($claim, ['claim_status' => 'sanctioned']));
        self::assertSame('Say why the claim was rejected (at least 10 characters).', ComplianceTrackingService::saveClaimTracking($claim, ['claim_status' => 'rejected', 'rejected_reason' => 'no']));
        self::assertNull(ComplianceTrackingService::saveClaimTracking($claim, ['claim_status' => 'sanctioned', 'sanctioned_amount' => '950', 'sanctioned_at' => '2026-05-01', 'claim_deadline' => '2026-12-31']));
        $saved = Database::connection()->query("SELECT * FROM ca_export_benefits WHERE id = {$id}")->fetch();
        self::assertSame('sanctioned', $saved['claim_status']);
        self::assertSame('950.00', $saved['sanctioned_amount']);
        $v = ComplianceTrackingService::claimView($saved);
        self::assertSame('2026-12-31', $v['effective_deadline']);
        self::assertFalse($v['deadline_is_default']);
        $this->exec("UPDATE ca_export_benefits SET received_amount = 950, received_at = '2026-06-01' WHERE id = {$id}");
        self::assertSame('received', ComplianceTrackingService::claimView(Database::connection()->query("SELECT * FROM ca_export_benefits WHERE id = {$id}")->fetch())['effective_status']);
    }

    public function testRemindersGoOutOnce(): void
    {
        $this->setting('ecgc_policy_no', 'ECGC/SCR/2026/77');
        $this->setting('ecgc_declaration_day', '10');
        $finance = $this->createTestUser('Accounts Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->orderWithFob($clientId, 1000);
        $this->receiveAdvance($orderId, 300.0);
        $this->exec('INSERT INTO order_shipping (order_id, bl_date, shipping_bill_date) VALUES (:o, :d, :d2) ON DUPLICATE KEY UPDATE bl_date = :d3', ['o' => $orderId, 'd' => '2026-10-15', 'd2' => '2026-10-14', 'd3' => '2026-10-15']);
        $this->exec("INSERT INTO ca_export_benefits (order_id, scheme_name, claimed_amount, claimed_at, currency_code, recorded_by, claim_deadline) VALUES (:o, 'RODTEP', 500, '2026-10-20', 'INR', :u, '2026-11-25')", ['o' => $orderId, 'u' => $finance]);

        $first = ComplianceTrackingService::runReminders('2026-11-05');
        self::assertGreaterThan(0, $first);
        $types = Database::connection()->query("SELECT DISTINCT type FROM notifications WHERE user_id = {$finance}")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('ebrc_missing_monthly', $types);
        self::assertContains('benefit_claim_deadline', $types);
        self::assertContains('ecgc_declaration_due', $types, 'no Export Compliance Manager exists, so Finance gets the declaration reminder');
        self::assertSame(0, ComplianceTrackingService::runReminders('2026-11-05'), 'the same day again sends nothing');
        $msg = (string) Database::connection()->query("SELECT message FROM notifications WHERE user_id = {$finance} AND type = 'benefit_claim_deadline' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('the RODTEP claim must be completed by 2026-11-25 (20 day(s) left).', $msg);
        // 7 days before is a new threshold.
        self::assertGreaterThan(0, ComplianceTrackingService::runReminders('2026-11-19'));
    }
}
