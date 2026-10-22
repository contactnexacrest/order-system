<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-34: "PHP upload limit mismatch: server rejects
 * files above 8 MB (post_max_size) while the app setting says 10 MB; the
 * 413 page is a bare page without navigation." SettingsController now
 * warns on the Settings page itself when php.ini's own post_max_size/
 * upload_max_filesize is smaller than the largest upload type this app
 * is configured to accept — this test environment's own php.ini
 * (post_max_size=8M, upload_max_filesize=2M, both below
 * order_comment_media's configured 50 MB) is exactly that mismatch, so
 * this exercises the real condition rather than a synthetic one.
 */
final class UploadLimitSettingsWarningTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    public function testWarnsWhenPhpInisEffectiveLimitIsBelowTheLargestConfiguredUploadType(): void
    {
        $postMax = (string) ini_get('post_max_size');
        $uploadMax = (string) ini_get('upload_max_filesize');
        self::assertNotSame('', $postMax, 'sanity check: post_max_size must actually be set for this test to mean anything');
        self::assertNotSame('', $uploadMax, 'sanity check: upload_max_filesize must actually be set for this test to mean anything');

        ob_start();
        (new SettingsController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('alert-warning', $output);
        self::assertStringContainsString('File too large', $output);
        self::assertStringContainsString('post_max_size', $output);
    }
}
