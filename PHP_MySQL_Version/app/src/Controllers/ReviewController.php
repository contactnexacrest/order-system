<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\DocumentCrossVerificationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\ReviewWorkflowService;

/** Spec Section 9 — Review, Approval & Cross-Verification. */
final class ReviewController
{
    /** My review queue: pending document_reviews rows assigned to me. */
    public function queue(array $params): void
    {
        $user = AuthService::currentUser();
        View::render('reviews/queue', [
            'pendingReviews' => DocumentReviewRepository::pendingForReviewer((int) $user['id']),
        ], 'layout/base');
    }

    /** Admin/privileged: assign one or more reviewers to a generated document. */
    public function assign(array $params): void
    {
        $documentId = (int) $params['documentId'];
        $document = DocumentRepository::find($documentId);
        if (!$document) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }

        $reviewerIds = array_map('intval', (array) ($_POST['reviewer_ids'] ?? []));
        $reviewerIds = array_filter($reviewerIds, static fn(int $id): bool => $id > 0);

        if (empty($reviewerIds)) {
            Flash::set('error', 'Select at least one reviewer.');
            header("Location: /orders/{$document['order_id']}");
            return;
        }

        try {
            ReviewWorkflowService::assignReviewers($documentId, $reviewerIds, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Reviewer(s) assigned — document is now in review.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header("Location: /orders/{$document['order_id']}");
    }

    public function approve(array $params): void
    {
        $reviewId = (int) $params['reviewId'];
        $review = DocumentReviewRepository::find($reviewId);
        $orderId = $review ? (DocumentRepository::find((int) $review['document_id'])['order_id'] ?? null) : null;

        try {
            ReviewWorkflowService::approve($reviewId, (int) AuthService::currentUser()['id'], trim((string) ($_POST['comments'] ?? '')) ?: null);
            Flash::set('success', 'Review approved.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header('Location: ' . ($orderId ? "/orders/{$orderId}" : '/reviews'));
    }

    public function reject(array $params): void
    {
        $reviewId = (int) $params['reviewId'];
        $review = DocumentReviewRepository::find($reviewId);
        $orderId = $review ? (DocumentRepository::find((int) $review['document_id'])['order_id'] ?? null) : null;

        try {
            ReviewWorkflowService::reject($reviewId, (int) AuthService::currentUser()['id'], trim((string) ($_POST['comments'] ?? '')));
            Flash::set('success', 'Review rejected — sent back to the creator.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header('Location: ' . ($orderId ? "/orders/{$orderId}" : '/reviews'));
    }

    public function crossVerify(array $params): void
    {
        $documentId = (int) $params['documentId'];
        $document = DocumentRepository::find($documentId);
        if (!$document) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }

        try {
            ReviewWorkflowService::crossVerify(
                $documentId,
                (int) AuthService::currentUser()['id'],
                (string) ($_POST['result'] ?? ''),
                trim((string) ($_POST['comments'] ?? '')) ?: null
            );
            Flash::set('success', 'Cross-verification recorded.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header("Location: /orders/{$document['order_id']}");
    }
}
