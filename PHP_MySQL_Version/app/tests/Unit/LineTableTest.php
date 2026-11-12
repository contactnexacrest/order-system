<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\MoneyFormat;
use App\Services\LineTable;
use App\Services\UnitPrices;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-339 (12 Nov 2026, owner "confirm 1" and "confirm 2 with option a"):
 * the detailed product table — measures and rounding, which table a document gets
 * (one unit / mixed units / no size / as per annexure / PCS / lines not sold by size),
 * the totals by unit, the line total never worked out again, and the No. of pieces.
 * Node twin: tests/unit/lineTable.test.js (same cases, same wording).
 */
final class LineTableTest extends TestCase
{
    /** One order_products row (and how the documents print it today). */
    private static function line(int $id, string $desc, string $unit, ?float $l, ?float $w, ?float $h, ?int $pcs, ?string $qty, ?string $price, array $extra = []): array
    {
        $amount = $qty !== null && $price !== null ? number_format(round((float) $qty * (float) $price, 2), 2, '.', '') : null;
        return $extra + [
            'id' => $id, 'description' => $desc, 'finish' => $extra['finish'] ?? 'P6', 'unit' => $unit, 'line_type' => $extra['line_type'] ?? 'goods',
            'piece_length_cm' => $l, 'piece_width_cm' => $w, 'piece_thickness_cm' => $h, 'pieces_count' => $pcs,
            'quantity' => $qty, 'quantity_is_tbc' => 0, 'unit_price' => $price, 'fob_value' => $amount, 'hs_code' => $extra['hs_code'] ?? '68022310',
            'as_per_annexure' => $extra['as_per_annexure'] ?? 0, 'size_note' => $extra['size_note'] ?? null, 'dimensions' => $extra['dimensions'] ?? null,
        ];
    }

    /** D-343: money as DocumentDataAssembler prints it — Indian grouping on an INR order. */
    private static function formatted(array $lines, string $currency = 'USD'): array
    {
        return array_map(static fn (array $l): array => [
            'description' => $l['description'], 'quantity' => (string) $l['quantity'], 'unit' => $l['unit'],
            'unit_price' => $l['unit_price'] !== null ? MoneyFormat::amount($l['unit_price'], $currency) : 'To Be Confirmed',
            'amount' => $l['fob_value'] !== null ? MoneyFormat::amount($l['fob_value'], $currency) : 'To Be Confirmed',
            'line_type' => $l['line_type'], 'attributes' => [], 'sac_code' => null, 'dimensions' => $l['dimensions'], 'finish' => $l['finish'], 'hs_code' => $l['hs_code'],
        ], $lines);
    }

    private static function build(array $lines, string $currency = 'USD', ?string $letter = null, bool $prices = true): array
    {
        return LineTable::build($lines, self::formatted($lines, $currency), $currency, $letter, $prices);
    }

    public function testMeasuresAndRounding(): void
    {
        self::assertSame(0.1872, LineTable::perPiece('CBM', 180, 80, 13));
        self::assertSame(0.36, LineTable::perPiece('SQM', 60, 60, 2));
        self::assertSame(0.98, LineTable::perPiece('SQM', 140, 70, null));
        self::assertSame(15.5, LineTable::perPiece('SQFT', 180, 80, 2));
        self::assertSame(10.55, LineTable::perPiece('SQFT', 140, 70, 2));
        self::assertNull(LineTable::perPiece('CBM', 180, 80, null), 'CBM needs the thickness');
        self::assertNull(LineTable::perPiece('PCS', 180, 80, 13), 'a piece has no measure');
        self::assertNull(LineTable::perPiece('TON', 180, 80, 13));
        self::assertSame('4.680', LineTable::quantityFromPieces('CBM', 180, 80, 13, 25));
        self::assertSame('3.185', LineTable::quantityFromPieces('CBM', 140, 70, 13, 25));
        self::assertSame('0.936', LineTable::quantityFromPieces('CBM', 120, 60, 13, 10));
        self::assertSame('180.000', LineTable::quantityFromPieces('SQM', 60, 60, 2, 500));
        self::assertSame('263.750', LineTable::quantityFromPieces('SQFT', 140, 70, 2, 25));
        self::assertSame('20.000', LineTable::quantityFromPieces('PCS', null, null, null, 20));
        self::assertSame(365.04, LineTable::pricePerPiece(1950.0, 0.1872));
        self::assertSame(32760.0, LineTable::pricePerPiece(175000.0, 0.1872));
        self::assertSame(
            'This line: 0.1872 CBM per piece · 25 pcs = 4.680 CBM · USD 365.04 per piece · total USD 9,126.00',
            LineTable::summary('CBM', 180, 80, 13, 25, 1950.0, 'USD')
        );
    }

    public function testOwnersExampleOneUnitFullTable(): void
    {
        $lines = [
            self::line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '175000.00'),
            self::line(2, 'CHM Black', 'CBM', 140, 70, 13, 25, '3.185', '175000.00'),
            self::line(3, 'CHM Black', 'CBM', 120, 60, 13, 10, '0.936', '175000.00'),
        ];
        $t = self::build($lines, 'INR');
        self::assertSame('full', $t['variant']);
        self::assertSame('CBM', $t['unit']);
        self::assertSame(['Material', 'Surface', 'Dimensions (cm)', 'CBM', 'Qty', 'CBM', 'Price (INR)', 'Price (INR)', 'Total Price'], array_column($t['heads']['top'], 'text'));
        self::assertSame(['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs', 'per CBM', 'per piece', '(INR)'], $t['heads']['bottom']);
        $r = $t['rows'][0];
        // D-343: an INR order's money in Indian grouping.
        self::assertSame(['180', '80', '13', '0.1872', '25', '4.680', '1,75,000.00', '32,760.00', '8,19,000.00'],
            [$r['l'], $r['w'], $r['h'], $r['per_piece'], $r['pcs'], $r['all_pcs'], $r['price_unit'], $r['price_piece'], $r['total']]);
        self::assertSame(['60', '8.801', '15,40,175.00'], [$t['total']['pcs'], $t['total']['measure'], $t['total']['amount']]);
        self::assertNull($t['totals_by_unit']);
        self::assertSame([['label' => 'HS Code:', 'text' => '68022310'], ['label' => 'Country of Origin:', 'text' => 'India']], $r['detail']);
    }

    public function testLineTotalIsNeverWorkedOutAgain(): void
    {
        // A total that is not price per piece × pieces (rounded figures) prints as stored.
        $l = self::line(1, 'CHM Black', 'SQFT', 140, 70, 2, 25, '263.75', '18.00');
        $l['fob_value'] = '4747.50';
        $t = self::build([$l]);
        self::assertSame('4,747.50', $t['rows'][0]['total']);
        self::assertSame('4,747.50', $t['total']['amount']);
        self::assertSame('189.90', $t['rows'][0]['price_piece']);
    }

    public function testMixedUnitsNameTheirUnitAndTotalByUnit(): void
    {
        $t = self::build([
            self::line(1, 'CHM Black slabs', 'CBM', 180, 80, 13, 25, '4.680', '1950.00'),
            self::line(2, 'CHM Black tiles', 'SQM', 60, 60, 2, 500, '180.00', '32.00', ['finish' => 'Polished']),
            self::line(3, 'Carved planter', 'PCS', 60, 40, 40, 20, '20', '85.00', ['finish' => 'Honed', 'hs_code' => '68029100']),
        ]);
        self::assertTrue($t['mixed']);
        self::assertSame(['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs', 'per unit', 'per piece', '(USD)'], $t['heads']['bottom']);
        self::assertSame('Measure', $t['heads']['top'][3]['text']);
        self::assertSame(['0.1872 CBM', '4.680 CBM', '1,950.00 /CBM', '365.04'], [$t['rows'][0]['per_piece'], $t['rows'][0]['all_pcs'], $t['rows'][0]['price_unit'], $t['rows'][0]['price_piece']]);
        self::assertSame(['0.36 SQM', '180.00 SQM', '32.00 /SQM', '11.52'], [$t['rows'][1]['per_piece'], $t['rows'][1]['all_pcs'], $t['rows'][1]['price_unit'], $t['rows'][1]['price_piece']]);
        // PCS: no measure; the price per unit is the price per piece.
        self::assertSame(['—', '20', '—', '85.00 /PCS', '85.00'], [$t['rows'][2]['per_piece'], $t['rows'][2]['pcs'], $t['rows'][2]['all_pcs'], $t['rows'][2]['price_unit'], $t['rows'][2]['price_piece']]);
        self::assertSame(['545', '', '16,586.00'], [$t['total']['pcs'], $t['total']['measure'], $t['total']['amount']]);
        self::assertSame('Totals by unit: 4.680 CBM · 180.00 SQM · 20 PCS', $t['totals_by_unit']);
    }

    public function testNoSizeGivesTheShortTableWithTheSizeNote(): void
    {
        $t = self::build([
            self::line(1, 'CHM Black — gangsaw blocks, random sizes', 'CBM', null, null, null, null, '25', '1950.00', ['finish' => 'Rough', 'size_note' => 'random, as available', 'hs_code' => '25161100']),
            self::line(2, 'CHM Black slabs', 'CBM', null, null, 13, null, '8.801', '1950.00'),
        ]);
        self::assertSame('short', $t['variant']);
        self::assertSame(['Material', 'Surface', 'Thickness', 'Qty', 'Price (USD)', 'Total Price'], array_column($t['heads']['top'], 'text'));
        self::assertSame(['Colour', 'Finish', '(cm)', 'CBM', 'per CBM', '(USD)'], $t['heads']['bottom']);
        self::assertSame(['—', '25.000', '1,950.00', '48,750.00'], [$t['rows'][0]['thickness'], $t['rows'][0]['qty'], $t['rows'][0]['price_unit'], $t['rows'][0]['total']]);
        self::assertSame('13', $t['rows'][1]['thickness']);
        self::assertSame(['label' => 'Sizes:', 'text' => 'random, as available'], $t['rows'][0]['detail'][0]);
        self::assertSame(['33.801', '65,911.95'], [$t['total']['measure'], $t['total']['amount']]);
    }

    public function testALineWithoutSizeInAFullTableShowsDashes(): void
    {
        $t = self::build([
            self::line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '1950.00'),
            self::line(2, 'CHM Black — random sizes', 'CBM', null, null, null, null, '25.000', '1950.00', ['finish' => 'Rough']),
        ]);
        self::assertSame('full', $t['variant']);
        $r = $t['rows'][1];
        self::assertSame(['—', '—', '—', '—', '—', '25.000', '1,950.00', '—', '48,750.00'],
            [$r['l'], $r['w'], $r['h'], $r['per_piece'], $r['pcs'], $r['all_pcs'], $r['price_unit'], $r['price_piece'], $r['total']]);
        self::assertSame(['25', '29.680'], [$t['total']['pcs'], $t['total']['measure']]);
    }

    public function testAsPerAnnexureLine(): void
    {
        $lines = [
            self::line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '175000.00'),
            self::line(4, 'CHM Black', 'CBM', null, null, null, null, '2.400', '175000.00', ['finish' => 'Leather', 'as_per_annexure' => 1]),
        ];
        $t = self::build($lines, 'INR', 'A');
        $r = $t['rows'][1];
        self::assertSame('As per Annexure A', $r['annexure']);
        self::assertSame(['—', '—', '2.400', '1,75,000.00', '—', '4,20,000.00'], [$r['per_piece'], $r['pcs'], $r['all_pcs'], $r['price_unit'], $r['price_piece'], $r['total']]);
        self::assertSame(['label' => null, 'text' => 'Qty as per Annexure A'], $r['detail'][0]);
        self::assertSame(['25', '7.080', '12,39,000.00'], [$t['total']['pcs'], $t['total']['measure'], $t['total']['amount']]);
        // Annexure B on the CI / PL when chosen (D-336).
        self::assertSame('As per Annexure B', self::build($lines, 'INR', 'B')['rows'][1]['annexure']);
    }

    public function testServiceAndNonMeasureLinesKeepTodaysRows(): void
    {
        $t = self::build([
            self::line(1, 'CHM Black', 'SQFT', 180, 80, 2, 25, '387.50', '18.00'),
            self::line(2, 'Inspection', 'LOT', null, null, null, null, '1', '150.00', ['line_type' => 'service']),
            self::line(3, 'Fixing kit', 'SET', null, null, null, null, '2', '40.00'),
        ]);
        self::assertSame('SQFT', $t['unit']);
        self::assertCount(1, $t['rows']);
        self::assertSame([2, 3], array_column($t['simple'], 'no'));
        self::assertSame(['Inspection', 'Fixing kit'], array_column($t['simple'], 'description'));
        // Only services and charges: no detailed table at all.
        self::assertSame('none', self::build([self::line(1, 'Freight', 'LOT', null, null, null, null, '1', '900.00', ['line_type' => 'charge'])])['variant']);
    }

    public function testPackingListHasNoPrices(): void
    {
        $t = self::build([self::line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '1950.00')], 'USD', null, false);
        self::assertSame(['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs', 'Packages'], $t['heads']['bottom']);
        self::assertSame('Crates /', end($t['heads']['top'])['text']);
    }

    public function testServerQuantityFromPieces(): void
    {
        // With the size in a measure unit the pieces are required and the quantity is worked out.
        self::assertSame([null, 'enter the No. of pieces — with the size given, the quantity is worked out from the pieces.'],
            LineTable::serverQuantity('CBM', 180, 80, 13, null, false, false));
        self::assertSame(['4.680', null], LineTable::serverQuantity('CBM', 180, 80, 13, 25, false, false));
        self::assertSame(['20.000', null], LineTable::serverQuantity('PCS', null, null, null, 20, false, false));
        // Without a size: the typed quantity stands; as per annexure: D-336 unchanged.
        self::assertSame([null, null], LineTable::serverQuantity('CBM', null, null, null, 25, false, false));
        self::assertSame([null, null], LineTable::serverQuantity('CBM', 180, 80, 13, null, true, false));
        self::assertSame([null, 'No. of pieces must be a whole number from 1 to 9999999 (e.g. 25).'], LineTable::readPieces('2.5'));
        self::assertSame([25, null], LineTable::readPieces(' 25 '));
    }

    public function testPricesInOtherUnits(): void
    {
        // Mockup screen 2: USD 1,950.00 per CBM at 2 cm = 39.00 per SQM = 3.62 per SQFT.
        self::assertSame(['CBM' => 1950.0, 'SQM' => 39.0, 'SQFT' => 3.62], UnitPrices::convert(1950.0, 'CBM', 20.0));
        self::assertSame(['CBM' => 1950.0, 'SQM' => 58.5, 'SQFT' => 5.43], UnitPrices::convert(1950.0, 'CBM', 30.0));
        self::assertSame(['CBM' => 1950.0, 'SQM' => 253.5, 'SQFT' => 23.55], UnitPrices::convert(1950.0, 'CBM', 130.0));
        // Typed per SQM: per SQFT without a thickness; per CBM needs one.
        self::assertSame(['CBM' => null, 'SQM' => 41.2, 'SQFT' => 3.83], UnitPrices::convert(41.2, 'SQM', null));
        self::assertSame(['CBM' => null, 'SQM' => null, 'SQFT' => null], UnitPrices::convert(null, 'SQM', 20.0));
    }
}
