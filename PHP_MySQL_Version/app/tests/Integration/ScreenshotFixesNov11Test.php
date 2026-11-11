<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociatePayoutService;
use App\Services\AuthService;
use App\Services\Navigation;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Small UI fixes found while screenshotting the 11 Nov 2026 build (Node twin: tests/integration/screenshotFixesNov11.test.js):
 * 1. Products › Price rules is the active bar item on a category's Price rules tab, "Pricing ▾" on Pricing settings;
 *    the "+ [ ] %" input stays on one line; the category tabs sit on one tidy row.
 * 2. Supplier thickness prices: inputs wide enough, the long legends written as typed (not Title Case).
 * 3. Suppliers list: the table scrolls inside the card, actions stacked.
 * 4. Associate portal › My commission: "How it works" and "How we set your commission" name the same currency
 *    (D-264: India always rupees, abroad the payout currency).
 * 5. Approvals: the bar's "E-mails" count is what this person may approve, as the page's chip.
 * Both UI templates share these views and the section bar.
 */
final class ScreenshotFixesNov11Test extends DbTestCase
{
    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__shots-nov11-routes-build__';
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
        parent::tearDown();
    }

    private function get(string $path): string
    {
        Flash::pull();
        $_POST = [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch('GET', (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        return $body;
    }

    private function asUser(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        Csrf::token();
    }

    private static function read(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    /** @return list<string> */
    private static function activeLabels(array $bar): array
    {
        return array_values(array_map(static fn (array $i): string => $i['label'], array_filter($bar['items'], static fn (array $i): bool => $i['active'])));
    }

    public function testProductsBarMarksPriceRulesAndPricing(): void
    {
        $all = static fn (): bool => true;
        $flags = ['approver' => true, 'mod_associates' => true];
        self::assertSame(['Price rules'], self::activeLabels(Navigation::build($all, $flags, [], '/products/categories/5', ['tab' => 'rules'])['bar']));
        self::assertSame(['Categories'], self::activeLabels(Navigation::build($all, $flags, [], '/products/categories/5', ['tab' => 'general'])['bar']));
        self::assertSame(['Categories'], self::activeLabels(Navigation::build($all, $flags, [], '/products/categories/5', [])['bar']));
        self::assertSame(['Categories'], self::activeLabels(Navigation::build($all, $flags, [], '/products/categories', [])['bar']));
        $pricing = Navigation::build($all, $flags, [], '/pricing/settings', [])['bar'];
        self::assertSame(['Pricing'], self::activeLabels($pricing));
        $drop = array_values(array_filter($pricing['items'], static fn (array $i): bool => $i['label'] === 'Pricing'))[0];
        self::assertSame(['Pricing settings (margin, buffer, exchange rate)'],
            array_values(array_map(static fn (array $c): string => $c['label'], array_filter($drop['children'], static fn (array $c): bool => $c['active']))));
        // The drop-down's summary is drawn like the other bar items (the sitewide "▸ button" look skips it), so its gold underline shows.
        self::assertStringContainsString('<details class="section-nav-details">', self::read('app/views/partials/section_nav.php'));
        $css = self::read('public_html/assets/css/app.css');
        self::assertStringContainsString(':not(.dept-switch):not(.section-nav-details) > summary{', $css);
        self::assertStringContainsString('.section-nav-drop.active > details > summary{font-weight:700;border-bottom-color:var(--gold);}', $css);
    }

    public function testPriceRuleInputOnOneLineAndCategoryTabsOneRow(): void
    {
        $rules = self::read('app/views/products/_price_rules.php');
        self::assertStringContainsString('<td class="rule-cell"><?php if ($__t[\'base\']): ?>base<?php else: ?>+ <input type="text"', $rules);
        self::assertStringContainsString('class="rule-input"', $rules);
        self::assertStringNotContainsString('style="width:6em"', $rules);
        $css = self::read('public_html/assets/css/app.css');
        self::assertStringContainsString('.tab-pills{display:flex;flex-wrap:wrap;gap:0.5rem;margin:0.75rem 0 1.5rem;}', $css);
        self::assertStringContainsString('td.rule-cell{white-space:nowrap;}', $css);
        self::assertStringContainsString('td.rule-cell input.rule-input{display:inline-block;width:6em;', $css);
    }

    public function testThicknessPricesWideEnoughAndLegendsInSentenceCase(): void
    {
        $css = self::read('public_html/assets/css/app.css');
        self::assertStringContainsString('.field-grid > fieldset.full{grid-column:1 / -1;}', $css);
        self::assertStringContainsString('.thickness-grid input.grid-price{min-width:14ch;}', $css);
        self::assertStringContainsString('legend.legend-sentence{text-transform:none;}', $css);
        // The global legend style (capitalised short titles) is left as it is.
        self::assertStringContainsString('legend{padding:0 0.5rem;font-weight:700;color:var(--navy);text-transform:capitalize;}', $css);
        foreach (['app/views/products/supplier_price_edit.php', 'app/views/products/market_prices.php'] as $view) {
            self::assertStringContainsString('<legend class="legend-sentence">Prices by thickness (₹) — enter any one; the others are worked out. Change any of them if the supplier quoted it.</legend>', self::read($view));
        }
        self::assertStringContainsString('<legend class="legend-sentence">CHA + transport (one price per container)</legend>', self::read('app/views/products/_cha_transport.php'));
    }

    public function testSuppliersTableScrollsInsideItsCard(): void
    {
        $this->asUser($this->createTestUser('Managing Director'));
        $page = $this->get('/suppliers');
        self::assertStringContainsString("<div class=\"table-scroll\">\n    <table class=\"list suppliers-table\">", $page);
        self::assertStringContainsString('<div class="row-actions">', $page);
        self::assertStringContainsString('.suppliers-table .row-actions{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}', self::read('public_html/assets/css/app.css'));
    }

    public function testPaidInCurrencyRule(): void
    {
        self::assertSame('INR', AssociatePayoutService::payCurrency(['based_in' => 'india', 'currency_code' => 'USD']));
        self::assertSame('INR', AssociatePayoutService::payCurrency(['based_in' => 'india', 'currency_code' => 'INR']));
        self::assertSame('EUR', AssociatePayoutService::payCurrency(['based_in' => 'abroad', 'currency_code' => 'EUR']));
        self::assertSame('USD', AssociatePayoutService::payCurrency(['based_in' => 'abroad', 'currency_code' => '']));
        self::assertSame('USD', AssociatePayoutService::payCurrency(['based_in' => 'abroad', 'currency_code' => 'INR']));
    }

    private function associate(array $extra): array
    {
        $staff = $this->createTestUser('Admin');
        $tag = strtoupper(bin2hex(random_bytes(3)));
        $id = AssociateRepository::create($extra + ['name' => 'Shots Associate ' . $tag, 'email' => 'associate-' . strtolower($tag) . '@email.com',
            'status' => 'active', 'portal_enabled' => 1], $staff);
        $stmt = Database::connection()->prepare('UPDATE associates SET portal_enabled = 1, currency_code = :c WHERE id = :id');
        $stmt->execute(['c' => $extra['currency_code'], 'id' => $id]);
        AssociateCodeService::issue($id, 'S' . $tag . substr((string) $id, -2));
        return AssociateRepository::find($id);
    }

    private function commissionPage(array $a): string
    {
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        return str_replace('&#039;', "'", $this->get('/associate/commission'));
    }

    public function testIndiaAssociateReadsRupeesInBothPlaces(): void
    {
        $page = $this->commissionPage($this->associate(['based_in' => 'india', 'country' => 'India', 'currency_code' => 'USD']));
        self::assertStringContainsString('in Indian rupees to your verified bank account.</li>', $page);
        self::assertStringNotContainsString('in US dollars to your verified bank account', $page);
        self::assertStringContainsString('we turn it into rupees at the rate the bank used for the buyer\'s payment. We pay you that rupee amount.', $page);
    }

    public function testAbroadAssociateReadsTheirCurrencyInBothPlaces(): void
    {
        $page = $this->commissionPage($this->associate(['based_in' => 'abroad', 'country' => 'Germany', 'currency_code' => 'EUR']));
        self::assertStringContainsString('in euros to your verified bank account.</li>', $page);
        self::assertStringContainsString('we set the amount in euros. We pay you exactly that amount in euros into your checked bank account.', $page);
        self::assertStringNotContainsString('rupee', $page);
    }

    public function testApprovalsBarEmailCountMatchesTheChip(): void
    {
        $md = $this->createTestUser('Managing Director');
        $sales = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $ids = [];
        // One e-mail waiting for Level-2 approval (counted), one already approved and waiting to go out (not an approval).
        $pdo->prepare("INSERT INTO email_log (order_id, template_key, recipient_email, subject, body_snapshot, status, requested_by)
                       VALUES (:o, 'test', 'buyer@email.com', 'PHPUnit shots pending', 'Body', 'pending_approval', :u)")->execute(['o' => $orderId, 'u' => $sales]);
        $ids[] = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO email_log (order_id, template_key, recipient_email, subject, body_snapshot, status, requested_by, scheduled_at)
                       VALUES (:o, 'test', 'buyer@email.com', 'PHPUnit shots approved', 'Body', 'approved', :u, DATE_ADD(NOW(), INTERVAL 7 DAY))")->execute(['o' => $orderId, 'u' => $sales]);
        $ids[] = (int) $pdo->lastInsertId();
        try {
            $this->asUser($md);
            $page = $this->get('/approvals');
            self::assertSame(1, preg_match('#href="/approvals\?tab=emails">E-mails (\d+)</a>#', $page, $chip));
            self::assertSame(1, preg_match('#<a href="/email-approvals"[^>]*>E-mails(?: <span class="nav-badge">(\d+)</span>)?</a>#', $page, $bar));
            self::assertGreaterThanOrEqual(1, (int) $chip[1]);
            self::assertSame((int) $chip[1], (int) ($bar[1] ?? 0));
        } finally {
            $pdo->exec("UPDATE email_log SET status = 'cancelled' WHERE id IN (" . implode(',', $ids) . ')');
        }
    }
}
