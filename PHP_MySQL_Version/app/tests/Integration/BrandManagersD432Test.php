<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateBrandRepository;
use App\Repositories\AssociateRepository;
use App\Services\AssociateBrands;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * D-432 (owner 26 Nov 2026): brand managers. A staff member given a brand (Admin › Users › Brand, "Manage divisions")
 * sees and changes only that brand: its associates, their pages and actions, the brand's rules and ladder, its
 * registrations, price requests, report and payout lines. Never another brand, never brand details, never a
 * company-wide setting, never moving an associate between brands. Empty = all brands, as before.
 * Node twin: brandManagersD432.test.js.
 */
final class BrandManagersD432Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $md;
    private string $tag;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__d432-routes-build__';
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
        $id = AssociateRepository::create(['name' => 'D432 Associate ' . $this->tag . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
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

    public function testOnlyStaffWhoSeeAllBrandsGiveSomeoneABrand(): void
    {
        $stone = $this->brand('stone');
        $clerk = $this->createTestUser('Export Executive');
        $this->asStaff($this->md);
        $r = $this->call('POST', "/users/{$clerk}/brand", ['brand_id' => (string) $stone['id']]);
        self::assertStringStartsWith('Saved: this person now looks after', (string) $r['flash']);
        self::assertSame((int) $stone['id'], (int) Database::connection()->query("SELECT brand_id FROM users WHERE id = {$clerk}")->fetchColumn());
        self::assertStringContainsString('data-testid="user-brand"', $this->call('GET', "/users/{$clerk}/edit")['body']);

        $manager = $this->brandManager((int) $stone['id']);
        $this->asStaff($manager);
        $r = $this->call('POST', "/users/{$clerk}/brand", ['brand_id' => '']);
        self::assertSame('Only staff who look after all brands can give people a brand.', $r['flash']);
    }

    public function testABrandManagerSeesAndChangesOnlyTheirBrand(): void
    {
        $stone = $this->brand('stone');
        $textile = $this->brand('textile');
        $mine = $this->associate((int) $stone['id']);
        $theirs = $this->associate((int) $textile['id']);
        $none = $this->associate(null);
        $this->asStaff($this->brandManager((int) $stone['id']));

        $list = $this->call('GET', '/associates?view=list')['body'];
        self::assertStringContainsString((string) $mine['name'], $list);
        self::assertStringNotContainsString((string) $theirs['name'], $list);
        self::assertStringNotContainsString((string) $none['name'], $list);

        self::assertStringContainsString('data-associate-brand', $this->call('GET', '/associates/' . $mine['id'])['body']);
        self::assertStringNotContainsString('data-brand-change', $this->call('GET', '/associates/' . $mine['id'])['body'], 'never moves an associate between brands');
        self::assertSame(404, $this->call('GET', '/associates/' . $theirs['id'])['code']);
        self::assertSame(404, $this->call('GET', '/associates/' . $none['id'])['code']);
        self::assertSame(404, $this->call('POST', '/associates/' . $theirs['id'] . '/status', ['status' => 'suspended', 'reason' => 'Not my brand at all'])['code']);
        self::assertSame('active', AssociateRepository::find((int) $theirs['id'])['status']);
        self::assertSame(404, $this->call('POST', '/associates/' . $mine['id'] . '/brand', ['brand_id' => (string) $textile['id'], 'reason' => 'Trying to move them away'])['code']);
        self::assertSame((int) $stone['id'], (int) AssociateRepository::find((int) $mine['id'])['brand_id']);

        // Admin › Brands: only their brand; its rules with a reason; never another brand or the details.
        $index = $this->call('GET', '/admin/brands')['body'];
        self::assertStringContainsString((string) $stone['name'], $index);
        self::assertStringNotContainsString((string) $textile['name'], $index);
        self::assertStringNotContainsString('Add brand', $index);
        self::assertSame(404, $this->call('GET', '/admin/brands/' . $textile['id'])['code']);
        $this->call('POST', '/admin/brands/' . $stone['id'] . '/rules', ['action' => 'save', 'group_days' => '20', 'reason' => 'Stone orders come in batches']);
        self::assertSame(['group_days' => '20'], AssociateBrandRepository::settings((int) $stone['id']));
        self::assertSame(404, $this->call('POST', '/admin/brands/' . $textile['id'] . '/rules', ['action' => 'save', 'group_days' => '20', 'reason' => 'Not my brand to change'])['code']);
        self::assertSame([], AssociateBrandRepository::settings((int) $textile['id']));
        self::assertSame(404, $this->call('POST', '/admin/brands/' . $stone['id'], ['name' => 'Renamed', 'code' => 'renamed' . $this->tag, 'status' => 'active'])['code']);

        // Company-wide settings stay with staff who see all brands.
        self::assertSame(404, $this->call('POST', '/associates/ladder', ['associate_group_days' => '20'])['code']);
        self::assertSame(404, $this->call('POST', '/admin/brands', ['name' => 'New ' . $this->tag, 'code' => 'new' . $this->tag, 'status' => 'active'])['code']);
    }

    public function testWhatABrandManagerAddsBelongsToTheirBrand(): void
    {
        $stone = $this->brand('stone');
        $this->asStaff($this->brandManager((int) $stone['id']));
        $id = AssociateRepository::create(['name' => 'Added by manager ' . $this->tag, 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'added-' . bin2hex(random_bytes(4)) . '@email.com', 'status' => 'invited'], $this->md);
        self::assertSame((int) $stone['id'], (int) AssociateRepository::find($id)['brand_id']);
        $this->call('POST', '/associates/registrations/links', ['note' => 'Fair ' . $this->tag, 'brand_id' => '']);
        $brandId = Database::connection()->query("SELECT brand_id FROM associate_registration_links WHERE note = 'Fair {$this->tag}'")->fetchColumn();
        self::assertSame((int) $stone['id'], (int) $brandId, 'a brand manager\'s registration links are for their brand');
    }

    public function testStaffWithNoBrandSeeEverythingAsBefore(): void
    {
        $stone = $this->brand('stone');
        $textile = $this->brand('textile');
        $a = $this->associate((int) $stone['id']);
        $b = $this->associate((int) $textile['id']);
        $this->asStaff($this->md);
        $list = $this->call('GET', '/associates?view=list')['body'];
        self::assertStringContainsString((string) $a['name'], $list);
        self::assertStringContainsString((string) $b['name'], $list);
        self::assertSame(200, $this->call('GET', '/associates/' . $b['id'])['code']);
    }
}
