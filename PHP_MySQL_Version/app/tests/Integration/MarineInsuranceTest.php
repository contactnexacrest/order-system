<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductCategoryRepository;
use App\Services\DocumentDataAssembler;
use App\Services\MarineInsurance;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 21 Nov 2026:
 *  D-402 ("approve A") — the insurance of a CIF order is worked out from the CIF value: rate × 110 % × (FOB + freight)
 *        ÷ (1 − rate × 110 %), at the highest rate among the order's product categories (Natural Stone 0.30 % incl.
 *        breakage), else the default 0.20 %; rounded up; no GST; a typed figure is kept; CFR / FOB get none.
 *  D-403 (confirmed twice) — the QT / PI "Insurance" row of a CIF order names the cover.
 *  D-404 (confirmed twice) — the QT / PI line "Estimated total CIF / CFR value"; nothing on FOB orders.
 * Node twin: marineInsurance.test.js.
 */
final class MarineInsuranceTest extends DbTestCase
{
    private const S = ['cover_pct' => 110.0, 'rate_pct' => 0.2, 'min_premium' => null, 'rounding' => 'up', 'add_gst' => false, 'cover_wording' => 'ICC (A) all risks'];

    public function testThePremiumIsOnTheCifValueAt110PercentRoundedUp(): void
    {
        // FOB 4,500 + freight 950 at 0.30 %: 0.0033 × 5,450 ÷ 0.9967 = 18.044… → 19 (18.04 at 2 decimals).
        self::assertSame(19.0, MarineInsurance::premium(4500, 950, 0.30, self::S));
        self::assertSame(18.04, MarineInsurance::premium(4500, 950, 0.30, ['rounding' => '2dp'] + self::S));
        // The cover is a true 110 % of CIF: premium = rate × 110 % × (FOB + freight + premium).
        $p = MarineInsurance::premium(4500, 950, 0.30, ['rounding' => '2dp'] + self::S);
        self::assertEqualsWithDelta(0.003 * 1.1 * (5450 + $p), $p, 0.01);
        // Default rate 0.20 %: 0.0022 × 5,450 ÷ 0.9978 = 12.016… → 13.
        self::assertSame(13.0, MarineInsurance::premium(4500, 950, 0.20, self::S));
        // GST added only when the setting says so; a minimum premium lifts a small figure.
        self::assertSame(22.0, MarineInsurance::premium(4500, 950, 0.30, ['add_gst' => true] + self::S));
        self::assertSame(25.0, MarineInsurance::premium(4500, 950, 0.30, ['min_premium' => 25.0] + self::S));
        self::assertSame(0.0, MarineInsurance::premium(0, 0, 0.30, self::S));
    }

    public function testTheHighestCategoryRateWinsAndNaturalStoneInsuresWithBreakage(): void
    {
        $stone = MarineInsurance::rateFor([null], self::S);
        self::assertSame(0.3, $stone['rate_pct']);
        self::assertSame('ICC (A) incl. breakage', $stone['wording']);
        self::assertSame('Natural Stone', $stone['category']);

        $pdo = Database::connection();
        $pdo->exec("INSERT INTO product_categories (name, is_system, is_active, sort_order) VALUES ('PHPUnit Glassware', 0, 1, 9)");
        $glass = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO product_categories (name, is_system, is_active, sort_order) VALUES ('PHPUnit Steel', 0, 1, 9)");
        $steel = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE product_categories SET insurance_rate_pct = 0.4500, insurance_cover_wording = 'ICC (A) incl. breakage and leakage' WHERE id = {$glass}");
        // A category without its own rate uses the default (0.20 %).
        self::assertSame(0.2, MarineInsurance::rateFor([$steel], self::S)['rate_pct']);
        self::assertSame('ICC (A) all risks', MarineInsurance::rateFor([$steel], self::S)['wording']);
        // Mixed: the highest rate among the order's categories.
        self::assertSame(0.45, MarineInsurance::rateFor([null, $glass, $steel], self::S)['rate_pct']);
        self::assertSame(0.3, MarineInsurance::rateFor([null, $steel], self::S)['rate_pct']);
    }

    public function testACifOrderWithoutAFigureGetsTheCalculatedOneAndATypedFigureIsKept(): void
    {
        $order = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        OrderProductRepository::add($order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
        $pdo = Database::connection();
        $pdo->exec("UPDATE orders SET indicative_freight_amount = 950, indicative_insurance_amount = NULL WHERE id = {$order}");
        MarineInsurance::fillIfEmpty($order);
        self::assertSame('19.00', (string) $pdo->query("SELECT indicative_insurance_amount FROM orders WHERE id = {$order}")->fetchColumn());

        $pdo->exec("UPDATE orders SET indicative_insurance_amount = 25 WHERE id = {$order}");
        MarineInsurance::fillIfEmpty($order);
        self::assertSame('25.00', (string) $pdo->query("SELECT indicative_insurance_amount FROM orders WHERE id = {$order}")->fetchColumn());

        // CFR and FOB orders never get insurance.
        foreach (['CFR', 'FOB'] as $term) {
            $o = $this->createTestOrder($this->createTestClient(), $term, 'Standard — New Buyer', 'EUR');
            OrderProductRepository::add($o, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
            $pdo->exec("UPDATE orders SET indicative_freight_amount = 950, indicative_insurance_amount = NULL WHERE id = {$o}");
            MarineInsurance::fillIfEmpty($o);
            self::assertNull($pdo->query("SELECT indicative_insurance_amount FROM orders WHERE id = {$o}")->fetchColumn() ?: null, $term);
        }
    }

    public function testTheSettingsFormAndTheCategoryFormCheckTheirValues(): void
    {
        $base = ['margin_pct' => '15', 'other_cost_pct' => '5.5', 'pricing_fx_rate' => '82', 'buffer_pct' => '3', 'incentive_mode' => 'pct', 'incentive_value' => '15', 'stale_days' => '60'];
        [$values, $errors] = PricingService::readSettingsForm($base + ['insurance_cover_pct' => '110', 'insurance_rate_pct' => '0.3', 'insurance_min_premium' => '', 'insurance_rounding' => 'up', 'insurance_add_gst' => '0', 'insurance_cover_wording' => 'ICC (A) all risks']);
        self::assertSame([], $errors);
        self::assertSame('110.00', $values['insurance_cover_pct']);
        self::assertSame('0.3000', $values['insurance_rate_pct']);
        self::assertSame('', $values['insurance_min_premium']);
        [, $errors] = PricingService::readSettingsForm($base + ['insurance_cover_pct' => '90', 'insurance_rate_pct' => '0', 'insurance_cover_wording' => '']);
        self::assertCount(3, $errors);
        // A form without the fields keeps the stored values (nothing is read).
        [$values] = PricingService::readSettingsForm($base);
        self::assertArrayNotHasKey('insurance_rate_pct', $values);

        [$s, $error] = ProductCategoryRepository::parseSettings(['insurance_rate_pct' => '0.35', 'insurance_cover_wording' => 'ICC (A) incl. breakage'], [], []);
        self::assertNull($error);
        self::assertSame('0.3500', $s['insurance_rate_pct']);
        [, $error] = ProductCategoryRepository::parseSettings(['insurance_rate_pct' => '9'], [], []);
        self::assertStringContainsString('Insurance rate', (string) $error);
        [$s] = ProductCategoryRepository::parseSettings(['insurance_rate_pct' => ''], [], []);
        self::assertNull($s['insurance_rate_pct']);
        // The global settings are read with their defaults until someone saves the page.
        self::assertSame(110.0, MarineInsurance::settings(PricingRepository::settings())['cover_pct']);
    }

    public function testTheEstimatedValueLineAndTheCoverWording(): void
    {
        $money = static fn ($v): string => number_format((float) $v, 2);
        $cif = DocumentDataAssembler::estimatedValue(false, true, 4500.0, '950.00', '19.00', $money, 'EUR', [], false, true);
        self::assertSame('CIF', $cif['term']);
        self::assertSame('4,519.00', $money(4519)); // sanity of the helper
        self::assertSame('5,469.00', $cif['total']);
        self::assertSame('= FOB 4,500.00 + freight 950.00 + insurance 19.00', $cif['breakdown']);
        self::assertSame('Advance and balance are on the FOB value. The Commercial Invoice will show FOB + the actual freight & insurance (billed by Debit Note). Open any Letter of Credit for this CIF value.', $cif['note']);

        $cfr = DocumentDataAssembler::estimatedValue(false, false, 4500.0, '950.00', null, $money, 'EUR', [], false, true);
        self::assertSame('CFR', $cfr['term']);
        self::assertSame('5,450.00', $cfr['total']);
        self::assertSame('= FOB 4,500.00 + indicative freight 950.00', $cfr['breakdown']);
        self::assertStringContainsString('Insurance is arranged by the buyer (CFR). Open any Letter of Credit for this CFR value.', $cfr['note']);

        // D-409: without LC payment terms the Letter of Credit sentence is left out.
        self::assertSame('Advance and balance are on the FOB value. The Commercial Invoice will show FOB + the actual freight & insurance (billed by Debit Note).', DocumentDataAssembler::estimatedValue(false, true, 4500.0, '950.00', '19.00', $money, 'EUR', [], false, false)['note']);
        self::assertSame('Advance and balance are on the FOB value. The Commercial Invoice will show FOB + the actual freight (billed by Debit Note). Insurance is arranged by the buyer (CFR).', DocumentDataAssembler::estimatedValue(false, false, 4500.0, '950.00', null, $money, 'EUR', [], false)['note']);

        // Freight not entered yet: the known part and "To Be Confirmed".
        $open = DocumentDataAssembler::estimatedValue(false, true, 8040.0, null, null, $money, 'USD', [], false);
        self::assertSame('8,040.00 + freight and insurance To Be Confirmed', $open['total']);
        self::assertSame('= FOB 8,040.00 + freight To Be Confirmed + insurance To Be Confirmed', $open['breakdown']);

        // FOB: nothing.
        self::assertNull(DocumentDataAssembler::estimatedValue(true, false, 4500.0, '950', '19', $money, 'EUR', [], false));

        $order = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        OrderProductRepository::add($order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
        self::assertSame('ICC (A) incl. breakage, 110% of CIF value — arranged by seller; actual premium billed by Debit Note.', DocumentDataAssembler::insuranceCoverText($order));
    }
}
