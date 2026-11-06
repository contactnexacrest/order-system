<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Services\CaSyncService;
use App\Services\DivisionZohoSync;
use App\Services\ImportService;
use App\Services\ZohoBooksService;
use App\Services\ZohoPendingService;
use App\Tests\Support\DbTestCase;

/**
 * D-257 Zoho (owner 5 Nov 2026: "make sure the Zoho sync is complete"): the
 * new divisions' money goes to Zoho Books with the full sync — Domestic
 * Trade's tax invoice and receipts, Import & Distribution's bills, payments
 * abroad, duty payment and IGST journal, Merchant Trade's invoice, bill and
 * both payment legs — once, applied to the right invoice or bill, with the
 * app's own D-212 descriptions; the "Waiting for Zoho" badge counts them;
 * the "App vs Zoho" check compares them. Against a fake Zoho Books.
 */
final class DivisionZohoSyncTest extends DbTestCase
{
    public static function tearDownAfterClass(): void
    {
        self::clearDivisionTradeData(); // leave no division-trade records for later test classes
        parent::tearDownAfterClass();
    }

    /** @var list<array{method:string, url:string, payload:array}> */
    private array $requests = [];
    private array $contacts = [];
    private array $invoices = [];
    private array $bills = [];
    private array $customerPayments = [];
    private array $vendorPayments = [];
    private array $journals = [];
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        $_ENV['ZOHO_EINVOICE_ENABLED'] = 'false';
        foreach ([
            'zoho_books_enabled' => '1',
            'zoho_books_client_id' => 'fake-client',
            'zoho_books_client_secret' => 'fake-secret',
            'zoho_books_refresh_token' => 'fake-refresh',
            'zoho_books_organization_id' => '600',
            'zoho_books_deposit_account_id' => 'SBI-1',
        ] as $key => $value) {
            CompanySettingsRepository::set($key, $value);
        }
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url, $payload));
        // Earlier tests' records must not reach this test's fake Zoho.
        $pdo = Database::connection();
        $pdo->exec('UPDATE domestic_orders SET zoho_invoice_id = CONCAT(\'OLD-\', order_id) WHERE zoho_invoice_id IS NULL AND tax_invoice_no IS NOT NULL');
        $pdo->exec("UPDATE domestic_payments SET zoho_payment_id = 'OLD' WHERE zoho_payment_id IS NULL");
        $pdo->exec("UPDATE import_shipments SET zoho_bill_id = COALESCE(zoho_bill_id, 'OLD'), zoho_duty_bill_id = COALESCE(zoho_duty_bill_id, 'OLD'), duty_zoho_payment_id = COALESCE(duty_zoho_payment_id, 'OLD'), zoho_igst_journal_id = COALESCE(zoho_igst_journal_id, 'OLD'), zoho_clearance_bill_id = COALESCE(zoho_clearance_bill_id, 'OLD')");
        $pdo->exec("UPDATE import_payments SET zoho_payment_id = 'OLD' WHERE zoho_payment_id IS NULL");
        $pdo->exec("UPDATE merchant_deals SET zoho_invoice_id = COALESCE(zoho_invoice_id, 'OLD'), zoho_bill_id = COALESCE(zoho_bill_id, 'OLD')");
        $pdo->exec("UPDATE merchant_payments SET zoho_payment_id = 'OLD' WHERE zoho_payment_id IS NULL");
        $this->userId = $this->createTestUser('Admin');
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        parent::tearDown();
    }

    /** @return array{status:int, body:string} */
    private function fakeZoho(string $method, string $url, array $payload): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $json = static fn (array $body, int $status = 200): array => ['status' => $status, 'body' => (string) json_encode($body)];
        $paid = function (string $kind, string $id): float {
            $sum = 0.0;
            foreach ($kind === 'invoice' ? $this->customerPayments : $this->vendorPayments as $p) {
                foreach ($p[$kind === 'invoice' ? 'invoices' : 'bills'] ?? [] as $a) {
                    $sum += $a[$kind . '_id'] === $id ? $a['amount_applied'] : 0;
                }
            }
            return $sum;
        };
        $total = static fn (array $p): float => round(array_sum(array_map(static fn (array $l): float => $l['quantity'] * $l['rate'] * (isset($l['tax_id']) ? 1 + (int) preg_replace('/\D/', '', $l['tax_id']) / 100 : 1), $p['line_items'])), 2);

        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access']);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => true, 'tax_settings' => ['tax_reg_no' => '29AAKCN8733G1ZZ']]]);
        }
        if ($path === '/books/v3/settings/taxes') {
            $taxes = [];
            foreach ([0, 5, 12, 18, 28] as $rate) {
                $taxes[] = ['tax_id' => "TX-GST{$rate}", 'tax_name' => "GST{$rate}", 'tax_percentage' => $rate];
                $taxes[] = ['tax_id' => "TX-IGST{$rate}", 'tax_name' => "IGST{$rate}", 'tax_percentage' => $rate];
            }
            return $json(['code' => 0, 'taxes' => $taxes]);
        }
        if ($path === '/books/v3/settings/currencies') {
            return $json(['code' => 0, 'currencies' => array_map(static fn (string $c): array => ['currency_id' => "CUR-{$c}", 'currency_code' => $c], ['INR', 'USD', 'EUR'])]);
        }
        if ($path === '/books/v3/chartofaccounts') {
            $names = ['Imported Goods Stock', 'IGST Paid on Import', 'Input IGST', 'Merchanting Trade Sales', 'Merchanting Trade Purchases'];
            return $json(['code' => 0, 'chartofaccounts' => array_map(static fn (string $n): array => ['account_id' => 'AC-' . md5($n), 'account_name' => $n], $names)]);
        }
        if ($path === '/books/v3/contacts' && $method === 'GET') {
            return $json(['code' => 0, 'contacts' => array_values(array_filter($this->contacts, static fn (array $c): bool => $c['contact_name'] === ($q['contact_name'] ?? null)))]);
        }
        if ($path === '/books/v3/contacts' && $method === 'POST') {
            $id = 'CT-' . (count($this->contacts) + 1);
            $this->contacts[$id] = ['contact_id' => $id, 'contact_name' => $payload['contact_name'], 'currency_code' => isset($payload['currency_id']) ? substr($payload['currency_id'], 4) : ($payload['currency_code'] ?? 'INR'), 'gst_treatment' => $payload['gst_treatment'] ?? null, 'payload' => $payload];
            return $json(['code' => 0, 'contact' => $this->contacts[$id]], 201);
        }
        if ($path === '/books/v3/invoices' && $method === 'GET') {
            $rows = array_values(array_filter($this->invoices, static fn (array $i): bool => $i['invoice_number'] === ($q['invoice_number'] ?? $i['invoice_number'])));
            return $json(['code' => 0, 'invoices' => array_map(static fn (array $i): array => $i + ['balance' => round($i['total'] - $paid('invoice', $i['invoice_id']), 2)], $rows)]);
        }
        if ($path === '/books/v3/invoices' && $method === 'POST') {
            $id = 'IN-' . (count($this->invoices) + 1);
            $this->invoices[$id] = ['invoice_id' => $id, 'invoice_number' => $payload['invoice_number'], 'customer_id' => $payload['customer_id'], 'status' => 'draft', 'total' => $total($payload), 'payload' => $payload];
            return $json(['code' => 0, 'invoice' => $this->invoices[$id]], 201);
        }
        if (preg_match('#^/books/v3/invoices/(.+)/status/sent$#', $path, $m) === 1) {
            $this->invoices[$m[1]]['status'] = 'sent';
            return $json(['code' => 0, 'message' => 'Invoice status has been changed to Sent.']);
        }
        if ($path === '/books/v3/bills' && $method === 'GET') {
            $rows = array_values(array_filter($this->bills, static fn (array $b): bool => $b['bill_number'] === ($q['bill_number'] ?? $b['bill_number']) && $b['vendor_id'] === ($q['vendor_id'] ?? $b['vendor_id'])));
            return $json(['code' => 0, 'bills' => array_map(static fn (array $b): array => $b + ['balance' => round($b['total'] - $paid('bill', $b['bill_id']), 2)], $rows)]);
        }
        if ($path === '/books/v3/bills' && $method === 'POST') {
            $id = 'BL-' . (count($this->bills) + 1);
            $this->bills[$id] = ['bill_id' => $id, 'bill_number' => $payload['bill_number'], 'vendor_id' => $payload['vendor_id'], 'total' => $total($payload), 'payload' => $payload];
            return $json(['code' => 0, 'bill' => $this->bills[$id]], 201);
        }
        foreach (['customerpayments' => ['customerPayments', 'payment', 'customer_id'], 'vendorpayments' => ['vendorPayments', 'vendorpayment', 'vendor_id']] as $p => [$store, $one, $field]) {
            if ($path === "/books/v3/{$p}" && $method === 'GET') {
                $rows = [];
                foreach ($this->{$store} as $id => $pay) {
                    if ((!isset($q[$field]) || $pay[$field] === $q[$field]) && (!isset($q['reference_number']) || $pay['reference_number'] === $q['reference_number'])) {
                        $rows[] = ['payment_id' => $id, 'amount' => $pay['amount'], 'reference_number' => $pay['reference_number'], $field => $pay[$field]];
                    }
                }
                return $json(['code' => 0, $p => $rows, 'page_context' => ['has_more_page' => false]]);
            }
            if ($path === "/books/v3/{$p}" && $method === 'POST') {
                $id = strtoupper(substr($p, 0, 2)) . '-' . (count($this->{$store}) + 1);
                $this->{$store}[$id] = $payload;
                return $json(['code' => 0, $one => ['payment_id' => $id]], 201);
            }
        }
        if ($path === '/books/v3/expenses' && $method === 'GET') {
            return $json(['code' => 0, 'expenses' => [], 'page_context' => ['has_more_page' => false]]);
        }
        if ($path === '/books/v3/journals' && $method === 'POST') {
            $id = 'JN-' . (count($this->journals) + 1);
            $this->journals[$id] = $payload;
            return $json(['code' => 0, 'journal' => ['journal_id' => $id]], 201);
        }
        if ($path === '/books/v3/journals' && $method === 'GET') {
            return $json(['code' => 0, 'journals' => array_map(static fn (string $id, array $j): array => ['journal_id' => $id, 'total' => $j['line_items'][0]['amount'], 'notes' => $j['notes']], array_keys($this->journals), $this->journals), 'page_context' => ['has_more_page' => false]]);
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
    }

    private function calls(string $method, string $prefix): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with((string) parse_url($r['url'], PHP_URL_PATH), $prefix)));
    }

    /** A Domestic Trade order with its tax invoice fixed and one receipt. */
    private function domesticOrder(): int
    {
        $pdo = Database::connection();
        $clientId = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET company_legal_name = 'TEST-Bengaluru Stone Traders' WHERE id = :id")->execute(['id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD');
        $pdo->prepare('UPDATE orders SET division_id = 2 WHERE id = :id')->execute(['id' => $orderId]);
        $no = 'DOM/26-27/' . str_pad((string) ($orderId % 10000), 4, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO domestic_orders (order_id, stage, buyer_gstin, buyer_state_code, place_of_supply, seller_state_code, payment_terms_days, tax_invoice_no, tax_invoice_date, taxable_total, cgst_total, sgst_total, igst_total, round_off, invoice_total)
            VALUES (:o, 'payment', '29AAPFU0939F1ZR', '29', '29', '29', 30, :no, '2026-11-02', 100000.00, 9000.00, 9000.00, 0, 0, 118000.00)")->execute(['o' => $orderId, 'no' => $no]);
        $pdo->prepare("INSERT INTO domestic_order_lines (order_id, line_no, description, hsn_code, quantity, unit, rate_inr, gst_rate_pct) VALUES (:o, 1, 'Black granite slabs 20 mm', '6802', 50, 'm2', 2000.00, 18.00)")->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO domestic_payments (order_id, received_on, amount_inr, bank_reference, description) VALUES (:o, '2026-11-04', 118000.00, :r, :d)")
            ->execute(['o' => $orderId, 'r' => "UTR-DOM-{$orderId}", 'd' => "Domestic sale receipt of ₹1,18,000.00 from TEST-Bengaluru Stone Traders against GST tax invoice {$no} (order X), bank ref UTR-DOM-{$orderId}"]);
        return $orderId;
    }

    /** A cleared import with its bills' data, two payments abroad, the duty paid and the IGST credit taken. */
    private function import(): int
    {
        $pdo = Database::connection();
        $code = 'ZI' . random_int(10000, 99999);
        $pdo->prepare("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'Imported quartz worktop', '68101990')")->execute(['c' => $code]);
        $productId = (int) $pdo->lastInsertId();
        $ref = 'IMP/PO/26-27/' . random_int(1000, 9999);
        $pdo->prepare("INSERT INTO import_shipments (division_id, reference, stage, supplier_name, supplier_country, currency_code, po_date, bl_no, bl_date, port_code, cha_name, boe_no, boe_date, customs_fx_rate, clearance_date,
                value_total_inr, assessable_total, bcd_total, sws_total, igst_total, landed_total, igst_credit_on, duty_paid_on, duty_bank_reference, cha_inr, port_inr)
            VALUES (4, :ref, 'cleared', 'TEST-Xiamen Stone Co', 'China', 'USD', '2026-10-01', 'BL-77', '2026-10-15', 'INMAA1', 'TEST-Chennai CHA', :boe, '2026-10-30', 84.0000, '2026-10-31',
                840000.00, 860000.00, 86000.00, 8600.00, 171828.00, 960000.00, '2026-11-01', '2026-10-30', :duty, 18000.00, 22000.00)")
            ->execute(['ref' => $ref, 'boe' => (string) random_int(1000000, 9999999), 'duty' => 'CIN-' . random_int(100000, 999999)]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO import_shipment_lines (shipment_id, line_no, product_id, description, hsn_code, quantity, unit, unit_price_fc, bcd_pct, sws_pct, igst_pct) VALUES (:s, 1, :p, 'Quartz worktop 3 m', '68101990', 100, 'pcs', 100.00, 10, 10, 18)")
            ->execute(['s' => $id, 'p' => $productId]);
        foreach ([['advance', '3000.00', '83.5000', '250500.00'], ['balance', '7000.00', '84.1000', '588700.00']] as $i => [$kind, $fc, $fx, $inr]) {
            $pdo->prepare('INSERT INTO import_payments (shipment_id, paid_on, kind, amount_fc, fx_rate, amount_inr, bank_reference, description) VALUES (:s, :d, :k, :fc, :fx, :inr, :r, :desc)')
                ->execute(['s' => $id, 'd' => $i === 0 ? '2026-10-05' : '2026-11-03', 'k' => $kind, 'fc' => $fc, 'fx' => $fx, 'inr' => $inr, 'r' => "SWIFT-IMP-{$id}-{$i}", 'desc' => "Import supplier {$kind} of USD {$fc} to TEST-Xiamen Stone Co (China) against import PO {$ref}, SWIFT/UTR ref SWIFT-IMP-{$id}-{$i}"]);
        }
        return $id;
    }

    /** A merchant trade deal with its sale invoice fixed and one payment on each leg. */
    private function deal(): int
    {
        $pdo = Database::connection();
        $n = random_int(1000, 9999);
        $pdo->prepare("INSERT INTO merchant_deals (division_id, reference, po_no, sale_invoice_no, stage, deal_date, supplier_name, supplier_country, purchase_currency, buyer_name, buyer_country, sale_currency, origin_country, bl_no, bl_date, invoice_generated_at, invoice_date)
            VALUES (3, :r, :po, :inv, 'shipped', '2026-10-01', 'TEST-Anatolia Marble', 'Turkey', 'EUR', 'TEST-Gulf Interiors LLC', 'UAE', 'USD', 'Turkey', 'BL-MTT-1', '2026-10-20', NOW(), '2026-10-21')")
            ->execute(['r' => "MTT/26-27/{$n}", 'po' => "MTT/PO/26-27/{$n}", 'inv' => "MTT/I/26-27/{$n}"]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO merchant_deal_lines (deal_id, line_no, description, hsn_code, quantity, unit, buy_price_fc, sell_price_fc) VALUES (:d, 1, 'Marble blocks', '2515', 20, 'm3', 800.00, 1000.00)")->execute(['d' => $id]);
        foreach ([['buy', '16000.00', '91.2000', '1459200.00', '2026-10-10'], ['sell', '20000.00', '84.0000', '1680000.00', '2026-10-25']] as [$leg, $fc, $fx, $inr, $on]) {
            $pdo->prepare('INSERT INTO merchant_payments (deal_id, leg, moved_on, amount_fc, fx_rate, amount_inr, bank_reference, description) VALUES (:d, :l, :o, :fc, :fx, :inr, :r, :desc)')
                ->execute(['d' => $id, 'l' => $leg, 'o' => $on, 'fc' => $fc, 'fx' => $fx, 'inr' => $inr, 'r' => "SWIFT-MTT-{$id}-{$leg}", 'desc' => "Merchant trade {$leg} leg {$fc} against deal MTT/26-27/{$n}, SWIFT/UTR ref SWIFT-MTT-{$id}-{$leg}"]);
        }
        return $id;
    }

    public function testTheFullSyncSendsEveryNewDivisionRecordOnceAppliedWithTheAppsDescriptions(): void
    {
        $orderId = $this->domesticOrder();
        $importId = $this->import();
        $dealId = $this->deal();
        // ZOH-6/7: the merchant buyer's name is already in Books as a EUR customer, and the domestic buyer as an overseas USD one.
        $this->contacts['CT-EUR'] = ['contact_id' => 'CT-EUR', 'contact_name' => 'TEST-Gulf Interiors LLC', 'currency_code' => 'EUR', 'gst_treatment' => 'overseas', 'payload' => []];
        $this->contacts['CT-USD'] = ['contact_id' => 'CT-USD', 'contact_name' => 'TEST-Bengaluru Stone Traders', 'currency_code' => 'USD', 'gst_treatment' => 'overseas', 'payload' => []];

        $waiting = ZohoPendingService::counts(null)['by_type'];
        self::assertSame(['domestic_invoice' => 1, 'import_bills' => 1, 'merchant_deal' => 1], array_intersect_key($waiting, DivisionZohoSync::TYPE_LABELS), 'payments wait for their invoice / bill');

        $r = CaSyncService::runFullSync('manual', $this->userId);
        $errors = Database::connection()->query("SELECT message FROM zoho_sync_log WHERE status = 'error' ORDER BY id DESC LIMIT 3")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['sent' => 10, 'failed' => 0, 'skipped' => 0], $r['divisions'], implode(' | ', $errors) . ' — invoice + receipt, import bills + 2 payments + duty + journal, deal + 2 legs = 10');
        self::assertSame([], array_intersect_key(ZohoPendingService::counts(null)['by_type'], DivisionZohoSync::TYPE_LABELS), 'nothing left waiting');

        // Domestic: the invoice is marked sent, the receipt applied to it in full, with the app's D-212 text.
        $pdo = Database::connection();
        $dom = $pdo->query("SELECT * FROM domestic_orders WHERE order_id = {$orderId}")->fetch();
        self::assertSame('sent', $this->invoices[$dom['zoho_invoice_id']]['status']);
        // ZOH-6/7: its own INR, GST-registered customer, not the overseas USD one; remembered on the client.
        $domCustomer = $this->contacts[$this->invoices[$dom['zoho_invoice_id']]['customer_id']];
        self::assertSame(['TEST-Bengaluru Stone Traders (INR)', 'INR', 'business_gst', '29AAPFU0939F1ZR'], [$domCustomer['contact_name'], $domCustomer['currency_code'], $domCustomer['gst_treatment'], $domCustomer['payload']['gst_no']]);
        $clientRow = $pdo->query("SELECT o.client_id, c.zoho_contact_id_inr FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.id = {$orderId}")->fetch();
        self::assertSame($domCustomer['contact_id'], $clientRow['zoho_contact_id_inr']);
        // ZOH-8: the rupee round-off goes as the adjustment.
        self::assertSame([0.0, 'Round off'], [$this->invoices[$dom['zoho_invoice_id']]['payload']['adjustment'], $this->invoices[$dom['zoho_invoice_id']]['payload']['adjustment_description']]);
        $receipt = $pdo->query("SELECT * FROM domestic_payments WHERE order_id = {$orderId}")->fetch();
        $cp = $this->customerPayments[$receipt['zoho_payment_id']];
        self::assertSame(118000.0, (float) $cp['amount']);
        self::assertSame("UTR-DOM-{$orderId}", $cp['reference_number']);
        self::assertSame($receipt['description'], $cp['description']);
        self::assertSame([['invoice_id' => $dom['zoho_invoice_id'], 'amount_applied' => 118000.0]], $cp['invoices']);
        self::assertSame('SBI-1', $cp['account_id']);
        self::assertArrayNotHasKey('exchange_rate', $cp, 'INR');

        // Import: payments abroad in USD at the bank's rate, applied to the supplier's bill (USD 10,000).
        $s = $pdo->query("SELECT * FROM import_shipments WHERE id = {$importId}")->fetch();
        $pays = $pdo->query("SELECT * FROM import_payments WHERE shipment_id = {$importId} ORDER BY id")->fetchAll();
        $adv = $this->vendorPayments[$pays[0]['zoho_payment_id']];
        self::assertSame([3000.0, 83.5, 'SWIFT-IMP-' . $importId . '-0'], [(float) $adv['amount'], (float) $adv['exchange_rate'], $adv['reference_number']]);
        self::assertSame([['bill_id' => $s['zoho_bill_id'], 'amount_applied' => 3000.0]], $adv['bills']);
        self::assertSame($pays[0]['description'], $adv['description']);
        self::assertSame(7000.0, $this->vendorPayments[$pays[1]['zoho_payment_id']]['bills'][0]['amount_applied']);
        // The duty: INR 2,66,428.00 to TEST-Indian Customs on the duty bill, with the facts in the description.
        $duty = $this->vendorPayments[$s['duty_zoho_payment_id']];
        self::assertSame(266428.0, (float) $duty['amount']);
        self::assertSame([['bill_id' => $s['zoho_duty_bill_id'], 'amount_applied' => 266428.0]], $duty['bills']);
        self::assertSame("Customs duty and IGST paid to Indian Customs for import PO {$s['reference']}, bill of entry {$s['boe_no']} dated 2026-10-30 at INMAA1: basic customs duty ₹86,000.00 + social welfare surcharge ₹8,600.00 + IGST ₹1,71,828.00 = ₹2,66,428.00, against customs duty bill BE-{$s['boe_no']}, challan / bank ref {$s['duty_bank_reference']}", $duty['description']);
        self::assertSame('TEST-Indian Customs', $this->contacts[$duty['vendor_id']]['contact_name']);
        // The clearance charges: one bill from the CHA, to stock (landed cost).
        $clr = $this->bills[$s['zoho_clearance_bill_id']];
        self::assertSame(['CLR-' . $s['boe_no'], 'TEST-Chennai CHA', 40000.0], [$clr['bill_number'], $this->contacts[$clr['vendor_id']]['contact_name'], $clr['total']]);
        self::assertSame(['CHA charges on import PO ' . $s['reference'] . ', bill of entry ' . $s['boe_no'] . ' dated 2026-10-30', 'Port and terminal charges on import PO ' . $s['reference'] . ', bill of entry ' . $s['boe_no'] . ' dated 2026-10-30'], array_column($clr['payload']['line_items'], 'description'));
        // The IGST credit journal, marked TEST- in the test organisation.
        $journal = $this->journals[$s['zoho_igst_journal_id']];
        self::assertStringStartsWith('TEST- IGST paid on import taken as input credit', $journal['notes']);
        self::assertSame([171828.0, 171828.0], [$journal['line_items'][0]['amount'], $journal['line_items'][1]['amount']]);

        // Merchant trade: the buy leg on the supplier's bill (EUR), the sell leg on the sale invoice (USD).
        $d = $pdo->query("SELECT * FROM merchant_deals WHERE id = {$dealId}")->fetch();
        self::assertSame('sent', $this->invoices[$d['zoho_invoice_id']]['status']);
        self::assertSame('TEST-Gulf Interiors LLC (USD)', $this->contacts[$this->invoices[$d['zoho_invoice_id']]['customer_id']]['contact_name'], 'ZOH-7: a USD invoice never goes to the EUR contact');
        self::assertLessThanOrEqual(16, strlen((string) $d['sale_invoice_no']));
        $legs = $pdo->query("SELECT * FROM merchant_payments WHERE deal_id = {$dealId} ORDER BY leg")->fetchAll();
        $buy = $this->vendorPayments[$legs[0]['zoho_payment_id']];
        self::assertSame([16000.0, 91.2, [['bill_id' => $d['zoho_bill_id'], 'amount_applied' => 16000.0]]], [(float) $buy['amount'], (float) $buy['exchange_rate'], $buy['bills']]);
        $sell = $this->customerPayments[$legs[1]['zoho_payment_id']];
        self::assertSame([20000.0, 84.0, [['invoice_id' => $d['zoho_invoice_id'], 'amount_applied' => 20000.0]]], [(float) $sell['amount'], (float) $sell['exchange_rate'], $sell['invoices']]);
        self::assertSame($legs[1]['description'], $sell['description']);

        // A second run sends nothing again.
        $before = count($this->requests);
        $again = CaSyncService::runFullSync('manual', $this->userId);
        self::assertSame(0, $again['divisions']['sent']);
        self::assertSame(0, $this->calls('POST', '/books/v3/customerpayments') - 2);
        self::assertSame(0, $this->calls('POST', '/books/v3/vendorpayments') - 4);
        self::assertGreaterThan($before, count($this->requests), 'the other parts of the sync still read');

        // The sync log says what went, linked to the record.
        $logged = $pdo->query("SELECT sync_type, entity_type, status FROM zoho_sync_log WHERE entity_type IN ('import_shipment', 'merchant_deal') AND entity_id IN ({$importId}, {$dealId}) ORDER BY id")->fetchAll();
        self::assertSame(['import_bills', 'merchant_deal', 'import_payment', 'import_payment', 'import_duty_payment', 'import_igst_journal', 'merchant_payment', 'merchant_payment'], array_column($logged, 'sync_type'));
        self::assertSame(['success'], array_values(array_unique(array_column($logged, 'status'))));
    }

    public function testARetryFindsAPaymentBooksAlreadyHasAndAPaymentWaitsForItsInvoice(): void
    {
        $orderId = $this->domesticOrder();
        $pdo = Database::connection();
        // The invoice is not in Books yet: the receipt waits (not an error).
        $pdo->prepare('UPDATE domestic_orders SET tax_invoice_date = :d WHERE order_id = :o')->execute(['d' => '2026-11-02', 'o' => $orderId]);
        $pending = DivisionZohoSync::pending(null, ['order', $orderId]);
        self::assertCount(1, $pending['domestic_invoice']);
        self::assertCount(0, $pending['domestic_receipt']);

        // The app stopped after Books saved the receipt: the next run reuses it, no second payment.
        [$error] = DivisionZohoSync::sendNow('order', $orderId, $this->userId);
        self::assertNull($error);
        $paymentId = (string) $pdo->query("SELECT zoho_payment_id FROM domestic_payments WHERE order_id = {$orderId}")->fetchColumn();
        $pdo->exec("UPDATE domestic_payments SET zoho_payment_id = NULL WHERE order_id = {$orderId}");
        [$error, $ok] = DivisionZohoSync::sendNow('order', $orderId, $this->userId);
        self::assertNull($error);
        self::assertSame('1 record(s) sent to Zoho Books (invoice / bills and their payments).', $ok);
        self::assertSame($paymentId, (string) $pdo->query("SELECT zoho_payment_id FROM domestic_payments WHERE order_id = {$orderId}")->fetchColumn());
        self::assertCount(1, $this->customerPayments);
        self::assertSame([null, 'Nothing of this record was waiting for Zoho Books.'], DivisionZohoSync::sendNow('order', $orderId, $this->userId));
    }

    public function testBeforeTheGoLiveDateNothingIsSentAndAFailureIsLoggedWithoutStoppingTheOthers(): void
    {
        $orderId = $this->domesticOrder();
        $importId = $this->import();
        CompanySettingsRepository::set('zoho_books_sync_start_date', '2026-11-03');
        try {
            // The DOM invoice is dated 2 Nov: before the go-live date. The import's bill of entry is 30 Oct: also before.
            self::assertSame([], array_filter(DivisionZohoSync::pending('2026-11-03'), static fn (array $rows): bool => array_filter($rows, static fn (array $r): bool => in_array((int) ($r['order_id'] ?? 0), [$orderId], true) || (int) ($r['id'] ?? 0) === $importId) !== []));
        } finally {
            CompanySettingsRepository::set('zoho_books_sync_start_date', '');
        }
        // A supplier whose name lacks TEST- is refused in the test organisation; the other records still go.
        Database::connection()->prepare("UPDATE import_shipments SET supplier_name = 'Real Supplier Ltd' WHERE id = :id")->execute(['id' => $importId]);
        $r = DivisionZohoSync::run('manual', $this->userId);
        self::assertSame(1, $r['failed']);
        self::assertSame(2, $r['sent'], 'the domestic invoice and its receipt');
        $msg = (string) Database::connection()->query("SELECT message FROM zoho_sync_log WHERE sync_type = 'import_bills' AND entity_id = {$importId} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('only vendors whose name starts with TEST-', $msg);
    }

    public function testTheDutyPaymentIsRecordedWithItsChallanAndTheAppVsZohoCheckComparesEachRecord(): void
    {
        $importId = $this->import();
        $pdo = Database::connection();
        $pdo->exec("UPDATE import_shipments SET duty_paid_on = NULL, duty_bank_reference = NULL WHERE id = {$importId}");
        self::assertSame('Enter the challan or bank reference of the duty payment (e.g. ICEGATE challan CIN).', ImportService::saveDutyPayment($importId, ['duty_paid_on' => '2026-10-31', 'duty_bank_reference' => ''], $this->userId));
        self::assertSame('Enter the date the duty was paid (on or after the bill of entry date, not in the future).', ImportService::saveDutyPayment($importId, ['duty_paid_on' => '2026-10-01', 'duty_bank_reference' => 'CIN-1'], $this->userId));
        self::assertNull(ImportService::saveDutyPayment($importId, ['duty_paid_on' => '2026-10-31', 'duty_bank_reference' => 'CIN-2611'], $this->userId));

        $before = DivisionZohoSync::check('import', $importId);
        self::assertSame(['not_sent' => 7], $before['counts'], 'three bills, two payments, the duty payment and the journal');
        DivisionZohoSync::sendNow('import', $importId, $this->userId);
        $after = DivisionZohoSync::check('import', $importId);
        self::assertSame(['match' => 7], $after['counts']);
        self::assertSame(['Supplier\'s bill', 'USD 10,000.00', 'USD 10,000.00'], [substr($after['rows'][0]['item'], 0, 15), $after['rows'][0]['app'], $after['rows'][0]['books']]);
        self::assertSame('This duty payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it in Books.', ImportService::saveDutyPayment($importId, ['duty_paid_on' => '2026-10-31', 'duty_bank_reference' => 'CIN-9'], $this->userId));

        // Someone changes the bill in Books: the check says so.
        $billId = (string) $pdo->query("SELECT zoho_bill_id FROM import_shipments WHERE id = {$importId}")->fetchColumn();
        $this->bills[$billId]['total'] = 9999.0;
        $changed = DivisionZohoSync::check('import', $importId);
        self::assertSame('different', $changed['rows'][0]['status']);
        unset($this->vendorPayments[(string) $pdo->query("SELECT duty_zoho_payment_id FROM import_shipments WHERE id = {$importId}")->fetchColumn()]);
        self::assertSame(1, DivisionZohoSync::check('import', $importId)['counts']['missing']);
    }
}
