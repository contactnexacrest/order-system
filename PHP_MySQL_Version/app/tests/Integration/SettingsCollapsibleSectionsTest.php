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
 *
 * 90-item backlog "sitewide collapsible sections beyond Settings": each
 * section now also carries the sitewide "collapsible-section" class
 * (the open/closed-per-browser persistence itself moved to layout/base.php
 * so every page gets it, not just this one) — "settings-section" stays
 * alongside it unchanged, since toggleAllSettingsSections() and this
 * test's own selectors still key off it.
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
            '/<details class="collapsible-section settings-section" data-category="company"[^>]*>\s*<summary>Company/s',
            $html,
            'each settings category must be wrapped in a <details class="collapsible-section settings-section"> with a matching <summary>'
        );
        self::assertStringContainsString('<div class="settings-toolbar">', $html);
        self::assertStringContainsString('toggleAllSettingsSections(true)', $html);
        self::assertStringContainsString('toggleAllSettingsSections(false)', $html);
    }

    public function testFieldsRemainInsideTheFieldsetNestedWithinTheDetailsSection(): void
    {
        $html = $this->renderSettingsPage();

        self::assertMatchesRegularExpression(
            '/<details class="collapsible-section settings-section" data-category="company"[^>]*>.*?<fieldset>.*?name="settings\[/s',
            $html,
            'the regression: fields must still render inside <fieldset> nested inside the new <details> wrapper, not be lost'
        );
    }

    /**
     * Owner request (2026-10-25): "by default Company Settings should be
     * compressed" — every section now starts collapsed (it used to start
     * open); each browser still remembers a section the user opened
     * (layout/base.php's per-section open/closed persistence).
     */
    public function testDetailsSectionsDefaultToCollapsed(): void
    {
        $html = $this->renderSettingsPage();

        self::assertMatchesRegularExpression('/<details class="collapsible-section settings-section" data-category="[^"]+">/', $html);
        self::assertDoesNotMatchRegularExpression('/<details class="collapsible-section settings-section" data-category="[^"]+"\s+open>/', $html);
    }
}
