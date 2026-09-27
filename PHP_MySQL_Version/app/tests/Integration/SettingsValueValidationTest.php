<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Repositories\CompanySettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (SET-02 — external QA report cross-verification): company_settings
 * defines a value_type per row (number/boolean/date/json/string), but
 * SettingsController::update() never actually checked a submitted value
 * against it — any string could be saved into a 'number' setting like
 * session_timeout_minutes, later parsing to garbage everywhere it's read.
 */
final class SettingsValueValidationTest extends DbTestCase
{
    private const SETTING_KEY = 'session_timeout_minutes'; // value_type = 'number'
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

    public function testRejectsNonNumericValueForANumberTypedSetting(): void
    {
        $this->attemptUpdate('not-a-number');
        self::assertSame($this->originalValue, (string) CompanySettingsRepository::get(self::SETTING_KEY));
    }

    public function testRejectsNegativeValueForANumberTypedSetting(): void
    {
        $this->attemptUpdate('-5');
        self::assertSame($this->originalValue, (string) CompanySettingsRepository::get(self::SETTING_KEY));
    }

    public function testRejectsBlankValueForANumberTypedSetting(): void
    {
        $this->attemptUpdate('');
        self::assertSame($this->originalValue, (string) CompanySettingsRepository::get(self::SETTING_KEY));
    }

    public function testAcceptsAValidNonNegativeNumber(): void
    {
        $this->attemptUpdate('45');
        self::assertSame('45', (string) CompanySettingsRepository::get(self::SETTING_KEY));
    }

    private function attemptUpdate(string $newValue): void
    {
        $_POST = [
            'settings' => [self::SETTING_KEY => $newValue],
            'reason' => 'phpunit settings validation regression check',
        ];

        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();
    }
}
