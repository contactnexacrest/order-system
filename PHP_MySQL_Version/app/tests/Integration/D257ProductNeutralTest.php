<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderQualityDocumentController;
use App\Controllers\ProductCategoryController;
use App\Helpers\Flash;
use App\Repositories\HsCodeRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderQualityDocumentRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\PricingRepository;
use App\Repositories\SpecRepository;
use App\Services\DocumentDataAssembler;
use App\Services\PricingService;
use App\Services\ProductSpecs;
use App\Tests\Support\DbTestCase;
use App\Tests\Support\PricingFixtures;

/**
 * Owner decision D-257 Phase 0B (5 Nov 2026): product-neutral groundwork.
 * A product category decides its units, HS code, packing, quality documents
 * and T&C clause set; Natural Stone keeps exactly today's behaviour. A
 * test-only "Engineering" category (unit piece, HS 730791, per-piece
 * pricing, MTC 3.1 and drawing approval) is worked end to end.
 * Node twin: tests/integration/d257ProductNeutral.test.js.
 */
final class D257ProductNeutralTest extends DbTestCase
{
    use PricingFixtures;

    private int $userId;
    private int $orderId;

    /** Natural Stone's seeded settings, put back in tearDown (two tests here save other values). */
    private const STONE_SETTINGS = ['default_unit', 'allowed_units', 'default_hs_code', 'packing_type', 'quality_documents', 'tc_clause_set', 'weight_t_per_cbm'];
    private array $stoneSeeded = [];

    protected function setUp(): void
    {
        parent::setUp();
        // QA 13 Nov 2026 (SUI-006 / D-347): these figures use margin 20 % and other costs 2.5 % —
        // every pricing setting is pinned, so the result never depends on what ran before.
        self::pinWorkedExamplePricing();
        $this->stoneSeeded = (array) Database::connection()->query('SELECT id, ' . implode(', ', self::STONE_SETTINGS) . ' FROM product_categories WHERE is_system = 1 ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->allowHandTypedGoodsLines();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        $_FILES = [];
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        if (!HsCodeRepository::isActiveCode('730791')) {
            HsCodeRepository::create('730791', 'Flanges of iron or steel (test)', $this->userId);
        }
    }

    protected function tearDown(): void
    {
        // Saving Natural Stone's settings here left its units and weight per CBM changed for later files
        // (D270OrderLineUnitsTest, OrderLinePricingTest rely on the seed) — put the seeded values back.
        if ($this->stoneSeeded !== []) {
            Database::connection()->prepare('UPDATE product_categories SET ' . implode(', ', array_map(static fn (string $k): string => "{$k} = :{$k}", self::STONE_SETTINGS)) . ' WHERE id = :id')
                ->execute($this->stoneSeeded);
        }
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        self::resetPricingSettings();
        parent::tearDown();
    }

    private function post(object $controller, string $method, array $post, array $params = []): string
    {
        $_POST = $post;
        ob_start();
        $controller->{$method}($params);
        ob_end_clean();
        $_POST = [];
        $all = Flash::pull();
        return (string) ((end($all) ?: [])['message'] ?? '');
    }

    private function stone(): array
    {
        return array_values(array_filter(ProductCategoryRepository::all(), static fn (array $c): bool => (int) $c['is_system'] === 1))[0];
    }

    /** The test-only Engineering category: unit piece, HS 730791, MTC 3.1 and drawing approval. */
    private function engineering(): int
    {
        $name = 'Engineering ' . bin2hex(random_bytes(3));
        $this->post(new ProductCategoryController(), 'store', ['name' => $name, 'attributes' => ['Material', 'Size', 'Pressure class']]);
        $id = (int) Database::connection()->query('SELECT id FROM product_categories WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
        self::assertSame("Settings for \"{$name}\" saved.", $this->post(new ProductCategoryController(), 'settings', [
            'default_unit' => 'PCS', 'allowed_units' => ['PCS', 'NOS', 'SET'], 'default_hs_code' => '730791', 'packing_type' => 'Wooden Crates',
            'quality_documents' => "Mill test certificate 3.1\r\nDrawing approval\r\n", 'tc_clause_set' => 'engineering',
        ], ['id' => (string) $id]));
        return $id;
    }

    public function testNaturalStoneIsSeededWithTodaysBehaviour(): void
    {
        $stone = $this->stone();
        // D-270 (owner point 25, 11 Nov 2026): Natural Stone's goods lines use CBM, m², sq ft, PCS, Ton and RMT.
        self::assertSame([null, 'CBM,SQM,SQFT,PCS,TON,RMT', '680293', 'Wooden Crates', null, 'natural_stone'],
            [$stone['default_unit'], $stone['allowed_units'], $stone['default_hs_code'], $stone['packing_type'], $stone['quality_documents'], $stone['tc_clause_set']]);
        self::assertSame(['CBM', 'SQM', 'SQFT', 'PCS', 'TON', 'RMT'], ProductCategoryRepository::allowedUnits($stone));
        self::assertSame([], ProductCategoryRepository::qualityDocuments($stone), 'no extra quality documents');
        self::assertSame(' data-allowed-units="CBM,SQM,SQFT,PCS,TON,RMT" data-allowed-goods-only="1"', ProductCategoryRepository::lineOptionAttributes($stone), 'no HS / unit fills for Natural Stone, only its unit list for goods lines');
        $units = array_column(\App\Repositories\LookupRepository::dropdownOptions('unit'), 'option_value');
        foreach (['PCS', 'SET', 'KG', 'MT', 'MTR', 'LTR', 'HOUR', 'DAY', 'SQM', 'CBM', 'TON', 'RMT'] as $u) {
            self::assertContains($u, $units);
        }
        $types = array_column(\App\Repositories\LookupRepository::dropdownOptions('supplier_type'), 'option_value');
        foreach (['Quarry Owner', 'Trader', 'Manufacturer', 'Machine Shop', 'Forging / Casting Unit'] as $t) {
            self::assertContains($t, $types);
        }
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM file_upload_contexts WHERE context_key = 'quality_document'")->fetchColumn());
    }

    public function testCategorySettingsAreCheckedThenSaved(): void
    {
        $c = new ProductCategoryController();
        $id = $this->engineering();
        $base = ['default_unit' => 'PCS', 'allowed_units' => ['PCS'], 'default_hs_code' => '', 'packing_type' => '', 'quality_documents' => '', 'tc_clause_set' => ''];
        $cat = ProductCategoryRepository::find($id);
        $n = $cat['name'];
        self::assertSame("{$n}: Unit \"BUCKET\" is not on the Units list.", $this->post($c, 'settings', ['allowed_units' => ['BUCKET']] + $base, ['id' => (string) $id]));
        self::assertSame("{$n}: The default unit KG must be one of the allowed units.", $this->post($c, 'settings', ['default_unit' => 'KG'] + $base, ['id' => (string) $id]));
        self::assertSame("{$n}: HS code \"99999999\" is not on the HS Code master list — add it there first (HS Codes, under Admin).", $this->post($c, 'settings', ['default_hs_code' => '99999999'] + $base, ['id' => (string) $id]));
        self::assertSame("{$n}: Choose the packing type from the Package Types list.", $this->post($c, 'settings', ['packing_type' => 'Sack'] + $base, ['id' => (string) $id]));
        self::assertSame("{$n}: \"mtc\" is listed twice — each quality document once.", $this->post($c, 'settings', ['quality_documents' => "MTC\nmtc"] + $base, ['id' => (string) $id]));
        self::assertSame("{$n}: Choose the T&C clause set from the list.", $this->post($c, 'settings', ['tc_clause_set' => 'x'] + $base, ['id' => (string) $id]));
        $cat = ProductCategoryRepository::find($id);
        self::assertSame(['PCS', 'PCS,NOS,SET', '730791', 'Wooden Crates', "Mill test certificate 3.1\nDrawing approval", 'engineering'],
            [$cat['default_unit'], $cat['allowed_units'], $cat['default_hs_code'], $cat['packing_type'], $cat['quality_documents'], $cat['tc_clause_set']], 'a refused save changes nothing');
        $audit = Database::connection()->query("SELECT new_value FROM audit_log WHERE action_type = 'PRODUCT_CATEGORY_SETTINGS_UPDATED' AND entity_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame('unit PCS (allowed: PCS,NOS,SET); HS 730791; packing Wooden Crates; quality documents: Mill test certificate 3.1, Drawing approval; T&C engineering; weight per CBM -', $audit); // D-270 (point 25): the category's weight per CBM (left empty here)
        self::assertSame(' data-fills="{&quot;hs_code&quot;:&quot;730791&quot;,&quot;unit&quot;:&quot;PCS&quot;}" data-allowed-units="PCS,NOS,SET"', ProductCategoryRepository::lineOptionAttributes($cat));

        // Natural Stone's settings can be saved (its attributes stay locked).
        $stone = $this->stone();
        self::assertSame('Settings for "Natural Stone" saved.', $this->post($c, 'settings', ['default_unit' => '', 'default_hs_code' => '680293', 'packing_type' => 'Wooden Crates', 'quality_documents' => '', 'tc_clause_set' => 'natural_stone'], ['id' => (string) $stone['id']]));
        self::assertSame(['Dimensions', 'Finish'], ProductCategoryRepository::find((int) $stone['id'])['attributes']);
    }

    public function testAnEngineeringLineTakesItsHsCodeAndUnitFromTheCategory(): void
    {
        $eng = $this->engineering();
        self::assertSame('Product line added.', $this->post(new OrderController(), 'addProduct', [
            'description' => 'Weld neck flange', 'quantity' => '40', 'unit' => '', 'unit_price' => '18.50', 'line_type' => 'goods', 'hs_code' => '',
            'category_id' => (string) $eng, "attr_{$eng}" => ['ASTM A105', '4 inch', '150#'],
        ], ['id' => (string) $this->orderId]));
        $line = Database::connection()->query("SELECT * FROM order_products WHERE order_id = {$this->orderId} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['730791', 'PCS', (string) $eng], [$line['hs_code'], $line['unit'], (string) $line['category_id']]);

        // A unit the category does not allow is refused.
        self::assertSame("Product line: Unit KG is not one of the units for " . ProductCategoryRepository::find($eng)['name'] . ' (PCS, NOS, SET).', $this->post(new OrderController(), 'addProduct', [
            'description' => 'Blind flange', 'quantity' => '10', 'unit' => 'KG', 'unit_price' => '9.00', 'line_type' => 'goods', 'hs_code' => '',
            'category_id' => (string) $eng, "attr_{$eng}" => ['', '', ''],
        ], ['id' => (string) $this->orderId]));
        // An HS code typed on the line wins over the category's.
        $this->post(new OrderController(), 'addProduct', [
            'description' => 'Granite flange base', 'quantity' => '2', 'unit' => 'SET', 'unit_price' => '30', 'line_type' => 'goods', 'hs_code' => '680293',
            'category_id' => (string) $eng, "attr_{$eng}" => ['', '', ''],
        ], ['id' => (string) $this->orderId]);
        $line = Database::connection()->query("SELECT hs_code, unit FROM order_products WHERE order_id = {$this->orderId} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['680293', 'SET'], [$line['hs_code'], $line['unit']]);

        // A Natural Stone line is exactly as today: no HS code typed is still refused, the unit stays as sent.
        self::assertSame('HS code "" is not on the HS Code master list — add it there first (HS Codes, under Admin).', $this->post(new OrderController(), 'addProduct', [
            'description' => 'Black granite slab', 'quantity' => '10', 'unit' => 'SQM', 'unit_price' => '40', 'line_type' => 'goods', 'hs_code' => '', 'category_id' => '',
        ], ['id' => (string) $this->orderId]));
    }

    public function testPerPiecePerKgAndPerSetOptionsAddToThePriceWhenTheUnitMatches(): void
    {
        self::assertSame(['+$4.5/piece', '+$0.35/kg', '−$2/set', 'Included in base price'],
            [ProductSpecs::changeLabel('usd_piece', 4.5), ProductSpecs::changeLabel('usd_kg', 0.35), ProductSpecs::changeLabel('usd_set', -2.0), ProductSpecs::changeLabel('usd_piece', 0.0)]);
        $offered = [['id' => 1, 'name' => 'Machining', 'kind' => 'normal', 'options' => [
            ['id' => 11, 'name' => 'As forged', 'is_base' => 1, 'change_type' => 'none', 'value' => 0, 'thickness_mm' => null],
            ['id' => 12, 'name' => 'Machined', 'is_base' => 0, 'change_type' => 'usd_piece', 'value' => 4.5, 'thickness_mm' => null],
        ]], ['id' => 2, 'name' => 'Coating', 'kind' => 'normal', 'options' => [
            ['id' => 21, 'name' => 'Galvanised', 'is_base' => 0, 'change_type' => 'usd_kg', 'value' => 0.35, 'thickness_mm' => null],
        ]], ['id' => 3, 'name' => 'Bolts', 'kind' => 'normal', 'options' => [
            ['id' => 31, 'name' => 'With bolt set', 'is_base' => 0, 'change_type' => 'usd_set', 'value' => 2.25, 'thickness_mm' => null],
        ]]];
        $pcs = ProductSpecs::adjustments(['reference_unit' => 'PCS'], $offered, [1 => 12, 2 => 21, 3 => 31], 50);
        self::assertSame(4.5, round($pcs['usd'], 2), 'per piece applies on a piece price');
        self::assertSame(['Galvanised: USD 0.35 per kg, charged separately', 'With bolt set: USD 2.25 per set, charged separately'], $pcs['charges']);
        $kg = ProductSpecs::adjustments(['reference_unit' => 'KG'], $offered, [1 => 12, 2 => 21], 50);
        self::assertSame([0.35, ['Machined: USD 4.50 per piece, charged separately']], [round($kg['usd'], 2), $kg['charges']]);
        $set = ProductSpecs::adjustments(['reference_unit' => 'SET'], $offered, [3 => 31], 50);
        self::assertSame(2.25, round($set['usd'], 2));
        $stone = ProductSpecs::adjustments(['reference_unit' => 'SQM'], $offered, [1 => 12], 50);
        self::assertSame([0.0, ['Machined: USD 4.50 per piece, charged separately']], [$stone['usd'], $stone['charges']], 'never added to an m² price');
    }

    public function testAnEngineeringProductIsPricedPerPieceEndToEnd(): void
    {
        $eng = $this->engineering();
        $product = ProductRepository::create(['name' => 'D257 Weld neck flange ' . bin2hex(random_bytes(3)), 'specifications' => 'ASTM A105', 'hs_code' => '730791', 'reference_unit' => 'PCS'], $this->userId);
        SpecRepository::setProductFields($product, $eng, null, null);
        $spec = SpecRepository::createSpec(['category_id' => $eng, 'name' => 'Machining', 'kind' => 'normal', 'applies_to' => null, 'sort_order' => 1], $this->userId);
        $base = SpecRepository::createOption(['spec_id' => $spec, 'name' => 'As forged', 'is_base' => 1, 'change_type' => 'none', 'value' => null, 'thickness_mm' => null, 'sort_order' => 1], $this->userId);
        $machined = SpecRepository::createOption(['spec_id' => $spec, 'name' => 'Machined', 'is_base' => 0, 'change_type' => 'usd_piece', 'value' => '4.50', 'thickness_mm' => null, 'sort_order' => 2], $this->userId);
        $s = ProductSupplierRepository::create(['product_id' => $product, 'supplier_name' => 'Rajkot Forge', 'location' => 'Gujarat', 'contact_person' => null,
            'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 1230.0, 'factory_cost' => null, 'transportation_cost' => null,
            'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 1, 'notes' => null], $this->userId);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        $p = ProductRepository::find($product);
        $asForged = PricingService::forProduct($p, null, null, null, [$spec => $base]);
        $withMachining = PricingService::forProduct($p, null, null, null, [$spec => $machined]);
        self::assertNotNull($asForged['build']['minimum']);
        self::assertSame('PCS', ProductSpecs::adjustments($p, ProductSpecs::offered($p), [$spec => $machined], 50)['unit']);
        // USD 4.50 per piece is added to the cost; the margin, incentive and buffer come on top, as for every option (D-255).
        self::assertSame(4.5, round($withMachining['build']['cost'] - $asForged['build']['cost'], 2));
        // ₹1,230 ÷ 82 = USD 15.00 + 2.5 % other costs = 15.375; + 4.50 = 19.875; ÷ (1 − 20 %) = 24.84; + 3 % buffer = 25.59.
        self::assertSame([15.38, 19.22, 19.8], [$asForged['build']['cost'], $asForged['build']['minimum'], $asForged['build']['staff_quote']]);
        self::assertSame([19.88, 24.84, 25.59], [$withMachining['build']['cost'], $withMachining['build']['minimum'], $withMachining['build']['staff_quote']]);
    }

    public function testTheQualityChecklistFollowsTheLinesCategories(): void
    {
        // A Natural Stone order asks for nothing: no card.
        OrderProductRepository::add($this->orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
        self::assertSame([], OrderQualityDocumentRepository::forOrder($this->orderId));

        $eng = $this->engineering();
        $this->post(new OrderController(), 'addProduct', [
            'description' => 'Weld neck flange', 'quantity' => '40', 'unit' => 'PCS', 'unit_price' => '18.50', 'line_type' => 'goods', 'hs_code' => '',
            'category_id' => (string) $eng, "attr_{$eng}" => ['ASTM A105', '4 inch', '150#'],
        ], ['id' => (string) $this->orderId]);
        $docs = OrderQualityDocumentRepository::forOrder($this->orderId);
        self::assertSame([['Mill test certificate 3.1', 'pending'], ['Drawing approval', 'pending']], array_map(static fn ($d) => [$d['document_name'], $d['status']], $docs));

        $q = new OrderQualityDocumentController();
        $params = ['id' => (string) $this->orderId];
        self::assertSame('This order does not ask for that quality document.', $this->post($q, 'save', ['category_id' => (string) $eng, 'document_name' => 'Heat number', 'status' => 'received'], $params));
        self::assertSame('Drawing approval: attach the document before marking it received.', $this->post($q, 'save', ['category_id' => (string) $eng, 'document_name' => 'Drawing approval', 'status' => 'received'], $params));
        self::assertSame('Drawing approval marked pending.', $this->post($q, 'save', ['category_id' => (string) $eng, 'document_name' => 'drawing approval', 'status' => 'pending', 'note' => 'Sent to buyer 5 Nov'], $params));

        // The upload context resolves (a real move needs an HTTP upload — the L-22 proof).
        $tmp = tempnam(sys_get_temp_dir(), 'mtc-');
        file_put_contents($tmp, '%PDF-1.4 test');
        $_FILES['file'] = ['name' => 'mtc.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        self::assertStringContainsString('Failed to move the uploaded file', $this->post($q, 'save', ['category_id' => (string) $eng, 'document_name' => 'Mill test certificate 3.1', 'status' => 'received'], $params));
        $_FILES = [];
        @unlink($tmp);

        // With a file on record, it can be marked received, then approved.
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO file_store (order_id, file_origin, received_from, document_type_label, internal_only, server_path, uuid_filename, original_filename, file_size_bytes, mime_type, uploaded_by)
                       VALUES (:o, 'RECEIVED', 'Supplier', 'Mill test certificate 3.1', 1, '/tmp/x.pdf', 'x.pdf', 'MTC-H2311.pdf', 10, 'application/pdf', :u)")->execute(['o' => $this->orderId, 'u' => $this->userId]);
        OrderQualityDocumentRepository::save($this->orderId, $eng, 'Mill test certificate 3.1', 'received', (int) $pdo->lastInsertId(), null, $this->userId);
        self::assertSame('Mill test certificate 3.1 marked approved.', $this->post($q, 'save', ['category_id' => (string) $eng, 'document_name' => 'Mill test certificate 3.1', 'status' => 'approved', 'note' => 'Heat no. H2311'], $params));
        $docs = OrderQualityDocumentRepository::forOrder($this->orderId);
        self::assertSame([['approved', 'MTC-H2311.pdf', 'Heat no. H2311'], ['pending', null, 'Sent to buyer 5 Nov']],
            array_map(static fn ($d) => [$d['status'], $d['original_filename'], $d['note']], $docs), 'the file stays when only the status changes');
        self::assertSame('pending', $pdo->query("SELECT old_value FROM audit_log WHERE action_type = 'QUALITY_DOCUMENT_UPDATED' AND entity_id = {$this->orderId} ORDER BY id LIMIT 1")->fetchColumn());
    }

    public function testStoneDocumentDataIsIdenticalWhateverTheCategorySettings(): void
    {
        OrderProductRepository::add($this->orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
        $before = [];
        foreach (['QT', 'PI', 'CI', 'PL', 'SUPPO'] as $type) {
            $before[$type] = DocumentDataAssembler::assemble($this->orderId, $type);
        }
        // Even Natural Stone settings changed to something else do not reach a document in Phase 0B.
        $stone = $this->stone();
        $this->post(new ProductCategoryController(), 'settings', ['default_unit' => 'PCS', 'allowed_units' => ['PCS', 'SQM'], 'default_hs_code' => '730791', 'packing_type' => 'Wooden Crates',
            'quality_documents' => 'Inspection report', 'tc_clause_set' => 'general_goods'], ['id' => (string) $stone['id']]);
        $this->engineering();
        foreach (['QT', 'PI', 'CI', 'PL', 'SUPPO'] as $type) {
            self::assertEquals($before[$type], DocumentDataAssembler::assemble($this->orderId, $type), "{$type} document data unchanged");
        }
        self::assertSame('680293', $before['PI']['products'][0]['hs_code'] ?? '680293');
    }
}
