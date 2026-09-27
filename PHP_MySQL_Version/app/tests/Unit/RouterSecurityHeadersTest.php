<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\Router;
use PHPUnit\Framework\TestCase;

/**
 * QA-5 UP-02: every file-download action (dispute/amendment/BL/PO evidence,
 * chat attachments, client-portal payment screenshots) served back a
 * Content-Type trusted from whatever the browser declared at upload time,
 * with no X-Content-Type-Options header anywhere — letting a browser sniff
 * an uploaded "invoice.pdf" that's actually HTML/JS and render/execute it
 * instead of downloading it. Router::dispatch() now sends
 * Router::SECURITY_HEADERS on every request, the single chokepoint every
 * response passes through. header()'s own effect isn't observable under the
 * CLI SAPI PHPUnit runs under, so this asserts the constant dispatch()
 * actually sends rather than trying to intercept header() itself.
 */
final class RouterSecurityHeadersTest extends TestCase
{
    public function testSecurityHeadersIncludesContentTypeOptionsNosniff(): void
    {
        self::assertContains('X-Content-Type-Options: nosniff', Router::SECURITY_HEADERS);
    }
}
