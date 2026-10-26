<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Helpers\OrderStorage;
use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Services\OrderFileReorganizer;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-26): an order's folders match its steps on the
 * order page, and every document lands in its own step's folder — a
 * Quotation regenerated after the order moved on used to be filed under
 * whatever stage the order was at.
 */
final class OrderStorageFoldersTest extends DbTestCase
{
    private const ORDER = ['client_unique_number' => 'NC/SC/2026/1', 'order_reference' => 'SC/ORD/2026/9'];

    public function testFoldersAreNamedAfterTheOrderPageSteps(): void
    {
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/01-quotation/generated', OrderStorage::forGeneratedDocument(self::ORDER, 'QT'));
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/10-packing-and-bl/generated', OrderStorage::forGeneratedDocument(self::ORDER, 'BLI'));
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/13-closure/generated', OrderStorage::forGeneratedDocument(self::ORDER, 'BLE'));
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/04-buyer-po/received', OrderStorage::forReceived(self::ORDER, 'buyer_po'));
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/disputes/7', OrderStorage::folder(self::ORDER, 'disputes', '7'));
    }

    public function testAGeneratedQuotationIsFiledUnderTheQuotationStepWhateverStageTheOrderIsAt(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        foreach ([1, 2, 3] as $stage) {
            StageGateService::passAndUnlockNext($orderId, $stage, $userId);
        }

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);

        $path = (string) FileStoreRepository::find((int) $result['pdf_file_id'])['server_path'];
        self::assertStringContainsString('/01-quotation/generated/', $path);
        self::assertFileExists($path);
    }

    public function testTheReorganizerMapsOldFoldersToTheirStep(): void
    {
        $old = '/srv/storage/clients/NC-1/SC-ORD-1/';
        self::assertSame(
            ['clients/NC-SC-2026-1/SC-ORD-2026-9/01-quotation/generated', 'generated QT'],
            OrderFileReorganizer::targetFolder($old . 'pi/generated/a.pdf', self::ORDER, 'QT'),
            'a document goes by its own type, not the stage folder it was found in'
        );
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/10-packing-and-bl/received', OrderFileReorganizer::targetFolder($old . 'shipping/b.pdf', self::ORDER, null)[0]);
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/amendments/SC-AMD-1/received', OrderFileReorganizer::targetFolder($old . 'amendments/SC-AMD-1/signed/c.pdf', self::ORDER, null)[0]);
        self::assertSame('clients/NC-SC-2026-1/SC-ORD-2026-9/04-buyer-po/received', OrderFileReorganizer::targetFolder($old . '04-buyer-po/received/d.pdf', self::ORDER, null)[0], 'already in the new layout');
        self::assertNull(OrderFileReorganizer::targetFolder($old . 'mystery/e.pdf', self::ORDER, null)[0], 'unknown folders are left alone');
    }
}
