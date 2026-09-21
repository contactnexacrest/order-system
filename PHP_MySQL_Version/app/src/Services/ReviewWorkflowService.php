<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentCrossVerificationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\NotificationRepository;

/**
 * Spec Section 9 — REVIEW QUEUE / REVIEWER ASSIGNMENT / REVIEW ACTIONS /
 * CROSS-VERIFICATION. Orchestrates document_reviews + document_types.
 * min_reviewers_default + the DRAFT->FINAL watermark swap
 * (DocumentGenerationService::finalizeApproval()) — kept out of the
 * controller because "did every required reviewer approve, with none
 * pending or rejected" is real branching logic worth testing on its own,
 * not routing.
 */
final class ReviewWorkflowService
{
    /** @param int[] $reviewerIds */
    public static function assignReviewers(int $documentId, array $reviewerIds, int $assignedByUserId): void
    {
        $document = DocumentRepository::find($documentId);
        if (!$document) {
            throw new \RuntimeException("Document {$documentId} not found");
        }

        foreach (array_unique($reviewerIds) as $reviewerId) {
            DocumentReviewRepository::assign($documentId, (int) $reviewerId);
            NotificationRepository::create(
                (int) $reviewerId,
                null,
                'review_assigned',
                (int) $document['order_id'],
                "You've been assigned to review {$document['document_type_code']} {$document['document_reference']} Rev.{$document['revision_number']}."
            );
        }

        if ($document['status'] === 'draft') {
            DocumentRepository::markInReview($documentId);
        }

        AuditLogRepository::log(
            $assignedByUserId,
            'REVIEWERS_ASSIGNED',
            'documents',
            $documentId,
            'reviewers',
            null,
            implode(',', $reviewerIds)
        );
    }

    public static function approve(int $documentReviewId, int $reviewerUserId, ?string $comments): void
    {
        $review = DocumentReviewRepository::find($documentReviewId);
        if (!$review) {
            throw new \RuntimeException("Review {$documentReviewId} not found");
        }
        if ((int) $review['reviewer_id'] !== $reviewerUserId) {
            throw new \RuntimeException('You are not the assigned reviewer for this document.');
        }
        if ($review['status'] !== 'pending') {
            throw new \RuntimeException('This review has already been actioned.');
        }

        DocumentReviewRepository::approve($documentReviewId, $comments);
        AuditLogRepository::log($reviewerUserId, 'DOCUMENT_REVIEW_APPROVED', 'documents', (int) $review['document_id'], null, null, null, $comments);

        self::finalizeIfFullyApproved((int) $review['document_id']);
    }

    public static function reject(int $documentReviewId, int $reviewerUserId, string $comments): void
    {
        if ($error = ReasonValidator::check($comments)) {
            throw new \RuntimeException($error);
        }
        $review = DocumentReviewRepository::find($documentReviewId);
        if (!$review) {
            throw new \RuntimeException("Review {$documentReviewId} not found");
        }
        if ((int) $review['reviewer_id'] !== $reviewerUserId) {
            throw new \RuntimeException('You are not the assigned reviewer for this document.');
        }
        if ($review['status'] !== 'pending') {
            throw new \RuntimeException('This review has already been actioned.');
        }

        DocumentReviewRepository::reject($documentReviewId, $comments);

        $document = DocumentRepository::find((int) $review['document_id']);
        if ($document) {
            // "Document returns to creator. New version must be generated
            // and re-submitted." — back to draft; the creator regenerates
            // (a fresh call to DocumentGenerationService::generate(), a
            // new revision) once whatever was wrong is fixed.
            DocumentRepository::markDraft((int) $document['id']);
            if ($document['generated_by'] !== null) {
                NotificationRepository::create(
                    (int) $document['generated_by'],
                    null,
                    'review_rejected',
                    (int) $document['order_id'],
                    "{$document['document_type_code']} {$document['document_reference']} Rev.{$document['revision_number']} was rejected: {$comments}"
                );
            }
        }

        AuditLogRepository::log($reviewerUserId, 'DOCUMENT_REVIEW_REJECTED', 'documents', (int) $review['document_id'], null, null, null, $comments);
    }

    public static function crossVerify(int $documentId, int $verifiedByUserId, string $result, ?string $comments): void
    {
        if (!in_array($result, ['pass', 'fail'], true)) {
            throw new \RuntimeException('Cross-verification result must be pass or fail.');
        }
        DocumentCrossVerificationRepository::create($documentId, $verifiedByUserId, $result, $comments);
        AuditLogRepository::log($verifiedByUserId, 'DOCUMENT_CROSS_VERIFIED', 'documents', $documentId, 'result', null, $result, $comments);
    }

    /**
     * All required reviewers approved, none pending, none rejected ->
     * document is fully approved: swap DRAFT watermark for the final one
     * and flip documents.status. Anything short of that (still pending
     * reviewers, or the min_reviewers_default threshold not yet met)
     * leaves the document exactly as it was.
     */
    private static function finalizeIfFullyApproved(int $documentId): void
    {
        $document = DocumentRepository::find($documentId);
        if (!$document || $document['status'] === 'approved') {
            return;
        }

        $pending = DocumentReviewRepository::countPending($documentId);
        $rejected = DocumentReviewRepository::countRejected($documentId);
        $approved = DocumentReviewRepository::countApproved($documentId);
        $minRequired = (int) ($document['min_reviewers_default'] ?? 1);

        if ($pending === 0 && $rejected === 0 && $approved >= max(1, $minRequired)) {
            DocumentGenerationService::finalizeApproval($documentId);
            AuditLogRepository::log(null, 'DOCUMENT_APPROVED', 'documents', $documentId, 'status', $document['status'], 'approved');
        }
    }
}
