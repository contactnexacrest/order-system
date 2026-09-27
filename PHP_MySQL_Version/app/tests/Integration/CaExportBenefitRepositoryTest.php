<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * CA / Accounting module (Phase 8) — government export benefit/incentive
 * claims (RODTEP + other schemes). Closes a real gap raised during a
 * holistic CA/Reports cross-check: money the government owes the company
 * (export incentives) had no home anywhere in the system, unlike every
 * expense (ca_expenses, imported from Zoho Books).
 */
final class CaExportBenefitRepositoryTest extends DbTestCase
{
    public function testRecordAndFindRoundTrip(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = CaExportBenefitRepository::record(null, 'RODTEP', 'SB1234567', 15000.50, '2026-06-15', 'INR', 'Q1 claim', $userId);

        $found = CaExportBenefitRepository::find($id);
        self::assertNotNull($found);
        self::assertSame('RODTEP', $found['scheme_name']);
        self::assertSame('SB1234567', $found['reference_number']);
        self::assertEqualsWithDelta(15000.50, (float) $found['claimed_amount'], 0.001);
        self::assertNull($found['received_amount']);
        self::assertNull($found['order_id']);
    }

    public function testRecordLinkedToAnOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $id = CaExportBenefitRepository::record($orderId, 'Duty Drawback', null, 5000, '2026-05-01', 'INR', null, $userId);

        $found = CaExportBenefitRepository::find($id);
        self::assertSame($orderId, $found['order_id']);
    }

    public function testMarkReceivedSetsAmountAndDate(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = CaExportBenefitRepository::record(null, 'RODTEP', null, 10000, '2026-04-01', 'INR', null, $userId);

        CaExportBenefitRepository::markReceived($id, 9500, '2026-07-10');

        $found = CaExportBenefitRepository::find($id);
        self::assertEqualsWithDelta(9500.0, (float) $found['received_amount'], 0.001);
        self::assertSame('2026-07-10', $found['received_at']);
    }

    public function testTotalsAggregateAcrossClaims(): void
    {
        $userId = $this->createTestUser('Admin');
        $idA = CaExportBenefitRepository::record(null, 'RODTEP', null, 1000, '2026-01-01', 'INR', null, $userId);
        $idB = CaExportBenefitRepository::record(null, 'RODTEP', null, 2000, '2026-01-02', 'INR', null, $userId);
        CaExportBenefitRepository::markReceived($idA, 900, '2026-02-01');

        self::assertGreaterThanOrEqual(3000.0, CaExportBenefitRepository::totalClaimed());
        self::assertGreaterThanOrEqual(900.0, CaExportBenefitRepository::totalReceived());
        unset($idB);
    }

    public function testFindByReferenceHelperUsedForLinkingAnExistingOrder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $order = OrderRepository::find($orderId);

        self::assertSame($orderId, OrderRepository::findIdByReference($order['order_reference']));
        self::assertNull(OrderRepository::findIdByReference('NOT-A-REAL-REFERENCE'));
    }
}
