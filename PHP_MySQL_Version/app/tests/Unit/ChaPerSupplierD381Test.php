<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\ThicknessPrices;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-381 (18 Nov 2026, confirmed twice): CHA + transport per container per supplier.
 * Order of use: the price row's own figure → the row's directory supplier's figure → the product
 * default → none, with where it came from ('row' | 'supplier' | 'product'). A row with
 * fob_source 'computed' (an older price with the CHA already inside) never gets it, with the note
 * "CHA already inside this older price". The rows here carry the supplier's figure as the
 * repository joins it (supplier_cha_per_container / supplier_cha_port), so no database is needed.
 * Node twin: tests/unit/chaPerSupplierD381.test.js.
 */
final class ChaPerSupplierD381Test extends TestCase
{
    private static function rules(): array
    {
        return [
            'grid' => true, 'base_mm' => 20, 'k' => 40.0, 'cbm_from' => 50,
            'rows' => [['mm' => 20, 'pct' => 0.0, 'option' => '20 mm'], ['mm' => 30, 'pct' => 35.0, 'option' => '30 mm']],
            'container_cbm' => 8.0, 'container_max_t' => 27.0, 't_per_cbm' => 2.7, 'own' => [], 'category_id' => 1, 'category_name' => 'Natural Stone', 'unit' => 'SQM',
        ];
    }

    private static function row(?string $own, ?string $supplier, ?string $port = null, string $source = 'direct'): array
    {
        return ['cha_transport_per_container' => $own, 'supplier_id' => 7, 'supplier_cha_per_container' => $supplier, 'supplier_cha_port' => $port,
            'fob_source' => $source, 'includes_packing_loading' => 1];
    }

    public function testTheRowsOwnFigureComesFirst(): void
    {
        $src = ThicknessPrices::chaSource(self::row('60000.00', '45000.00', 'Mundra'), ['cha_transport_per_container' => '40000']);
        self::assertSame(['value' => 60000.0, 'source' => 'row', 'port' => null, 'computed' => false], $src);
        self::assertSame('this price', ThicknessPrices::chaSourceTag($src));
    }

    public function testThenTheSuppliersFigureWithItsPort(): void
    {
        $src = ThicknessPrices::chaSource(self::row(null, '45000.00', 'Mundra'), ['cha_transport_per_container' => '40000']);
        self::assertSame(['value' => 45000.0, 'source' => 'supplier', 'port' => 'Mundra', 'computed' => false], $src);
        self::assertSame('supplier · Mundra', ThicknessPrices::chaSourceTag($src));
        self::assertSame(45000.0, ThicknessPrices::chaPerContainer(self::row(null, '45000.00', 'Mundra'), []));
        self::assertSame('supplier', ThicknessPrices::chaSourceTag(ThicknessPrices::chaSource(self::row(null, '45000.00'), [])), 'no port: just "supplier"');
    }

    public function testThenTheProductDefaultThenNone(): void
    {
        $src = ThicknessPrices::chaSource(self::row(null, null), ['cha_transport_per_container' => '40000']);
        self::assertSame(['value' => 40000.0, 'source' => 'product', 'port' => null, 'computed' => false], $src);
        self::assertSame('product default', ThicknessPrices::chaSourceTag($src));
        $none = ThicknessPrices::chaSource(self::row(null, null), []);
        self::assertNull($none['value']);
        self::assertNull(ThicknessPrices::chaSourceTag($none));
        // A row with no directory supplier (and no joined figure) goes straight to the product default.
        self::assertSame(40000.0, ThicknessPrices::chaPerContainer(['cha_transport_per_container' => null], ['cha_transport_per_container' => '40000']));
    }

    public function testAComputedRowNeverGetsPerContainerCha(): void
    {
        $src = ThicknessPrices::chaSource(self::row('60000.00', '45000.00', 'Mundra', 'computed'), ['cha_transport_per_container' => '40000']);
        self::assertSame(['value' => null, 'source' => null, 'port' => null, 'computed' => true], $src);
        self::assertNull(ThicknessPrices::chaPerContainer(self::row('60000.00', '45000.00', 'Mundra', 'computed'), ['cha_transport_per_container' => '40000']));
        $c = ThicknessPrices::cost(self::rules(), [20 => 1600.0], self::row(null, '45000.00', 'Mundra', 'computed'), ['cha_transport_per_container' => '40000'], 'unit', null);
        self::assertEqualsWithDelta(0.0, $c['cha'], 1e-9);
        self::assertEqualsWithDelta(1600.0, $c['total'], 1e-6);
        self::assertSame('CHA already inside this older price', $c['note']);
    }

    public function testTheSuppliersFigureIsUsedInTheCost(): void
    {
        // ₹48,000 per container ÷ 8 CBM = ₹6,000 per CBM; per m² at 20 mm = 120.
        $c = ThicknessPrices::cost(self::rules(), [20 => 1600.0], self::row(null, '48000.00', 'Mundra'), ['cha_transport_per_container' => '40000'], 'unit', null);
        self::assertEqualsWithDelta(120.0, $c['cha'], 1e-6);
        self::assertEqualsWithDelta(1720.0, $c['total'], 1e-6);
        self::assertNull($c['note']);
    }

    public function testTheEditPricePlaceholderNamesTheFallback(): void
    {
        self::assertSame('Empty = supplier\'s ₹45,000.00 (Mundra)', ThicknessPrices::chaFallbackText(self::row('60000.00', '45000.00', 'Mundra'), ['cha_transport_per_container' => '40000']));
        self::assertSame('Empty = supplier\'s ₹45,000.00', ThicknessPrices::chaFallbackText(self::row(null, '45000.00'), []));
        self::assertSame('Empty = product default ₹40,000.00', ThicknessPrices::chaFallbackText(self::row(null, null), ['cha_transport_per_container' => '40000']));
        self::assertSame('Empty = no CHA + transport', ThicknessPrices::chaFallbackText(self::row(null, null), []));
    }
}
