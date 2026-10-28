<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\UserController;
use App\Helpers\Flash;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-33: "Delete user: when only a password-reset
 * token or notification row blocks the delete, the message wrongly says
 * the user 'has order, document, or audit history'." UserRepository::
 * delete() now clears password_reset_tokens and notifications for the
 * user first — rows with zero history value — so a user with ONLY those
 * actually gets deleted instead of being wrongly blocked, while a user
 * with REAL history (e.g. an audit_log entry) still correctly gets the
 * "has ... history" message.
 */
final class UserDeleteTransientRowsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testUserWithOnlyAResetTokenAndNotificationIsDeletedNotBlocked(): void
    {
        $actorId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $actorId;
        $targetId = $this->createTestUser('Export Executive');

        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:id, SHA2('x', 256), DATE_ADD(NOW(), INTERVAL 1 HOUR))"
        )->execute(['id' => $targetId]);
        $pdo->prepare(
            "INSERT INTO notifications (user_id, type, message) VALUES (:id, 'test', 'L-33 test notification')"
        )->execute(['id' => $targetId]);

        ob_start();
        (new UserController())->delete(['id' => (string) $targetId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type'] ?? null, 'a user with only transient rows must be deleted, not blocked');
        self::assertStringContainsString('permanently deleted', $flash[0]['message'] ?? '');

        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE id = :id');
        $stmt->execute(['id' => $targetId]);
        self::assertSame(0, (int) $stmt->fetch()['c']);
    }

    public function testUserWithRealHistoryIsStillBlockedWithTheAccurateMessage(): void
    {
        $actorId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $actorId;
        $targetId = $this->createTestUser('Export Executive');

        // Real, non-transient history: an audit_log row referencing this user.
        Database::connection()->prepare(
            "INSERT INTO audit_log (user_id, action_type, entity_type, entity_id, field_name, old_value, new_value)
             VALUES (:user_id, 'TEST_ACTION', 'users', :entity_id, NULL, NULL, 'x')"
        )->execute(['user_id' => $targetId, 'entity_id' => $targetId]);

        ob_start();
        (new UserController())->delete(['id' => (string) $targetId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type'] ?? null);
        self::assertStringContainsString('still appear in: audit log (1)', $flash[0]['message'] ?? ''); // QA Round 5, R5-09: the real blocker, named

        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM users WHERE id = :id');
        $stmt->execute(['id' => $targetId]);
        self::assertSame(1, (int) $stmt->fetch()['c'], 'a user with real history must not actually be deleted');
    }
}
