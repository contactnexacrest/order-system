<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ImportController;
use App\Helpers\Flash;
use App\Repositories\DivisionRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\ImportRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\DivisionService;
use App\Services\DomesticDocumentService;
use App\Services\DomesticOrderService;
use App\Services\ImportDocumentService;
use App\Services\ImportService;
use App\Services\PermissionService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the Import & Distribution (IMP)
 * division. IMP stays "planned" in the seed; this test activates it inside
 * the test only, to prove: while planned nothing changes; the landed-cost and
 * duty maths (assessable value, basic duty, SWS, IGST as credit, charges
 * spread by value, paise rounding); one import end to end (PO → BL → bill of
 * entry → landed cost frozen → stock → supplier paid with D-212 descriptions
 * → IGST credit → closed) and a local sale out of that stock under IMP's own
 * numbers; stock never goes below zero; Export and IMP never see each
 * other's records; the Zoho payloads (mocked); the list and the board offer
 * the same. Node: importDistribution.test.js.
 */
final class ImportDistributionTest extends DbTestCase
{
    public static function tearDownAfterClass(): void
    {
        self::clearDivisionTradeData(); // leave no division-trade records for later test classes
        parent::tearDownAfterClass();
    }

    private const IMP = 4;
    private const BUYER_GSTIN = '29AAPFU0939F1ZR';

    private int $md;
    private int $impStaff;
    private int $expStaff;
    private int $client;
    private int $productA;
    private int $productB;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connection();
        $this->md = $this->createTestUser('Managing Director');
        $this->impStaff = $this->createTestUser('Procurement Manager');
        $this->expStaff = $this->createTestUser('Procurement Manager');
        $pdo->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $this->md]);
        $pdo->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => self::IMP, 'id' => $this->impStaff]);
        $pdo->prepare('UPDATE users SET division_id = 1 WHERE id = :id')->execute(['id' => $this->expStaff]);
        $pdo->exec("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 4 THEN 1 ELSE 0 END WHERE module_key = 'import_stock'");
        $this->client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET company_legal_name = 'Bengaluru Stone Traders', billing_address = :a WHERE id = :id")->execute(['a' => "12 MG Road\nBengaluru 560001", 'id' => $this->client]);
        $code = 'IMPT-' . bin2hex(random_bytes(3));
        $pdo->prepare("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'Imported black granite slab', '68029300')")->execute(['c' => $code . 'A']);
        $this->productA = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'Imported quartz worktop', '68101990')")->execute(['c' => $code . 'B']);
        $this->productB = (int) $pdo->lastInsertId();
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        $pdo->exec('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
        $pdo->prepare('DELETE FROM client_divisions WHERE client_id = :c AND division_id <> 1')->execute(['c' => $this->client]);
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
        Database::connection()->prepare("UPDATE divisions SET status = 'active' WHERE id = :d")->execute(['d' => self::IMP]);
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

    /** An import with two lines (USD), as IMP staff. */
    private function import(): int
    {
        $this->activate();
        $this->actAs($this->impStaff);
        [$id, $error] = ImportService::create([
            'supplier_name' => 'TEST-Xiamen Stone Industries', 'supplier_country' => 'China', 'supplier_address' => "No. 8 Shuitou Road\nNan'an, Fujian",
            'currency_code' => 'USD', 'incoterm' => 'CIF', 'po_date' => date('Y-m-d', strtotime('-20 days')), 'payment_terms' => '30% advance, balance against BL',
        ], $this->impStaff);
        self::assertNull($error);
        self::assertNull(ImportService::addLine((int) $id, ['product_id' => $this->productA, 'quantity' => '100', 'unit' => 'sqm', 'unit_price_fc' => '18.50', 'bcd_pct' => '20', 'sws_pct' => '10', 'igst_pct' => '18'], $this->impStaff));
        self::assertNull(ImportService::addLine((int) $id, ['product_id' => $this->productB, 'description' => 'Quartz worktops', 'quantity' => '3', 'unit' => 'PCS', 'unit_price_fc' => '333.33', 'bcd_pct' => '10', 'sws_pct' => '10', 'igst_pct' => '18'], $this->impStaff));
        return (int) $id;
    }

    /** Takes an import to "In stock" (cleared at 84.65 with charges). */
    private function receive(int $id): void
    {
        ImportService::markPoGenerated($id, $this->impStaff);
        self::assertNull(ImportService::saveShipment($id, ['bl_no' => 'COSU6234567890', 'bl_date' => date('Y-m-d', strtotime('-15 days')), 'vessel' => 'COSCO Leo', 'port_of_loading' => 'Xiamen'], $this->impStaff));
        self::assertNull(ImportService::advance($id, 'ordered', $this->impStaff));
        self::assertNull(ImportService::saveClearance($id, [
            'boe_no' => '4567891', 'boe_date' => date('Y-m-d', strtotime('-3 days')), 'port_code' => 'innsa1', 'cha_name' => 'Speedways Clearing',
            'customs_fx_rate' => '84.65', 'clearance_date' => date('Y-m-d', strtotime('-2 days')),
            'freight_inr' => '10000', 'insurance_inr' => '1500.50', 'cha_inr' => '12000', 'port_inr' => '8000.25', 'other_inr' => '',
        ], $this->impStaff));
        self::assertNull(ImportService::advance($id, 'shipped', $this->impStaff));
        self::assertNull(ImportService::advance($id, 'cleared', $this->impStaff));
    }

    public function testLandedCostAndDutyMaths(): void
    {
        $f = ImportService::compute([
            ['quantity' => '100', 'unit_price_fc' => '18.50', 'bcd_pct' => '20', 'sws_pct' => '10', 'igst_pct' => '18'],
            ['quantity' => '3', 'unit_price_fc' => '333.33', 'bcd_pct' => '10', 'sws_pct' => '10', 'igst_pct' => '18'],
        ], '84.65', ['freight_inr' => '10000', 'insurance_inr' => '1500.50', 'cha_inr' => '12000', 'port_inr' => '8000.25', 'other_inr' => '0']);
        $a = $f['lines'][0];
        // 1850.00 USD × 84.65 = 156602.50; freight and insurance spread by value make the assessable value.
        self::assertSame(['1850.00', '156602.50', '6491.25', '974.01', '164067.76'], [$a['value_fc'], $a['value_inr'], $a['freight_share'], $a['insurance_share'], $a['assessable_inr']]);
        // BCD 20 % = 32813.552 → 32813.55; SWS 10 % of the duty = 3281.355 → 3281.36 (half away from zero); IGST 18 % of 200162.67.
        self::assertSame(['32813.55', '3281.36', '36029.28'], [$a['bcd_inr'], $a['sws_inr'], $a['igst_inr']]);
        // Landed = assessable + duty + SWS + its share of CHA, port and other charges; IGST (input credit) is not a cost.
        self::assertSame(['12982.66', '213145.33', '2131.4533'], [$a['charges_share'], $a['landed_inr'], $a['landed_unit_inr']]);
        $b = $f['lines'][1];
        // 3 × 333.33 = 999.99 USD × 84.65 = 84649.1535 → 84649.15; the last line takes what rounding leaves.
        self::assertSame(['999.99', '84649.15', '3508.75', '526.49', '88684.39', '8868.44', '886.84', '17719.14', '7017.59', '105457.26', '35152.4200'],
            [$b['value_fc'], $b['value_inr'], $b['freight_share'], $b['insurance_share'], $b['assessable_inr'], $b['bcd_inr'], $b['sws_inr'], $b['igst_inr'], $b['charges_share'], $b['landed_inr'], $b['landed_unit_inr']]);
        self::assertSame(['2849.99', '241251.65', '252752.15', '41681.99', '4168.20', '45850.19', '53748.42', '20000.25', '318602.59'],
            [$f['value_total_fc'], $f['value_total_inr'], $f['assessable_total'], $f['bcd_total'], $f['sws_total'], $f['duty_total'], $f['igst_total'], $f['charges_total'], $f['landed_total']]);
        // Shares always add up exactly.
        self::assertSame([1, 2, 7], ImportService::spread(10, [1, 2, 7]));
        self::assertSame([33, 33, 34], ImportService::spread(100, [1, 1, 1]));
        // Suggested selling price = landed per unit ÷ (1 − 20 %): 2131.4533 / 0.8 = 2664.3166… → 2664.32.
        self::assertSame('2664.32', ImportService::suggestedPrice('2131.4533', '20'));
    }

    public function testWhileImportIsPlannedNothingChanges(): void
    {
        self::assertFalse(ImportService::isActive());
        $this->actAs($this->md);
        [$id, $error] = ImportService::create(['supplier_name' => 'X', 'supplier_country' => 'China', 'currency_code' => 'USD', 'po_date' => date('Y-m-d')], $this->md);
        self::assertNull($id);
        self::assertStringContainsString('not active', (string) $error);
        // The module is on for IMP only, so with IMP planned nobody reaches /import and no menu shows it.
        self::assertNotNull(DivisionService::disabledModuleForPath('/import'));
        self::assertNotNull(DivisionService::disabledModuleForPath('/import/reports'));
        self::assertNull(DivisionService::disabledModuleForPath('/orders'));
        $layout = $this->render(fn () => \App\Helpers\View::render('import/duty_rates', ['rates' => [], 'defaults' => ['bcd' => '10', 'sws' => '10', 'igst' => '18']], 'layout/base'));
        self::assertStringNotContainsString('href="/import"', $layout, 'no Imports menu entry');
        // The local sale is refused too.
        [$sale, $error] = DomesticOrderService::create(['client_id' => $this->client, 'buyer_state_code' => '29', 'place_of_supply' => '29'], $this->md, 'IMP');
        self::assertNull($sale);
        self::assertStringContainsString('Import & Distribution is not active', (string) $error);
        // Admin › Divisions: Import & Distribution's pre-flight lists what it needs.
        $labels = array_column(DivisionRepository::preflight(self::IMP), 'label');
        self::assertContains('NexaCrest\'s IEC is set (the importer of record)', $labels);
        self::assertContains('AD bank for import LCs set', $labels);
        self::assertContains('Customs agent (CHA) set', $labels);
        self::assertContains('Import duty rates set (confirm them with the CA and the customs agent)', $labels);
        $flags = array_column(DivisionRepository::preflight(self::IMP), 'ok', 'label');
        self::assertTrue($flags['Import duty rates set (confirm them with the CA and the customs agent)'], 'HSN 6802 is seeded');
        self::assertFalse($flags['AD bank for import LCs set'], 'the LC bank starts empty');
        self::assertSame(['IMP_PURCHASE', 'IMP_WAREHOUSE', 'IMP_SALES'], array_column(DivisionRepository::STARTER_DEPARTMENTS['IMP'], 0));
    }

    public function testOneImportEndToEndThenALocalSaleFromStock(): void
    {
        $id = $this->import();
        $s = ImportRepository::find($id);
        self::assertSame(self::IMP, (int) $s['division_id']);
        self::assertMatchesRegularExpression('#^IMP/PO/\d{2}-\d{2}/\d{4}$#', (string) $s['reference']);
        self::assertSame('Imported black granite slab', ImportRepository::lines($id)[0]['description'], 'blank description = the product name');
        self::assertSame('68029300', ImportRepository::lines($id)[0]['hsn_code'], 'blank HSN = the product HS code');
        self::assertSame('Choose the product (stock is kept per product).', ImportService::addLine($id, ['quantity' => '1', 'unit' => 'PCS', 'unit_price_fc' => '1'], $this->impStaff));

        // Ordered: the PO, then the BL.
        self::assertSame(['Generate the import PO.', 'Record the bill of lading (BL).'], ImportService::blockers($id));
        $html = ImportDocumentService::html($id);
        self::assertStringContainsString('PURCHASE ORDER', $html);
        self::assertStringContainsString('USD 2,849.99', $html);
        self::assertStringContainsString('1,850.00', $html);

        // Payments abroad (D-212): what, to whom, which PO, the rate and the SWIFT/UTR reference.
        self::assertNull(ImportService::recordPayment($id, ['kind' => 'advance', 'paid_on' => date('Y-m-d', strtotime('-18 days')), 'amount_fc' => '1000', 'fx_rate' => '84.92', 'bank_reference' => 'SWIFT-ADV-' . $id], $this->impStaff));
        $p = ImportRepository::payments($id)[0];
        self::assertSame("Import supplier advance of USD 1,000.00 (₹84,920.00 at 84.92) to TEST-Xiamen Stone Industries (China) against import PO {$s['reference']}, SWIFT/UTR ref SWIFT-ADV-{$id}", $p['description']);
        self::assertSame('84920.00', $p['amount_inr']);
        self::assertSame('This bank reference is already recorded. Check the payment is not entered twice.', ImportService::recordPayment($id, ['kind' => 'balance', 'paid_on' => date('Y-m-d'), 'amount_fc' => '1', 'fx_rate' => '84', 'bank_reference' => 'SWIFT-ADV-' . $id], $this->impStaff));
        self::assertStringContainsString('more than the PO still owes (USD 1,849.99)', (string) ImportService::recordPayment($id, ['kind' => 'balance', 'paid_on' => date('Y-m-d'), 'amount_fc' => '2000', 'fx_rate' => '84', 'bank_reference' => 'X-' . $id], $this->impStaff));
        self::assertSame('Record the LC first.', ImportService::recordPayment($id, ['kind' => 'lc', 'paid_on' => date('Y-m-d'), 'amount_fc' => '1', 'fx_rate' => '84', 'bank_reference' => 'Y-' . $id], $this->impStaff));
        self::assertNull(ImportService::saveLc($id, ['lc_no' => '0123ILC000456', 'lc_bank' => 'State Bank of India', 'lc_amount_fc' => '1849.99', 'lc_expiry' => date('Y-m-d', strtotime('+60 days')), 'lc_margin_inr' => '15000'], $this->impStaff));

        // Shipped → cleared: the duty and landed cost are worked out and frozen; → in stock.
        self::assertSame('Customs clearance is recorded once the goods are shipped and before they are cleared.', ImportService::saveClearance($id, [], $this->impStaff));
        $this->receive($id);
        $s = ImportRepository::find($id);
        self::assertSame('received', $s['stage']);
        self::assertSame(['252752.15', '41681.99', '4168.20', '53748.42', '318602.59'], [$s['assessable_total'], $s['bcd_total'], $s['sws_total'], $s['igst_total'], $s['landed_total']]);
        self::assertSame('INNSA1', $s['port_code']);
        $lines = ImportRepository::lines($id);
        self::assertSame(['213145.33', '105457.26'], array_column($lines, 'landed_inr'));
        $b = ImportRepository::balance(self::IMP, $this->productA);
        self::assertSame(['100.000', '213145.33'], [$b['qty_on_hand'], $b['value_inr']]);

        // Received → closed needs the supplier paid in full and the IGST credit taken.
        self::assertSame(['Still to pay the supplier: USD 1,849.99.', 'Record when the IGST paid on import was taken as input credit.'], ImportService::blockers($id));
        self::assertNull(ImportService::recordPayment($id, ['kind' => 'lc', 'paid_on' => date('Y-m-d'), 'amount_fc' => '1849.99', 'fx_rate' => '84.70', 'bank_reference' => 'LC-RET-' . $id], $this->impStaff));
        self::assertSame("LC retirement payment of USD 1,849.99 (₹1,56,694.15 at 84.70) to TEST-Xiamen Stone Industries (China) against import PO {$s['reference']}, LC 0123ILC000456, SWIFT/UTR ref LC-RET-{$id}",
            ImportRepository::payments($id)[1]['description']);
        self::assertNull(ImportService::saveIgstCredit($id, date('Y-m-d'), $this->impStaff));
        self::assertNull(ImportService::advance($id, 'received', $this->impStaff));
        self::assertSame('closed', ImportRepository::find($id)['stage']);

        // The local sale: a Domestic Trade order in the IMP division, out of stock, under IMP's own numbers.
        Database::connection()->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)')->execute(['c' => $this->client, 'd' => self::IMP]);
        [$orderId, $error] = DomesticOrderService::create([
            'client_id' => $this->client, 'buyer_gstin' => self::BUYER_GSTIN, 'buyer_state_code' => '29', 'place_of_supply' => '29', 'payment_terms_days' => '30',
        ], $this->impStaff, 'IMP');
        self::assertNull($error);
        $order = OrderRepository::find((int) $orderId);
        self::assertSame(self::IMP, (int) $order['division_id']);
        self::assertTrue(DomesticOrderService::isDomesticOrder($order));
        self::assertSame('Choose the product: an Import & Distribution sale comes out of stock.', DomesticOrderService::addLine((int) $orderId, ['description' => 'Granite', 'hsn_code' => '6802', 'quantity' => '1', 'unit' => 'SQM', 'rate_inr' => '10'], $this->impStaff));
        self::assertNull(DomesticOrderService::addLine((int) $orderId, ['product_id' => $this->productA, 'description' => 'Imported black granite slab', 'hsn_code' => '68029300', 'quantity' => '40', 'unit' => 'SQM', 'rate_inr' => '2700'], $this->impStaff));
        self::assertNull(DomesticOrderService::advance((int) $orderId, 'enquiry', $this->impStaff));
        [, $err] = DomesticDocumentService::generate((int) $orderId, 'DQT', $this->impStaff);
        self::assertNull($err);
        self::assertStringStartsWith('IMP/QT/', DomesticOrderRepository::latestDocument((int) $orderId, 'DQT')['reference']);
        self::assertNull(DomesticOrderService::advance((int) $orderId, 'quotation', $this->impStaff));
        DomesticDocumentService::generate((int) $orderId, 'DOC', $this->impStaff);
        self::assertSame('An Import & Distribution sale comes out of stock: there is no supplier PO.', DomesticOrderService::savePurchase((int) $orderId, ['supplier_name' => 'X', 'supplier_po_ref' => 'Y'], $this->impStaff));
        self::assertNull(DomesticOrderService::advance((int) $orderId, 'order_confirmation', $this->impStaff));
        self::assertSame([], DomesticOrderService::blockers((int) $orderId), 'the stock covers the sale: no supplier PO needed');
        // Moving on to dispatch fixes the tax invoice (IMP/26-27/0001) and takes the goods out of stock.
        self::assertNull(DomesticOrderService::advance((int) $orderId, 'purchase', $this->impStaff));
        $dom = DomesticOrderRepository::find((int) $orderId);
        self::assertMatchesRegularExpression('#^IMP/\d{2}-\d{2}/\d{4}$#', (string) $dom['tax_invoice_no']);
        $b = ImportRepository::balance(self::IMP, $this->productA);
        // 40 of 100 at weighted average: 213145.33 × 40 / 100 = 85258.132 → 85258.13 out; 127887.20 left.
        self::assertSame(['60.000', '127887.20'], [$b['qty_on_hand'], $b['value_inr']]);
        $issue = Database::connection()->query("SELECT * FROM stock_movements WHERE kind = 'issue' AND order_id = " . (int) $orderId)->fetch();
        self::assertSame(['-40.000', '-85258.13'], [$issue['quantity'], $issue['value_inr']]);
        // D-212: a local sale receipt says so.
        self::assertSame("Local sale receipt of ₹1,27,440.00 from Bengaluru Stone Traders against GST tax invoice {$dom['tax_invoice_no']} (order {$order['order_reference']}), bank ref UTR-IMP-1",
            DomesticOrderService::paymentDescription($order, $dom, '127440.00', 'UTR-IMP-1', null));

        // Reports: stock on hand with average cost and suggested price; landed cost; duty and IGST credit.
        $this->actAs($this->impStaff);
        $page = $this->render(fn () => (new ImportController())->reports([]));
        self::assertStringContainsString('Imported black granite slab', $page);
        self::assertStringContainsString('1,27,887.20', $page);
        self::assertStringContainsString('2131.4533', $page, 'average cost per unit is unchanged by an issue');
        self::assertStringContainsString('2,664.32', $page);
        self::assertStringContainsString('4567891', $page);
    }

    public function testStockNeverGoesBelowZero(): void
    {
        $id = $this->import();
        $this->receive($id);
        $pdo = Database::connection();
        $line = static fn (string $qty): array => ['line_no' => 1, 'description' => 'Quartz', 'product_id' => null, 'quantity' => $qty];
        $productB = $this->productB;
        $issue = function (string $qty, int $orderId) use ($pdo, $productB): ?string {
            $pdo->beginTransaction();
            $err = ImportService::issueForSale(self::IMP, $orderId, 'SALE-' . $orderId, [['line_no' => 1, 'description' => 'Quartz', 'product_id' => $productB, 'quantity' => $qty]], $this->impStaff);
            $err === null ? $pdo->commit() : $pdo->rollBack();
            return $err;
        };
        $o1 = $this->createTestOrder($this->client);
        $o2 = $this->createTestOrder($this->createTestClient());
        self::assertNull($issue('2', $o1));
        self::assertSame('Not enough stock of Imported quartz worktop: the sale needs 2, on hand 1.', $issue('2', $o2), 'the second issue finds only 1 left');
        self::assertSame('1.000', ImportRepository::balance(self::IMP, $this->productB)['qty_on_hand']);
        self::assertNull($issue('1', $o2), 'the last unit takes all the value that is left');
        self::assertSame(['0.000', '0.00'], [ImportRepository::balance(self::IMP, $this->productB)['qty_on_hand'], ImportRepository::balance(self::IMP, $this->productB)['value_inr']]);
        self::assertStringContainsString('not linked to a product', (string) ImportService::issueForSale(self::IMP, $o1, 'X', [$line('1')], $this->impStaff));
        self::assertSame(['Not enough stock of Imported quartz worktop: the sale needs 5, on hand 0.'],
            ImportService::stockShortfalls(self::IMP, [['line_no' => 1, 'description' => 'Q', 'product_id' => $this->productB, 'quantity' => '5']]));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM stock_balances WHERE qty_on_hand < 0')->fetchColumn());
    }

    public function testExportAndImportNeverSeeEachOther(): void
    {
        $exportOrder = $this->createTestOrder($this->client);
        Database::connection()->prepare('UPDATE orders SET division_id = 1 WHERE id = :id')->execute(['id' => $exportOrder]);
        $id = $this->import();
        // Export staff: the module is off for Export, and an import is not found.
        $this->actAs($this->expStaff);
        self::assertNotNull(DivisionService::disabledModuleForPath('/import'));
        $out = $this->render(fn () => (new ImportController())->show(['id' => (string) $id]));
        self::assertSame('Import not found.', $out);
        // IMP staff: the import is theirs; Export's orders are not.
        $this->actAs($this->impStaff);
        self::assertNull(DivisionService::disabledModuleForPath('/import'));
        self::assertFalse(DivisionService::allowsOrderId($exportOrder));
        self::assertStringContainsString('TEST-Xiamen Stone Industries', $this->render(fn () => (new ImportController())->show(['id' => (string) $id])));
        // Company level (MD, "All divisions"): sees both.
        $this->actAs($this->md);
        self::assertTrue(DivisionService::allowsOrderId($exportOrder));
        self::assertStringContainsString('TEST-Xiamen Stone Industries', $this->render(fn () => (new ImportController())->show(['id' => (string) $id])));
        // D-320: "Imports & stock" is in the Procurement & suppliers menu bar, shown on that section's pages (as Node checks on /import/:id).
        $_SERVER['REQUEST_URI'] = '/import/create';
        try {
            self::assertStringContainsString('Imports &amp; stock', $this->render(fn () => \App\Helpers\View::render('import/create', ['active' => true, 'old' => []], 'layout/base')));
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    public function testZohoPayloadsForAnImport(): void
    {
        $id = $this->import();
        $this->receive($id);
        self::assertNull(ImportService::saveIgstCredit($id, date('Y-m-d'), $this->impStaff));
        $s = ImportRepository::find($id);
        $acc = static fn (string $name): string => 'acc:' . $name;
        $bill = ZohoBooksService::importBillPayload($s, ImportRepository::lines($id), 'V1', $acc);
        self::assertSame(['V1', $s['reference'], 'COSU6234567890', 'USD', 84.65, 'overseas'], [$bill['vendor_id'], $bill['bill_number'], $bill['reference_number'], $bill['currency_code'], $bill['exchange_rate'], $bill['gst_treatment']]);
        self::assertSame([100.0, 18.5, 'acc:Imported Goods Stock', '68029300'], [$bill['line_items'][0]['quantity'], $bill['line_items'][0]['rate'], $bill['line_items'][0]['account_id'], $bill['line_items'][0]['hsn_or_sac']]);
        self::assertSame("Import purchase from TEST-Xiamen Stone Industries (China) against import PO {$s['reference']}, BL COSU6234567890, bill of entry 4567891.", $bill['notes']);
        self::assertArrayNotHasKey('tags', $bill, 'two divisions are active here, but no Division tag is set up');
        $duty = ZohoBooksService::customsDutyBillPayload($s, 'C1', $acc);
        self::assertSame('BE-4567891', $duty['bill_number']);
        self::assertSame([41681.99, 4168.2, 53748.42], array_column($duty['line_items'], 'rate'));
        self::assertSame(['acc:Imported Goods Stock', 'acc:Imported Goods Stock', 'acc:IGST Paid on Import'], array_column($duty['line_items'], 'account_id'));
        $journal = ZohoBooksService::importIgstCreditJournalPayload($s, $acc);
        self::assertSame([['acc:Input IGST', 'debit', 53748.42], ['acc:IGST Paid on Import', 'credit', 53748.42]],
            array_map(static fn (array $l): array => [$l['account_id'], $l['debit_or_credit'], $l['amount']], $journal['line_items']));
        self::assertStringContainsString('₹53,748.42', $journal['notes']);
        // With two active divisions and the tag set up, the Division tag goes along.
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO company_settings (setting_key, setting_value, value_type, category, description) VALUES ('zoho_division_tag_id', 'T1', 'string', 'company', 't'), ('zoho_division_tag_option_imp', 'OPT-IMP', 'string', 'company', 't') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        self::assertSame([['tag_id' => 'T1', 'tag_option_id' => 'OPT-IMP']], ZohoBooksService::importBillPayload($s, ImportRepository::lines($id), 'V1', $acc)['tags']);
        $pdo->exec("DELETE FROM company_settings WHERE setting_key IN ('zoho_division_tag_id', 'zoho_division_tag_option_imp')");
    }

    public function testTheListAndTheBoardOfferTheSame(): void
    {
        $id = $this->import();
        $this->actAs($this->impStaff);
        UserRepository::updateUiTemplatePreference($this->impStaff, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $this->actAs($this->impStaff);
        $_GET = [];
        $board = $this->render(fn () => (new ImportController())->index([]));
        $_GET = ['view' => 'list'];
        $list = $this->render(fn () => (new ImportController())->index([]));
        foreach ([$board, $list] as $page) {
            self::assertStringContainsString('href="/import/' . $id . '"', $page);
            self::assertStringContainsString('href="/import/create"', $page);
            self::assertStringContainsString('href="/import/reports"', $page);
            self::assertStringContainsString('name="stage"', $page);
            self::assertStringContainsString('TEST-Xiamen Stone Industries', $page);
            self::assertStringContainsString('USD 2,849.99', $page);
        }
        self::assertStringContainsString('kanban-board', $board);
        self::assertStringContainsString('Switch to list view', $board);
        self::assertStringContainsString('Switch to board view', $list);
        $_GET = ['view' => 'list', 'stage' => 'closed'];
        self::assertStringNotContainsString('href="/import/' . $id . '"', $this->render(fn () => (new ImportController())->index([])), 'the stage filter works');
    }
}
