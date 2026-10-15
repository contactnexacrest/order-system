<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #11: DocumentDataAssembler::resolveConsignee() already
 * computed all 7 consignee fields correctly, but only 2 of them
 * (consignee_name/consignee_address) actually routed through it on
 * generated documents — the other 5 (vat_eori_tax_no, contact_person,
 * phone, email, country_of_destination) read the BUYER's own raw columns
 * regardless of consignee_same_as_buyer, on every document type that
 * labels these as "Consignee" fields: BUYERPO, BLI, and COOPREP (DocxDocumentBuilder
 * + the corresponding Twig/PDF templates). So when a client has a genuinely
 * independent consignee, these documents still printed the BUYER's VAT/
 * contact/phone/email/country under the "Consignee" heading.
 */
final class BuyerPoConsigneeFieldsTest extends DbTestCase
{
    private function extractPdfText(int $pdfFileId): string
    {
        $file = FileStoreRepository::find($pdfFileId);
        self::assertNotNull($file, 'generated PDF file_store row must exist');
        $process = proc_open(
            ['pdftotext', $file['server_path'], '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $text = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return (string) $text;
    }

    private function extractDocxText(int $docxFileId): string
    {
        $file = FileStoreRepository::find($docxFileId);
        self::assertNotNull($file, 'generated DOCX file_store row must exist');
        $xml = shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        self::assertNotNull($xml, 'could not read word/document.xml out of the generated docx');
        return (string) preg_replace('/<[^>]+>/', ' ', $xml);
    }

    private function createIndependentConsigneeClient(): int
    {
        $clientId = $this->createTestClient();
        Database::connection()->prepare(
            'UPDATE clients SET
                consignee_same_as_buyer = 0,
                consignee_name = :name,
                consignee_address_line1 = :addr1,
                consignee_city = :city,
                consignee_country = :country,
                consignee_vat_eori_tax_no = :vat,
                consignee_contact_person = :contact,
                consignee_phone = :phone,
                consignee_email = :email
             WHERE id = :id'
        )->execute([
            'name'    => 'Independent Consignee Co',
            'addr1'   => '5 Consignee Avenue',
            'city'    => 'Manchester',
            'country' => 'United Kingdom',
            'vat'     => 'GB-CONSIGNEE-VAT-999',
            'contact' => 'Priya Consignee-Contact',
            'phone'   => '+44-7700-900111',
            'email'   => 'consignee-contact@example.test',
            'id'      => $clientId,
        ]);
        return $clientId;
    }

    public function testBuyerPoPdfShowsTheConsigneesOwnDetailsNotTheBuyers(): void
    {
        $clientId = $this->createIndependentConsigneeClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'BUYERPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('GB-CONSIGNEE-VAT-999', $text, 'Buyer PO must show the consignee\'s own VAT/EORI, not the buyer\'s');
        self::assertStringContainsString('Priya Consignee-Contact', $text);
        self::assertStringContainsString('+44-7700-900111', $text);
        self::assertStringContainsString('consignee-contact@example.test', $text);
        self::assertStringContainsString('United Kingdom', $text);
    }

    public function testBuyerPoDocxShowsTheConsigneesOwnDetailsNotTheBuyers(): void
    {
        $clientId = $this->createIndependentConsigneeClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'BUYERPO', $userId, null, true, true);
        self::assertNotNull($result['docx_file_id'], 'BUYERPO must have generated a docx file when generateDocx=true');
        $text = $this->extractDocxText((int) $result['docx_file_id']);

        self::assertStringContainsString('GB-CONSIGNEE-VAT-999', $text);
        self::assertStringContainsString('Priya Consignee-Contact', $text);
        self::assertStringContainsString('+44-7700-900111', $text);
        self::assertStringContainsString('consignee-contact@example.test', $text);
        self::assertStringContainsString('United Kingdom', $text);
    }

    public function testBlInstructionSheetPdfShowsTheConsigneesOwnDetailsNotTheBuyers(): void
    {
        $clientId = $this->createIndependentConsigneeClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'BLI', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('Independent Consignee Co', $text, 'BLI Section 2 (CONSIGNEE) must show the consignee\'s own company name, not the buyer\'s');
        self::assertStringContainsString('5 Consignee Avenue', $text);
        self::assertStringContainsString('United Kingdom', $text);
        self::assertStringContainsString('GB-CONSIGNEE-VAT-999', $text);
    }

    public function testCooPrepSheetPdfShowsTheConsigneesOwnDetailsNotTheBuyers(): void
    {
        $clientId = $this->createIndependentConsigneeClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');
        $this->createTestDocument($orderId, 'BLI', 'approved');

        $result = DocumentGenerationService::generate($orderId, 'COOPREP', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('Independent Consignee Co', $text, 'COOPREP "Consignee — Legal Name" must be the consignee\'s own name, not the buyer\'s');
        self::assertStringContainsString('5 Consignee Avenue', $text);
        self::assertStringContainsString('United Kingdom', $text);
    }

    public function testBuyerPoStillShowsTheBuyersOwnDetailsWhenConsigneeIsSameAsBuyer(): void
    {
        // Regression guard for the common case (consignee_same_as_buyer=1,
        // the seeded default) — must still resolve correctly post-fix.
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'BUYERPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('PHPUnit Test Buyer Ltd', $text);
        self::assertStringNotContainsString('Independent Consignee Co', $text);
    }
}
