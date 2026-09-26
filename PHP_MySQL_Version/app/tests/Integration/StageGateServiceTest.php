<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderStageRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * QA-4 (extending QA-1 coverage per docs/QA/TEST_PLAN.md P0.1): every
     * one of Stages 2-9 must refuse on a freshly created order (only Stage
     * 1 in_progress) — the exact shape of the original vulnerability,
     * checked for every stage, not just the ones QA-1's first pass covered.
     *
     */
    #[DataProvider('lockedStageProvider')]
    public function testEveryLockedStageRefusesPassAndUnlockNext(int $stageNumber): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $before = $this->stageStatuses($orderId);

        self::assertFalse(StageGateService::isUnlocked($orderId, $stageNumber), "Stage {$stageNumber} should not be unlocked yet");
        self::assertFalse(StageGateService::passAndUnlockNext($orderId, $stageNumber, null), "Stage {$stageNumber} should refuse to pass");
        self::assertSame($before, $this->stageStatuses($orderId), "Stage {$stageNumber}'s refused attempt must not mutate any order_stages row");
    }

    /** @return array<int, array{0:int}> */
    public static function lockedStageProvider(): array
    {
        return [[2], [3], [4], [5], [6], [7], [8], [9]];
    }

    /**
     * Full legitimate sequential walk through all 9 stages via the real
     * StageGateService (the same calls each controller action makes),
     * confirming the QA-1 guard never blocks genuine in-order progression
     * and that a skip-ahead attempt from every intermediate position is
     * still refused mid-walk.
     */
    public function testSequentialWalkThroughAllNineStagesSucceedsWhileSkipAheadIsAlwaysRefused(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'CIF'); // CIF so Stage 6 (freight) is NOT auto-skipped

        for ($stage = 1; $stage <= 9; $stage++) {
            // Attempt every stage still ahead of the current one — all must be refused.
            for ($ahead = $stage + 1; $ahead <= 9; $ahead++) {
                self::assertFalse(
                    StageGateService::isUnlocked($orderId, $ahead),
                    "Stage {$ahead} must not be unlocked while order is only up to Stage {$stage}"
                );
            }

            self::assertTrue(StageGateService::isUnlocked($orderId, $stage), "Stage {$stage} should be genuinely in_progress");
            self::assertTrue(StageGateService::passAndUnlockNext($orderId, $stage, null), "Stage {$stage} should pass legitimately");
        }

        $final = $this->stageStatuses($orderId);
        foreach ($final as $stageNumber => $status) {
            self::assertSame('gate_passed', $status, "Stage {$stageNumber} should be gate_passed at the end of the walk");
        }
    }

    /** QA-1 test-plan item P0.1(c): FOB auto-skips Stage 6; CIF does not. */
    public function testFobAutoSkipsFreightStageButCifDoesNot(): void
    {
        $fobClientId = $this->createTestClient();
        $fobOrderId = $this->createTestOrder($fobClientId, 'FOB');
        StageGateService::passAndUnlockNext($fobOrderId, 1, null);
        StageGateService::passAndUnlockNext($fobOrderId, 2, null);
        StageGateService::passAndUnlockNext($fobOrderId, 3, null);
        StageGateService::passAndUnlockNext($fobOrderId, 4, null);
        StageGateService::passAndUnlockNext($fobOrderId, 5, null);
        StageGateService::maybeAutoSkipFreightStage($fobOrderId, 0);
        $fobStages = $this->stageStatuses($fobOrderId);
        self::assertSame('skipped', $fobStages[6], 'FOB order should auto-skip Stage 6');
        self::assertSame('in_progress', $fobStages[7], 'Stage 7 should unlock immediately after the auto-skip');

        $cifClientId = $this->createTestClient();
        $cifOrderId = $this->createTestOrder($cifClientId, 'CIF');
        StageGateService::passAndUnlockNext($cifOrderId, 1, null);
        StageGateService::passAndUnlockNext($cifOrderId, 2, null);
        StageGateService::passAndUnlockNext($cifOrderId, 3, null);
        StageGateService::passAndUnlockNext($cifOrderId, 4, null);
        StageGateService::passAndUnlockNext($cifOrderId, 5, null);
        StageGateService::maybeAutoSkipFreightStage($cifOrderId, 0);
        $cifStages = $this->stageStatuses($cifOrderId);
        self::assertSame('in_progress', $cifStages[6], 'CIF order must NOT auto-skip Stage 6');
        self::assertSame('locked', $cifStages[7], 'Stage 7 must stay locked until Stage 6 genuinely passes on a CIF order');
    }
}
