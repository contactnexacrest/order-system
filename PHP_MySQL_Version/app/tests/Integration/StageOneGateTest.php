<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\OrderStageRepository;
use App\Services\ReviewWorkflowService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (GATE-01 — external QA report cross-verification, Owner Decision #1:
 * "No stage passes until its document is approved"): Stage 1's real gate is
 * the QT reaching document_reviews-approved status, not merely being
 * generated as a draft. Before this fix, DocumentController::generate()
 * passed Stage 1 the instant a QT draft was rendered — a staff member could
 * generate a QT and immediately unlock Stage 2 (Buyer PO) with the
 * quotation still unreviewed, or even rejected moments later, since
 * rejection sends the document back to draft without ever re-locking Stage
 * 1.
 */
final class StageOneGateTest extends DbTestCase
{
    private function setMinReviewers(int $documentId, int $count): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT document_type_id FROM documents WHERE id = :id');
        $stmt->execute(['id' => $documentId]);
        $typeId = (int) $stmt->fetchColumn();

        $pdo->prepare('UPDATE document_types SET min_reviewers_default = :count WHERE id = :id')
            ->execute(['count' => $count, 'id' => $typeId]);
    }

    public function testStageOneStaysLockedWhileTheQtIsStillAnUnreviewedDraft(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'QT', 'draft');

        self::assertSame('in_progress', $this->stageStatus($orderId, 1), 'a freshly generated QT draft must not pass Stage 1 on its own');
        self::assertFalse(StageGateService::isUnlocked($orderId, 2), 'Stage 2 must stay locked until the QT is actually approved');
    }

    public function testStageOnePassesOnlyOnceTheQtIsFullyApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer = $this->createTestUser('Export Executive');

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];
        self::assertSame('in_progress', $this->stageStatus($orderId, 1), 'assigning a reviewer alone must not pass Stage 1 either');

        ReviewWorkflowService::approve((int) $review['id'], $reviewer, null);

        self::assertSame('gate_passed', $this->stageStatus($orderId, 1));
        self::assertTrue(StageGateService::isUnlocked($orderId, 2), 'Stage 2 must unlock once the QT is genuinely approved');
    }

    public function testStageOneRemainsLockedWhenOneOfTwoRequiredReviewersIsStillPending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $this->setMinReviewers($documentId, 2);
        $reviewer1 = $this->createTestUser('Export Executive');
        $reviewer2 = $this->createTestUser('Export Executive');

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer1, $reviewer2], 1);
        $reviews = DocumentReviewRepository::forDocument($documentId);
        ReviewWorkflowService::approve((int) $reviews[0]['id'], (int) $reviews[0]['reviewer_id'], null);

        self::assertSame('in_progress', $this->stageStatus($orderId, 1), 'one outstanding reviewer must keep Stage 1 locked even though the other approved');
    }

    public function testRejectingTheQtAfterApprovalCannotBeUsedToRepassStageOneEarly(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer = $this->createTestUser('Export Executive');

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];
        ReviewWorkflowService::reject((int) $review['id'], $reviewer, 'PHPUnit test rejection — needs corrections');

        self::assertSame('in_progress', $this->stageStatus($orderId, 1), 'a rejected QT must never have passed Stage 1');
    }

    private function stageStatus(int $orderId, int $stageNumber): string
    {
        return (string) OrderStageRepository::findByOrderAndStageNumber($orderId, $stageNumber)['status'];
    }
}
