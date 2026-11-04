<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Flash;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\ReportRepository;
use App\Services\AssociateCommission;
use App\Services\IncentiveReportService;
use App\Services\IncentiveService;
use App\Services\OrderLinePricing;
use App\Services\OrderProfitabilityService;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-245, phase P6: five worked example orders, end to end, to the
 * paisa — price from a product whose ₹80,000 supplier price is "used for
 * pricing" (cost USD 1,000.00, minimum 1,250.00, staff floor 1,287.50, staff
 * quote 1,326.13; associate 5% floor 1,355.26, quote 1,395.92), lines picked from
 * Products, the level fixed at the PI, buyer remittances with the INR credited,
 * the MD's lock, payouts and payment; every profit line with its state; and the
 * register, "To pay", "Owed" and profitability report totals equal the sum of
 * these orders. Node twin: tests/integration/d245WorkedExamples.test.js — same
 * inputs, same figures.
 */
final class D245WorkedExamplesTest extends DbTestCase
{
    private int $admin;
    /** @var array<string,int> */
    private array $p = [];
    /** @var array<string, array<string,mixed>> */
    private array $rolesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $this->actAs($this->admin);
        foreach (['sales' => 'Export Executive', 'manager' => 'Export Executive', 'export' => 'Export Executive', 'logistics' => 'Logistics Executive',
            'marketing' => 'Export Executive', 'accounts' => 'Accounts Executive'] as $k => $role) {
            $this->p[$k] = $this->createTestUser($role);
        }
        IncentiveRepository::replaceUserRoles([
            $this->p['sales'] => ['sales', 'export'], $this->p['manager'] => ['sales_manager'], $this->p['export'] => ['export'],
            $this->p['logistics'] => ['logistics'], $this->p['marketing'] => ['marketing'], $this->p['accounts'] => ['accounts'],
        ]);
        IncentiveRepository::setSetting('program_enabled', '1', $this->admin);
        foreach (['associate_commission_due_days' => '30', 'associate_claim_hold' => '1', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '5',
            'associate_counting_threshold_inr' => '500000', 'associate_gst_pct' => '18.00'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
        $this->rolesBefore = IncentiveRepository::allRoles();
    }

    protected function tearDown(): void
    {
        IncentiveRepository::replaceUserRoles([]);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function call(string $method, int $orderId, array $post): array
    {
        $_POST = $post;
        ob_start();
        (new \App\Controllers\IncentiveController())->{$method}(['id' => (string) $orderId]);
        ob_end_clean();
        $_POST = [];
        return Flash::pull()[0] ?? ['type' => 'none', 'message' => ''];
    }

    /** The product: ₹80,000 per CBM from the supplier, used for pricing. */
    private function product(): int
    {
        $id = ProductRepository::create(['name' => 'P6 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $this->admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'Sri Balaji Granites', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        return $id;
    }

    /** @return array{0:int,1:int} associate id, client id */
    private function associate(string $mode, ?string $levelName, ?string $gstin = null): array
    {
        $pdo = Database::connection();
        $level = $levelName === null ? null : (int) $pdo->query('SELECT id FROM commission_levels WHERE name = ' . $pdo->quote($levelName))->fetchColumn();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id, gstin) VALUES (:n, 'active', :m, :l, :g)")
            ->execute(['n' => 'P6 Associate ' . bin2hex(random_bytes(3)), 'm' => $mode, 'l' => $level, 'g' => $gstin]);
        $a = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $a, 'c' => $client]);
        return [$a, $client];
    }

    /**
     * An order whose lines are picked from the product: [qty, unit price].
     *
     * @param list<array{0:string,1:string}> $lines
     * @param list<int> $assignees
     * @return array{0:int, 1:list<int>} order id, line ids
     */
    private function order(int $productId, array $lines, ?int $client = null, array $assignees = []): array
    {
        $orderId = $this->createTestOrder($client ?? $this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $assignees);
        $ids = [];
        foreach ($lines as $i => [$qty, $price]) {
            $lineId = OrderProductRepository::add($orderId, $i + 1, 'Black Galaxy block', null, null, $qty, false, 'CBM', $price, '25161100');
            OrderProductRepository::setCatalogProduct($lineId, $productId);
            $ids[] = $lineId;
        }
        return [$orderId, $ids];
    }

    /**
     * The buyer pays 40% advance and 60% balance at $rate (INR credited on each receipt), the balance cleared on $cleared
     * (null = not yet); the supplier's bill is $supplierInr.
     */
    private function money(int $orderId, float $rate, ?string $cleared, float $supplierInr, string $short = ''): void
    {
        $pdo = Database::connection();
        $total = (float) $pdo->query("SELECT SUM(fob_value) FROM order_products WHERE order_id = {$orderId}")->fetchColumn();
        $advance = round($total * 0.4, 2);
        $balance = round($total - $advance, 2);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = :a, advance_cleared_at = :ac, advance_inr_actual = :ai, advance_exchange_rate = :r,
            balance_amount = :b, balance_cleared_at = :bc, balance_inr_actual = :bi, balance_exchange_rate = :r2 WHERE order_id = :o')
            ->execute(['a' => $advance, 'ac' => '2026-10-10 10:00:00', 'ai' => round($advance * $rate, 2), 'r' => $rate, 'b' => $balance,
                'bc' => $cleared === null ? null : $cleared . ' 11:00:00', 'bi' => $cleared === null ? null : round($balance * $rate, 2), 'r2' => $rate, 'o' => $orderId]);
        foreach ([['advance', $advance], ['balance', $balance]] as [$leg, $amount]) {
            if ($leg === 'balance' && $cleared === null) {
                continue;
            }
            $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', :r, :i, 'UTR-P6')")
                ->execute(['o' => $orderId, 'l' => $leg, 'a' => $amount, 'r' => $rate, 'i' => round($amount * $rate, 2)]);
        }
        $pdo->exec("INSERT INTO suppliers (supplier_legal_name) VALUES ('TEST-P6 Supplier')");
        $supplier = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference, total_payable_inr) VALUES (:o, :s, 'TEST-P6 Supplier', :ref, :t)")
            ->execute(['o' => $orderId, 's' => $supplier, 'ref' => 'TEST-SPO-P6-' . $orderId, 't' => $supplierInr]);
    }

    /** @return array<string,mixed> the profit lines that matter, rounded */
    private function profit(int $orderId): array
    {
        $p = OrderProfitabilityService::computeForOrder($orderId);
        return ['revenue' => $p['revenue_inr'], 'cost' => $p['cost_before_incentives_inr'], 'before' => $p['profit_before_commission_inr'],
            'commission' => $p['commission_inr'], 'commission_state' => $p['commission_state'], 'incentive' => $p['incentive_inr'],
            'incentive_state' => $p['incentive_state'], 'after' => $p['profit_after_incentive_inr']];
    }

    /** @return array<string,float> payee => amount */
    private function payouts(int $orderId): array
    {
        $names = array_flip($this->p);
        $out = [];
        foreach (IncentiveRepository::payoutsForOrder($orderId) as $p) {
            $key = $p['payee_kind'] === 'staff' ? $names[(int) $p['user_id']] : 'associate';
            $out[$key] = round(($out[$key] ?? 0) + (float) $p['amount_inr'], 2);
        }
        ksort($out);
        return $out;
    }

    /** The register, "To pay", "Owed" and the profitability report add up to these orders, with nothing else mixed in. */
    private function assertReportsAddUp(array $orderIds): void
    {
        $expectedPayouts = 0.0;
        foreach ($orderIds as $id) {
            $p = OrderProfitabilityService::computeForOrder($id);
            $expectedPayouts += $p['commission_inr'] + $p['incentive_inr'];
        }
        $rows = IncentiveReportService::rows(['order_ids' => $orderIds]);
        self::assertSame(array_values(array_unique(array_column($rows, 'order_id'))), array_values(array_intersect(array_unique(array_column($rows, 'order_id')), $orderIds)));
        $sum = round(array_sum(array_column($rows, 'amount_inr')), 2);
        self::assertSame(round($expectedPayouts, 2), $sum, 'the register adds up to the commission + incentive of these orders');
        self::assertSame($sum, IncentiveReportService::totals($rows)['amount_inr']);
        // Paged: the pages together are the whole set.
        $paged = [];
        for ($i = 1, $pages = IncentiveReportService::page($rows, 1, 2)['pages']; $i <= $pages; $i++) {
            array_push($paged, ...IncentiveReportService::page($rows, $i, 2)['rows']);
        }
        self::assertSame($rows, $paged);
        $toPay = IncentiveReportService::toPay('', null, ['order_ids' => $orderIds]);
        $groupSum = 0.0;
        foreach ($toPay as $g) {
            self::assertSame(round(array_sum(array_column($g['rows'], 'amount_inr')), 2), $g['totals']['amount_inr']);
            $groupSum += $g['totals']['amount_inr'];
        }
        $unpaid = round(array_sum(array_map(static fn (array $r): float => $r['state'] === 'paid' ? 0.0 : (float) $r['amount_inr'], $rows)), 2);
        self::assertSame($unpaid, round($groupSum, 2), '"To pay" holds every unpaid row of these orders exactly once');
        $owed = IncentiveReportService::owedAt(date('Y-m-d'), ['order_ids' => $orderIds]);
        self::assertSame(round(array_sum(array_column($owed, 'amount_inr')), 2), IncentiveReportService::totals($owed)['amount_inr']);
        $report = ReportRepository::orderProfitabilityReport(null, null, 'all');
        foreach ($report['rows'] as $r) {
            if (in_array((int) $r['order_id'], $orderIds, true)) {
                $p = OrderProfitabilityService::computeForOrder((int) $r['order_id']);
                self::assertSame([$p['profit_before_commission_inr'], $p['commission_inr'], $p['incentive_inr'], $p['profit_after_incentive_inr']],
                    [(float) $r['profit_before_commission_inr'], (float) $r['commission_inr'], (float) $r['incentive_inr'], (float) $r['profit_after_incentive_inr']]);
            }
        }
    }

    public function testExample1DirectSaleGlobalFifteenPercentLockedThenPaid(): void
    {
        $productId = $this->product();
        [$orderId, $lines] = $this->order($productId, [['20', '1326.13']], null, [$this->p['sales'], $this->p['logistics']]);
        $f = OrderLinePricing::forOrder($orderId)['lines'][$lines[0]];
        self::assertSame([OrderLinePricing::OK, 1000.0, 1250.0, 1287.5, 1326.13], [$f['status'], $f['figures']['cost'], $f['figures']['minimum'], $f['figures']['floor'], $f['figures']['quote']]);
        OrderLinePricing::snapshotForPi($orderId);
        $this->money($orderId, 83.0, '2026-10-25', 1640000.0);

        self::assertSame(['revenue' => 2201375.8, 'cost' => 1640000.0, 'before' => 561375.8, 'commission' => 0.0, 'commission_state' => null,
            'incentive' => 84206.37, 'incentive_state' => 'estimated', 'after' => 477169.43], $this->profit($orderId));
        self::assertContains($orderId, IncentiveService::readyToLock());
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        // ₹84,206.37 over the 95% of roles with someone (Export Compliance has nobody). The seller is on the order and holds
        // Sales and Export Documentation, so both shares are theirs (the other export holder is not on the order); roles with
        // nobody on the order go to their holders (the existing rule).
        self::assertSame(['accounts' => 8863.83, 'logistics' => 13295.74, 'manager' => 8863.83, 'marketing' => 13295.74, 'sales' => 39887.23], $this->payouts($orderId));
        self::assertSame(84206.37, round(array_sum($this->payouts($orderId)), 2));
        self::assertSame(['locked', 477169.43], [$this->profit($orderId)['incentive_state'], $this->profit($orderId)['after']]);
        $this->assertReportsAddUp([$orderId]);

        foreach (IncentiveRepository::payoutsForOrder($orderId) as $row) {
            IncentiveService::markPaid((int) $row['id'], date('Y-m-d'), (float) $row['amount_inr'], null, $this->admin);
        }
        self::assertSame(['paid', 477169.43], [$this->profit($orderId)['incentive_state'], $this->profit($orderId)['after']], 'paying changes the state, never the profit');
        $this->assertReportsAddUp([$orderId]);
    }

    public function testExample2AssociateFivePercentBelowFloorLineGstManagerAndClaimHold(): void
    {
        $productId = $this->product();
        [$a, $client] = $this->associate('fixed', 'Level 2', '29ABCDE1234F1Z5');
        [$orderId, $lines] = $this->order($productId, [['20', '1395.92'], ['5', '1300.00']], $client, [$this->p['sales'], $this->p['manager'], $this->p['export'], $this->p['logistics']]);
        $check = OrderLinePricing::forOrder($orderId)['lines'];
        self::assertSame([OrderLinePricing::OK, OrderLinePricing::BELOW], [$check[$lines[0]]['status'], $check[$lines[1]]['status']]);
        self::assertNotNull(OrderLinePricing::piBlockReason($orderId));
        self::assertNull(OrderLinePricing::approve($orderId, $lines[1], $this->admin, 'Trial container for a new distributor'));
        self::assertNull(OrderLinePricing::piBlockReason($orderId));
        AssociateCommission::fixLevelAtPi($orderId);
        OrderLinePricing::snapshotForPi($orderId);
        $this->money($orderId, 83.0, '2026-10-25', 2050000.0);

        $c = AssociateCommission::forOrder($orderId);
        self::assertSame([34418.4, 27918.4, 1395.92, 115861.36, '2026-11-24', '2026-12-05'], [$c['goods_fc'], $c['basis_fc'], $c['amount_fc'], $c['amount_inr'], $c['due_on'], $c['payout_run_on']]);
        self::assertSame(['revenue' => 2856727.2, 'cost' => 2050000.0, 'before' => 806727.2, 'commission' => 115861.36, 'commission_state' => 'estimated',
            'incentive' => 103629.88, 'incentive_state' => 'estimated', 'after' => 587235.96], $this->profit($orderId));

        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        // ₹1,03,629.88 over the roles with someone in them (Export Compliance has nobody: its 5% spreads over the other 95%).
        // The seller holds Sales and Export Documentation; Export Documentation is shared with the export executive.
        // Marketing and Accounts are not on this order, so the role holders get them (the existing rule).
        self::assertSame(['accounts' => 10908.41, 'associate' => 115861.36, 'export' => 8181.3, 'logistics' => 16362.61, 'manager' => 10908.41, 'marketing' => 16362.61, 'sales' => 40906.54], $this->payouts($orderId));
        self::assertSame(103629.88, round(array_sum($this->payouts($orderId)) - 115861.36, 2));
        $assoc = Database::connection()->query("SELECT * FROM incentive_payouts WHERE order_id = {$orderId} AND payee_kind = 'associate'")->fetch();
        self::assertSame(['2026-11-24', '2026-12-05'], [$assoc['due_on'], $assoc['payout_run_on']]);
        self::assertSame(0.0, AssociateCommission::gstFor($assoc), 'no tax invoice yet');
        AssociateDocumentRepository::add($a, 'invoice', $this->createTestFile(), (int) $assoc['id'], 'INV-P6-1', true, null);
        self::assertSame(20855.04, AssociateCommission::gstFor($assoc), 'GST 18% on top, not a cost');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Two blocks cracked', 'open', '2026-11-20 09:00:00')")->execute(['o' => $orderId, 'c' => $client]);
        self::assertSame('A buyer claim on this order is open — the commission waits until it is resolved.', AssociateCommission::payBlockReason($assoc, '2026-12-05'));
        $pdo->prepare("UPDATE order_complaints SET status = 'resolved' WHERE order_id = :o")->execute(['o' => $orderId]);
        self::assertNull(AssociateCommission::payBlockReason($assoc, '2026-12-05'));
        self::assertSame(['locked', 'locked', 587235.96], [$this->profit($orderId)['commission_state'], $this->profit($orderId)['incentive_state'], $this->profit($orderId)['after']]);
        $this->assertReportsAddUp([$orderId]);
    }

    public function testExample3LadderCrossesToFivePercentAtTheSixthCountingOrderAndASmallOrderDoesNotCount(): void
    {
        $productId = $this->product();
        [$a, $client] = $this->associate('ladder', null);
        for ($i = 0; $i < 5; $i++) {
            $old = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
            IncentiveRepository::saveOrder($old, ['associate_id' => $a, 'associate_counts' => 1, 'locked_at' => date('Y-m-d H:i:s')], $this->admin);
        }
        self::assertSame(5, AssociateCommission::countingOrders($a));
        [$big] = $this->order($productId, [['10', '1395.92']], $client, [$this->p['sales']]);
        AssociateCommission::fixLevelAtPi($big);
        $this->money($big, 83.0, '2026-10-25', 820000.0);
        $c = AssociateCommission::forOrder($big);
        self::assertSame(['Level 2 — 5%', 13959.2, 697.96, 57930.68, true], [$c['level_label'], $c['basis_fc'], $c['amount_fc'], $c['amount_inr'], $c['counts']]);
        self::assertNull(IncentiveService::lockIfReady($big, $this->admin));
        self::assertSame(6, AssociateCommission::countingOrders($a));

        [, $client2] = [0, $this->createTestClient()];
        Database::connection()->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $a, 'c' => $client2]);
        [$small] = $this->order($productId, [['2', '1395.92']], $client2, [$this->p['sales']]);
        AssociateCommission::fixLevelAtPi($small);
        $this->money($small, 83.0, '2026-10-25', 164000.0);
        $c = AssociateCommission::forOrder($small);
        self::assertSame(['Level 2 — 5%', 2791.84, 139.59, 11585.97, false], [$c['level_label'], $c['basis_fc'], $c['amount_fc'], $c['amount_inr'], $c['counts']], 'USD 139.59 (to the cent) × ₹83.00');
        self::assertNull(IncentiveService::lockIfReady($small, $this->admin));
        self::assertSame(6, AssociateCommission::countingOrders($a), 'under ₹5 lakh: the current step, not counted');
        self::assertSame(['revenue' => 1158613.6, 'cost' => 820000.0, 'before' => 338613.6, 'commission' => 57930.68, 'commission_state' => 'locked',
            'incentive' => 42102.44, 'incentive_state' => 'locked', 'after' => 238580.48], $this->profit($big));
        $this->assertReportsAddUp([$big, $small]);
    }

    public function testExample4PerOrderOverrideTicksOnePersonTwoRolesLockUnlockAndHistory(): void
    {
        $productId = $this->product();
        [$orderId] = $this->order($productId, [['10', '1326.13']], null, [$this->p['sales'], $this->p['logistics'], $this->p['marketing'], $this->p['accounts']]);
        $this->money($orderId, 83.0, '2026-10-25', 700687.9);
        self::assertSame(400000.0, $this->profit($orderId)['before']);

        self::assertSame('success', $this->call('saveOrder', $orderId, ['incentive_mode' => 'pct', 'incentive_value' => '20', 'incentive_reason' => 'Twenty per cent for this order'])['type']);
        self::assertSame(80000.0, IncentiveService::calculate($orderId)['amount']);
        self::assertSame('success', $this->call('saveOrder', $orderId, ['incentive_mode' => 'fixed', 'incentive_value' => '100000', 'incentive_reason' => 'Large first order, weekend work'])['type']);
        self::assertSame('success', $this->call('saveOrderPeople', $orderId, ['gets_it' => ['sales' => '1', 'export' => '1', 'logistics' => '1'],
            'people' => ['sales' => [$this->p['sales']], 'export' => [$this->p['sales']], 'logistics' => [$this->p['logistics']]]])['type']);
        $calc = IncentiveService::calculate($orderId);
        self::assertSame([100000.0, 50000.0, 25000.0, 25000.0, false, false], [$calc['team_total'], $calc['roles']['sales']['amount'], $calc['roles']['export']['amount'],
            $calc['roles']['logistics']['amount'], $calc['roles']['marketing']['gets_it'], $calc['roles']['accounts']['gets_it']]);
        self::assertSame(['revenue' => 1100687.9, 'cost' => 700687.9, 'before' => 400000.0, 'commission' => 0.0, 'commission_state' => null,
            'incentive' => 100000.0, 'incentive_state' => 'estimated', 'after' => 300000.0], $this->profit($orderId));

        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertSame(['logistics' => 25000.0, 'sales' => 75000.0], $this->payouts($orderId), 'one person holding Sales and Export Documentation gets both shares');
        self::assertNull(IncentiveService::unlock($orderId, $this->admin, 'Checking the shares with the team'));
        self::assertSame([], IncentiveRepository::payoutsForOrder($orderId));
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertSame(['logistics' => 25000.0, 'sales' => 75000.0], $this->payouts($orderId));
        self::assertSame(['lock', 'unlock', 'lock', 'people', 'amount', 'amount'], array_column(IncentiveRepository::history($orderId), 'action'), 'every change, newest first');
        self::assertSame(['locked', 300000.0], [$this->profit($orderId)['incentive_state'], $this->profit($orderId)['after']]);
        $this->assertReportsAddUp([$orderId]);
    }

    public function testExample5AgreedClaimLowersTheBasisUnpaidPaysNothingAndBankChargesNeverReduceIt(): void
    {
        $productId = $this->product();
        [, $client] = $this->associate('fixed', 'Level 2');
        [$claim] = $this->order($productId, [['10', '1395.92']], $client, [$this->p['sales']]);
        AssociateCommission::fixLevelAtPi($claim);
        $this->money($claim, 83.0, '2026-10-25', 820000.0);
        $pdo = Database::connection();
        // The bank kept ₹502 of the balance (D-244 "the bank deducted its charges"): never lowers the basis.
        $pdo->prepare("UPDATE order_payment_receipts SET inr_credited = inr_credited - 502, rate_gap_choice = 'bank_charges', rate_gap_inr = 502 WHERE order_id = :o AND leg = 'balance'")->execute(['o' => $claim]);
        $pdo->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                       VALUES (:o, 'balance', 8375.52, 7375.52, 1000, 'written_off', 'Buyer claim on chipped edges settled', :u, 'claim_settled')")->execute(['o' => $claim, 'u' => $this->admin]);
        $c = AssociateCommission::forOrder($claim);
        self::assertSame([1000.0, 12959.2, 647.96, 53780.68, 83.0], [$c['reduction_fc'], $c['basis_fc'], $c['amount_fc'], $c['amount_inr'], round((float) $c['rate_inr'], 4)]);

        [, $client2] = $this->associate('fixed', 'Level 2');
        [$unpaid] = $this->order($productId, [['10', '1395.92']], $client2, [$this->p['sales']]);
        AssociateCommission::fixLevelAtPi($unpaid);
        $this->money($unpaid, 83.0, '2026-10-25', 820000.0);
        $pdo->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                       VALUES (:o, 'balance', 8375.52, 7375.52, 1000, 'written_off', 'Buyer stopped paying', :u, 'unpaid')")->execute(['o' => $unpaid, 'u' => $this->admin]);
        $c = AssociateCommission::forOrder($unpaid);
        self::assertSame(['unpaid', 0.0, 0.0], [$c['state'], $c['amount_fc'], $c['amount_inr']], 'nothing unless paid in full');

        foreach ([$claim, $unpaid] as $id) {
            IncentiveService::lockIfReady($id, $this->admin);
        }
        self::assertSame(53780.68, $this->payouts($claim)['associate']);
        self::assertArrayNotHasKey('associate', $this->payouts($unpaid));
        $this->assertReportsAddUp([$claim, $unpaid]);
    }
}
