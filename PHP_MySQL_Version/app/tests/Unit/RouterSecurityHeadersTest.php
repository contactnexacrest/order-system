<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Env;
use App\Helpers\Router;
use PHPUnit\Framework\TestCase;

/**
 * QA-5 UP-02/AUTH-14: every file-download action (dispute/amendment/BL/PO
 * evidence, chat attachments, client-portal payment screenshots) served back
 * a Content-Type trusted from whatever the browser declared at upload time,
 * and the site had no X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy, or HSTS header anywhere — leaving it open to MIME-sniffed
 * stored XSS via upload, clickjacking of authenticated pages (e.g. an
 * order's approve/close action framed under bait by a disgruntled insider),
 * token leakage through the Referer header, and protocol downgrade.
 * Router::dispatch() now sends Router::SECURITY_HEADERS plus a conditional
 * HSTS header on every request, the single chokepoint every response passes
 * through. header()'s own effect isn't observable under the CLI SAPI
 * PHPUnit runs under, so this asserts the constant/method dispatch() actually
 * sends rather than trying to intercept header() itself.
 */
final class RouterSecurityHeadersTest extends TestCase
{
    public function testSecurityHeadersIncludesContentTypeOptionsNosniff(): void
    {
        self::assertContains('X-Content-Type-Options: nosniff', Router::SECURITY_HEADERS);
    }

    public function testSecurityHeadersIncludesFrameOptionsDeny(): void
    {
        self::assertContains('X-Frame-Options: DENY', Router::SECURITY_HEADERS);
    }

    public function testSecurityHeadersIncludesReferrerPolicy(): void
    {
        self::assertContains('Referrer-Policy: strict-origin-when-cross-origin', Router::SECURITY_HEADERS);
    }

    public function testHstsIsOmittedLocallyButSentInProduction(): void
    {
        // The test suite always runs with APP_ENV=local (see .env) — confirm
        // that's actually true before relying on it, then flip APP_ENV to
        // prove the conditional logic itself, not just one branch of it.
        self::assertTrue(Env::isLocal(), 'this test assumes the suite runs with APP_ENV=local');
        self::assertNull(Router::hstsHeader());

        $original = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        try {
            self::assertSame('Strict-Transport-Security: max-age=31536000; includeSubDomains', Router::hstsHeader());
        } finally {
            if ($original === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $original;
            }
        }
    }
}
