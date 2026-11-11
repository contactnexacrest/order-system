<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateRepository;
use App\Services\AssociateAccess;
use App\Services\AssociateService;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * D-261 (owner approval 6 Nov 2026, mockups mock13): associate portal access levels.
 * Starter / Quoting / Full from the MD's matrix, Custom per associate; hidden features
 * refused by the server (lock page, 403); the FOB value and the commission % taken out
 * on the server; MD-only changes with a reason, logged; the "Ready for Quoting?"
 * suggestion after 3 completed, fully paid orders. Node twin: associateAccessLevels.test.js.
 */
final class AssociateAccessLevelsTest extends DbTestCase
{
    private static ?Router $router = null;
    private int $md;
    private int $admin;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__associate-access-build__';
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
        AssociateAccess::resetCache();
        $this->md = $this->createTestUser('Managing Director');
        $this->admin = $this->createTestUser('Admin');
    }

    protected function tearDown(): void
    {
        // Back to the recommended defaults for every later test.
        $pdo = Database::connection();
        foreach (AssociateAccess::defaultMatrix() as $level => $fs) {
            foreach ($fs as $f => $on) {
                if ($f !== 'profile') {
                    $pdo->prepare('UPDATE associate_access_level_settings SET allowed = :v WHERE level = :l AND feature_key = :f')->execute(['v' => $on ? 1 : 0, 'l' => $level, 'f' => $f]);
                }
            }
        }
        AssociateAccess::resetCache();
        Flash::pull();
        Flash::pullOld();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        AssociateAccess::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null];
    }

    private function associate(string $level): array
    {
        $id = AssociateRepository::create(['name' => 'Access Assoc ' . bin2hex(random_bytes(3)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'access-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active', 'portal_enabled' => 1], $this->admin);
        Database::connection()->prepare('UPDATE associates SET portal_enabled = 1, access_level = :l WHERE id = :id')->execute(['l' => $level, 'id' => $id]);
        return AssociateRepository::find($id);
    }

    /** An order of the associate's client with a goods line of FOB 12,345.67. */
    private function order(array $a): int
    {
        $client = $this->createTestClient();
        Database::connection()->prepare('UPDATE clients SET associate_id = :a WHERE id = :c')->execute(['a' => (int) $a['id'], 'c' => $client]);
        $orderId = $this->createTestOrder($client);
        Database::connection()->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 123.4567, 12345.67)")->execute(['o' => $orderId]);
        return $orderId;
    }

    private function asAssociate(array $a): void
    {
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
    }

    private function asStaff(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
    }

    public function testEachLevelOpensOnlyItsFeaturesAndTheServerRefusesTheRest(): void
    {
        $paths = [
            ['GET', '/associate/clients', 'clients'], ['GET', '/associate/orders', 'orders'], ['GET', '/associate/catalogue', 'catalogue'],
            ['GET', '/associate/commission', 'commission'], ['GET', '/associate/commission?format=csv', 'commission_statement'], ['GET', '/associate/messages', 'messages'],
        ];
        $expected = [
            'starter' => ['clients' => true, 'orders' => true, 'catalogue' => false, 'commission' => true, 'commission_statement' => false, 'messages' => true],
            'quoting' => ['clients' => true, 'orders' => true, 'catalogue' => true, 'commission' => true, 'commission_statement' => true, 'messages' => true],
            'full' => ['clients' => true, 'orders' => true, 'catalogue' => true, 'commission' => true, 'commission_statement' => true, 'messages' => true],
        ];
        foreach ($expected as $level => $allowed) {
            $a = $this->associate($level);
            $this->asAssociate($a);
            foreach ($paths as [$method, $path, $feature]) {
                $r = $this->call($method, $path);
                if ($allowed[$feature]) {
                    self::assertNotSame(403, $r['code'], "{$level}: {$path} opens");
                    self::assertStringNotContainsString('This page is not open for your account', $r['body'], "{$level}: {$path}");
                } else {
                    self::assertSame(403, $r['code'], "{$level}: {$path} is refused");
                    self::assertStringContainsString('This page is not open for your account', $r['body'], "{$level}: {$path} shows the lock page");
                }
            }
            $home = $this->call('GET', '/associate');
            self::assertSame(!empty($expected[$level]['catalogue']), str_contains($home['body'], 'href="/associate/catalogue"'), "{$level}: the Catalogue menu item");
        }
        // Get price (POST) is refused for a Starter even with a made-up product.
        $s = $this->associate('starter');
        $this->asAssociate($s);
        self::assertSame(403, $this->call('POST', '/associate/catalogue/1/price', ['spec' => []])['code'], 'Starter cannot ask for a price');
    }

    public function testStarterSeesNoFobValueOrRateUntilTheMatrixShowsIt(): void
    {
        $a = $this->associate('starter');
        $order = $this->order($a);
        $this->asAssociate($a);
        $list = $this->call('GET', '/associate/orders')['body'];
        self::assertStringNotContainsString('12,345.67', $list, 'no FOB value at Starter');
        self::assertStringContainsString('The order value (FOB) is not shown', $list);
        self::assertStringNotContainsString('12,345.67', $this->call('GET', "/associate/orders/{$order}")['body']);
        $filtered = AssociateAccess::filterOrder($a, ['fob_value' => 12345.67, 'commission' => ['level' => 'Level 2 — 5%', 'rate' => 5.0, 'detail' => null]]);
        self::assertNull($filtered['fob_value']);
        self::assertNull($filtered['commission']['level'], 'no commission % at Starter');

        // The MD switches "Show FOB order value" on for Starter: the FOB value now shows.
        $this->asStaff($this->md);
        $ticks = [];
        foreach (AssociateAccess::matrix() as $level => $fs) {
            $ticks[$level] = array_keys(array_filter($fs));
        }
        $ticks['starter'][] = 'fob_value';
        $r = $this->call('POST', '/associates/access-levels', ['tick' => $ticks, 'reason' => 'Starter associates may now see the FOB value', 'action' => 'save']);
        self::assertSame('Level settings saved. They apply to every associate on that level at once.', $r['flash']);
        $this->asAssociate($a);
        self::assertStringContainsString('12,345.67', $this->call('GET', '/associate/orders')['body'], 'FOB shown once the matrix enables it');
        self::assertSame('Starter: Show FOB order value on', AssociateAccess::history(null, 1)[0]['new_value']);

        // A Quoting associate sees the FOB value by default.
        $q = $this->associate('quoting');
        $this->order($q);
        $this->asAssociate($q);
        self::assertStringContainsString('12,345.67', $this->call('GET', '/associate/orders')['body']);
    }

    public function testACustomAssociateHasTheirOwnTicks(): void
    {
        $a = $this->associate('starter');
        $md = \App\Repositories\UserRepository::findById($this->md);
        self::assertNull(AssociateAccess::setLevel($a, 'custom', ['clients', 'catalogue', 'get_price'], 'Trial: may quote but not see orders', $md));
        $a = AssociateRepository::find((int) $a['id']);
        self::assertSame('custom', $a['access_level']);
        $this->asAssociate($a);
        self::assertNotSame(403, $this->call('GET', '/associate/catalogue')['code'], 'Custom: catalogue ticked');
        self::assertSame(403, $this->call('GET', '/associate/orders')['code'], 'Custom: orders not ticked');
        self::assertSame(403, $this->call('GET', '/associate/messages')['code'], 'Custom: messages not ticked');
        $h = AssociateAccess::history((int) $a['id'], 1)[0];
        self::assertSame('Starter', $h['old_value']);
        self::assertSame('Custom: My clients, Catalogue (no prices), Get price / quoting', $h['new_value']);
        self::assertSame('Trial: may quote but not see orders', $h['reason']);
    }

    public function testExistingAndNewAssociates(): void
    {
        // An associate created outside the "Add an associate" form (as every associate before D-261) is Full.
        $id = AssociateRepository::create(['name' => 'Old Assoc ' . bin2hex(random_bytes(3)), 'based_in' => 'india', 'currency_code' => 'INR', 'status' => 'active'], $this->admin);
        self::assertSame('full', AssociateRepository::find($id)['access_level']);
        // One added through Associates › Add an associate starts on Starter.
        $this->asStaff($this->admin);
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->admin}");
        $name = 'New Assoc ' . bin2hex(random_bytes(3));
        $this->call('POST', '/associates', ['name' => $name, 'based_in' => 'india', 'currency_code' => 'INR', 'email' => 'new-' . bin2hex(random_bytes(3)) . '@assoc.test', 'phone' => '+91-0000000000',
            'commission_mode' => 'ladder']);
        $new = Database::connection()->prepare('SELECT access_level FROM associates WHERE name = :n');
        $new->execute(['n' => $name]);
        self::assertSame('starter', $new->fetchColumn(), 'a new associate starts on Starter');
    }

    public function testOnlyTheMdChangesAccessAndAReasonIsNeeded(): void
    {
        $a = $this->associate('starter');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->admin}");
        $this->asStaff($this->admin);
        $r = $this->call('POST', "/associates/{$a['id']}/access", ['access_level' => 'full', 'reason' => 'Promote them to the full portal']);
        self::assertSame("Only the Managing Director can change an associate's portal access.", $r['flash']);
        self::assertSame('starter', AssociateRepository::find((int) $a['id'])['access_level']);
        $r = $this->call('POST', '/associates/access-levels', ['tick' => ['starter' => ['clients']], 'reason' => 'Admin tries to change the matrix', 'action' => 'save']);
        self::assertSame('Only the Managing Director can change the level settings.', $r['flash']);

        $this->asStaff($this->md);
        $r = $this->call('POST', "/associates/{$a['id']}/access", ['access_level' => 'quoting', 'reason' => 'short']);
        self::assertSame('Give the reason for the change (at least 10 characters).', $r['flash']);
        $r = $this->call('POST', "/associates/{$a['id']}/access", ['access_level' => 'quoting', 'reason' => '3 orders completed and paid — may now quote']);
        self::assertStringStartsWith('Portal access changed.', (string) $r['flash']);
        self::assertSame('quoting', AssociateRepository::find((int) $a['id'])['access_level']);
        $page = $this->call('GET', "/associates/{$a['id']}")['body'];
        self::assertStringContainsString('id="portal-access"', $page);
        self::assertStringContainsString('3 orders completed and paid — may now quote', $page);
        self::assertStringContainsString('Associate access levels', $this->call('GET', '/associates/access-levels')['body']);
    }

    public function testNoticeAmountsAndRateAreHiddenWhereTheLevelDoesNotShowThem(): void
    {
        $s = $this->associate('starter');
        self::assertSame('Your price request PR-1 has a price: quote [amount in your portal], level [rate in your agreement].',
            AssociateAccess::maskNotice($s, 'Your price request PR-1 has a price: quote USD 1,234.50, level 5%.'));
        $f = $this->associate('full');
        self::assertSame('Commission ₹6,281.71 at 7.5%.', AssociateAccess::maskNotice($f, 'Commission ₹6,281.71 at 7.5%.'));
        AssociateService::notify($s, 'message', 'Order value USD 9,999.00 noted.', null, null, null, null, false);
        $msg = Database::connection()->prepare('SELECT message FROM associate_events WHERE associate_id = :a ORDER BY id DESC LIMIT 1');
        $msg->execute(['a' => (int) $s['id']]);
        self::assertSame('Order value [amount in your portal] noted.', $msg->fetchColumn());
    }

    public function testTheMdIsAskedAboutQuotingAfterThreeCompletedFullyPaidOrders(): void
    {
        $a = $this->associate('starter');
        $pdo = Database::connection();
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $orders[] = $o = $this->order($a);
            $pdo->prepare("UPDATE orders SET status = 'complete' WHERE id = :o")->execute(['o' => $o]);
            $pdo->prepare('INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:o, 1000, NOW())
                ON DUPLICATE KEY UPDATE advance_amount = 1000, advance_cleared_at = NOW(), balance_amount = NULL, freight_amount = NULL')->execute(['o' => $o]);
            $a = AssociateRepository::find((int) $a['id']);
            self::assertSame($i === 2, AssociateAccess::suggestUpgrade($a), 'suggestion only at the 3rd completed, fully paid order');
        }
        self::assertContains((int) $a['id'], array_column(AssociateAccess::upgradeSuggestions(), 'id'));
        $this->asStaff($this->md);
        self::assertStringContainsString('Ready for the Quoting level?', $this->call('GET', "/associates/{$a['id']}")['body']);
        $this->call('POST', "/associates/{$a['id']}/access/not-yet");
        self::assertFalse(AssociateAccess::suggestUpgrade(AssociateRepository::find((int) $a['id'])), '"Not yet" hides it until the next one');
    }
}
