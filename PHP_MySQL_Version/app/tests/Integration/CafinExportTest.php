<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\FinancialYear;
use App\Services\CafinExportService;
use App\Services\CaFinancialSnapshotService;
use App\Services\OrderProfitabilityService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request 2026-10-30: CAFIN as CSV for every order of a financial
 * year — summary (one row per order) and detail (every CAFIN line), from
 * the same calculation as the CAFIN PDF; test orders never included. The
 * Node suite has the same test (cafinExport.test.js).
 */
final class CafinExportTest extends DbTestCase
{
    private function order(string $status = 'active'): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 1800, advance_remittance_received_at = '2026-10-09 10:00:00', advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = 150000,
            balance_amount = 2700, balance_remittance_received_at = '2026-10-24 10:00:00', balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000 WHERE order_id = :o")->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE orders SET status = :s WHERE id = :o')->execute(['s' => $status, 'o' => $orderId]);
        return $orderId;
    }

    public function testTheSummaryHasOneRowPerOrderWithTheSameFiguresAsCafin(): void
    {
        $orderId = $this->order();
        $ids = CafinExportService::orderIds(FinancialYear::current(), 'all');
        self::assertContains($orderId, $ids);

        $row = array_combine(CafinExportService::SUMMARY_HEADERS, CafinExportService::summaryRows([$orderId])[0]);
        $p = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(['4500.00', '400000.00', 'No'], [$row['FOB value'], $row['Revenue (INR)'], $row['Revenue estimated']]);
        self::assertSame(number_format($p['profit_inr'], 2, '.', ''), $row['Order profit (INR)']);
        self::assertSame(number_format($p['total_cost_inr'], 2, '.', ''), $row['Total cost (INR)']);
        self::assertSame('400000.00', $row['Cash: received from buyer (INR)']);

        $snapshot = CaFinancialSnapshotService::build($orderId);
        $profitLine = array_values(array_filter($snapshot['pl'], static fn (array $l): bool => $l[1] === 'ORDER PROFIT'))[0];
        self::assertSame(str_replace(',', '', $profitLine[3]), $row['Order profit (INR)'], 'the summary agrees with the CAFIN PDF');
    }

    public function testTheDetailHasEveryCafinSectionAsPlainNumbers(): void
    {
        $orderId = $this->order();
        $rows = CafinExportService::detailRows([$orderId]);
        $sections = array_values(array_unique(array_column($rows, 2)));
        foreach (['A. Order details', 'A. Products', 'A. Money received from the buyer', 'C1. Freight & insurance', 'C. Expenses summary', 'E. Profit', 'E. Cash position so far', 'F. Open items for the CA'] as $section) {
            self::assertContains($section, $sections);
        }
        $receiptTotal = array_values(array_filter($rows, static fn (array $r): bool => $r[2] === 'A. Money received from the buyer' && $r[3] === 'TOTAL'))[0];
        self::assertSame(['4500.00', 'USD', '400000.00'], [$receiptTotal[6], $receiptTotal[7], $receiptTotal[8]]);
    }

    public function testTheStatusFilterAndTestOrdersLeftOut(): void
    {
        $settled = $this->order('complete');
        $lost = $this->order('lost');
        $test = $this->order();
        Database::connection()->prepare('UPDATE orders SET is_test_data = 1 WHERE id = :o')->execute(['o' => $test]);
        $fy = FinancialYear::current();
        self::assertContains($settled, CafinExportService::orderIds($fy, 'complete'));
        self::assertNotContains($lost, CafinExportService::orderIds($fy, 'complete'));
        self::assertContains($lost, CafinExportService::orderIds($fy, 'lost'));
        try {
            self::assertNotContains($test, CafinExportService::orderIds($fy, 'all'), 'test orders are never exported');
        } finally {
            // Leftover test data would keep Test Mode from being switched off in later tests.
            Database::connection()->prepare('UPDATE orders SET is_test_data = 0 WHERE id = :o')->execute(['o' => $test]);
        }
        self::assertNotContains($settled, CafinExportService::orderIds('2020-21', 'all'));
    }

    public function testThePageOpensForWhoeverMaySeeCafinOnly(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Accounts Executive');
        ob_start();
        (new \App\Controllers\CaController())->cafinExport([]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Internal only.', $html);
        self::assertStringContainsString('Download summary (one row per order)', $html);
        $_SESSION = [];

        $pdo = Database::connection();
        $role = static fn (string $name): int => (int) $pdo->query('SELECT id FROM roles WHERE name = ' . $pdo->quote($name))->fetchColumn();
        self::assertTrue(PermissionService::can($this->createTestUser('CA / Chartered Accountant'), $role('CA / Chartered Accountant'), 'view_cafin'));
        self::assertFalse(PermissionService::can($this->createTestUser('Export Executive'), $role('Export Executive'), 'view_cafin'));
        self::assertFalse(PermissionService::can($this->createTestUser('Logistics Executive'), $role('Logistics Executive'), 'view_cafin'));
    }
}
