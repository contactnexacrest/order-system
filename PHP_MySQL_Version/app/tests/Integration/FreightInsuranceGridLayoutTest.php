<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * External QA report U-17: "Indicative Freight is half width while
 * Insurance is full width in the same section (inconsistent layout)."
 * Fix direction: use the 2-column grid for both — i.e. neither field gets
 * the full-width override class.
 */
final class FreightInsuranceGridLayoutTest extends TestCase
{
    public function testNeitherFreightNorInsuranceUsesTheFullWidthOverride(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 3) . '/app/views/orders/create.php');

        self::assertMatchesRegularExpression('/<label>Freight \(indicative/', $html);
        self::assertMatchesRegularExpression('/<label>Insurance \(indicative/', $html, 'Insurance must no longer carry the full-width override class');
    }
}
