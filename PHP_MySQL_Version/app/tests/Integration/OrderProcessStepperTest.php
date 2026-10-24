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
     * Batch 3 #14 — the stepper is clickable: each step carries
     * data-stage="N". The order-detail-page rebuild (step-card sequence,
     * further split into one card per individual numbered/lettered step —
     * nothing grouped) replaced every stage's own data-stage-carrying
     * `<div class="section">` with several `<details class="step-card">`
     * step-cards per DB stage — the stepper's click-to-jump script now
     * resolves a clicked stage number to its full list of card ids via
     * its own `stageToCardId` lookup object instead of a shared data-stage
     * attribute on the target. This pins that every stage number the
     * stepper exposes still resolves to real step-card ids present in the
     * page (kept in lock-step with the same object in show.php's own
     * script).
     */
    public function testStepperStepsAndTheirTargetPanelsCarryMatchingDataStageAttributes(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        $stageToCardId = [
            1 => ['step-1-quotation-prepare', 'step-2-quotation-send', 'step-3-quotation-accept', 'document-review-approval'],
            2 => ['step-4a-buyer-po-generate', 'step-4b-buyer-po-confirm', 'step-4c-buyer-po-attach'],
            3 => ['step-5a-pi-generate', 'step-5b-advance-record', 'step-5c-advance-clear', 'step-4d-pi-intake-link', 'step-4e-pi-intake-apply', 'step-5-lc-bank-loan'],
            4 => ['step-6a-oc-generate', 'step-6b-oc-acknowledge', 'step-6c-buyer-action-link'],
            5 => ['step-7a-supplier-po-terms', 'step-7b-supplier-po-generate', 'step-7c-supplier-po-signed', 'step-7d-supplier-po-attach'],
            6 => ['step-9a-freight-terms', 'step-9b-freight-debit-note', 'step-9c-freight-remittance', 'step-9d-freight-clear'],
            7 => ['step-9e-packing', 'step-9f-packing-list', 'step-9g-shipping-details', 'step-9h-bl-instruction', 'step-9i-bl-issued'],
            8 => ['step-ci-generate', 'step-ci-scanned-bl-sent', 'step-balance-record', 'step-balance-clear'],
            9 => ['step-11a-coo-prep', 'step-11b-bl-originals-received', 'step-11c-bl-endorsed-mark', 'step-11d-bl-endorsement-save', 'step-11f-bl-endorsement-generate', 'step-11g-close-order', 'step-despatch'],
        ];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $stage) {
            self::assertStringContainsString("role=\"button\" tabindex=\"0\" data-stage=\"{$stage}\"", $output, "stepper step {$stage} must be focusable and carry data-stage");
            foreach ($stageToCardId[$stage] as $cardId) {
                self::assertStringContainsString("id=\"{$cardId}\"", $output, "a step-card carrying id=\"{$cardId}\" must exist for the stepper to jump to for stage {$stage}");
            }
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
