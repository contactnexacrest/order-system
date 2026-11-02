<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\AmountInWords;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-224 (2 Nov 2026, D1–D5, confirmed twice), PDF and Word:
 * D1 Order Confirmation prints the buyer's PO number (only when entered);
 * D2 the Debit Note's "2. TO (BUYER)" is the full buyer block;
 * D3 the BL Instruction gives the CHA the instruction only (no payment
 *    terms, no grey notes, one release line);
 * D4 the Commercial Invoice no longer mentions an IGST refund (LUT export);
 * D5 the Commercial Invoice prints its total in words.
 * Node twin: documentWordingD224.test.js.
 */
final class DocumentWordingD224Test extends DbTestCase
{
    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        return html_entity_decode((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]+>/', ' ', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function order(?string $buyersPo = 'PO-2026-0457'): int
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD');
        OrderProductRepository::add($orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '1250.05');
        $pdo = Database::connection();
        $pdo->prepare(
            "UPDATE orders SET buyers_po_ref = :po, billing_address_line1 = '7 Harbour Road', billing_address_line2 = 'Unit 4',
                    billing_city = 'Poti', billing_postcode = '4400', country_of_destination = 'Georgia',
                    vat_eori_tax_no = 'GE-VAT-778899', contact_person = 'Nino Buyer' WHERE id = :id"
        )->execute(['po' => $buyersPo, 'id' => $orderId]);
        $pdo->prepare("UPDATE orders SET client_phone = '+995 555 123 456', client_email = :email WHERE id = :id")->execute(['email' => 'nino-' . $orderId . '@buyer.test', 'id' => $orderId]);
        return $orderId;
    }

    /** @return array{pdf:string,docx:string} */
    private function generate(int $orderId, string $code): array
    {
        $r = DocumentGenerationService::generate($orderId, $code, $this->createTestUser('Admin'), null, true, true);
        return ['pdf' => $this->pdfText((int) $r['pdf_file_id']), 'docx' => $this->docxText((int) $r['docx_file_id'])];
    }

    public function testD1OrderConfirmationShowsTheBuyersPoOnlyWhenEntered(): void
    {
        foreach ($this->generate($this->order(), 'OC') as $format => $text) {
            self::assertSame(1, substr_count($text, "Buyer's PO / Ref No."), "OC {$format}");
            self::assertStringContainsString('PO-2026-0457', $text, "OC {$format}");
            self::assertLessThan(strpos($text, "Buyer's PO / Ref No."), strpos($text, 'Proforma Invoice No.'), "OC {$format}: after the PI No.");
        }
        foreach ($this->generate($this->order('NIL'), 'OC') as $format => $text) {
            self::assertStringNotContainsString("Buyer's PO / Ref No.", $text, "OC {$format}: no row without a PO");
        }
    }

    public function testD2DebitNoteHasTheFullBuyerBlock(): void
    {
        foreach ($this->generate($this->order(), 'FDN') as $format => $text) {
            self::assertStringContainsString('2. TO (BUYER)', $text, "FDN {$format}");
            foreach (['Billing Address Line 1', '7 Harbour Road', 'Unit 4', 'City / Town', 'Poti', '4400', 'Georgia',
                      'VAT / EORI / Tax Reg. No.', 'GE-VAT-778899', 'Nino Buyer', 'Phone', '+995 555 123 456', '@buyer.test'] as $expected) {
                self::assertStringContainsString($expected, $text, "FDN {$format}: {$expected}");
            }
        }
    }

    public function testD3BlInstructionGivesTheChaTheInstructionOnly(): void
    {
        foreach ($this->generate($this->order(), 'BLI') as $format => $text) {
            self::assertStringContainsString('MANDATORY INSTRUCTION — DO NOT ISSUE SEA WAYBILL OR EXPRESS BL', $text, "BLI {$format}");
            self::assertStringContainsString('ORIGINAL NEGOTIABLE BILL OF LADING — 3 ORIGINALS', $text, "BLI {$format}");
            self::assertStringContainsString('Original BL release: CHA to hand all 3 originals to NexaCrest only — never to the buyer or anyone else.', $text, "BLI {$format}");
            foreach (['Reason:', 'payment terms are', 'NexaCrest will hold all 3 originals', 'released freely', 'Original BL release process',
                      'balance T/T', 'ensures the BL is to NexaCrest'] as $gone) {
                self::assertStringNotContainsString($gone, $text, "BLI {$format}: '{$gone}' removed");
            }
        }
    }

    public function testD4AndD5CommercialInvoiceWordingAndAmountInWords(): void
    {
        foreach ($this->generate($this->order(), 'CI') as $format => $text) {
            self::assertStringNotContainsString('IGST refund', $text, "CI {$format}");
            self::assertStringContainsString('FOB Value is the basis for all payments and is the value declared on the Indian Shipping Bill', $text, "CI {$format}");
            self::assertStringContainsString('the value declared on the Indian Shipping Bill', $text, "CI {$format}");
            self::assertStringContainsString('US Dollars Twelve Thousand Five Hundred and Fifty Cents Only', $text, "CI {$format}: total in words");
            self::assertStringNotContainsString('_______________', $text, "CI {$format}: no blank line");
        }
    }

    public function testAmountInWords(): void
    {
        $cases = [
            ['0.50', 'US Dollars Zero and Fifty Cents Only'],
            ['1', 'US Dollars One Only'],
            ['12', 'US Dollars Twelve Only'],
            ['100', 'US Dollars One Hundred Only'],
            ['1005', 'US Dollars One Thousand Five Only'],
            ['12500.50', 'US Dollars Twelve Thousand Five Hundred and Fifty Cents Only'],
            ['1000000', 'US Dollars One Million Only'],
        ];
        foreach ($cases as [$amount, $expected]) {
            self::assertSame($expected, AmountInWords::format($amount, 'USD'), $amount);
        }
        self::assertSame('Euros Nine Hundred Ninety-Nine and One Cents Only', AmountInWords::format('999.01', 'EUR'));
        self::assertSame('Pounds Sterling Twenty-One and Five Pence Only', AmountInWords::format('21.05', 'GBP'));
        self::assertSame('UAE Dirhams Seventy Only', AmountInWords::format('70', 'AED'));
        self::assertSame('Indian Rupees Two Thousand and Ten Paise Only', AmountInWords::format('2000.10', 'INR'));
        self::assertSame('CAD Five Only', AmountInWords::format('5', 'CAD'));
    }
}
