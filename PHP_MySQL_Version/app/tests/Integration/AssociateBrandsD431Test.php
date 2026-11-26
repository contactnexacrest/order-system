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
use App\Services\AssociateCodeService;
use App\Services\AssociateCommission;
use App\Services\AssociateRules;
use App\Services\AssociateService;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\PriceRequestService;
use App\Tests\Support\DbTestCase;

/**
 * D-431 (owner 25–26 Nov 2026): Brands for divisions, associate side only.
 *  - Admin › Brands: name only (no logo), the line under it, login address code; Rules (empty = company setting,
 *    "Copy all company settings", "Clear all") and an own ladder, each change with a reason.
 *  - Rules resolve: the associate's own value, then the brand's, then the company's.
 *  - The associate login, portal and e-mails carry the brand name; buyers never see it (the buyer form keeps a clean
 *    address bar). One associate = one brand; only "Manage divisions" moves an associate, with a reason.
 * Node twin: associateBrandsD431.test.js.
 */
final class AssociateBrandsD431Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $md;
    private string $tag;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__d431-routes-build__';
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
        AssociateRules::reset();
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
        AssociateRules::reset();
        parent::tearDown();
    }

    /** @return array{body: string, flash: ?string, code: int} */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        $q = parse_url($path, PHP_URL_QUERY);
        $_GET = [];
        if (is_string($q)) {
            parse_str($q, $_GET);
        }
        http_response_code(200);
        PermissionService::resetCache();
        AssociateBrands::reset();
        ob_start();
        try {
            self::router()->dispatch($method, $path);
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

    private function brand(array $extra = []): array
    {
        $id = AssociateBrandRepository::save(null, $extra + ['code' => 'st' . $this->tag, 'name' => 'Stone Division ' . $this->tag, 'tagline' => AssociateBrands::DEFAULT_TAGLINE,
            'products' => 'Natural Stone', 'website' => null, 'email' => null, 'status' => 'active'], $this->md);
        return AssociateBrandRepository::find($id);
    }

    private function associate(?int $brandId = null): array
    {
        $id = AssociateRepository::create(['name' => 'D431 Associate ' . $this->tag . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'associate-' . bin2hex(random_bytes(4)) . '@email.com', 'status' => 'active', 'portal_enabled' => 1], $this->md);
        Database::connection()->exec("UPDATE associates SET portal_enabled = 1 WHERE id = {$id}");
        AssociateCodeService::issue($id, 'B' . strtoupper($this->tag) . substr((string) $id, -2));
        if ($brandId !== null) {
            AssociateBrandRepository::setAssociateBrand($id, $brandId);
        }
        return AssociateRepository::find($id);
    }

    public function testRulesFollowTheCompanyUntilTheBrandSetsItsOwn(): void
    {
        $b = $this->brand();
        $a = $this->associate((int) $b['id']);
        $plain = $this->associate();
        $company = AssociateBrands::companyValues();
        self::assertSame((float) $company['min_order_inr'], AssociateRules::minOrderInr((int) $a['id']), 'empty = the company setting');
        self::assertSame(AssociateRules::companyGroupDays(), AssociateRules::groupDays((int) $a['id']));

        AssociateBrandRepository::saveSettings((int) $b['id'], ['min_order_inr' => '1000000.00', 'group_days' => '30', 'price_requests_per_day' => '3', 'price_valid_days' => '14',
            'price_rule' => 'fixed', 'price_fixed_pct' => '4.00'], $this->md);
        AssociateBrands::reset();
        self::assertSame(1000000.0, AssociateRules::minOrderInr((int) $a['id']), "the brand's own minimum");
        self::assertSame(30, AssociateRules::groupDays((int) $a['id']));
        self::assertSame((float) $company['min_order_inr'], AssociateRules::minOrderInr((int) $plain['id']), 'an associate without a brand keeps the company setting');
        $fresh = AssociateRepository::find((int) $a['id']);
        self::assertSame(3, PriceRequestService::dailyLimit($fresh));
        self::assertSame(14, PriceRequestService::validDays($fresh));
        $rule = AssociateRules::priceRuleFor($fresh);
        self::assertSame(['fixed', 4.0, true], [$rule['rule'], $rule['pct'], $rule['brand']]);

        // The associate's own value still comes first.
        Database::connection()->exec("UPDATE associates SET price_request_daily_limit = 7 WHERE id = {$a['id']}");
        self::assertSame(7, PriceRequestService::dailyLimit(AssociateRepository::find((int) $a['id'])));
    }

    public function testTheBrandLadderReplacesTheCompanyLadder(): void
    {
        $b = $this->brand();
        $a = $this->associate((int) $b['id']);
        $level = (int) Database::connection()->query('SELECT id FROM commission_levels WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        self::assertSame(\App\Repositories\PricingRepository::ladder(), AssociateCommission::ladderFor($a), 'empty = the company ladder');
        AssociateBrandRepository::replaceLadder((int) $b['id'], [['from' => 1, 'to' => null, 'level_id' => $level]], $this->md);
        $steps = AssociateCommission::ladderFor($a);
        self::assertCount(1, $steps);
        self::assertSame($level, (int) $steps[0]['level_id']);
    }

    public function testAdminBrandsScreenAndRulesWithAReason(): void
    {
        $this->asStaff($this->md);
        $page = $this->call('GET', '/admin/brands')['body'];
        self::assertStringContainsString('data-brands-intro', $page);
        $r = $this->call('POST', '/admin/brands', ['name' => 'Textile Division ' . $this->tag, 'code' => 'tx' . $this->tag, 'tagline' => '', 'status' => 'active']);
        self::assertStringStartsWith('Brand Textile Division', (string) $r['flash']);
        $b = AssociateBrandRepository::findByCode('tx' . $this->tag);
        self::assertSame(AssociateBrands::DEFAULT_TAGLINE, $b['tagline'], 'an empty line gets the company default');
        self::assertStringContainsString('Another brand already uses this login address code.', (string) $this->call('POST', '/admin/brands', ['name' => 'Other ' . $this->tag, 'code' => 'tx' . $this->tag, 'status' => 'active'])['flash']);
        self::assertStringContainsString('login address code', (string) $this->call('POST', '/admin/brands', ['name' => 'Other ' . $this->tag, 'code' => 'code', 'status' => 'active'])['flash'], '"code" is a portal page');

        $id = (int) $b['id'];
        self::assertStringContainsString('data-brand-rules-table', $this->call('GET', "/admin/brands/{$id}")['body']);
        self::assertStringContainsString('Give the reason', (string) $this->call('POST', "/admin/brands/{$id}/rules", ['action' => 'copy_all', 'reason' => 'short'])['flash']);
        $this->call('POST', "/admin/brands/{$id}/rules", ['action' => 'copy_all', 'reason' => 'Start from the company settings for this division']);
        $copied = AssociateBrandRepository::settings($id);
        self::assertSame(AssociateBrands::companyValues()['group_days'], $copied['group_days'], 'Copy all fills every rule with the company value');
        $this->call('POST', "/admin/brands/{$id}/rules", ['action' => 'clear_all', 'reason' => 'Follow the company settings again']);
        self::assertSame([], AssociateBrandRepository::settings($id), 'Clear all = follow the company');
        $this->call('POST', "/admin/brands/{$id}/rules", ['action' => 'save', 'min_order_inr' => '750000', 'group_days' => '', 'reason' => 'Textile orders are smaller than stone orders']);
        self::assertSame(['min_order_inr' => '750000.00'], AssociateBrandRepository::settings($id));
        $audit = (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ASSOCIATE_BRAND_RULE_CHANGED' AND entity_id = {$id}")->fetchColumn();
        self::assertGreaterThan(0, $audit);
    }

    public function testOnlyManageDivisionsChangesBrandDetailsAndTheAssociatesBrand(): void
    {
        $b = $this->brand();
        $a = $this->associate();
        $clerk = $this->createTestUser('Export Executive');
        $this->asStaff($clerk);
        $this->call('POST', '/associates/' . $a['id'] . '/brand', ['brand_id' => (string) $b['id'], 'reason' => 'Joined through the stone division']);
        self::assertNull(AssociateRepository::find((int) $a['id'])['brand_id']);

        $this->asStaff($this->md);
        $r = $this->call('POST', '/associates/' . $a['id'] . '/brand', ['brand_id' => (string) $b['id'], 'reason' => 'Joined through the stone division']);
        self::assertStringStartsWith('Brand changed to', (string) $r['flash']);
        self::assertSame((int) $b['id'], (int) AssociateRepository::find((int) $a['id'])['brand_id']);
        self::assertStringContainsString('data-associate-brand', $this->call('GET', '/associates/' . $a['id'])['body']);
    }

    public function testTheAssociateSeesTheBrandAndBuyersDoNot(): void
    {
        $b = $this->brand();
        $a = $this->associate((int) $b['id']);
        // The brand's login address remembers the brand for the login page.
        $this->call('GET', '/associate/login/' . $b['code']);
        self::assertSame($b['code'], $_SESSION[AssociateBrands::SESSION_KEY] ?? null);
        $login = $this->call('GET', '/associate/login')['body'];
        self::assertStringContainsString('For ' . $b['name'] . ' associates.', $login);
        self::assertStringContainsString('data-portal-brand-footer', $login);
        $_SESSION = [];

        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        $home = $this->call('GET', '/associate')['body'];
        self::assertStringContainsString('data-portal-brand', $home);
        self::assertStringContainsString((string) $b['name'], $home);
        self::assertStringContainsString('Your agreement and your payments are with NexaCrest International Private Limited.', $home);
        self::assertStringContainsString(AssociateBrands::BUYER_TIP, $home);

        [$subject, $body] = AssociateBrands::email($a, 'Your NexaCrest login code', "Your login code is 123456.\n\nNexaCrest International Private Limited");
        self::assertSame('Your ' . $b['name'] . ' login code', $subject);
        self::assertStringStartsWith($b['name'] . ' — ' . AssociateBrands::DEFAULT_TAGLINE, $body);
        self::assertStringEndsWith(AssociateBrands::legalLine($b), $body);
        self::assertSame(\App\Config\Env::get('APP_URL', '') . '/associate/login/' . $b['code'], AssociateService::loginUrl($a));

        // The buyer's form: the code is kept for the visit, the address bar shows only /quotation-details, and no brand.
        $_SESSION = [];
        $r = $this->call('GET', '/quotation-details?code=' . $a['personal_code']);
        self::assertSame(303, $r['code']);
        $form = $this->call('GET', '/quotation-details')['body'];
        self::assertStringContainsString('name="associate_code" value="' . $a['personal_code'] . '"', $form);
        self::assertStringNotContainsString((string) $b['name'], $form, 'a buyer never sees the brand');
    }

    public function testTheRegistrationLinkCarriesTheBrand(): void
    {
        $b = $this->brand();
        $raw = \App\Repositories\AssociateRegistrationRepository::createLink('Trade fair', $this->md, (int) $b['id']);
        $_SESSION = [];
        $page = $this->call('GET', '/associate-register/' . $raw)['body'];
        self::assertStringContainsString('Register as a ' . $b['name'] . ' associate', $page);
        self::assertStringContainsString('Your agreement and your payments are with NexaCrest International Private Limited.', $page);
    }
}
