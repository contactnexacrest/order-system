'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const userRepository = require('../repositories/userRepository');
const permissionRepository = require('../repositories/permissionRepository');
const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');
const emailService = require('./emailService');
const db = require('../config/db');

/**
 * Port of App\Services\ActionNotificationService — see its own docblock
 * for the full Notification Routing Plan rationale (supersedes the
 * original "User-reported batch item 20" design, which fired the same
 * two recipients — a configurable catch-all and the acting user's own
 * opted-in email — for every one of 230 distinct audit-logged action
 * types with zero differentiation).
 *
 * Still hooked into auditLogRepository.log() — the single choke point
 * every meaningful state-changing action in this codebase already funnels
 * through — but each action type now routes to exactly one of five
 * categories:
 *
 *   - order:     that order's own assignees (order_assignees) + the
 *                Admin/MD/ED oversight tier (a safety net for an
 *                unassigned order) + the catch-all. Never toggleable.
 *   - target:    the one specific person the action is about + the
 *                catch-all. Also never toggleable.
 *   - broadcast: everyone holding the permission key that actually
 *                governs the shared data that changed + the catch-all.
 *                Never toggleable.
 *   - self:      routine, about the actor's own activity only — the one
 *                category where the acting user's personal
 *                email_action_notifications_enabled toggle still applies.
 *                Exactly the original resolveRecipients() behaviour.
 *   - none:      no email at all. The audit_log row is still written by
 *                auditLogRepository.log() exactly as before — only the
 *                notification email is removed.
 *
 * Deliberately fire-and-forget: a notification failure must never break
 * the action it's reporting on, and emailService.sendPlainText() already
 * degrades safely (Test Mode block, no-SMTP log-only fallback) with no
 * exception on its own.
 */

/** Action types resolved as order-scoped — see resolveOrderId() below. */
const ORDER_ACTION_TYPES = new Set([
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
  'ORDER_FEEDBACK_SUBMITTED', 'ORDER_FEEDBACK_RECORDED_BY_STAFF',
  'CLIENT_PAYMENT_REPORTED', 'CLIENT_PAYMENT_REPORT_REVIEWED', 'CLIENT_PORTAL_PROVISIONED', 'BUYER_ACTION_LINK_GENERATED',
  'ADVANCE_PAYMENT_RECORDED', 'ADVANCE_PAYMENT_CLEARED', 'BALANCE_PAYMENT_RECORDED', 'BALANCE_PAYMENT_CLEARED', 'FREIGHT_PAYMENT_RECORDED',
  'PI_INTAKE_SUBMITTED', 'PI_INTAKE_REJECTED', 'PI_INTAKE_LINK_GENERATED',
]);

/** Action types resolved as "about one specific person" — see resolveTargetRecipients() below. */
const TARGET_ACTION_TYPES = new Set([
  'USER_PERMISSION_GRANTED', 'USER_PERMISSION_REVOKED', 'ROLE_PERMISSIONS_UPDATED',
  'USER_ARCHIVED', 'USER_UNARCHIVED', 'USER_CREATED', 'PASSWORD_ADMIN_RESET',
  'SIGNATORY_USER_ASSET_UPLOADED', 'SIGNATORY_USER_ASSET_DEACTIVATED',
  'SUPER_ADMIN_DELEGATION_GRANTED', 'SUPER_ADMIN_DELEGATION_REVOKED',
]);

/** Target types where the oversight (Admin/MD/ED) tier is ALSO notified, on top of the affected person. */
const TARGET_ALSO_NOTIFY_OVERSIGHT = new Set([
  'USER_ARCHIVED', 'USER_UNARCHIVED', 'SUPER_ADMIN_DELEGATION_GRANTED', 'SUPER_ADMIN_DELEGATION_REVOKED',
]);

/** Broadcast action types with a fixed permission key. */
const BROADCAST_PERMISSION_BY_ACTION_TYPE = {
  PORT_ADDED: 'manage_orders', PORT_UPDATED: 'manage_orders', PORT_DEFAULT_CHANGED: 'manage_orders',
  PORT_ROUTE_ADDED: 'manage_orders', PORT_ROUTE_UPDATED: 'manage_orders',
  DROPDOWN_OPTION_ADDED: 'manage_orders', DROPDOWN_OPTION_UPDATED: 'manage_orders', DROPDOWN_OPTION_DEFAULT_CHANGED: 'manage_orders',
  HOLIDAY_ADDED: 'manage_orders', HOLIDAY_REMOVED: 'manage_orders',
  CLIENT_INTAKE_SUBMITTED: 'manage_orders', CLIENT_INTAKE_SUBMISSION_UPDATED_BY_CLIENT: 'manage_orders',

  HS_CODE_ADDED: 'manage_hs_codes', HS_CODE_UPDATED: 'manage_hs_codes', HS_CODE_DELETED: 'manage_hs_codes',
  HS_CODE_TOGGLED: 'manage_hs_codes', HS_CODE_BULK_IMPORTED: 'manage_hs_codes', HS_CODE_GUIDE_IMPORTED: 'manage_hs_codes',

  PAYMENT_PRESET_ADDED: 'manage_payment_presets', PAYMENT_PRESET_UPDATED: 'manage_payment_presets',

  LOGISTICS_PARTNER_ADDED: 'manage_logistics_partners', LOGISTICS_PARTNER_UPDATED: 'manage_logistics_partners',
  LOGISTICS_PARTNER_DELETED: 'manage_logistics_partners',

  SUPPLIER_CREATED: 'manage_product_catalog', SUPPLIER_FACTORY_ADDRESS_CREATED: 'manage_product_catalog',
  SUPPLIER_FACTORY_ADDRESS_DELETED: 'manage_product_catalog',
  PRODUCT_CREATED: 'manage_product_catalog', PRODUCT_UPDATED: 'manage_product_catalog', PRODUCT_DELETED: 'manage_product_catalog',
  PRODUCT_IMAGE_UPLOADED: 'manage_product_catalog', PRODUCT_IMAGE_DELETED: 'manage_product_catalog',
  PRODUCT_MISC_CHARGE_ADDED: 'manage_product_catalog', PRODUCT_MISC_CHARGE_DELETED: 'manage_product_catalog',
  PRODUCT_SUPPLIER_ADDED: 'manage_product_catalog', PRODUCT_SUPPLIER_DELETED: 'manage_product_catalog',
  PRODUCT_SUPPLIER_SET_PRIMARY: 'manage_product_catalog', PRODUCT_SUPPLIER_UPDATED: 'manage_product_catalog',

  COMPLIANCE_TASK_TYPE_ADDED: 'manage_compliance_task_types', COMPLIANCE_TASK_TYPE_UPDATED: 'manage_compliance_task_types',
  COMPLIANCE_TASK_TYPE_DELETED: 'manage_compliance_task_types',

  EMAIL_TEMPLATE_CREATED: 'manage_email_templates', EMAIL_TEMPLATE_UPDATED: 'manage_email_templates',
  EMAIL_TEMPLATE_TOGGLED_ACTIVE: 'manage_email_templates',

  WATERMARK_SETTINGS_UPDATED: 'manage_assets', WATERMARK_ASSET_DELETED: 'manage_assets', WATERMARK_ASSET_REPLACED: 'manage_assets',

  DOCUMENT_TYPE_SIGNATORY_SET: 'manage_signatories', GLOBAL_DEFAULT_SIGNATORY_SET: 'manage_signatories',
  SIGNATORY_DESIGNATION_CREATED: 'manage_signatories', SIGNATORY_DESIGNATION_DELETED: 'manage_signatories',
  SIGNATORY_DESIGNATION_RENAMED: 'manage_signatories', SIGNATORY_DESIGNATION_TOGGLED: 'manage_signatories',

  REFERENCE_LIBRARY_CATEGORY_CREATED: 'manage_company_settings', REFERENCE_LIBRARY_CATEGORY_UPDATED: 'manage_company_settings',
  REFERENCE_LIBRARY_CATEGORY_DELETED: 'manage_company_settings', REFERENCE_LIBRARY_DOC_CREATED: 'manage_company_settings',
  REFERENCE_LIBRARY_DOC_UPDATED: 'manage_company_settings', REFERENCE_LIBRARY_DOC_DELETED: 'manage_company_settings',
  REFERENCE_DOC_UPDATED: 'manage_company_settings',
};

/**
 * Broadcast action types routed to the Admin/MD/ED oversight tier
 * specifically, via the 'assign_order_owner' permission — already
 * granted to exactly Admin/Managing Director/Executive Director (see
 * docs/seed.sql's role_permissions cross-join) and nobody else, making it
 * the existing, already-correct proxy for this tier.
 */
const OVERSIGHT_PERMISSION_KEY = 'assign_order_owner';

const OVERSIGHT_ONLY_ACTION_TYPES = new Set([
  'CLIENT_AGREEMENT_FORCE_EXPIRED', 'CLIENT_IMPERSONATION_STARTED', 'CLIENT_IMPERSONATION_ENDED',
  'CLIENT_PASSWORD_ADMIN_RESET',
  'CA_FINANCIAL_YEAR_LOCKED', 'CA_FINANCIAL_YEAR_UNLOCKED', 'CA_FY_LOCK_OVERRIDDEN',
  'ROLE_CREATED', 'ROLE_UPDATED', 'ROLE_DELETED', 'PERMISSION_CREATED', 'PERMISSION_UPDATED', 'PERMISSION_DELETED',
  'USER_DELETED',
]);

/** Action types that get no email at all — the audit_log row is still written by auditLogRepository.log(), unchanged. */
const NONE_ACTION_TYPES = new Set([
  'SAMPLE_DATA_LOADED', 'SAMPLE_DATA_CLEARED',
  'TEST_MODE_ENABLED', 'TEST_MODE_DISABLED', 'TEST_DATA_DELETED', 'TEST_EMAIL_UPDATED',
  'SETTINGS_TEST_SMTP_EMAIL_SENT', 'TEST_ZOHO_EMAIL_SENT',
  'LOGIN_SUCCESS', 'LOGOUT', 'CLIENT_LOGIN_SUCCESS', 'CLIENT_LOGOUT',
  'PERMISSION_DENIED', 'FIELD_EDIT',
  'ACCOUNT_NOTIFICATION_PREFERENCE_UPDATED', 'SIGNATURE_UPDATED',
  'UI_TEMPLATE_PREFERENCE_UPDATED', 'UI_TEMPLATE_TOGGLED_ACTIVE',
  'CA_ZOHO_SYNC_RUN', 'CLIENT_PASSWORD_CHANGED', 'CLIENT_PASSWORD_RESET_REQUESTED',
]);

/** entityType values where entityId IS the order's own id — no join needed. */
const DIRECT_ORDER_ENTITY_TYPES = new Set([
  'orders', 'order', 'order_freight', 'order_shipping', 'order_packing', 'order_payment_status',
  'order_lc_details', 'order_bl_endorsements', 'order_inspection_fees', 'order_travel_expenses',
]);

/** entityType => column name holding the order id, one join away. */
const ONE_HOP_ORDER_ENTITY_TYPES = {
  documents: 'order_id', email_log: 'order_id', amendments: 'order_id',
  order_cost_entries: 'order_id', order_buyer_po_documents: 'order_id', file_store: 'order_id',
  order_supplier_po: 'order_id', order_draft_bl_reviews: 'order_id',
  order_cancellations: 'order_id', order_complaints: 'order_id', disputes: 'order_id',
  client_payment_reports: 'order_id', pi_intake_submissions: 'order_id',
  order_annexure_products: 'order_id', ca_export_benefits: 'order_id', order_products: 'order_id',
  order_reorder_requests: 'source_order_id',
};

/** entityType => [column pointing to the parent row's id, parent table, parent's order-id column]. */
const TWO_HOP_ORDER_ENTITY_TYPES = {
  order_supplier_po_documents: ['order_supplier_po_id', 'order_supplier_po', 'order_id'],
  dispute_documents: ['dispute_id', 'disputes', 'order_id'],
  order_annexure_images: ['order_annexure_product_id', 'order_annexure_products', 'order_id'],
};

/**
 * Called from auditLogRepository.log() after its own Test-Mode exclusion
 * check, so this never fires for a test order/client.
 *
 * @returns {Promise<string[]>} the recipient email addresses actually attempted — exposed for testability
 */
async function notify(userId, actionType, entityType, entityId, fieldName, oldValue, newValue, reason, notifyTargetUserId = null) {
  const category = categoryFor(actionType, entityType);
  if (category === 'none') {
    return [];
  }

  const user = userId !== null && userId !== undefined ? await userRepository.findById(userId) : null;
  let recipients;
  if (category === 'order') {
    recipients = await resolveOrderRecipients(entityType, entityId);
  } else if (category === 'target') {
    recipients = await resolveTargetRecipients(actionType, entityId, notifyTargetUserId);
  } else if (category === 'broadcast') {
    recipients = await resolveBroadcastRecipients(actionType, entityType);
  } else {
    recipients = await resolveRecipients(user);
  }
  if (recipients.length === 0) {
    return [];
  }

  const entityReference = await resolveEntityReference(entityType, entityId);
  const message = buildMessage(user, actionType, entityType, entityId, fieldName, oldValue, newValue, reason, entityReference);
  for (const recipient of recipients) {
    try {
      await emailService.sendPlainText(recipient, message.subject, message.body);
    } catch (e) {
      // Never let a notification failure break the action it's reporting on.
    }
  }

  return recipients;
}

/**
 * Which of the five Notification Routing Plan categories a given action
 * type falls into. entityType is only consulted for the two
 * field-protection action types — see broadcastPermissionKey() below.
 */
function categoryFor(actionType, entityType = null) {
  if (actionType === 'PROTECTED_FIELD_UNLOCKED' || actionType === 'PROTECTION_FLAG_REQUESTED') {
    return 'broadcast';
  }
  if (NONE_ACTION_TYPES.has(actionType)) {
    return 'none';
  }
  if (ORDER_ACTION_TYPES.has(actionType)) {
    return 'order';
  }
  if (TARGET_ACTION_TYPES.has(actionType)) {
    return 'target';
  }
  if (Object.prototype.hasOwnProperty.call(BROADCAST_PERMISSION_BY_ACTION_TYPE, actionType) || OVERSIGHT_ONLY_ACTION_TYPES.has(actionType)) {
    return 'broadcast';
  }
  // Everything else (routine self-activity, and any action type not yet
  // classified) keeps the original toggleable self+catch-all behaviour —
  // the conservative default, never silently "none".
  return 'self';
}

/** @returns {Promise<string[]>} */
async function resolveOrderRecipients(entityType, entityId) {
  let recipients = [];
  const orderId = await resolveOrderId(entityType, entityId);
  if (orderId !== null) {
    for (const assignee of await orderAssigneeRepository.forOrder(orderId)) {
      if (assignee.email) {
        recipients.push(assignee.email);
      }
    }
  }
  recipients = recipients.concat(await oversightEmails(), await catchAllRecipients());
  return [...new Set(recipients)];
}

/**
 * Resolves entityType/entityId down to the order id it belongs to —
 * directly, one join away, or two joins away — covering every
 * order-scoped action type's actual logging shape in this codebase.
 * Returns null for anything unresolvable, so resolveOrderRecipients()
 * still falls back to the oversight tier + catch-all rather than
 * silently emailing nobody.
 *
 * @returns {Promise<number|null>}
 */
async function resolveOrderId(entityType, entityId) {
  if (entityType === null || entityType === undefined || entityId === null || entityId === undefined) {
    return null;
  }

  if (DIRECT_ORDER_ENTITY_TYPES.has(entityType)) {
    return entityId;
  }

  if (Object.prototype.hasOwnProperty.call(ONE_HOP_ORDER_ENTITY_TYPES, entityType)) {
    return fetchOrderIdColumn(entityType, ONE_HOP_ORDER_ENTITY_TYPES[entityType], entityId);
  }

  if (Object.prototype.hasOwnProperty.call(TWO_HOP_ORDER_ENTITY_TYPES, entityType)) {
    const [viaColumn, parentTable, parentColumn] = TWO_HOP_ORDER_ENTITY_TYPES[entityType];
    const viaId = await fetchOrderIdColumn(entityType, viaColumn, entityId);
    return viaId !== null ? fetchOrderIdColumn(parentTable, parentColumn, viaId) : null;
  }

  return null;
}

async function fetchOrderIdColumn(table, column, id) {
  try {
    const row = await db.queryOne(`SELECT ${column} AS resolved_id FROM ${table} WHERE id = :id`, { id });
    return row && row.resolved_id !== null && row.resolved_id !== undefined ? Number(row.resolved_id) : null;
  } catch (e) {
    return null;
  }
}

/**
 * "About one specific person" recipient resolution. Most of these action
 * types log the affected user's own id directly as entity_id; two
 * (grant/revoke of a per-user permission override) need the explicit
 * notifyTargetUserId hint instead.
 *
 * @returns {Promise<string[]>}
 */
async function resolveTargetRecipients(actionType, entityId, notifyTargetUserId) {
  let targetUserIds = [];
  if (actionType === 'USER_PERMISSION_GRANTED' || actionType === 'USER_PERMISSION_REVOKED') {
    targetUserIds = notifyTargetUserId !== null && notifyTargetUserId !== undefined ? [notifyTargetUserId] : [];
  } else if (actionType === 'ROLE_PERMISSIONS_UPDATED') {
    targetUserIds = await userIdsByRoleId(entityId);
  } else if (['USER_CREATED', 'USER_ARCHIVED', 'USER_UNARCHIVED', 'PASSWORD_ADMIN_RESET', 'SUPER_ADMIN_DELEGATION_GRANTED', 'SUPER_ADMIN_DELEGATION_REVOKED'].includes(actionType)) {
    targetUserIds = entityId !== null && entityId !== undefined ? [entityId] : [];
  } else if (actionType === 'SIGNATORY_USER_ASSET_UPLOADED' || actionType === 'SIGNATORY_USER_ASSET_DEACTIVATED') {
    targetUserIds = await signatoryOwnerUserId(entityId);
  }

  let recipients = [];
  for (const uid of targetUserIds) {
    const u = await userRepository.findById(uid);
    if (u && u.email) {
      recipients.push(u.email);
    }
  }
  if (TARGET_ALSO_NOTIFY_OVERSIGHT.has(actionType)) {
    recipients = recipients.concat(await oversightEmails());
  }

  return [...new Set(recipients.concat(await catchAllRecipients()))];
}

/** @returns {Promise<number[]>} */
async function userIdsByRoleId(roleId) {
  if (roleId === null || roleId === undefined) {
    return [];
  }
  const rows = await db.query('SELECT id FROM users WHERE role_id = :role_id AND is_active = 1', { role_id: roleId });
  return rows.map((r) => Number(r.id));
}

/** @returns {Promise<number[]>} zero-or-one user id — user_signature_assets rows are never deleted by the deactivate action, so this join is always resolvable. */
async function signatoryOwnerUserId(assetId) {
  if (assetId === null || assetId === undefined) {
    return [];
  }
  const userId = await fetchOrderIdColumn('user_signature_assets', 'user_id', assetId);
  return userId !== null ? [userId] : [];
}

/** @returns {Promise<string[]>} */
async function resolveBroadcastRecipients(actionType, entityType) {
  const permissionKey = broadcastPermissionKey(actionType, entityType);
  const recipients = permissionKey !== null ? await emailsForPermission(permissionKey) : [];
  return [...new Set(recipients.concat(await catchAllRecipients()))];
}

/**
 * PROTECTED_FIELD_UNLOCKED/PROTECTION_FLAG_REQUESTED can fire against a
 * payment preset, a T&C clause, or a company setting — whichever table
 * was actually unlocked decides who's affected: a payment preset routes
 * to the people who manage payment presets, anything else to whoever
 * manages company settings.
 */
function broadcastPermissionKey(actionType, entityType) {
  if (actionType === 'PROTECTED_FIELD_UNLOCKED' || actionType === 'PROTECTION_FLAG_REQUESTED') {
    return entityType === 'payment_presets' ? 'manage_payment_presets' : 'manage_company_settings';
  }
  if (OVERSIGHT_ONLY_ACTION_TYPES.has(actionType)) {
    return OVERSIGHT_PERMISSION_KEY;
  }
  return BROADCAST_PERMISSION_BY_ACTION_TYPE[actionType] ?? null;
}

/** @returns {Promise<string[]>} */
async function oversightEmails() {
  return emailsForPermission(OVERSIGHT_PERMISSION_KEY);
}

/** @returns {Promise<string[]>} */
async function emailsForPermission(permissionKey) {
  const emails = [];
  for (const uid of await permissionRepository.usersWithPermission(permissionKey)) {
    const u = await userRepository.findById(uid);
    if (u && u.email) {
      emails.push(u.email);
    }
  }
  return emails;
}

/** @returns {Promise<string[]>} zero-or-one address — the configurable catch-all, only when actually set. */
async function catchAllRecipients() {
  const notificationEmail = String((await companySettingsRepository.get('notification_email')) || '').trim();
  return notificationEmail !== '' ? [notificationEmail] : [];
}

/**
 * The "self" category's recipient resolution — unchanged from the
 * original design: the catch-all (always, when set) plus the acting
 * user's own email, gated by their own email_action_notifications_enabled
 * toggle. The only category where that toggle has any effect.
 *
 * @returns {Promise<string[]>}
 */
async function resolveRecipients(user) {
  const recipients = await catchAllRecipients();

  if (user && Number(user.email_action_notifications_enabled ?? 1) === 1 && user.email) {
    recipients.push(user.email);
  }

  return [...new Set(recipients)];
}

const ENTITY_REFERENCE_LOOKUP = {
  order: ['orders', 'order_reference', 'Order'],
  orders: ['orders', 'order_reference', 'Order'],
  documents: ['documents', 'document_reference', 'Document'],
  clients: ['clients', 'company_legal_name', 'Client'],
  users: ['users', 'name', 'User'],
  roles: ['roles', 'name', 'Role'],
  permissions: ['permissions', 'name', 'Permission'],
  products: ['products', 'name', 'Product'],
  ports: ['ports', 'name', 'Port'],
  document_types: ['document_types', 'name', 'Document Type'],
  compliance_task_types: ['compliance_task_types', 'name', 'Compliance Task Type'],
  logistics_partners: ['logistics_partners', 'partner_name', 'Logistics Partner'],
  // External QA report N-10: these "one row per order" tables
  // (auditLogRepository.forOrder()'s own UNION query convention from
  // L-23) are logged with the ORDER's own id as entity_id, never a
  // separate row id of their own, so the readable reference is just
  // that order's own reference.
  order_lc_details: ['orders', 'order_reference', 'Order'],
  order_freight: ['orders', 'order_reference', 'Order'],
  order_shipping: ['orders', 'order_reference', 'Order'],
  order_packing: ['orders', 'order_reference', 'Order'],
  order_payment_status: ['orders', 'order_reference', 'Order'],
};

// External QA report N-10: unlike ENTITY_REFERENCE_LOOKUP's tables, these
// log the CHILD row's own id as entity_id, so the readable reference
// needs a join back to the order that owns it. { childTable, label }
const ENTITY_REFERENCE_VIA_ORDER_LOOKUP = {
  disputes: { childTable: 'disputes', label: 'Dispute' },
  order_cancellations: { childTable: 'order_cancellations', label: 'Cancellation' },
  order_complaints: { childTable: 'order_complaints', label: 'Complaint' },
};

/**
 * External QA report L-46: action-notification subjects/bodies used the raw
 * table name + numeric id ("Login Success — Users #37", "Reviewers
 * Assigned — Documents #231") — unreadable without opening the record.
 * Looks up a human-friendly reference for the handful of entity types that
 * have one; any entity type not in this whitelist, or a row that no longer
 * exists, returns null — buildMessage() then falls straight back to its
 * own "#id" formatting. This never throws and never blocks the action it
 * reports on, matching notify()'s own fire-and-forget contract.
 *
 * @returns {Promise<{label: string, reference: string}|null>}
 */
async function resolveEntityReference(entityType, entityId) {
  if (entityType === null || entityType === undefined || entityId === null || entityId === undefined) {
    return null;
  }
  const lookup = ENTITY_REFERENCE_LOOKUP[entityType];
  if (lookup) {
    const [table, column, label] = lookup;
    try {
      const row = await db.queryOne(`SELECT ${column} AS reference FROM ${table} WHERE id = :id`, { id: entityId });
      const reference = String((row && row.reference) || '').trim();
      return reference !== '' ? { label, reference } : null;
    } catch (e) {
      return null;
    }
  }

  const joinLookup = ENTITY_REFERENCE_VIA_ORDER_LOOKUP[entityType];
  if (joinLookup) {
    const { childTable, label } = joinLookup;
    try {
      const row = await db.queryOne(
        `SELECT o.order_reference AS reference FROM ${childTable} c JOIN orders o ON o.id = c.order_id WHERE c.id = :id`,
        { id: entityId }
      );
      const reference = String((row && row.reference) || '').trim();
      return reference !== '' ? { label, reference: `Order ${reference}` } : null;
    } catch (e) {
      return null;
    }
  }

  return null;
}

/**
 * @param {{label: string, reference: string}|null} [entityReference] see resolveEntityReference() above
 * @returns {{subject: string, body: string}}
 */
function buildMessage(user, actionType, entityType, entityId, fieldName, oldValue, newValue, reason, entityReference = null) {
  const actionLabel = String(actionType).toLowerCase().split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  let entityLabel = entityType !== null && entityType !== undefined
    ? `${entityType.charAt(0).toUpperCase()}${entityType.slice(1)}${entityId !== null && entityId !== undefined ? ` #${entityId}` : ''}`
    : null;
  if (entityReference) {
    entityLabel = `${entityReference.label} ${entityReference.reference}`;
  }

  const subject = `NexaCrest — ${actionLabel}${entityLabel !== null ? ` — ${entityLabel}` : ''}`;

  const actorName = (user && user.name) || 'System';

  const lines = [`Action: ${actionLabel}`, `By: ${actorName}`];
  if (entityLabel !== null) {
    lines.push(`On: ${entityLabel}`);
  }
  if (fieldName !== null && fieldName !== undefined) {
    lines.push(`Field: ${fieldName}`);
  }
  if ((oldValue !== null && oldValue !== undefined) || (newValue !== null && newValue !== undefined)) {
    lines.push(`Change: ${oldValue ?? '—'} -> ${newValue ?? '—'}`);
  }
  if (reason !== null && reason !== undefined && reason !== '') {
    lines.push(`Reason: ${reason}`);
  }
  const body = lines.join('\n') + '\n\nNexaCrest International Private Limited — automated action notification.';

  return { subject, body };
}

module.exports = { notify, categoryFor, resolveOrderId, resolveRecipients, buildMessage, resolveEntityReference };
