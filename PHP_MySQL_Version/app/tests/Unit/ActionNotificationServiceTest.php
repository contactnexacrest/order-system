<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\ActionNotificationService;
use PHPUnit\Framework\TestCase;

/**
 * User-reported batch item 20 ("non-negotiable"): the system must email
 * a notification for every action, to a configurable catch-all
 * notification_email AND to the acting user's own email when they've
 * opted in. Drives the two pure helper methods directly — resolveRecipients()
 * and buildMessage() — rather than notify() itself, since notify()
 * touches the DB (CompanySettingsRepository/UserRepository) and actually
 * calls EmailService; that round-trip is covered separately by
 * AuditLogNotificationHookTest.
 */
final class ActionNotificationServiceTest extends TestCase
{
    public function testResolveRecipientsReturnsEmptyWhenNothingIsConfigured(): void
    {
        self::assertSame([], ActionNotificationService::resolveRecipients(null));
    }

    public function testResolveRecipientsIncludesTheOptedInUsersEmail(): void
    {
        $user = ['email' => 'jane@nexacrestinternational.com', 'email_action_notifications_enabled' => 1];
        self::assertSame(['jane@nexacrestinternational.com'], ActionNotificationService::resolveRecipients($user));
    }

    public function testResolveRecipientsExcludesAnOptedOutUser(): void
    {
        $user = ['email' => 'jane@nexacrestinternational.com', 'email_action_notifications_enabled' => 0];
        self::assertSame([], ActionNotificationService::resolveRecipients($user));
    }

    public function testResolveRecipientsDefaultsToOptedInWhenTheColumnIsMissing(): void
    {
        $user = ['email' => 'jane@nexacrestinternational.com'];
        self::assertSame(['jane@nexacrestinternational.com'], ActionNotificationService::resolveRecipients($user), 'default-on, matching the column\'s own DEFAULT 1');
    }

    public function testBuildMessageHumanizesTheActionTypeAndIncludesTheChange(): void
    {
        $user = ['name' => 'Jane Staff'];
        $message = ActionNotificationService::buildMessage($user, 'FIELD_EDIT', 'orders', 42, 'incoterm_id', '1', '2', 'Buyer changed terms');

        self::assertStringContainsString('Field Edit', $message['subject']);
        self::assertStringContainsString('Orders #42', $message['subject']);
        self::assertStringContainsString('By: Jane Staff', $message['body']);
        self::assertStringContainsString('Field: incoterm_id', $message['body']);
        self::assertStringContainsString('Change: 1 -> 2', $message['body']);
        self::assertStringContainsString('Reason: Buyer changed terms', $message['body']);
    }

    public function testBuildMessageFallsBackToSystemWhenNoUserPerformedTheAction(): void
    {
        $message = ActionNotificationService::buildMessage(null, 'ALERT_RAISED', null, null, null, null, null, null);

        self::assertStringContainsString('By: System', $message['body']);
    }

    /**
     * External QA report L-46: "action-notification subjects use internal
     * labels ('Login Success — Users #37', 'Reviewers Assigned —
     * Documents #231')". When a resolved human-friendly reference is
     * passed in (resolveEntityReference() — covered against a real DB in
     * AuditLogNotificationHookTest), buildMessage() must use it instead of
     * the raw table-name-plus-id.
     */
    public function testBuildMessageUsesAResolvedEntityReferenceWhenGiven(): void
    {
        $user = ['name' => 'Jane Staff'];
        $message = ActionNotificationService::buildMessage(
            $user,
            'REVIEWERS_ASSIGNED',
            'documents',
            231,
            null,
            null,
            null,
            null,
            ['label' => 'Document', 'reference' => 'SC/QT/2026/1234']
        );

        self::assertStringContainsString('Document SC/QT/2026/1234', $message['subject']);
        self::assertStringNotContainsString('Documents #231', $message['subject']);
        self::assertStringContainsString('On: Document SC/QT/2026/1234', $message['body']);
    }

    public function testBuildMessageFallsBackToTheRawLabelWhenNoReferenceResolved(): void
    {
        $message = ActionNotificationService::buildMessage(
            ['name' => 'Jane Staff'],
            'LOGIN_SUCCESS',
            'users',
            37,
            null,
            null,
            null,
            null,
            null
        );

        self::assertStringContainsString('Users #37', $message['subject']);
    }
}
