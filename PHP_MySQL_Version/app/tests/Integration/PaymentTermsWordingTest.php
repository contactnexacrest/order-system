<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions (2026-10-28), confirmed twice on before/after previews:
 *  - the PI says exactly what to pay now — an ADVANCE PAYABLE NOW box with
 *    the advance, its due date, the balance "not payable now" and who bears
 *    the bank charges — and that production starts only on cleared funds;
 *  - the CI's Bank Details end with the same bank-charges sentence;
 *  - the Word CI's balance line follows the order's own terms (it always
 *    said "within N days of BL date", even on before-shipment orders);
 *  - every buyer payment deadline in the Word QT/PI/OC/FDN reads Calendar
 *    Days, like the PDFs, and the FDN "by [date]" is 3 calendar days on.
 * The Node suite has the same test (paymentTermsWording.test.js).
 */
final class PaymentTermsWordingTest extends DbTestCase
{
    private function order(string $incoterm = 'CIF', string $preset = 'Standard — New Buyer'): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm, $preset);
        OrderProductRepository::add($orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '450.00');
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '950.00', 'insurance_amount' => '120.00']);
        return $orderId;
    }

    /** @return array{0: string, 1: string} the PDF text and the Word text, whitespace-flattened */
    private function generate(int $orderId, string $type): array
    {
        $result = DocumentGenerationService::generate($orderId, $type, $this->createTestUser('Admin'), null, true, true);
        $pdf = (string) shell_exec('pdftotext ' . escapeshellarg((string) FileStoreRepository::find((int) $result['pdf_file_id'])['server_path']) . ' -');
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) FileStoreRepository::find((int) $result['docx_file_id'])['server_path']) . ' word/document.xml');
        $flat = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8')));
        return [$flat($pdf), $flat((string) preg_replace('/<[^>]+>/', '', str_replace(['<w:br/>', '</w:p>'], ' ', $xml)))];
    }

    public function testThePiAndCiCarryOneAndTheSameBankChargesSentence(): void
    {
        $orderId = $this->order();
        $pi = DocumentDataAssembler::assemble($orderId, 'PI')['financial'];
        $ci = DocumentDataAssembler::assemble($orderId, 'CI')['financial'];

        self::assertSame("All bank charges outside India are for the Buyer's account — please remit the full amount (charges option \"OUR\").", DocumentDataAssembler::BANK_CHARGES_SENTENCE);
        self::assertSame(DocumentDataAssembler::BANK_CHARGES_SENTENCE, $pi['bank_charges_text']);
        self::assertSame($pi['bank_charges_text'], $ci['bank_charges_text']);
    }

    public function testThePiSaysWhatToPayNowAndWhenProductionStarts(): void
    {
        [$pdf, $word] = $this->generate($this->order(), 'PI');

        foreach (['PDF' => $pdf, 'Word' => $word] as $kind => $text) {
            self::assertStringContainsString('ADVANCE PAYABLE NOW', $text, $kind);
            self::assertStringContainsString('(40% of FOB value) — due by', $text, $kind);
            self::assertStringContainsString('— not payable now. Payable before shipment — within 3 Calendar Days', $text, $kind);
            self::assertStringContainsString('Bank charges: All bank charges outside India are for the Buyer', $text, $kind);
            self::assertStringContainsString('only once the advance is received in our account as cleared funds.', $text, $kind);
        }
        self::assertStringNotContainsString('3 working days', $word, 'Word buyer payment deadlines read Calendar Days');
    }

    public function testTheCiBalanceFollowsTheOrdersOwnTermsAndEndsWithBankCharges(): void
    {
        [$pdf, $word] = $this->generate($this->order(), 'CI');
        foreach (['PDF' => $pdf, 'Word' => $word] as $kind => $text) {
            self::assertStringContainsString("Bank Charges All bank charges outside India are for the Buyer's account", $text, $kind);
            self::assertStringContainsString('Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation', $text, $kind);
        }
        self::assertStringNotContainsString('days of BL date', $word, 'a before-shipment order never says the balance is due against the BL');

        [, $word] = $this->generate($this->order('CIF', 'Established Buyer — Post-BL'), 'CI');
        self::assertStringContainsString('Payable against scanned copy of Bill of Lading, within 7 Calendar Days of the date NexaCrest emails the scanned BL copy.', $word);
    }

    public function testWordQuotationConfirmationAndDebitNoteCountBuyerPaymentsInCalendarDays(): void
    {
        $orderId = $this->order();
        foreach (['QT' => 'Payment required within 3 Calendar Days of Freight Debit Note date.', 'OC' => 'Payment required within 3 Calendar Days of the Debit Note date.', 'FDN' => 'Within 3 Calendar Days of this Debit Note date — by'] as $type => $expected) {
            [, $word] = $this->generate($orderId, $type);
            self::assertStringContainsString($expected, $word, $type);
            self::assertStringNotContainsString('3 working days', $word, $type);
        }
    }

    public function testTheDebitNoteDueDateIsThreeCalendarDaysOn(): void
    {
        $fdn = DocumentDataAssembler::assemble($this->order(), 'FDN');
        self::assertSame(
            DocumentDataAssembler::formatDate((new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d')),
            $fdn['freight']['payment_due_date']
        );
    }
}
