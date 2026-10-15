<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #27: the Settings screen renders ~20 <fieldset> categories
 * (company, bank, mail, zoho, security, rbi, ...) as one long unbroken
 * scroll with no way to collapse a category you don't need right now.
 * Fixed by wrapping each category's fieldset in a plain <details>/<summary>
 * section (same no-JS-required pattern already used for the sidebar's
 * .nav-group dropdowns) plus Expand all/Collapse all buttons. Inputs inside
 * a closed <details> still submit normally, so this is a pure presentation
 * change with no effect on what gets saved.
 */
final class SettingsCollapsibleSectionsTest extends DbTestCase
{
    private function renderSettingsPage(): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $userId];
        ob_start();
        (new SettingsController())->index([]);
        return (string) ob_get_clean();
    }

    public function testEachSettingsCategoryIsWrappedInACollapsibleDetailsSection(): void
    {
        $html = $this->renderSettingsPage();

        self::assertMatchesRegularExpression(
            '/<details class="settings-section" data-category="company"[^>]*>\s*<summary>Company/s',
            $html,
            'each settings category must be wrapped in a <details class="settings-section"> with a matching <summary>'
        );
        self::assertStringContainsString('<div class="settings-toolbar">', $html);
        self::assertStringContainsString('toggleAllSettingsSections(true)', $html);
        self::assertStringContainsString('toggleAllSettingsSections(false)', $html);
    }

    public function testFieldsRemainInsideTheFieldsetNestedWithinTheDetailsSection(): void
    {
        $html = $this->renderSettingsPage();

        self::assertMatchesRegularExpression(
            '/<details class="settings-section" data-category="company"[^>]*>.*?<fieldset>.*?name="settings\[/s',
            $html,
            'the regression: fields must still render inside <fieldset> nested inside the new <details> wrapper, not be lost'
        );
    }

    public function testDetailsSectionsDefaultToOpenSoNoFieldIsHiddenOnFirstLoad(): void
    {
        $html = $this->renderSettingsPage();

        self::assertMatchesRegularExpression(
            '/<details class="settings-section" data-category="[^"]+"\s+open>/',
            $html,
            'sections must default to open — collapsing is an opt-in the user chooses, not a default that hides fields on first load'
        );
    }
}
