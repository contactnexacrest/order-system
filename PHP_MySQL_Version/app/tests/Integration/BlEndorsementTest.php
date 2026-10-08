<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderBlEndorsementRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BC — the Bill of Lading Endorsement print
 * feature: a small CRUD record (OrderBlEndorsementRepository) backing a
 * genuinely buyer-facing document (category='customer_facing', unlike
 * CAFIN/BLI/SUPPO) that self-approves instead of going through the
 * normal draft->review cycle (see DocumentGenerationService::
 * generateBlEndorsement()'s own docblock for why).
 */
final class BlEndorsementTest extends DbTestCase
{
    public function testUpsertCreatesThenUpdatesTheSameRow(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        OrderBlEndorsementRepository::upsert($orderId, [
            'bl_number'           => 'MSCU1234567',
            'vessel_voyage'       => 'MSC MAYA / 012W',
            'port_of_loading'     => 'Chennai, India',
            'port_of_discharge'   => 'Rotterdam, Netherlands',
            'date_of_endorsement' => '2026-06-15',
        ], $userId);

        $row = OrderBlEndorsementRepository::find($orderId);
        self::assertSame('MSCU1234567', $row['bl_number']);
        self::assertSame('MSC MAYA / 012W', $row['vessel_voyage']);

        OrderBlEndorsementRepository::upsert($orderId, [
            'bl_number'           => 'MSCU7654321',
            'vessel_voyage'       => 'MSC MAYA / 012W',
            'port_of_loading'     => 'Chennai, India',
            'port_of_discharge'   => 'Rotterdam, Netherlands',
            'date_of_endorsement' => '2026-06-16',
        ], $userId);

        $all = Database::connection()->query("SELECT COUNT(*) FROM order_bl_endorsements WHERE order_id = {$orderId}")->fetchColumn();
        self::assertSame(1, (int) $all, 'upsert must never create a second row for the same order');

        $reloaded = OrderBlEndorsementRepository::find($orderId);
        self::assertSame('MSCU7654321', $reloaded['bl_number'], 'the second save must overwrite the first');
    }

    public function testGenerateRefusesWhenNoEndorsementDetailsSavedYet(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('have not been saved');
        DocumentGenerationService::generateBlEndorsement($orderId, $userId);
    }

    public function testGenerateProducesARealPdfAndAnApprovedCustomerFacingDocument(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderBlEndorsementRepository::upsert($orderId, [
            'bl_number'           => 'MSCU1234567',
            'vessel_voyage'       => 'MSC MAYA / 012W',
            'port_of_loading'     => 'Chennai, India',
            'port_of_discharge'   => 'Rotterdam, Netherlands',
            'date_of_endorsement' => '2026-06-15',
        ], $userId);

        $result = DocumentGenerationService::generateBlEndorsement($orderId, $userId);

        self::assertNotEmpty($result['document_reference']);
        self::assertStringContainsString('BLE', $result['document_reference']);

        $document = DocumentRepository::find((int) $result['document_id']);
        self::assertSame('BLE', $document['document_type_code']);
        self::assertSame('customer_facing', $document['document_type_category']);
        self::assertSame('approved', $document['status'], 'BLE self-approves — no draft->review cycle');

        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        self::assertSame(0, (int) $file['internal_only'], 'unlike CAFIN, this file is never internal_only');
        self::assertTrue(is_file($file['server_path']), 'Generated BLE PDF must actually exist on disk.');
        $pdfBytes = (string) file_get_contents($file['server_path']);
        self::assertStringStartsWith('%PDF', $pdfBytes, 'File must be a real PDF.');

        // Regression guard: the Twig template used `x or 'default'` for every
        // field, which in real Twig/PHP is strict boolean `or` (not a
        // Python/JS-style "return left operand if truthy" or) -- it silently
        // rendered "1" for every field regardless of value. Caught only by
        // actually reading the generated PDF's text, not by checking that a
        // PDF merely exists. Fixed to use the `|default()` filter instead.
        $text = @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');
        if ($text !== null && $text !== false && trim($text) !== '') {
            self::assertStringContainsString('MSCU1234567', $text, 'BL Number must render as its real value, not "1".');
            self::assertStringContainsString('MSC MAYA / 012W', $text, 'Vessel/Voyage must render as its real value, not "1".');
        }

        @unlink($file['server_path']);
    }

    public function testGeneratedBleAppearsInCustomerFacingForOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderBlEndorsementRepository::upsert($orderId, [
            'bl_number'     => 'MSCU1234567',
            'vessel_voyage' => 'MSC MAYA / 012W',
        ], $userId);

        $result = DocumentGenerationService::generateBlEndorsement($orderId, $userId);

        $customerFacing = DocumentRepository::customerFacingForOrder($orderId);
        $codes = array_column($customerFacing, 'document_type_code');
        self::assertContains('BLE', $codes, 'BLE is genuinely buyer-facing — it must appear in the client portal document list');

        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        @unlink($file['server_path']);
    }

    public function testEachGenerationBumpsTheRevisionNumberOnTheSameReference(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderBlEndorsementRepository::upsert($orderId, ['bl_number' => 'MSCU1111111'], $userId);

        $first = DocumentGenerationService::generateBlEndorsement($orderId, $userId);
        OrderBlEndorsementRepository::upsert($orderId, ['bl_number' => 'MSCU2222222'], $userId);
        $second = DocumentGenerationService::generateBlEndorsement($orderId, $userId);

        self::assertSame($first['document_reference'], $second['document_reference'], 'regenerating keeps the same document reference');
        self::assertSame($first['revision_number'] + 1, $second['revision_number']);

        @unlink(FileStoreRepository::find((int) $first['pdf_file_id'])['server_path']);
        @unlink(FileStoreRepository::find((int) $second['pdf_file_id'])['server_path']);
    }

    public function testSaveBlEndorsementControllerActionUpsertsFromPost(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'bl_number'           => 'MSCU9999999',
            'vessel_voyage'       => 'EVER GIVEN / 099E',
            'port_of_loading'     => 'Chennai, India',
            'port_of_discharge'   => 'Hamburg, Germany',
            'date_of_endorsement' => '2026-07-01',
        ];
        $controller = new OrderController();
        ob_start();
        $controller->saveBlEndorsement(['id' => (string) $orderId]);
        ob_get_clean();

        $row = OrderBlEndorsementRepository::find($orderId);
        self::assertSame('MSCU9999999', $row['bl_number']);
        self::assertSame('EVER GIVEN / 099E', $row['vessel_voyage']);
    }

    public function testGenerateBlEndorsementControllerActionGeneratesWhenDetailsSaved(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderBlEndorsementRepository::upsert($orderId, ['bl_number' => 'MSCU3333333'], $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new OrderController();
        ob_start();
        $controller->generateBlEndorsement(['id' => (string) $orderId]);
        ob_get_clean();

        $doc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'BLE');
        self::assertNotNull($doc);

        $file = FileStoreRepository::find((int) $doc['pdf_file_id']);
        @unlink($file['server_path']);
    }

    public function testGenerateBlEndorsementControllerActionFlashesErrorWhenNotSaved(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new OrderController();
        ob_start();
        $controller->generateBlEndorsement(['id' => (string) $orderId]);
        ob_get_clean();

        self::assertNull(DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'BLE'));
    }
}
