<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\DbResilience;
use PHPUnit\Framework\TestCase;

/**
 * QA Round 7, R7-N04 (parity with Node's dbResilience.test.js): a database
 * that is briefly unreachable fails the request with 503 "please try
 * again", while a real bug still shows the 500 page.
 */
final class DbResilienceTest extends TestCase
{
    public function testARefusedConnectionAnswers503(): void
    {
        try {
            new \PDO('mysql:host=127.0.0.1;port=1;dbname=x', 'x', 'x', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2]);
            self::fail('Port 1 should refuse the connection.');
        } catch (\PDOException $e) {
            self::assertTrue(DbResilience::isTransient($e));
            $r = DbResilience::errorResponse($e, false);
            self::assertSame(503, $r['status']);
            self::assertSame(['Retry-After' => '10'], $r['headers']);
            self::assertStringContainsString('The database is busy or briefly unavailable. Nothing was saved — please wait a moment and try again.', $r['body']);
        }
    }

    public function testTooManyConnectionsIsTransient(): void
    {
        $e = new \PDOException('SQLSTATE[HY000] [1040] Too many connections');
        self::assertTrue(DbResilience::isTransient($e));
        $gone = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        self::assertTrue(DbResilience::isTransient($gone));
    }

    public function testABugStillAnswers500(): void
    {
        $dup = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $dup->errorInfo = ['23000', 1062, 'Duplicate entry'];
        self::assertFalse(DbResilience::isTransient($dup));
        self::assertSame(500, DbResilience::errorResponse($dup, false)['status']);
        self::assertSame(500, DbResilience::errorResponse(new \TypeError('bug'), false)['status']);
        self::assertSame('<h1>500 — Something went wrong</h1><p>The error has been logged.</p>', DbResilience::errorResponse(new \RuntimeException('x'), false)['body']);
    }
}
