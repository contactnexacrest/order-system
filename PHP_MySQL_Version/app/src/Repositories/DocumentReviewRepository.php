<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 9 — "REVIEWER ASSIGNMENT" / "REVIEW ACTIONS". One row per
 * (document, assigned reviewer). A document can have several — all must
 * reach 'approved' (with zero left 'pending' and none 'rejected') before
 * ReviewWorkflowService considers the document itself approved.
 */
final class DocumentReviewRepository
{
    public static function assign(int $documentId, int $reviewerId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO document_reviews (document_id, reviewer_id, status) VALUES (:document_id, :reviewer_id, \'pending\')'
        );
        $stmt->execute(['document_id' => $documentId, 'reviewer_id' => $reviewerId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT dr.*, u.name AS reviewer_name
             FROM document_reviews dr JOIN users u ON u.id = dr.reviewer_id
             WHERE dr.document_id = :document_id
             ORDER BY dr.assigned_at'
        );
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM document_reviews WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Reviews still awaiting action by a specific reviewer, across all orders. */
    public static function pendingForReviewer(int $reviewerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT dr.*, d.order_id, d.document_reference, d.revision_number, dt.code AS document_type_code, dt.name AS document_type_name
             FROM document_reviews dr
             JOIN documents d ON d.id = dr.document_id
             JOIN document_types dt ON dt.id = d.document_type_id
             WHERE dr.reviewer_id = :reviewer_id AND dr.status = \'pending\'
             ORDER BY dr.assigned_at'
        );
        $stmt->execute(['reviewer_id' => $reviewerId]);
        return $stmt->fetchAll();
    }

    public static function approve(int $id, ?string $comments): void
    {
        Database::connection()->prepare(
            "UPDATE document_reviews SET status = 'approved', comments = :comments, reviewed_at = NOW() WHERE id = :id"
        )->execute(['comments' => $comments, 'id' => $id]);
    }

    public static function reject(int $id, string $comments): void
    {
        Database::connection()->prepare(
            "UPDATE document_reviews SET status = 'rejected', comments = :comments, reviewed_at = NOW() WHERE id = :id"
        )->execute(['comments' => $comments, 'id' => $id]);
    }

    public static function countPending(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS c FROM document_reviews WHERE document_id = :document_id AND status = 'pending'"
        );
        $stmt->execute(['document_id' => $documentId]);
        return (int) $stmt->fetch()['c'];
    }

    public static function countApproved(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS c FROM document_reviews WHERE document_id = :document_id AND status = 'approved'"
        );
        $stmt->execute(['document_id' => $documentId]);
        return (int) $stmt->fetch()['c'];
    }

    public static function countRejected(int $documentId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS c FROM document_reviews WHERE document_id = :document_id AND status = 'rejected'"
        );
        $stmt->execute(['document_id' => $documentId]);
        return (int) $stmt->fetch()['c'];
    }
}
