<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision 1 Nov 2026 (AS-5, guarantees 4 and 6): everything that
 * happens with an associate's clients — a history nobody edits, and the
 * notices shown in their portal. Plus the message thread with their
 * relationship manager (AS-15).
 */
final class AssociateEventRepository
{
    public static function add(int $associateId, string $event, string $message, ?int $clientId = null, ?int $orderId = null, ?int $invitationId = null, ?int $userId = null, bool $notice = true): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO associate_events (associate_id, client_id, order_id, invitation_id, event, message, user_id, is_notice)
                       VALUES (:a, :c, :o, :i, :e, :m, :u, :n)')
            ->execute(['a' => $associateId, 'c' => $clientId, 'o' => $orderId, 'i' => $invitationId, 'e' => $event, 'm' => mb_substr($message, 0, 600), 'u' => $userId, 'n' => $notice ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> newest first */
    public static function forAssociate(int $associateId, ?int $clientId = null, int $limit = 200): array
    {
        $sql = 'SELECT e.*, c.company_legal_name FROM associate_events e LEFT JOIN clients c ON c.id = e.client_id WHERE e.associate_id = :a';
        $params = ['a' => $associateId];
        if ($clientId !== null) {
            $sql .= ' AND e.client_id = :c';
            $params['c'] = $clientId;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY e.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function unreadNotices(int $associateId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM associate_events WHERE associate_id = :a AND is_notice = 1 AND read_at IS NULL');
        $stmt->execute(['a' => $associateId]);
        return (int) $stmt->fetchColumn();
    }

    public static function markNoticesRead(int $associateId): void
    {
        Database::connection()->prepare('UPDATE associate_events SET read_at = NOW() WHERE associate_id = :a AND is_notice = 1 AND read_at IS NULL')->execute(['a' => $associateId]);
    }

    public static function hasEvent(int $associateId, string $event, ?int $clientId, ?int $orderId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM associate_events WHERE associate_id = :a AND event = :e AND client_id <=> :c AND order_id <=> :o LIMIT 1');
        $stmt->execute(['a' => $associateId, 'e' => $event, 'c' => $clientId, 'o' => $orderId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function addMessage(int $associateId, bool $fromAssociate, ?int $userId, string $body): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO associate_messages (associate_id, from_associate, user_id, body) VALUES (:a, :f, :u, :b)')
            ->execute(['a' => $associateId, 'f' => $fromAssociate ? 1 : 0, 'u' => $userId, 'b' => $body]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> oldest first */
    public static function messages(int $associateId): array
    {
        $stmt = Database::connection()->prepare('SELECT m.*, u.name AS user_name FROM associate_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.associate_id = :a ORDER BY m.id');
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }
}
