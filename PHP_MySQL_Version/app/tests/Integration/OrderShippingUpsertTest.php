<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderShippingRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 4, N-02: recordBl, attachBlCopy, recordBlOriginalsReceived
 * and recordBlEndorsed used to run a plain UPDATE against order_shipping.
 * That row only ever existed if staff had saved "Shipping / Vessel
 * Details" first (OrderShippingRepository::upsert()) — if they skipped
 * straight to confirming the BL number, attaching the scan, recording
 * originals, or recording the endorsement, every one of those actions
 * reported success while silently updating zero rows. Fixed by making
 * all four upsert, same pattern attachDraftBl() already used.
 */
final class OrderShippingUpsertTest extends DbTestCase
{
    public function testRecordBlCreatesTheRowWhenShippingDetailsWereNeverSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertNull(OrderShippingRepository::find($orderId), 'Precondition: no order_shipping row exists yet.');

        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-10-20');

        $row = OrderShippingRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame('MSCU1234567', $row['bl_number']);
        self::assertSame('2026-10-20', $row['bl_date']);
    }

    public function testAttachBlCopyCreatesTheRowWhenShippingDetailsWereNeverSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId);

        OrderShippingRepository::attachBlCopy($orderId, $fileId);

        $row = OrderShippingRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame($fileId, (int) $row['bl_copy_file_id']);
    }

    public function testRecordBlOriginalsReceivedCreatesTheRowWhenShippingDetailsWereNeverSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        OrderShippingRepository::recordBlOriginalsReceived($orderId, 3);

        $row = OrderShippingRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame(3, (int) $row['bl_originals_received_count']);
        self::assertNotNull($row['bl_originals_received_at']);
    }

    public function testRecordBlEndorsedCreatesTheRowWhenShippingDetailsWereNeverSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        OrderShippingRepository::recordBlEndorsed($orderId, $userId);

        $row = OrderShippingRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame($userId, (int) $row['bl_endorsed_by']);
        self::assertNotNull($row['bl_endorsed_at']);
    }

    /**
     * The full reproduce journey from the QA report: skip the Shipping /
     * Vessel Details form entirely, then run through BL issued →
     * originals received → endorsed → copy attached, in order, and
     * confirm every single one of those four values survives together on
     * the same row (not just that each one works in isolation).
     */
    public function testFullBlJourneyWithoutEverSavingShippingDetailsPersistsEverything(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $fileId = $this->createTestFile($orderId);

        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-10-20');
        OrderShippingRepository::recordBlOriginalsReceived($orderId, 3);
        OrderShippingRepository::recordBlEndorsed($orderId, $userId);
        OrderShippingRepository::attachBlCopy($orderId, $fileId);

        $row = OrderShippingRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame('MSCU1234567', $row['bl_number']);
        self::assertSame('2026-10-20', $row['bl_date']);
        self::assertSame(3, (int) $row['bl_originals_received_count']);
        self::assertSame($userId, (int) $row['bl_endorsed_by']);
        self::assertSame($fileId, (int) $row['bl_copy_file_id']);
    }

    public function testRecordBlDoesNotClobberShippingDetailsSavedEarlier(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MV Test']);

        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-10-20');

        $row = OrderShippingRepository::find($orderId);
        self::assertSame('Maersk', $row['shipping_line']);
        self::assertSame('MV Test', $row['vessel_name']);
        self::assertSame('MSCU1234567', $row['bl_number']);
    }
}
