<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** docs/schema.sql Section AI — order progress chat thread. */
final class OrderCommentRepository
{
    public static function create(int $orderId, string $authorType, ?int $authorUserId, ?int $authorClientId, ?string $body): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_comments (order_id, author_type, author_user_id, author_client_id, body)
             VALUES (:order_id, :author_type, :author_user_id, :author_client_id, :body)'
        );
        $stmt->execute([
            'order_id'         => $orderId,
            'author_type'      => $authorType,
            'author_user_id'   => $authorUserId,
            'author_client_id' => $authorClientId,
            'body'             => $body,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function attachFile(int $commentId, int $fileStoreId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO order_comment_attachments (comment_id, file_store_id) VALUES (:comment_id, :file_store_id)'
        );
        $stmt->execute(['comment_id' => $commentId, 'file_store_id' => $fileStoreId]);
    }

    /**
     * Ownership check for a comment-attachment download link: confirms
     * $fileStoreId is actually attached to a comment on $orderId before
     * either the staff or client download route serves it.
     */
    public static function findAttachmentForOrder(int $fileStoreId, int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT f.* FROM order_comment_attachments a
             JOIN order_comments c ON c.id = a.comment_id
             JOIN file_store f ON f.id = a.file_store_id
             WHERE a.file_store_id = :file_store_id AND c.order_id = :order_id
             LIMIT 1'
        );
        $stmt->execute(['file_store_id' => $fileStoreId, 'order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function markEmailSent(int $commentId): void
    {
        self::recordEmailStatus($commentId, 'sent');
    }

    /**
     * D-268: what happened to a staff post's client e-mail — 'sent',
     * 'sending_off' (Admin switched e-mail sending off), 'no_email' (no
     * client e-mail on file) or 'failed' (the send itself failed).
     */
    public static function recordEmailStatus(int $commentId, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE order_comments SET email_status = :status, email_sent = :sent WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'sent' => $status === 'sent' ? 1 : 0, 'id' => $commentId]);
    }

    /**
     * D-268: the note staff see beside a staff post in Order Communication,
     * giving the true reason when the client was not e-mailed. A client's
     * own post carries no note. A post saved before D-268 has no recorded
     * reason, so it only says it was not e-mailed.
     */
    public static function emailNote(array $comment): string
    {
        if (($comment['author_type'] ?? '') !== 'staff') {
            return '';
        }
        if ((int) ($comment['email_sent'] ?? 0) === 1) {
            return 'e-mailed';
        }
        return match ($comment['email_status'] ?? null) {
            'sending_off' => 'not e-mailed — e-mail sending is switched off in Admin',
            'no_email'    => 'not e-mailed — no client e-mail on file',
            'failed'      => 'not e-mailed — the e-mail could not be sent',
            default       => 'not e-mailed',
        };
    }

    /**
     * The full thread for an order, oldest first (chat reads top-to-bottom),
     * each comment carrying its author's display name/role and its
     * attachments (joined against file_store for original filename/mime
     * type/size — enough for the view to decide image vs video vs plain
     * download link).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function forOrder(int $orderId): array
    {
        $comments = Database::connection()->prepare(
            "SELECT c.*,
                    u.name AS staff_name,
                    cl.company_legal_name AS client_name
             FROM order_comments c
             LEFT JOIN users u ON u.id = c.author_user_id
             LEFT JOIN clients cl ON cl.id = c.author_client_id
             WHERE c.order_id = :order_id
             ORDER BY c.created_at ASC, c.id ASC"
        );
        $comments->execute(['order_id' => $orderId]);
        $rows = $comments->fetchAll();
        if (!$rows) {
            return [];
        }

        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $attStmt = Database::connection()->prepare(
            "SELECT a.comment_id, f.id AS file_id, f.original_filename, f.mime_type, f.file_size_bytes
             FROM order_comment_attachments a
             JOIN file_store f ON f.id = a.file_store_id
             WHERE a.comment_id IN ({$placeholders})
             ORDER BY a.id ASC"
        );
        $attStmt->execute($ids);
        $byComment = [];
        foreach ($attStmt->fetchAll() as $att) {
            $byComment[(int) $att['comment_id']][] = $att;
        }

        foreach ($rows as &$row) {
            $row['attachments'] = $byComment[(int) $row['id']] ?? [];
            $row['email_note'] = self::emailNote($row);
        }
        return $rows;
    }
}
