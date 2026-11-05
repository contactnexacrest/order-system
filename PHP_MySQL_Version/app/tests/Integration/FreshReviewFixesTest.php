<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\IncentiveController;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderQualityDocumentRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PricingRepository;
use App\Services\AssociateCommission;
use App\Services\AssociateTds;
use App\Services\DivisionService;
use App\Services\IncentiveReportService;
use App\Services\IncentiveService;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PriceRequestService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * The fresh-eyes review of 5 Nov 2026 (scratchpad fresh_review.md), item by
 * item, with the same figures on both stacks. Node twin:
 * tests/integration/freshReviewFixes.test.js.
 *
 *   H1  a "USD x per unit" commission or incentive on a non-USD order is converted at the USD rate
 *   M1  an order's incentive is locked once; unlock never deletes a paid payout
 *   M2  after the client moves to another associate, the old associate's fixed level no longer applies
 *   M3  money rounding: PHP round() half away from zero (Node now matches — same figures)
 *   M4  a change of a line's options or unit clears its fixed figures and voids the MD's approval
 *   M5  the Zoho payment description names the GST paid on top
 *   L1  the incentive register, ready-to-lock and the price-request report follow the division scope
 *   L2  GST on a paid payout is worked out on the gross billed (paid + TDS)
 *   L3  a deleted line asks for no quality document
 *   (L4, the atomic daily price-request limit, is in D255PriceRequestsTest.)
 */
final class FreshReviewFixesTest extends DbTestCase
{
    private int $admin;
    private string $fxBefore;
    /** @var list<int> */
    private array $levels = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        PermissionService::resetCache();
        foreach (['associate_commission_due_days' => '30', 'associate_claim_hold' => '1', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '5',
            'associate_counting_threshold_inr' => '500000', 'associate_gst_pct' => '18.00', 'associate_protection_months' => '6'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
        $this->fxBefore = (string) PricingRepository::settings()['pricing_fx_rate'];
        PricingRepository::setSetting('pricing_fx_rate', '83.5000', $this->admin);
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        PricingRepository::setSetting('pricing_fx_rate', $this->fxBefore, $this->admin);
        $pdo->exec("UPDATE pricing_settings SET setting_value = 'pct' WHERE setting_key = 'incentive_mode'");
        $pdo->exec("UPDATE pricing_settings SET setting_value = '15.0000' WHERE setting_key = 'incentive_value'");
        foreach ($this->levels as $id) {
            $pdo->prepare('UPDATE associates SET commission_level_id = NULL WHERE commission_level_id = :l')->execute(['l' => $id]);
            $pdo->prepare('UPDATE orders SET associate_level_id = NULL WHERE associate_level_id = :l')->execute(['l' => $id]);
            $pdo->prepare('DELETE FROM commission_levels WHERE id = :l')->execute(['l' => $id]);
        }
        $pdo->exec('UPDATE orders SET division_id = 1 WHERE division_id <> 1');
        $pdo->exec('UPDATE associates SET division_id = 1 WHERE division_id <> 1');
        DivisionService::resetCache();
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function newLevel(string $kind, string $value): int
    {
        $id = PricingRepository::createLevel('FR ' . $kind . ' ' . bin2hex(random_bytes(3)), $kind, $value, $this->admin);
        $this->levels[] = $id;
        return $id;
    }

    private function level(string $name): int
    {
        return (int) Database::connection()->query('SELECT id FROM commission_levels WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    /** @return array{0:int,1:int} associate id, client id — the associate on a fixed level */
    private function associate(int $levelId, ?string $gstin = null): array
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id, gstin) VALUES (:n, 'active', 'fixed', :l, :g)")
            ->execute(['n' => 'FR Associate ' . bin2hex(random_bytes(3)), 'l' => $levelId, 'g' => $gstin]);
        $a = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $a, 'c' => $client]);
        return [$a, $client];
    }

    /**
     * An order in $currency with goods lines [fob, qty], paid in full at $rate rupees per unit of
     * the order currency (advance and balance receipts with the INR credited), balance cleared 25 Oct.
     *
     * @param list<array{0: float, 1: float}> $lines
     */
    private function order(int $client, string $currency, array $lines, float $rate): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($client, 'FOB', 'Standard — New Buyer', $currency);
        $total = 0.0;
        foreach ($lines as $i => $l) {
            $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type)
                           VALUES (:o, :n, 'Granite', :q, 'SQM', :p, :f, 'goods')")
                ->execute(['o' => $orderId, 'n' => $i + 1, 'q' => $l[1], 'p' => round($l[0] / $l[1], 2), 'f' => $l[0]]);
            $total += $l[0];
        }
        $advance = round($total * 0.4, 2);
        $balance = round($total - $advance, 2);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = :a, advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = :ai, advance_exchange_rate = :r,
            balance_amount = :b, balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = :bi, balance_exchange_rate = :r2 WHERE order_id = :o")
            ->execute(['a' => $advance, 'ai' => round($advance * $rate, 2), 'r' => $rate, 'b' => $balance, 'bi' => round($balance * $rate, 2), 'r2' => $rate, 'o' => $orderId]);
        foreach ([['advance', $advance], ['balance', $balance]] as [$leg, $amount]) {
            $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', :r, :i, 'UTR-FR')")
                ->execute(['o' => $orderId, 'l' => $leg, 'a' => $amount, 'r' => $rate, 'i' => round($amount * $rate, 2)]);
        }
        return $orderId;
    }

    private function associatePayout(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL");
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public function testH1AFixedUsdLevelOnAEuroOrderIsConvertedAtTheUsdRate(): void
    {
        [, $client] = $this->associate($this->newLevel('fixed_usd', '1.00'));
        // EUR order, 2,000 SQM, the buyer's euros realised ₹90.00; the Admin pricing exchange rate (USD) is ₹83.50.
        $eur = $this->order($client, 'EUR', [[30000.0, 2000.0]], 90.0);
        $c = AssociateCommission::forOrder($eur);
        self::assertSame([167000.0, 1855.56, 83.5, 90.0], [$c['amount_inr'], $c['amount_fc'], $c['usd_rate'], $c['rate_inr']],
            'USD 1.00 × 2,000 = USD 2,000 = ₹1,67,000.00 (not 2,000 × ₹90 = ₹1,80,000), shown as EUR 1,855.56');
        self::assertSame([1855.56], array_column($c['lines'], 'commission'));

        // A USD order is unchanged: USD 2,000 at the rate its receipts realised (₹83.00).
        [, $client2] = $this->associate($this->newLevel('fixed_usd', '1.00'));
        $usd = $this->order($client2, 'USD', [[30000.0, 2000.0]], 83.0);
        $c = AssociateCommission::forOrder($usd);
        self::assertSame([166000.0, 2000.0, null], [$c['amount_inr'], $c['amount_fc'], $c['usd_rate']]);
    }

    public function testH1AUsdIncentiveRuleOnAEuroOrderAndTheLadderEstimate(): void
    {
        [, $client] = $this->associate($this->level('Level 2'));
        $eur = $this->order($client, 'EUR', [[30000.0, 2000.0]], 90.0);
        PricingRepository::setSetting('incentive_mode', 'usd', $this->admin);
        PricingRepository::setSetting('incentive_value', '0.5000', $this->admin);
        $g = IncentiveService::globalAmount($eur, OrderRepository::find($eur), 1000000.0);
        self::assertSame(83500.0, $g['amount'], 'USD 0.50 × 2,000 × ₹83.50 (not × ₹90.00)');

        // The ladder's counting threshold at PI: a EUR order at the rate expected on its receipts, a USD order at the pricing rate.
        self::assertSame(522000.0, AssociateCommission::estimateInr(['id' => $eur, 'currency_code' => 'EUR'] + OrderRepository::find($eur), 5800.0));
        self::assertSame(484300.0, AssociateCommission::estimateInr(['currency_code' => 'USD'], 5800.0));
        self::assertSame(5800.0, AssociateCommission::estimateInr(['currency_code' => 'INR'], 5800.0));
    }

    public function testM1AnOrderIsLockedOnceAndUnlockNeverDeletesAPaidPayout(): void
    {
        [, $client] = $this->associate($this->level('Level 2'));
        $orderId = $this->order($client, 'USD', [[10000.0, 10.0]], 83.0);
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        // A second lock of the same order (another tab, "Lock all ready") writes nothing.
        self::assertFalse(IncentiveService::lock($orderId, '2026-10-25', $this->admin));
        self::assertSame('This order\'s incentive is already locked.', IncentiveService::lockIfReady($orderId, $this->admin));
        $pdo = Database::connection();
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM incentive_payouts WHERE order_id = {$orderId}")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM order_cost_entries WHERE order_id = {$orderId} AND incentive_kind = 'associate'")->fetchColumn());

        // Paid: unlock is refused and the paid row (with its UTR) stays.
        $p = $this->associatePayout($orderId);
        $pdo->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = amount_inr, paid_reference = 'UTR-FR-PAID' WHERE id = :id")->execute(['id' => $p['id']]);
        self::assertSame('Part of this order\'s incentive is already paid, so it can no longer be unlocked.', IncentiveService::unlock($orderId, $this->admin, 'Correcting the commission level'));
        // Even called directly, the payouts delete keeps a paid payout.
        IncentiveRepository::deleteOrderPayouts($orderId);
        self::assertSame('UTR-FR-PAID', $this->associatePayout($orderId)['paid_reference']);
    }

    public function testM2AfterTheClientMovesTheNewAssociateEarnsAtTheirOwnLevel(): void
    {
        [$a, $client] = $this->associate($this->level('Level 4'));
        $orderId = $this->order($client, 'USD', [[10000.0, 10.0]], 83.0);
        AssociateCommission::fixLevelAtPi($orderId);
        self::assertSame(['Level 4 — 10%', true, 1000.0], [AssociateCommission::forOrder($orderId)['level_label'], AssociateCommission::forOrder($orderId)['level_fixed'], AssociateCommission::forOrder($orderId)['amount_fc']]);

        // The MD moves the client to B (Level 1, 2%): B earns at B's level, not A's fixed 10%.
        [$b] = $this->associate($this->level('Level 1'));
        Database::connection()->prepare('UPDATE clients SET associate_id = :b WHERE id = :c')->execute(['b' => $b, 'c' => $client]);
        $c = AssociateCommission::forOrder($orderId);
        self::assertSame([$b, 'Level 1 — 2%', false, 200.0, 16600.0], [$c['id'], $c['level_label'], $c['level_fixed'], $c['amount_fc'], $c['amount_inr']]);
        self::assertNull(AssociateCommission::fixedLevel(OrderRepository::find($orderId), $b));
        self::assertSame('Level 4', AssociateCommission::fixedLevel(OrderRepository::find($orderId), $a)['name']);
    }

    public function testM3MoneyRoundingIsHalfAwayFromZeroWithTheSameFiguresAsNode(): void
    {
        self::assertSame([75.23, 6281.71, 2.14, 9.0, -12.35], [round(1003 * 0.075, 2), round(75.23 * 83.5, 2), round(2.135, 2), round(8.995, 2), round(-12.345, 2)]);
        // The same through the commission: 1,003 × 7.5% = USD 75.23 = ₹6,281.71 at ₹83.50.
        [, $client] = $this->associate($this->newLevel('pct', '7.5'));
        $orderId = $this->order($client, 'USD', [[1003.0, 1.0]], 83.5);
        $c = AssociateCommission::forOrder($orderId);
        self::assertSame([75.23, 6281.71], [$c['amount_fc'], $c['amount_inr']]);
    }

    public function testM4AChangeOfOptionsOrUnitClearsTheFixedFiguresAndVoidsTheApproval(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type, spec_selection, price_snapshot,
                         price_approved_by, price_approved_at, price_approval_reason, price_approved_unit_price, price_approved_selection, price_approval_commission_zero)
                       VALUES (:o, 1, 'Granite 20 mm polished', 100, 'SQM', 52.00, 5200.00, 'goods', '{\"1\":2}', '{\"floor\":50}', :u, NOW(), 'Buyer repeat order', 52.00, '{\"1\":2}|SQM', 1)")
            ->execute(['o' => $orderId, 'u' => $this->admin]);
        $lineId = (int) $pdo->lastInsertId();
        $before = OrderProductRepository::find($lineId);
        self::assertTrue(OrderLinePricing::approvalHolds($before));

        // Same product and price, other options: the PI-time floor and the approval no longer apply.
        $pdo->prepare("UPDATE order_products SET spec_selection = '{\"1\":3}' WHERE id = :id")->execute(['id' => $lineId]);
        self::assertFalse(OrderLinePricing::approvalHolds(OrderProductRepository::find($lineId)), 'the approval was for other options');
        OrderLinePricing::afterSave($lineId, $before, $this->admin);
        $after = OrderProductRepository::find($lineId);
        self::assertSame([null, null, null, null, 0], [$after['price_snapshot'], $after['price_approved_at'], $after['price_approved_unit_price'], $after['price_approved_selection'], (int) $after['price_approval_commission_zero']]);
        self::assertSame('Order line 1: the options or unit changed after the MD approved it — the approval no longer holds.',
            $pdo->query("SELECT reason FROM audit_log WHERE action_type = 'LINE_PRICE_APPROVAL_VOIDED' AND entity_id = {$lineId} ORDER BY id DESC LIMIT 1")->fetchColumn());

        // A unit change does the same.
        $pdo->prepare("UPDATE order_products SET price_snapshot = '{\"floor\":50}' WHERE id = :id")->execute(['id' => $lineId]);
        $before = OrderProductRepository::find($lineId);
        $pdo->prepare("UPDATE order_products SET unit = 'CBM' WHERE id = :id")->execute(['id' => $lineId]);
        OrderLinePricing::afterSave($lineId, $before, $this->admin);
        self::assertNull(OrderProductRepository::find($lineId)['price_snapshot']);
        self::assertSame('{"1":3}|CBM', OrderLinePricing::approvalSelectionKey(OrderProductRepository::find($lineId)));
    }

    public function testM5AndL2TheZohoPaymentNamesTheGstOnTheGrossBilled(): void
    {
        self::assertSame('Associate commission for Ravi Kumar — order SC/ORD/26/001 — gross ₹50,000.00, TDS u/s 194H ₹2,500.00 (5%), net paid ₹47,500.00'
            . " + GST ₹9,000.00 on the associate's tax invoice (input credit), total paid ₹56,500.00 · UTR UTR998877",
            AssociateTds::paymentDescription('Ravi Kumar', ['SC/ORD/26/001'], 50000, 2500, 5.0, '194H', 47500, 'UTR998877', 9000.0));
        self::assertSame('Associate commission for Ravi Kumar — order SC/ORD/26/001 — gross ₹50,000.00, no TDS, net paid ₹50,000.00 · UTR U1',
            AssociateTds::paymentDescription('Ravi Kumar', ['SC/ORD/26/001'], 50000, 0, null, null, 50000, 'U1'), 'no GST: as before');

        // L2: a GST-registered associate paid ₹40,000 with ₹1,000 TDS on a ₹41,500 payout: GST on the ₹41,000 billed.
        [$a, $client] = $this->associate($this->level('Level 2'), '29ABCDE1234F1Z5');
        $orderId = $this->order($client, 'USD', [[10000.0, 10.0]], 83.0);
        IncentiveService::lockIfReady($orderId, $this->admin);
        $p = $this->associatePayout($orderId);
        AssociateDocumentRepository::add($a, 'invoice', $this->createTestFile(), (int) $p['id'], 'INV-FR-1', true, null);
        self::assertSame(7470.0, AssociateCommission::gstFor($p), 'not paid yet: 18% of the ₹41,500.00 payout');
        self::assertSame(7380.0, AssociateCommission::gstFor(['paid_inr' => '40000.00', 'tds_inr' => '1000.00'] + $p), '18% of ₹41,000.00 billed');

        // Through the Zoho payload: the payment amount equals the net paid plus that GST, and the description says so.
        $pdo = Database::connection();
        $pdo->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = 40000.00, tds_inr = 1000.00, gst_pct = 18.00, gst_inr = 7380.00, paid_reference = 'UTR-FR-GST' WHERE id = :id")->execute(['id' => $p['id']]);
        $cost = ['order_id' => $orderId, 'buyer_inquiry_ref' => (string) $pdo->query("SELECT buyer_inquiry_ref FROM orders WHERE id = {$orderId}")->fetchColumn(), 'buyer_name' => 'Buyer'];
        $z = ZohoBooksService::associateCommission($cost);
        self::assertSame(47380.0, ZohoBooksService::associatePaymentPayload($z, 'V1', 'B1', 'A1')['amount']);
        self::assertStringContainsString(", net paid ₹40,000.00 + GST ₹7,380.00 on the associate's tax invoice (input credit), total paid ₹47,380.00 · UTR UTR-FR-GST", $z['payment_description']);
    }

    public function testL1TheRegisterReadyToLockAndThePriceRequestReportFollowTheDivision(): void
    {
        [$a, $client] = $this->associate($this->level('Level 2'));
        $exp = $this->order($client, 'USD', [[1000.0, 1.0]], 83.0);
        [$b, $client2] = $this->associate($this->level('Level 2'));
        $dom = $this->order($client2, 'USD', [[1000.0, 1.0]], 83.0);
        $pdo = Database::connection();
        $pdo->prepare('UPDATE orders SET division_id = 2 WHERE id = :id')->execute(['id' => $dom]);
        $pdo->prepare('UPDATE associates SET division_id = 2 WHERE id = :id')->execute(['id' => $b]);

        $open = DivisionService::withDivision(1, static fn (): array => IncentiveReportService::openOrderIds(['order_ids' => [$exp, $dom]]));
        self::assertSame([$exp], $open);
        $ready = DivisionService::withDivision(1, static fn (): array => IncentiveRepository::unlockedOrderIdsWithPayments());
        self::assertContains($exp, $ready);
        self::assertNotContains($dom, $ready);

        IncentiveService::lockIfReady($exp, $this->admin);
        IncentiveService::lockIfReady($dom, $this->admin);
        $orders = DivisionService::withDivision(2, static fn (): array => array_values(array_unique(array_column(IncentiveReportService::rows(['order_ids' => [$exp, $dom]]), 'order_id'))));
        self::assertSame([$dom], $orders);

        $product = \App\Repositories\ProductRepository::create(['name' => 'FR Granite ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], $this->admin);
        foreach ([$a, $b] as $associate) {
            $pdo->prepare("INSERT INTO price_requests (number, associate_id, product_id, selection, selection_text, kind, status, unit, requested_at)
                           VALUES (:n, :a, :p, '{}', 'Standard specification', 'manual', 'pending', 'SQM', NOW())")
                ->execute(['n' => 'PR-FR-' . $associate, 'a' => $associate, 'p' => $product]);
        }
        $pdo->prepare('UPDATE price_requests SET division_id = 2 WHERE associate_id = :b')->execute(['b' => $b]);
        $names = array_column(DivisionService::withDivision(1, static fn (): array => PriceRequestService::report()), 'associate_id');
        self::assertContains($a, $names);
        self::assertNotContains($b, $names);
    }

    public function testL3ADeletedLineAsksForNoQualityDocument(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO product_categories (name, default_unit, allowed_units, quality_documents) VALUES (:n, 'PCS', 'PCS', 'Mill test certificate 3.1')")
            ->execute(['n' => 'FR Engineering ' . bin2hex(random_bytes(3))]);
        $category = (int) $pdo->lastInsertId();
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type, category_id) VALUES (:o, 1, 'Weld neck flange', 40, 'PCS', 18.50, 740.00, 'goods', :c)")
            ->execute(['o' => $orderId, 'c' => $category]);
        $lineId = (int) $pdo->lastInsertId();
        self::assertSame(['Mill test certificate 3.1'], array_column(OrderQualityDocumentRepository::forOrder($orderId), 'document_name'));
        $pdo->prepare('UPDATE order_products SET is_active = 0 WHERE id = :id')->execute(['id' => $lineId]);
        self::assertSame([], OrderQualityDocumentRepository::forOrder($orderId));
    }
}
