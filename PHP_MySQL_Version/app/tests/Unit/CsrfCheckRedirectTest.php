<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Middleware\CsrfCheck;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog #25: a CSRF mismatch (almost always a genuinely stale
 * session — the common case — rather than an attack) used to dump a bare,
 * unstyled "419 — Session expired" page with no navigation at all, a dead
 * end whose own advice ("refresh the page") just re-submits into the same
 * wall. CsrfCheck::verify() now redirects to the right login screen
 * instead, matching every other session-expiry path (SessionAuth's idle
 * timeout) — this pins the http_response_code()/output-echo change,
 * since header('Location: ...') itself isn't observable in CLI PHPUnit
 * (headers_list() is always empty there), the same constraint the existing
 * SessionAuth/ClientAuth middleware tests already work around by only
 * asserting the return value and other observable side effects.
 */
final class CsrfCheckRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '0';
        http_response_code(200);
    }

    public function testStaffRouteCsrfMismatchNoLongerEchoesTheRaw419Page(): void
    {
        $_SERVER['REQUEST_URI'] = '/settings/update';
        $_SESSION['_csrf_token'] = 'the-real-token';
        $_POST['_csrf'] = 'a-stale-mismatched-token';

        $middleware = CsrfCheck::verify();
        ob_start();
        $result = $middleware([]);
        $output = ob_get_clean();

        self::assertFalse($result, 'CSRF mismatch must still refuse the request');
        self::assertSame('', $output, 'the bug: this used to echo a raw unstyled "419 — Session expired" HTML blob with no navigation');
        self::assertNotSame(419, http_response_code(), 'must no longer set the dead-end 419 status — a redirect is issued instead');
    }

    public function testClientPortalRouteCsrfMismatchAlsoNoLongerEchoesTheRaw419Page(): void
    {
        $_SERVER['REQUEST_URI'] = '/client/orders/5/comments';
        $_SESSION['_csrf_token'] = 'the-real-token';
        $_POST['_csrf'] = 'a-stale-mismatched-token';

        $middleware = CsrfCheck::verify();
        ob_start();
        $result = $middleware([]);
        $output = ob_get_clean();

        self::assertFalse($result);
        self::assertSame('', $output);
        self::assertNotSame(419, http_response_code());
    }

    public function testAMissingCsrfTokenEntirelyAlsoNoLongerEchoesTheRaw419Page(): void
    {
        // The genuinely common real-world case: session expired entirely,
        // so there's no stored token to compare against at all, not just a
        // mismatched one.
        $_SERVER['REQUEST_URI'] = '/orders/5/shipping';
        unset($_SESSION['_csrf_token']);
        $_POST['_csrf'] = 'whatever-was-in-the-stale-form';

        $middleware = CsrfCheck::verify();
        ob_start();
        $result = $middleware([]);
        $output = ob_get_clean();

        self::assertFalse($result);
        self::assertSame('', $output);
        self::assertNotSame(419, http_response_code());
    }

    public function testAMatchingTokenStillPassesThroughNormally(): void
    {
        $_SERVER['REQUEST_URI'] = '/settings/update';
        $_SESSION['_csrf_token'] = 'the-real-token';
        $_POST['_csrf'] = 'the-real-token';

        $middleware = CsrfCheck::verify();
        self::assertTrue($middleware([]));
    }
}
