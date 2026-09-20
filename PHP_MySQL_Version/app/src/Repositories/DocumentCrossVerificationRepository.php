<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 9 — "CROSS-VERIFICATION": a separate, additional quality
 * layer any team member with permission can add to any document, distinct
 * from the formal reviewer sign-off (document_reviews) and never gating
 * whether the document counts as "approved".
 */
final class DocumentCrossVerificationRepository
{
    public static function create(int $documentId, int $verifiedBy, string $result, ?string $comments): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO document_cross_verifications (document_id, verified_by, result, comments)
             VALUES (:document_id, :verified_by, :result, :comments)'
        );
        $stmt->execute([
            'document_id' => $documentId,
            'verified_by' => $verifiedBy,
            'result'      => $result,
            'comments'    => $comments,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cv.*, u.name AS verified_by_name
             FROM document_cross_verifications cv JOIN users u ON u.id = cv.verified_by
             WHERE cv.document_id = :document_id
             ORDER BY cv.verified_at DESC'
        );
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll();
    }
}
