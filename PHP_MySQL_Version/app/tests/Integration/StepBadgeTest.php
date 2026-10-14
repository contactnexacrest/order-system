<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #80: "sequential numbered step-tracking (1, 2, 3a,
 * 3b...) across the whole order pipeline so staff can't skip or forget a
 * step." Scoped (per the user's own choice between the candidate
 * interpretations) to a small sub-step badge — e.g. "5a" — next to each
 * stage's own primary staff action buttons on the order page, so staff
 * see the literal order of operations within a stage. Purely a display
 * label ($stepBadge in orders/show.php): it carries no gating logic of
 * its own — the actions it labels already gate exactly as they did
 * before this change.
 */
final class StepBadgeTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function render(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testFreshOrderShowsOnlyStage1sBadge(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($orderId);

        self::assertStringContainsString('<span class="step-badge" title="Step 1a">1a</span>', $output);
        // A freshly-created order has only Stage 1 unlocked. 3b (PI Form
        // Link) and 4c (Buyer Action Link) are deliberate exceptions —
        // both are optional, never-blocking alternate channels available
        // from the very first render regardless of stage ("entirely
        // optional and never blocks anything" per docs/SOP), not part of
        // the gated sequence — so every OTHER later-stage badge must be
        // absent.
        self::assertStringContainsString('title="Step 3b"', $output);
        self::assertStringContainsString('title="Step 4c"', $output);
        $output = str_replace(['title="Step 3b"', 'title="Step 4c"'], '', $output);
        for ($stage = 2; $stage <= 9; $stage++) {
            self::assertStringNotContainsString("title=\"Step {$stage}", $output, "Stage {$stage} badge must not leak onto a brand-new order");
        }
    }

    public function testStepBadgesNeverLeakAheadOfTheCurrentlyUnlockedStage(): void
    {
        // Walks the gate chain one stage at a time and asserts, at every
        // checkpoint, that no rendered badge names a stage further along
        // than what has actually unlocked so far — the literal promise of
        // this feature ("staff can't skip or forget a step" also means
        // staff are never shown a later step before an earlier one). 3b
        // and 4c are excluded as documented above — they are intentionally
        // not stage-gated.
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        // Same reasoning as BliCoopPrepSectionTest: driving the gate chain
        // directly with passAndUnlockNext() (rather than the real
        // "Confirm BL Issued" action) still leaves $blIssued true once
        // Stage 7 passes, so a real BL record is needed here too or the
        // "else" branch reads order_shipping.bl_number/bl_date off a row
        // that was never actually recorded.
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');

        for ($reachedStage = 1; $reachedStage <= 9; $reachedStage++) {
            $this->unlockThrough($orderId, $reachedStage - 1);
            self::assertTrue($reachedStage === 1 || StageGateService::isUnlocked($orderId, $reachedStage));

            $output = str_replace(['title="Step 3b"', 'title="Step 4c"'], '', $this->render($orderId));
            preg_match_all('/class="step-badge" title="Step (\d)[a-z]"/', $output, $matches);
            foreach ($matches[1] as $badgeStage) {
                self::assertLessThanOrEqual(
                    $reachedStage,
                    (int) $badgeStage,
                    "a Stage {$badgeStage} badge rendered while only Stage {$reachedStage} has unlocked"
                );
            }
        }
    }

    public function testBadgesAppearInAscendingOrderAsTheyFirstBecomeActionable(): void
    {
        // Stages 1-5's own primary badge each render with no further setup
        // beyond the stage itself being unlocked (verified against the
        // view's own gating conditions) — a real positional check that
        // "5a" really does sit after "4a", which sits after "3a", etc.
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 4);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5));

        $output = $this->render($orderId);

        $pos1a = strpos($output, 'title="Step 1a"');
        $pos2a = strpos($output, 'title="Step 2a"');
        $pos3a = strpos($output, 'title="Step 3a"');
        $pos4a = strpos($output, 'title="Step 4a"');
        $pos5a = strpos($output, 'title="Step 5a"');

        self::assertNotFalse($pos1a);
        self::assertNotFalse($pos2a);
        self::assertNotFalse($pos3a);
        self::assertNotFalse($pos4a);
        self::assertNotFalse($pos5a);
        self::assertLessThan($pos2a, $pos1a);
        self::assertLessThan($pos3a, $pos2a);
        self::assertLessThan($pos4a, $pos3a);
        self::assertLessThan($pos5a, $pos4a);
    }
}
