<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * In-app notification queue (spec Section 10 "AUTO-NOTIFICATIONS" + Section
 * 9 "System notifies assigned reviewers"). The deferred-send email
 * pipeline (EmailLogRepository) is separate and only fires for buyer-
 * facing document sends.
 *
 * External QA report M-06: "In-app notifications also sent by e-mail."
 * create() itself fans out to the targeted user's own email (same
 * users.email_action_notifications_enabled toggle — "does this user want
 * notification emails") rather than instrumenting each of this method's
 * call sites separately. Since 2026-10-26 this is the only notification
 * email channel: the audit log no longer mails anything, so a notification
 * is created only where a specific person needs to know or act.
 * Fire-and-forget: an email failure must never break the action that
 * triggered the notification.
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
        $id = (int) $pdo->lastInsertId();

        if ($userId !== null) {
            self::emailNotification($userId, $message);
        }

        return $id;
    }

    /**
     * Something the client did on an order that staff now need to act on
     * (acknowledged the OC, uploaded their PO, reported a payment):
     * notifies that order's own assignees — or, on an order nobody is
     * assigned to yet, everyone who manages orders, so it is never missed.
     *
     * @return int[] the user ids notified
     */
    public static function notifyOrderTeam(int $orderId, string $type, string $message): array
    {
        $userIds = array_map(static fn (array $a): int => (int) $a['user_id'], OrderAssigneeRepository::forOrder($orderId));
        if ($userIds === []) {
            $userIds = PermissionRepository::usersWithPermission('manage_orders');
        }
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        foreach ($userIds as $userId) {
            self::create($userId, null, $type, $orderId, $message);
        }
        return $userIds;
    }

    /**
     * @return string|null the email address actually attempted — null
     *   when the user doesn't exist, has no email, or has opted out.
     *   Exposed (and non-void) purely for testability.
     */
    public static function emailNotification(int $userId, string $message): ?string
    {
        $user = UserRepository::findById($userId);
        if (!$user || (int) ($user['email_action_notifications_enabled'] ?? 1) !== 1 || empty($user['email'])) {
            return null;
        }
        try {
            \App\Services\EmailService::sendPlainText($user['email'], self::emailSubject($message), $message);
        } catch (\Throwable $e) {
            // Never let a notification-email failure break the action it's reporting on.
        }
        return $user['email'];
    }

    /**
     * The notification itself as the subject (not a generic "New
     * Notification"), so a busy inbox shows at a glance which order and
     * what needs doing.
     */
    public static function emailSubject(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $message));
        if (mb_strlen($line) > 110) {
            $line = rtrim(mb_substr($line, 0, 107)) . '…';
        }
        return 'NexaCrest — ' . $line;
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
