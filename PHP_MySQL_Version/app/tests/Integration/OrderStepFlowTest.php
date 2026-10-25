<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\PiIntakeRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-25): "after refresh I must be on the same card if
 * sub tasks are there, or the next step open if the previous is completed
 * ... open the lock of one door, enter the next room". Each lettered
 * sub-step now has its own done signal; inside the current step only its
 * first unfinished sub-step starts open (the page scrolls to it), done
 * sub-steps show a tick, and a later step being done marks every earlier
 * one done — except the Compliance Checklist (8b), which stays amber with
 * its open-task count but no longer claims the page once the pipeline has
 * moved past it.
 */
final class OrderStepFlowTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }

    private function render(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    private function cardIcon(string $output, string $cardId): string
    {
        self::assertMatchesRegularExpression('/id="' . preg_quote($cardId, '/') . '"[^>]*>\s*<summary><span class="step-card-icon (\w+)"/', $output);
        preg_match('/id="' . preg_quote($cardId, '/') . '"[^>]*>\s*<summary><span class="step-card-icon (\w+)"/', $output, $m);
        return $m[1];
    }

    private function cardIsOpen(string $output, string $cardId): bool
    {
        return (bool) preg_match('/id="' . preg_quote($cardId, '/') . '" open>/', $output);
    }

    private function orderAtStep5(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'QT', 'sent');
        $this->createTestDocument($orderId, 'BUYERPO');
        StageGateService::passAndUnlockNext($orderId, 1, $this->userId);
        StageGateService::passAndUnlockNext($orderId, 2, $this->userId);
        return $orderId;
    }

    public function testAfterGeneratingThePiLinkStep5aIsTickedAndTheNextSubStepIsOpen(): void
    {
        $orderId = $this->orderAtStep5();
        PiIntakeRepository::createLink($orderId, $this->userId);

        $output = $this->render($orderId);

        self::assertTrue($this->cardIsOpen($output, 'step-group-stage-3'), 'the Step 5 box itself is open');
        self::assertSame('done', $this->cardIcon($output, 'step-5a-pi-form-link'));
        self::assertFalse($this->cardIsOpen($output, 'step-5a-pi-form-link'), 'a finished sub-step stays closed');
        self::assertSame('current', $this->cardIcon($output, 'step-5b-pi-intake-apply'));
        self::assertTrue($this->cardIsOpen($output, 'step-5b-pi-intake-apply'), 'the next unfinished sub-step is the one opened');
        self::assertSame('locked', $this->cardIcon($output, 'step-5c-pi-generate'));
        self::assertFalse($this->cardIsOpen($output, 'step-5c-pi-generate'));
    }

    public function testBeforeAnythingIsDoneInStep5OnlyItsFirstSubStepIsOpen(): void
    {
        $output = $this->render($this->orderAtStep5());

        self::assertSame('current', $this->cardIcon($output, 'step-5a-pi-form-link'));
        self::assertTrue($this->cardIsOpen($output, 'step-5a-pi-form-link'));
        self::assertFalse($this->cardIsOpen($output, 'step-5b-pi-intake-apply'));
    }

    public function testALaterSubStepDoneMarksTheEarlierOnesDone(): void
    {
        $orderId = $this->orderAtStep5();
        // PI generated without the client ever using the PI form link.
        $this->createTestDocument($orderId, 'PI');

        $output = $this->render($orderId);

        self::assertSame('done', $this->cardIcon($output, 'step-5a-pi-form-link'));
        self::assertSame('done', $this->cardIcon($output, 'step-5b-pi-intake-apply'));
        self::assertSame('done', $this->cardIcon($output, 'step-5c-pi-generate'));
        self::assertTrue($this->cardIsOpen($output, 'step-5d-payment-record'));
    }

    public function testOutstandingComplianceStaysAmberButNoLongerClaimsThePageOncePastIt(): void
    {
        $orderId = $this->orderAtStep5();
        OrderComplianceTaskRepository::initializeForOrder($orderId);
        foreach ([3, 4, 5, 6, 7] as $stageNumber) {
            StageGateService::passAndUnlockNext($orderId, $stageNumber, $this->userId);
        }

        $output = $this->render($orderId);

        self::assertStringContainsString('compliance tasks still open', $output);
        self::assertSame('current', $this->cardIcon($output, 'compliance-checklist'), '8b stays honest');
        self::assertFalse($this->cardIsOpen($output, 'compliance-checklist'), 'but the page opens the real next action instead');
        self::assertTrue($this->cardIsOpen($output, 'step-group-stage-8'), 'Step 11 (DB stage 8) is the current step');
    }

    public function testLaterStepDoneMarksEveryEarlierPipelineStepDone(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // QT still 'draft' and no BUYERPO document, but the order has been
        // walked through to Stage 4.
        $this->createTestDocument($orderId, 'QT', 'draft');
        foreach ([1, 2, 3] as $stageNumber) {
            StageGateService::passAndUnlockNext($orderId, $stageNumber, $this->userId);
        }

        $output = $this->render($orderId);

        foreach (['step-1-quotation-prepare', 'step-2-quotation-send', 'step-3-quotation-accept', 'step-group-stage-2', 'step-group-stage-3'] as $cardId) {
            self::assertSame('done', $this->cardIcon($output, $cardId), "{$cardId} must show done once a later step is done");
        }
    }

    public function testStep7AttachAcknowledgmentComesBeforeConfirmSigned(): void
    {
        $output = $this->render($this->orderAtStep5());

        $attach = strpos($output, 'id="step-7c-supplier-po-attach"');
        $confirm = strpos($output, 'id="step-7d-supplier-po-signed"');
        self::assertNotFalse($attach);
        self::assertNotFalse($confirm);
        self::assertLessThan($confirm, $attach);
    }

    /** Owner request (2026-10-25): the active step and sub-step must stand out. */
    public function testTheCurrentStepAndSubStepAreHighlighted(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');

        self::assertStringContainsString('.step-group:has(> summary > .step-card-icon.current){border:2px solid var(--gold);', $css);
        self::assertStringContainsString('.step-card:has(> summary > .step-card-icon.current){border-color:var(--gold);border-left:5px solid var(--gold);', $css);
    }
}
