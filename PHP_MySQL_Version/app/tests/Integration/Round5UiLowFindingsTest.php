<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SopController;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, low findings on the UI:
 *  - R5-05: SOP links to ./README.md opened /sop/README (404) — they now
 *    open /sop, the chapter list.
 *  - R5-06: every form field gets an accessible name — one sitewide
 *    fallback in app.js for fields with no label.
 *  - R5-11: phones — tap targets at least 24px, no text below 11px,
 *    16px form fields (no iOS zoom on focus).
 */
final class Round5UiLowFindingsTest extends DbTestCase
{
    public function testSopReadmeLinksOpenTheChapterList(): void
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        ob_start();
        (new SopController())->show(['chapter' => '00-getting-started']);
        $html = (string) ob_get_clean();
        $_SESSION = [];

        self::assertStringNotContainsString('href="/sop/README', $html);
        self::assertStringContainsString('href="/sop"', $html);
    }

    public function testAppJsNamesEveryUnlabelledField(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/js/app.js');
        self::assertStringContainsString('function initFieldLabels()', $js);
        self::assertMatchesRegularExpression('/function init\(\) \{.*initFieldLabels\(\);/s', $js);
    }

    public function testPhoneCssRaisesTapTargetsTextAndInputSizes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');
        self::assertStringContainsString('@media (max-width:640px){
  .tag,.tag-hint,.step-group-num,.gs-pill{font-size:11px;}', $css);
        self::assertStringContainsString('input[type=checkbox],input[type=radio]{min-width:24px;min-height:24px;}', $css);
        self::assertStringContainsString('input:not([type=checkbox]):not([type=radio]),select,textarea{font-size:16px !important;}', $css);
    }
}
