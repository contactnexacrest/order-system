<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderComplaintRepository;
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

    /** External QA report L-46: resolveEntityReference() is the DB-touching half of the subject-readability fix — see ActionNotificationServiceTest's buildMessage() tests for the pure-helper half. */
    public function testResolveEntityReferenceReturnsTheOrdersOwnReferenceNumber(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $resolved = ActionNotificationService::resolveEntityReference('orders', $orderId);

        self::assertNotNull($resolved);
        self::assertSame('Order', $resolved['label']);
        self::assertNotSame('', $resolved['reference']);
    }

    public function testResolveEntityReferenceReturnsNullForAnUnmappedEntityType(): void
    {
        self::assertNull(ActionNotificationService::resolveEntityReference('watermark_settings', 1));
    }

    public function testResolveEntityReferenceReturnsNullForAnIdThatNoLongerExists(): void
    {
        self::assertNull(ActionNotificationService::resolveEntityReference('orders', 999999999));
    }

    /**
     * External QA report N-10: 'order_lc_details' is logged with the
     * ORDER's own id as entity_id (L-23's "one row per order" convention),
     * so this must resolve against orders.order_reference directly —
     * never order_lc_details' own (nonexistent, since no row was ever
     * created here) id.
     */
    public function testResolveEntityReferenceResolvesOrderLcDetailsToTheOrdersOwnReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $resolved = ActionNotificationService::resolveEntityReference('order_lc_details', $orderId);

        self::assertNotNull($resolved);
        self::assertSame('Order', $resolved['label']);
        self::assertNotSame('', $resolved['reference']);
    }

    /**
     * External QA report N-10: 'disputes'/'order_cancellations'/
     * 'order_complaints' are logged with the CHILD row's own id as
     * entity_id — these must join back to the order that owns it.
     */
    public function testResolveEntityReferenceJoinsDisputesBackToTheirOwningOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, '2026-10-23', 'Buyer', 'N-10 test dispute', null, '2026-11-05');

        $resolved = ActionNotificationService::resolveEntityReference('disputes', $disputeId);

        self::assertNotNull($resolved);
        self::assertSame('Dispute', $resolved['label']);
        self::assertStringStartsWith('Order ', $resolved['reference']);
    }

    public function testResolveEntityReferenceJoinsOrderCancellationsBackToTheirOwningOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $cancellationId = OrderCancellationRepository::create($orderId, 'N-10 test cancellation request.', $requester);

        $resolved = ActionNotificationService::resolveEntityReference('order_cancellations', $cancellationId);

        self::assertNotNull($resolved);
        self::assertSame('Cancellation', $resolved['label']);
        self::assertStringStartsWith('Order ', $resolved['reference']);
    }

    public function testResolveEntityReferenceJoinsOrderComplaintsBackToTheirOwningOrder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'N-10 test complaint.');

        $resolved = ActionNotificationService::resolveEntityReference('order_complaints', $complaintId);

        self::assertNotNull($resolved);
        self::assertSame('Complaint', $resolved['label']);
        self::assertStringStartsWith('Order ', $resolved['reference']);
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
