<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\ActionNotificationService;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 20 ("non-negotiable"): AuditLogRepository::log()
 * — the single choke point every meaningful action in this codebase
 * already funnels through (47+ call sites) — must fire an action
 * notification every time it's called, to the configurable catch-all
 * notification_email and to the acting user's own email when opted in.
 */
final class AuditLogNotificationHookTest extends DbTestCase
{
    public function testAuditLogRepositoryIsWiredToCallActionNotificationService(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Repositories/AuditLogRepository.php');
        self::assertStringContainsString('ActionNotificationService::notify(', $source, 'log() must call ActionNotificationService::notify() so every audit-logged action also fires a notification email');
    }

    public function testNotifyResolvesTheConfiguredCatchAllAddressAgainstRealDbState(): void
    {
        CompanySettingsRepository::set('notification_email', 'ops-notify@nexacrestinternational.test');
        $userId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($userId, false);

        $recipients = ActionNotificationService::notify($userId, 'FIELD_EDIT', 'orders', 1, 'incoterm_id', '1', '2', null);

        self::assertSame(['ops-notify@nexacrestinternational.test'], $recipients, 'opted-out user must not be in the list, but the catch-all address must still fire');

        CompanySettingsRepository::set('notification_email', '');
    }

    public function testNotifyIncludesBothTheCatchAllAndAnOptedInUsersAddress(): void
    {
        CompanySettingsRepository::set('notification_email', 'ops-notify@nexacrestinternational.test');
        $userId = $this->createTestUser('Export Executive');
        UserRepository::updateNotificationPreference($userId, true);
        $user = UserRepository::findById($userId);

        $recipients = ActionNotificationService::notify($userId, 'ORDER_CREATED', 'orders', 1, null, null, null, null);

        self::assertContains('ops-notify@nexacrestinternational.test', $recipients);
        self::assertContains($user['email'], $recipients);

        CompanySettingsRepository::set('notification_email', '');
    }

    public function testCallingLogDoesNotThrowWhenNotificationsAreFullyConfigured(): void
    {
        CompanySettingsRepository::set('notification_email', 'ops-notify@nexacrestinternational.test');
        $userId = $this->createTestUser('Export Executive');

        AuditLogRepository::log($userId, 'TEST_ACTION', 'users', $userId, null, null, null, null);

        $entries = AuditLogRepository::search(null, null, $userId, 'TEST_ACTION');
        self::assertNotEmpty($entries, 'log() must still insert the audit_log row exactly as before, notification hook aside');

        CompanySettingsRepository::set('notification_email', '');
    }
}
