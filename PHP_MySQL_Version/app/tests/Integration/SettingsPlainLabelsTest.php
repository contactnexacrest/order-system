<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Helpers\SettingLabels;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-332 (12 Nov 2026): Settings › Company shows each setting by a
 * plain-English name and each section by a plain title; the technical key is only
 * small grey text (for support); no help sentence is a developer note.
 */
final class SettingsPlainLabelsTest extends DbTestCase
{
    private function renderSettingsPage(): string
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        ob_start();
        (new SettingsController())->index([]);
        return (string) ob_get_clean();
    }

    public function testSettingsAndSectionsShowPlainNamesWithTheKeyOnlyAsSmallGreyText(): void
    {
        $html = $this->renderSettingsPage();
        self::assertStringContainsString('<span class="setting-name" title="Technical name: session_timeout_staff_minutes">Staff: log out after … minutes idle</span>', $html);
        self::assertStringContainsString('<small class="muted setting-key" title="Technical name of this setting (for support)">session_timeout_staff_minutes</small>', $html);
        foreach (['Security and logging in', 'RBI purpose codes', 'Screen layout', 'Zoho Books', 'Company details'] as $title) {
            self::assertMatchesRegularExpression('/<summary>' . preg_quote($title, '/') . ' <span/', $html, $title);
        }
        foreach (['<summary>Zoho_books', '<summary>Rbi', '<summary>Ui '] as $old) {
            self::assertStringNotContainsString($old, $html);
        }
        // Sections in the order of their plain titles.
        preg_match_all('/class="collapsible-section settings-section"[^>]*>\s*<summary>([^<]+) <span/', $html, $m);
        $titles = array_map('trim', $m[1]);
        $sorted = $titles;
        usort($sorted, static fn (string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));
        self::assertSame($sorted, $titles);
    }

    public function testNoHelpSentenceOnThePageIsADeveloperNote(): void
    {
        $html = $this->renderSettingsPage();
        preg_match_all('/<small class="muted setting-help">([^<]*)<\/small>/', $html, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $help) {
            $text = html_entity_decode($help, ENT_QUOTES);
            self::assertDoesNotMatchRegularExpression('/SessionAuth|architecture doc|backlog|Addition beyond|\.php\b|Section [A-Z]{1,2}\b|\bD-\d{3}\b|WorkingDaysCalculator|QA report/', $text, $text);
        }
    }

    public function testEverySeededSettingHasAPlainLabelAndTheSeedCarriesThePlainSentences(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/seed.sql');
        foreach (\App\Config\Database::connection()->query('SELECT setting_key FROM company_settings')->fetchAll(\PDO::FETCH_COLUMN) as $key) {
            self::assertArrayHasKey($key, SettingLabels::LABELS, "{$key} needs a plain label");
        }
        foreach (SettingLabels::LABELS as $key => [$label, $help]) {
            self::assertNotSame('', $label);
            self::assertStringNotContainsString('_', $label, "{$key}: a label is words, not a key");
            if ($help !== null) {
                self::assertStringContainsString(str_replace("'", "''", $help), $seed, "{$key}: seed.sql carries the same plain sentence");
            }
        }
        foreach (['Enforced on every request (SessionAuth)', 'flagging per architecture doc practice', 'D-283: staff are signed out'] as $old) {
            self::assertStringNotContainsString($old, $seed);
        }
        self::assertSame('Rbi purpose code advance', SettingLabels::humanise('rbi_purpose_code_advance'), 'an unknown key falls back to readable words');
        self::assertSame('Something new', SettingLabels::label('something_new'));
    }
}
