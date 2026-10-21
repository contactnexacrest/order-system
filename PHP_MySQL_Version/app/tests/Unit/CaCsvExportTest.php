<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controllers\CaController;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog item #13: the CA module had no CSV export anywhere —
 * the only CSV-handling code there was for IMPORTING a bank statement, not
 * exporting any of the CA screens. Drives CaController's extracted
 * *CsvRows() pure functions directly, rather than the controller actions
 * themselves, since Csv::stream() calls exit() on its success path — same
 * reasoning as ReportCsvExportTest.
 */
final class CaCsvExportTest extends TestCase
{
    public function testSettlementRegisterCsvRowsMatchesOnScreenColumns(): void
    {
        $legRows = [
            ['buyer_inquiry_ref' => 'NC/2026/0042', 'company_legal_name' => 'Acme Importers Ltd', 'leg' => 'advance', 'cleared_at' => '2026-10-05', 'foreign_amount' => 1000.0, 'currency_code' => 'USD', 'inr_actual' => 83250.0, 'forex_gain_loss' => 250.0],
        ];

        $result = CaController::settlementRegisterCsvRows($legRows);

        self::assertSame(['Order Ref', 'Client', 'Leg', 'Cleared On', 'Foreign Amount', 'Currency', 'INR Actual', 'Forex Gain/(Loss)'], $result['headers']);
        self::assertSame('Advance', $result['rows'][0]['Leg'], 'leg must be rendered ucfirst, same as the on-screen table');
        self::assertSame('NC/2026/0042', $result['rows'][0]['Order Ref']);
        self::assertSame(250.0, $result['rows'][0]['Forex Gain/(Loss)']);
    }

    public function testExpensesCsvRowsFallsBackForUnlinkedExpenseAndNoTds(): void
    {
        $expenses = [
            ['expense_date' => '2026-09-15', 'category' => 'Freight', 'vendor_name' => 'XYZ Logistics', 'description' => 'Inland haulage', 'amount' => 15000.0, 'currency_code' => 'INR', 'order_id' => 7, 'order_reference' => 'NC/2026/0007', 'is_tds_applicable' => true, 'tds_amount' => 300.0],
            ['expense_date' => '2026-09-20', 'category' => 'Office', 'vendor_name' => null, 'description' => null, 'amount' => 500.0, 'currency_code' => 'INR', 'order_id' => null, 'order_reference' => null, 'is_tds_applicable' => false, 'tds_amount' => null],
        ];

        $result = CaController::expensesCsvRows($expenses);

        self::assertSame(['Date', 'Category', 'Vendor', 'Description', 'Amount', 'Currency', 'Order Ref', 'TDS Applicable', 'TDS Amount'], $result['headers']);
        self::assertSame('Yes', $result['rows'][0]['TDS Applicable']);
        self::assertSame(300.0, $result['rows'][0]['TDS Amount']);
        self::assertSame('NC/2026/0007', $result['rows'][0]['Order Ref']);
        self::assertSame('No', $result['rows'][1]['TDS Applicable'], 'an expense with TDS not applicable must still render, not disappear');
        self::assertSame('', $result['rows'][1]['Vendor'], 'an expense with no vendor must fall back to blank, not null/warning');
        self::assertSame('', $result['rows'][1]['Order Ref'], 'an expense not linked to any order must fall back to blank');
    }

    public function testExportBenefitsCsvRowsReflectsClaimedVsReceivedStatus(): void
    {
        $benefits = [
            ['claimed_at' => '2026-08-01', 'scheme_name' => 'RODTEP', 'order_id' => 3, 'order_reference' => 'NC/2026/0003', 'reference_number' => 'SB-2026-00417', 'claimed_amount' => 15000.0, 'currency_code' => 'INR', 'received_amount' => 14800.0, 'received_at' => '2026-09-10'],
            ['claimed_at' => '2026-10-01', 'scheme_name' => 'RODTEP', 'order_id' => null, 'order_reference' => null, 'reference_number' => null, 'claimed_amount' => 5000.0, 'currency_code' => 'INR', 'received_amount' => null, 'received_at' => null],
        ];

        $result = CaController::exportBenefitsCsvRows($benefits);

        self::assertSame(['Claimed On', 'Scheme', 'Order Ref', 'Reference', 'Claimed Amount', 'Currency', 'Received Amount', 'Received On', 'Status'], $result['headers']);
        self::assertSame('Received', $result['rows'][0]['Status']);
        self::assertSame(14800.0, $result['rows'][0]['Received Amount']);
        self::assertSame('Claimed', $result['rows'][1]['Status'], 'a claim not yet received must show Claimed, not disappear');
        self::assertSame('', $result['rows'][1]['Received Amount']);
        self::assertSame('', $result['rows'][1]['Order Ref'], 'a claim not tied to any order must fall back to blank');
    }
}
