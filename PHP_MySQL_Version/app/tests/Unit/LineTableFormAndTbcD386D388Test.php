<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\MoneyFormat;
use App\Services\LineTable;
use PHPUnit\Framework\TestCase;

/**
 * Owner decisions D-386 and D-388 (18 Nov 2026, document changes confirmed twice), the pure part:
 *  - D-386: the grey line of the detailed table prints "Product Form: <form>" immediately before
 *    "HS Code:" for a line with a Sold as (the form comes in $names, set only for the QT / PI / OC /
 *    CI); a line without one prints exactly as before;
 *  - D-388: a line whose quantity is "To Be Confirmed" never prints 0.00, and the TOTAL is
 *    "To Be Confirmed" (every line TBC), "<known> + line 2 To Be Confirmed" (one) or
 *    "<known> + lines 2, 4 To Be Confirmed" (several).
 * Node twin: tests/unit/lineTableFormAndTbcD386D388.test.js.
 */
final class LineTableFormAndTbcD386D388Test extends TestCase
{
    private static function line(int $id, string $unit, ?string $qty, ?string $price, bool $tbc = false): array
    {
        $amount = !$tbc && $qty !== null && $price !== null ? number_format(round((float) $qty * (float) $price, 2), 2, '.', '') : null;
        return [
            'id' => $id, 'description' => 'Black Galaxy ' . $id, 'finish' => 'Polished', 'unit' => $unit, 'line_type' => 'goods',
            'piece_length_cm' => null, 'piece_width_cm' => null, 'piece_thickness_cm' => null, 'pieces_count' => null,
            'quantity' => $tbc ? null : $qty, 'quantity_is_tbc' => $tbc ? 1 : 0, 'unit_price' => $price, 'fob_value' => $amount,
            'hs_code' => '25161100', 'as_per_annexure' => 0, 'size_note' => null, 'dimensions' => null,
        ];
    }

    private static function formatted(array $lines): array
    {
        return array_map(static fn (array $l): array => [
            'description' => $l['description'], 'quantity' => $l['quantity_is_tbc'] ? 'To Be Confirmed' : (string) $l['quantity'], 'unit' => $l['unit'],
            'unit_price' => $l['unit_price'] !== null ? MoneyFormat::amount($l['unit_price'], 'USD') : 'To Be Confirmed',
            'amount' => $l['fob_value'] !== null ? MoneyFormat::amount($l['fob_value'], 'USD') : 'To Be Confirmed',
            'line_type' => 'goods', 'attributes' => [], 'sac_code' => null, 'dimensions' => null, 'finish' => $l['finish'], 'hs_code' => $l['hs_code'],
        ], $lines);
    }

    private static function build(array $lines, array $names = []): array
    {
        return LineTable::build($lines, self::formatted($lines), 'USD', null, true, [], $names);
    }

    /** @return list<string> the grey line's labels */
    private static function labels(array $row): array
    {
        return array_map(static fn (array $d): string => (string) $d['label'], $row['detail']);
    }

    public function testD386ProductFormComesImmediatelyBeforeTheHsCode(): void
    {
        $t = self::build([self::line(11, 'CBM', '25', '1950.00'), self::line(12, 'CBM', '10', '1950.00')], [11 => ['form' => 'Raw block']]);
        self::assertSame(['Product Form:', 'HS Code:', 'Country of Origin:'], self::labels($t['rows'][0]));
        self::assertSame('Raw block', $t['rows'][0]['detail'][0]['text']);
        // A line without a Sold as prints exactly as before.
        self::assertSame(['HS Code:', 'Country of Origin:'], self::labels($t['rows'][1]));
        // No form passed (Buyer PO, Packing List, every other document): no "Product Form".
        $plain = self::build([self::line(11, 'CBM', '25', '1950.00')]);
        self::assertNotContains('Product Form:', self::labels($plain['rows'][0]));
    }

    public function testD386AfterTheSizeNoteAndAttributes(): void
    {
        $l = self::line(21, 'CBM', '25', '1950.00') + [];
        $l['size_note'] = 'Length 160–210 cm';
        $t = self::build([$l], [21 => ['form' => 'Sawn block']]);
        self::assertSame(['Sizes:', 'Product Form:', 'HS Code:', 'Country of Origin:'], self::labels($t['rows'][0]));
    }

    public function testD388TbcTotalWording(): void
    {
        self::assertSame('9,750.00', LineTable::tbcTotal('9,750.00', [], false), 'no line to be confirmed: as today');
        self::assertSame('To Be Confirmed', LineTable::tbcTotal('0.00', [1, 2], true));
        self::assertSame('9,750.00 + line 2 To Be Confirmed', LineTable::tbcTotal('9,750.00', [2], false));
        self::assertSame('9,750.00 + lines 2, 4 To Be Confirmed', LineTable::tbcTotal('9,750.00', [4, 2], false));
    }

    public function testD388EveryLineTbcTotalIsToBeConfirmed(): void
    {
        $t = self::build([self::line(1, 'CBM', null, '1950.00', true), self::line(2, 'CBM', null, '1800.00', true)]);
        self::assertSame('To Be Confirmed', $t['total']['amount']);
        self::assertSame(['To Be Confirmed', 'To Be Confirmed'], array_column($t['rows'], 'total'), 'a TBC line never prints 0.00');
        self::assertSame('To Be Confirmed', $t['rows'][0]['qty']);
        self::assertSame('1,950.00', $t['rows'][0]['price_unit'], 'the price per unit prints as today');
    }

    public function testD388OneLineTbc(): void
    {
        $t = self::build([self::line(1, 'CBM', '5', '1950.00'), self::line(2, 'CBM', null, '1800.00', true)]);
        self::assertSame('9,750.00 + line 2 To Be Confirmed', $t['total']['amount']);
        self::assertSame(['9,750.00', 'To Be Confirmed'], array_column($t['rows'], 'total'));
    }

    public function testD388SeveralLinesTbc(): void
    {
        $t = self::build([
            self::line(1, 'CBM', '5', '1950.00'), self::line(2, 'CBM', null, '1800.00', true),
            self::line(3, 'CBM', '2', '1000.00'), self::line(4, 'CBM', null, '1700.00', true),
        ]);
        self::assertSame('11,750.00 + lines 2, 4 To Be Confirmed', $t['total']['amount']);
    }
}
