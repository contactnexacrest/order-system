<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\UserController;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * Phase 3 item 10 of the approved remediation plan: "User 3-way status
 * filter (active/inactive/archive) + delete-if-unreferenced (only a
 * 2-way active/inactive toggle exists)." Archive is a third, distinct
 * state from a merely deactivated account. Delete is a real DELETE,
 * left to MySQL's own FK constraints (57+ tables reference users.id,
 * all ON DELETE RESTRICT) to decide "is this user referenced anywhere"
 * rather than a hand-maintained check.
 */
final class UserArchiveAndDeleteTest extends DbTestCase
{
    private function callAsAdmin(string $action, array $params): void
    {
        $actorId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $actorId;
        ob_start();
        (new UserController())->$action($params);
        ob_end_clean();
    }

    public function testStatusCountsReflectsActiveInactiveAndArchivedBuckets(): void
    {
        $activeUser = $this->createTestUser('Admin');
        $inactiveUser = $this->createTestUser('Admin');
        UserRepository::setActive($inactiveUser, false);
        $archivedUser = $this->createTestUser('Admin');
        $this->callAsAdmin('archive', ['id' => (string) $archivedUser]);

        $counts = UserRepository::statusCounts();

        self::assertGreaterThanOrEqual(1, $counts['active']);
        self::assertGreaterThanOrEqual(1, $counts['inactive']);
        self::assertGreaterThanOrEqual(1, $counts['archived']);

        $activeList = array_column(UserRepository::listAllForAdmin('active'), 'id');
        $inactiveList = array_column(UserRepository::listAllForAdmin('inactive'), 'id');
        $archivedList = array_column(UserRepository::listAllForAdmin('archived'), 'id');

        self::assertContains($activeUser, $activeList);
        self::assertContains($inactiveUser, $inactiveList);
        self::assertContains($archivedUser, $archivedList);
        self::assertNotContains($archivedUser, $activeList);
        self::assertNotContains($archivedUser, $inactiveList);
    }

    public function testArchivingAUserForcesThemInactiveAndRecordsWhoAndWhen(): void
    {
        $targetId = $this->createTestUser('Admin');
        $actorId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $actorId;

        ob_start();
        (new UserController())->archive(['id' => (string) $targetId]);
        ob_end_clean();

        $target = UserRepository::findById($targetId);
        self::assertSame(1, (int) $target['is_archived']);
        self::assertSame(0, (int) $target['is_active'], 'archiving must force the account inactive too');
        self::assertSame($actorId, (int) $target['archived_by']);
        self::assertNotNull($target['archived_at']);
    }

    public function testUnarchiveClearsArchiveFieldsButLeavesTheAccountInactive(): void
    {
        $targetId = $this->createTestUser('Admin');
        $this->callAsAdmin('archive', ['id' => (string) $targetId]);

        $this->callAsAdmin('unarchive', ['id' => (string) $targetId]);

        $target = UserRepository::findById($targetId);
        self::assertSame(0, (int) $target['is_archived']);
        self::assertNull($target['archived_at']);
        self::assertNull($target['archived_by']);
        self::assertSame(0, (int) $target['is_active'], 'unarchiving must not silently reactivate the login');
    }

    public function testArchiveRefusesASuperAdminOrProtectedAccount(): void
    {
        $protectedId = $this->createTestUser('Admin');
        \App\Config\Database::connection()->prepare(
            'UPDATE users SET is_protected_account = 1 WHERE id = :id'
        )->execute(['id' => $protectedId]);

        $this->callAsAdmin('archive', ['id' => (string) $protectedId]);

        $target = UserRepository::findById($protectedId);
        self::assertSame(0, (int) $target['is_archived'], 'a protected account must never be archived');
    }

    public function testDeleteSucceedsForAUserWithNoReferencingHistory(): void
    {
        $targetId = $this->createTestUser('Admin');

        $this->callAsAdmin('delete', ['id' => (string) $targetId]);

        self::assertNull(UserRepository::findById($targetId), 'an unreferenced user must actually be gone');
    }

    public function testDeleteRefusesAUserWithAuditLogHistoryAndLeavesThemIntact(): void
    {
        $targetId = $this->createTestUser('Admin');
        // Gives this user real audit_log history as the actor of an
        // action — audit_log.user_id is a real FK to users(id), ON
        // DELETE RESTRICT, so this alone must block the delete.
        AuditLogRepository::log($targetId, 'LOGIN_SUCCESS', 'users', $targetId);

        $this->callAsAdmin('delete', ['id' => (string) $targetId]);

        self::assertNotNull(UserRepository::findById($targetId), 'a referenced user must survive the failed delete attempt, not end up half-deleted');
    }
}
