<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\MerchantTradeController;
use App\Helpers\Flash;
use App\Repositories\DivisionRepository;
use App\Repositories\MerchantTradeRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\DivisionService;
use App\Services\MerchantDocumentService;
use App\Services\MerchantTradeService as M;
use App\Services\PermissionService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the Merchant Trade (MTT)
 * division. MTT stays "planned" in the seed; this test activates it inside
 * the test only, to prove: while planned nothing changes; the margin maths
 * on two foreign-currency legs; the RBI checks (9-month deal, 4-month
 * outlay, advance limit unless LC/BG, the bank's documents) and their
 * reminders, sent once; one deal end to end (PO → BL / switch BL → sale
 * invoice → both legs paid with D-212 descriptions → bank documents →
 * closed); Export and MTT never see each other's records; the Zoho payloads
 * (mocked); the list and the board offer the same.
 * Node: merchantTrade.test.js.
 */
final class MerchantTradeTest extends DbTestCase
{
    public static function tearDownAfterClass(): void
    {
        self::clearDivisionTradeData(); // leave no division-trade records for later test classes
        parent::tearDownAfterClass();
    }

    private const MTT = 3;

    private int $md;
    private int $mttStaff;
    private int $expStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connection();
        $this->md = $this->createTestUser('Managing Director');
        // QA batch 2 (ACC-4): the merchant trade desk works both legs — a test role with Procurement Manager's
        // permissions plus the selling side (a Procurement designation sees neither the sale nor the buyer).
        $pdo->exec("INSERT IGNORE INTO roles (name, description) VALUES ('TEST-Merchant Trade Desk', 'Test only: merchant trade desk, both legs')");
        $deskRole = (int) $pdo->query("SELECT id FROM roles WHERE name = 'TEST-Merchant Trade Desk'")->fetchColumn();
        $pdo->exec("DELETE FROM role_permissions WHERE role_id = {$deskRole}");
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, is_enabled)
            SELECT {$deskRole}, rp.permission_id, rp.is_enabled FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.name = 'Procurement Manager'");
        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, is_enabled)
            SELECT {$deskRole}, p.id, 1 FROM permissions p WHERE p.permission_key IN ('view_selling_prices', 'view_buyer_payments')
              AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = {$deskRole} AND x.permission_id = p.id)");
        $this->mttStaff = $this->createTestUser('TEST-Merchant Trade Desk');
        $this->expStaff = $this->createTestUser('Procurement Manager');
        $pdo->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $this->md]);
        $pdo->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => self::MTT, 'id' => $this->mttStaff]);
        $pdo->prepare('UPDATE users SET division_id = 1 WHERE id = :id')->execute(['id' => $this->expStaff]);
        $pdo->exec("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 3 THEN 1 ELSE 0 END WHERE module_key = 'merchant_trade'");
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        $pdo->exec('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
        $pdo->exec("UPDATE division_settings SET setting_value = '500000' WHERE division_id = 3 AND setting_key = 'mtt_advance_limit_usd'");
        ZohoBooksService::setTransport(null);
        DivisionService::resetCache();
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function activate(): void
    {
        Database::connection()->prepare("UPDATE divisions SET status = 'active' WHERE id = :d")->execute(['d' => self::MTT]);
        DivisionService::resetCache();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        DivisionService::resetCache();
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    private static function daysAgo(int $n): string
    {
        return date('Y-m-d', strtotime("-{$n} days"));
    }

    /** A deal (EUR purchase from Turkey, USD sale to the UAE) with one line, as MTT staff. */
    private function deal(): int
    {
        $this->activate();
        $this->actAs($this->mttStaff);
        [$id, $error] = M::create([
            'deal_date' => self::daysAgo(40), 'supplier_name' => 'TEST-Anatolia Marble', 'supplier_country' => 'Turkey', 'supplier_address' => "Organize Sanayi 12\nAfyon",
            'purchase_currency' => 'EUR', 'purchase_incoterm' => 'FOB', 'buyer_name' => 'TEST-Gulf Stone Trading', 'buyer_country' => 'United Arab Emirates',
            'buyer_address' => "Office 1203\nDubai", 'sale_currency' => 'USD', 'sale_incoterm' => 'CFR', 'origin_country' => 'Turkey',
            'port_of_loading' => 'Izmir', 'port_of_discharge' => 'Jebel Ali',
        ], $this->mttStaff);
        self::assertNull($error);
        self::assertNull(M::addLine((int) $id, ['description' => 'White marble slabs, 2 cm', 'hsn_code' => '68022100', 'quantity' => '500', 'unit' => 'sqm', 'buy_price_fc' => '32.00', 'sell_price_fc' => '38.50'], $this->mttStaff));
        return (int) $id;
    }

    public function testMarginOnTwoCurrencyLegsAndTheDates(): void
    {
        $deal = ['sale_currency' => 'USD', 'purchase_currency' => 'EUR', 'stage' => 'shipped', 'closed_on' => null, 'advance_backing' => 'none', 'backing_ref' => null];
        $lines = [['quantity' => '500', 'buy_price_fc' => '32.00', 'sell_price_fc' => '38.50']];
        $pay = [['leg' => 'buy', 'moved_on' => '2026-01-10', 'amount_fc' => '16000.00', 'amount_inr' => '1459200.00'], ['leg' => 'sell', 'moved_on' => '2026-02-01', 'amount_fc' => '19250.00', 'amount_inr' => '1618925.00']];
        $f = M::money($deal, $lines, $pay, '84.0000');
        // ₹16,18,925.00 received − ₹14,59,200.00 paid = ₹1,59,725.00; in USD at the sell leg's realised 84.10 = 1,899.23; 9.87 % of the ₹ received.
        self::assertSame([1925000, 1600000, '84.1000', '91.2000', '84.1000', '159725.00', 189923, '9.87', null, true],
            [$f['sale_cents'], $f['purchase_cents'], $f['sell_rate'], $f['buy_rate'], $f['usd_rate'], $f['margin_inr'], $f['margin_usd_cents'], $f['margin_pct'], $f['contract_margin_cents'], $f['final']]);
        // Neither leg in USD: the USD figure uses the MTT setting mtt_usd_inr_rate.
        self::assertSame(['84.0000', 190149], array_values(array_intersect_key(M::money(['sale_currency' => 'EUR', 'purchase_currency' => 'GBP'] + $deal, $lines, $pay, '84.0000'), ['usd_rate' => 1, 'margin_usd_cents' => 1])));
        // Same currency on both legs: the contract margin in that currency too.
        self::assertSame(325000, M::money(['sale_currency' => 'USD', 'purchase_currency' => 'USD'] + $deal, $lines, [], '84')['contract_margin_cents']);
        self::assertFalse(M::money($deal, $lines, [$pay[0]], '84')['final']);
        // Months: the day is kept, or cut to the month's last day.
        self::assertSame(['2026-02-28', '2024-02-29', '2027-02-28', '2026-12-15'], [M::addMonths('2026-01-31', 1), M::addMonths('2024-01-31', 1), M::addMonths('2026-11-30', 3), M::addMonths('2026-03-15', 9)]);
        self::assertSame([['from' => '2026-01-10', 'to' => '2026-03-01'], ['from' => '2026-03-05', 'to' => null]], M::outlayPeriods([
            ['leg' => 'buy', 'moved_on' => '2026-01-10', 'amount_inr' => '100'], ['leg' => 'sell', 'moved_on' => '2026-02-01', 'amount_inr' => '50'],
            ['leg' => 'sell', 'moved_on' => '2026-03-01', 'amount_inr' => '60'], ['leg' => 'buy', 'moved_on' => '2026-03-05', 'amount_inr' => '20'],
        ]));
    }

    public function testTheRbiChecks(): void
    {
        $deal = ['sale_currency' => 'USD', 'purchase_currency' => 'EUR', 'stage' => 'shipped', 'closed_on' => null, 'advance_backing' => 'none', 'backing_ref' => null];
        $s = ['mtt_max_months_total' => '9', 'mtt_max_outlay_months' => '4', 'mtt_advance_limit_usd' => '500000', 'mtt_usd_inr_rate' => '84.0000'];
        $docs = ['a' => 'Bill of lading', 'b' => 'Our sale invoice'];
        $p = [['leg' => 'buy', 'moved_on' => '2026-01-10', 'amount_fc' => '1000.00', 'amount_inr' => '91200.00'], ['leg' => 'sell', 'moved_on' => '2026-06-01', 'amount_fc' => '2000.00', 'amount_inr' => '168200.00']];
        $view = static fn (array $checks): array => array_map(static fn (array $c): array => [$c['key'], $c['level'], $c['text']], $checks);
        self::assertSame([
            ['total', 'ok', 'Finish by 10 Oct 2026 (112 days left).'],
            ['outlay', 'breach', 'Outlay from 10 Jan 2026 to 01 Jun 2026 was longer than the limit (10 May 2026). Report it to the AD bank.'],
            ['advance', 'ok', 'Advance USD 1,085.71, within the limit.'],
            ['documents', 'info', '1 of 2 with the bank. Missing: Our sale invoice.'],
        ], $view(M::rbiChecks($deal, $p, $docs, ['a' => '2026-06-01'], $s, '2026-06-20')));
        self::assertSame([
            ['total', 'warn', 'Due soon: finish by 10 Oct 2026 (20 days left).'],
            ['outlay', 'breach', 'Overdue: money paid to the supplier since 10 Jan 2026 had to be covered by the buyer by 10 May 2026. Report it to the AD bank.'],
        ], array_slice($view(M::rbiChecks($deal, [$p[0]], $docs, [], $s, '2026-09-20')), 0, 2));
        self::assertSame(['info', 'info', 'ok'], array_column(array_slice(M::rbiChecks($deal, [], $docs, [], $s, '2026-09-20'), 0, 3), 'level'));
        $closed = M::rbiChecks(['stage' => 'closed', 'closed_on' => '2026-11-01', 'advance_backing' => 'lc', 'backing_ref' => 'LC123'] + $deal, $p, $docs, ['a' => '2026-06-01', 'b' => '2026-06-02'], ['mtt_advance_limit_usd' => '1000'] + $s, '2026-11-02');
        self::assertSame([
            ['total', 'breach', 'Finished on 01 Nov 2026, after the limit (10 Oct 2026). Report it to the AD bank.'],
            ['advance', 'ok', 'Advance USD 1,085.71 backed by letter of credit LC123.'],
            ['documents', 'ok', 'All 2 documents are with the bank.'],
        ], [$view($closed)[0], $view($closed)[2], $view($closed)[3]]);
        self::assertSame(['advance', 'breach', 'Advance USD 1,085.71 is above the limit and not backed by an LC or bank guarantee. Report it to the AD bank.'],
            $view(M::rbiChecks($deal, $p, $docs, [], ['mtt_advance_limit_usd' => '1000'] + $s, '2026-06-20'))[2]);
        self::assertSame('breach', M::worstLevel(M::rbiChecks($deal, $p, $docs, [], $s, '2026-06-20')));
    }

    public function testWhileMerchantTradeIsPlannedNothingChanges(): void
    {
        self::assertFalse(M::isActive());
        $this->actAs($this->md);
        [$id, $error] = M::create(['deal_date' => date('Y-m-d')], $this->md);
        self::assertNull($id);
        self::assertStringContainsString('not active', (string) $error);
        self::assertNotNull(DivisionService::disabledModuleForPath('/merchant'));
        self::assertNotNull(DivisionService::disabledModuleForPath('/merchant/reports'));
        self::assertNull(DivisionService::disabledModuleForPath('/orders'));
        $layout = $this->render(fn () => \App\Helpers\View::render('merchant/create', ['active' => false, 'old' => []], 'layout/base'));
        self::assertStringNotContainsString('href="/merchant"', $layout, 'no Merchant trade menu entry');
        self::assertSame(0, M::runAlerts(), 'no deals, no reminders');
        $flags = array_column(DivisionRepository::preflight(self::MTT), 'ok', 'label');
        self::assertFalse($flags['AD bank\'s merchanting approval recorded'], 'the AD bank approval starts empty');
        self::assertTrue($flags['RBI limits and the bank\'s document list set (confirm them with the AD bank and the CA)'], 'seeded');
        self::assertArrayHasKey('Merchant trade module on; export-only modules off', $flags);
        self::assertSame(['MTT_TRADE', 'MTT_DOCS'], array_column(DivisionRepository::STARTER_DEPARTMENTS['MTT'], 0));
        self::assertCount(7, M::bankDocuments());
    }

    public function testOneDealEndToEnd(): void
    {
        $id = $this->deal();
        $d = MerchantTradeRepository::find($id);
        self::assertSame(self::MTT, (int) $d['division_id']);
        self::assertMatchesRegularExpression('#^MTT/\d{2}-\d{2}/\d{4}$#', (string) $d['reference']);
        self::assertMatchesRegularExpression('#^MTT/PO/\d{2}-\d{2}/\d{4}$#', (string) $d['po_no']);
        [, $error] = M::headerFields(['deal_date' => date('Y-m-d'), 'supplier_name' => 'X', 'supplier_country' => 'India', 'buyer_name' => 'Y', 'buyer_country' => 'UAE', 'origin_country' => 'India']);
        self::assertStringContainsString('both the supplier and the buyer are abroad', (string) $error);
        [, $error] = M::headerFields(['deal_date' => date('Y-m-d'), 'supplier_name' => 'X', 'supplier_country' => 'Turkey', 'buyer_name' => 'Y', 'buyer_country' => 'UAE', 'origin_country' => 'india']);
        self::assertSame('Goods made in India and shipped abroad are an export, not a merchant trade.', $error);

        self::assertSame(['Generate the purchase order to the supplier.', 'Record the bill of lading (BL).'], M::blockers($id));
        $po = MerchantDocumentService::html($id, 'po');
        self::assertStringContainsString('PURCHASE ORDER', $po);
        self::assertStringContainsString('EUR 16,000.00', $po);
        self::assertStringContainsString((string) $d['po_no'], $po);
        self::assertSame('Record the BL first: the sale invoice goes with the shipping documents.', M::fixInvoice($id, $this->mttStaff));

        // Buy leg: an advance before the buyer has paid (D-212 wording); above the advance limit only with an LC / BG.
        self::assertNull(M::recordPayment($id, ['leg' => 'buy', 'moved_on' => self::daysAgo(35), 'amount_fc' => '8000', 'fx_rate' => '91.20', 'bank_reference' => "SWIFT-MTT-A-{$id}"], $this->mttStaff));
        self::assertSame("Merchant trade supplier advance of EUR 8,000.00 (₹7,29,600.00 at 91.20) to TEST-Anatolia Marble (Turkey) against merchant trade deal {$d['reference']}, PO {$d['po_no']}, SWIFT/UTR ref SWIFT-MTT-A-{$id}",
            MerchantTradeRepository::payments($id)[0]['description']);
        // D-262: the deal keeps the RBI settings it was made under, so the lower limit is set on the deal's own copy.
        Database::connection()->prepare("UPDATE merchant_deals SET rbi_settings_json = JSON_SET(rbi_settings_json, '$.mtt_advance_limit_usd', '10000') WHERE id = :id")->execute(['id' => $id]);
        $second = ['leg' => 'buy', 'moved_on' => self::daysAgo(34), 'amount_fc' => '8000', 'fx_rate' => '91.20', 'bank_reference' => "SWIFT-MTT-B-{$id}"];
        self::assertSame('This would make the advance to the supplier USD 17,371.42, above the USD 10,000.00 limit for an advance not backed by an LC or bank guarantee (RBI merchanting rule, to be confirmed with the AD bank). Record the LC or bank guarantee first.',
            M::recordPayment($id, $second, $this->mttStaff));
        self::assertSame('Enter the LC or bank guarantee number.', M::saveBacking($id, ['advance_backing' => 'lc'], $this->mttStaff));
        self::assertNull(M::saveBacking($id, ['advance_backing' => 'lc', 'backing_ref' => 'SBLC-77'], $this->mttStaff));
        self::assertNull(M::recordPayment($id, $second, $this->mttStaff));
        self::assertStringContainsString(', backed by letter of credit SBLC-77, SWIFT/UTR ref', MerchantTradeRepository::payments($id)[1]['description']);
        self::assertSame('This is more than the supplier is still owed (EUR 0.00).', M::recordPayment($id, ['leg' => 'buy', 'moved_on' => self::daysAgo(1), 'amount_fc' => '1', 'fx_rate' => '91', 'bank_reference' => "X-{$id}"], $this->mttStaff));
        self::assertSame('This bank reference is already recorded. Check the payment is not entered twice.', M::recordPayment($id, ['leg' => 'sell', 'moved_on' => self::daysAgo(1), 'amount_fc' => '1', 'fx_rate' => '84', 'bank_reference' => "SWIFT-MTT-A-{$id}"], $this->mttStaff));

        // The BL with a switch BL; the PO generated → shipped.
        self::assertSame('Enter the number of the switch BL.', M::saveShipment($id, ['bl_no' => 'MSCUIZ1234567', 'bl_date' => self::daysAgo(20), 'switch_bl' => '1'], $this->mttStaff));
        self::assertNull(M::saveShipment($id, ['bl_no' => 'MSCUIZ1234567', 'bl_date' => self::daysAgo(20), 'vessel' => 'MSC Aurora', 'switch_bl' => '1', 'switch_bl_no' => 'MSCUIZ1234567-S', 'switch_bl_ref' => 'MSC Dubai agent; SBI letter MT/118'], $this->mttStaff));
        M::markPoGenerated($id, $this->mttStaff);
        self::assertNull(M::advance($id, 'contracted', $this->mttStaff));

        // The sale invoice (its number fixed once) shows the switch BL; then the buyer pays.
        self::assertSame(['Generate the sale invoice to the buyer.', 'Still to receive from the buyer: USD 19,250.00.'], M::blockers($id));
        self::assertNull(M::fixInvoice($id, $this->mttStaff));
        $d = MerchantTradeRepository::find($id);
        self::assertMatchesRegularExpression('#^MTT/I/\d{2}-\d{2}/\d{4}$#', (string) $d['sale_invoice_no']);
        self::assertNull(M::fixInvoice($id, $this->mttStaff));
        self::assertSame($d['sale_invoice_no'], MerchantTradeRepository::find($id)['sale_invoice_no'], 'the number is fixed once');
        $inv = MerchantDocumentService::html($id, 'invoice');
        self::assertStringContainsString('INVOICE', $inv);
        self::assertStringContainsString('USD 19,250.00', $inv);
        self::assertStringContainsString('MSCUIZ1234567-S', $inv);
        self::assertStringContainsString('Nineteen Thousand Two Hundred Fifty', $inv);
        self::assertStringContainsString('they do not enter India', $inv);
        self::assertNull(M::recordPayment($id, ['leg' => 'sell', 'moved_on' => self::daysAgo(5), 'amount_fc' => '19250', 'fx_rate' => '84.10', 'bank_reference' => "SWIFT-MTT-S-{$id}"], $this->mttStaff));
        self::assertSame("Merchant trade sale receipt of USD 19,250.00 (₹16,18,925.00 at 84.10) from TEST-Gulf Stone Trading (United Arab Emirates) against merchant trade deal {$d['reference']}, invoice {$d['sale_invoice_no']}, SWIFT/UTR ref SWIFT-MTT-S-{$id}",
            MerchantTradeRepository::payments($id)[2]['description']);
        $f = M::figures($d);
        self::assertSame(['159725.00', 189923, '9.87', true], [$f['margin_inr'], $f['margin_usd_cents'], $f['margin_pct'], $f['final']]);
        self::assertNull(M::advance($id, 'shipped', $this->mttStaff));

        // Settled → closed once the AD bank has every document on its checklist.
        self::assertStringStartsWith('Documents still to give the AD bank: ', M::blockers($id)[0]);
        foreach (array_keys(M::bankDocuments()) as $key) {
            self::assertNull(M::saveDocument($id, $key, self::daysAgo(1), $this->mttStaff));
        }
        self::assertSame('Choose a document from the bank\'s checklist.', M::saveDocument($id, 'not-on-the-list', self::daysAgo(1), $this->mttStaff));
        self::assertNull(M::advance($id, 'settled', $this->mttStaff));
        $d = MerchantTradeRepository::find($id);
        self::assertSame(['closed', date('Y-m-d')], [$d['stage'], $d['closed_on']]);
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column(M::checksFor($d), 'level'));
        self::assertSame('The deal is closed.', M::recordPayment($id, ['leg' => 'sell'], $this->mttStaff));

        // Reports: margin per deal, RBI status.
        $page = $this->render(fn () => (new MerchantTradeController())->reports([]));
        self::assertStringContainsString((string) $d['reference'], $page);
        self::assertStringContainsString('1,59,725.00', $page);
        self::assertStringContainsString('1,899.23', $page);
    }

    public function testRemindersGoOutOnceAndOnlyToTheDivision(): void
    {
        Database::connection()->exec("UPDATE merchant_deals SET stage = 'closed'"); // only this test's deal is open
        $this->activate();
        $this->actAs($this->mttStaff);
        [$id] = M::create([
            'deal_date' => self::daysAgo(130), 'supplier_name' => 'TEST-Supplier', 'supplier_country' => 'Vietnam', 'purchase_currency' => 'USD',
            'buyer_name' => 'TEST-Buyer', 'buyer_country' => 'Kenya', 'sale_currency' => 'USD', 'origin_country' => 'Vietnam',
        ], $this->mttStaff);
        self::assertNull(M::addLine((int) $id, ['description' => 'Granite', 'hsn_code' => '6802', 'quantity' => '10', 'unit' => 'MT', 'buy_price_fc' => '100', 'sell_price_fc' => '120'], $this->mttStaff));
        self::assertNull(M::recordPayment((int) $id, ['leg' => 'buy', 'moved_on' => self::daysAgo(125), 'amount_fc' => '500', 'fx_rate' => '84', 'bank_reference' => "REM-{$id}"], $this->mttStaff));
        $count = static fn (int $user): int => (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE type = 'merchant_trade_rbi' AND user_id = {$user}")->fetchColumn();
        self::assertGreaterThan(0, M::runAlerts());
        self::assertSame(1, $count($this->mttStaff), 'the outlay is past 4 months');
        self::assertSame(1, $count($this->md), 'company level');
        self::assertSame(0, $count($this->expStaff), 'Export staff are not told');
        $msg = (string) Database::connection()->query("SELECT message FROM notifications WHERE type = 'merchant_trade_rbi' AND user_id = {$this->mttStaff}")->fetchColumn();
        self::assertStringContainsString('Outlay within 4 months — Overdue', $msg);
        self::assertSame(0, M::runAlerts(), 'never sent twice');
    }

    public function testExportAndMerchantTradeNeverSeeEachOther(): void
    {
        $id = $this->deal();
        $this->actAs($this->expStaff);
        self::assertNotNull(DivisionService::disabledModuleForPath('/merchant'));
        self::assertSame('Deal not found.', $this->render(fn () => (new MerchantTradeController())->show(['id' => (string) $id])));
        $this->actAs($this->mttStaff);
        self::assertNull(DivisionService::disabledModuleForPath('/merchant'));
        self::assertStringContainsString('TEST-Gulf Stone Trading', $this->render(fn () => (new MerchantTradeController())->show(['id' => (string) $id])));
        $this->actAs($this->md);
        $page = $this->render(fn () => (new MerchantTradeController())->show(['id' => (string) $id]));
        self::assertStringContainsString('TEST-Gulf Stone Trading', $page);
        self::assertStringContainsString('href="/merchant"', $page, 'the menu entry shows where the module is on');
    }

    public function testZohoPayloadsForADeal(): void
    {
        $id = $this->deal();
        self::assertNull(M::recordPayment($id, ['leg' => 'buy', 'moved_on' => self::daysAgo(30), 'amount_fc' => '16000', 'fx_rate' => '91.20', 'bank_reference' => "Z-B-{$id}"], $this->mttStaff));
        self::assertNull(M::saveShipment($id, ['bl_no' => 'MSCUIZ7654321', 'bl_date' => self::daysAgo(20)], $this->mttStaff));
        self::assertNull(M::fixInvoice($id, $this->mttStaff));
        self::assertNull(M::recordPayment($id, ['leg' => 'sell', 'moved_on' => self::daysAgo(5), 'amount_fc' => '19250', 'fx_rate' => '84.10', 'bank_reference' => "Z-S-{$id}"], $this->mttStaff));
        $d = MerchantTradeRepository::find($id);
        $acc = static fn (string $name): string => 'acc:' . $name;
        $inv = ZohoBooksService::merchantInvoicePayload($d, MerchantTradeRepository::lines($id), 'C1', $acc);
        self::assertSame(['C1', $d['sale_invoice_no'], $d['reference'], 'USD', 'overseas', 84.1], [$inv['customer_id'], $inv['invoice_number'], $inv['reference_number'], $inv['currency_code'], $inv['gst_treatment'], $inv['exchange_rate']]);
        self::assertSame([500.0, 38.5, 'acc:Merchanting Trade Sales', '68022100'], [$inv['line_items'][0]['quantity'], $inv['line_items'][0]['rate'], $inv['line_items'][0]['account_id'], $inv['line_items'][0]['hsn_or_sac']]);
        self::assertSame("Merchant trade sale invoice {$d['sale_invoice_no']} for deal {$d['reference']} to TEST-Gulf Stone Trading (United Arab Emirates): goods from Turkey shipped directly to the buyer, BL MSCUIZ7654321; they do not enter India.", $inv['notes']);
        self::assertArrayNotHasKey('tags', $inv);
        $bill = ZohoBooksService::merchantBillPayload($d, MerchantTradeRepository::lines($id), 'V1', $acc);
        self::assertSame(['V1', $d['po_no'], 'EUR', 91.2, 32.0, 'acc:Merchanting Trade Purchases'], [$bill['vendor_id'], $bill['bill_number'], $bill['currency_code'], $bill['exchange_rate'], $bill['line_items'][0]['rate'], $bill['line_items'][0]['account_id']]);
        self::assertSame("Merchant trade purchase from TEST-Anatolia Marble (Turkey) against PO {$d['po_no']}, deal {$d['reference']}, BL MSCUIZ7654321; goods shipped directly to the buyer abroad.", $bill['notes']);
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO company_settings (setting_key, setting_value, value_type, category, description) VALUES ('zoho_division_tag_id', 'T1', 'string', 'company', 't'), ('zoho_division_tag_option_mtt', 'OPT-MTT', 'string', 'company', 't') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        self::assertSame([['tag_id' => 'T1', 'tag_option_id' => 'OPT-MTT']], ZohoBooksService::merchantBillPayload($d, MerchantTradeRepository::lines($id), 'V1', $acc)['tags']);
        $pdo->exec("DELETE FROM company_settings WHERE setting_key IN ('zoho_division_tag_id', 'zoho_division_tag_option_mtt')");
    }

    public function testTheListAndTheBoardOfferTheSame(): void
    {
        $id = $this->deal();
        UserRepository::updateUiTemplatePreference($this->mttStaff, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $this->actAs($this->mttStaff);
        $_GET = [];
        $board = $this->render(fn () => (new MerchantTradeController())->index([]));
        $_GET = ['view' => 'list'];
        $list = $this->render(fn () => (new MerchantTradeController())->index([]));
        foreach ([$board, $list] as $page) {
            self::assertStringContainsString('href="/merchant/' . $id . '"', $page);
            self::assertStringContainsString('href="/merchant/create"', $page);
            self::assertStringContainsString('href="/merchant/reports"', $page);
            self::assertStringContainsString('name="stage"', $page);
            self::assertStringContainsString('TEST-Gulf Stone Trading', $page);
            self::assertStringContainsString('Buy EUR 16,000.00', $page);
            self::assertStringContainsString('Sell USD 19,250.00', $page);
            self::assertStringContainsString('RBI: on track', $page);
        }
        self::assertStringContainsString('kanban-board', $board);
        self::assertStringContainsString('Switch to list view', $board);
        self::assertStringContainsString('Switch to board view', $list);
        $_GET = ['view' => 'list', 'stage' => 'closed'];
        self::assertStringNotContainsString('href="/merchant/' . $id . '"', $this->render(fn () => (new MerchantTradeController())->index([])), 'the stage filter works');
    }
}
