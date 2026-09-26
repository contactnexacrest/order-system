<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CaExpenseRepository;
use App\Repositories\CaRepository;
use App\Tests\Support\DbTestCase;

/**
 * Regression coverage for the CA/Reports gap-analysis additions specific
 * to the CA module: the TDS Payable Summary and the FY-Close Readiness
 * checklist.
 */
final class CaGapsTest extends DbTestCase
{
    public function testTdsSummaryGroupsByMonthAndQuarter(): void
    {
        $id = CaExpenseRepository::insert('ZOHO-TEST-1', 'Freight', 'Test expense', 'Test Vendor', 1000.00, 'INR', '2026-05-15');
        CaExpenseRepository::setTds($id, true, 100.00, 1);

        $summary = CaExpenseRepository::tdsSummary();
        $row = null;
        foreach ($summary as $r) {
            if ($r['month'] === '2026-05') {
                $row = $r;
            }
        }

        self::assertNotNull($row);
        self::assertSame(1, $row['expense_count']);
        self::assertSame(1000.0, $row['total_expense_amount']);
        self::assertSame(100.0, $row['total_tds_amount']);
        self::assertSame('Q1 FY2026-27', $row['quarter']); // May falls in Apr-Jun => Q1 of FY2026-27
    }

    public function testTdsSummaryExcludesExpensesNotMarkedTdsApplicable(): void
    {
        $id = CaExpenseRepository::insert('ZOHO-TEST-2', 'Office', 'Not TDS', null, 500.00, 'INR', '2026-06-01');
        // Deliberately never call setTds() — is_tds_applicable defaults to 0.

        $summary = CaExpenseRepository::tdsSummary();
        foreach ($summary as $r) {
            self::assertNotEquals('2026-06', $r['month'], 'a non-TDS expense must not appear in the TDS summary');
        }
    }

    public function testFyReadinessIsNotReadyWhenALegIsMissingInrActual(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $pdo = \App\Config\Database::connection();
        $pdo->prepare(
            "INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at)
             VALUES (:order_id, 500.00, NOW())"
        )->execute(['order_id' => $orderId]);
        // advance_inr_actual deliberately left NULL.

        $fy = \App\Helpers\FinancialYear::current();
        $readiness = CaRepository::fyReadiness($fy);

        self::assertFalse($readiness['isReady']);
        self::assertGreaterThan(0, $readiness['revenue']['legsMissingInr']);
    }
}
