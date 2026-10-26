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
        $this->createTestDocument($orderId, 'PI', 'approved');

        $output = $this->render($orderId);

        self::assertSame('done', $this->cardIcon($output, 'step-5a-pi-form-link'));
        self::assertSame('done', $this->cardIcon($output, 'step-5b-pi-intake-apply'));
        self::assertSame('done', $this->cardIcon($output, 'step-5c-pi-generate'));
        self::assertTrue($this->cardIsOpen($output, 'step-5d-payment-record'));
    }

    public function testOutstandingComplianceRunsAlongsideAndNeverLocksTheSteps(): void
    {
        // Owner request (2026-10-26): the checklist sits at the start of
        // Steps 7 and 10, split by phase; outstanding tasks stay amber but
        // never lock the sub-steps next to them.
        $orderId = $this->orderAtStep5();
        OrderComplianceTaskRepository::initializeForOrder($orderId);
        foreach ([3, 4, 5, 6] as $stageNumber) {
            StageGateService::passAndUnlockNext($orderId, $stageNumber, $this->userId);
        }

        $output = $this->render($orderId);

        self::assertSame('done', $this->cardIcon($output, 'step-7d-supplier-po-signed'));
        self::assertSame('current', $this->cardIcon($output, 'compliance-checklist'), 'the Step 7 checklist stays honest');
        self::assertSame('current', $this->cardIcon($output, 'compliance-checklist-shipment'));
        self::assertTrue($this->cardIsOpen($output, 'compliance-checklist-shipment'), 'Step 10 is current, so its checklist opens');
        self::assertStringNotContainsString('id="step-10a-supplier-balance-paid" data-locked', $output, 'outstanding compliance never locks 10a');
    }

    public function testASubStepWaitingOnTheOneBeforeItIsLocked(): void
    {
        // Owner rule (2026-10-26): a sub-step can't be opened until the one
        // before it is complete — e.g. the PI can't be generated until the
        // client's PI-stage form is applied or skipped.
        $orderId = $this->orderAtStep5();

        $output = $this->render($orderId);
        self::assertStringContainsString('id="step-5c-pi-generate" data-locked="Complete Step 5a first"', $output);

        \App\Repositories\OrderRepository::markPiFormSkipped($orderId, $this->userId, 'Repeat buyer, nothing changed');
        $output = $this->render($orderId);
        self::assertSame('done', $this->cardIcon($output, 'step-5b-pi-intake-apply'));
        self::assertStringNotContainsString('id="step-5c-pi-generate" data-locked', $output);
        self::assertTrue($this->cardIsOpen($output, 'step-5c-pi-generate'));
    }

    public function testMarkFullySettledStaysCurrentAfterTheOrderIsClosed(): void
    {
        // Owner report (2026-10-26): Close Order passes Stage 9's gate, which
        // used to paint all of Step 13 done — including 13g Mark Fully
        // Settled, still waiting to be done.
        $orderId = $this->orderAtStep5();
        foreach ([3, 4, 5, 6, 7, 8, 9] as $stageNumber) {
            StageGateService::passAndUnlockNext($orderId, $stageNumber, $this->userId);
        }
        \App\Config\Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $orderId]);

        $output = $this->render($orderId);

        self::assertSame('current', $this->cardIcon($output, 'step-group-stage-9'));
        self::assertSame('done', $this->cardIcon($output, 'step-11g-close-order'));
        self::assertSame('current', $this->cardIcon($output, 'step-despatch'));
        self::assertLessThan(strpos($output, 'id="step-despatch"'), strpos($output, 'id="step-11c-bl-endorsed-mark"'), '13e (endorsed) comes before 13g');
        self::assertLessThan(strpos($output, 'id="step-11c-bl-endorsed-mark"'), strpos($output, 'id="step-11f-bl-endorsement-generate"'), '13d (print) comes before 13e (endorsed)');
    }

    public function testOnlyTheSectionHoldingTheCurrentStepStartsOpen(): void
    {
        // Owner request (2026-10-26): the steps are grouped into five
        // sections, collapsed by default except the one being worked on.
        $output = $this->render($this->orderAtStep5());

        self::assertTrue($this->cardIsOpen($output, 'phase-booking'), 'Order Booking holds Step 5');
        self::assertSame('current', $this->cardIcon($output, 'phase-booking'));
        foreach (['phase-production', 'phase-shipment', 'phase-closure', 'phase-aftersales'] as $phase) {
            self::assertStringContainsString('id="' . $phase . '">', $output, "{$phase} starts collapsed");
        }
        self::assertSame('locked', $this->cardIcon($output, 'phase-production'));
        self::assertStringContainsString('available any time', $output);
        self::assertLessThan(strpos($output, 'id="phase-aftersales"'), strpos($output, 'id="step-complete"'), 'Step 19 sits in Closure & Settlement');
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
