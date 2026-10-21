<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\Env;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog "storage_base_path precedence": every call site used to
 * inline its own fallback for a missing STORAGE_BASE_PATH, and they had
 * drifted — Controllers computed a real directory
 * (dirname(__DIR__, 3) . '/storage'), but DocumentGenerationService,
 * SampleDataService, FileUploadService, TestModeRepository and
 * SampleDataRepository fell back to '' instead, silently resolving every
 * path built from it against the filesystem root. Env::storageBasePath()
 * is now the one place this fallback is computed, so every call site
 * agrees on the real directory whenever .env doesn't set one.
 */
final class EnvStorageBasePathTest extends TestCase
{
    private string|false $originalEnvValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvValue = $_ENV['STORAGE_BASE_PATH'] ?? false;
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvValue === false) {
            unset($_ENV['STORAGE_BASE_PATH']);
            putenv('STORAGE_BASE_PATH');
        } else {
            $_ENV['STORAGE_BASE_PATH'] = $this->originalEnvValue;
            putenv("STORAGE_BASE_PATH={$this->originalEnvValue}");
        }
        parent::tearDown();
    }

    public function testFallsBackToTheRealStorageDirectoryWhenUnset(): void
    {
        unset($_ENV['STORAGE_BASE_PATH']);
        putenv('STORAGE_BASE_PATH');

        $result = Env::storageBasePath();

        self::assertNotSame('', $result, 'must never silently fall back to the filesystem root');
        self::assertStringEndsWith('/PHP_MySQL_Version/storage', $result);
        self::assertDirectoryExists($result, 'the computed default must be the real, existing storage directory');
    }

    public function testUsesTheRealEnvValueWhenOneIsSet(): void
    {
        $_ENV['STORAGE_BASE_PATH'] = '/custom/deployment/storage';

        self::assertSame('/custom/deployment/storage', Env::storageBasePath());
    }
}
