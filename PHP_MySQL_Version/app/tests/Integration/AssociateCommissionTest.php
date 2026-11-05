<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Controllers\IncentiveController;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\AssociateCommission;
use App\Services\IncentiveService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-245, phase P3 (4 Nov 2026): associate commission on the final
 * invoiced FOB of the goods, line by line, at the level fixed at PI, full payment
 * only; ladder by counting orders; due N days after full payment, held while a
 * buyer claim is open, paid in the payout run; GST on top for a GST-registered
 * associate with a tax invoice; take-back only for fraud or breach; forcing
 * commission on is the MD's, with a reason; a registration lapses. Worked to the
 * paisa. Node twin: tests/integration/associateCommission.test.js.
 */
final class AssociateCommissionTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $this->actAs($this->admin);
        foreach (['associate_commission_due_days' => '30', 'associate_claim_hold' => '1', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '5',
            'associate_counting_threshold_inr' => '500000', 'associate_gst_pct' => '18.00', 'associate_protection_months' => '6'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function flash(): string
    {
        $m = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $m === [] ? '' : (string) end($m)['message'];
    }

    private function level(string $name): int
    {
        return (int) Database::connection()->query('SELECT id FROM commission_levels WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    /** @return array{0:int,1:int} associate id, client id */
    private function associate(string $mode = 'fixed', string $levelName = 'Level 2', ?string $gstin = null): array
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id, gstin) VALUES (:n, 'active', :m, :l, :g)")
            ->execute(['n' => 'P3 Associate ' . bin2hex(random_bytes(3)), 'm' => $mode, 'l' => $mode === 'fixed' ? $this->level($levelName) : null, 'g' => $gstin]);
        $a = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $a, 'c' => $client]);
        return [$a, $client];
    }

    /**
     * A USD order for $client with goods lines [fob, qty, below-floor approved?], paid in full at ₹83.00
     * (advance and balance receipts with the INR credited), balance cleared on $cleared (null = not yet).
     *
     * @param list<array{0: float, 1: float, 2?: bool}> $lines
     */
    private function order(int $client, array $lines, ?string $cleared = '2026-10-25', float $rate = 83.0): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($client, 'FOB', 'Standard — New Buyer', 'USD');
        $total = 0.0;
        foreach ($lines as $i => $l) {
            $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type, price_approval_commission_zero, price_approved_by)
                           VALUES (:o, :n, 'Granite', :q, 'CBM', :p, :f, 'goods', :z, :by)")
                ->execute(['o' => $orderId, 'n' => $i + 1, 'q' => $l[1], 'p' => round($l[0] / $l[1], 2), 'f' => $l[0], 'z' => !empty($l[2]) ? 1 : 0, 'by' => !empty($l[2]) ? $this->admin : null]);
            $total += $l[0];
        }
        $advance = round($total * 0.4, 2);
        $balance = round($total - $advance, 2);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = :a, advance_cleared_at = :ac, advance_inr_actual = :ai, advance_exchange_rate = :r,
            balance_amount = :b, balance_cleared_at = :bc, balance_inr_actual = :bi, balance_exchange_rate = :r2 WHERE order_id = :o')
            ->execute(['a' => $advance, 'ac' => '2026-10-10 10:00:00', 'ai' => round($advance * $rate, 2), 'r' => $rate, 'b' => $balance,
                'bc' => $cleared === null ? null : $cleared . ' 11:00:00', 'bi' => $cleared === null ? null : round($balance * $rate, 2), 'r2' => $rate, 'o' => $orderId]);
        foreach ([['advance', $advance], ['balance', $balance]] as [$leg, $amount]) {
            $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', :r, :i, 'UTR-P3')")
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

    public function testFixedFivePercentOnInvoicedGoodsFobWithALineApprovedBelowTheFloor(): void
    {
        [$a, $client] = $this->associate('fixed', 'Level 2');
        $orderId = $this->order($client, [[7525.0, 500.0], [4380.0, 300.0], [2840.0, 200.0, true]]);
        $c = AssociateCommission::forOrder($orderId);
        self::assertSame([14745.0, 11905.0, 11905.0, 595.25, 83.0], [$c['goods_fc'], $c['in_range_fc'], $c['basis_fc'], $c['amount_fc'], $c['rate_inr']]);
        self::assertSame([376.25, 219.0, 0.0], array_column($c['lines'], 'commission'), 'line 3 approved below the floor earns nothing');
        self::assertSame([49405.75, 988115.0, 'paid', true], [$c['amount_inr'], $c['basis_inr'], $c['state'], $c['counts']]);
        self::assertSame(['2026-11-24', '2026-12-05'], [$c['due_on'], $c['payout_run_on']], '30 days after full payment; the run on the 5th');

        // The level is fixed on the order with the PI and stays, even if the associate's level changes later.
        AssociateCommission::fixLevelAtPi($orderId);
        PricingRepository::setAssociateCommission($a, 'fixed', $this->level('Level 4'));
        self::assertSame('Level 2 — 5%', AssociateCommission::forOrder($orderId)['level_label']);

        IncentiveService::lockIfReady($orderId, $this->admin);
        $p = $this->associatePayout($orderId);
        self::assertSame(['49405.75', '595.25', '2026-11-24', '2026-12-05', '2026-12'], [$p['amount_inr'], $p['amount_fc'], $p['due_on'], $p['payout_run_on'], $p['incentive_month']]);
        self::assertSame('Associate commission — ' . AssociateRepository::find($a)['name'] . ' (Level 2 — 5%) on the invoiced FOB of the goods, USD 11,905.00; lines the MD approved below the floor earn nothing',
            IncentiveRepository::costEntries($orderId)['associate']['description']);
        $row = IncentiveRepository::forOrder($orderId);
        self::assertSame(['1', '11905.00', '988115.00', '2026-11-24'], [(string) $row['associate_counts'], $row['associate_basis_fc'], $row['associate_basis_inr'], $row['associate_due_on']]);
    }

    public function testBankChargesNeverReduceTheBasis(): void
    {
        [, $client] = $this->associate();
        $orderId = $this->order($client, [[1800.0, 10.0]]);
        // The balance receipt: the bank kept ₹502 (D-244 "bank deducted its charges") — added back to the rupees.
        Database::connection()->prepare("UPDATE order_payment_receipts SET inr_credited = inr_credited - 502, rate_gap_choice = 'bank_charges', rate_gap_inr = 502 WHERE order_id = :o AND leg = 'balance'")
            ->execute(['o' => $orderId]);
        self::assertSame([83.0, 90.0, 7470.0], [round(AssociateCommission::forOrder($orderId)['rate_inr'], 4), AssociateCommission::forOrder($orderId)['amount_fc'], AssociateCommission::forOrder($orderId)['amount_inr']]);
    }

    public function testTheLadderCountsOrdersAndASmallOrderEarnsTheCurrentStepWithoutCounting(): void
    {
        [$a, $client] = $this->associate('ladder');
        $assoc = AssociateRepository::find($a);
        self::assertSame('Level 1', AssociateCommission::levelForAssociate($assoc, null)[0]['name'], 'first order: step 1 (2%)');
        // Five counting orders already.
        for ($i = 0; $i < 5; $i++) {
            $old = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
            IncentiveRepository::saveOrder($old, ['associate_id' => $a, 'associate_counts' => 1, 'locked_at' => date('Y-m-d H:i:s')], $this->admin);
        }
        self::assertSame(5, AssociateCommission::countingOrders($a));
        self::assertSame('Level 2', AssociateCommission::levelForAssociate($assoc, null, 600000.0)[0]['name'], 'the 6th counting order: 5%');
        self::assertSame('Level 1', AssociateCommission::levelForAssociate($assoc, null, 100000.0)[0]['name'], 'under ₹5 lakh: the current step (2%), not counting');

        // A big order fixes Level 2 at PI (estimate at the pricing rate ₹82: USD 10,000 = ₹8.2 lakh) and counts once paid.
        $big = $this->order($client, [[10000.0, 10.0]]);
        AssociateCommission::fixLevelAtPi($big);
        self::assertSame(['Level 2', 'pct', '5.0000'], array_values(Database::connection()->query("SELECT associate_level_name, associate_level_kind, associate_level_value FROM orders WHERE id = {$big}")->fetch(\PDO::FETCH_NUM)));
        IncentiveService::lockIfReady($big, $this->admin);
        self::assertSame(['1', '6'], [(string) IncentiveRepository::forOrder($big)['associate_counts'], (string) IncentiveRepository::forOrder($big)['associate_order_number']]);

        // A small order (USD 1,000 = ₹83,000): the current step after six counting orders is Level 2; it does not count.
        $client2 = $this->createTestClient();
        Database::connection()->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $a, 'c' => $client2]);
        $small = $this->order($client2, [[1000.0, 1.0]]);
        AssociateCommission::fixLevelAtPi($small);
        $c = AssociateCommission::forOrder($small);
        self::assertSame(['Level 2 — 5%', 50.0, false], [$c['level_label'], $c['amount_fc'], $c['counts']]);
        IncentiveService::lockIfReady($small, $this->admin);
        self::assertSame(['0', 6], [(string) IncentiveRepository::forOrder($small)['associate_counts'], AssociateCommission::countingOrders($a)]);
    }

    public function testAnAgreedClaimLowersTheBasisAndAnUnpaidAmountPaysNothing(): void
    {
        [, $client] = $this->associate();
        $claim = $this->order($client, [[10000.0, 10.0]]);
        Database::connection()->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                                          VALUES (:o, 'balance', 6000, 5000, 1000, 'written_off', 'Buyer claim on chipped edges settled', :u, 'claim_settled')")->execute(['o' => $claim, 'u' => $this->admin]);
        $c = AssociateCommission::forOrder($claim);
        self::assertSame([1000.0, 9000.0, 450.0, 37350.0], [$c['reduction_fc'], $c['basis_fc'], $c['amount_fc'], $c['amount_inr']]);

        [$a2, $client2] = $this->associate();
        $unpaid = $this->order($client2, [[10000.0, 10.0]]);
        Database::connection()->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                                          VALUES (:o, 'balance', 6000, 5000, 1000, 'written_off', 'Buyer stopped paying', :u, 'unpaid')")->execute(['o' => $unpaid, 'u' => $this->admin]);
        $c = AssociateCommission::forOrder($unpaid);
        self::assertSame(['unpaid', 0.0, 0.0], [$c['state'], $c['amount_fc'], $c['amount_inr']], 'no pro rata: nothing unless paid in full');
        IncentiveService::lockIfReady($unpaid, $this->admin);
        self::assertNull($this->associatePayout($unpaid));
        self::assertSame('0', (string) IncentiveRepository::forOrder($unpaid)['associate_counts']);
        self::assertSame(0, AssociateCommission::countingOrders($a2));
    }

    public function testNothingBeforeFullPaymentAndTheShortfallMustSayWhatItIs(): void
    {
        [, $client] = $this->associate();
        $orderId = $this->order($client, [[1000.0, 1.0]], null);
        $c = AssociateCommission::forOrder($orderId);
        self::assertSame(['awaiting', null, 50.0], [$c['state'], $c['due_on'], $c['amount_fc']], 'an estimate until the buyer has paid in full');
        IncentiveService::lockIfReady($orderId, $this->admin);
        self::assertNull(IncentiveRepository::forOrder($orderId));
        self::assertSame(['agreed_discount', 'claim_settled', 'unpaid'], array_keys(AssociateCommission::SETTLEMENT_KINDS));

        // Nothing received yet: the estimate uses the Admin pricing exchange rate, never 0.
        Database::connection()->exec("DELETE FROM order_payment_receipts WHERE order_id = {$orderId}");
        Database::connection()->exec("DELETE FROM order_payment_status WHERE order_id = {$orderId}");
        $fx = (float) \App\Repositories\PricingRepository::settings()['pricing_fx_rate'];
        self::assertGreaterThan(0, $fx);
        $c = AssociateCommission::forOrder($orderId);
        self::assertSame([$fx, true, round(50 * $fx, 2)], [(float) $c['rate_inr'], (bool) $c['rate_estimated'], round((float) $c['amount_inr'], 2)]);
    }

    public function testDueDateClaimHoldAndPayoutRun(): void
    {
        self::assertSame('2026-12-05', AssociateCommission::payoutRunOn('2026-12-03'));
        self::assertSame('2026-12-05', AssociateCommission::payoutRunOn('2026-12-05'));
        self::assertSame('2027-01-05', AssociateCommission::payoutRunOn('2026-12-06'));
        IncentiveRepository::setSetting('associate_payout_run', 'weekly', $this->admin);
        IncentiveRepository::setSetting('associate_payout_day', '1', $this->admin);
        self::assertSame('2026-11-30', AssociateCommission::payoutRunOn('2026-11-24'), 'weekly on Mondays: Tue 24 Nov → Mon 30 Nov');
        IncentiveRepository::setSetting('associate_payout_run', 'monthly', $this->admin);
        IncentiveRepository::setSetting('associate_payout_day', '5', $this->admin);

        [$kycA, $client] = $this->associate();
        $this->completeAssociateKyc(is_array($kycA) ? (int) $kycA['id'] : (int) $kycA); // D-252: KYC complete, so only the due date and claim hold apply
        $orderId = $this->order($client, [[1000.0, 1.0]]);
        IncentiveService::lockIfReady($orderId, $this->admin);
        $p = $this->associatePayout($orderId);
        self::assertStringContainsString('due on 24 Nov 2026', (string) AssociateCommission::payBlockReason($p, '2026-11-20'));
        self::assertNull(AssociateCommission::payBlockReason($p, '2026-12-05'));
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Chipped edges on 3 slabs', 'open', '2026-11-20 09:00:00')")
            ->execute(['o' => $orderId, 'c' => $client]);
        self::assertSame('A buyer claim on this order is open — the commission waits until it is resolved.', AssociateCommission::payBlockReason($p, '2026-12-05'));
        $pdo->prepare("UPDATE order_complaints SET status = 'resolved' WHERE order_id = :o")->execute(['o' => $orderId]);
        self::assertNull(AssociateCommission::payBlockReason($p, '2026-12-05'));
        // A claim opened after the due date does not hold it.
        $pdo->prepare("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Late complaint', 'open', '2026-11-30 09:00:00')")
            ->execute(['o' => $orderId, 'c' => $client]);
        self::assertNull(AssociateCommission::payBlockReason($p, '2026-12-05'));
    }

    public function testGstOnTopOnlyForAGstRegisteredAssociateWithATaxInvoice(): void
    {
        [$a, $client] = $this->associate('fixed', 'Level 2', '29ABCDE1234F1Z5');
        $orderId = $this->order($client, [[10000.0, 10.0]]);
        IncentiveService::lockIfReady($orderId, $this->admin);
        $p = $this->associatePayout($orderId);
        self::assertSame('41500.00', $p['amount_inr']);
        self::assertSame(0.0, AssociateCommission::gstFor($p), 'no tax invoice yet');
        AssociateDocumentRepository::add($a, 'invoice', $this->createTestFile(), (int) $p['id'], 'INV-P3-1', true, null);
        self::assertSame(7470.0, AssociateCommission::gstFor($p), '18% of ₹41,500.00');

        [$b, $client2] = $this->associate();
        $order2 = $this->order($client2, [[10000.0, 10.0]]);
        IncentiveService::lockIfReady($order2, $this->admin);
        $p2 = $this->associatePayout($order2);
        AssociateDocumentRepository::add($b, 'invoice', $this->createTestFile(), (int) $p2['id'], 'INV-P3-2', true, null);
        self::assertSame(0.0, AssociateCommission::gstFor($p2), 'not GST-registered: no GST');
        self::assertSame('Associate commission to ' . AssociateRepository::find($b)['name'] . ' on order ' . Database::connection()->query("SELECT order_reference FROM orders WHERE id = {$order2}")->fetchColumn()
            . ' (payout no. ' . $p2['id'] . ', settles their commission invoice for this order): ₹41,500.00', AssociateCommission::payoutDescription($p2));
    }

    public function testTakeBackOnlyForFraudOrBreachWithAReason(): void
    {
        [, $client] = $this->associate();
        $orderId = $this->order($client, [[1000.0, 1.0]]);
        IncentiveService::lockIfReady($orderId, $this->admin);
        $p = $this->associatePayout($orderId);
        Database::connection()->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = amount_inr, paid_reference = 'UTR-PAID-1' WHERE id = :id")->execute(['id' => $p['id']]);
        $_POST = ['amount_inr' => '1000', 'reason' => 'Buyer was refunded for damage'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $p['id']]);
        self::assertStringContainsString('only for the associate\'s fraud or breach', $this->flash());
        self::assertSame('0', (string) Database::connection()->query("SELECT COUNT(*) FROM incentive_payouts WHERE clawback_of_payout_id = {$p['id']}")->fetchColumn());

        $_POST = ['amount_inr' => '1000', 'reason' => 'Fake second quote', 'takeback_kind' => 'fraud'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $p['id']]);
        $tb = Database::connection()->query("SELECT amount_inr, takeback_kind FROM incentive_payouts WHERE clawback_of_payout_id = {$p['id']}")->fetch();
        self::assertSame(['-1000.00', 'fraud'], [$tb['amount_inr'], $tb['takeback_kind']]);
    }

    public function testForcingCommissionOnIsTheMdsWithAReason(): void
    {
        [, $client] = $this->associate();
        $orderId = $this->order($client, [[1000.0, 1.0]], null);
        $this->actAs($this->createTestUser('Export Executive'));
        $_POST = ['associate_mode' => 'yes', 'associate_mode_reason' => 'Buyer introduced before the registration was recorded'];
        (new IncentiveController())->saveOrder(['id' => (string) $orderId]);
        // D-245 P4: every change to an order's incentive is the MD's (manage_pricing).
        self::assertStringContainsString('Only the MD', $this->flash());

        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $_POST = ['associate_mode' => 'yes', 'associate_mode_reason' => 'short'];
        (new IncentiveController())->saveOrder(['id' => (string) $orderId]);
        self::assertStringContainsString('10 to 300 characters', $this->flash());
        $_POST = ['associate_mode' => 'yes', 'associate_mode_reason' => 'Buyer introduced before the registration was recorded'];
        (new IncentiveController())->saveOrder(['id' => (string) $orderId]);
        $row = IncentiveRepository::forOrder($orderId);
        self::assertSame(['yes', 'Buyer introduced before the registration was recorded', (string) $md], [$row['associate_mode'], $row['associate_mode_reason'], (string) $row['associate_mode_set_by']]);
        self::assertSame('Buyer introduced before the registration was recorded',
            Database::connection()->query("SELECT reason FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_FORCED' AND entity_id = {$orderId}")->fetchColumn());
    }

    public function testARegistrationWithNoOrderLapses(): void
    {
        [$a, $client] = $this->associate();
        $pdo = Database::connection();
        $pdo->prepare("UPDATE clients SET associate_locked_until = '2026-10-01' WHERE id = :c")->execute(['c' => $client]);
        [, $withOrder] = $this->associate();
        $pdo->prepare("UPDATE clients SET associate_locked_until = '2026-10-01' WHERE id = :c")->execute(['c' => $withOrder]);
        $this->createTestOrder($withOrder);
        self::assertGreaterThanOrEqual(1, AssociateCommission::lapseRegistrations('2026-11-04'));
        self::assertNull($pdo->query("SELECT associate_id FROM clients WHERE id = {$client}")->fetchColumn() ?: null, 'no order by then: unlinked');
        self::assertNotNull($pdo->query("SELECT associate_id FROM clients WHERE id = {$withOrder}")->fetchColumn() ?: null, 'the buyer ordered: still linked');
        self::assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM associate_events WHERE associate_id = {$a} AND event = 'registration_lapsed'")->fetchColumn());
    }

    public function testThePortalPriceListShowsOnlyTheirTwoPricesAndIsAcknowledged(): void
    {
        $id = ProductRepository::create(['name' => 'P3 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $this->admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'Sri Balaji Granites', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        [$a] = $this->associate('fixed', 'Level 2');
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
        $list = AssociateCommission::priceList(AssociateRepository::find($a));
        $row = array_values(array_filter($list['rows'], static fn (array $r): bool => str_starts_with($r['name'], 'P3 Black Galaxy')))[0];
        self::assertSame(['name', 'unit', 'quote', 'lowest'], array_keys($row), 'never cost, margin or formula');
        self::assertSame(['CBM', 1355.26], [$row['unit'], $row['lowest']], 'their lowest price at 5%: staff floor USD 1,287.50 ÷ 0.95');
        self::assertFalse($list['acknowledged']);
        Database::connection()->prepare('UPDATE associates SET price_list_ack_hash = :h, price_list_ack_at = NOW() WHERE id = :id')->execute(['h' => $list['hash'], 'id' => $a]);
        self::assertTrue(AssociateCommission::priceList(AssociateRepository::find($a))['acknowledged']);
        // Prices change (the team incentive program is switched off): the list is new and must be acknowledged again.
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
        self::assertFalse(AssociateCommission::priceList(AssociateRepository::find($a))['acknowledged']);
    }
}
