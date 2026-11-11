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
    /**
     * Owner decision 2 Nov 2026 (D-233): an in-app notification only — for a
     * caller that sends its own fuller e-mail (the Zoho sync failure list),
     * so the person never gets the same news twice.
     */
    public static function createInAppOnly(int $userId, string $type, ?int $relatedOrderId, string $message): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO notifications (user_id, role_id, type, related_order_id, message) VALUES (:u, NULL, :t, :o, :m)')
            ->execute(['u' => $userId, 't' => $type, 'o' => $relatedOrderId, 'm' => $message]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * D-280: $email false = in-app only for this person (routing decided elsewhere,
     * e.g. notifyOrderTeam()). The e-mail itself also follows Admin › E-mail
     * settings (the type's kind, see EmailPolicy::NOTIFICATION_KINDS) and the
     * person's own "e-mail me my notifications" switch (not for MUST alerts).
     */
    public static function create(?int $userId, ?int $roleId, string $type, ?int $relatedOrderId, string $message, bool $email = true): int
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

        if ($userId !== null && $email) {
            self::emailNotification($userId, $message, $type);
        }

        return $id;
    }

    /**
     * Something the buyer / supplier did on an order that staff now need to act on
     * (acknowledged the OC, uploaded their PO, reported a payment).
     *
     * In-app: the order's assignees — or, on an order nobody is assigned to yet,
     * everyone who manages orders — so it is never missed (as before).
     * E-mail (owner decision D-281, 11 Nov 2026): only the people of the event's
     * department on that order (StaffRouting::forOrder) — its department owner
     * and the department's assignees; with none, the department's manager with an
     * "assign someone" note; with no manager, the MD. The MD, ED and Order
     * Coordinator get the in-app notice only.
     *
     * @return int[] the user ids notified in-app
     */
    public static function notifyOrderTeam(int $orderId, string $type, string $message): array
    {
        $userIds = array_map(static fn (array $a): int => (int) $a['user_id'], OrderAssigneeRepository::forOrder($orderId));
        if ($userIds === []) {
            $userIds = PermissionRepository::usersWithPermission('manage_orders');
        }
        $route = \App\Services\StaffRouting::forOrder($orderId, \App\Services\StaffRouting::departmentFor($type));
        $emailIds = $route['email'];
        $userIds = array_values(array_unique(array_map('intval', array_merge($userIds, $emailIds))));
        foreach ($userIds as $userId) {
            $isRouted = in_array($userId, $emailIds, true);
            $text = $isRouted && $route['note'] !== null ? $message . ' ' . $route['note'] : $message;
            self::create($userId, null, $type, $orderId, $text, $isRouted);
        }
        return $userIds;
    }

    /**
     * D-281: an alert whose in-app list and e-mail list differ (check_alerts,
     * compliance reminders): everyone in $inAppIds gets the notice, only
     * $emailIds (a subset or extra people) also get the e-mail.
     *
     * @param list<int> $inAppIds
     * @param list<int> $emailIds
     * @return list<int> everyone notified
     */
    public static function notifyRouted(array $inAppIds, array $emailIds, string $type, ?int $relatedOrderId, string $message): array
    {
        $emailIds = array_values(array_unique(array_map('intval', $emailIds)));
        $all = array_values(array_unique(array_map('intval', array_merge($inAppIds, $emailIds))));
        foreach ($all as $userId) {
            self::create($userId, null, $type, $relatedOrderId, $message, in_array($userId, $emailIds, true));
        }
        return $all;
    }

    /**
     * @return string|null the email address actually attempted — null
     *   when the user doesn't exist, has no email, or has opted out.
     *   Exposed (and non-void) purely for testability.
     */
    public static function emailNotification(int $userId, string $message, string $type = ''): ?string
    {
        // D-280: the type's row in Admin › E-mail settings (MUST alerts always go;
        // the person's own opt-out applies only to the others).
        $kind = \App\Services\EmailPolicy::kindForNotification($type);
        if (!\App\Services\EmailPolicy::allows($kind, 'staff')) {
            return null;
        }
        $user = UserRepository::findById($userId);
        $optedOut = (int) ($user['email_action_notifications_enabled'] ?? 1) !== 1 && !\App\Services\EmailPolicy::isMust($kind, 'staff');
        if (!$user || $optedOut || empty($user['email']) || (int) ($user['is_active'] ?? 1) !== 1) {
            return null;
        }
        try {
            \App\Services\EmailService::sendPlainText($user['email'], self::emailSubject($message), self::emailBody($message, $kind), false, $kind, 'staff');
        } catch (\Throwable $e) {
            // Never let a notification-email failure break the action it's reporting on.
        }
        return $user['email'];
    }

    /**
     * D-310: an approval e-mail ends with the link to the Approvals page, where
     * the item waits with a button to the exact approval screen.
     */
    public static function emailBody(string $message, string $kind): string
    {
        if ($kind !== 'staff_approvals') {
            return $message;
        }
        return $message . "\n\nOpen your approvals: " . rtrim((string) \App\Config\Env::get('APP_URL', ''), '/') . '/approvals';
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
