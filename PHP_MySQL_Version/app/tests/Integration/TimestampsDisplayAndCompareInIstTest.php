<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 6: "the time stamp must be IST Indian
 * -Kolkata time stamp". bootstrap.php already set
 * date_default_timezone_set('Asia/Kolkata'), but the MySQL connection's
 * own session time zone defaulted to SYSTEM (UTC on this host) — so
 * every TIMESTAMP column (generated_at, sent_at/due_at, etc.) stored and
 * read back UTC wall-clock values while PHP's own fresh date()/
 * DateTimeImmutable() calls were already IST. A document re-rendered
 * from its stored generated_at could show the wrong calendar date, and
 * a TIMESTAMP comparison against NOW() (e.g. the 48-hour OC
 * acknowledgment auto-confirm deadline) was off by the full 5.5-hour
 * gap between UTC and IST. Fixed by setting the connection's session
 * time zone to the same +05:30 offset (Database::connection()).
 */
final class TimestampsDisplayAndCompareInIstTest extends DbTestCase
{
    public function testATimestampGeneratedNearUtcMidnightDisplaysItsCorrectIstCalendarDate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $docId = $this->createTestDocument($orderId, 'QT');

        // CONVERT_TZ anchors this to a true, known UTC instant (2026-10-20
        // 20:00:00 UTC = 2026-10-21 01:30:00 IST) regardless of whatever
        // the connection's OWN session time zone currently is, so this
        // setup step is deterministic whether or not the fix under test
        // is applied — only the later read/format step's correctness
        // depends on it.
        Database::connection()->exec(
            "UPDATE documents SET generated_at = CONVERT_TZ('2026-10-20 20:00:00', '+00:00', @@session.time_zone) WHERE id = {$docId}"
        );

        $doc = DocumentRepository::find($docId);

        self::assertSame('21 October 2026', DocumentDataAssembler::formatDate($doc['generated_at']));
    }

    public function testAnOverdueOcAcknowledgmentIsPickedUpForAutoConfirmImmediately(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $docId = $this->createTestDocument($orderId, 'OC');

        // Mirrors EmailDispatchService::dispatch()'s exact computation:
        // fresh PHP DateTimeImmutable values, which are IST since
        // bootstrap sets the default timezone. due_at one minute in the
        // real past means this acknowledgment is genuinely overdue right
        // now — it must show up immediately, not ~5.5 hours later.
        $now = new \DateTimeImmutable();
        OrderOcAcknowledgmentRepository::recordSent(
            $orderId,
            $docId,
            $now->modify('-2 hours')->format('Y-m-d H:i:s'),
            $now->modify('-1 minute')->format('Y-m-d H:i:s')
        );

        $due = OrderOcAcknowledgmentRepository::dueForAutoConfirm();
        $orderIds = array_map(static fn (array $row): int => (int) $row['order_id'], $due);

        self::assertContains($orderId, $orderIds, 'an acknowledgment whose due_at is 1 minute in the past must be due for auto-confirm right now, not hours from now');
    }
}
