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
        // A freshly-created order has only Stage 1 unlocked. 4d (PI Form
        // Link) and 4c (Buyer Action Link) are deliberate exceptions —
        // both are optional, never-blocking alternate channels available
        // from the very first render regardless of stage ("entirely
        // optional and never blocks anything" per docs/SOP), not part of
        // the gated sequence — so every OTHER badge must be absent.
        self::assertStringContainsString('title="Step 4d"', $output);
        self::assertStringContainsString('title="Step 4c"', $output);
        $stripped = preg_replace('/class="step-badge" title="Step (1a|4d|4c)">[^<]*<\/span>/', '', $output);
        self::assertStringNotContainsString('class="step-badge"', $stripped, 'no other step badge may leak onto a brand-new order');
    }

    public function testStepBadgesNeverLeakAheadOfTheCurrentlyUnlockedStage(): void
    {
        // Walks the gate chain one stage at a time and asserts, at every
        // checkpoint, that no rendered badge names a stage further along
        // than what has actually unlocked so far — the literal promise of
        // this feature ("staff can't skip or forget a step" also means
        // staff are never shown a later step before an earlier one). 4d
        // and 4c are excluded as documented above — they are intentionally
        // not stage-gated. The order-detail-page rebuild relabelled every
        // badge to the user's own step numbering rather than the DB's
        // stage_number, so a badge's own digit prefix no longer equals its
        // governing DB stage — this map is the authoritative link between
        // the two, kept in lock-step with the stepBadge() calls in
        // views/orders/show.php.
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        // Same reasoning as BliCoopPrepSectionTest: driving the gate chain
        // directly with passAndUnlockNext() (rather than the real
        // "Confirm BL Issued" action) still leaves $blIssued true once
        // Stage 7 passes, so a real BL record is needed here too or the
        // "else" branch reads order_shipping.bl_number/bl_date off a row
        // that was never actually recorded.
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');

        $badgeStage = [
            '1a' => 1,
            '2a' => 2, '2b' => 2, '2c' => 2,
            '5a' => 3, '5b' => 3, '5c' => 3,
            '4a' => 4, '4b' => 4,
            '7a' => 5, '7b' => 5, '7c' => 5, '7d' => 5,
            '6a' => 6, '6b' => 6, '6c' => 6, '6d' => 6,
            '9g' => 7, '9h' => 7, '9i' => 7, '9j' => 7, '9k' => 7,
            '8a' => 8, '8b' => 8, '8c' => 8, '8d' => 8,
            '9a' => 9, '9b' => 9, '9c' => 9, '9d' => 9, '9e' => 9, '9f' => 9,
            '11e' => 9,
        ];

        for ($reachedStage = 1; $reachedStage <= 9; $reachedStage++) {
            $this->unlockThrough($orderId, $reachedStage - 1);
            self::assertTrue($reachedStage === 1 || StageGateService::isUnlocked($orderId, $reachedStage));

            $output = $this->render($orderId);
            preg_match_all('/class="step-badge" title="Step ([0-9a-z]+)"/', $output, $matches);
            foreach ($matches[1] as $badge) {
                if ($badge === '4c' || $badge === '4d') {
                    continue;
                }
                self::assertArrayHasKey($badge, $badgeStage, "unrecognised step badge '{$badge}' — update this test's badge-to-stage map");
                self::assertLessThanOrEqual(
                    $reachedStage,
                    $badgeStage[$badge],
                    "badge '{$badge}' (stage {$badgeStage[$badge]}) rendered while only stage {$reachedStage} has unlocked"
                );
            }
        }
    }

    public function testBadgesAppearInAscendingOrderAsTheyFirstBecomeActionable(): void
    {
        // Stages 1-5's own primary badge each render with no further setup
        // beyond the stage itself being unlocked (verified against the
        // view's own gating conditions) — a real positional check that
        // each card's badge really does sit after the previous card's.
        // The order-detail-page rebuild lays out cards in the user's own
        // step sequence (QT -> Buyer PO -> PI & Advance Payment -> Order
        // Confirmation -> Supplier PO), not DB stage_number order, so the
        // badges appear in that same sequence even though their own
        // letters (driven by each card's governing DB stage) aren't
        // numerically ascending — "5a" (PI & Advance, DB stage 3) sits
        // before "4a" (Order Confirmation, DB stage 4) here.
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 4);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5));

        $output = $this->render($orderId);

        $pos1a = strpos($output, 'title="Step 1a"');
        $pos2a = strpos($output, 'title="Step 2a"');
        $pos5a = strpos($output, 'title="Step 5a"');
        $pos4a = strpos($output, 'title="Step 4a"');
        $pos7a = strpos($output, 'title="Step 7a"');

        self::assertNotFalse($pos1a);
        self::assertNotFalse($pos2a);
        self::assertNotFalse($pos5a);
        self::assertNotFalse($pos4a);
        self::assertNotFalse($pos7a);
        self::assertLessThan($pos2a, $pos1a);
        self::assertLessThan($pos5a, $pos2a);
        self::assertLessThan($pos4a, $pos5a);
        self::assertLessThan($pos7a, $pos4a);
    }
}
