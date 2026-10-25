<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;

/**
 * Notification Routing Plan (supersedes the original "User-reported batch
 * item 20" design, which fired the same two recipients — a configurable
 * catch-all and the acting user's own opted-in email — for every one of
 * 230 distinct audit-logged action types with zero differentiation. That
 * meant a genuinely critical transactional notification (a QT/PI sent to
 * a client, a client-side action, a payment, a permission change) was
 * drowned out by routine noise (logins, Sample Data Playground loads),
 * defeating the whole point of the feature).
 *
 * Still hooked into AuditLogRepository::log() — the single choke point
 * every meaningful state-changing action in this codebase already funnels
 * through — but each action type now routes to exactly one of five
 * categories, decided with the user action by action:
 *
 *   - order:     that order's own assignees (order_assignees) + the
 *                Admin/MD/ED oversight tier (a safety net for an
 *                unassigned order) + the catch-all. Never toggleable —
 *                "part of the application," per the user's own words.
 *   - target:    the one specific person the action is about (their
 *                permissions changed, their password was reset) + the
 *                catch-all. Also never toggleable — missing one of these
 *                reads as a bug ("till yesterday I could see this").
 *   - broadcast: everyone holding the permission key that actually
 *                governs the shared data that changed (ports, HS codes,
 *                presets, ...) + the catch-all. Never toggleable.
 *   - self:      routine, about the actor's own activity only — the one
 *                category where the acting user's personal
 *                email_action_notifications_enabled toggle still applies.
 *                Exactly the original resolveRecipients() behaviour.
 *   - none:      no email at all. The audit_log row is still written by
 *                AuditLogRepository::log() exactly as before — only the
 *                notification email is removed, for the types that were
 *                pure noise (sample data, test mode, logins, ...).
 *
 * Deliberately fire-and-forget, exactly like every other informational
 * email already in this codebase: a notification failure must never
 * break the action it's reporting on, and EmailService::sendPlainText()
 * already degrades safely (Test Mode block, no-SMTP log-only fallback)
 * with no exception on its own.
 */
final class ActionNotificationService
{
    /**
     * Action types resolved as order-scoped: entityType/entityId (see
     * resolveOrderId() below) identifies the order, and every one of that
     * order's own assignees — never just the acting user — must be
     * notified, because the whole point of this tier is "nobody
     * responsible for this order misses it."
     */
    private const ORDER_ACTION_TYPES = [
        'ORDER_CREATED', 'ORDER_DUPLICATED', 'ORDER_FULLY_SETTLED', 'ORDER_MARKED_LOST',
        'ORDER_ARCHIVED', 'ORDER_UNARCHIVED', 'ORDER_EDITED_POST_CONFIRMATION', 'ORDER_EDITED_WHILE_LOCKED',
        'ORDER_STAGE_GATE_PASSED', 'ORDER_STAGE_GATE_SKIPPED', 'BL_ISSUED',
        'PRODUCTION_STATUS_UPDATED', 'EST_SHIPMENT_DATE_UPDATED',
        'ORDER_FREIGHT_TERMS_SAVED', 'ORDER_PACKING_SAVED', 'ORDER_SHIPPING_DETAILS_SAVED',
        'ORDER_BL_COPY_ATTACHED', 'ORDER_SCANNED_BL_SENT', 'ORDER_BL_ORIGINALS_RECEIVED',
        'ORDER_BL_ENDORSED', 'ORDER_BL_ENDORSEMENT_GENERATED', 'ORDER_BL_ENDORSEMENT_SAVED',
        'ORDER_INSPECTION_FEE_SAVED', 'ORDER_TRAVEL_EXPENSE_SAVED',
        'ORDER_COST_ENTRY_RECORDED', 'ORDER_COST_ENTRY_DELETED',
        'ORDER_LC_DETAILS_SAVED', 'LC_DETAILS_RECORDED', 'LC_CONFIRMED_USABLE',
        'ORDER_BUYER_PO_DOCUMENT_UPLOADED', 'OC_ACKNOWLEDGED_VIA_EMAIL', 'BUYER_OC_ACKNOWLEDGED', 'BUYER_PO_UPLOADED_BY_CLIENT',
        'ORDER_ADDITIONAL_DOCUMENT_ADDED', 'ORDER_ADDITIONAL_DOCUMENT_REMOVED', 'ORDER_COMMENT_POSTED',
        'SUPPLIER_PO_SAVED', 'SUPPLIER_PO_SIGNED', 'SUPPLIER_PO_DOCUMENT_UPLOADED',
        'SUPPLIER_ADVANCE_PAID', 'SUPPLIER_BALANCE_PAID',
        'DRAFT_BL_UPLOADED', 'DRAFT_BL_REVIEWED',
        'DOCUMENT_APPROVED', 'DOCUMENT_CROSS_VERIFIED', 'DOCUMENT_SUPERSEDED',
        'DOCUMENT_REVIEW_APPROVED', 'DOCUMENT_REVIEW_REJECTED',
        'EMAIL_SENT', 'EMAIL_SEND_APPROVED', 'EMAIL_SEND_AUTO_APPROVED', 'EMAIL_SEND_REQUESTED',
        'EMAIL_SEND_REJECTED', 'EMAIL_SEND_CANCELLED', 'EMAIL_SEND_FORCED_NOW',
        'AMENDMENT_REQUESTED', 'AMENDMENT_ACTIVATED', 'AMENDMENT_MD_APPROVED', 'AMENDMENT_REJECTED', 'AMENDMENT_DOCUMENT_GENERATED',
        'ANNEXURE_INCLUDE_TOGGLED', 'ANNEXURE_MODE_UPDATED', 'ANNEXURE_TERMS_UPDATED',
        'ANNEXURE_PRODUCT_CREATED', 'ANNEXURE_PRODUCT_UPDATED', 'ANNEXURE_PRODUCT_DELETED',
        'ANNEXURE_IMAGE_UPLOADED', 'ANNEXURE_IMAGE_REMOVED',
        'CLOSE_ORDER_DOCUMENT_GATE_OVERRIDE', 'LOCK_OVERRIDE',
        'ORDER_CANCELLATION_REQUESTED', 'ORDER_CANCELLATION_APPROVED', 'ORDER_CANCELLATION_REJECTED', 'ORDER_CANCELLATION_REFUND_COMPLETED',
        'REORDER_REQUEST_SUBMITTED', 'REORDER_REQUEST_REJECTED',
        'COMPLIANCE_TASK_STATUS_UPDATED', 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED',
        'CA_EXCHANGE_RATE_RECORDED', 'CA_FIRC_RECORDED', 'CA_EXPORT_BENEFIT_RECORDED', 'CA_EXPORT_BENEFIT_RECEIVED',
        'CA_INR_ACTUAL_RECORDED', 'CA_INR_ACTUAL_DELETED',
        'COMPLAINT_RAISED', 'COMPLAINT_REPLY_POSTED', 'COMPLAINT_STATUS_CHANGED',
        'DISPUTE_RAISED', 'DISPUTE_REPLY_POSTED', 'DISPUTE_STATUS_CHANGED', 'DISPUTE_BUTTON_VISIBILITY_CHANGED', 'DISPUTE_DOCUMENT_UPLOADED',
        'ORDER_FEEDBACK_SUBMITTED',
        'CLIENT_PAYMENT_REPORTED', 'CLIENT_PAYMENT_REPORT_REVIEWED', 'CLIENT_PORTAL_PROVISIONED', 'BUYER_ACTION_LINK_GENERATED',
        'ADVANCE_PAYMENT_RECORDED', 'ADVANCE_PAYMENT_CLEARED', 'BALANCE_PAYMENT_RECORDED', 'BALANCE_PAYMENT_CLEARED', 'FREIGHT_PAYMENT_RECORDED',
        'PI_INTAKE_SUBMITTED', 'PI_INTAKE_REJECTED', 'PI_INTAKE_LINK_GENERATED',
    ];

    /** Action types resolved as "about one specific person" — see resolveTargetRecipients(). */
    private const TARGET_ACTION_TYPES = [
        'USER_PERMISSION_GRANTED', 'USER_PERMISSION_REVOKED', 'ROLE_PERMISSIONS_UPDATED',
        'USER_ARCHIVED', 'USER_UNARCHIVED', 'USER_CREATED', 'PASSWORD_ADMIN_RESET',
        'SIGNATORY_USER_ASSET_UPLOADED', 'SIGNATORY_USER_ASSET_DEACTIVATED',
        'SUPER_ADMIN_DELEGATION_GRANTED', 'SUPER_ADMIN_DELEGATION_REVOKED',
    ];

    /** Target types where the oversight (Admin/MD/ED) tier is ALSO notified, on top of the affected person. */
    private const TARGET_ALSO_NOTIFY_OVERSIGHT = [
        'USER_ARCHIVED', 'USER_UNARCHIVED', 'SUPER_ADMIN_DELEGATION_GRANTED', 'SUPER_ADMIN_DELEGATION_REVOKED',
    ];

    /**
     * Broadcast action types with a fixed permission key: shared/reference
     * data that everyone holding that permission relies on day to day.
     */
    private const BROADCAST_PERMISSION_BY_ACTION_TYPE = [
        'PORT_ADDED' => 'manage_orders', 'PORT_UPDATED' => 'manage_orders', 'PORT_DEFAULT_CHANGED' => 'manage_orders',
        'PORT_ROUTE_ADDED' => 'manage_orders', 'PORT_ROUTE_UPDATED' => 'manage_orders',
        'DROPDOWN_OPTION_ADDED' => 'manage_orders', 'DROPDOWN_OPTION_UPDATED' => 'manage_orders', 'DROPDOWN_OPTION_DEFAULT_CHANGED' => 'manage_orders',
        'HOLIDAY_ADDED' => 'manage_orders', 'HOLIDAY_REMOVED' => 'manage_orders',
        'CLIENT_INTAKE_SUBMITTED' => 'manage_orders', 'CLIENT_INTAKE_SUBMISSION_UPDATED_BY_CLIENT' => 'manage_orders',

        'HS_CODE_ADDED' => 'manage_hs_codes', 'HS_CODE_UPDATED' => 'manage_hs_codes', 'HS_CODE_DELETED' => 'manage_hs_codes',
        'HS_CODE_TOGGLED' => 'manage_hs_codes', 'HS_CODE_BULK_IMPORTED' => 'manage_hs_codes', 'HS_CODE_GUIDE_IMPORTED' => 'manage_hs_codes',

        'PAYMENT_PRESET_ADDED' => 'manage_payment_presets', 'PAYMENT_PRESET_UPDATED' => 'manage_payment_presets',

        'LOGISTICS_PARTNER_ADDED' => 'manage_logistics_partners', 'LOGISTICS_PARTNER_UPDATED' => 'manage_logistics_partners',
        'LOGISTICS_PARTNER_DELETED' => 'manage_logistics_partners',

        'SUPPLIER_CREATED' => 'manage_product_catalog', 'SUPPLIER_FACTORY_ADDRESS_CREATED' => 'manage_product_catalog',
        'SUPPLIER_FACTORY_ADDRESS_DELETED' => 'manage_product_catalog',
        'PRODUCT_CREATED' => 'manage_product_catalog', 'PRODUCT_UPDATED' => 'manage_product_catalog', 'PRODUCT_DELETED' => 'manage_product_catalog',
        'PRODUCT_IMAGE_UPLOADED' => 'manage_product_catalog', 'PRODUCT_IMAGE_DELETED' => 'manage_product_catalog',
        'PRODUCT_MISC_CHARGE_ADDED' => 'manage_product_catalog', 'PRODUCT_MISC_CHARGE_DELETED' => 'manage_product_catalog',
        'PRODUCT_SUPPLIER_ADDED' => 'manage_product_catalog', 'PRODUCT_SUPPLIER_DELETED' => 'manage_product_catalog',
        'PRODUCT_SUPPLIER_SET_PRIMARY' => 'manage_product_catalog', 'PRODUCT_SUPPLIER_UPDATED' => 'manage_product_catalog',

        'COMPLIANCE_TASK_TYPE_ADDED' => 'manage_compliance_task_types', 'COMPLIANCE_TASK_TYPE_UPDATED' => 'manage_compliance_task_types',
        'COMPLIANCE_TASK_TYPE_DELETED' => 'manage_compliance_task_types',

        'EMAIL_TEMPLATE_CREATED' => 'manage_email_templates', 'EMAIL_TEMPLATE_UPDATED' => 'manage_email_templates',
        'EMAIL_TEMPLATE_TOGGLED_ACTIVE' => 'manage_email_templates',

        'WATERMARK_SETTINGS_UPDATED' => 'manage_assets', 'WATERMARK_ASSET_DELETED' => 'manage_assets', 'WATERMARK_ASSET_REPLACED' => 'manage_assets',

        'DOCUMENT_TYPE_SIGNATORY_SET' => 'manage_signatories', 'GLOBAL_DEFAULT_SIGNATORY_SET' => 'manage_signatories',
        'SIGNATORY_DESIGNATION_CREATED' => 'manage_signatories', 'SIGNATORY_DESIGNATION_DELETED' => 'manage_signatories',
        'SIGNATORY_DESIGNATION_RENAMED' => 'manage_signatories', 'SIGNATORY_DESIGNATION_TOGGLED' => 'manage_signatories',

        'REFERENCE_LIBRARY_CATEGORY_CREATED' => 'manage_company_settings', 'REFERENCE_LIBRARY_CATEGORY_UPDATED' => 'manage_company_settings',
        'REFERENCE_LIBRARY_CATEGORY_DELETED' => 'manage_company_settings', 'REFERENCE_LIBRARY_DOC_CREATED' => 'manage_company_settings',
        'REFERENCE_LIBRARY_DOC_UPDATED' => 'manage_company_settings', 'REFERENCE_LIBRARY_DOC_DELETED' => 'manage_company_settings',
        'REFERENCE_DOC_UPDATED' => 'manage_company_settings',
    ];

    /**
     * Broadcast action types routed to the Admin/MD/ED oversight tier
     * specifically (narrower than any of the permission keys above) —
     * via the 'assign_order_owner' permission, which (see
     * docs/seed.sql's role_permissions cross-join) is already granted to
     * exactly Admin/Managing Director/Executive Director and nobody
     * else, making it the existing, already-correct proxy for this
     * tier — no new permission key needed, and no hardcoded role name.
     */
    private const OVERSIGHT_PERMISSION_KEY = 'assign_order_owner';

    private const OVERSIGHT_ONLY_ACTION_TYPES = [
        'CLIENT_AGREEMENT_FORCE_EXPIRED', 'CLIENT_IMPERSONATION_STARTED', 'CLIENT_IMPERSONATION_ENDED',
        'CA_FINANCIAL_YEAR_LOCKED', 'CA_FINANCIAL_YEAR_UNLOCKED', 'CA_FY_LOCK_OVERRIDDEN',
        'ROLE_CREATED', 'ROLE_UPDATED', 'ROLE_DELETED', 'PERMISSION_CREATED', 'PERMISSION_UPDATED', 'PERMISSION_DELETED',
        'USER_DELETED',
    ];

    /** Action types that get no email at all — the audit_log row is still written by AuditLogRepository::log(), unchanged. */
    private const NONE_ACTION_TYPES = [
        'SAMPLE_DATA_LOADED', 'SAMPLE_DATA_CLEARED',
        'TEST_MODE_ENABLED', 'TEST_MODE_DISABLED', 'TEST_DATA_DELETED', 'TEST_EMAIL_UPDATED',
        'SETTINGS_TEST_SMTP_EMAIL_SENT', 'TEST_ZOHO_EMAIL_SENT',
        'LOGIN_SUCCESS', 'LOGOUT', 'CLIENT_LOGIN_SUCCESS', 'CLIENT_LOGOUT',
        'PERMISSION_DENIED', 'FIELD_EDIT',
        'ACCOUNT_NOTIFICATION_PREFERENCE_UPDATED', 'SIGNATURE_UPDATED',
        'UI_TEMPLATE_PREFERENCE_UPDATED', 'UI_TEMPLATE_TOGGLED_ACTIVE',
        'CA_ZOHO_SYNC_RUN', 'CLIENT_PASSWORD_CHANGED', 'CLIENT_PASSWORD_RESET_REQUESTED',
    ];

    /** entityType values where entityId IS the order's own id — no join needed. */
    private const DIRECT_ORDER_ENTITY_TYPES = [
        'orders', 'order', 'order_freight', 'order_shipping', 'order_packing', 'order_payment_status',
        'order_lc_details', 'order_bl_endorsements', 'order_inspection_fees', 'order_travel_expenses',
    ];

    /** entityType => column name holding the order id, one join away. */
    private const ONE_HOP_ORDER_ENTITY_TYPES = [
        'documents' => 'order_id', 'email_log' => 'order_id', 'amendments' => 'order_id',
        'order_cost_entries' => 'order_id', 'order_buyer_po_documents' => 'order_id', 'file_store' => 'order_id',
        'order_supplier_po' => 'order_id', 'order_draft_bl_reviews' => 'order_id',
        'order_cancellations' => 'order_id', 'order_complaints' => 'order_id', 'disputes' => 'order_id',
        'client_payment_reports' => 'order_id', 'pi_intake_submissions' => 'order_id',
        'order_annexure_products' => 'order_id', 'ca_export_benefits' => 'order_id', 'order_products' => 'order_id',
        'order_reorder_requests' => 'source_order_id',
    ];

    /** entityType => [column pointing to the parent row's id, parent table, parent's order-id column]. */
    private const TWO_HOP_ORDER_ENTITY_TYPES = [
        'order_supplier_po_documents' => ['order_supplier_po_id', 'order_supplier_po', 'order_id'],
        'dispute_documents' => ['dispute_id', 'disputes', 'order_id'],
        'order_annexure_images' => ['order_annexure_product_id', 'order_annexure_products', 'order_id'],
    ];

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
        ?string $reason,
        ?int $notifyTargetUserId = null
    ): array {
        $category = self::categoryFor($actionType, $entityType);
        if ($category === 'none') {
            return [];
        }

        $user = $userId !== null ? UserRepository::findById($userId) : null;
        $recipients = match ($category) {
            'order' => self::resolveOrderRecipients($entityType, $entityId),
            'target' => self::resolveTargetRecipients($actionType, $entityId, $notifyTargetUserId),
            'broadcast' => self::resolveBroadcastRecipients($actionType, $entityType),
            default => self::resolveRecipients($user),
        };
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

    /**
     * Which of the five Notification Routing Plan categories a given
     * action type falls into. $entityType is only consulted for the two
     * field-protection action types, whose real category depends on
     * which kind of record was unlocked (see docblock on
     * broadcastPermissionKey() below) — every other action type's
     * category is a fixed, context-free lookup.
     */
    public static function categoryFor(string $actionType, ?string $entityType = null): string
    {
        if ($actionType === 'PROTECTED_FIELD_UNLOCKED' || $actionType === 'PROTECTION_FLAG_REQUESTED') {
            return 'broadcast';
        }
        if (in_array($actionType, self::NONE_ACTION_TYPES, true)) {
            return 'none';
        }
        if (in_array($actionType, self::ORDER_ACTION_TYPES, true)) {
            return 'order';
        }
        if (in_array($actionType, self::TARGET_ACTION_TYPES, true)) {
            return 'target';
        }
        if (isset(self::BROADCAST_PERMISSION_BY_ACTION_TYPE[$actionType]) || in_array($actionType, self::OVERSIGHT_ONLY_ACTION_TYPES, true)) {
            return 'broadcast';
        }
        // Everything else (routine self-activity, and any action type not
        // yet classified) keeps the original toggleable self+catch-all
        // behaviour — the conservative default, never silently "none".
        return 'self';
    }

    /** @return string[] */
    private static function resolveOrderRecipients(?string $entityType, ?int $entityId): array
    {
        $recipients = [];
        $orderId = self::resolveOrderId($entityType, $entityId);
        if ($orderId !== null) {
            foreach (OrderAssigneeRepository::forOrder($orderId) as $assignee) {
                if (!empty($assignee['email'])) {
                    $recipients[] = $assignee['email'];
                }
            }
        }
        $recipients = array_merge($recipients, self::oversightEmails(), self::catchAllRecipients());
        return array_values(array_unique($recipients));
    }

    /**
     * Resolves entityType/entityId down to the order id it belongs to —
     * directly, one join away (a child row with its own order_id), or two
     * joins away (a grandchild row whose own parent carries the
     * order_id) — covering every order-scoped action type's actual
     * logging shape in this codebase. Returns null (never throws) for
     * anything unresolvable, so resolveOrderRecipients() still falls back
     * to the oversight tier + catch-all rather than silently emailing
     * nobody.
     */
    public static function resolveOrderId(?string $entityType, ?int $entityId): ?int
    {
        if ($entityType === null || $entityId === null) {
            return null;
        }

        if (in_array($entityType, self::DIRECT_ORDER_ENTITY_TYPES, true)) {
            return $entityId;
        }

        if (isset(self::ONE_HOP_ORDER_ENTITY_TYPES[$entityType])) {
            $column = self::ONE_HOP_ORDER_ENTITY_TYPES[$entityType];
            return self::fetchOrderIdColumn($entityType, $column, $entityId);
        }

        if (isset(self::TWO_HOP_ORDER_ENTITY_TYPES[$entityType])) {
            [$viaColumn, $parentTable, $parentColumn] = self::TWO_HOP_ORDER_ENTITY_TYPES[$entityType];
            $viaId = self::fetchOrderIdColumn($entityType, $viaColumn, $entityId);
            return $viaId !== null ? self::fetchOrderIdColumn($parentTable, $parentColumn, $viaId) : null;
        }

        return null;
    }

    private static function fetchOrderIdColumn(string $table, string $column, int $id): ?int
    {
        try {
            $stmt = Database::connection()->prepare("SELECT {$column} AS resolved_id FROM {$table} WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            return null;
        }
        return $row && $row['resolved_id'] !== null ? (int) $row['resolved_id'] : null;
    }

    /**
     * "About one specific person" recipient resolution. Most of these
     * action types log the affected user's own id directly as entity_id;
     * two (grant/revoke of a per-user permission override) need the
     * explicit $notifyTargetUserId hint instead, because the grant row
     * entity_id refers to may already be deleted (revoke) or never
     * carried the target user's id in the first place (grant logs the
     * override row's own id, not the user's).
     *
     * @return string[]
     */
    private static function resolveTargetRecipients(string $actionType, ?int $entityId, ?int $notifyTargetUserId): array
    {
        $targetUserIds = match ($actionType) {
            'USER_PERMISSION_GRANTED', 'USER_PERMISSION_REVOKED' => $notifyTargetUserId !== null ? [$notifyTargetUserId] : [],
            'ROLE_PERMISSIONS_UPDATED' => self::userIdsByRoleId($entityId),
            'USER_CREATED', 'USER_ARCHIVED', 'USER_UNARCHIVED', 'PASSWORD_ADMIN_RESET',
            'SUPER_ADMIN_DELEGATION_GRANTED', 'SUPER_ADMIN_DELEGATION_REVOKED' => $entityId !== null ? [$entityId] : [],
            'SIGNATORY_USER_ASSET_UPLOADED', 'SIGNATORY_USER_ASSET_DEACTIVATED' => self::signatoryOwnerUserId($entityId),
            default => [],
        };

        $recipients = [];
        foreach ($targetUserIds as $uid) {
            $u = UserRepository::findById($uid);
            if ($u && !empty($u['email'])) {
                $recipients[] = $u['email'];
            }
        }
        if (in_array($actionType, self::TARGET_ALSO_NOTIFY_OVERSIGHT, true)) {
            $recipients = array_merge($recipients, self::oversightEmails());
        }

        return array_values(array_unique(array_merge($recipients, self::catchAllRecipients())));
    }

    /** @return int[] */
    private static function userIdsByRoleId(?int $roleId): array
    {
        if ($roleId === null) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE role_id = :role_id AND is_active = 1');
        $stmt->execute(['role_id' => $roleId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /** @return int[] zero-or-one user id — user_signature_assets rows are never deleted by the deactivate action, so this join is always resolvable. */
    private static function signatoryOwnerUserId(?int $assetId): array
    {
        if ($assetId === null) {
            return [];
        }
        $userId = self::fetchOrderIdColumn('user_signature_assets', 'user_id', $assetId);
        return $userId !== null ? [$userId] : [];
    }

    /** @return string[] */
    private static function resolveBroadcastRecipients(string $actionType, ?string $entityType): array
    {
        $permissionKey = self::broadcastPermissionKey($actionType, $entityType);
        $recipients = $permissionKey !== null ? self::emailsForPermission($permissionKey) : [];
        return array_values(array_unique(array_merge($recipients, self::catchAllRecipients())));
    }

    /**
     * PROTECTED_FIELD_UNLOCKED/PROTECTION_FLAG_REQUESTED can fire against
     * a payment preset, a T&C clause, or a company setting — whichever
     * table was actually unlocked decides who's affected: a payment
     * preset routes to the people who manage payment presets, anything
     * else to whoever manages company settings (the broadest "admin
     * configuration changed" audience, covering T&C clauses and any
     * future protected table too).
     */
    private static function broadcastPermissionKey(string $actionType, ?string $entityType): ?string
    {
        if ($actionType === 'PROTECTED_FIELD_UNLOCKED' || $actionType === 'PROTECTION_FLAG_REQUESTED') {
            return $entityType === 'payment_presets' ? 'manage_payment_presets' : 'manage_company_settings';
        }
        if (in_array($actionType, self::OVERSIGHT_ONLY_ACTION_TYPES, true)) {
            return self::OVERSIGHT_PERMISSION_KEY;
        }
        return self::BROADCAST_PERMISSION_BY_ACTION_TYPE[$actionType] ?? null;
    }

    /** @return string[] */
    private static function oversightEmails(): array
    {
        return self::emailsForPermission(self::OVERSIGHT_PERMISSION_KEY);
    }

    /** @return string[] */
    private static function emailsForPermission(string $permissionKey): array
    {
        $emails = [];
        foreach (PermissionRepository::usersWithPermission($permissionKey) as $uid) {
            $u = UserRepository::findById($uid);
            if ($u && !empty($u['email'])) {
                $emails[] = $u['email'];
            }
        }
        return $emails;
    }

    /** @return string[] zero-or-one address — the configurable catch-all, only when actually set. */
    private static function catchAllRecipients(): array
    {
        $notificationEmail = trim((string) (CompanySettingsRepository::get('notification_email') ?? ''));
        return $notificationEmail !== '' ? [$notificationEmail] : [];
    }

    /**
     * The "self" category's recipient resolution — unchanged from the
     * original design: the catch-all (always, when set) plus the acting
     * user's own email, gated by their own email_action_notifications_enabled
     * toggle (Account > Notifications, default on). The only category
     * where that toggle has any effect — every other category ignores it
     * entirely, by design.
     *
     * @param array<string,mixed>|null $user
     * @return string[]
     */
    public static function resolveRecipients(?array $user): array
    {
        $recipients = self::catchAllRecipients();

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
            // External QA report N-10: these "one row per order" tables
            // (AuditLogRepository::forOrder()'s own UNION query convention
            // from L-23) are logged with the ORDER's own id as entity_id,
            // never a separate row id of their own, so the readable
            // reference is just that order's own reference.
            'order_lc_details', 'order_freight', 'order_shipping', 'order_packing', 'order_payment_status' => ['orders', 'order_reference', 'Order'],
            default => null,
        };
    }

    /**
     * External QA report N-10: unlike entityReferenceLookup()'s tables,
     * these log the CHILD row's own id as entity_id (several rows can
     * exist per order), so the readable reference needs a join back to
     * the order that owns it.
     *
     * @return array{0: string, 1: string}|null [child table, label]
     */
    private static function entityReferenceViaOrderLookup(string $entityType): ?array
    {
        return match ($entityType) {
            'disputes' => ['disputes', 'Dispute'],
            'order_cancellations' => ['order_cancellations', 'Cancellation'],
            'order_complaints' => ['order_complaints', 'Complaint'],
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
        if ($lookup !== null) {
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

        $joinLookup = self::entityReferenceViaOrderLookup($entityType);
        if ($joinLookup !== null) {
            [$childTable, $label] = $joinLookup;
            try {
                $stmt = Database::connection()->prepare(
                    "SELECT o.order_reference AS reference FROM {$childTable} c JOIN orders o ON o.id = c.order_id WHERE c.id = :id"
                );
                $stmt->execute(['id' => $entityId]);
                $row = $stmt->fetch();
            } catch (\Throwable $e) {
                return null;
            }
            $reference = trim((string) ($row['reference'] ?? ''));
            return $reference !== '' ? ['label' => $label, 'reference' => "Order {$reference}"] : null;
        }

        return null;
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
