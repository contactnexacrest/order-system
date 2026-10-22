'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const userRepository = require('../repositories/userRepository');
const emailService = require('./emailService');
const db = require('../config/db');

/**
 * Port of App\Services\ActionNotificationService. User-reported batch
 * item 20 ("non-negotiable"): fires an email for every audit-logged
 * action in the system. Hooked into auditLogRepository.log() — the
 * single choke point every meaningful state-changing action in this
 * codebase already funnels through (47+ call sites across every
 * controller/service) — rather than requiring a separate
 * instrumentation pass per action.
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
 * email already in this codebase (clientIntakeController's correction
 * link, ordersController's PI-stage/Buyer Action links): a notification
 * failure must never break the action it's reporting on, and
 * emailService.sendPlainText() already degrades safely (Test Mode
 * block, no-SMTP log-only fallback) with no exception on its own.
 */

/**
 * Called from auditLogRepository.log() after its own Test-Mode
 * exclusion check, so this never fires for a test order/client.
 *
 * @returns {Promise<string[]>} the recipient email addresses actually attempted — exposed for testability
 */
async function notify(userId, actionType, entityType, entityId, fieldName, oldValue, newValue, reason) {
  const user = userId !== null && userId !== undefined ? await userRepository.findById(userId) : null;
  const recipients = await resolveRecipients(user);
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

/** @returns {Promise<string[]>} */
async function resolveRecipients(user) {
  const recipients = [];

  const notificationEmail = String((await companySettingsRepository.get('notification_email')) || '').trim();
  if (notificationEmail !== '') {
    recipients.push(notificationEmail);
  }

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
};

/**
 * External QA report L-46: action-notification subjects/bodies used the raw
 * table name + numeric id ("Login Success — Users #37", "Reviewers
 * Assigned — Documents #231") — unreadable without opening the record.
 * Looks up a human-friendly reference for the handful of entity types that
 * have one; any entity type not in this whitelist, or a row that no longer
 * exists (e.g. since deleted), returns null — buildMessage() then falls
 * straight back to its own "#id" formatting. This never throws and never
 * blocks the action it reports on, matching notify()'s own
 * fire-and-forget contract.
 *
 * @returns {Promise<{label: string, reference: string}|null>}
 */
async function resolveEntityReference(entityType, entityId) {
  if (entityType === null || entityType === undefined || entityId === null || entityId === undefined) {
    return null;
  }
  const lookup = ENTITY_REFERENCE_LOOKUP[entityType];
  if (!lookup) {
    return null;
  }
  const [table, column, label] = lookup;
  try {
    const row = await db.queryOne(`SELECT ${column} AS reference FROM ${table} WHERE id = :id`, { id: entityId });
    const reference = String((row && row.reference) || '').trim();
    return reference !== '' ? { label, reference } : null;
  } catch (e) {
    return null;
  }
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

module.exports = { notify, resolveRecipients, buildMessage, resolveEntityReference };
