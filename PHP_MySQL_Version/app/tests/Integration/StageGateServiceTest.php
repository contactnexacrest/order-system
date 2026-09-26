<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderStageRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA-1 regression coverage: StageGateService::passAndUnlockNext() used to
 * only refuse when a stage was already gate_passed — it never checked that
 * the stage was actually in_progress (genuinely reached) versus still
 * locked (its predecessor never passed). Every stage-advancing controller
 * action calls it, so any of them could force-pass any stage on any order
 * by being POSTed directly, skipping every stage before it. These tests
 * exercise the fix directly against a real database and real order rows
 * (not mocks — the repositories are static classes over a shared PDO
 * connection, so integration-style testing is the natural fit here).
 */
final class StageGateServiceTest extends DbTestCase
{
    public function testIsUnlockedIsFalseForALockedStage(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $stages = $this->stageStatuses($orderId);
        self::assertSame('in_progress', $stages[1]);
        self::assertSame('locked', $stages[9]);

        self::assertFalse(StageGateService::isUnlocked($orderId, 9));
        self::assertTrue(StageGateService::isUnlocked($orderId, 1));
    }

    public function testPassAndUnlockNextRefusesALockedStageAndMutatesNothing(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $before = $this->stageStatuses($orderId);

        // This is the exact shape of the original vulnerability: calling
        // passAndUnlockNext() directly on Stage 9 (closeOrder's gate) while
        // the order is still on Stage 1. Before the QA-1 fix this returned
        // void and silently passed the gate anyway.
        $result = StageGateService::passAndUnlockNext($orderId, 9, null);

        self::assertFalse($result);
        self::assertSame($before, $this->stageStatuses($orderId));
    }

    public function testPassAndUnlockNextPassesAGenuinelyInProgressStageAndUnlocksTheNext(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $result = StageGateService::passAndUnlockNext($orderId, 1, null);

        self::assertTrue($result);
        $stages = $this->stageStatuses($orderId);
        self::assertSame('gate_passed', $stages[1]);
        self::assertSame('in_progress', $stages[2]);
        self::assertSame('locked', $stages[3]);
    }

    public function testPassAndUnlockNextIsIdempotentWhenAlreadyPassed(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        self::assertTrue(StageGateService::passAndUnlockNext($orderId, 1, null));
        $afterFirstPass = $this->stageStatuses($orderId);

        self::assertTrue(StageGateService::passAndUnlockNext($orderId, 1, null));
        self::assertSame($afterFirstPass, $this->stageStatuses($orderId));
    }

    public function testPassAndUnlockNextReturnsFalseForANonexistentStage(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        self::assertFalse(StageGateService::passAndUnlockNext($orderId, 99, null));
        self::assertNull(OrderStageRepository::findByOrderAndStageNumber($orderId, 99));
    }
}
