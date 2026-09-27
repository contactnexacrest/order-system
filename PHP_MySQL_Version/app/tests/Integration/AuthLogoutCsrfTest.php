<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * QA-5 AUTH-15: /logout and /client/logout were bare GET routes with no
 * CSRF check (CsrfCheck::verify() only inspects $_SERVER['REQUEST_METHOD']
 * === 'POST', so a GET route is never covered by it regardless of
 * middleware). A plain <img src="https://…/logout"> embedded on any page a
 * logged-in victim's browser loaded would silently end their session —
 * logout CSRF — usable by a disgruntled coworker or client to repeatedly
 * force someone out mid-approval as harassment, or to mask timing for a
 * follow-on attack. Both routes are now POST + CsrfCheck::verify(), like
 * every other state-changing action; the sidewide CSRF-coverage test
 * (IdorAndInputValidationTest) already re-checks the CsrfCheck::verify()
 * requirement generically once a route is POST — this test pins the
 * GET-route removal specifically, since that's the actual defect.
 */
final class AuthLogoutCsrfTest extends TestCase
{
    private function routesFileContents(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public_html/index.php');
    }

    public function testLogoutIsNoLongerRegisteredAsAGetRoute(): void
    {
        $contents = $this->routesFileContents();
        self::assertDoesNotMatchRegularExpression(
            "/\\\$router->get\\('\\/logout'/",
            $contents,
            '/logout must not be reachable via GET (logout CSRF)'
        );
    }

    public function testClientLogoutIsNoLongerRegisteredAsAGetRoute(): void
    {
        $contents = $this->routesFileContents();
        self::assertDoesNotMatchRegularExpression(
            "/\\\$router->get\\('\\/client\\/logout'/",
            $contents,
            '/client/logout must not be reachable via GET (logout CSRF)'
        );
    }

    public function testLogoutIsRegisteredAsAPostRouteWithCsrfVerification(): void
    {
        $contents = $this->routesFileContents();
        self::assertMatchesRegularExpression(
            "/\\\$router->post\\('\\/logout',[^;]*?CsrfCheck::verify\\(\\)/s",
            $contents
        );
    }

    public function testClientLogoutIsRegisteredAsAPostRouteWithCsrfVerification(): void
    {
        $contents = $this->routesFileContents();
        self::assertMatchesRegularExpression(
            "/\\\$router->post\\('\\/client\\/logout',[^;]*?CsrfCheck::verify\\(\\)/s",
            $contents
        );
    }
}
