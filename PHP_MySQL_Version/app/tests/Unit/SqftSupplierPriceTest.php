<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\LineUnits;
use App\Services\SupplierOffers;
use App\Services\ThicknessPrices;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-364 (13 Nov 2026, "go with recommendation", mockup sqm_sqft_mockup): a
 * supplier price per SQM may be typed per SQFT — it is saved as the SQM price (× 10.7639),
 * and what was typed is kept in the notes and the price history. CBM stays for 50 mm and
 * above; a product whose own unit is SQFT keeps its SQFT price. The same unit names on every
 * screen: SQM, SQFT, CBM. Node twin: tests/unit/sqftSupplierPrice.test.js.
 */
final class SqftSupplierPriceTest extends TestCase
{
    public function testSqftIsConvertedToTheSqmPrice(): void
    {
        self::assertSame(1991.32, SupplierOffers::sqftToSqm(185.0));
        self::assertSame(2152.78, SupplierOffers::sqftToSqm(200.0));
        self::assertSame('typed ₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)', SupplierOffers::typedText(185.0));
    }

    public function testOnlyAPricePerSqmMayBeTypedPerSqft(): void
    {
        $grid = SupplierOffers::fromSqft(['price' => 185.0, 'unit' => 'SQFT', 'mm' => 20], ['reference_unit' => 'SQM'], true);
        self::assertSame([1991.32, 'm2', 185.0], [$grid['price'], $grid['unit'], $grid['typed_sqft']]);
        $cbm = SupplierOffers::fromSqft(['price' => 185.0, 'unit' => 'SQFT', 'mm' => ThicknessPrices::CBM], ['reference_unit' => 'SQM'], true);
        self::assertSame([185.0, null], [$cbm['price'], $cbm['typed_sqft']], '50 mm and above is per CBM');
        $sqm = SupplierOffers::fromSqft(['price' => 185.0, 'unit' => 'SQM', 'mm' => null], ['reference_unit' => 'SQM'], false);
        self::assertSame([185.0, null], [$sqm['price'], $sqm['typed_sqft']]);
        $perUnit = SupplierOffers::fromSqft(['price' => 185.0, 'unit' => 'SQFT', 'mm' => null], ['reference_unit' => 'SQM'], false);
        self::assertSame([1991.32, 'SQM'], [$perUnit['price'], $perUnit['unit']]);
        $ownSqft = SupplierOffers::fromSqft(['price' => 185.0, 'unit' => 'SQFT', 'mm' => null], ['reference_unit' => 'SQFT'], false);
        self::assertSame([185.0, null], [$ownSqft['price'], $ownSqft['typed_sqft']], 'a product sold per SQFT keeps its SQFT price');
    }

    public function testTheNotesKeepWhatWasTypedAndTheStaffNotes(): void
    {
        $n = SupplierOffers::notesWithTyped('Ex-works, Grade A', 185.0);
        self::assertSame("Ex-works, Grade A\ntyped ₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)", $n);
        self::assertSame("Ex-works, Grade A\ntyped ₹200 / SQFT = ₹2,152.78 / SQM (× 10.7639)", SupplierOffers::notesWithTyped($n, 200.0), 'the old typed line is replaced');
        self::assertSame('Ex-works, Grade A', SupplierOffers::notesWithTyped($n, null), 'a price typed per SQM drops it');
        self::assertNull(SupplierOffers::notesWithTyped(null, null));
    }

    public function testTheUnitNamesAreSqmAndSqft(): void
    {
        self::assertSame(['SQM', 'SQFT', 'CBM'], [LineUnits::label('M2'), LineUnits::label('sq ft'), LineUnits::label('cbm')]);
    }
}
