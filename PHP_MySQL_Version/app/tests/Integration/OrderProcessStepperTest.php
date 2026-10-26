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

    /**
     * The Step 8 group box (8a Production + 8b Compliance Checklist) never
     * carried its own `open` marker, unlike every other .step-group — so
     * when 8b was the order's current step, the server-opened checklist
     * card sat inside a closed group, invisible, and the on-load
     * scroll-to-current-card had nothing to scroll to (page stayed at the
     * top). Steps 1-7 done + checklist outstanding is exactly that state.
     */
    public function testComplianceChecklistOpensInsideStep7WhileTasksAreOutstanding(): void
    {
        // Owner request (2026-10-26): the checklist is split by phase — the
        // "with the Supplier PO" part sits at the start of Step 7 and opens
        // with it while anything there is still outstanding.
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'QT', 'sent');
        $this->createTestDocument($orderId, 'BUYERPO');
        foreach ([1, 2, 3, 4] as $stageNumber) {
            \App\Services\StageGateService::passAndUnlockNext($orderId, $stageNumber, $userId);
        }
        \App\Repositories\OrderComplianceTaskRepository::initializeForOrder($orderId);

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('id="step-group-stage-5" open', $output);
        self::assertStringContainsString('id="compliance-checklist" open', $output);
        self::assertLessThan(strpos($output, 'id="step-7a-supplier-po-terms"'), strpos($output, 'id="compliance-checklist"'), 'it is the first card in Step 7');
        self::assertStringContainsString('id="compliance-checklist-shipment"', $output, 'the before-shipment part sits in Step 10');
    }

    /**
     * Steps 2/3 used to need the QT marked 'sent' in-app and a BUYERPO
     * document — so an order whose quotation went out by email (QT left
     * 'draft') stayed stuck on Step 2 even after the Buyer PO stage gate
     * passed, and every page load opened and scrolled to Step 2 instead
     * of the real current step (Step 5, Proforma Invoice).
     */
    public function testStepsTwoAndThreeCountAsDoneOnceTheBuyerPoStageHasPassed(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'QT', 'draft');
        foreach ([1, 2] as $stageNumber) {
            $stage = \App\Repositories\OrderStageRepository::findByOrderAndStageNumber($orderId, $stageNumber);
            \App\Repositories\OrderStageRepository::passGate((int) $stage['id'], $userId);
        }

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertMatchesRegularExpression('/id="step-2-quotation-send">\s*<summary><span class="step-card-icon done"/', $output);
        self::assertMatchesRegularExpression('/id="step-3-quotation-accept">\s*<summary><span class="step-card-icon done"/', $output);
        self::assertStringContainsString('id="step-group-stage-3" open', $output);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }
}
