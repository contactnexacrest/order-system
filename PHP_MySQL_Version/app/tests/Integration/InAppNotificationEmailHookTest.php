<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report M-06: "In-app notifications also sent by e-mail."
 * NotificationRepository::create() must also email the targeted user,
 * gated by the same users.email_action_notifications_enabled toggle
 * ActionNotificationService's own, differently-sourced emails already use
 * (see AuditLogNotificationHookTest for that half) — a second, independent
 * trigger for the same per-user preference, not a new toggle.
 */
final class InAppNotificationEmailHookTest extends DbTestCase
{
    public function testEmailNotificationSendsToAnOptedInUser(): void
    {
        $userId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($userId, true);
        $user = UserRepository::findById($userId);

        $attempted = NotificationRepository::emailNotification($userId, 'Something happened.');

        self::assertSame($user['email'], $attempted);
    }

    public function testEmailNotificationSkipsAnOptedOutUser(): void
    {
        $userId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($userId, false);

        $attempted = NotificationRepository::emailNotification($userId, 'Something happened.');

        self::assertNull($attempted);
    }

    public function testEmailNotificationDefaultsToOptedInWhenNeverSet(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $user = UserRepository::findById($userId);

        $attempted = NotificationRepository::emailNotification($userId, 'Something happened.');

        self::assertSame($user['email'], $attempted, 'default-on, matching the column\'s own DEFAULT 1');
    }

    public function testCreateFiresTheEmailHookForAUserTargetedNotification(): void
    {
        $userId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($userId, false);
        $orderId = $this->createTestOrder($this->createTestClient());

        // Doesn't throw, and the in-app row is still inserted exactly as
        // before this fix — the opted-out user just isn't emailed.
        $id = NotificationRepository::create($userId, null, 'review_assigned', $orderId, 'You have been assigned to review a document.');

        $rows = NotificationRepository::forUser($userId);
        self::assertNotEmpty($rows);
        self::assertSame($id, (int) $rows[0]['id']);
    }

    public function testCreateNeverThrowsWhenNoUserIsTargeted(): void
    {
        // A role-only notification (userId null) has no single user to
        // email — create() must simply skip the email hook, not error.
        $id = NotificationRepository::create(null, 1, 'role_notice', null, 'Role-wide notice.');

        self::assertGreaterThan(0, $id);
    }
}
