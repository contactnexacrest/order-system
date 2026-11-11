<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\LineUnits;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-270 (point 25, 11 Nov 2026, double-confirmed): a line price in
 * any unit is converted to the product's reference unit with the piece size.
 * The figures are the owner's example on mockup p25b (180 × 80 × 13 cm = 0.1872 CBM).
 * Node twin: tests/unit/lineUnits.test.js.
 */
final class LineUnitsTest extends TestCase
{
    public function testUnitsAreRecognisedWithTheirUsualSpellings(): void
    {
        self::assertSame(['CBM', 'M2', 'M2', 'SQFT', 'PCS', 'PCS', 'RMT', 'TON', 'TON', null, null, null],
            array_map([LineUnits::class, 'canonical'], ['cbm', 'SQM', 'm²', 'Sq Ft', 'PCS', 'NOS', 'RMT', 'TON', 'MT', 'SET', 'LOT', '']));
    }

    public function testOnePieceOf180By80By13CmIs01872Cbm(): void
    {
        $f = LineUnits::factor('PCS', 'CBM', 180.0, 80.0, 13.0, 2.7);
        self::assertEqualsWithDelta(0.1872, $f['factor'], 1e-9);
        self::assertSame([], $f['missing']);
        // USD 495 per piece = USD 2,644.23 per CBM (mockup p25b)
        self::assertSame('2644.23', number_format(495 / $f['factor'], 2, '.', ''));
        self::assertSame('2511.77', number_format(320 / LineUnits::factor('PCS', 'CBM', 140.0, 70.0, 13.0, null)['factor'], 2, '.', ''));
    }

    public function testSquareMetreAndSquareFeetNeedNoThicknessBetweenThem(): void
    {
        self::assertEqualsWithDelta(10.7639, LineUnits::factor('SQM', 'SQFT', null, null, null, null)['factor'], 1e-9);
        self::assertEqualsWithDelta(1 / 10.7639, LineUnits::factor('SQFT', 'SQM', null, null, null, null)['factor'], 1e-12);
    }

    public function testSquareMetreToCbmUsesTheThickness(): void
    {
        self::assertEqualsWithDelta(0.02, LineUnits::factor('SQM', 'CBM', null, null, 2.0, null)['factor'], 1e-12);
        self::assertSame(['piece_thickness_cm'], LineUnits::factor('SQM', 'CBM', null, null, null, null)['missing']);
    }

    public function testRunningMetreNeedsWidthAndThickness(): void
    {
        self::assertEqualsWithDelta(0.3 * 0.03, LineUnits::factor('RMT', 'CBM', null, 30.0, 3.0, null)['factor'], 1e-12);
        self::assertSame(['piece_width_cm', 'piece_thickness_cm'], LineUnits::factor('RMT', 'CBM', null, null, null, null)['missing']);
    }

    public function testPiecesNeedAllThreeSizes(): void
    {
        self::assertSame(['piece_length_cm', 'piece_width_cm', 'piece_thickness_cm'], LineUnits::factor('PCS', 'CBM', null, null, null, null)['missing']);
        self::assertSame(['piece_length_cm'], LineUnits::factor('PCS', 'CBM', null, 80.0, 13.0, null)['missing']);
    }

    public function testTonUsesTheWeightPerCbm(): void
    {
        self::assertEqualsWithDelta(1 / 2.7, LineUnits::factor('TON', 'CBM', null, null, null, 2.7)['factor'], 1e-12);
        self::assertSame(['weight'], LineUnits::factor('TON', 'CBM', null, null, null, null)['missing']);
    }

    public function testSetAndLotCannotBeConvertedAndTheSameUnitNeedsNothing(): void
    {
        self::assertTrue(LineUnits::factor('SET', 'CBM', null, null, null, null)['unconvertible']);
        self::assertTrue(LineUnits::factor('PCS', 'LOT', 1.0, 1.0, 1.0, null)['unconvertible']);
        self::assertSame(1.0, LineUnits::factor('CBM', 'CBM', null, null, null, null)['factor']);
        self::assertSame(1.0, LineUnits::factor('SQM', 'm2', null, null, null, null)['factor']);
        self::assertSame(1.0, LineUnits::factor('', 'CBM', null, null, null, null)['factor'], 'no unit on the line: compared as before');
    }

    public function testMissingMessagesAskForTheSize(): void
    {
        self::assertSame('Enter the length (cm) — needed to compare a price per piece with the product\'s price per CBM.', LineUnits::missingMessage('piece_length_cm', 'PCS', 'CBM'));
        self::assertStringNotContainsString('too low', LineUnits::missingMessage('piece_thickness_cm', 'SQM', 'CBM'));
    }

    public function testTotalsCbmAndContainersOfEightCbm(): void
    {
        self::assertEqualsWithDelta(0.1872, LineUnits::cbmPerUnit('PCS', 180.0, 80.0, 13.0, null), 1e-9);
        self::assertNull(LineUnits::cbmPerUnit('PCS', null, 80.0, 13.0, null));
        self::assertSame([0, 1, 1, 2, 3], [LineUnits::containers(0.0), LineUnits::containers(0.5), LineUnits::containers(8.0), LineUnits::containers(8.01), LineUnits::containers(23.1)]);
    }

    public function testDimensionsTextAndSizeInput(): void
    {
        self::assertSame('180 × 80 × 13 cm', LineUnits::dimensionsText(180.0, 80.0, 13.0));
        self::assertSame('2.5 cm', LineUnits::dimensionsText(null, null, 2.5));
        [$size, $err] = LineUnits::readSize(['piece_length_cm' => '180', 'piece_width_cm' => '80.5', 'piece_thickness_cm' => '']);
        self::assertNull($err);
        self::assertSame(['piece_length_cm' => '180.00', 'piece_width_cm' => '80.50', 'piece_thickness_cm' => null], $size);
        self::assertNotNull(LineUnits::readSize(['piece_length_cm' => '-1'])[1]);
        self::assertNotNull(LineUnits::readSize(['piece_width_cm' => '1.234'])[1]);
        self::assertNotNull(LineUnits::readSize(['piece_thickness_cm' => '20000'])[1]);
    }

    public function testAProductWithoutAReferenceUnitIsComparedAsItIs(): void
    {
        // ProductSpecs labels a product with no reference unit "unit": any line unit compares 1:1, never "needs MD approval".
        self::assertSame(['factor' => 1.0, 'missing' => [], 'unconvertible' => false], LineUnits::factor('PCS', 'unit', null, null, null, null));
        self::assertSame(1.0, LineUnits::factor('SQM', '', null, null, null, null)['factor']);
        self::assertTrue(LineUnits::factor('BOX', 'CBM', null, null, null, null)['unconvertible']);
    }
}
