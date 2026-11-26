<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateBrandRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\ApprovalsInbox;
use App\Services\PriceRequestService;
use App\Services\ProductApproval;
use App\Services\AssociateBrands;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * D-435 (owner 26 Nov 2026): the brand catalogue — brand → main category → sub-category → product. A sub-category
 * follows its main category's settings until it sets its own; a price setting still empty follows the brand's pricing,
 * then the company's. A product a brand manager adds waits for approval ("Approve new products", never your own);
 * until then it cannot be quoted or seen by associates. Brand managers change only their brand's catalogue;
 * company-wide categories and products are view-only for them. Associates see their brand's and company-wide products.
 * Node twin: brandCatalogueD435.test.js.
 *
 * (Helpers below as in BrandManagersD432Test.) D-432 (owner 26 Nov 2026): brand managers. A staff member given a brand (Admin › Users › Brand, "Manage divisions")
 * sees and changes only that brand: its associates, their pages and actions, the brand's rules and ladder, its
 * registrations, price requests, report and payout lines. Never another brand, never brand details, never a
 * company-wide setting, never moving an associate between brands. Empty = all brands, as before.
 * Node twin: brandManagersD432.test.js.
 */
final class BrandCatalogueD435Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $md;
    private string $tag;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__d435-routes-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::router();
        AssociateBrands::reset();
        $this->tag = strtolower(bin2hex(random_bytes(3)));
        $this->md = $this->createTestUser('Managing Director');
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        AssociateBrands::reset();
        parent::tearDown();
    }

    /** @return array{body: string, flash: ?string, code: int} */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];
        $q = parse_url($path, PHP_URL_QUERY);
        if (is_string($q)) {
            parse_str($q, $_GET);
        }
        http_response_code(200);
        PermissionService::resetCache();
        AssociateBrands::reset();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $all = Flash::pull();
        return ['body' => $body, 'flash' => (end($all) ?: [])['message'] ?? null, 'code' => http_response_code()];
    }

    private function asStaff(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
    }

    private function brand(string $prefix): array
    {
        $id = AssociateBrandRepository::save(null, ['code' => $prefix . $this->tag, 'name' => ucfirst($prefix) . ' Brand ' . $this->tag, 'tagline' => AssociateBrands::DEFAULT_TAGLINE,
            'products' => null, 'website' => null, 'email' => null, 'status' => 'active'], $this->md);
        return AssociateBrandRepository::find($id);
    }

    private function associate(?int $brandId): array
    {
        $id = AssociateRepository::create(['name' => 'D435 Associate ' . $this->tag . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'associate-' . bin2hex(random_bytes(4)) . '@email.com', 'status' => 'active'], $this->md);
        if ($brandId !== null) {
            AssociateBrandRepository::setAssociateBrand($id, $brandId);
        }
        return AssociateRepository::find($id);
    }

    /** An MD who looks after one brand (D-432 works with any role; the MD holds every permission used here). */
    private function brandManager(int $brandId): int
    {
        $u = $this->createTestUser('Managing Director');
        Database::connection()->prepare('UPDATE users SET brand_id = :b WHERE id = :id')->execute(['b' => $brandId, 'id' => $u]);
        return $u;
    }

    private function mainCategory(string $name, ?int $brandId, array $settings = []): int
    {
        $id = ProductCategoryRepository::create($name . ' ' . $this->tag, ['Size', 'Finish'], $this->md, null, $brandId);
        if ($settings !== []) {
            $cols = implode(', ', array_map(static fn (string $k): string => "{$k} = :{$k}", array_keys($settings)));
            Database::connection()->prepare("UPDATE product_categories SET {$cols} WHERE id = :id")->execute($settings + ['id' => $id]);
        }
        return $id;
    }

    private function product(string $name, ?int $categoryId, int $userId, ?array $user = null): int
    {
        $id = ProductRepository::create(['name' => $name . ' ' . $this->tag, 'specifications' => 'Test product', 'hs_code' => '680293', 'is_active' => 1], $userId);
        if ($categoryId !== null) {
            Database::connection()->prepare('UPDATE catalog_products SET category_id = :c WHERE id = :id')->execute(['c' => $categoryId, 'id' => $id]);
        }
        ProductApproval::afterCreate($id, $userId, $user ?? ['brand_id' => null]);
        return $id;
    }

    public function testASubCategoryFollowsItsMainCategoryThenTheBrandThenTheCompany(): void
    {
        $stone = $this->brand('stone');
        $main = $this->mainCategory('Natural Stones', (int) $stone['id'], ['default_hs_code' => '680293', 'packing_type' => 'Wooden crates', 'margin_pct' => null]);
        $sub = ProductCategoryRepository::create('Granite ' . $this->tag, [], $this->md, $main);
        $own = ProductCategoryRepository::findOwn($sub);
        self::assertSame((int) $stone['id'], (int) $own['brand_id'], 'a sub-category takes its main category\'s brand');
        self::assertSame([], $own['attributes']);

        $eff = ProductCategoryRepository::find($sub);
        self::assertSame('680293', (string) $eff['default_hs_code']);
        self::assertSame('Wooden crates', (string) $eff['packing_type']);
        self::assertSame(['Size', 'Finish'], $eff['attributes']);
        self::assertSame('Natural Stones ' . $this->tag . ' › Granite ' . $this->tag, ProductCategoryRepository::displayName($eff));

        Database::connection()->prepare('UPDATE product_categories SET packing_type = :p WHERE id = :id')->execute(['p' => 'Bundles', 'id' => $sub]);
        self::assertSame('Bundles', (string) ProductCategoryRepository::find($sub)['packing_type'], 'its own value comes first');

        // Pricing: empty everywhere = company (null here); the brand's value fills in; the category's own value wins.
        self::assertNull(ProductCategoryRepository::find($sub)['margin_pct'] ?? null);
        AssociateBrandRepository::saveSettings((int) $stone['id'], ['pricing_margin_pct' => '31'], $this->md);
        $eff = ProductCategoryRepository::find($sub);
        self::assertSame(31.0, (float) $eff['margin_pct']);
        self::assertTrue(!empty($eff['margin_pct_from_brand']));
        Database::connection()->prepare('UPDATE product_categories SET margin_pct = 28 WHERE id = :id')->execute(['id' => $main]);
        self::assertSame(28.0, (float) ProductCategoryRepository::find($sub)['margin_pct']);
    }

    public function testCategoriesScreensAddMainAndSubCategoriesWithTheirBrand(): void
    {
        $stone = $this->brand('stone');
        $this->asStaff($this->md);
        $r = $this->call('POST', '/products/categories', ['name' => 'Stones ' . $this->tag, 'attributes' => ['Size'], 'brand_id' => (string) $stone['id']]);
        self::assertStringContainsString('added with Size', (string) $r['flash']);
        $mainId = (int) Database::connection()->query("SELECT id FROM product_categories WHERE name = 'Stones {$this->tag}'")->fetchColumn();
        self::assertSame((int) $stone['id'], (int) ProductCategoryRepository::findOwn($mainId)['brand_id']);

        $r = $this->call('POST', '/products/categories', ['name' => 'Marble ' . $this->tag, 'parent_id' => (string) $mainId, 'attributes' => ['', '']]);
        self::assertStringContainsString('Sub-category "Marble ' . $this->tag . '" added under', (string) $r['flash']);
        $subId = (int) Database::connection()->query("SELECT id FROM product_categories WHERE name = 'Marble {$this->tag}'")->fetchColumn();
        self::assertSame($mainId, (int) ProductCategoryRepository::findOwn($subId)['parent_id']);

        $page = $this->call('GET', '/products/categories')['body'];
        self::assertStringContainsString('Add a sub-category', $page);
        self::assertStringContainsString('sub-category of Stones ' . $this->tag, $page);
        self::assertStringContainsString('Sub-category: Marble ' . $this->tag, $this->call('GET', "/products/categories/{$subId}")['body']);

        // No sub-category under a sub-category.
        $r = $this->call('POST', '/products/categories', ['name' => 'Deeper ' . $this->tag, 'parent_id' => (string) $subId, 'attributes' => ['']]);
        self::assertSame('Choose the main category for the sub-category.', $r['flash']);
    }

    public function testABrandManagerChangesOnlyTheirBrandsCatalogue(): void
    {
        $stone = $this->brand('stone');
        $textile = $this->brand('textile');
        $mineCat = $this->mainCategory('Stone cat', (int) $stone['id']);
        $theirCat = $this->mainCategory('Textile cat', (int) $textile['id']);
        $companyCat = $this->mainCategory('Company cat', null);
        $this->asStaff($this->brandManager((int) $stone['id']));

        $list = $this->call('GET', '/products/categories')['body'];
        self::assertStringContainsString('Stone cat ' . $this->tag, $list);
        self::assertStringContainsString('Company cat ' . $this->tag, $list);
        self::assertStringNotContainsString('Textile cat ' . $this->tag, $list);

        self::assertSame(404, $this->call('GET', "/products/categories/{$theirCat}")['code']);
        self::assertSame(200, $this->call('GET', "/products/categories/{$companyCat}")['code'], 'company-wide: view only');
        self::assertSame(404, $this->call('POST', "/products/categories/{$companyCat}", ['name' => 'Renamed ' . $this->tag, 'attributes' => ['Size']])['code']);
        self::assertSame(404, $this->call('POST', "/products/categories/{$theirCat}/settings", ['default_unit' => 'SQM', 'reason' => 'Not mine to change'])['code']);
        $r = $this->call('POST', "/products/categories/{$mineCat}", ['name' => 'Stone cat ' . $this->tag . ' B', 'attributes' => ['Size']]);
        self::assertStringContainsString('saved', (string) $r['flash']);

        // A new main category is always their brand's; brand pricing and settings stay with staff who see all brands.
        $this->call('POST', '/products/categories', ['name' => 'Mine new ' . $this->tag, 'attributes' => ['Size'], 'brand_id' => (string) $textile['id']]);
        self::assertSame((int) $stone['id'], (int) Database::connection()->query("SELECT brand_id FROM product_categories WHERE name = 'Mine new {$this->tag}'")->fetchColumn());
        $this->call('POST', '/products/categories', ['name' => 'Sneaky ' . $this->tag, 'parent_id' => (string) $theirCat, 'attributes' => ['']]);
        self::assertFalse(Database::connection()->query("SELECT id FROM product_categories WHERE name = 'Sneaky {$this->tag}'")->fetchColumn());
        $this->call('POST', '/admin/brands/' . $stone['id'] . '/pricing', ['action' => 'save', 'margin_pct' => '40', 'reason' => 'Trying my own pricing']);
        self::assertSame([], AssociateBrandRepository::settings((int) $stone['id']));
        self::assertSame(404, $this->call('POST', '/pricing/settings', ['margin_pct' => '10'])['code']);
    }

    public function testAProductABrandManagerAddsWaitsForApproval(): void
    {
        $stone = $this->brand('stone');
        $cat = $this->mainCategory('Stone cat', (int) $stone['id']);
        $managerId = $this->brandManager((int) $stone['id']);
        $manager = ['id' => $managerId, 'brand_id' => (int) $stone['id']];
        $pid = $this->product('Pending granite', $cat, $managerId, $manager);
        $p = ProductRepository::find($pid);
        self::assertSame('pending', $p['approval_status']);
        self::assertFalse(ProductRepository::isQuotable($pid), 'never quoted while waiting');
        self::assertNotContains($pid, array_map(static fn (array $r): int => (int) $r['id'], ProductRepository::forPicker()));

        // The approver sees it under Approvals › New products; the one who added it cannot approve it.
        $mdItems = array_values(array_filter(ApprovalsInbox::items($this->md, (int) Database::connection()->query("SELECT role_id FROM users WHERE id = {$this->md}")->fetchColumn()),
            static fn (array $i): bool => $i['key'] === 'product:' . $pid));
        self::assertCount(1, $mdItems);
        self::assertSame('products', $mdItems[0]['tab']);
        self::assertSame('Someone else approves a product you added.', ProductApproval::refusal($p, $managerId, (int) Database::connection()->query("SELECT role_id FROM users WHERE id = {$managerId}")->fetchColumn()));

        $this->asStaff($this->md);
        $r = $this->call('POST', "/products/{$pid}/approval", ['decision' => 'reject', 'reason' => 'short']);
        self::assertStringStartsWith('Give the reason the product is not approved', (string) $r['flash']);
        $r = $this->call('POST', "/products/{$pid}/approval", ['decision' => 'reject', 'reason' => 'HS code should be 680223 for this granite']);
        self::assertStringContainsString('is not approved', (string) $r['flash']);
        self::assertSame('rejected', ProductRepository::find($pid)['approval_status']);

        $this->asStaff($managerId);
        $this->call('POST', "/products/{$pid}/resubmit");
        self::assertSame('pending', ProductRepository::find($pid)['approval_status']);
        $this->asStaff($this->md);
        $r = $this->call('POST', "/products/{$pid}/approval", ['decision' => 'approve', 'reason' => '']);
        self::assertStringContainsString('is approved', (string) $r['flash']);
        self::assertSame('approved', ProductRepository::find($pid)['approval_status']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE entity_id = {$pid} AND entity_type = 'catalog_products' AND action_type = 'PRODUCT_APPROVED'")->fetchColumn());

        // Staff who see all brands add products as before — live at once; the setting off = live at once for everyone.
        self::assertSame('approved', ProductRepository::find($this->product('Staff product', $cat, $this->md))['approval_status']);
        Database::connection()->exec("UPDATE company_settings SET setting_value = '0' WHERE setting_key = 'brand_products_need_approval'");
        try {
            self::assertSame('approved', ProductRepository::find($this->product('Setting off', $cat, $managerId, $manager))['approval_status']);
        } finally {
            Database::connection()->exec("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'brand_products_need_approval'");
        }
    }

    public function testAssociatesSeeOnlyApprovedProductsOfTheirBrandAndCompanyWide(): void
    {
        $stone = $this->brand('stone');
        $textile = $this->brand('textile');
        $stoneCat = $this->mainCategory('Stone cat', (int) $stone['id']);
        $textileCat = $this->mainCategory('Textile cat', (int) $textile['id']);
        $companyCat = $this->mainCategory('Company cat', null);
        $a = $this->associate((int) $stone['id']);
        $stoneP = ProductRepository::find($this->product('Stone product', $stoneCat, $this->md));
        $textileP = ProductRepository::find($this->product('Textile product', $textileCat, $this->md));
        $companyP = ProductRepository::find($this->product('Company product', $companyCat, $this->md));
        self::assertTrue(PriceRequestService::inAssociateBrand($a, $stoneP));
        self::assertTrue(PriceRequestService::inAssociateBrand($a, $companyP));
        self::assertFalse(PriceRequestService::inAssociateBrand($a, $textileP));
        $stoneP['approval_status'] = 'pending';
        self::assertFalse(PriceRequestService::inAssociateBrand($a, $stoneP), 'a product waiting for approval is never shown');
    }

    public function testBrandPricingAndMovingProductsWithinABrand(): void
    {
        $stone = $this->brand('stone');
        $textile = $this->brand('textile');
        $main = $this->mainCategory('Stone cat', (int) $stone['id']);
        $sub = ProductCategoryRepository::create('Sub ' . $this->tag, [], $this->md, $main);
        $other = $this->mainCategory('Textile cat', (int) $textile['id']);
        $this->asStaff($this->md);

        $r = $this->call('POST', '/admin/brands/' . $stone['id'] . '/pricing', ['action' => 'save', 'margin_pct' => '33.5', 'other_cost_pct' => '', 'buffer_pct' => '4', 'reason' => 'Stone margins agreed for 2027']);
        self::assertSame('Brand pricing saved. It applies to prices worked out from now on.', $r['flash']);
        self::assertSame(['pricing_buffer_pct' => '4', 'pricing_margin_pct' => '33.5'], array_intersect_key(AssociateBrandRepository::settings((int) $stone['id']), ['pricing_buffer_pct' => 1, 'pricing_margin_pct' => 1]));
        self::assertStringContainsString('data-brand-pricing', $this->call('GET', '/admin/brands/' . $stone['id'])['body']);
        $r = $this->call('POST', '/admin/brands/' . $stone['id'] . '/pricing', ['action' => 'save', 'margin_pct' => '95', 'reason' => 'Far too high margin']);
        self::assertStringContainsString('Margin % must be a number from 0 to 89.99', (string) $r['flash']);

        $pid = $this->product('Movable', $main, $this->md);
        $r = $this->call('POST', '/products/move-category', ['product_ids' => [(string) $pid], 'category_id' => (string) $sub, 'return_to' => '/products']);
        self::assertStringStartsWith('1 product(s) moved to', (string) $r['flash']);
        self::assertSame($sub, (int) ProductRepository::find($pid)['category_id']);
        $r = $this->call('POST', '/products/move-category', ['product_ids' => [(string) $pid], 'category_id' => (string) $other, 'return_to' => '/products']);
        self::assertStringContainsString('Not moved (another brand', (string) $r['flash']);
        self::assertSame($sub, (int) ProductRepository::find($pid)['category_id']);
    }
}
