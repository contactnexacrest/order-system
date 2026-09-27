<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Middleware\CsrfCheck;
use PHPUnit\Framework\TestCase;

/**
 * QA-5 UP-03: when a POST body exceeds php.ini's post_max_size, PHP
 * silently empties $_POST and $_FILES entirely before any application code
 * runs, and CsrfCheck previously reported the generic "419 — Session
 * expired" — indistinguishable from an actually-stale session, and
 * misleading for a staff member trying to upload a large amendment/dispute
 * document. CsrfCheck::verify()'s closure never calls exit(), so it's
 * directly invokable here (unlike Router::dispatch()).
 */
final class CsrfCheckOversizedUploadTest extends TestCase
{
    private array $originalPost;
    private array $originalFiles;
    private array $originalServer;
    private array $originalSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPost = $_POST;
        $this->originalFiles = $_FILES;
        $this->originalServer = $_SERVER;
        $this->originalSession = $_SESSION ?? [];
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        $_FILES = $this->originalFiles;
        $_SERVER = $this->originalServer;
        $_SESSION = $this->originalSession;
        parent::tearDown();
    }

    public function testIniSizeToBytesParsesShorthandSuffixes(): void
    {
        self::assertSame(8 * 1024 * 1024, CsrfCheck::iniSizeToBytes('8M'));
        self::assertSame(512 * 1024, CsrfCheck::iniSizeToBytes('512K'));
        self::assertSame(2 * 1024 * 1024 * 1024, CsrfCheck::iniSizeToBytes('2G'));
        self::assertSame(12345, CsrfCheck::iniSizeToBytes('12345'));
        self::assertSame(0, CsrfCheck::iniSizeToBytes('0'));
        self::assertSame(0, CsrfCheck::iniSizeToBytes(''));
    }

    public function testDetectsAnOversizedUploadDrop(): void
    {
        // post_max_size in this environment's php.ini is 8M (see .env-adjacent
        // php.ini) — a Content-Length well beyond it, with $_POST/$_FILES
        // empty, is exactly PHP's own signature for a dropped oversized body.
        $postMaxSizeBytes = CsrfCheck::iniSizeToBytes((string) ini_get('post_max_size'));
        self::assertGreaterThan(0, $postMaxSizeBytes, 'this test assumes post_max_size is a real positive limit');

        $_SERVER['CONTENT_LENGTH'] = (string) ($postMaxSizeBytes + 1024);
        self::assertTrue(CsrfCheck::isLikelyOversizedUploadDrop());
    }

    public function testDoesNotMisfireOnAGenuinelyEmptyPost(): void
    {
        unset($_SERVER['CONTENT_LENGTH']);
        self::assertFalse(CsrfCheck::isLikelyOversizedUploadDrop());

        $_SERVER['CONTENT_LENGTH'] = '0';
        self::assertFalse(CsrfCheck::isLikelyOversizedUploadDrop());
    }

    public function testDoesNotMisfireWhenPostOrFilesArePresent(): void
    {
        $postMaxSizeBytes = CsrfCheck::iniSizeToBytes((string) ini_get('post_max_size'));
        $_SERVER['CONTENT_LENGTH'] = (string) ($postMaxSizeBytes + 1024);

        $_POST = ['_csrf' => 'something'];
        self::assertFalse(CsrfCheck::isLikelyOversizedUploadDrop());

        $_POST = [];
        $_FILES = ['file' => ['name' => 'x.pdf']];
        self::assertFalse(CsrfCheck::isLikelyOversizedUploadDrop());
    }

    public function testVerifyReturnsAFileTooLargeMessageInsteadOfSessionExpired(): void
    {
        $postMaxSizeBytes = CsrfCheck::iniSizeToBytes((string) ini_get('post_max_size'));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = (string) ($postMaxSizeBytes + 1024);

        $verify = CsrfCheck::verify();
        ob_start();
        $result = $verify([]);
        $output = ob_get_clean();

        self::assertFalse($result);
        self::assertStringContainsString('File too large', $output);
        self::assertStringNotContainsString('Session expired', $output);
    }

    public function testVerifyStillReturnsSessionExpiredForAGenuineCsrfMismatch(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = ['_csrf' => 'wrong-token'];

        $verify = CsrfCheck::verify();
        ob_start();
        $result = $verify([]);
        $output = ob_get_clean();

        self::assertFalse($result);
        self::assertStringContainsString('Session expired', $output);
    }
}
