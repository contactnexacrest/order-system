<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;

/**
 * User-reported batch item 20 ("non-negotiable"): fires an email for
 * every audit-logged action in the system. Hooked into
 * AuditLogRepository::log() — the single choke point every meaningful
 * state-changing action in this codebase already funnels through (47+
 * call sites across every controller/service) — rather than requiring a
 * separate instrumentation pass per action.
 *
 * Two independent recipients per action: the configurable
 * company_settings.notification_email catch-all (always, when set —
 * never opt-out-able, per the explicit "non-negotiable" instruction),
 * and the acting user's own email, gated by their own per-user
 * users.email_action_notifications_enabled toggle (Account >
 * Notifications, default on) — the "configurable option" the same
 * instruction separately asked for.
 *
 * Deliberately fire-and-forget, exactly like every other informational
 * email already in this codebase (ClientIntakeController's correction
 * link, OrderController's PI-stage/Buyer Action links): a notification
 * failure must never break the action it's reporting on, and
 * EmailService::sendPlainText() already degrades safely (Test Mode
 * block, no-SMTP log-only fallback) with no exception on its own.
 */
final class ActionNotificationService
{
    /**
     * Called from AuditLogRepository::log() after its own Test-Mode
     * exclusion check, so this never fires for a test order/client.
     *
     * @return string[] the recipient email addresses actually attempted — exposed for testability
     */
    public static function notify(
        ?int $userId,
        string $actionType,
        ?string $entityType,
        ?int $entityId,
        ?string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?string $reason
    ): array {
        $user = $userId !== null ? UserRepository::findById($userId) : null;
        $recipients = self::resolveRecipients($user);
        if ($recipients === []) {
            return [];
        }

        $entityReference = self::resolveEntityReference($entityType, $entityId);
        $message = self::buildMessage($user, $actionType, $entityType, $entityId, $fieldName, $oldValue, $newValue, $reason, $entityReference);
        foreach ($recipients as $recipient) {
            try {
                EmailService::sendPlainText($recipient, $message['subject'], $message['body']);
            } catch (\Throwable $e) {
                // Never let a notification failure break the action it's reporting on.
            }
        }

        return $recipients;
    }

    /** @param array<string,mixed>|null $user
     * @return string[] */
    public static function resolveRecipients(?array $user): array
    {
        $recipients = [];

        $notificationEmail = trim((string) (CompanySettingsRepository::get('notification_email') ?? ''));
        if ($notificationEmail !== '') {
            $recipients[] = $notificationEmail;
        }

        if ($user && (int) ($user['email_action_notifications_enabled'] ?? 1) === 1 && !empty($user['email'])) {
            $recipients[] = $user['email'];
        }

        return array_values(array_unique($recipients));
    }

    /**
     * External QA report L-46: action-notification subjects/bodies used
     * the raw table name + numeric id ("Login Success — Users #37",
     * "Reviewers Assigned — Documents #231") — unreadable without opening
     * the record. Looks up a human-friendly reference for the handful of
     * entity types that have one (orders.order_reference,
     * documents.document_reference, a name/title column, etc.); any
     * entity type not in this whitelist, or a row that no longer exists
     * (e.g. since deleted), falls straight back to buildMessage()'s own
     * "#id" formatting — this never throws and never blocks the action it
     * reports on, matching notify()'s own fire-and-forget contract.
     *
     * @return array{0: string, 1: string, 2: string}|null [table, column, singular label]
     */
    private static function entityReferenceLookup(string $entityType): ?array
    {
        return match ($entityType) {
            'order', 'orders' => ['orders', 'order_reference', 'Order'],
            'documents' => ['documents', 'document_reference', 'Document'],
            'clients' => ['clients', 'company_legal_name', 'Client'],
            'users' => ['users', 'name', 'User'],
            'roles' => ['roles', 'name', 'Role'],
            'permissions' => ['permissions', 'name', 'Permission'],
            'products' => ['products', 'name', 'Product'],
            'ports' => ['ports', 'name', 'Port'],
            'document_types' => ['document_types', 'name', 'Document Type'],
            'compliance_task_types' => ['compliance_task_types', 'name', 'Compliance Task Type'],
            'logistics_partners' => ['logistics_partners', 'partner_name', 'Logistics Partner'],
            default => null,
        };
    }

    /** @return array{label: string, reference: string}|null */
    public static function resolveEntityReference(?string $entityType, ?int $entityId): ?array
    {
        if ($entityType === null || $entityId === null) {
            return null;
        }
        $lookup = self::entityReferenceLookup($entityType);
        if ($lookup === null) {
            return null;
        }
        [$table, $column, $label] = $lookup;
        try {
            $stmt = Database::connection()->prepare("SELECT {$column} AS reference FROM {$table} WHERE id = :id");
            $stmt->execute(['id' => $entityId]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            return null;
        }
        $reference = trim((string) ($row['reference'] ?? ''));
        return $reference !== '' ? ['label' => $label, 'reference' => $reference] : null;
    }

    /**
     * @param array<string,mixed>|null $user
     * @param array{label: string, reference: string}|null $entityReference see resolveEntityReference() above
     * @return array{subject: string, body: string}
     */
    public static function buildMessage(
        ?array $user,
        string $actionType,
        ?string $entityType,
        ?int $entityId,
        ?string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?string $reason,
        ?array $entityReference = null
    ): array {
        $actionLabel = ucwords(strtolower(str_replace('_', ' ', $actionType)));
        $entityLabel = $entityType !== null ? (ucfirst($entityType) . ($entityId !== null ? " #{$entityId}" : '')) : null;
        if ($entityReference !== null) {
            $entityLabel = $entityReference['label'] . ' ' . $entityReference['reference'];
        }

        $subject = 'NexaCrest — ' . $actionLabel . ($entityLabel !== null ? " — {$entityLabel}" : '');

        $actorName = $user['name'] ?? 'System';

        $lines = ["Action: {$actionLabel}", "By: {$actorName}"];
        if ($entityLabel !== null) {
            $lines[] = "On: {$entityLabel}";
        }
        if ($fieldName !== null) {
            $lines[] = "Field: {$fieldName}";
        }
        if ($oldValue !== null || $newValue !== null) {
            $lines[] = 'Change: ' . ($oldValue ?? '—') . ' -> ' . ($newValue ?? '—');
        }
        if ($reason !== null && $reason !== '') {
            $lines[] = "Reason: {$reason}";
        }
        $body = implode("\n", $lines) . "\n\nNexaCrest International Private Limited — automated action notification.";

        return ['subject' => $subject, 'body' => $body];
    }
}
