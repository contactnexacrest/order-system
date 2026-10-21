'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');
const userRepository = require('../repositories/userRepository');
const emailService = require('./emailService');

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

  const message = buildMessage(user, actionType, entityType, entityId, fieldName, oldValue, newValue, reason);
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

/** @returns {{subject: string, body: string}} */
function buildMessage(user, actionType, entityType, entityId, fieldName, oldValue, newValue, reason) {
  const actionLabel = String(actionType).toLowerCase().split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  const entityLabel = entityType !== null && entityType !== undefined
    ? `${entityType.charAt(0).toUpperCase()}${entityType.slice(1)}${entityId !== null && entityId !== undefined ? ` #${entityId}` : ''}`
    : null;

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

module.exports = { notify, resolveRecipients, buildMessage };
