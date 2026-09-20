<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * In-app notification queue (spec Section 10 "AUTO-NOTIFICATIONS" + Section
 * 9 "System notifies assigned reviewers"). Deliberately not tied to email —
 * this is the bell-icon queue a logged-in user sees; the deferred-send
 * email pipeline (EmailLogRepository) is separate and only fires for
 * buyer-facing document sends.
 */
final class NotificationRepository
{
    public static function create(?int $userId, ?int $roleId, string $type, ?int $relatedOrderId, string $message): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, role_id, type, related_order_id, message)
             VALUES (:user_id, :role_id, :type, :related_order_id, :message)'
        );
        $stmt->execute([
            'user_id'          => $userId,
            'role_id'          => $roleId,
            'type'             => $type,
            'related_order_id' => $relatedOrderId,
            'message'          => $message,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function unreadCountForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_id = :user_id AND is_read = 0'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetch()['c'];
    }

    public static function markRead(int $id, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id'
        )->execute(['id' => $id, 'user_id' => $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0'
        )->execute(['user_id' => $userId]);
    }

    /**
     * Dedup guard for check_alerts.php: without this, a daily cron re-fires
     * the same "LUT expires in N days" / "dispute overdue" notification
     * every single run for as long as the underlying condition stays true,
     * flooding the recipient's bell with same-day duplicates. One
     * notification per (user, type, related_order_id) per calendar day is
     * enough — the condition is still visible in the bell until resolved,
     * it just isn't re-announced hourly/daily.
     */
    public static function existsToday(int $userId, string $type, ?int $relatedOrderId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM notifications
             WHERE user_id = :user_id AND type = :type
               AND related_order_id <=> :related_order_id
               AND DATE(created_at) = CURDATE()'
        );
        $stmt->execute(['user_id' => $userId, 'type' => $type, 'related_order_id' => $relatedOrderId]);
        return ((int) $stmt->fetch()['c']) > 0;
    }
}
