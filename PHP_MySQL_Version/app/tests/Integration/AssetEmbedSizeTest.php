<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssetRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-45: generated PDFs were 2.2-2.7MB — too large for
 * many recipients' inboxes. Root cause: DocumentDataAssembler::assetsBlock()
 * embedded the company logo/signature/seal/watermark straight off disk —
 * up to AssetController::MAX_BYTES = 5MB each — with no resizing. Confirmed
 * against the app's own real seeded company seal
 * (storage/assets/seals/company_seal.png, 1024x1024, ~1.16MB on disk).
 *
 * This locks in the DocumentImageOptimizer wiring itself (not just the
 * standalone helper, covered by DocumentImageOptimizerTest) — an oversized
 * company asset must come back from assetsBlock() meaningfully smaller.
 */
final class AssetEmbedSizeTest extends DbTestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir() . '/phpunit-asset-embed-' . bin2hex(random_bytes(4)) . '.png';
        $im = imagecreatetruecolor(2000, 1500);
        imagefilledrectangle($im, 0, 0, 1999, 1499, imagecolorallocate($im, 30, 60, 120));
        imagepng($im, $this->tmpPath, 0); // uncompressed — same as a careless real-world upload
        imagedestroy($im);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpPath)) {
            unlink($this->tmpPath);
        }
        parent::tearDown();
    }

    public function testAnOversizedLogoUploadIsEmbeddedMuchSmallerThanTheRawFile(): void
    {
        $rawSize = filesize($this->tmpPath);
        self::assertGreaterThan(500_000, $rawSize, 'the fixture must actually be large for this test to mean anything');

        AssetRepository::replace('logo', 'Test Logo', $this->tmpPath, 'image/png', null);

        $block = DocumentDataAssembler::assetsBlock();
        $dataUri = $block['logo_data_uri'];

        self::assertNotNull($dataUri);
        self::assertLessThan($rawSize / 2, strlen($dataUri), 'an oversized upload must be embedded at a small fraction of its raw size, not as-is');

        $info = getimagesizefromstring(base64_decode(explode(',', $dataUri, 2)[1]));
        self::assertLessThanOrEqual(1000, max($info[0], $info[1]), 'the embedded copy must be capped to the max embed dimension');
    }
}
