<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\MoneyFormat;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\LineTable;
use App\Services\PurchasingService;
use PHPUnit\Framework\TestCase;

/**
 * HNT-L01 (owner-confirmed twice, 13 Nov 2026): PHP is the reference for
 * money rounding; the fix was on Node only. This pins PHP's own figures for
 * the half-cent cases the Node twin (tests/unit/hntL01MoneyRoundingParity.test.js)
 * checks against the same fixture: milestones, vendor bill GST and totals,
 * pricing conversion and award value, Zoho payload and document amounts,
 * large-amount round(), price per piece.
 */
final class MoneyRoundingParityHntL01Test extends TestCase
{
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/hntl01_money_rounding_cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMilestonesUnchanged(): void
    {
        foreach (self::cases()['milestones'] as $c) {
            $amount = round((float) $c['fob'] * (float) $c['pct'] / 100, 2);
            $this->assertSame((float) $c['amount'], $amount);
            $this->assertSame((float) $c['balance_after'], OrderPaymentStatusRepository::balanceDue((float) $c['fob'], 0.0, ['advance_amount' => null], $amount));
        }
    }

    public function testVendorBillGstAndTotalUnchanged(): void
    {
        foreach (self::cases()['vendor_bills'] as $c) {
            $gst = round(round((float) $c['taxable'], 2) * (float) $c['rate']) / 100;
            $this->assertSame((float) $c['gst'], $gst);
            $this->assertSame((float) $c['total'], round((float) $c['taxable'] + $gst, 2));
        }
    }

    public function testPricingUnchanged(): void
    {
        $cases = self::cases();
        foreach ($cases['pricing'] as $c) {
            $this->assertSame((float) $c['converted'], round((float) $c['quote'] * (float) $c['factor'], 2));
        }
        foreach ($cases['awards'] as $c) {
            $this->assertSame((float) $c['value'], PurchasingService::awardValue(['unit_price_inr' => $c['unit_price_inr'], 'gst_percent' => (string) $c['gst_percent']], (float) $c['quantity']));
        }
        foreach ($cases['per_piece'] as $c) {
            $this->assertSame((float) $c['price'], LineTable::pricePerPiece((float) $c['unit_price'], (float) $c['per_piece']));
        }
    }

    public function testZohoPayloadAndDocumentAmountsUnchanged(): void
    {
        foreach (self::cases()['zoho'] as $c) {
            $this->assertSame($c['payload'], number_format((float) $c['x'], 2, '.', ''));
            $this->assertSame($c['document_usd'], MoneyFormat::amount($c['x'], 'USD'));
            $this->assertSame($c['document_inr'], MoneyFormat::amount($c['x'], 'INR'));
        }
        $this->assertSame('8484053.92', number_format(8484053.915, 2, '.', ''));
    }

    public function testRoundUnchangedOnLargeHalfCentAmounts(): void
    {
        foreach (self::cases()['round'] as $c) {
            $this->assertSame((float) $c['r2'], round((float) $c['x'], 2));
            $this->assertSame((float) $c['r4'], round((float) $c['x'], 4));
            $this->assertSame((float) $c['r0'], round((float) $c['x'], 0));
        }
    }
}
