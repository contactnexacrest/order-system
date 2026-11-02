<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2 Nov 2026): "I need the complete payment screenshots for
 * both advance/balance etc for supplier and logistic partner — my team needs
 * to see this". GET /help/supplier-logistics-payments is an illustrated,
 * static guide any staff member can open (an Export Executive and an
 * Accounts Executive alike); a signed-out visitor is sent to /login. It is
 * linked from Steps 7e, 10a, the Logistics Partner Bills card and Getting
 * Started, and its text is the same as Node's (helpGuide.test.js).
 */
final class HelpGuideTest extends DbTestCase
{
    private const PATH = '/help/supplier-logistics-payments';

    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__help-guide-build__';
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
        Flash::pullOld();
        $_SESSION = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    /** @return array{0:int,1:string} */
    private function get(?int $userId): array
    {
        $_SESSION = $userId === null ? [] : ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = self::PATH;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch('GET', self::PATH);
        } finally {
            $body = (string) ob_get_clean();
        }
        return [(int) http_response_code(), $body];
    }

    public function testAnyStaffMemberSeesTheGuideWithItsScreenshots(): void
    {
        foreach (['Export Executive', 'Accounts Executive'] as $role) {
            $userId = $this->createTestUser($role);
            Database::connection()->exec("UPDATE users SET force_password_change = 0 WHERE id = {$userId}");
            [$code, $body] = $this->get($userId);
            self::assertSame(200, $code, $role);
            self::assertStringContainsString('Supplier &amp; logistics partner payments — step by step', $body, $role);
            foreach (['A. Supplier advance — Step 7e', 'B. Supplier balance — Step 10a', 'C. CHA / transport — Logistics Partner Bills', 'D. Freight forwarder — ocean freight bill', 'E. Common mistakes'] as $section) {
                self::assertStringContainsString(str_replace('&', '&amp;', $section), $body, "{$role}: {$section}");
            }
            self::assertSame(11, substr_count($body, 'src="/assets/img/guide/'), "{$role}: 11 screenshots");
            self::assertStringContainsString('UTR / reference', $body);
        }
    }

    public function testASignedOutVisitorIsSentToLogin(): void
    {
        [$code, $body] = $this->get(null);
        self::assertSame(302, $code);
        self::assertSame('', trim($body));
    }

    public function testEveryScreenshotExistsAndIsSmall(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/help/supplier_logistics_payments.php');
        preg_match_all('#src="/assets/img/guide/([^"]+)"#', $view, $m);
        self::assertCount(11, $m[1]);
        foreach ($m[1] as $file) {
            $path = dirname(__DIR__, 3) . '/public_html/assets/img/guide/' . $file;
            self::assertFileExists($path);
            self::assertLessThan(250 * 1024, filesize($path), $file);
        }
    }

    public function testTheGuideTextIsTheSameAsNodes(): void
    {
        $strip = static function (string $html, string $imgBase): string {
            $html = (string) preg_replace('/^<\?php.*?\?>\s*|^\{#.*?#\}\s*/s', '', $html);
            return str_replace($imgBase, '__IMG__', $html);
        };
        $php = $strip((string) file_get_contents(dirname(__DIR__, 2) . '/views/help/supplier_logistics_payments.php'), '/assets/img/guide');
        $node = $strip((string) file_get_contents(dirname(__DIR__, 4) . '/NODE_MySQL_Version/app/views/help/supplier_logistics_payments.njk'), '/img/guide');
        self::assertSame($php, $node);
    }

    public function testTheGuideIsLinkedFromTheBillCardsAndGettingStarted(): void
    {
        $show = (string) file_get_contents(dirname(__DIR__, 2) . '/views/orders/show.php');
        self::assertStringContainsString("/help/supplier-logistics-payments#guide-' . (\$leg === 'advance' ? 'a' : 'b') . '\" target=\"_blank\"", $show, '7e and 10a');
        self::assertStringContainsString('href="/help/supplier-logistics-payments#guide-c" target="_blank"', $show, 'Logistics Partner Bills');
        $gs = (string) file_get_contents(dirname(__DIR__, 2) . '/views/getting-started/show.php');
        self::assertStringContainsString('<a href="/help/supplier-logistics-payments">Step-by-step guide with screenshots</a>', $gs);
    }
}
