<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\MoneyFormat;
use App\Services\LineTable;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"):
 * Indian digit grouping for the money of an INR order's documents, and the total CBM of a
 * detailed order's lines for the Weight & Volume box.
 * Node twin: tests/unit/moneyFormat.test.js (same cases, same wording).
 */
final class MoneyFormatTest extends TestCase
{
    public function testIndianGrouping(): void
    {
        $cases = [
            [0, '0.00'], [999, '999.00'], [1000, '1,000.00'], [100000, '1,00,000.00'], [175000, '1,75,000.00'],
            [616070, '6,16,070.00'], [1540175, '15,40,175.00'], [123456789.5, '12,34,56,789.50'],
            [-1540175, '-15,40,175.00'], [-999.5, '-999.50'], ['32760.004', '32,760.00'], [0.005, '0.01'],
        ];
        foreach ($cases as [$value, $expected]) {
            self::assertSame($expected, MoneyFormat::indian($value), (string) $value);
        }
    }

    public function testOnlyInrUsesIndianGrouping(): void
    {
        self::assertSame('15,40,175.00', MoneyFormat::amount(1540175, 'INR'));
        self::assertSame('15,40,175.00', MoneyFormat::amount(1540175, ' inr '));
        foreach (['USD', 'EUR', 'GBP', 'AED', '', null] as $currency) {
            self::assertSame('1,540,175.00', MoneyFormat::amount(1540175, $currency), (string) $currency);
            self::assertSame('-123,456,789.50', MoneyFormat::amount(-123456789.5, $currency), (string) $currency);
        }
    }

    /** @param array<string,mixed> $extra */
    private static function line(string $unit, ?float $l, ?float $w, ?float $h, ?int $pcs, ?string $qty, array $extra = []): array
    {
        return $extra + [
            'line_type' => 'goods', 'unit' => $unit, 'piece_length_cm' => $l, 'piece_width_cm' => $w, 'piece_thickness_cm' => $h,
            'pieces_count' => $pcs, 'quantity' => $qty, 'quantity_is_tbc' => 0,
        ];
    }

    public function testTotalCbmOfTheLines(): void
    {
        // The owner's example: CHM Black P6 180 × 80 × 13 × 25, 140 × 70 × 13 × 25, 120 × 60 × 13 × 10.
        $owner = [
            self::line('CBM', 180, 80, 13, 25, '4.680'),
            self::line('CBM', 140, 70, 13, 25, '3.185'),
            self::line('CBM', 120, 60, 13, 10, '0.936'),
        ];
        self::assertSame(8.801, LineTable::totalCbm($owner));
        // A CBM line without a size counts its quantity; a SQM / SQFT line with a thickness its area × thickness.
        self::assertSame(10.001, LineTable::totalCbm(array_merge($owner, [self::line('CBM', null, null, null, null, '1.200')])));
        self::assertSame(9.161, LineTable::totalCbm(array_merge($owner, [self::line('SQM', null, null, 2, null, '18.00')])));
        self::assertSame(0.2, LineTable::totalCbm([self::line('SQFT', null, null, 2, null, '107.639')]));
        // Services and charges are not counted.
        self::assertSame(8.801, LineTable::totalCbm(array_merge($owner, [self::line('LOT', null, null, null, null, '1', ['line_type' => 'charge'])])));
        // A goods line that cannot be turned into CBM: "To Be Confirmed".
        self::assertNull(LineTable::totalCbm(array_merge($owner, [self::line('SQM', 60, 60, null, 500, '180.00')])), 'SQM without a thickness');
        self::assertNull(LineTable::totalCbm(array_merge($owner, [self::line('PCS', null, null, null, null, '20')])), 'PCS without a size');
        self::assertNull(LineTable::totalCbm(array_merge($owner, [self::line('TON', null, null, null, null, '3')])), 'another unit');
        self::assertNull(LineTable::totalCbm(array_merge($owner, [self::line('CBM', null, null, null, null, null, ['quantity_is_tbc' => 1])])), 'a quantity to be confirmed');
        self::assertNull(LineTable::totalCbm([]), 'no goods line');
    }
}
