<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #28: sensitive-setting alert badges. Investigation
 * confirmed this was already fully implemented — every company_settings
 * row flagged is_sensitive=1 (bank_account_no, ifsc, gstin, iec_pan,
 * lut_number, zoho_client_secret, ...) renders a visible
 * <span class="badge badge-sensitive">sensitive</span> next to its field,
 * carries data-sensitive="1" through to the input/checkbox/select/textarea,
 * and confirmSettingsSave() in the page's own <script> warns the user with
 * a distinct "banking/RBI-sensitive" confirmation before a save that
 * touches one goes through. No code change was required for this item —
 * this test locks in that existing, already-correct behavior as a
 * regression guard rather than leaving it unverified.
 */
final class SettingsSensitiveBadgeTest extends DbTestCase
{
    public function testSensitiveSettingRendersTheSensitiveBadge(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $userId];
        ob_start();
        (new SettingsController())->index([]);
        $html = (string) ob_get_clean();

        self::assertMatchesRegularExpression(
            '/bank_account_no.*?<span class="badge badge-sensitive">sensitive<\/span>/s',
            $html,
            'a setting flagged is_sensitive=1 must render the sensitive badge next to it'
        );
        self::assertMatchesRegularExpression(
            '/name="settings\[bank_account_no\]"[^>]*data-sensitive="1"/',
            $html,
            'the sensitive flag must reach the actual input as data-sensitive="1" so the save-confirmation JS can act on it'
        );
    }

    public function testSaveConfirmationJsWarnsDistinctlyForSensitiveFieldChanges(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $userId];
        ob_start();
        (new SettingsController())->index([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('changedSensitive', $html);
        self::assertStringContainsString('banking/RBI-sensitive field', $html);
    }
}
