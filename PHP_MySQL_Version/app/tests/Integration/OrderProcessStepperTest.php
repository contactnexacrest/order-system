<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Tests\Support\DbTestCase;

/**
 * Point 2 (2026-10-01): "on order screen -> on the top -> must show the
 * process/sequence like a line with dots and a step below, indicative of
 * process." The existing stage-chip grid gives full per-stage detail but
 * reads as a grid of boxes, not a sequence — this adds a horizontal
 * line-with-dots stepper above it (process-stepper/process-step markup),
 * purely an at-a-glance summary; the detailed grid is untouched below it.
 */
final class OrderProcessStepperTest extends DbTestCase
{
    public function testStepperRendersAllNineStagesInOrderWithTheirNames(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('process-stepper', $output);
        self::assertStringContainsString('process-step-dot', $output);

        // A freshly-created order's first stage is unlocked (in_progress);
        // the rest start locked.
        self::assertMatchesRegularExpression(
            '/process-step in_progress.*?process-step-dot">1<.*?Enquiry &amp; Quotation/s',
            $output
        );
        self::assertMatchesRegularExpression(
            '/process-step locked.*?process-step-dot">9<.*?Document Despatch &amp; Closure/s',
            $output
        );
    }

    /**
     * Batch 3 #14 — the stepper is now clickable: each step carries
     * data-stage="N" and the JS jumps to whichever panel carries the
     * matching data-stage attribute. This doesn't exercise the JS itself
     * (no headless browser here), but pins the markup contract the JS
     * depends on — both sides of the match must be present in the HTML.
     */
    public function testStepperStepsAndTheirTargetPanelsCarryMatchingDataStageAttributes(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $stage) {
            self::assertStringContainsString("role=\"button\" tabindex=\"0\" data-stage=\"{$stage}\"", $output, "stepper step {$stage} must be focusable and carry data-stage");
            self::assertMatchesRegularExpression(
                '/<div class="section"[^>]*data-stage="' . $stage . '"/',
                $output,
                "a panel carrying data-stage=\"{$stage}\" must exist for the stepper to jump to"
            );
        }
    }

    public function testStepperMarksAGatePassedStageDistinctlyFromALockedOne(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $stage1 = \App\Repositories\OrderStageRepository::findByOrderAndStageNumber($orderId, 1);
        \App\Repositories\OrderStageRepository::passGate((int) $stage1['id'], $userId);

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertMatchesRegularExpression('/process-step gate_passed.*?process-step-dot">1</s', $output);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }
}
