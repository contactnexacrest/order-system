'use strict';

const db = require('../config/db');
const userRepository = require('./userRepository');
const orderAssigneeRepository = require('./orderAssigneeRepository');
const permissionRepository = require('./permissionRepository');
const emailService = require('../services/emailService');

// Full port of App\Repositories\NotificationRepository. In-app notification
// queue (spec Section 10 "AUTO-NOTIFICATIONS" + Section 9 "System notifies
// assigned reviewers"). The deferred-send email pipeline
// (emailLogRepository) is separate and only fires for buyer-facing document
// sends.
//
// External QA report M-06: "In-app notifications also sent by e-mail."
// create() itself fans out to the targeted user's own email (the
// users.email_action_notifications_enabled toggle — "does this user want
// notification emails") rather than instrumenting each of this function's
// call sites separately. Since 2026-10-26 this is the only notification
// email channel: the audit log no longer mails anything, so a notification
// is created only where a specific person needs to know or act.
// Fire-and-forget: an email failure must never break the action that
// triggered the notification.

/**
 * The notification itself as the subject (not a generic "New
 * Notification"), so a busy inbox shows at a glance which order and what
 * needs doing.
 */
function emailSubject(message) {
  let line = String(message).replace(/\s+/g, ' ').trim();
  if ([...line].length > 110) {
    line = `${[...line].slice(0, 107).join('').trimEnd()}…`;
  }
  return `NexaCrest — ${line}`;
}

/**
 * @returns {Promise<string|null>} the email address actually attempted —
 *   null when the user doesn't exist, has no email, or has opted out.
 *   Exposed (and non-void) purely for testability.
 */
async function emailNotification(userId, message, type = '') {
  // D-280: the type's row in Admin › E-mail settings (MUST alerts always go;
  // the person's own opt-out applies only to the others).
  const emailPolicy = require('../services/emailPolicy');
  const kind = emailPolicy.kindForNotification(type);
  if (!(await emailPolicy.allows(kind, 'staff'))) {
    return null;
  }
  const user = await userRepository.findById(userId);
  const optedOut = user && Number(user.email_action_notifications_enabled ?? 1) !== 1 && !emailPolicy.isMust(kind, 'staff');
  if (!user || optedOut || !user.email || Number(user.is_active ?? 1) !== 1) {
    return null;
  }
  try {
    await emailService.sendPlainText(user.email, emailSubject(message), emailBody(message, kind), { kind, audience: 'staff' });
  } catch (e) {
    // Never let a notification-email failure break the action it's reporting on.
  }
  return user.email;
}

/**
 * D-310: an approval e-mail ends with the link to the Approvals page, where
 * the item waits with a button to the exact approval screen.
 */
function emailBody(message, kind) {
  if (kind !== 'staff_approvals') return message;
  return `${message}\n\nOpen your approvals: ${String(require('../config/env').get('APP_URL', '') || '').replace(/\/+$/, '')}/approvals`;
}

/**
 * Owner decision 2 Nov 2026 (D-233): an in-app notification only — for a caller
 * that sends its own fuller e-mail (the Zoho sync failure list), so the person
 * never gets the same news twice.
 */
async function createInAppOnly(userId, type, relatedOrderId, message) {
  const result = await db.execute(
    'INSERT INTO notifications (user_id, role_id, type, related_order_id, message) VALUES (:u, NULL, :t, :o, :m)',
    { u: userId, t: type, o: relatedOrderId, m: message }
  );
  return result.insertId;
}

/**
 * D-280: email false = in-app only for this person (routing decided elsewhere,
 * e.g. notifyOrderTeam()). The e-mail itself also follows Admin › E-mail
 * settings (the type's kind) and the person's own notification-e-mail switch.
 */
async function create(userId, roleId, type, relatedOrderId, message, email = true) {
  const result = await db.execute(
    `INSERT INTO notifications (user_id, role_id, type, related_order_id, message)
     VALUES (:user_id, :role_id, :type, :related_order_id, :message)`,
    { user_id: userId, role_id: roleId, type, related_order_id: relatedOrderId, message }
  );

  if (userId !== null && userId !== undefined && email) {
    await emailNotification(userId, message, type);
  }

  return result.insertId;
}

/**
 * Something the buyer / supplier did on an order that staff now need to act on.
 *
 * In-app: the order's assignees — or, on an order nobody is assigned to yet,
 * everyone who manages orders (as before).
 * E-mail (owner decision D-281, 11 Nov 2026): only the people of the event's
 * department on that order (staffRouting.forOrder); with none, the
 * department's manager with an "assign someone" note; with no manager, the MD.
 * The MD, ED and Order Coordinator get the in-app notice only.
 *
 * @returns {Promise<number[]>} the user ids notified in-app
 */
async function notifyOrderTeam(orderId, type, message) {
  const staffRouting = require('../services/staffRouting');
  let userIds = (await orderAssigneeRepository.forOrder(orderId)).map((a) => Number(a.user_id));
  if (!userIds.length) {
    userIds = (await permissionRepository.usersWithPermission('manage_orders')).map(Number);
  }
  const route = await staffRouting.forOrder(orderId, staffRouting.departmentFor(type));
  const emailIds = route.email.map(Number);
  userIds = [...new Set(userIds.concat(emailIds))];
  for (const userId of userIds) {
    const routed = emailIds.includes(userId);
    const text = routed && route.note !== null ? `${message} ${route.note}` : message;
    await create(userId, null, type, orderId, text, routed);
  }
  return userIds;
}

/**
 * D-281: everyone in inAppIds gets the notice; only emailIds also get the e-mail.
 * @returns {Promise<number[]>} everyone notified
 */
async function notifyRouted(inAppIds, emailIds, type, relatedOrderId, message) {
  const mail = [...new Set((emailIds || []).map(Number))];
  const all = [...new Set((inAppIds || []).map(Number).concat(mail))];
  for (const userId of all) {
    await create(userId, null, type, relatedOrderId, message, mail.includes(userId));
  }
  return all;
}

async function forUser(userId, limit = 20) {
  return db.query(`SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ${parseInt(limit, 10)}`, {
    user_id: userId,
  });
}

async function unreadCountForUser(userId) {
  const row = await db.queryOne('SELECT COUNT(*) AS c FROM notifications WHERE user_id = :user_id AND is_read = 0', { user_id: userId });
  return row ? parseInt(row.c, 10) : 0;
}

async function markRead(id, userId) {
  await db.execute('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id', { id, user_id: userId });
}

async function markAllRead(userId) {
  await db.execute('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0', { user_id: userId });
}

/**
 * Dedup guard for the daily-alert-check background job: without this, a
 * daily job re-fires the same "LUT expires in N days" / "dispute overdue"
 * notification every single run for as long as the underlying condition
 * stays true, flooding the recipient's bell with same-day duplicates. One
 * notification per (user, type, related_order_id) per calendar day is
 * enough — the condition is still visible in the bell until resolved, it
 * just isn't re-announced hourly/daily.
 */
async function existsToday(userId, type, relatedOrderId) {
  const row = await db.queryOne(
    `SELECT COUNT(*) AS c FROM notifications
     WHERE user_id = :user_id AND type = :type
       AND related_order_id <=> :related_order_id
       AND DATE(created_at) = CURDATE()`,
    { user_id: userId, type, related_order_id: relatedOrderId }
  );
  return row ? parseInt(row.c, 10) > 0 : false;
}

module.exports = { create, createInAppOnly, forUser, unreadCountForUser, markRead, markAllRead, existsToday, emailNotification, emailSubject, emailBody, notifyOrderTeam, notifyRouted };
