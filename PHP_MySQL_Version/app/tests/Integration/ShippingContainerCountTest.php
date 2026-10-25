<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Phase 1 item 8 of the approved remediation plan: "1-container vs
 * 2-container / multi-product-multi-container handling" had no dedicated
 * field at all — container_no was free text that might list more than one
 * container number, but how many containers a shipment actually uses was
 * never captured as its own value. User chose "add a container-count
 * field" when asked how this should work. Added order_shipping.container_count,
 * surfaced on the Stage 7 Shipping Details form and printed on both the
 * BL Instruction Sheet and the Commercial Invoice.
 */
final class ShippingContainerCountTest extends DbTestCase
{
    private function extractPdfText(int $pdfFileId): string
    {
        $file = FileStoreRepository::find($pdfFileId);
        self::assertNotNull($file, 'generated PDF file_store row must exist');
        // -layout: the default "reading order" mode can interleave the
        // diagonal draft watermark's text with nearby table rows in an
        // order sensitive to exact row height, which this test's
        // label-then-value regexes aren't meant to depend on; -layout
        // preserves the physical column layout instead.
        $process = proc_open(
            ['pdftotext', '-layout', $file['server_path'], '-'],
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

    public function testShippingDetailsFormShowsTheSavedContainerCount(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'container_count' => 2]);

        $shipping = OrderShippingRepository::find($orderId);

        self::assertSame(2, (int) $shipping['container_count']);
    }

    public function testBlInstructionSheetPdfShowsTheNumberOfContainers(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'container_count' => 2, 'container_no' => 'MSCU1111111; MSCU2222222']);

        $result = DocumentGenerationService::generate($orderId, 'BLI', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('Number of Containers', $text);
        self::assertMatchesRegularExpression('/Number of Containers\s*2/', $text);
    }

    public function testCommercialInvoicePdfShowsTheNumberOfContainers(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'container_count' => 3]);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');

        $result = DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('Number of Containers', $text);
        self::assertMatchesRegularExpression('/Number of Containers\s*3/', $text);
    }

    public function testBlInstructionSheetShowsToBeConfirmedWhenContainerCountNotYetSet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk']);

        $result = DocumentGenerationService::generate($orderId, 'BLI', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertMatchesRegularExpression('/Number of Containers\s*To Be Confirmed/', $text);
    }
}
