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
async function emailNotification(userId, message) {
  const user = await userRepository.findById(userId);
  if (!user || Number(user.email_action_notifications_enabled ?? 1) !== 1 || !user.email) {
    return null;
  }
  try {
    await emailService.sendPlainText(user.email, emailSubject(message), message);
  } catch (e) {
    // Never let a notification-email failure break the action it's reporting on.
  }
  return user.email;
}

async function create(userId, roleId, type, relatedOrderId, message) {
  const result = await db.execute(
    `INSERT INTO notifications (user_id, role_id, type, related_order_id, message)
     VALUES (:user_id, :role_id, :type, :related_order_id, :message)`,
    { user_id: userId, role_id: roleId, type, related_order_id: relatedOrderId, message }
  );

  if (userId !== null && userId !== undefined) {
    await emailNotification(userId, message);
  }

  return result.insertId;
}

/**
 * Something the client did on an order that staff now need to act on
 * (acknowledged the OC, uploaded their PO, reported a payment): notifies
 * that order's own assignees — or, on an order nobody is assigned to yet,
 * everyone who manages orders, so it is never missed.
 *
 * @returns {Promise<number[]>} the user ids notified
 */
async function notifyOrderTeam(orderId, type, message) {
  let userIds = (await orderAssigneeRepository.forOrder(orderId)).map((a) => Number(a.user_id));
  if (!userIds.length) {
    userIds = (await permissionRepository.usersWithPermission('manage_orders')).map(Number);
  }
  userIds = [...new Set(userIds)];
  for (const userId of userIds) {
    await create(userId, null, type, orderId, message);
  }
  return userIds;
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

module.exports = { create, forUser, unreadCountForUser, markRead, markAllRead, existsToday, emailNotification, emailSubject, notifyOrderTeam };
