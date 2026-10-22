<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * External QA report U-08/U-10: small screen-text defects, grouped into
 * one file since each is a one-line static-text check with nothing else
 * to set up.
 */
final class UiTextCleanupTest extends \PHPUnit\Framework\TestCase
{
    /**
     * U-08: the "(only shown when Incoterm is not FOB)" hint was never
     * actually true — the fieldset always renders regardless of
     * Incoterm, with no show/hide JS anywhere on the page. Reworded to
     * describe what actually happens (the Quotation itself prints NIL
     * for FOB — see templates/QT/quotation.html.twig's own is_fob branch)
     * rather than building new conditional-visibility JS.
     */
    public function testOrderCreateNoLongerClaimsTheFreightSectionHidesForFob(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../views/orders/create.php');

        self::assertStringNotContainsString('only shown when Incoterm is not FOB', $html);
        self::assertStringContainsString('printed on the Quotation only for CFR/CIF', $html);
    }

    /** U-10: developer jargon ("WorkingDaysCalculator") shown to end users on /holidays. */
    public function testHolidaysPageHasNoDeveloperJargon(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../views/holidays/index.php');

        self::assertStringNotContainsString('WorkingDaysCalculator', $html);
    }
}
