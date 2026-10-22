<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\FileUploadService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-15 (UP-02): "Uploaded file MIME type is taken
 * from the browser: an HTML file named .pdf is stored and served as
 * text/html (mitigated: nosniff + attachment). Fix: Sniff MIME
 * server-side; reject when content and extension disagree."
 * FileUploadService::looksLikeDangerousContent() now checks the file's
 * own leading bytes, regardless of what extension or browser
 * Content-Type was claimed.
 */
final class DangerousContentUploadRejectedTest extends DbTestCase
{
    private function tryUpload(string $content, string $claimedName, string $claimedType): \RuntimeException
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'dangerous-upload-test-');
        file_put_contents($tmpPath, $content);
        $_FILES['bl_copy'] = [
            'name'     => $claimedName,
            'type'     => $claimedType,
            'tmp_name' => $tmpPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpPath),
        ];

        try {
            FileUploadService::handleUpload('bl_copy', 'bl_copy', 'test/dangerous-upload', null, null, null);
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            return $e;
        } finally {
            unset($_FILES['bl_copy']);
            @unlink($tmpPath);
        }
    }

    public function testAnHtmlFileNamedPdfIsRejected(): void
    {
        $e = $this->tryUpload('<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'invoice.pdf', 'application/pdf');

        self::assertStringContainsString('does not match a safe file type', $e->getMessage());
    }

    public function testBareHtmlTagNamedPdfIsRejected(): void
    {
        $e = $this->tryUpload('<html><body>hello</body></html>', 'invoice.pdf', 'application/pdf');

        self::assertStringContainsString('does not match a safe file type', $e->getMessage());
    }

    public function testAPhpFileNamedPdfIsRejected(): void
    {
        $e = $this->tryUpload('<?php echo shell_exec($_GET["cmd"]); ?>', 'invoice.pdf', 'application/pdf');

        self::assertStringContainsString('does not match a safe file type', $e->getMessage());
    }

    public function testACaseInsensitiveDoctypeIsStillCaught(): void
    {
        $e = $this->tryUpload('<!DoCtYpE HTML><html></html>', 'invoice.pdf', 'application/pdf');

        self::assertStringContainsString('does not match a safe file type', $e->getMessage());
    }

    public function testALegitimatePdfIsNotRejectedByTheContentCheck(): void
    {
        // move_uploaded_file() always fails outside a real HTTP upload,
        // so the point here is WHICH error surfaces: this content must
        // clear the dangerous-content check and reach the move step,
        // exactly like EmptyFileUploadRejectedTest's non-empty case.
        $e = $this->tryUpload('%PDF-1.4 fake pdf content for test', 'invoice.pdf', 'application/pdf');

        self::assertStringNotContainsString('does not match a safe file type', $e->getMessage());
        self::assertStringContainsString('Failed to move the uploaded file', $e->getMessage());
    }
}
