<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Repositories\CompanySettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #33: storage_base_path was a plain, fully-editable
 * Settings field, but editing it has zero effect on the app — the real
 * path only ever comes from STORAGE_BASE_PATH in .env (see
 * App\Config\Env and every FileUploadService/DocumentGenerationService
 * call site). Saving a new value here was silently inert, with no
 * indication to the admin that the edit did nothing. Fixed by making it
 * genuinely read-only — client-side (forced `readonly` + a "read-only"
 * badge in settings/index.php) and server-side (SettingsController::
 * update() refuses the whole submission if a crafted POST still
 * includes this key, same fail-closed pattern as is_protected).
 */
final class SettingsStorageBasePathReadOnlyTest extends DbTestCase
{
    private const SETTING_KEY = 'storage_base_path';
    private string $originalValue;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->originalValue = (string) CompanySettingsRepository::get(self::SETTING_KEY);
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set(self::SETTING_KEY, $this->originalValue, $this->userId);
        parent::tearDown();
    }

    public function testSettingsPageRendersStorageBasePathAsReadOnly(): void
    {
        ob_start();
        (new SettingsController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('>read-only<', $output, 'storage_base_path must be visibly flagged as read-only');
        self::assertMatchesRegularExpression(
            '/name="settings\[storage_base_path\]"[^>]*readonly/s',
            $output,
            'the bug: storage_base_path must be forced readonly regardless of is_protected'
        );
    }

    public function testUpdateRejectsAChangeToStorageBasePathEvenViaDirectPost(): void
    {
        $_POST = [
            'settings' => [self::SETTING_KEY => '/some/other/path'],
            'reason' => 'phpunit storage_base_path readonly regression check — attempted bypass',
        ];

        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        self::assertSame(
            $this->originalValue,
            (string) CompanySettingsRepository::get(self::SETTING_KEY),
            'the bug: a crafted POST must not be able to persist a new storage_base_path value'
        );
    }
}
