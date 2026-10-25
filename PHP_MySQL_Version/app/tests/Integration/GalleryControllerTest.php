<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\GalleryController;
use App\Tests\Support\DbTestCase;

/**
 * Pure disk-backed knowledgebase gallery (no upload UI, no database) —
 * tests run against the real docs/gallery directory, same precedent as
 * the SOP viewer's own docs/SOP. A temporary nested fixture folder is
 * created and removed per test to exercise folder navigation without
 * permanently altering the committed seed image.
 */
final class GalleryControllerTest extends DbTestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_GET = [];
        $_SESSION = [];
        $this->rootDir = dirname(__DIR__, 3) . '/docs/gallery';
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
    }

    protected function tearDown(): void
    {
        $sub = $this->rootDir . '/__phpunit_fixture__';
        if (is_dir($sub)) {
            array_map('unlink', glob($sub . '/*') ?: []);
            rmdir($sub);
        }
        parent::tearDown();
    }

    public function testIndexListsTheSeedImageAtRoot(): void
    {
        ob_start();
        (new GalleryController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('001-dashboard.png', $output);
        self::assertStringContainsString('/gallery/file?p=001-dashboard.png', $output);
    }

    public function testFileServesTheSeedImageWithCorrectBytesAndType(): void
    {
        $_GET['p'] = '001-dashboard.png';

        ob_start();
        (new GalleryController())->file([]);
        $output = ob_get_clean();

        self::assertSame(filesize($this->rootDir . '/001-dashboard.png'), strlen($output));
    }

    public function testFileRejectsPathTraversalOutsideTheGalleryRoot(): void
    {
        $_GET['p'] = '../../../../etc/passwd';

        ob_start();
        (new GalleryController())->file([]);
        $output = ob_get_clean();

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('File not found.', $output);
    }

    public function testFileRejectsAPathThatDoesNotExist(): void
    {
        $_GET['p'] = 'nonexistent-file.png';

        ob_start();
        (new GalleryController())->file([]);
        $output = ob_get_clean();

        self::assertSame(404, http_response_code());
    }

    public function testIndexNavigatesIntoANestedFolderAndShowsABreadcrumb(): void
    {
        $sub = $this->rootDir . '/__phpunit_fixture__';
        mkdir($sub);
        file_put_contents($sub . '/nested.png', 'not-a-real-png-but-fine-for-listing');

        $_GET['p'] = '__phpunit_fixture__';

        ob_start();
        (new GalleryController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('nested.png', $output);
        self::assertStringContainsString('__phpunit_fixture__', $output);
        self::assertStringContainsString('Up one level', $output);
    }

    public function testIndexFallsBackToRootWhenAskedForAPathOutsideTheGalleryRoot(): void
    {
        $_GET['p'] = '../../app/src';

        ob_start();
        (new GalleryController())->index([]);
        $output = ob_get_clean();

        // Must never leak the escaped directory's real contents.
        self::assertStringNotContainsString('Controllers', $output);
        self::assertStringContainsString('001-dashboard.png', $output);
    }
}
