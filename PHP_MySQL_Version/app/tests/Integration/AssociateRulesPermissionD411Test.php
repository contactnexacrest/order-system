<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateRepository;
use App\Repositories\PricingRepository;
use App\Services\AssociateRules;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-411 (24 Nov 2026): "Manage associate rules". The associate commission rules (commission levels and
 * the ladder, what the client's price includes, an associate's own rule, Associates › Settings, payment advice
 * corrections, a client's associate, the portal access levels) are changed by the MD, the ED and anyone given the
 * permission in Admin › Roles (or one person) — not by Admin by default, not by other staff. Node twin:
 * tests/integration/associateRulesPermissionD411.test.js.
 */
final class AssociateRulesPermissionD411Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $md;
    private int $ed;
    private int $admin;
    private int $exec;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__d411-routes-build__';
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
        $this->md = $this->createTestUser('Managing Director');
        $this->ed = $this->createTestUser('Executive Director');
        $this->admin = $this->createTestUser('Admin');
        $this->exec = $this->createTestUser('Export Executive');
        Database::connection()->exec("UPDATE users SET is_super_admin = 0 WHERE id IN ({$this->md}, {$this->ed}, {$this->admin}, {$this->exec})");
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
        PermissionService::resetCache();
        parent::tearDown();
    }

    /** @return array{code: int, body: string, flash: ?string, type: ?string} */
    private function call(int $userId, string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $all = Flash::pull();
        $flash = end($all) ?: null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    /** The POST routes that change an associate rule (D-411). @return list<string> */
    private function rulePosts(int $associateId): array
    {
        return [
            '/pricing/settings/associate-price-rule',
            '/pricing/commission-levels',
            '/pricing/commission-ladder',
            '/associates/access-levels',
            "/associates/{$associateId}/commission-rule",
            "/associates/{$associateId}/payment-advices/999999/revise",
        ];
    }

    public function testTheMdAndTheEdHoldThePermissionAndAdminDoesNot(): void
    {
        $roles = Database::connection()->query("SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                                                 WHERE p.permission_key = 'manage_associate_rules' AND rp.is_enabled = 1 ORDER BY r.name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['Executive Director', 'Managing Director'], $roles);
        self::assertSame('Manage associate rules', Database::connection()->query("SELECT name FROM permissions WHERE permission_key = 'manage_associate_rules'")->fetchColumn());
        self::assertTrue(AssociateRules::canManage(\App\Repositories\UserRepository::findById($this->ed)));
        self::assertFalse(AssociateRules::canManage(\App\Repositories\UserRepository::findById($this->admin)));
    }

    public function testOnlyPeopleWithThePermissionPassTheRuleRoutes(): void
    {
        $a = AssociateRepository::find(AssociateRepository::create(['name' => 'D411 Associate ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => null, 'phone' => null, 'tax_id' => null, 'agreement_signed_on' => '2025-12-01', 'status' => 'active', 'notes' => null], $this->md));
        foreach ($this->rulePosts((int) $a['id']) as $path) {
            foreach ([$this->admin, $this->exec] as $who) {
                self::assertSame(403, $this->call($who, 'POST', $path)['code'], "{$path}: refused without the permission");
            }
            foreach ([$this->md, $this->ed] as $who) {
                self::assertNotSame(403, $this->call($who, 'POST', $path)['code'], "{$path}: the MD and the ED pass the gate");
            }
        }
    }

    public function testTheEdChangesWhatTheClientPriceIncludesWithAReason(): void
    {
        $r = $this->call($this->ed, 'POST', '/pricing/settings/associate-price-rule', ['associate_price_rule' => 'fixed', 'associate_price_fixed_pct' => '6.00']);
        self::assertSame('error', $r['type']);
        self::assertStringContainsString("Give the reason for changing what the client's price includes", (string) $r['flash']);
        self::assertSame('5.00', (string) (PricingRepository::settings()['associate_price_fixed_pct'] ?? '5.00'));
        $r = $this->call($this->ed, 'POST', '/pricing/settings/associate-price-rule', ['associate_price_rule' => 'fixed', 'associate_price_fixed_pct' => '6.00', 'associate_price_reason' => 'Margin review by the ED, November']);
        self::assertSame('success', $r['type']);
        self::assertSame('6.00', (string) PricingRepository::settings()['associate_price_fixed_pct']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE user_id = {$this->ed} AND action_type = 'PRICING_SETTING_CHANGED' AND new_value LIKE '%Margin review by the ED%'")->fetchColumn());
        PricingRepository::setSetting('associate_price_fixed_pct', '5.00', $this->md);
    }

    public function testTheGeneralSettingsFormNoLongerChangesTheAssociateRule(): void
    {
        $settings = PricingRepository::settings();
        $this->call($this->md, 'POST', '/pricing/settings', ['associate_price_rule' => 'ladder', 'associate_price_reason' => 'Should be ignored by this form'] + $settings);
        self::assertSame('fixed', AssociateRules::companyPriceRule()['rule']);
    }

    public function testTheEdSeesTheFormsAndOthersSeeWhoChangesThem(): void
    {
        $page = $this->call($this->ed, 'GET', '/pricing/settings')['body'];
        self::assertStringContainsString('action="/pricing/settings/associate-price-rule"', $page);
        self::assertStringContainsString("Save what the client's price includes", $page);
        self::assertStringContainsString('Only the MD can change these settings.', $page, 'the other pricing settings stay with Manage pricing');
        $levels = $this->call($this->ed, 'GET', '/pricing/commission-levels')['body'];
        self::assertStringContainsString('Save ladder', $levels);
        self::assertStringNotContainsString('data-rules-read-only', $levels);
    }

    public function testThePermissionCanBeGivenToOnePerson(): void
    {
        self::assertSame(403, $this->call($this->exec, 'POST', '/pricing/commission-ladder')['code']);
        Database::connection()->prepare("INSERT INTO user_permissions (user_id, permission_id, is_enabled, granted_by, reason)
            SELECT :u, id, 1, :by, 'D-411 test: one person may manage the associate rules' FROM permissions WHERE permission_key = 'manage_associate_rules'")
            ->execute(['u' => $this->exec, 'by' => $this->md]);
        self::assertNotSame(403, $this->call($this->exec, 'POST', '/pricing/commission-ladder')['code']);
        self::assertTrue(AssociateRules::canManage(\App\Repositories\UserRepository::findById($this->exec)));
    }
}
