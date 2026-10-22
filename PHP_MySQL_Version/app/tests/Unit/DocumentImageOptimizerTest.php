<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\DocumentImageOptimizer;
use PHPUnit\Framework\TestCase;

/**
 * External QA report L-45: generated PDFs were 2.2-2.7MB — too large for
 * many recipients' inboxes. Root cause: every raster image a document
 * embeds (company logo/signature/seal/watermark, and Annexure A product
 * photos) was read straight off disk and base64-embedded as-is, with no
 * resizing/recompression, even though none of these ever needs to render
 * larger than a few centimetres on an A4 page.
 */
final class DocumentImageOptimizerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/doc-image-optimizer-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function decodedDimensions(string $dataUri): array
    {
        $info = getimagesizefromstring(base64_decode(explode(',', $dataUri, 2)[1]));
        return [$info[0], $info[1]];
    }

    private function makeJpeg(string $path, int $width, int $height): void
    {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 140, 200));
        imagejpeg($im, $path, 90);
        imagedestroy($im);
    }

    private function makeTransparentPng(string $path, int $width, int $height): void
    {
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        imagefilledellipse($im, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.6), (int) ($height * 0.6), imagecolorallocate($im, 20, 60, 120));
        imagepng($im, $path, 0);
        imagedestroy($im);
    }

    public function testOversizedJpegIsShrunkToTheMaxDimension(): void
    {
        $path = $this->tmpDir . '/photo.jpg';
        $this->makeJpeg($path, 2400, 1600);
        $rawSize = filesize($path);

        $dataUri = DocumentImageOptimizer::dataUriForEmbed($path, 'image/jpeg');
        $embeddedSize = strlen($dataUri);

        [$w, $h] = $this->decodedDimensions($dataUri);
        self::assertLessThanOrEqual(1000, max($w, $h), 'the longer side must be capped to the max embed dimension');
        self::assertLessThan($rawSize, $embeddedSize, 'the embedded copy must be smaller than the raw upload for an oversized source');
    }

    public function testSmallImageIsNotUpscaled(): void
    {
        $path = $this->tmpDir . '/small.jpg';
        $this->makeJpeg($path, 200, 100);

        $dataUri = DocumentImageOptimizer::dataUriForEmbed($path, 'image/jpeg');

        [$w, $h] = $this->decodedDimensions($dataUri);
        self::assertSame(200, $w);
        self::assertSame(100, $h);
    }

    public function testTransparentPngKeepsItsAlphaChannelAfterShrinking(): void
    {
        $path = $this->tmpDir . '/logo.png';
        $this->makeTransparentPng($path, 2000, 1500);

        $dataUri = DocumentImageOptimizer::dataUriForEmbed($path, 'image/png');

        self::assertStringStartsWith('data:image/png;base64,', $dataUri, 'a transparent source must stay a PNG, never get flattened onto a black/white JPEG background');
        [$w, $h] = $this->decodedDimensions($dataUri);
        self::assertLessThanOrEqual(1000, max($w, $h));
    }

    public function testSvgPassesThroughUnchanged(): void
    {
        $path = $this->tmpDir . '/mark.svg';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><circle cx="50" cy="50" r="40"/></svg>';
        file_put_contents($path, $svg);

        $dataUri = DocumentImageOptimizer::dataUriForEmbed($path, 'image/svg+xml');

        self::assertSame('data:image/svg+xml;base64,' . base64_encode($svg), $dataUri);
    }

    public function testRepeatedCallsAgainstAnUnchangedFileReturnTheSameCachedResult(): void
    {
        $path = $this->tmpDir . '/photo.jpg';
        $this->makeJpeg($path, 1800, 1200);

        $first = DocumentImageOptimizer::dataUriForEmbed($path, 'image/jpeg');
        $second = DocumentImageOptimizer::dataUriForEmbed($path, 'image/jpeg');

        self::assertSame($first, $second);
    }

    public function testACorruptFileFallsBackToEmbeddingTheRawBytesRatherThanFailing(): void
    {
        $path = $this->tmpDir . '/corrupt.jpg';
        file_put_contents($path, 'not actually a jpeg');

        $dataUri = DocumentImageOptimizer::dataUriForEmbed($path, 'image/jpeg');

        self::assertSame('data:image/jpeg;base64,' . base64_encode('not actually a jpeg'), $dataUri);
    }
}
