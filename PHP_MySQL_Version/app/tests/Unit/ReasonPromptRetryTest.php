<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner report (2026-10-25): "deleting the duplicate draft document is not
 * working". The Delete Draft prompt silently threw away a reason under the
 * 10-character minimum ("duplicate" is 9), so the button just did nothing.
 * Every reason prompt now goes through app.js's promptForReason(), which
 * asks again with an explanation instead.
 */
final class ReasonPromptRetryTest extends TestCase
{
    public function testAppJsDefinesTheRetryingReasonPrompt(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/js/app.js');

        self::assertStringContainsString('window.promptForReason = function (question)', $js);
        self::assertStringContainsString('Please give a reason of at least', $js);
    }

    public function testNoViewAsksForAReasonWithARawPrompt(): void
    {
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../views'));
        foreach ($views as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression("/[^.\\w]prompt\\('Why/", (string) file_get_contents($file->getPathname()), $file->getFilename() . ' must use promptForReason()');
        }
    }
}
