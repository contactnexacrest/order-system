<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Services\CaFinancialSnapshotService;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D6 of 2 Nov 2026 (confirmed twice; register D-225): the
 * Step 9a confirmed freight rate and insurance are entered in the ORDER's
 * currency — the Debit Note charges the buyer exactly that — so Step 12,
 * the CA annexure (CAFIN), the reports and the incentive must count them
 * in rupees at a real rate, never add USD 6,000 as ₹6,000.
 * Node twin: freightCostInInr.test.js.
 */
final class FreightCostInInrTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
    }

    /** A USD CIF order with freight 6,000 + insurance 200 confirmed at Step 9a. */
    private function cifOrder(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        Database::connection()->prepare("UPDATE orders SET currency_code = 'USD' WHERE id = :id")->execute(['id' => $orderId]);
        OrderProductRepository::add($orderId, 1, 'Test granite', null, null, '10', false, 'PCS', '1000');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '6000', 'insurance_amount' => '200']);
        return $orderId;
    }

    public function testFreightAndInsuranceAreConvertedAtTheAdvanceRate(): void
    {
        $orderId = $this->cifOrder();
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 83.5, $this->userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(501000.0, $p['freight_cost_inr'], 'USD 6,000 × 83.50');
        self::assertSame(16700.0, $p['insurance_cost_inr'], 'USD 200 × 83.50');
        self::assertFalse($p['freight_rate_missing']);
        self::assertSame('advance_rate', $p['freight_rate_source']);
        self::assertSame('USD 6,000.00 × 83.50 = ₹501,000.00', $p['freight_conversion_text']);
        self::assertSame('USD 200.00 × 83.50 = ₹16,700.00', $p['insurance_conversion_text']);
        self::assertSame(round($p['supplier_cost_inr'] + 501000 + 16700 + $p['other_costs_inr'] + $p['logistics_cost_inr'] + $p['expenses_inr'] + $p['zoho_expenses_inr'], 2), $p['total_cost_inr']);
    }

    public function testTheBuyersFreightPaymentRateWinsOnceRecorded(): void
    {
        $orderId = $this->cifOrder();
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 83.5, $this->userId);
        OrderPaymentStatusRepository::recordFreightReceived($orderId, 6200.0, '2026-06-10');
        OrderPaymentStatusRepository::setFreightInrActual($orderId, 520800.0, $this->userId); // 6,200 × 84

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame('freight_payment', $p['freight_rate_source']);
        self::assertSame(504000.0, $p['freight_cost_inr'], 'USD 6,000 × 84.00');
        self::assertSame(16800.0, $p['insurance_cost_inr'], 'USD 200 × 84.00');
    }

    public function testWithNoRateTheForeignAmountIsLeftOutAndFlagged(): void
    {
        $orderId = $this->cifOrder();

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(0.0, $p['freight_cost_inr'], 'never USD 6,000 counted as ₹6,000');
        self::assertSame(0.0, $p['insurance_cost_inr']);
        self::assertTrue($p['freight_rate_missing']);
        self::assertSame('USD 6,000.00 — not counted yet, no exchange rate', $p['freight_conversion_text']);
    }

    public function testTheForwardersRupeeBillsStillReplaceTheConfirmedRate(): void
    {
        $orderId = $this->cifOrder();
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 83.5, $this->userId);
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'D6 Forwarder', 'service_type' => 'freight_forwarder'], $this->userId);
        OrderVendorBillRepository::create($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'ocean_freight', 'bill_no' => 'OF-D6', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 480000, 'gst_inr' => 0], $this->userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertTrue($p['freight_from_bills']);
        self::assertSame(480000.0, $p['freight_cost_inr'], "the forwarder's bill is already in rupees");
        self::assertNull($p['freight_conversion_text']);
        self::assertSame(16700.0, $p['insurance_cost_inr'], 'insurance still converted');
    }

    public function testTheCaAnnexureShowsTheConversion(): void
    {
        $orderId = $this->cifOrder();
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 83.5, $this->userId);

        $text = json_encode(CaFinancialSnapshotService::build($orderId), JSON_UNESCAPED_UNICODE);

        self::assertStringContainsString('Confirmed rate (Step 9a): USD 6,000.00 × 83.50 = ₹501,000.00', (string) $text);
        self::assertStringContainsString('Step 9a: USD 200.00 × 83.50 = ₹16,700.00', (string) $text);
    }
}
