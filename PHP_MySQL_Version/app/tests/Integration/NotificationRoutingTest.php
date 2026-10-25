<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\ActionNotificationService;
use App\Tests\Support\DbTestCase;

/**
 * Notification Routing Plan: ActionNotificationService::notify() must
 * route each action type to exactly one of five categories (order /
 * target / broadcast / self / none), per the user-approved classification
 * (the "Notification Routing Plan" artifact) — replacing the original
 * flat "catch-all + opted-in actor" design that fired identically for
 * every one of 230 action types regardless of what actually happened.
 */
final class NotificationRoutingTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CompanySettingsRepository::set('notification_email', '');
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('notification_email', '');
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Category classification itself
    // ---------------------------------------------------------------

    public function testCategoryForClassifiesEachOfTheFiveCategories(): void
    {
        self::assertSame('order', ActionNotificationService::categoryFor('ORDER_CREATED'));
        self::assertSame('target', ActionNotificationService::categoryFor('USER_PERMISSION_GRANTED'));
        self::assertSame('broadcast', ActionNotificationService::categoryFor('PORT_ADDED'));
        self::assertSame('broadcast', ActionNotificationService::categoryFor('ROLE_CREATED'), 'Admin/MD/ED-only oversight broadcast');
        self::assertSame('none', ActionNotificationService::categoryFor('SAMPLE_DATA_LOADED'));
        self::assertSame('self', ActionNotificationService::categoryFor('PASSWORD_CHANGED'));
        self::assertSame('self', ActionNotificationService::categoryFor('SOME_FUTURE_ACTION_TYPE_NOBODY_CLASSIFIED_YET'), 'unclassified types default to self, never none');
    }

    public function testCategoryForProtectedFieldUnlockRoutesByEntityType(): void
    {
        self::assertSame('broadcast', ActionNotificationService::categoryFor('PROTECTED_FIELD_UNLOCKED', 'payment_presets'));
        self::assertSame('broadcast', ActionNotificationService::categoryFor('PROTECTED_FIELD_UNLOCKED', 'company_settings'));
    }

    // ---------------------------------------------------------------
    // Order category — assignees + oversight + catch-all, never toggle-gated
    // ---------------------------------------------------------------

    public function testOrderCategoryNotifiesAssigneesEvenWhenTheirSelfToggleIsOff(): void
    {
        $assigneeId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($assigneeId, false);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $assigneeId);
        $assignee = UserRepository::findById($assigneeId);

        $actorId = $this->createTestUser('Logistics Executive');
        $recipients = ActionNotificationService::notify($actorId, 'ORDER_STAGE_GATE_PASSED', 'orders', $orderId, null, null, null, null);

        self::assertContains($assignee['email'], $recipients, 'the order\'s own assignee must be notified regardless of their personal toggle — order-scoped notifications are never toggleable');
    }

    public function testOrderCategoryAlsoNotifiesOversightAsASafetyNet(): void
    {
        $admin = UserRepository::findById($this->createTestUser('Admin'));
        // No $assignedTo passed — createTestOrder() leaves this order with
        // zero assignees, exactly the "nobody responsible for this order"
        // gap the oversight safety net exists for.
        $orderId = $this->createTestOrder($this->createTestClient());

        $recipients = ActionNotificationService::notify(null, 'BL_ISSUED', 'orders', $orderId, null, null, null, null);

        self::assertContains($admin['email'], $recipients, 'Admin/MD/ED oversight must be notified even when an order has zero assignees');
    }

    public function testOrderCategoryResolvesAChildRowBackToItsOwningOrderOneHop(): void
    {
        $assigneeId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $assigneeId);
        $assignee = UserRepository::findById($assigneeId);

        $documentId = $this->insertTestDocumentForOrder($orderId);

        $recipients = ActionNotificationService::notify($assigneeId, 'DOCUMENT_APPROVED', 'documents', $documentId, null, null, null, null);

        self::assertContains($assignee['email'], $recipients, 'documents.order_id must resolve back to the owning order (one-hop join)');
    }

    // ---------------------------------------------------------------
    // Target category — the specific affected person, never toggle-gated
    // ---------------------------------------------------------------

    public function testTargetCategoryNotifiesTheAffectedUserEvenWithTheirToggleOff(): void
    {
        $targetId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($targetId, false);
        $target = UserRepository::findById($targetId);
        $adminId = $this->createTestUser('Admin');

        $recipients = ActionNotificationService::notify($adminId, 'PASSWORD_ADMIN_RESET', 'users', $targetId, null, null, null, null);

        self::assertSame([$target['email']], $recipients, 'the affected user must be notified of their own password reset regardless of their toggle, with no catch-all configured');
    }

    public function testTargetCategoryGrantUsesTheExplicitHintSinceEntityIdIsTheGrantRowNotTheUser(): void
    {
        $targetId = $this->createTestUser('Export Executive');
        $target = UserRepository::findById($targetId);

        // entity_id here is deliberately a grant-row id that doesn't exist
        // as a user id (999999) — proves resolution comes from the hint,
        // not from entity_id.
        $recipients = ActionNotificationService::notify(null, 'USER_PERMISSION_GRANTED', 'user_permissions', 999999, null, null, null, null, $targetId);

        self::assertSame([$target['email']], $recipients);
    }

    public function testTargetCategoryArchiveAlsoNotifiesOversight(): void
    {
        $targetId = $this->createTestUser('Export Executive');
        $target = UserRepository::findById($targetId);
        $admin = UserRepository::findById($this->createTestUser('Admin'));

        $recipients = ActionNotificationService::notify(null, 'USER_ARCHIVED', 'users', $targetId, null, null, null, null);

        self::assertContains($target['email'], $recipients);
        self::assertContains($admin['email'], $recipients, 'USER_ARCHIVED must also reach the oversight tier');
    }

    // ---------------------------------------------------------------
    // Broadcast category — permission-key holders, never toggle-gated
    // ---------------------------------------------------------------

    public function testBroadcastCategoryNotifiesEveryoneHoldingTheGoverningPermission(): void
    {
        $exportExecId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($exportExecId, false);
        $exportExec = UserRepository::findById($exportExecId);
        $viewerId = $this->createTestUser('Viewer / Auditor');

        $recipients = ActionNotificationService::notify($exportExecId, 'PORT_ADDED', 'ports', 1, null, null, null, null);

        self::assertContains($exportExec['email'], $recipients, 'manage_orders holders (e.g. Export Executive) must be notified of a shared-data change, regardless of their own toggle');
        $viewer = UserRepository::findById($viewerId);
        self::assertNotContains($viewer['email'], $recipients, 'a role without manage_orders must not receive this broadcast');
    }

    public function testBroadcastCategoryRoutesOversightOnlyActionsToAdminMdEd(): void
    {
        $admin = UserRepository::findById($this->createTestUser('Admin'));
        $exportExec = UserRepository::findById($this->createTestUser('Export Executive'));

        $recipients = ActionNotificationService::notify(null, 'ROLE_CREATED', 'roles', 1, null, null, null, null);

        self::assertContains($admin['email'], $recipients);
        self::assertNotContains($exportExec['email'], $recipients, 'a non-oversight role must not receive an Admin/MD/ED-only broadcast');
    }

    // ---------------------------------------------------------------
    // None category — audit row written, but zero email ever attempted
    // ---------------------------------------------------------------

    public function testNoneCategoryActionTypesSendNoEmailEvenWithACatchAllConfigured(): void
    {
        CompanySettingsRepository::set('notification_email', 'ops-notify@nexacrestinternational.test');
        $userId = $this->createTestUser('Export Executive');

        \App\Repositories\AuditLogRepository::log($userId, 'SAMPLE_DATA_LOADED', 'users', $userId);
        $direct = ActionNotificationService::notify($userId, 'SAMPLE_DATA_LOADED', 'users', $userId, null, null, null, null);

        self::assertSame([], $direct, 'a "none" category action type must never send any email, even with a catch-all configured');

        $log = Database::connection()->query(
            "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'SAMPLE_DATA_LOADED'"
        )->fetch();
        self::assertGreaterThan(0, (int) $log['c'], 'the audit_log row must still be written — only the email is removed');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function insertTestDocumentForOrder(int $orderId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO documents (order_id, document_type_id, document_reference, generated_by, generated_at)
             SELECT :order_id, id, 'NOTIF-TEST-DOC', NULL, NOW() FROM document_types LIMIT 1"
        );
        $stmt->execute(['order_id' => $orderId]);
        return (int) $pdo->lastInsertId();
    }
}
