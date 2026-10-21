<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderPackingRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Phase 2 item 13 of the approved remediation plan: "Packing Actual
 * Quantity needs unit dropdown / default to PI's unit." The Packing form
 * already implicitly assumed actual_quantity_packed shared the order's
 * own product unit (the shortfall-tolerance error message already
 * quoted that unit), but never stored or showed a unit anywhere — the
 * Packing List document printed the bare number with no unit at all.
 */
final class PackingActualQuantityUnitTest extends DbTestCase
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

    public function testPackingListPdfShowsTheQuantityWithItsUnit(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        OrderPackingRepository::upsert($orderId, ['actual_quantity_packed' => '95', 'actual_quantity_unit' => 'Sq.Mtr']);

        $result = DocumentGenerationService::generate($orderId, 'PL', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertMatchesRegularExpression('/Total Quantity[\s\S]{0,10}95 Sq\.Mtr/', $text);
    }

    public function testPackingListPdfShowsToBeConfirmedWhenQuantityNotYetSet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'PL', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertMatchesRegularExpression('/Total Quantity[\s\S]{0,10}To Be Confirmed/', $text);
    }
}
