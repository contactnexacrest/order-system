<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Services\CafinExportService;
use App\Services\CaFinancialSnapshotService;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2 Nov 2026 (D-236, confirmed twice): in the CA annexure's
 * "Money received from the buyer", a remittance the banks took charges from
 * shows what the buyer paid, what actually reached our bank and the charges
 * beside it; the receipts INR total is what reached the bank. An INR actual is
 * already the credited figure. Receipts with no charges print exactly as
 * before. Node twin: cafinReceiptsBankCharges.test.js.
 */
final class CafinReceiptsBankChargesTest extends DbTestCase
{
    private function orderWithAdvance(float $charges, ?float $inrActual = null): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 1800, advance_exchange_rate = 84,
                advance_remittance_received_at = :d, advance_inr_actual = :a WHERE order_id = :o')
            ->execute(['d' => '2026-11-02 10:00:00', 'a' => $inrActual, 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, bank_charges, bank_charges_inr)
                VALUES (:o, 'advance', :a, '2026-11-02', 84, 'UTR-CAFIN-BC', :c, :ci)")
            ->execute(['o' => $orderId, 'a' => 1800 - $charges, 'c' => $charges > 0 ? $charges : null, 'ci' => $charges > 0 ? $charges * 84 : null]);
        return $orderId;
    }

    private function advanceRow(array $s): array
    {
        $rows = array_values(array_filter($s['receipts'], static fn (array $r): bool => $r['leg'] === 'Advance'));
        self::assertCount(1, $rows);
        return $rows[0];
    }

    public function testChargesShowCreditedRupeesWithTheChargesBeside(): void
    {
        $s = CaFinancialSnapshotService::build($this->orderWithAdvance(20.0));
        $r = $this->advanceRow($s);

        self::assertSame('1,800.00', $r['amount']);
        self::assertSame('1,800.00 (received 1,780.00 + bank charges 20.00)', $r['amount_display']);
        self::assertSame('84.0000', $r['rate']);
        self::assertSame('149,520.00', $r['inr']);
        self::assertSame('149,520.00 credited · bank charges ₹1,680.00', $r['inr_display']);
        self::assertSame('149,520.00', $s['receipts_inr'], 'the receipts total is what reached the bank');
        self::assertSame('1,800.00', $s['receipts_foreign']);
    }

    public function testAnInrActualIsAlreadyTheCreditedAmount(): void
    {
        $s = CaFinancialSnapshotService::build($this->orderWithAdvance(20.0, 149000.0));
        $r = $this->advanceRow($s);

        self::assertSame('149,000.00', $r['inr'], 'not reduced a second time by the charges');
        self::assertSame('149,000.00 credited · bank charges ₹1,680.00', $r['inr_display']);
        self::assertSame('INR actual', $r['basis']);
    }

    public function testNoChargesPrintsExactlyAsBefore(): void
    {
        $s = CaFinancialSnapshotService::build($this->orderWithAdvance(0.0));
        $r = $this->advanceRow($s);

        self::assertSame(['leg', 'amount', 'rate', 'inr', 'received', 'cleared', 'basis'], array_keys($r));
        self::assertSame('1,800.00', $r['amount']);
        self::assertSame('151,200.00', $r['inr']);
        self::assertSame('at recorded rate', $r['basis']);
    }

    public function testTheExcelExportKeepsNumbersAndExplainsTheChargesInTheNote(): void
    {
        $orderId = $this->orderWithAdvance(20.0);
        $rows = array_values(array_filter(CafinExportService::detailRows([$orderId]),
            static fn (array $r): bool => $r[2] === 'A. Money received from the buyer' && $r[3] === 'Advance'));
        self::assertCount(1, $rows);
        self::assertSame('1800.00', $rows[0][6]);
        self::assertSame('149520.00', $rows[0][8]);
        self::assertSame('at recorded rate · credited after bank charges ' . $rows[0][7] . ' 20.00 (₹1,680.00) — received 1,780.00', $rows[0][9]);
    }

    public function testTheGeneratedPdfShowsTheCreditedLine(): void
    {
        $orderId = $this->orderWithAdvance(20.0);
        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $this->createTestUser('Admin'));
        $path = FileStoreRepository::find((int) $result['pdf_file_id'])['server_path'];
        $text = (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg($path) . ' -'));
        @unlink($path);

        // Table cells wrap in the PDF text, so check each cell's pieces.
        self::assertStringContainsString('1,800.00 (received 1,780.00 + bank', $text);
        self::assertStringContainsString('149,520.00 credited · bank charges', $text);
        self::assertStringContainsString('charges ₹1,680.00', $text);
        self::assertStringContainsString('charges 20.00)', $text);
    }
}
