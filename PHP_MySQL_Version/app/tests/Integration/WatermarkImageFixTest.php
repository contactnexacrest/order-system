<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\WatermarkController;
use App\Helpers\Flash;
use App\Repositories\AssetRepository;
use App\Repositories\WatermarkSettingsRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Point 8 — mode 'both' used to save successfully and then silently
 * render text-only on every generated PDF forever after, with nothing
 * telling anyone why, whenever the watermark image's file went missing
 * from disk (moved/deleted/not carried over by a deploy) while the
 * watermark_settings row still pointed at it. Two things changed:
 * (1) DocumentGenerationService::assetDataUri() now logs instead of
 * failing completely silently, and (2) WatermarkController::update()
 * now refuses to save mode 'image'/'both' at all when the active
 * watermark asset's file isn't actually on disk, catching the problem
 * while it's still fixable instead of after the fact.
 */
final class WatermarkImageFixTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testUpdateRefusesBothModeWhenTheActiveWatermarkAssetFileIsMissing(): void
    {
        $missingPath = sys_get_temp_dir() . '/phpunit-watermark-does-not-exist-' . bin2hex(random_bytes(4)) . '.png';
        self::assertFileDoesNotExist($missingPath);
        AssetRepository::insert('watermark', 'Test Watermark', $missingPath, 'image/png', null, true);

        $userId = $this->createTestUser('Admin');

        // Seed defaults now default new rows to mode 'both' (see docs/seed.sql),
        // so start from a known, different mode here — otherwise the
        // "unchanged" assertion below would pass trivially even if the
        // refused update wrongly saved, since 'both' would already be the
        // pre-existing value regardless of what update() does.
        WatermarkSettingsRepository::upsertGlobal(true, [
            'mode' => 'text', 'text_content' => 'DRAFT', 'font' => 'Helvetica', 'font_size' => 60,
            'color' => '#a8701f', 'opacity' => 0.15, 'angle' => 45,
            'image_asset_id' => null, 'image_opacity' => 0.05, 'image_position' => 'center',
        ], $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['mode' => 'both', 'text_content' => 'DRAFT'];

        ob_start();
        (new WatermarkController())->update(['which' => 'draft']);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('missing from storage', $flash[0]['message']);
        $saved = WatermarkSettingsRepository::findGlobal(true);
        self::assertSame('text', $saved['mode'] ?? null, 'must not save a mode the file behind it cannot support');
    }

    public function testUpdateSavesBothModeWhenTheActiveWatermarkAssetFileExists(): void
    {
        $realPath = sys_get_temp_dir() . '/phpunit-watermark-real-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($realPath, "\x89PNG\r\n\x1a\n");
        try {
            AssetRepository::insert('watermark', 'Test Watermark', $realPath, 'image/png', null, true);

            $userId = $this->createTestUser('Admin');
            $_SESSION['_auth_user_id'] = $userId;
            $_POST = ['mode' => 'both', 'text_content' => 'DRAFT'];

            ob_start();
            (new WatermarkController())->update(['which' => 'draft']);
            ob_end_clean();

            $flash = Flash::pull();
            self::assertSame('success', $flash[0]['type']);
            $saved = WatermarkSettingsRepository::findGlobal(true);
            self::assertSame('both', $saved['mode']);
            self::assertNotNull($saved['image_asset_id']);
        } finally {
            @unlink($realPath);
        }
    }

    public function testWatermarkFromRowShowsBothLayersWhenTheImageFileExists(): void
    {
        $realPath = sys_get_temp_dir() . '/phpunit-watermark-render-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($realPath, "\x89PNG\r\n\x1a\n");
        try {
            $assetId = AssetRepository::insert('watermark', 'Render Test', $realPath, 'image/png', null, true);
            $result = $this->invokeWatermarkFromRow([
                'mode' => 'both', 'text_content' => 'DRAFT', 'color' => '#CCCCCC', 'opacity' => 0.3,
                'angle' => 45, 'font_size' => 60, 'image_asset_id' => $assetId,
                'image_opacity' => 0.15, 'image_position' => 'center',
            ]);

            self::assertTrue($result['show_text']);
            self::assertTrue($result['show_image'], 'the bug: this used to stay false even though the file existed and mode was both');
            self::assertNotNull($result['image_data_uri']);
        } finally {
            @unlink($realPath);
        }
    }

    public function testWatermarkFromRowShowsOnlyTextWhenTheImageFileIsMissing(): void
    {
        $missingPath = sys_get_temp_dir() . '/phpunit-watermark-missing-render-' . bin2hex(random_bytes(4)) . '.png';
        $assetId = AssetRepository::insert('watermark', 'Missing Render Test', $missingPath, 'image/png', null, true);

        $result = $this->invokeWatermarkFromRow([
            'mode' => 'both', 'text_content' => 'DRAFT', 'color' => '#CCCCCC', 'opacity' => 0.3,
            'angle' => 45, 'font_size' => 60, 'image_asset_id' => $assetId,
            'image_opacity' => 0.15, 'image_position' => 'center',
        ]);

        self::assertTrue($result['show_text']);
        self::assertFalse($result['show_image'], 'mode is still both, but there is genuinely no file to show — this is the one case that must stay false');
        self::assertNull($result['image_data_uri']);
    }

    /**
     * 90-item backlog #7 (the actual user report, distinct from the
     * already-fixed Point 8/#220/#283 missing-file case above): seed.sql's
     * two global watermark_settings rows were inserted with mode='both'
     * before the watermark `assets` row even existed in the file, so their
     * image_asset_id was left NULL forever — every fresh environment
     * rendered text-only despite showing 'both' as the configured mode.
     * seed.sql now backfills image_asset_id right after inserting the
     * watermark asset; this guards that backfill actually ran.
     */
    public function testSeededGlobalWatermarkSettingsHaveImageAssetIdBackfilled(): void
    {
        foreach ([true, false] as $isDraftMode) {
            $row = WatermarkSettingsRepository::findGlobal($isDraftMode);
            self::assertNotNull($row);
            self::assertSame('both', $row['mode'], 'seed.sql defaults both global rows to mode=both');
            self::assertNotNull(
                $row['image_asset_id'],
                'the bug: seed.sql inserted this row with mode=both but never backfilled image_asset_id, so the image layer silently never showed'
            );
            // Not asserting a specific asset id here: other test classes in
            // this same PHPUnit process (DB rebuilt once per process, not
            // per test) may insert further 'watermark' assets, so the id
            // this points to depends on test order — what matters is that
            // it resolves to a real, active watermark-type asset row.
            $asset = AssetRepository::find((int) $row['image_asset_id']);
            self::assertNotNull($asset, 'image_asset_id must point to a real assets row');
            self::assertSame('watermark', $asset['asset_type']);
            self::assertSame(1, (int) $asset['is_active']);
        }
    }

    /** @param array<string,mixed> $row */
    private function invokeWatermarkFromRow(array $row): array
    {
        $method = new \ReflectionMethod(DocumentGenerationService::class, 'watermarkFromRow');
        $method->setAccessible(true);
        return $method->invoke(null, $row);
    }
}
