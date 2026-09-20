'use strict';

const db = require('../config/db');

// Full port of App\Repositories\NotificationRepository. In-app notification
// queue (spec Section 10 "AUTO-NOTIFICATIONS" + Section 9 "System notifies
// assigned reviewers"). Deliberately not tied to email — this is the
// bell-icon queue a logged-in user sees; the deferred-send email pipeline
// (emailLogRepository) is separate and only fires for buyer-facing document
// sends.

async function create(userId, roleId, type, relatedOrderId, message) {
  const result = await db.execute(
    `INSERT INTO notifications (user_id, role_id, type, related_order_id, message)
     VALUES (:user_id, :role_id, :type, :related_order_id, :message)`,
    { user_id: userId, role_id: roleId, type, related_order_id: relatedOrderId, message }
  );
  return result.insertId;
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

module.exports = { create, forUser, unreadCountForUser, markRead, markAllRead, existsToday };
