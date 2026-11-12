<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AmendmentController;
use App\Controllers\OrderController;
use App\Repositories\AmendmentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\AmendmentService;
use App\Repositories\DepartmentTaskRepository;
use App\Services\DocumentGenerationService;
use App\Services\FxRateService;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PriceLock;
use App\Services\PricingService;
use App\Services\ProductsAmendmentService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved):
 *  - the first approved Quotation fixes the exchange rate and every line's price and
 *    price check figures; the PI keeps the same rate;
 *  - after that no line is added, changed, copied or removed on the order page — not by
 *    the Super Admin either; the products table is read-only with the note and a link;
 *  - the "Products & prices" amendment: a price only goes down, quantities / sizes change,
 *    lines are added and removed; totals and the advance worked out; MD approval, the AMD
 *    document (PDF), the signed copy, activation (lines applied, stage kept, money follows);
 *  - the re-issue checklist; the document guard; the payment-terms AMD unchanged; the
 *    migration's back-fill of existing orders.
 * Node twin: tests/integration/priceLockProductsAmendmentD342.test.js (same wording).
 */
final class PriceLockProductsAmendmentD342Test extends DbTestCase
{
    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_SESSION = [];
        $_POST = [];
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

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        return (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    /** A product priced per CBM (₹80,000 supplier price): the mockup's USD 1,950.00 and 1,900.00 are above its floor. */
    private function product(): int
    {
        $admin = $this->createTestUser('Admin');
        $id = ProductRepository::create(['name' => 'D342 CHM Black P6 ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite slab', 'hs_code' => '68022310', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'Test Supplier', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        return $id;
    }

    /**
     * The mockup's order: three lines of CHM Black P6 in CBM at USD 1,950.00 (total USD 17,161.95), 40% advance.
     *
     * @return array{0:int,1:list<int>} order id, line ids
     */
    private function mockupOrder(): array
    {
        $productId = $this->product();
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare('UPDATE orders SET advance_pct = 40, balance_pct = 60 WHERE id = :id')->execute(['id' => $orderId]);
        $ids = [];
        foreach ([[180, 80, 13, 25, '4.680'], [140, 70, 13, 25, '3.185'], [120, 60, 13, 10, '0.936']] as $i => [$l, $w, $t, $pcs, $qty]) {
            $id = OrderProductRepository::add($orderId, $i + 1, 'CHM Black P6', "{$l} × {$w} × {$t} cm", null, $qty, false, 'CBM', '1950.00', '68022310');
            OrderProductRepository::setCatalogProduct($id, $productId);
            OrderProductRepository::setPieceSize($id, ['piece_length_cm' => (string) $l, 'piece_width_cm' => (string) $w, 'piece_thickness_cm' => (string) $t]);
            OrderProductRepository::setPieces($id, $pcs, null);
            $ids[] = $id;
        }
        return [$orderId, $ids];
    }

    private function approveQt(int $orderId): int
    {
        $qt = DocumentGenerationService::generate($orderId, 'QT', $this->createTestUser('Admin'), null, false, true);
        DocumentGenerationService::finalizeApproval((int) $qt['document_id']);
        return (int) $qt['document_id'];
    }

    /** The mockup's amendment: line 1 to 30 pieces, line 3 down to USD 1,900.00, a new 100 × 50 × 13 line of 20 pieces. */
    private function mockupPost(array $ids, array $extra = []): array
    {
        $line = static fn (string $l, string $w, string $t, string $pcs, string $price): array => ['length_cm' => $l, 'width_cm' => $w, 'thickness_cm' => $t, 'pieces' => $pcs, 'unit_price' => $price];
        return array_replace_recursive([
            'line' => [
                $ids[0] => $line('180', '80', '13', '30', '1950.00'),
                $ids[1] => $line('140', '70', '13', '25', '1950.00'),
                $ids[2] => $line('120', '60', '13', '10', '1900.00'),
            ],
            'new' => [0 => ['source_line_id' => (string) $ids[0], 'length_cm' => '100', 'width_cm' => '50', 'thickness_cm' => '13', 'pieces' => '20', 'unit_price' => '1950.00']],
        ], $extra);
    }

    public function testTheFirstApprovedQuotationFixesTheRateAndEveryLinesPriceAndFigures(): void
    {
        [$orderId, $ids] = $this->mockupOrder();
        self::assertFalse(PriceLock::isFixed(OrderRepository::find($orderId)));
        $rateNow = FxRateService::rate();
        $qtId = $this->approveQt($orderId);
        $order = OrderRepository::find($orderId);
        self::assertNotNull($order['prices_fixed_at']);
        self::assertSame($qtId, (int) $order['prices_fixed_by_document_id']);
        self::assertSame($rateNow, (float) $order['pricing_fx_rate_at_qt']);
        $line = OrderProductRepository::find($ids[0]);
        self::assertSame('1950.00', (string) $line['qt_fixed_unit_price']);
        $snap = json_decode((string) $line['price_snapshot'], true);
        self::assertSame($rateNow, (float) $snap['fx_rate'], 'the price check figures are fixed at the rate of the approval');
        $floorBefore = OrderLinePricing::forOrder($orderId)['lines'][$ids[0]]['figures']['floor'];

        // A new rate after the approval changes nothing on this order — not its figures, not the PI's rate.
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = 'manual' WHERE setting_key = 'fx_mode'");
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = '99.5000' WHERE setting_key = 'pricing_fx_rate'");
        self::assertSame(99.5, FxRateService::rate());
        self::assertSame($floorBefore, OrderLinePricing::forOrder($orderId)['lines'][$ids[0]]['figures']['floor']);
        self::assertFalse(PriceLock::fixAtQtApproval($orderId, $qtId), 'a second approval never fixes again');
        $pi = DocumentGenerationService::generate($orderId, 'PI', $this->createTestUser('Admin'), null, false, true);
        DocumentGenerationService::finalizeApproval((int) $pi['document_id']);
        self::assertSame($rateNow, (float) OrderRepository::find($orderId)['pricing_fx_rate_at_pi'], 'the PI keeps the rate fixed at the Quotation');
        self::assertStringStartsWith('Prices worked out at ₹' . number_format($rateNow, 2) . ' per USD — fixed by the approved Quotation of ' . date('j M Y'),
            (string) FxRateService::orderLine(OrderRepository::find($orderId), OrderLinePricing::forOrder($orderId)['lines']));
    }

    public function testEveryLineRouteIsRefusedAfterTheApprovalEvenForTheSuperAdmin(): void
    {
        [$orderId, $ids] = $this->mockupOrder();
        $this->approveQt($orderId);
        $superAdmin = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $superAdmin]);
        $this->actAs($superAdmin);
        $expected = 'Prices and products are fixed by the approved Quotation of ' . date('j M Y') . ' — change them through an Amendment (Order page › Amendments › Products & prices).';
        $c = new OrderController();
        $_POST = ['description' => 'CHM Black P6', 'quantity' => '9', 'unit' => 'CBM', 'unit_price' => '2500.00', 'reason' => 'Super Admin override attempt for the test'];
        foreach ([['addProduct', ['id' => $orderId]], ['updateProduct', ['id' => $orderId, 'productId' => $ids[0]]],
                  ['duplicateProduct', ['id' => $orderId, 'productId' => $ids[0]]], ['deleteProduct', ['id' => $orderId, 'productId' => $ids[1]]]] as [$action, $params]) {
            $c->{$action}($params);
            self::assertSame($expected, $this->flash(), $action);
        }
        self::assertCount(3, OrderProductRepository::forOrder($orderId));
        self::assertSame('1950.00', (string) OrderProductRepository::find($ids[0])['unit_price']);
        self::assertSame('4.680', (string) OrderProductRepository::find($ids[0])['quantity']);
    }

    public function testTheOrderPageShowsTheLinesReadOnlyWithTheNoteAndLink(): void
    {
        [$orderId] = $this->mockupOrder();
        $this->actAs($this->createTestUser('Admin'));
        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $before = (string) ob_get_clean();
        self::assertStringContainsString('data-open-panel="line-panel-new"', $before, 'before the approval the lines can be edited');
        $this->approveQt($orderId);
        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('id="price-lock-note"', $html);
        self::assertStringContainsString('Prices and products are fixed by the approved Quotation of ' . date('j M Y') . ' — change them through an Amendment (Order page › Amendments › Products &amp; prices).', $html);
        self::assertStringContainsString("/orders/{$orderId}/amendments#products-amendment", $html);
        self::assertStringNotContainsString('data-open-panel="line-panel-new"', $html);
        self::assertStringNotContainsString("/orders/{$orderId}/products/", $html, 'no Edit / Duplicate / Delete form');
    }

    public function testAPriceCanOnlyGoDownAndTheMockupTotalsAndAdvanceAreWorkedOut(): void
    {
        [$orderId, $ids] = $this->mockupOrder();
        $this->approveQt($orderId);
        Database::connection()->prepare('INSERT INTO order_payment_status (order_id, advance_amount) VALUES (:o, 6864.78) ON DUPLICATE KEY UPDATE advance_amount = 6864.78')->execute(['o' => $orderId]);
        $order = OrderRepository::find($orderId);

        $up = ProductsAmendmentService::draft($order, $this->mockupPost($ids, ['line' => [$ids[0] => ['unit_price' => '2000.00']]]));
        self::assertContains('Line 1: USD 1,950.00 is the price the buyer accepted in the Quotation — a price can only go down, with the MD\'s approval.', $up['errors']);

        $d = ProductsAmendmentService::draft($order, $this->mockupPost($ids));
        self::assertSame([], $d['errors']);
        $byNo = array_column($d['lines'], null, 'line_no');
        self::assertSame(['quantity'], $byNo[1]['changes']);
        self::assertSame(5.616, $byNo[1]['new_quantity']);
        self::assertSame(10951.2, $byNo[1]['new_line_total']);
        self::assertSame('none', $byNo[2]['change_kind']);
        self::assertSame(['price'], $byNo[3]['changes']);
        self::assertTrue($byNo[3]['price_lowered']);
        self::assertSame(1778.4, $byNo[3]['new_line_total']);
        self::assertSame(['add', 1.3, 2535.0], [$byNo[4]['change_kind'], $byNo[4]['new_quantity'], $byNo[4]['new_line_total']]);
        $t = $d['totals'];
        self::assertSame([17161.95, 21475.35, 6864.78, 8590.14, 10297.17, 14610.57], [$t['old_total'], $t['new_total'], $t['old_advance'], $t['new_advance'], $t['old_balance'], $t['new_balance']]);
        self::assertSame('+ USD 4,313.40', ProductsAmendmentService::signedDifference($t));
        self::assertSame('advance already received USD 6,864.78; the extra USD 1,725.36 is added to the balance.', ProductsAmendmentService::advanceNote($t));

        // Removing a line; a total below what was received shows as excess.
        $small = ProductsAmendmentService::draft($order, ['line' => [$ids[0] => ['remove' => '1'], $ids[1] => ['remove' => '1']]]);
        self::assertSame([], $small['errors']);
        self::assertSame(1825.2, $small['totals']['new_total']);
        self::assertSame('advance already received USD 6,864.78 is USD 5,039.58 more than the new total — it shows as excess.', ProductsAmendmentService::advanceNote($small['totals']));
        self::assertContains('Nothing was changed — change a line, add one or remove one first.', ProductsAmendmentService::draft($order, [])['errors']);
    }

    public function testTheWholeFlowAppliesTheLinesKeepsTheStageAndShowsTheChecklist(): void
    {
        [$orderId, $ids] = $this->mockupOrder();
        $this->approveQt($orderId);
        $admin = $this->createTestUser('Admin');
        $pi = DocumentGenerationService::generate($orderId, 'PI', $admin, null, false, true);
        DocumentGenerationService::finalizeApproval((int) $pi['document_id']);
        $pdo = Database::connection();
        $pdo->prepare("UPDATE orders SET buyers_po_ref = 'BUYER-PO-77', buyer_po_received_on = CURDATE() WHERE id = :o")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT INTO order_payment_status (order_id, advance_amount) VALUES (:o, 6864.78) ON DUPLICATE KEY UPDATE advance_amount = 6864.78')->execute(['o' => $orderId]);
        $before = OrderRepository::find($orderId);

        // Raised through the screen; a reason is required.
        $maker = $this->createTestUser('Export Executive');
        $this->actAs($maker);
        $_POST = $this->mockupPost($ids) + ['reason' => 'short', 'step' => 'send'];
        ob_start();
        (new AmendmentController())->createProducts(['id' => $orderId]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('Reason for the amendment: Reason must be at least 10 characters', $page);
        [$amendmentId, $errors] = ProductsAmendmentService::create($orderId, $this->mockupPost($ids), 'Buyer asked for 5 more slabs of 180 × 80 on 14 Nov (e-mail); discount on line 3 agreed by MD', $maker);
        self::assertSame([], $errors);
        self::assertSame('products', AmendmentRepository::find($amendmentId)['amendment_type']);
        self::assertNotNull(ProductsAmendmentService::cannotStart(OrderRepository::find($orderId)), 'one open products amendment at a time');

        $md = $this->createTestUser('Managing Director');
        AmendmentService::approveByMd($amendmentId, $md);
        $doc = AmendmentService::generateDocument($amendmentId, $md);
        $text = $this->pdfText((int) $doc['pdf_file_id']);
        foreach (['AMENDMENT — PRODUCTS & PRICES', '1. PRODUCTS & PRICES — AS AMENDED', 'Size (cm)', 'Price (USD) per CBM', 'Total (USD)',
                  '180×80×13', '10,951.20', '9,126.00', '1,900.00', '1,950.00', '100×50×13', 'New line', 'Quantity', 'Price',
                  'Order total (was USD 17,161.95)', '21,475.35', '2. EFFECT ON PAYMENT',
                  'Advance received USD 6,864.78 stays credited. Balance payable as per the agreed payment terms: USD 14,610.57. All other terms of the Quotation / Proforma Invoice remain unchanged.',
                  '3. ACCEPTANCE', 'Authorised Signatory Name', 'Company Stamp / Seal'] as $s) {
            self::assertStringContainsString($s, $text);
        }
        self::assertStringNotContainsString('AMENDED PAYMENT TERMS', $text);

        $this->createTestDocument($orderId, 'SUPPO', 'approved');
        // Everything issued so far was issued before the signed copy came back.
        $pdo->prepare('UPDATE documents SET generated_at = generated_at - INTERVAL 1 HOUR WHERE order_id = :o')->execute(['o' => $orderId]);
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $this->createTestFile($orderId), $maker);
        self::assertSame(1, (int) AmendmentRepository::find($amendmentId)['supplier_po_revision_needed']);
        $tasks = array_filter(DepartmentTaskRepository::open(['PROC']), static fn (array $t): bool => $t['task_key'] === 'revise_supplier_po' && (int) $t['order_id'] === $orderId);
        self::assertCount(1, $tasks, 'Procurement gets the task in My tasks');
        $after = OrderRepository::find($orderId);
        self::assertSame($before['current_stage_id'], $after['current_stage_id'], 'the order stays at its stage');
        self::assertSame((string) $before['pricing_fx_rate_at_qt'], (string) $after['pricing_fx_rate_at_qt'], 'the exchange rate is unchanged');
        $lines = array_column(OrderProductRepository::forOrder($orderId), null, 'line_no');
        self::assertSame(['5.616', 30, '10951.20'], [(string) $lines[1]['quantity'], (int) $lines[1]['pieces_count'], (string) $lines[1]['fob_value']]);
        self::assertSame(['1900.00', '1900.00'], [(string) $lines[3]['unit_price'], (string) $lines[3]['qt_fixed_unit_price']]);
        self::assertSame(['1.300', '1950.00', '100 × 50 × 13 cm'], [(string) $lines[4]['quantity'], (string) $lines[4]['qt_fixed_unit_price'], (string) $lines[4]['dimensions']]);
        self::assertNotEmpty($lines[4]['price_snapshot'], 'the added line is price-checked at the fixed rate');
        self::assertSame(21475.35, OrderProductRepository::totalFobValue($orderId));
        self::assertSame(14610.57, OrderPaymentStatusRepository::balanceDue(21475.35, 40.0, OrderPaymentStatusRepository::find($orderId)));
        self::assertNull(PriceLock::documentGuard($orderId, 'PI'));

        $list = ProductsAmendmentService::checklist($after);
        self::assertNotNull($list);
        $ref = AmendmentRepository::find($amendmentId)['amendment_reference'];
        self::assertSame('Amendment ' . $ref . ' activated — re-issue checklist', $list['title']);
        $rows = array_column($list['items'], null, 'document');
        self::assertSame('nothing — the signed amendment and the Quotation together are the agreement', $rows['Quotation Rev.00']['todo']);
        self::assertSame(['Outdated by ' . $ref, 'Generate revised Proforma Invoice (Rev.01)'], [$rows['Proforma Invoice Rev.00']['status'], $rows['Proforma Invoice Rev.00']['todo']]);
        self::assertSame('Ask the buyer for a revised PO, or tick "The signed amendment amends the Buyer PO"', $rows['Buyer PO (buyer\'s)']['todo']);
        self::assertSame('will use the amended figures', $rows['Commercial Invoice / Packing List']['todo']);
        self::assertSame(['Quantities changed', 'Procurement: revise the Supplier PO (task sent to Procurement)'], [$rows['Supplier PO']['status'], $rows['Supplier PO']['todo']]);
        self::assertStringContainsString('Money worked out again: total USD 21,475.35 · advance received USD 6,864.78 · balance now USD 14,610.57 (was USD 10,297.17).', $list['money']);

        // Items disappear once done: the revised PI, the Buyer PO tick.
        DocumentGenerationService::generate($orderId, 'PI', $admin, null, false, true);
        $pdo->prepare('UPDATE documents SET revision_number = 1 WHERE id = :id')->execute(['id' => $this->createTestDocument($orderId, 'SUPPO', 'draft')]);
        self::assertNull(ProductsAmendmentService::tickBuyerPo($orderId, $amendmentId, $admin));
        self::assertNull(ProductsAmendmentService::checklist(OrderRepository::find($orderId)), 'nothing left to do');
    }

    public function testTheDocumentGuardRefusesAPriceAboveTheFixedOne(): void
    {
        [$orderId, $ids] = $this->mockupOrder();
        $this->approveQt($orderId);
        $pdo = Database::connection();
        $pdo->prepare("UPDATE order_products SET unit_price = '2000.00' WHERE id = :id")->execute(['id' => $ids[1]]);
        try {
            DocumentGenerationService::generate($orderId, 'PI', $this->createTestUser('Admin'), null, false, true);
            self::fail('the PI must be refused');
        } catch (\RuntimeException $e) {
            self::assertSame('Line 2: USD 2,000.00 is higher than USD 1,950.00, the price the buyer accepted in the approved Quotation — the Proforma Invoice cannot be generated. Prices change only through an Amendment (Order page › Amendments › Products & prices).', $e->getMessage());
        }
        $pdo->prepare("UPDATE order_products SET unit_price = '1950.00', qt_fixed_unit_price = NULL WHERE id = :id")->execute(['id' => $ids[1]]);
        self::assertSame('Line 2 is not in the approved Quotation — the Commercial Invoice cannot be generated. Lines are added only through an Amendment (Order page › Amendments › Products & prices).',
            PriceLock::documentGuard($orderId, 'CI'));
        self::assertNull(PriceLock::documentGuard($orderId, 'QT'), 'the Quotation itself is not guarded');
    }

    public function testThePaymentTermsAmendmentPrintsExactlyAsBefore(): void
    {
        [$orderId] = $this->mockupOrder();
        $maker = $this->createTestUser('Export Executive');
        $id = AmendmentService::createRequest($orderId, 'Buyer asked for 30 days after BL', 'importer', 30.0, null, 'Balance within 30 days of BL', 'B_AGAINST_BL', 30, null, null, $maker);
        self::assertSame('payment_terms', AmendmentRepository::find($id)['amendment_type']);
        AmendmentService::approveByMd($id, $this->createTestUser('Managing Director'));
        $text = $this->pdfText((int) AmendmentService::generateDocument($id, $maker)['pdf_file_id']);
        foreach (['PAYMENT TERMS AMENDMENT AGREEMENT', '1. PARTIES TO THIS AMENDMENT', '2. ORIGINAL DOCUMENT REFERENCE', '4. AMENDED PAYMENT TERMS — REPLACING SECTION 3 ABOVE', '8. PAYMENT INSTRUCTIONS UNDER AMENDED TERMS'] as $s) {
            self::assertStringContainsString($s, $text);
        }
        self::assertStringNotContainsString('PRODUCTS & PRICES', $text);
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/AMD/payment_terms_amendment.html.twig');
        self::assertStringNotContainsString('products_amendment', $tpl, 'the payment-terms template is untouched by D-342');
    }

    public function testTheMigrationBackfillsOrdersWithAnApprovedQuotation(): void
    {
        $pdo = Database::connection();
        [$fixedOrder, $ids] = $this->mockupOrder();
        $qt = $this->createTestDocument($fixedOrder, 'QT', 'approved');
        $pdo->prepare('UPDATE orders SET pricing_fx_rate_at_pi = 84.2500 WHERE id = :o')->execute(['o' => $fixedOrder]);
        [$openOrder] = $this->mockupOrder();
        $this->createTestDocument($openOrder, 'QT', 'draft');
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/migrations/2026-11-12h-price-lock-and-products-amendment.sql');
        $cmd = ['mysql', '-h', \App\Config\Env::get('DB_HOST', '127.0.0.1'), '-P', \App\Config\Env::get('DB_PORT', '3306'), '-u', \App\Config\Env::get('DB_USERNAME'),
            '-p' . \App\Config\Env::get('DB_PASSWORD', ''), 'nexacrest_phpunit_test'];
        for ($run = 0; $run < 2; $run++) {
            $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $sql);
            fclose($pipes[0]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($p), 'the migration runs (twice): ' . $err);
        }
        $o = OrderRepository::find($fixedOrder);
        self::assertSame([$qt, '84.2500'], [(int) $o['prices_fixed_by_document_id'], (string) $o['pricing_fx_rate_at_qt']], 'past the PI: the PI\'s rate');
        self::assertNotNull($o['prices_fixed_at']);
        self::assertSame('1950.00', (string) OrderProductRepository::find($ids[0])['qt_fixed_unit_price']);
        self::assertNull(OrderRepository::find($openOrder)['prices_fixed_at'], 'no approved Quotation: not fixed');
    }
}
