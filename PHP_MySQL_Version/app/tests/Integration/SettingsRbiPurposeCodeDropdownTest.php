<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LookupRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #31: rbi_purpose_code_advance/balance/freight were
 * plain free-text settings with no standard list, unlike the admin
 * dropdown-backed fields built for #43/#66/#51a. Special-cased by
 * setting_key in settings/index.php rather than adding a generic
 * value_type='select', since company_settings has no dropdown variant
 * today and nothing else needs one yet.
 */
final class SettingsRbiPurposeCodeDropdownTest extends DbTestCase
{
    private const KEYS = ['rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight'];
    /** @var array<string, string> */
    private array $originalValues = [];
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        foreach (self::KEYS as $key) {
            $this->originalValues[$key] = (string) CompanySettingsRepository::get($key);
        }
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        foreach ($this->originalValues as $key => $value) {
            CompanySettingsRepository::set($key, $value, $this->userId);
        }
        parent::tearDown();
    }

    public function testSettingsPageRendersRbiPurposeCodeDropdowns(): void
    {
        ob_start();
        (new SettingsController())->index([]);
        $output = ob_get_clean();

        $codes = LookupRepository::dropdownOptions('rbi_purpose_code');
        self::assertNotEmpty($codes, 'admin must have at least one rbi_purpose_code option seeded to assert against');

        foreach (self::KEYS as $key) {
            self::assertStringContainsString("name=\"settings[{$key}]\" value=", $output, "{$key} must still submit via a hidden input carrying its value");
            self::assertStringNotContainsString("type=\"text\" name=\"settings[{$key}]\"", $output, "the bug: {$key} must be a <select>, not a free-text input");
        }
        foreach ($codes as $opt) {
            self::assertStringContainsString(htmlspecialchars($opt['option_value']), $output, "every admin-managed rbi_purpose_code option must appear as a choice");
        }
    }

    public function testSelectingADifferentSeededCodePersists(): void
    {
        $codes = LookupRepository::dropdownOptions('rbi_purpose_code');
        $other = null;
        foreach ($codes as $opt) {
            if ($opt['option_value'] !== $this->originalValues['rbi_purpose_code_advance']) {
                $other = $opt['option_value'];
                break;
            }
        }
        self::assertNotNull($other, 'need at least 2 seeded rbi_purpose_code options to exercise a real change');

        $_POST = [
            'settings' => ['rbi_purpose_code_advance' => $other],
            'reason' => 'phpunit rbi purpose code dropdown regression check',
        ];
        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        self::assertSame($other, (string) CompanySettingsRepository::get('rbi_purpose_code_advance'));
    }
}
