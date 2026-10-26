<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\OrderLedgerService;
use App\Services\TestimonialSuggestionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (2026-10-26, t3): the ledger shows every money event — in
 * full only to Order Financials holders; the feedback box starts with an
 * order-specific testimonial; every export benefit scheme saves in one
 * submit; a recorded remittance reads back with its exchange rate.
 */
final class OrderPageT3Test extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function lastFlash(): string
    {
        $flash = $_SESSION['_flash'] ?? [];
        $last = is_array($flash) ? end($flash) : null;
        return (string) ($last['message'] ?? '');
    }

    /** @return array<string,mixed> a complete Supplier PO terms array, as the controller builds it */
    private function poData(array $overrides = []): array
    {
        $keys = ['material_stone_type', 'grade', 'surface_finish', 'dimensions', 'dimensional_tolerance', 'quantity', 'unit',
            'colour_reference', 'special_requirements', 'unit_price_inr', 'basic_value_inr', 'gst_rate_pct', 'gst_amount_inr',
            'total_payable_inr', 'advance_pct', 'advance_amount_inr', 'balance_amount_inr', 'delivery_location',
            'required_delivery_date', 'packing_requirement'];
        return array_merge(array_fill_keys($keys, null), $overrides);
    }

    private function orderWithMoney(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.0, '2026-10-20');
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 83.5, $this->userId);
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Ledger Quarry ' . bin2hex(random_bytes(3)), 'types' => ['Trader']]);
        $poId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-LEDGER-1', $this->poData(['advance_amount_inr' => '20000', 'balance_amount_inr' => '30000']));
        OrderSupplierPoRepository::markAdvancePaid($poId, '2026-10-21', $this->userId, 20000.0);
        OrderCostEntryRepository::create($orderId, 'ecgc_insurance', 'ECGC premium', 1500.0, '2026-10-22', $this->userId);
        return $orderId;
    }

    public function testTheFullLedgerShowsMoneyInAndOutWithANet(): void
    {
        $orderId = $this->orderWithMoney();

        $ledger = OrderLedgerService::forOrder($orderId, 'USD', true);

        $events = array_column($ledger['rows'], 'event');
        self::assertContains('Advance remittance received from buyer', $events);
        self::assertContains('Expense — ECGC Insurance: ECGC premium', $events);
        self::assertCount(1, array_filter($events, static fn (string $e): bool => str_starts_with($e, 'Supplier Advance paid')));
        self::assertEqualsWithDelta(83500.0, $ledger['totals']['buyer_inr'], 0.001);
        self::assertEqualsWithDelta(20000.0, $ledger['totals']['supplier_inr'], 0.001);
        self::assertEqualsWithDelta(1500.0, $ledger['totals']['expenses_inr'], 0.001);
        self::assertEqualsWithDelta(62000.0, $ledger['totals']['net_inr'], 0.001);
    }

    public function testWithoutOrderFinancialsTheLedgerKeepsToBuyerReceipts(): void
    {
        $orderId = $this->orderWithMoney();

        $ledger = OrderLedgerService::forOrder($orderId, 'USD', false);

        self::assertNull($ledger['totals']);
        foreach ($ledger['rows'] as $row) {
            self::assertNotSame('out', $row['direction'], $row['event']);
        }
    }

    public function testTheTestimonialIsBuiltFromTheOrderAndNeverRepeatsAnEarlierOne(): void
    {
        $order = ['order_reference' => 'SC/ORD/2026/777', 'port_of_discharge_city' => 'Hamburg'];
        $products = [['description' => 'Black Granite Slabs', 'quantity' => '120.000', 'unit' => 'm2', 'is_active' => 1]];

        $first = TestimonialSuggestionService::build($order, $products);
        self::assertStringContainsString('120 m2 of Black Granite Slabs', $first);
        self::assertStringContainsString('Hamburg', $first);
        self::assertSame($first, TestimonialSuggestionService::build($order, $products), 'same order, same suggestion');

        $second = TestimonialSuggestionService::build($order, $products, [$first]);
        self::assertNotSame($first, $second, 'never the text this client already left');
    }

    public function testEveryExportBenefitSchemeSavesInOneSubmit(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderExportBenefitSchemeRepository::initializeForOrder($orderId);
        $schemes = OrderExportBenefitSchemeRepository::forOrder($orderId);
        self::assertGreaterThan(1, count($schemes));

        $_POST = ['schemes' => array_map(static fn (array $s): array => ['scheme_name' => $s['scheme_name'], 'status' => 'not_eligible', 'reason' => 'short'], $schemes)];
        (new OrderFinancialsController())->setExportBenefitSchemeStatus(['id' => (string) $orderId]);
        self::assertStringContainsString('Nothing saved', $this->lastFlash());
        self::assertSame(count($schemes), OrderExportBenefitSchemeRepository::summaryForOrder($orderId)['pending'], 'all or nothing');

        $_POST = ['schemes' => array_map(static fn (array $s, int $i): array => ['scheme_name' => $s['scheme_name'], 'status' => $i === 0 ? 'eligible' : 'not_eligible', 'reason' => 'Not claimed on this shipment'], $schemes, array_keys($schemes))];
        (new OrderFinancialsController())->setExportBenefitSchemeStatus(['id' => (string) $orderId]);
        self::assertStringContainsString('eligibility saved for', $this->lastFlash());
        $after = array_column(OrderExportBenefitSchemeRepository::forOrder($orderId), 'status', 'scheme_name');
        self::assertSame('eligible', $after[$schemes[0]['scheme_name']]);
        self::assertSame('not_eligible', $after[$schemes[1]['scheme_name']]);
    }

    public function testARecordedRemittanceReadsBackWithItsExchangeRate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $_POST = ['advance_amount' => '1800', 'advance_received_at' => '2026-10-26', 'advance_exchange_rate' => '83.25'];
        \App\Services\StageGateService::passAndUnlockNext($orderId, 1, $this->userId);
        \App\Services\StageGateService::passAndUnlockNext($orderId, 2, $this->userId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Received USD 1,800.00 on 26 Oct 2026 at today\'s exchange rate of &#8377;83.2500 (= &#8377;149,850.00).', $html);
    }
}
