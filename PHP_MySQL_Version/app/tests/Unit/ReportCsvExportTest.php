<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controllers\ReportController;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog "CSV export for the 6 non-CA reports": Trends, Staff
 * Productivity, Supplier Performance, Conversion Rate, Sales Performance,
 * and the per-Order report were the 6 reports in the non-CA Reports
 * module with no CSV export at all. Drives the extracted *CsvRows() pure
 * functions directly, rather than the controller methods themselves,
 * since Csv::stream() calls exit() on its success path — same reasoning
 * as CsvFormulaInjectionTest driving Csv::neutralizeFormula() directly.
 */
final class ReportCsvExportTest extends TestCase
{
    public function testTrendsCsvRowsFormatsMultiCurrencyFobAndZeroMonths(): void
    {
        $months = [
            ['month' => '2026-09', 'orders_created' => 3, 'quotations_sent' => 2, 'pi_sent' => 1, 'lost' => 0, 'fob_by_currency' => ['USD' => 1234.5, 'EUR' => 500.0]],
            ['month' => '2026-10', 'orders_created' => 0, 'quotations_sent' => 0, 'pi_sent' => 0, 'lost' => 0, 'fob_by_currency' => []],
        ];

        $result = ReportController::trendsCsvRows($months);

        self::assertSame(['Month', 'Orders Created', 'Quotations Sent', 'PI Sent', 'Lost', 'Total FOB Value'], $result['headers']);
        self::assertSame('USD 1,234.50; EUR 500.00', $result['rows'][0]['Total FOB Value']);
        self::assertSame('—', $result['rows'][1]['Total FOB Value'], 'a month with no activity must still render, not disappear');
    }

    public function testStaffCsvRowsFormatsPerTypeBreakdown(): void
    {
        $rows = [
            ['user_name' => 'Jane Staff', 'by_type' => ['QT' => 3, 'PI' => 1], 'documents_total' => 4, 'audit_actions' => 12],
            ['user_name' => 'No Activity User', 'by_type' => [], 'documents_total' => 0, 'audit_actions' => 0],
        ];

        $result = ReportController::staffCsvRows($rows);

        self::assertSame('QT: 3; PI: 1', $result['rows'][0]['Documents Generated (by type)']);
        self::assertSame('—', $result['rows'][1]['Documents Generated (by type)']);
    }

    public function testSuppliersCsvRowsHandlesNullAveragesAndPercentages(): void
    {
        $rows = [
            ['supplier_name' => 'Acme Granite', 'po_count' => 5, 'signed_count' => 5, 'avg_signing_days' => 2.4, 'delivery_tracked_count' => 3, 'on_time_pct' => 66.7],
            ['supplier_name' => 'No Signings Yet', 'po_count' => 1, 'signed_count' => 0, 'avg_signing_days' => null, 'delivery_tracked_count' => 0, 'on_time_pct' => null],
        ];

        $result = ReportController::suppliersCsvRows($rows);

        self::assertSame('66.7%', $result['rows'][0]['On-Time %']);
        self::assertSame('—', $result['rows'][1]['Avg Signing Days']);
        self::assertSame('—', $result['rows'][1]['On-Time %']);
    }

    public function testConversionCsvRowsIsAMetricValueTable(): void
    {
        $conversion = [
            'quotations_sent' => 20,
            'quotation_to_pi_pct' => 50.0,
            'quotation_lost_pct' => 10.0,
            'pi_sent' => 10,
            'pi_to_confirmed_pct' => 80.0,
            'pi_lost_pct' => 5.0,
            'overall_quotation_to_confirmed_pct' => 40.0,
        ];

        $result = ReportController::conversionCsvRows($conversion);

        self::assertSame(['Metric', 'Value'], $result['headers']);
        self::assertCount(7, $result['rows']);
        self::assertSame('Quotations sent', $result['rows'][0]['Metric']);
        self::assertSame(20, $result['rows'][0]['Value']);
        self::assertSame('50%', $result['rows'][1]['Value']);
    }

    public function testPerformanceCsvRowsExportsLostOrdersDetail(): void
    {
        $report = ['lost_orders' => [
            ['order_reference' => 'SC/ORD/2026/001-1', 'company_legal_name' => 'Lost Buyer Ltd', 'lost_at' => '2026-10-01 10:00:00', 'reached_pi' => true, 'lost_reason' => 'Price too high'],
            ['order_reference' => 'SC/ORD/2026/002-1', 'company_legal_name' => 'Another Lost Buyer', 'lost_at' => '2026-10-05 10:00:00', 'reached_pi' => false, 'lost_reason' => null],
        ]];

        $result = ReportController::performanceCsvRows($report);

        self::assertSame('PI', $result['rows'][0]['Stage Reached']);
        self::assertSame('Price too high', $result['rows'][0]['Reason']);
        self::assertSame('Quotation', $result['rows'][1]['Stage Reached']);
        self::assertSame('—', $result['rows'][1]['Reason'], 'a lost order with no reason recorded must still render, not disappear');
    }

    public function testOrderAuditCsvRowsFormatsOldToNewAndFallsBackForSystemActions(): void
    {
        $audit = [
            ['created_at' => '2026-10-01 09:00:00', 'user_name' => 'Jane Staff', 'action_type' => 'FIELD_EDIT', 'field_name' => 'incoterm_id', 'old_value' => '1', 'new_value' => '2', 'reason' => 'Buyer changed terms'],
            ['created_at' => '2026-10-01 09:05:00', 'user_name' => null, 'action_type' => 'DOCUMENT_GENERATED', 'field_name' => null, 'old_value' => null, 'new_value' => null, 'reason' => null],
        ];

        $result = ReportController::orderAuditCsvRows($audit);

        self::assertSame('1 -> 2', $result['rows'][0]['Old -> New']);
        self::assertSame('System', $result['rows'][1]['User'], 'a system-generated audit row with no user must fall back to "System", same as the on-page table');
        self::assertSame('—', $result['rows'][1]['Old -> New']);
    }
}
