<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ReferenceDocController;
use App\Repositories\ReferenceLibraryRepository;
use App\Tests\Support\DbTestCase;

/**
 * A Reference Library document whose original filename carries a
 * non-ASCII character (an em dash, in the real case that surfaced this —
 * "Quarry SOP — Block Selection & Reservation.pdf") used to produce a
 * mojibake filename on download: a bare Content-Disposition
 * filename="..." with a raw UTF-8 byte is outside what an HTTP header
 * value may legally contain, and clients recover from that
 * inconsistently. Fixed by adding an RFC 6266 filename* parameter with
 * the name percent-encoded, alongside an ASCII-safe filename= fallback.
 */
final class ReferenceDocDownloadEncodingTest extends DbTestCase
{
    public function testHeaderValueUsesRfc6266FilenameStarForNonAsciiNames(): void
    {
        $header = ReferenceDocController::contentDispositionHeaderValue('Quarry SOP — Block Selection & Reservation.pdf');

        self::assertStringContainsString("filename*=UTF-8''", $header);
        // The em dash (U+2014) percent-encodes to %E2%80%94 in UTF-8 —
        // never the double-encoded %C3%A2%E2%82%AC%E2%80%9D mojibake a
        // Latin-1 round-trip through the raw bytes would have produced.
        self::assertStringContainsString('%E2%80%94', $header);
        self::assertStringNotContainsString('%C3%A2', $header);

        preg_match('/filename="([^"]*)"/', $header, $m);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $m[1], 'the bare filename= fallback must stay ASCII-only');
    }

    public function testHeaderValueLeavesAPlainAsciiNameUnchanged(): void
    {
        $header = ReferenceDocController::contentDispositionHeaderValue('Factory Processing Agreement (Template).docx');

        self::assertStringContainsString('filename="Factory Processing Agreement (Template).docx"', $header);
        self::assertStringContainsString("filename*=UTF-8''Factory%20Processing%20Agreement%20%28Template%29.docx", $header);
    }

    public function testCustomDownloadStreamsTheFileForANonAsciiNamedDocument(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit-refdoc-');
        file_put_contents($tmpFile, 'fake pdf bytes for test');

        $userId = $this->createTestUser('Admin');
        $id = ReferenceLibraryRepository::create('PHPUnit Test — Em Dash Title', null, $userId);
        ReferenceLibraryRepository::updateFile($id, $tmpFile, 'PHPUnit Test — Em Dash Title.pdf', 'application/pdf', $userId);

        $controller = new ReferenceDocController();
        ob_start();
        $controller->customDownload(['id' => (string) $id]);
        $output = ob_get_clean();

        self::assertSame('fake pdf bytes for test', $output);

        unlink($tmpFile);
    }
}
