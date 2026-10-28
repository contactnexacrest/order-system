<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Scenario run of the PI / CI payment wording (owner decisions 2026-10-28,
 * PaymentTermsWordingTest) across every payment preset, every incoterm and
 * every currency, in the PDF and the Word file alike: the advance box
 * amounts, the balance line following the order's own terms, the freight
 * note, the bank-charges sentence and Calendar Days.
 *
 * Not asserted here, reported to the owner instead (a document change needs
 * their double confirmation): the Letter of Credit preset (0% advance)
 * prints "ADVANCE PAYABLE NOW: … 0.00" and the cleared-funds sentence, and
 * the CFR box says "Freight are paid separately".
 * The Node suite has the same test (paymentTermsScenarios.test.js).
 */
final class PaymentTermsScenariosTest extends DbTestCase
{
    private const BEFORE_SHIPMENT = 'Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.';
    private const AGAINST_BL = 'Payable against scanned copy of Bill of Lading, within 7 Calendar Days of the date NexaCrest emails the scanned BL copy.';
    private const BANK = "All bank charges outside India are for the Buyer's account — please remit the full amount (charges option \"OUR\").";

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}> preset, incoterm, currency, advance %, advance, balance, balance terms */
    public static function scenarios(): array
    {
        $presets = [
            ['Standard — New Buyer', '40', '1,800.00', '2,700.00', self::BEFORE_SHIPMENT],
            ['Established Buyer — Post-BL', '40', '1,800.00', '2,700.00', self::AGAINST_BL],
            ['Extended Terms — Before Shipment', '30', '1,350.00', '3,150.00', self::BEFORE_SHIPMENT],
            ['Extended Terms — Post-BL', '30', '1,350.00', '3,150.00', self::AGAINST_BL],
            ['Irrevocable Letter of Credit at Sight', '0', '0.00', '4,500.00', self::AGAINST_BL],
        ];
        $currencies = ['USD', 'EUR', 'GBP'];
        $rows = [];
        $i = 0;
        foreach ($presets as [$preset, $pct, $advance, $balance, $terms]) {
            foreach (['FOB', 'CFR', 'CIF'] as $incoterm) {
                $currency = $currencies[$i++ % 3];
                $rows["{$preset} / {$incoterm} / {$currency}"] = [$preset, $incoterm, $currency, $pct, $advance, $balance, $terms];
            }
        }
        return $rows;
    }

    /** @return array{0: string, 1: string} the PDF text and the Word text, whitespace-flattened */
    private function generate(int $orderId, string $type): array
    {
        $result = DocumentGenerationService::generate($orderId, $type, $this->createTestUser('Admin'), null, true, true);
        $pdf = (string) shell_exec('pdftotext ' . escapeshellarg((string) FileStoreRepository::find((int) $result['pdf_file_id'])['server_path']) . ' -');
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) FileStoreRepository::find((int) $result['docx_file_id'])['server_path']) . ' word/document.xml');
        $flat = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'))));
        return [$flat($pdf), $flat((string) preg_replace('/<[^>]+>/', '', str_replace(['<w:br/>', '</w:p>'], ' ', $xml)))];
    }

    #[DataProvider('scenarios')]
    public function testThePiAndCiPaymentWordingFollowTheOrder(string $preset, string $incoterm, string $currency, string $pct, string $advance, string $balance, string $terms): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm, $preset, $currency);
        OrderProductRepository::add($orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '450.00');
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '950.00', 'insurance_amount' => '120.00']);

        $financial = DocumentDataAssembler::assemble($orderId, 'PI')['financial'];
        self::assertSame($advance, $financial['advance_amount']);
        self::assertSame($balance, $financial['balance_amount']);
        self::assertSame($terms, $financial['balance_terms_text']);
        self::assertSame(self::BANK, $financial['bank_charges_text']);

        [$piPdf, $piWord] = $this->generate($orderId, 'PI');
        foreach (['PI PDF' => $piPdf, 'PI Word' => $piWord] as $kind => $text) {
            if ($pct !== '0') {
                self::assertStringContainsString("ADVANCE PAYABLE NOW: {$currency} {$advance} ({$pct}% of FOB value) — due by", $text, $kind);
                self::assertStringContainsString('only once the advance is received in our account as cleared funds.', $text, $kind);
            }
            self::assertStringContainsString("Balance {$currency} {$balance} — not payable now. {$terms}", $text, $kind);
            self::assertStringContainsString('Bank charges: ' . self::BANK, $text, $kind);
            if ($incoterm === 'CIF') {
                self::assertStringContainsString('Freight & insurance are paid separately against our Debit Note.', $text, $kind);
            } elseif ($incoterm === 'CFR') {
                self::assertStringContainsString('paid separately against our Debit Note.', $text, $kind);
                self::assertStringNotContainsString('Freight & insurance are paid separately', $text, $kind . ': no insurance on CFR');
            } else {
                self::assertStringNotContainsString('paid separately against our Debit Note', $text, $kind . ': no Debit Note on FOB');
            }
        }

        [$ciPdf, $ciWord] = $this->generate($orderId, 'CI');
        foreach (['CI PDF' => $ciPdf, 'CI Word' => $ciWord] as $kind => $text) {
            self::assertStringContainsString('Bank Charges ' . self::BANK, $text, $kind);
            self::assertStringContainsString($terms, $text, $kind);
            self::assertStringContainsString("{$currency} {$balance}", $text, $kind);
        }
        foreach (['PI Word' => $piWord, 'CI Word' => $ciWord] as $kind => $text) {
            self::assertStringNotContainsString('3 working days', $text, $kind . ': buyer deadlines read Calendar Days');
        }
    }
}
