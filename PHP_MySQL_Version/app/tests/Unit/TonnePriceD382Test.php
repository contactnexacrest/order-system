<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\SupplierOffers;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-382 (18 Nov 2026): Edit price / + Add product for a block — "Priced per" CBM or
 * Tonne. A price per tonne is stored per CBM (price × the product's weight per CBM) in the usual
 * fields; what was typed is kept (entered_unit 'TON', entered_price) for display:
 * "₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM". Without a weight per CBM the per-tonne
 * choice is refused with the LineUnits weight message. The products here have no id (no "Sold as"
 * ticks to read), so whether they are a block comes from their old forms.
 * Node twin: tests/unit/tonnePriceD382.test.js.
 */
final class TonnePriceD382Test extends TestCase
{
    private const BLOCK = ['id' => 0, 'forms' => 'block', 'reference_unit' => 'CBM'];

    public function testTheDisplayText(): void
    {
        self::assertSame('₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM', SupplierOffers::tonneText(30000.0, 2.9));
    }

    public function testAPricePerTonneIsStoredPerCbm(): void
    {
        $in = SupplierOffers::fromTonne(['price' => 30000.0, 'unit' => 'TON'], self::BLOCK, false, 'unit', 2.9);
        self::assertNull($in['tonne_error']);
        self::assertSame(87000.0, $in['price']);
        self::assertSame('CBM', $in['unit']);
        self::assertSame(['TON', 30000.0], [$in['entered_unit'], $in['entered_price']]);
    }

    public function testAPricePerCbmIsLeftAsItIs(): void
    {
        $in = SupplierOffers::fromTonne(['price' => 87000.0, 'unit' => 'CBM'], self::BLOCK, false, 'unit', 2.9);
        self::assertSame([87000.0, 'CBM', null, null, null], [$in['price'], $in['unit'], $in['entered_unit'], $in['entered_price'], $in['tonne_error']]);
    }

    public function testWithoutAWeightPerCbmTonneIsRefused(): void
    {
        $in = SupplierOffers::fromTonne(['price' => 30000.0, 'unit' => 'TON'], self::BLOCK, false, 'unit', 0.0);
        self::assertSame('Set the weight per CBM on the product or its category (Products) — needed to compare a price per Ton.', $in['tonne_error']);
        self::assertSame(30000.0, $in['price'], 'nothing converted');
    }

    public function testTonneOnlyForABlockPricedPerCbm(): void
    {
        self::assertTrue(SupplierOffers::tonnePriced(self::BLOCK, false));
        self::assertTrue(SupplierOffers::tonnePriced(['id' => 0, 'forms' => 'block', 'reference_unit' => 'SQM'], false, 'cbm'), 'an offer priced per CBM');
        self::assertFalse(SupplierOffers::tonnePriced(['id' => 0, 'forms' => 'slab', 'reference_unit' => 'CBM'], false), 'not a block');
        self::assertFalse(SupplierOffers::tonnePriced(['id' => 0, 'forms' => '', 'reference_unit' => 'CBM'], false), 'no forms = all forms, not a block');
        self::assertFalse(SupplierOffers::tonnePriced(self::BLOCK, true), 'a thickness grid');
        self::assertFalse(SupplierOffers::tonnePriced(['id' => 0, 'forms' => 'block', 'reference_unit' => 'SQM'], false), 'priced per SQM');
        $in = SupplierOffers::fromTonne(['price' => 30000.0, 'unit' => 'TON'], ['id' => 0, 'forms' => 'slab', 'reference_unit' => 'CBM'], false, 'unit', 2.9);
        self::assertSame('Priced per: Tonne is only for a block priced per CBM — choose the unit the price is per.', $in['tonne_error']);
    }
}
