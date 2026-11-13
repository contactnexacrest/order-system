<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DocumentLabel;
use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentCrossVerificationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;

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
        // QA batch 3 DOC-7: a document already sent to the buyer (or superseded)
        // is never pulled back into review — an approved, not-yet-sent one may
        // still get a second look before it goes out.
        if (in_array($document['status'], ['sent', 'superseded'], true)) {
            throw new \RuntimeException('Reviewers cannot be assigned to this document — it is already ' . $document['status'] . '.');
        }

        // QA-5: validate every requested reviewer up front (all-or-nothing)
        // before inserting any — a partial insert followed by a thrown
        // exception would leave some reviewers assigned and others not,
        // for reasons the caller never sees.
        $existingReviewerIds = array_map(
            static fn(array $r): int => (int) $r['reviewer_id'],
            DocumentReviewRepository::forDocument($documentId)
        );
        $generatedBy = $document['generated_by'] !== null ? (int) $document['generated_by'] : null;

        $toAssign = [];
        foreach (array_unique(array_map('intval', $reviewerIds)) as $reviewerId) {
            if (in_array($reviewerId, $existingReviewerIds, true)) {
                // Already has a review row on this document (pending,
                // approved or rejected) — assigning them again would leave
                // a second 'pending' row nobody is ever prompted to
                // resolve, permanently stalling finalizeIfFullyApproved()'s
                // pending-count check (REV-07).
                continue;
            }
            $reviewer = UserRepository::findById($reviewerId);
            if (!$reviewer) {
                throw new \RuntimeException("User {$reviewerId} not found.");
            }
            if (!$reviewer['is_active']) {
                // REV-06: an inactive user (e.g. deactivated after leaving)
                // can no longer log in to ever act on this assignment,
                // which would otherwise stall the document in review
                // forever.
                throw new \RuntimeException("{$reviewer['name']} is not an active user and cannot be assigned as a reviewer.");
            }
            if ($generatedBy !== null && $reviewerId === $generatedBy && !MakerCheckerGuard::selfApprovalAllowed('document_review', $reviewerId)) {
                // QA-5 maker-checker (REV-05); owner decisions 1 Nov 2026 (A-2,
                // then D-221): the document's own generator reviews it only
                // when one of their roles is ticked in Admin → Self-approval rules.
                throw new \RuntimeException("This document's own generator cannot be assigned as its reviewer — a different person must review it.");
            }
            $toAssign[] = $reviewerId;
        }

        foreach ($toAssign as $reviewerId) {
            DocumentReviewRepository::assign($documentId, $reviewerId);
            NotificationRepository::create(
                $reviewerId,
                null,
                'review_assigned',
                (int) $document['order_id'],
                'You\'ve been assigned to review ' . DocumentLabel::for($document['document_type_code'], $document['document_reference'], (int) $document['order_id']) . " Rev.{$document['revision_number']}."
            );
        }

        if (!empty($toAssign)) {
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
                implode(',', $toAssign)
            );
        }
    }

    /** HNT-004: order statuses on which a document review can no longer be approved. */
    private const DEAD_ORDER_STATUSES = ['lost', 'cancelled'];
    private const DEAD_ORDER_STATUSES_LABEL = ['lost' => 'marked lost', 'cancelled' => 'cancelled'];

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

        $document = DocumentRepository::find((int) $review['document_id']);
        if ($document && $document['order_id'] !== null) {
            // A newer revision of this (order, type) may have been generated
            // while this review sat pending (e.g. a reviewer was slow and the
            // creator regenerated to fix something reported out-of-band) —
            // approving a stale revision at this point would immediately get
            // superseded again by finalizeIfFullyApproved() below the moment
            // the real latest revision is later approved, which is confusing
            // at best. Block it outright: only the current latest revision
            // of a document may ever be approved.
            $latest = DocumentRepository::findLatestForOrderAndType((int) $document['order_id'], (int) $document['document_type_id']);
            if ($latest && (int) $latest['id'] !== (int) $document['id']) {
                throw new \RuntimeException('A newer revision of this document has since been generated — this one can no longer be approved. Review the latest revision instead.');
            }
            // HNT-004: once the order is lost or cancelled, nothing on it is approved (no
            // document becomes "sent", no D-342 prices are fixed). Rejecting stays possible.
            $order = \App\Repositories\OrderRepository::find((int) $document['order_id']);
            if ($order && in_array((string) $order['status'], self::DEAD_ORDER_STATUSES, true)) {
                throw new \RuntimeException('This order is ' . self::DEAD_ORDER_STATUSES_LABEL[(string) $order['status']] . ' — its documents can no longer be approved. Reject the review if it should be closed.');
            }
        }

        // Owner decision D-221: a self-review is allowed only while the rule allows it, and is logged as such.
        $selfReview = $document && $document['generated_by'] !== null && (int) $document['generated_by'] === $reviewerUserId;
        if ($selfReview && !MakerCheckerGuard::selfApprovalAllowed('document_review', $reviewerUserId)) {
            throw new \RuntimeException("This document's own generator cannot be assigned as its reviewer — a different person must review it.");
        }
        DocumentReviewRepository::approve($documentReviewId, $comments);
        AuditLogRepository::log($reviewerUserId, 'DOCUMENT_REVIEW_APPROVED', 'documents', (int) $review['document_id'], null, null, null, $comments);
        if ($selfReview) {
            AuditLogRepository::log($reviewerUserId, 'DOCUMENT_SELF_REVIEWED', 'documents', (int) $review['document_id'], null, null, 'approved by its own generator', 'Allowed by Admin → Self-approval rules.');
        }

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
        // QA batch 3 DOC-7: a sent or superseded document is never pulled back to draft.
        if ($document && !in_array($document['status'], ['sent', 'superseded'], true)) {
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
                    DocumentLabel::for($document['document_type_code'], $document['document_reference'], (int) $document['order_id']) . " Rev.{$document['revision_number']} was rejected: {$comments}"
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
        // QA batch 3 DOC-7: an approved, sent or superseded document is never re-finalised.
        if (!$document || in_array($document['status'], ['approved', 'sent', 'superseded'], true)) {
            return;
        }

        $pending = DocumentReviewRepository::countPending($documentId);
        $rejected = DocumentReviewRepository::countRejected($documentId);
        $approved = DocumentReviewRepository::countApproved($documentId);
        $minRequired = (int) ($document['min_reviewers_default'] ?? 1);

        if ($pending === 0 && $rejected === 0 && $approved >= max(1, $minRequired)) {
            DocumentGenerationService::finalizeApproval($documentId);
            AuditLogRepository::log(null, 'DOCUMENT_APPROVED', 'documents', $documentId, 'status', $document['status'], 'approved');

            // Point 1 (2026-10-01): this revision is now THE valid copy of
            // this (order, type) — any other revision still sitting as
            // 'approved'/'sent' from an earlier pass through review is now
            // stale and must be invalidated, both in status and visibly on
            // the PDF itself, or staff/buyers could keep relying on an old
            // copy with no way to tell it apart from the current one.
            DocumentGenerationService::supersedeOtherApprovedRevisions($documentId);
            EmailDispatchService::markSentIfMailDisabled($documentId);

            // GATE-01: the QT's approval — not its mere draft generation —
            // is Stage 1's real gate (Owner Decision #1: "No stage passes
            // until its document is approved"). With email sending on, owner
            // decision 2026-10-25: only once it's actually sent —
            // EmailDispatchService::dispatch() passes it then instead.
            if ($document['document_type_code'] === 'QT'
                && !DocumentGenerationService::stageDocumentBlockedBy((int) $document['order_id'], 1)) {
                StageGateService::passAndUnlockNext((int) $document['order_id'], 1, null);
            }
        }
    }
}
