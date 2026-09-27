<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 EML-06 (same overlapping-run defect class as the email dispatcher,
 * folded in while fixing it): the three callers of markAcknowledged()
 * (staff-recorded via email reply, client-portal, and the 48h auto-confirm
 * cron) each pre-check `acknowledged_at IS NULL` and then write
 * unconditionally — a check-then-act race if two land at the same moment
 * (e.g. the buyer acknowledges in the portal in the same instant the 48h
 * cron reaches their order). markAcknowledged() now makes the write itself
 * the atomic claim.
 */
final class OcAcknowledgmentRaceGuardTest extends DbTestCase
{
    private function makeAcknowledgment(int $orderId, int $documentId): void
    {
        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
    }

    public function testMarkAcknowledgedWinsOnceAndLosesOnASecondAttempt(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'sent');
        $this->makeAcknowledgment($orderId, $documentId);

        self::assertTrue(OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'client_portal', null, null), 'the first path to arrive (e.g. the client portal) must win the claim');

        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertSame('client_portal', $ack['acknowledged_via']);

        // The 48h auto-confirm cron reaching the same order a moment
        // later — this is the exact overlap the fix closes.
        self::assertFalse(OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'auto_48h', null, null), 'a second acknowledgment path must lose — the order was already acknowledged');

        $unchanged = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertSame('client_portal', $unchanged['acknowledged_via'], 'the losing call must not overwrite who actually acknowledged it');
    }

    public function testMarkAcknowledgedRefusesAnOrderWithNoPendingAcknowledgment(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        // No order_oc_acknowledgments row exists at all for this order.

        self::assertFalse(OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'staff_recorded_email', 'note', 1));
    }
}
