<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\FxRateService;
use App\Services\PriceRules;
use App\Services\ThicknessPrices;
use PHPUnit\Framework\TestCase;

/**
 * Owner decisions of 11 Nov 2026, double-confirmed — the pure maths:
 *  - D-300 (point 12): one supplier price for every thickness — 30 mm = 20 mm + 35 %,
 *    40 mm = 20 mm + 70 %, 50 mm+ per CBM = 20 mm per m² × 40, in every direction; TYPED
 *    prices stay, CALCULATED ones follow; 0 / empty is calculated again, all empty is refused;
 *    CHA + transport per container → per unit from what one container really carries.
 *  - D-301 (point 4): the market rate from either source; pricing rate = market × (1 − cushion %).
 * Figures are the ones in the owner's mockups (p12.png, p12b.png, rules.png).
 * Node twin: tests/unit/thicknessPrices.test.js.
 */
final class ThicknessPricesTest extends TestCase
{
    /** Natural Stone's seeded rules. */
    private static function rules(array $extra = []): array
    {
        return $extra + [
            'grid' => true, 'base_mm' => 20, 'k' => 40.0, 'cbm_from' => 50,
            'rows' => [['mm' => 15, 'pct' => -20.0, 'option' => '15 mm'], ['mm' => 18, 'pct' => -5.0, 'option' => '18 mm'], ['mm' => 20, 'pct' => 0.0, 'option' => '20 mm'],
                ['mm' => 30, 'pct' => 35.0, 'option' => '30 mm'], ['mm' => 40, 'pct' => 70.0, 'option' => '40 mm']],
            'container_cbm' => 8.0, 'container_max_t' => 27.0, 't_per_cbm' => 2.7, 'own' => [], 'category_id' => 1, 'category_name' => 'Natural Stone', 'unit' => 'SQM',
        ];
    }

    private static function prices(array $done): array
    {
        $out = [];
        foreach ($done['values'] as $mm => $v) {
            $out[$mm] = [$v['price'], $v['how']];
        }
        return $out;
    }

    public function testTyping20mmWorksOutEveryOtherThickness(): void
    {
        $d = ThicknessPrices::complete(self::rules(), [20 => 1600.0]);
        self::assertTrue($d['ok']);
        self::assertSame([15 => [1280.0, 'calculated'], 18 => [1520.0, 'calculated'], 20 => [1600.0, 'typed'], 30 => [2160.0, 'calculated'],
            40 => [2720.0, 'calculated'], 0 => [64000.0, 'calculated']], self::prices($d));
        self::assertSame('20 mm + 35%', $d['values'][30]['rule']);
        self::assertSame('20 mm price × 40', $d['values'][0]['rule']);
    }

    public function testItWorksInEveryDirection(): void
    {
        // Only the per-CBM price: 64,000 ÷ 40 = 1,600 per m² at 20 mm.
        $d = ThicknessPrices::complete(self::rules(), [0 => 64000.0]);
        self::assertSame([1600.0, 'calculated'], [$d['values'][20]['price'], $d['values'][20]['how']]);
        self::assertSame(2160.0, $d['values'][30]['price']);
        // Only 40 mm: 2,720 ÷ 1.70 = 1,600.
        $d = ThicknessPrices::complete(self::rules(), [40 => 2720.0]);
        self::assertSame(1600.0, $d['values'][20]['price']);
        self::assertSame(64000.0, $d['values'][0]['price']);
        // Only 30 mm at 2,100: the base is 2,100 ÷ 1.35.
        $d = ThicknessPrices::complete(self::rules(), [30 => 2100.0]);
        self::assertSame(1555.56, $d['values'][20]['price']);
        self::assertSame(2644.44, $d['values'][40]['price']);
        self::assertSame(62222.22, $d['values'][0]['price']);
    }

    public function testTypedPricesStayAndCalculatedOnesFollowTheBase(): void
    {
        // p12.png: 20 mm 1,600 and 40 mm 2,650 typed (the supplier's own quote).
        $d = ThicknessPrices::complete(self::rules(), [20 => 1600.0, 40 => 2650.0]);
        self::assertSame([2650.0, 'typed', 2720.0], [$d['values'][40]['price'], $d['values'][40]['how'], $d['values'][40]['calc']]);
        self::assertSame(64000.0, $d['values'][0]['price']);
        // p12b.png step 3: 20 mm changed to 1,700 — 30 mm typed stays 2,100 (calculated would be 2,295), 40 mm → 2,890, 50 mm+ → 68,000.
        $d = ThicknessPrices::complete(self::rules(), [20 => 1700.0, 30 => 2100.0]);
        self::assertSame([2100.0, 'typed', 2295.0], [$d['values'][30]['price'], $d['values'][30]['how'], $d['values'][30]['calc']]);
        self::assertSame(2890.0, $d['values'][40]['price']);
        self::assertSame(68000.0, $d['values'][0]['price']);
    }

    public function testNothingTypedIsRefused(): void
    {
        foreach ([[], [20 => 0.0], [30 => 0.0, 0 => 0.0]] as $typed) {
            $d = ThicknessPrices::complete(self::rules(), $typed);
            self::assertFalse($d['ok']);
            self::assertSame('Enter at least one price.', $d['error']);
        }
    }

    public function testReadingTheForm(): void
    {
        $r = self::rules();
        // With the browser script: TYPED and CALCULATED as marked.
        [$t, $e] = ThicknessPrices::readForm(['price' => [20 => '1600.00', 30 => '2160.00', 0 => '64000.00'], 'how' => [20 => 'typed', 30 => 'calculated', 0 => 'calculated']], $r);
        self::assertNull($e);
        self::assertSame([20 => 1600.0], $t);
        // A "calculated" box whose value differs was typed over; 0 or empty is calculated again.
        [$t] = ThicknessPrices::readForm(['price' => [20 => '1600', 30 => '2100', 40 => '0', 0 => ''], 'how' => [20 => 'typed', 30 => 'calculated', 40 => 'typed']], $r);
        self::assertSame([20 => 1600.0, 30 => 2100.0], $t);
        // Without the script every filled box is typed; thousands separators are accepted.
        [$t] = ThicknessPrices::readForm(['price' => [0 => '64,000']], $r);
        self::assertSame([0 => 64000.0], $t);
        // All empty / all zero is refused; a word is refused beside its thickness.
        self::assertSame('Enter at least one price.', ThicknessPrices::readForm(['price' => [20 => '', 30 => '0']], $r)[1]);
        self::assertStringStartsWith('30 mm: enter the price in ₹', (string) ThicknessPrices::readForm(['price' => [30 => 'abc']], $r)[1]);
        // A thickness that is not in the rules is ignored.
        self::assertSame([20 => 1600.0], ThicknessPrices::readForm(['price' => [20 => '1600', 25 => '1800']], $r)[0]);
    }

    public function testChaAndTransportPerUnitFromWhatAContainerCarries(): void
    {
        // p12.png: ₹60,000 per container, 8 CBM, 2.7 t per CBM = 21.6 t ≤ 27 t → ₹7,500 per CBM.
        $c = ThicknessPrices::chaPerCbm(60000.0, 8.0, 27.0, 2.7);
        self::assertSame([7500.0, 8.0, false], [$c['per_cbm'], $c['fits_cbm'], $c['by_weight']]);
        self::assertEqualsWithDelta(21.6, $c['weight_t'], 1e-9);
        // A heavy stone (3.5 t per CBM) reaches 27 t first: 27 ÷ 3.5 = 7.71 CBM fit.
        $c = ThicknessPrices::chaPerCbm(60000.0, 8.0, 27.0, 3.5);
        self::assertTrue($c['by_weight']);
        self::assertEqualsWithDelta(27 / 3.5, $c['fits_cbm'], 1e-9);
        self::assertEqualsWithDelta(7777.78, $c['per_cbm'], 0.005);
        self::assertNull(ThicknessPrices::chaPerCbm(null, 8.0, 27.0, 2.7)['per_cbm']);
        // "Same as per CBM": 1,600 per m² at 20 mm = 80,000; 2,160 at 30 mm = 72,000; 2,650 at 40 mm = 66,250.
        self::assertSame([80000.0, 72000.0, 66250.0], [ThicknessPrices::m2ToCbm(1600, 20), ThicknessPrices::m2ToCbm(2160, 30), ThicknessPrices::m2ToCbm(2650, 40)]);
    }

    public function testFobCostPerUnit(): void
    {
        $row = ['cha_transport_per_container' => '60000.00', 'includes_packing_loading' => 1];
        // p12.png (2 of 2): 1,600 + 150 (₹60,000 ÷ 400 m²) = 1,750 per m² at 20 mm.
        $c = ThicknessPrices::cost(self::rules(), [20 => 1600.0], $row, [], 'unit', null);
        self::assertEqualsWithDelta(1600.0, $c['supplier'], 1e-6);
        self::assertEqualsWithDelta(150.0, $c['cha'], 1e-6);
        self::assertEqualsWithDelta(1750.0, $c['total'], 1e-6);
        // 30 mm: 2,160 + 225.
        self::assertEqualsWithDelta(2385.0, ThicknessPrices::cost(self::rules(), [20 => 1600.0], $row, [], 'unit', 30.0)['total'], 1e-6);
        // A product sold per CBM: 80,000 + 7,500 at 20 mm; 50 mm and above: 64,000 + 7,500.
        self::assertEqualsWithDelta(87500.0, ThicknessPrices::cost(self::rules(['unit' => 'CBM']), [20 => 1600.0], $row, [], 'unit', null)['total'], 1e-6);
        self::assertEqualsWithDelta(71500.0, ThicknessPrices::cost(self::rules(['unit' => 'CBM']), [20 => 1600.0], $row, [], 'cbm', 130.0)['total'], 1e-6);
        // Packing and loading not in the price: the product's packing + loading are added; the product's CHA default is used.
        $c = ThicknessPrices::cost(self::rules(), [20 => 1600.0], ['cha_transport_per_container' => null, 'includes_packing_loading' => 0],
            ['cha_transport_per_container' => '40000', 'default_packing_cost' => '10', 'default_loading_cost' => '5'], 'unit', null);
        self::assertEqualsWithDelta(15.0, $c['packing'], 1e-6);
        self::assertEqualsWithDelta(100.0, $c['cha'], 1e-6);
        self::assertNull(ThicknessPrices::cost(self::rules(), [], $row, [], 'unit', null), 'nothing typed');
    }

    public function testTheRulesSummaryLine(): void
    {
        self::assertSame('15 mm = 20 mm − 20 % · 18 mm = 20 mm − 5 % · 30 mm = 20 mm + 35 % · 40 mm = 20 mm + 70 % · 50 mm+ per CBM = 20 mm × 40 · container 8 CBM / 27 t',
            PriceRules::summary(self::rules()));
        self::assertSame('50 mm and above', ThicknessPrices::label(0));
        self::assertSame('2.5', ThicknessPrices::fmt(2.5));
    }

    public function testExchangeRateParsingAndCushion(): void
    {
        self::assertSame(88.4, FxRateService::parse('{"amount":1.0,"base":"USD","date":"2026-11-10","rates":{"INR":88.4}}'));
        self::assertSame(88.41, FxRateService::parse('{"result":"success","base_code":"USD","rates":{"EUR":0.92,"INR":88.41}}'));
        self::assertNull(FxRateService::parse('not json'));
        self::assertNull(FxRateService::parse('{"rates":{"EUR":0.92}}'));
        self::assertNull(FxRateService::parse('{"rates":{"INR":0.5}}'), 'not a sensible rate');
        // rules.png / p14.png: ₹88.40 × (1 − 2 %) = ₹86.632.
        self::assertSame(86.632, FxRateService::pricingRateFor(88.40, 2.0));
        self::assertSame(88.4, FxRateService::pricingRateFor(88.40, 0.0));
        self::assertSame('+2.40 %', FxRateService::signed(2.4));
        self::assertSame('−3.10 %', FxRateService::signed(-3.1));
        self::assertSame([['fx_mode' => 'manual', 'fx_cushion_pct' => '2.5'], []], FxRateService::readForm(['fx_mode' => 'manual', 'fx_cushion_pct' => '2.5']));
        foreach (['5.5', '2.25', '-1', 'x'] as $bad) {
            self::assertNotSame([], FxRateService::readForm(['fx_cushion_pct' => $bad])[1], "cushion {$bad} refused");
        }
        self::assertNotSame([], FxRateService::readForm(['fx_mode' => 'sometimes'])[1]);
        self::assertSame([[], []], FxRateService::readForm([]), 'a form without the fields keeps the stored values');
    }
}
