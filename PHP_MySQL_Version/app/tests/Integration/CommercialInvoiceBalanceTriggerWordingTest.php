<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-06 (High, Both) — "CI on a Tier 1 order prints
 * the Tier 2 balance trigger ('within 7 Calendar Days of the date
 * NexaCrest emails the scanned BL copy')." The Commercial Invoice's own
 * Section 7 "BALANCE DUE NOW" clause used to hardcode that Tier 2
 * wording (plus a separate, order-independent `ci_balance_days_post_bl`
 * company setting) regardless of which payment preset the order was
 * actually on. QA's own fix direction: "CI template/terms must follow
 * the order's payment-preset tier like QT/PI do." Now reuses the same
 * balance_terms_text field QT/PI/BuyerPO already correctly use.
 */
final class CommercialInvoiceBalanceTriggerWordingTest extends DbTestCase
{
    private function addTestProduct(int $orderId): void
    {
        OrderProductRepository::add($orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '1825.00');
    }

    public function testTier1OrderPrintsTheBeforeShipmentTriggerNotTheBlCopyOne(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');
        $this->addTestProduct($orderId);

        $data = DocumentDataAssembler::assemble($orderId, 'CI');

        self::assertStringContainsString('before shipment', $data['financial']['balance_terms_text']);
        self::assertStringContainsString('Shipment Readiness Confirmation', $data['financial']['balance_terms_text']);
        self::assertStringNotContainsString('scanned copy of Bill of Lading', $data['financial']['balance_terms_text']);
    }

    public function testTier2OrderPrintsTheAgainstBlTrigger(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL');
        $this->addTestProduct($orderId);

        $data = DocumentDataAssembler::assemble($orderId, 'CI');

        self::assertStringContainsString('scanned copy of Bill of Lading', $data['financial']['balance_terms_text']);
        self::assertStringNotContainsString('before shipment', $data['financial']['balance_terms_text']);
    }

    /** End-to-end: the rendered PDF itself, not just the assembled data array. */
    public function testRenderedTier1CommercialInvoicePdfCarriesTheBeforeShipmentWording(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');
        $this->addTestProduct($orderId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');

        if ($text === null || $text === false || trim($text) === '') {
            self::markTestSkipped('pdftotext is not available in this environment.');
        }

        self::assertStringContainsString('Shipment Readiness Confirmation', $text);
        self::assertStringNotContainsString('scanned copy of Bill of Lading', $text);
    }
}
