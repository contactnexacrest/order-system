<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * External QA report U-07: "New Order product line is cramped: 7 inputs
 * in one row, placeholders cut off." (Unit and HS Code were already
 * admin-managed dropdowns/validated against the master list from an
 * earlier fix — M-05's generic dropdown_options admin screen already
 * covers "Product units add/edit/delete" via its 'unit' list_key, and
 * OrderController::store() already rejects any HS code not on the master
 * list — so the only remaining gap here is the layout itself.) A 4-column
 * grid puts the row's 8 items (7 fields + the action buttons) into two
 * readable rows instead of one single cramped row of 7-8 narrow columns.
 */
final class ProductRowLayoutTest extends \PHPUnit\Framework\TestCase
{
    public function testProductRowIsATwoRowFourColumnGridNotASingleCrampedRow(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');

        self::assertMatchesRegularExpression(
            '/\.product-row\{display:grid;grid-template-columns:repeat\(4,\s*minmax\(150px,\s*1fr\)\)/',
            $css,
            'the product-row grid must use 4 equal columns (two rows for 7-8 items) instead of the old single-row 7-8-column template'
        );
        self::assertStringNotContainsString(
            '.product-row{display:grid;grid-template-columns:2fr 1fr 1fr 0.8fr 0.8fr 0.9fr 0.8fr auto',
            $css,
            'the old cramped single-row template must be gone'
        );
    }
}
