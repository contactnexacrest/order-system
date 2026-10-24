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
     * Batch 3 #14, updated for the UI-polish round that made every
     * .step-group its own collapsible `<details>` with a unique id
     * (step-group-stage-N, N = DB stage_number 2-9): the stepper's
     * click-to-jump script now resolves a clicked stage number to that
     * one parent group box — opening and scrolling to the group itself,
     * never diving into one of its sub-item cards — via its own
     * `stageToGroupId` lookup object instead of a per-card list. Stage 1
     * has no group (standalone Quotation cards), so it still targets the
     * first of those. This pins that every stage number the stepper
     * exposes still resolves to a real id present in the page (kept in
     * lock-step with the same object in show.php's own script).
     */
    public function testStepperStepsAndTheirTargetPanelsCarryMatchingDataStageAttributes(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        $stageToGroupId = [
            1 => 'step-1-quotation-prepare',
            2 => 'step-group-stage-2',
            3 => 'step-group-stage-3',
            4 => 'step-group-stage-4',
            5 => 'step-group-stage-5',
            6 => 'step-group-stage-6',
            7 => 'step-group-stage-7',
            8 => 'step-group-stage-8',
            9 => 'step-group-stage-9',
        ];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $stage) {
            self::assertStringContainsString("role=\"button\" tabindex=\"0\" data-stage=\"{$stage}\"", $output, "stepper step {$stage} must be focusable and carry data-stage");
            self::assertStringContainsString("id=\"{$stageToGroupId[$stage]}\"", $output, "the parent group/card carrying id=\"{$stageToGroupId[$stage]}\" must exist for the stepper to jump to for stage {$stage}");
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
