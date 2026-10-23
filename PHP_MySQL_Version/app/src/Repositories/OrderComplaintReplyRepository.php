<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section BX — a complaint's own reply thread. Unlike
 * dispute_replies (staff-only), this is a genuine two-way conversation —
 * author_type/author_user_id/author_client_id, the same shape as
 * order_comments — because a Complaint is meant to be a back-and-forth
 * with the client, not an internal-only resolution log.
 */
final class OrderComplaintReplyRepository
{
    public static function createAsStaff(int $complaintId, int $authorUserId, string $body): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_complaint_replies (complaint_id, author_type, author_user_id, body)
             VALUES (:complaint_id, \'staff\', :author_user_id, :body)'
        );
        $stmt->execute(['complaint_id' => $complaintId, 'author_user_id' => $authorUserId, 'body' => $body]);
        return (int) $pdo->lastInsertId();
    }

    public static function createAsClient(int $complaintId, int $authorClientId, string $body): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_complaint_replies (complaint_id, author_type, author_client_id, body)
             VALUES (:complaint_id, \'client\', :author_client_id, :body)'
        );
        $stmt->execute(['complaint_id' => $complaintId, 'author_client_id' => $authorClientId, 'body' => $body]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * A complaint's replies, oldest first, with the author's display name
     * (staff user's name, or "You" is left to the view to render per side).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function forComplaint(int $complaintId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.name AS staff_author_name
             FROM order_complaint_replies r
             LEFT JOIN users u ON u.id = r.author_user_id
             WHERE r.complaint_id = :complaint_id
             ORDER BY r.created_at ASC, r.id ASC'
        );
        $stmt->execute(['complaint_id' => $complaintId]);
        return $stmt->fetchAll();
    }
}
