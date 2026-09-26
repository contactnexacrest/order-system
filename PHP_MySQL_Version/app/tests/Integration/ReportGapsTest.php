<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ReportRepository;
use App\Tests\Support\DbTestCase;

/**
 * Regression coverage for the CA/Reports gap-analysis additions (debtors
 * ageing, freight cost, product sales, supplier performance, conversion
 * rate) — verified once by hand against real dev data (see the QA-4
 * session notes), pinned here so a future change can't silently break
 * the bucket/aggregation math.
 */
final class ReportGapsTest extends DbTestCase
{
    private function setPaymentStatus(int $orderId, array $fields): void
    {
        $pdo = \App\Config\Database::connection();
        $pdo->prepare('INSERT INTO order_payment_status (order_id) VALUES (:order_id)')->execute(['order_id' => $orderId]);
        if ($fields) {
            $sets = implode(', ', array_map(static fn(string $k): string => "{$k} = :{$k}", array_keys($fields)));
            $pdo->prepare("UPDATE order_payment_status SET {$sets} WHERE order_id = :order_id")
                ->execute($fields + ['order_id' => $orderId]);
        }
    }

    public function testAgeingReportBucketsAnOverdueBalanceLegCorrectly(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        // Order created "today" in the fixture, so force created_at back 45
        // days to land in the 31-60 day bucket deterministically.
        \App\Config\Database::connection()
            ->prepare('UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 45 DAY) WHERE id = :id')
            ->execute(['id' => $orderId]);
        $this->setPaymentStatus($orderId, ['balance_amount' => 1000.00]);

        $report = ReportRepository::ageingReport();
        $row = null;
        foreach ($report['rows'] as $r) {
            if ((int) $r['order_id'] === $orderId) {
                $row = $r;
            }
        }

        self::assertNotNull($row, 'the overdue balance leg should appear in the ageing report');
        self::assertSame('Balance', $row['leg']);
        self::assertSame(1000.0, $row['amount']);
        self::assertSame('31-60 days', $row['bucket']);
        self::assertGreaterThanOrEqual(31, $row['days_overdue']);
    }

    public function testAgeingReportExcludesAClearedLeg(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->setPaymentStatus($orderId, ['advance_amount' => 500.00, 'advance_cleared_at' => date('Y-m-d H:i:s')]);

        $report = ReportRepository::ageingReport();
        foreach ($report['rows'] as $r) {
            self::assertNotSame($orderId, $r['order_id'], 'a cleared leg must never appear as a debtor');
        }
    }

    public function testFreightCostReportAggregatesByForwarder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'CIF');
        $pdo = \App\Config\Database::connection();
        $pdo->prepare(
            'INSERT INTO order_freight (order_id, confirmed_freight_rate, insurance_amount, freight_forwarder_name)
             VALUES (:order_id, 900.00, 50.00, :forwarder)'
        )->execute(['order_id' => $orderId, 'forwarder' => 'Test Forwarder Ltd']);

        $report = ReportRepository::freightCostReport(null, null);
        self::assertArrayHasKey('Test Forwarder Ltd', $report['by_forwarder']);
        self::assertSame(1, $report['by_forwarder']['Test Forwarder Ltd']['USD']['order_count']);
        self::assertSame(900.0, $report['by_forwarder']['Test Forwarder Ltd']['USD']['total_rate']);
    }

    public function testProductSalesReportGroupsByHsCode(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Config\Database::connection()->prepare(
            'INSERT INTO order_products (order_id, line_no, description, quantity, unit_price, fob_value, hs_code, is_active)
             VALUES (:order_id, 1, :desc, 10, 100.00, 1000.00, :hs, 1)'
        )->execute(['order_id' => $orderId, 'desc' => 'Test Slab', 'hs' => '9999.99']);

        $report = ReportRepository::productSalesReport(null, null);
        self::assertArrayHasKey('9999.99', $report['by_hs_code']);
        self::assertSame(1000.0, $report['by_hs_code']['9999.99']['by_currency']['USD']['total_fob_value']);
    }

    public function testSupplierPerformanceReportComputesOnTimeRate(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $pdo = \App\Config\Database::connection();
        $pdo->prepare(
            "INSERT INTO suppliers (supplier_legal_name) VALUES ('Test Supplier Co')"
        )->execute();
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference, required_delivery_date, signed_at)
             VALUES (:order_id, :supplier_id, 'TEST-SPO-1', '2026-12-31', NOW())"
        )->execute(['order_id' => $orderId, 'supplier_id' => $supplierId]);
        $pdo->prepare(
            "INSERT INTO order_packing (order_id, packing_date) VALUES (:order_id, '2026-12-01')"
        )->execute(['order_id' => $orderId]);

        $report = ReportRepository::supplierPerformanceReport();
        $row = null;
        foreach ($report as $s) {
            if ($s['supplier_name'] === 'Test Supplier Co') {
                $row = $s;
            }
        }

        self::assertNotNull($row);
        self::assertSame(1, $row['po_count']);
        self::assertSame(1, $row['signed_count']);
        self::assertSame(1, $row['delivery_tracked_count']);
        self::assertSame(1, $row['on_time_count']);
        self::assertSame(100.0, $row['on_time_pct']);
    }

    public function testConversionRateReportHandlesZeroQuotationsWithoutDivisionByZero(): void
    {
        $report = ReportRepository::conversionRateReport('2099-01-01', '2099-01-02'); // a window with no activity
        self::assertSame(0, $report['quotations_sent']);
        self::assertNull($report['quotation_to_pi_pct']);
        self::assertNull($report['overall_quotation_to_confirmed_pct']);
    }
}
