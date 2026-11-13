<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * QA round 13 Nov 2026 — UI, navigation and form fixes (UI-004 …). Parity:
 * qaUiBatchNov13.test.js (which also runs the Reorder script against a small fake DOM).
 */
final class QaUiBatchNov13Test extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';
    private const NODE_VIEWS = __DIR__ . '/../../../../NODE_MySQL_Version/app/views';

    /** UI-004: "+ Add product line" clones a row kept from page load when the buyer/staff removed every line. */
    public function testUi004AddProductLineDoesNotNeedARemainingRow(): void
    {
        foreach ([
            self::VIEWS . '/client_portal/reorder.php', self::VIEWS . '/orders/create.php',
            self::NODE_VIEWS . '/client_portal/reorder.njk', self::NODE_VIEWS . '/orders/create.njk',
        ] as $file) {
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/rows\.firstElementChild\.cloneNode/', $text, $file);
            self::assertMatchesRegularExpression('/var \w+Template = document\.getElementById\(\'product-rows\'\)\.firstElementChild\.cloneNode\(true\);/', $text, $file);
            self::assertMatchesRegularExpression('/\(rows\.firstElementChild \|\| \w+Template\)\.cloneNode/', $text, $file);
        }
    }
}
