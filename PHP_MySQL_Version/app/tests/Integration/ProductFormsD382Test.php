<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DropdownOptionController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Controllers\ProductFormController;
use App\Controllers\SpecController;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\PermissionService;
use App\Services\ProductForms;
use App\Services\ProductSpecs;
use App\Tests\Support\DbTestCase;
use App\Tests\Support\PricingFixtures;

/**
 * Owner decisions D-382 … D-385 and D-389 (18 Nov 2026, mockups approved, plan confirmed twice):
 * forms of stone, the specification list that sets the HS code, the finishes per form, a product's
 * "Sold as" ticks, an order line's Sold as with its locked HS code (MD / ED may change it with a
 * reason), and the option order (Monument first in Size / format).
 *
 * Every row this class creates carries "PHPUnit D-382" in its name or reason and is removed in
 * tearDown (forms, specifications, finish ticks, product ticks); products and orders are the
 * usual disposable test rows. Node twin: tests/integration/productFormsD382.test.js.
 */
final class ProductFormsD382Test extends DbTestCase
{
    use PricingFixtures;

    private const REASON = 'PHPUnit D-382 test change';

    /** @var array<int, list<int>> surface_finish_forms as seeded, restored in tearDown */
    private array $finishMap = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        $this->finishMap = ProductForms::finishFormMap();
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec("DELETE x FROM product_hs_specifications x JOIN hs_specifications s ON s.id = x.hs_spec_id WHERE s.name LIKE 'PHPUnit D-382%'");
        $pdo->exec("UPDATE order_products op JOIN hs_specifications s ON s.id = op.hs_spec_id SET op.hs_spec_id = NULL WHERE s.name LIKE 'PHPUnit D-382%'");
        $pdo->exec("DELETE FROM hs_specifications WHERE name LIKE 'PHPUnit D-382%'");
        $pdo->exec("DELETE FROM product_forms WHERE name LIKE 'PHPUnit D-382%'");
        // The seeded finish ticks back as they were.
        $pdo->exec('DELETE FROM surface_finish_forms');
        foreach ($this->finishMap as $dd => $forms) {
            ProductForms::setFinishForms((int) $dd, $forms);
        }
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    private function flash(): string
    {
        $m = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $m === [] ? '' : (string) end($m)['message'];
    }

    private static function value(string $sql): mixed
    {
        return Database::connection()->query($sql)->fetchColumn();
    }

    private static function specId(string $name): int
    {
        return (int) self::soldAs($name)['hs_spec_id'];
    }

    /** The base options with the Surface finish set to this finish (spec_json). */
    private static function withFinish(int $productId, string $finish): string
    {
        $sel = json_decode(self::baseSpecJson($productId), true);
        foreach (ProductSpecs::offered(ProductRepository::find($productId)) as $s) {
            if (($s['list_key'] ?? null) !== 'surface_finish') {
                continue;
            }
            foreach ($s['options'] as $o) {
                if ($o['name'] === $finish) {
                    $sel[(string) $s['id']] = (int) $o['id'];
                }
            }
        }
        return (string) json_encode((object) $sel);
    }

    /** A configured, quotable product (₹80,000 per CBM) with no Sold as ticked. */
    private function product(string $refUnit = 'CBM'): int
    {
        $admin = $this->createTestUser('Admin');
        $id = ProductRepository::create(['name' => 'PHPUnit D-382 granite ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022310', 'reference_unit' => $refUnit], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'D-382 Quarry', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        self::configureProductForQuoting($id, $admin);
        return $id;
    }

    // ------------------------------------------------------------------ seeds and migration

    public function testTheSeedHoldsTheFormsSpecificationsFinishesAndPermission(): void
    {
        self::assertSame(
            ['Monument slab', 'Gangsaw slab', 'Cut-to-size slab', 'Raw block', 'Sawn block', 'Tile', 'Paving stone', 'Kerbs, setts, cobbles'],
            array_column(ProductForms::forms(true), 'name')
        );
        $raw = ProductForms::findForm((int) self::value("SELECT id FROM product_forms WHERE code = 'raw_block'"));
        self::assertSame(['CBM', 'TON'], ProductForms::formUnits($raw));
        self::assertSame(['block', 'Roughly trimmed', 'CBM'], [$raw['spec_group'], $raw['default_finish'], $raw['default_unit']]);
        $codes = [];
        foreach (ProductForms::specifications(true) as $s) {
            $codes[$s['name']] = [$s['form_name'], $s['hs_code']];
        }
        self::assertSame([
            'Raw block' => ['Raw block', '25161100'], 'Sawn block' => ['Sawn block', '25161200'], 'Gangsaw slab' => ['Gangsaw slab', '68022310'],
            'Random slab — irregular' => ['Gangsaw slab', '68022390'], 'Cut-to-size slab' => ['Cut-to-size slab', '68022310'], 'Tile' => ['Tile', '68022310'],
            'Monument slab' => ['Monument slab', '68029300'], 'Monument — complete set / tombstone' => ['Monument slab', '68029300'],
            'Paving stone' => ['Paving stone', '68010000'], 'Kerbs, setts, cobbles' => ['Kerbs, setts, cobbles', '68010000'],
        ], $codes);
        // Finishes: Roughly trimmed on Raw block only, Sawn on Sawn block only, the others on every form that is not a block.
        self::assertSame(['Roughly trimmed'], array_column(ProductForms::finishesForForm((int) $raw['id']) ?? [], 'name'));
        $sawn = (int) self::value("SELECT id FROM product_forms WHERE code = 'sawn_block'");
        self::assertSame(['Sawn'], array_column(ProductForms::finishesForForm($sawn) ?? [], 'name'));
        $tile = array_column(ProductForms::finishesForForm((int) self::value("SELECT id FROM product_forms WHERE code = 'tile'")) ?? [], 'name');
        self::assertContains('Natural Polish', $tile);
        self::assertNotContains('Roughly trimmed', $tile);
        self::assertNotContains('Sawn', $tile);
        // The new finishes have their Surface finish option at no price change (D-357).
        self::assertSame(2, (int) self::value("SELECT COUNT(*) FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.list_key = 'surface_finish' AND s.product_id IS NULL
            JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1 WHERE o.name IN ('Roughly trimmed', 'Sawn') AND o.change_type = 'none' AND o.dropdown_option_id IS NOT NULL"));
        self::assertSame('Granite blocks or tiles', self::value("SELECT description FROM hs_codes WHERE code = '68022310'"));
        // MariaDB quotes the default ('68029300'), MySQL does not.
        self::assertSame('68029300', trim((string) self::value("SELECT COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'order_products' AND column_name = 'hs_code'"), "'"));
        // The permission: MD and ED, not Admin or staff.
        $md = $this->createTestUser('Managing Director');
        $ed = $this->createTestUser('Executive Director');
        $admin = $this->createTestUser('Admin');
        $sales = $this->createTestUser('Export Executive');
        foreach ([[$md, true], [$ed, true], [$admin, false], [$sales, false]] as [$u, $can]) {
            $this->actAs($u);
            self::assertSame($can, ProductForms::canOverride());
        }
        // D-389: Monument (cut to size) first in Size / format, the others keep their order.
        $order = Database::connection()->query("SELECT o.name FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.name = 'Size / format' AND s.product_id IS NULL
            JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1 WHERE o.product_id IS NULL ORDER BY o.sort_order, o.id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame('Monument (cut to size)', $order[0]);
        self::assertSame(['Gangsaw slab', 'Cutter slab'], array_slice($order, 1, 2));
    }

    // ------------------------------------------------------------------ Forms of stone

    public function testFormsAreAddedChangedAndSwitchedOffWithAReasonNeverDeletedOnceUsed(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $form = ['name' => 'PHPUnit D-382 cut block', 'units' => ['CBM', 'TON'], 'default_unit' => 'TON', 'spec_group' => 'block', 'default_finish' => 'Sawn', 'sort_order' => '30', 'is_active' => '1'];

        $_POST = $form + ['reason' => 'short'];
        $this->capture(fn () => (new ProductFormController())->saveForm([]));
        self::assertStringContainsString('Reason must be at least 10 characters', $this->flash());
        self::assertFalse(self::value("SELECT id FROM product_forms WHERE name = 'PHPUnit D-382 cut block'"));

        $_POST = ['default_unit' => 'PCS'] + $form + ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveForm([]));
        self::assertStringContainsString('Starts on: choose one of the ticked units.', $this->flash());

        $_POST = $form + ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveForm([]));
        self::assertSame('Form "PHPUnit D-382 cut block" added.', $this->flash());
        $id = (int) self::value("SELECT id FROM product_forms WHERE name = 'PHPUnit D-382 cut block'");
        self::assertSame('CBM,TON', self::value("SELECT units FROM product_forms WHERE id = {$id}"));
        self::assertSame(self::REASON, self::value("SELECT reason FROM audit_log WHERE action_type = 'PRODUCT_FORM_CHANGED' AND entity_id = {$id} ORDER BY id DESC LIMIT 1"));

        // Used by a specification: never deleted, switched off instead.
        $_POST = ['name' => 'PHPUnit D-382 cut block spec', 'form_id' => (string) $id, 'usual_finish' => 'Sawn', 'hs_code' => '25161200', 'sort_order' => '40', 'is_active' => '1', 'reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveSpecification([]));
        self::assertStringContainsString('added', $this->flash());
        $_POST = ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->deleteForm(['id' => (string) $id]));
        self::assertStringContainsString('is used by a specification — switch it off instead', $this->flash());
        self::assertNotNull(ProductForms::findForm($id));

        $off = $form;
        unset($off['is_active']);
        $_POST = $off + ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveForm(['id' => (string) $id]));
        self::assertSame('0', (string) self::value("SELECT is_active FROM product_forms WHERE id = {$id}"));
        self::assertStringContainsString('switched off', (string) self::value("SELECT new_value FROM audit_log WHERE action_type = 'PRODUCT_FORM_CHANGED' AND entity_id = {$id} ORDER BY id DESC LIMIT 1"));
        self::assertNotContains('PHPUnit D-382 cut block', array_column(ProductForms::forms(true), 'name'));

        // An unused form is deleted (with a reason).
        $_POST = ['name' => 'PHPUnit D-382 spare form'] + $form + ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveForm([]));
        $spare = (int) self::value("SELECT id FROM product_forms WHERE name = 'PHPUnit D-382 spare form'");
        $_POST = ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->deleteForm(['id' => (string) $spare]));
        self::assertSame('Form "PHPUnit D-382 spare form" deleted.', $this->flash());
        self::assertNull(ProductForms::findForm($spare));

        // The page lists them, with the add form (placeholders, Choose…).
        $page = $this->capture(fn () => (new ProductFormController())->forms([]));
        self::assertStringContainsString('Forms of stone', $page);
        self::assertStringContainsString('PHPUnit D-382 cut block', $page);
        self::assertStringContainsString('placeholder="e.g. Raw block"', $page);
    }

    // ------------------------------------------------------------------ Specifications (HS)

    public function testASpecificationNeedsAnActiveHsMasterCodeAndIsSwitchedOffOnceUsed(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $tileForm = (string) self::value("SELECT id FROM product_forms WHERE code = 'tile'");
        $spec = ['name' => 'PHPUnit D-382 honed tile', 'form_id' => $tileForm, 'usual_finish' => 'Honed', 'sort_order' => '50', 'is_active' => '1', 'reason' => self::REASON];

        $_POST = $spec + ['hs_code' => '6802.23'];
        $this->capture(fn () => (new ProductFormController())->saveSpecification([]));
        self::assertStringContainsString('HS code: 6 or 8 digits, no dots', $this->flash());
        $_POST = $spec + ['hs_code' => '99999999'];
        $this->capture(fn () => (new ProductFormController())->saveSpecification([]));
        self::assertStringContainsString('HS code 99999999 is not an active code of the HS Code Master', $this->flash());
        self::assertFalse(self::value("SELECT id FROM hs_specifications WHERE name = 'PHPUnit D-382 honed tile'"));

        $_POST = $spec + ['hs_code' => '68022310'];
        $this->capture(fn () => (new ProductFormController())->saveSpecification([]));
        self::assertStringContainsString('Specification "PHPUnit D-382 honed tile" added', $this->flash());
        $id = (int) self::value("SELECT id FROM hs_specifications WHERE name = 'PHPUnit D-382 honed tile'");
        self::assertSame('HS_SPECIFICATION_CHANGED', self::value("SELECT action_type FROM audit_log WHERE entity_type = 'hs_specifications' AND entity_id = {$id} ORDER BY id DESC LIMIT 1"));

        // Ticked on a product: used — deleting is refused.
        $product = $this->product();
        ProductForms::setProductSpecs($product, [$id]);
        $_POST = ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->deleteSpecification(['id' => (string) $id]));
        self::assertStringContainsString('switch it off instead', $this->flash());
        self::assertNotNull(ProductForms::findSpecification($id));

        $page = $this->capture(fn () => (new ProductFormController())->specifications([]));
        self::assertStringContainsString('Specifications (HS)', $page);
        self::assertStringContainsString('PHPUnit D-382 honed tile', $page);
        self::assertStringContainsString('<option value="">Choose…</option>', $page);
    }

    // ------------------------------------------------------------------ a product's Sold as

    public function testSoldAsTicksNeedAReasonAndDecideWhichCategorySpecificationsApply(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $product = $this->product();
        Database::connection()->exec("UPDATE catalog_products SET forms = 'tile' WHERE id = {$product}");
        $p = ProductRepository::find($product);
        // Nothing ticked: the old forms value still applies.
        self::assertSame(['tile'], ProductSpecs::productForms($p));

        $_POST = ['reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveSoldAs(['id' => (string) $product]));
        self::assertStringContainsString('Tick at least one specification the product is sold as.', $this->flash());

        $_POST = ['sold_as' => [self::specId('Raw block') => '1', self::specId('Gangsaw slab') => '1'], 'reason' => 'short'];
        $this->capture(fn () => (new ProductFormController())->saveSoldAs(['id' => (string) $product]));
        self::assertStringContainsString('Reason must be at least 10 characters', $this->flash());
        self::assertSame([], ProductForms::productSpecIds($product));

        $_POST = ['sold_as' => [self::specId('Raw block') => '1', self::specId('Gangsaw slab') => '1'], 'reason' => self::REASON];
        $this->capture(fn () => (new ProductFormController())->saveSoldAs(['id' => (string) $product]));
        self::assertStringContainsString('Sold as saved', $this->flash());
        $ids = [self::specId('Raw block'), self::specId('Gangsaw slab')];
        sort($ids);
        self::assertSame($ids, ProductForms::productSpecIds($product));
        // The product's forms are now the groups of its ticked specifications' forms (block, slab), not "tile".
        self::assertSame(['block', 'slab'], ProductSpecs::productForms($p));
        $names = array_column(ProductSpecs::tree($p), 'name');
        self::assertContains('Size / format', $names, 'applies to slab and block');
        self::assertNotContains('Calibration', $names, 'tiles only');
        self::assertSame('PRODUCT_SOLD_AS_CHANGED', self::value("SELECT action_type FROM audit_log WHERE entity_type = 'catalog_products' AND entity_id = {$product} ORDER BY id DESC LIMIT 1"));
        self::assertSame(['Raw block', 'Gangsaw slab'], array_column(ProductForms::soldAsByProduct([$product])[$product], 'name'));

        // The Specifications page shows the Sold as ticks (no more fixed "Forms" ticks); the Products list shows "Sold as".
        $page = $this->capture(fn () => (new SpecController())->product(['id' => (string) $product]));
        self::assertStringContainsString('id="sold-as"', $page);
        self::assertStringNotContainsString('name="forms[]"', $page);
        $_GET = ['status' => 'all', 'q' => (string) $p['name']];
        $list = $this->capture(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('Sold as: Raw block, Gangsaw slab', $list);
        // Owner hard rule (1 Nov 2026): the same in the Kanban template (the Products list has no board — one page for both).
        \App\Repositories\UserRepository::updateUiTemplatePreference($md, (int) \App\Repositories\UiTemplateRepository::findByKey('kanban')['id']);
        $this->actAs($md);
        self::assertStringContainsString('Sold as: Raw block, Gangsaw slab', $this->capture(fn () => (new ProductController())->index([])));
        self::assertStringContainsString('id="sold-as"', $this->capture(fn () => (new SpecController())->product(['id' => (string) $product])));
        // The product edit form calls the product's HS code the fallback.
        $_GET = [];
        $edit = $this->capture(fn () => (new ProductController())->edit(['id' => (string) $product]));
        self::assertStringContainsString('HS Code (fallback) *', $edit);
    }

    // ------------------------------------------------------------------ finishes per form

    public function testFinishTicksPerFormAreSavedWithTheListsReason(): void
    {
        $admin = $this->createTestUser('Admin');
        $this->actAs($admin);
        $flamed = (int) self::value("SELECT id FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Flamed'");
        $raw = (int) self::value("SELECT id FROM product_forms WHERE code = 'raw_block'");
        $rows = Database::connection()->query("SELECT id, option_value, sort_order, is_active FROM dropdown_options WHERE list_key = 'surface_finish'")->fetchAll();
        $base = ['finish_forms_present' => '1', 'option_value' => [], 'sort_order' => [], 'is_active' => [], 'finish_forms' => []];
        foreach ($rows as $r) {
            $base['option_value'][(int) $r['id']] = $r['option_value'];
            $base['sort_order'][(int) $r['id']] = (string) $r['sort_order'];
            if ((int) $r['is_active'] === 1) {
                $base['is_active'][(int) $r['id']] = '1';
            }
            foreach ($this->finishMap[(int) $r['id']] ?? [] as $f) {
                $base['finish_forms'][(int) $r['id']][$f] = '1';
            }
        }
        $base['finish_forms'][$flamed][$raw] = '1'; // Flamed now also on Raw block

        $_POST = $base;
        $this->capture(fn () => (new DropdownOptionController())->update(['listKey' => 'surface_finish']));
        self::assertStringContainsString('Offered for forms: A reason is required', $this->flash());
        self::assertNotContains($raw, ProductForms::finishFormMap()[$flamed] ?? []);

        $_POST = $base + ['reason' => self::REASON];
        $this->capture(fn () => (new DropdownOptionController())->update(['listKey' => 'surface_finish']));
        self::assertContains($raw, ProductForms::finishFormMap()[$flamed]);
        self::assertSame(['Flamed', 'Roughly trimmed'], array_column(ProductForms::finishesForForm($raw) ?? [], 'name'), 'in the list order');
        self::assertSame(self::REASON, self::value("SELECT reason FROM audit_log WHERE action_type = 'FINISH_FORMS_CHANGED' AND entity_id = {$flamed} ORDER BY id DESC LIMIT 1"));

        $page = $this->capture(fn () => (new DropdownOptionController())->index([]));
        self::assertStringContainsString('Offered for forms', $page);
        self::assertStringContainsString('name="finish_forms[' . $flamed . '][' . $raw . ']" value="1" checked', $page);
    }

    // ------------------------------------------------------------------ order lines

    public function testALinePickedOnAUsdOrderNeedsItsSoldAsWhichLocksTheHsCodeAndTheUnits(): void
    {
        $product = $this->product();
        ProductForms::setProductSpecs($product, [self::specId('Raw block'), self::specId('Gangsaw slab')]);
        $sales = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$sales, $md]);
        $this->actAs($sales);
        $post = ['catalog_product_id' => (string) $product, 'description' => 'D-382 raw block', 'quantity' => '25', 'unit' => 'TON', 'line_type' => 'goods',
            'hs_code' => '68022310', 'spec_json' => self::withFinish($product, 'Roughly trimmed')];

        // No Sold as: refused.
        $_POST = $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame("Line 1: choose what the product is sold as (Sold as) — it sets the line's HS code.", $this->flash());

        // A specification the product is not sold as: refused.
        $_POST = ['hs_spec_id' => (string) self::specId('Tile')] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('is not sold as "Tile" — choose one of its Sold as.', $this->flash());

        // A unit the form is not sold in: refused.
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'unit' => 'SQM'] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame('Line 1: Raw block is sold in CBM, TON — choose one of these units.', $this->flash());

        // A finish the form does not offer: refused.
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'spec_json' => self::withFinish($product, 'Natural Polish')] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame('Line 1: the finish "Natural Polish" is not offered for Raw block — choose another Surface finish.', $this->flash());

        // Raw block in tonnes: the HS code comes from the specification (the posted one is ignored when it is the product's).
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'hs_code' => ''] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('Product line added', $this->flash());
        $line = OrderProductRepository::forOrder($orderId)[0];
        self::assertSame(['25161100', self::specId('Raw block'), null, 'TON'], [$line['hs_code'], (int) $line['hs_spec_id'], $line['hs_override_reason'], $line['unit']]);
        self::assertSame('Raw block', ProductForms::formNameForLine($line));
        self::assertSame([(int) $line['id'] => 'Raw block'], ProductForms::formNameForOrderLines($orderId));

        // Staff may not change the HS code from the specification.
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'hs_code' => '25161200', 'hs_override_reason' => 'Customs want the sawn block code'] + $post;
        $this->capture(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $line['id']]));
        self::assertSame('Line 1: the HS code comes from its Sold as ("Raw block": 25161100) — only the MD or the ED can change it.', $this->flash());

        // The MD may, with a reason (ReasonValidator) and an active master code; audited.
        $this->actAs($md);
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'hs_code' => '25161200', 'hs_override_reason' => 'short'] + $post;
        $this->capture(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $line['id']]));
        self::assertSame('Line 1: give the reason for changing the HS code from 25161100 (at least 10 characters).', $this->flash());
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'hs_code' => '12345678', 'hs_override_reason' => 'Customs want another code'] + $post;
        $this->capture(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $line['id']]));
        self::assertSame('Line 1: HS code "12345678" is not an active code of the HS Code Master.', $this->flash());
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'hs_code' => '25161200', 'hs_override_reason' => 'Customs want the sawn block code'] + $post;
        $this->capture(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $line['id']]));
        self::assertStringContainsString('Product line updated', $this->flash());
        $line = OrderProductRepository::find((int) $line['id']);
        self::assertSame(['25161200', 'Customs want the sawn block code'], [$line['hs_code'], $line['hs_override_reason']]);
        $audit = Database::connection()->query("SELECT old_value, new_value, reason FROM audit_log WHERE action_type = 'HS_CODE_OVERRIDDEN' AND entity_id = {$line['id']} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['Line 1 (Raw block): 25161100', 'Line 1 (Raw block): 25161200', 'Customs want the sawn block code'], [$audit['old_value'], $audit['new_value'], $audit['reason']]);

        // Staff then edit the line (the MD's code unchanged): it stays, nothing refused.
        $this->actAs($sales);
        $_POST = ['hs_spec_id' => (string) self::specId('Raw block'), 'hs_code' => '25161200', 'quantity' => '26'] + $post;
        $this->capture(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $line['id']]));
        self::assertStringContainsString('Product line updated', $this->flash());
        self::assertSame(['25161200', 'Customs want the sawn block code'], [OrderProductRepository::find((int) $line['id'])['hs_code'], OrderProductRepository::find((int) $line['id'])['hs_override_reason']]);

        // A duplicated line copies the Sold as, the HS code and the reason.
        $_POST = [];
        $this->capture(fn () => (new OrderController())->duplicateProduct(['id' => (string) $orderId, 'productId' => (string) $line['id']]));
        $copy = OrderProductRepository::forOrder($orderId)[1];
        self::assertSame([self::specId('Raw block'), '25161200', 'Customs want the sawn block code'], [(int) $copy['hs_spec_id'], $copy['hs_code'], $copy['hs_override_reason']]);

        // The order page shows the Sold as and where the HS code comes from, and offers Sold as in the panel.
        $this->actAs($md);
        $page = $this->capture(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('Sold as: <b>Raw block</b>', $page);
        self::assertStringContainsString('changed by MD/ED — Customs want the sawn block code', $page);
        self::assertStringContainsString('class="sold-as-select"', $page);
        self::assertStringContainsString('data-sold-as="', $page);
        self::assertStringContainsString('data-unit-kind="TON"', $page);
        // The MD gets "Change HS code" beside the HS line (it opens More and focuses the HS Code box) …
        self::assertStringContainsString('<button type="button" class="btn-link" data-change-hs>Change HS code</button>', $page);
        // … staff without the permission do not.
        $this->actAs($sales);
        $staffPage = $this->capture(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('class="sold-as-select"', $staffPage);
        self::assertStringNotContainsString('data-change-hs>Change HS code</button>', $staffPage);
    }

    public function testWithASoldAsWhatTheLineNeedsFollowsItsUnitAndD348StaysForLinesWithout(): void
    {
        $product = $this->product();
        $admin = $this->createTestUser('Admin');
        $this->actAs($admin);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$admin]);
        // Thickness and Size / format left on "Choose…" (only the other options chosen).
        $sel = json_decode(self::baseSpecJson($product), true);
        foreach (ProductSpecs::offered(ProductRepository::find($product)) as $s) {
            if (in_array($s['name'], ['Thickness', 'Size / format'], true)) {
                unset($sel[(string) $s['id']]);
            }
        }
        $post = ['catalog_product_id' => (string) $product, 'description' => 'D-382 slab', 'quantity' => '40', 'line_type' => 'goods', 'spec_json' => json_encode((object) $sel)];

        // SQM with a Sold as: the thickness (option or cm) is needed — not the Size / format.
        $_POST = self::soldAs() + ['unit' => 'SQM'] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame('Line 1: choose the Thickness or enter the Thickness (cm) — a price per SQM needs it.', $this->flash());
        $_POST = self::soldAs() + ['unit' => 'SQM', 'piece_thickness_cm' => '3'] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('Product line added', $this->flash());

        // PCS (Monument slab): length, width and thickness.
        $_POST = self::soldAs('Monument slab') + ['unit' => 'PCS', 'piece_length_cm' => '180'] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame('Line 2: enter the width and thickness (cm) — a price per piece needs them.', $this->flash());

        // CBM: nothing more.
        $_POST = self::soldAs() + ['unit' => 'CBM'] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('Product line added', $this->flash());

        // Without a Sold as (picking not required here): D-348 exactly as before.
        $this->allowHandTypedGoodsLines();
        $_POST = ['unit' => 'CBM', 'hs_code' => '68022310', 'hs_spec_id' => ''] + $post;
        $this->capture(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame('Line 3: choose the Thickness and the Size / format — the price depends on them.', $this->flash());
    }

    // ------------------------------------------------------------------ D-389 option order

    public function testAnOptionsOrderIsEditableAndLogged(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $opt = Database::connection()->query("SELECT o.* FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id AND s.name = 'Edge work' AND s.product_id IS NULL
            JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1 WHERE o.name = 'Bullnose' AND o.product_id IS NULL")->fetch();
        $_POST = ['name' => $opt['name'], 'change_type' => $opt['change_type'], 'value' => (string) $opt['value'], 'thickness_mm' => '', 'sort_order' => '0'];
        try {
            $this->capture(fn () => (new SpecController())->updateOption(['id' => (string) $opt['id']]));
            self::assertSame('0', (string) self::value("SELECT sort_order FROM spec_options WHERE id = {$opt['id']}"));
            self::assertStringEndsWith('· order 0', (string) self::value("SELECT new_value FROM audit_log WHERE action_type = 'SPEC_OPTION_CHANGED' AND entity_id = {$opt['id']} ORDER BY id DESC LIMIT 1"));
            // The category's Options tab shows the order as a box (it was a hidden field).
            $_GET = ['tab' => 'options', 'edit' => (string) $opt['spec_id']];
            $page = $this->capture(fn () => (new \App\Controllers\ProductCategoryController())->show(['id' => (string) self::value('SELECT id FROM product_categories WHERE is_system = 1 ORDER BY id LIMIT 1')]));
            self::assertStringContainsString('aria-label="Order of Bullnose"', $page);
        } finally {
            Database::connection()->prepare('UPDATE spec_options SET sort_order = :o WHERE id = :id')->execute(['o' => $opt['sort_order'], 'id' => $opt['id']]);
        }
    }
}
