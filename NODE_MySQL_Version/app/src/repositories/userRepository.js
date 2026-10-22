'use strict';

const db = require('../config/db');

// Port of App\Repositories\UserRepository.

async function findByEmail(email) {
  return db.queryOne('SELECT * FROM users WHERE email = :email LIMIT 1', { email });
}

async function findById(id) {
  return db.queryOne('SELECT * FROM users WHERE id = :id LIMIT 1', { id });
}

async function incrementFailedLogins(userId) {
  await db.execute('UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = :id', { id: userId });
}

async function resetFailedLogins(userId) {
  await db.execute('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id', { id: userId });
}

async function lockUntil(userId, untilDateTime) {
  await db.execute('UPDATE users SET locked_until = :until WHERE id = :id', { until: untilDateTime, id: userId });
}

async function updateLastLogin(userId) {
  await db.execute('UPDATE users SET last_login_at = NOW() WHERE id = :id', { id: userId });
}

async function updatePassword(userId, passwordHash, forceChange = false) {
  await db.execute(
    'UPDATE users SET password_hash = :hash, force_password_change = :force, password_changed_at = NOW() WHERE id = :id',
    { hash: passwordHash, force: forceChange ? 1 : 0, id: userId }
  );
}

async function flagPasswordExpired(userId) {
  await db.execute('UPDATE users SET force_password_change = 1 WHERE id = :id', { id: userId });
}

async function setTwoFactor(userId, enabled, method, secret) {
  await db.execute(
    'UPDATE users SET two_fa_enabled = :enabled, two_fa_method = :method, two_fa_secret = :secret WHERE id = :id',
    { enabled: enabled ? 1 : 0, method, secret, id: userId }
  );
}

async function listActive() {
  return db.query(
    `SELECT u.id, u.name, u.email, r.name AS role_name
     FROM users u LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
     ORDER BY u.name`
  );
}

// 90-item backlog item #10: a 3-way status filter (active/inactive/
// archived), not just the one all-users list this always was. status
// defaults to 'active' — archived (and, previously, deactivated) users
// mixed into the default view is exactly the clutter this item asked
// to fix.
async function listAllForAdmin(status = 'active') {
  const where = status === 'inactive'
    ? 'u.is_active = 0 AND u.is_archived = 0'
    : status === 'archived'
      ? 'u.is_archived = 1'
      : 'u.is_active = 1 AND u.is_archived = 0';
  return db.query(
    `SELECT u.id, u.name, u.email, u.phone, u.is_active, u.force_password_change,
            u.two_fa_enabled, u.last_login_at, u.locked_until, u.created_at, u.is_super_admin,
            u.is_protected_account, u.is_archived, u.archived_at,
            r.id AS role_id, r.name AS role_name
     FROM users u LEFT JOIN roles r ON r.id = u.role_id
     WHERE ${where}
     ORDER BY u.is_active DESC, u.name`
  );
}

/** Counts for each of the 3 status tabs, so a tab's own count never requires switching to it first. */
async function statusCounts() {
  const row = await db.queryOne(
    `SELECT
        SUM(CASE WHEN is_active = 1 AND is_archived = 0 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN is_active = 0 AND is_archived = 0 THEN 1 ELSE 0 END) AS inactive,
        SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) AS archived
     FROM users`
  );
  return {
    active: Number(row.active) || 0,
    inactive: Number(row.inactive) || 0,
    archived: Number(row.archived) || 0,
  };
}

async function archive(userId, archivedBy) {
  await db.execute(
    'UPDATE users SET is_archived = 1, archived_at = NOW(), archived_by = :archived_by, is_active = 0 WHERE id = :id',
    { archived_by: archivedBy, id: userId }
  );
}

async function unarchive(userId) {
  await db.execute(
    'UPDATE users SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = :id',
    { id: userId }
  );
}

/**
 * Real DELETE, deliberately with no manual "is this user referenced
 * anywhere" check first — users.id is a FK target from 57+ tables
 * (audit_log, documents.generated_by, every reviewer/approver column,
 * ...), all left at MySQL's default ON DELETE RESTRICT, so the
 * database itself is already the single, always-up-to-date source of
 * truth for "is this user referenced anywhere." Letting the constraint
 * violation happen and translating it into a clean error (see
 * usersController's delete action) avoids hand-maintaining a table
 * list that would silently go stale the moment a new FK to users is
 * added anywhere else in this schema.
 *
 * External QA report L-33: password_reset_tokens and notifications
 * rows are the one exception — they carry zero history value once the
 * user is gone (an expired/used reset token, a read-or-not
 * notification), yet their FK was enough to block the delete and show
 * "has order, document, or audit history" for a user with neither.
 * Cleared here first so only a REAL blocker (an order, document,
 * audit_log entry, ...) ever reaches that message.
 */
async function deleteUser(userId) {
  await db.execute('DELETE FROM password_reset_tokens WHERE user_id = :id', { id: userId });
  await db.execute('DELETE FROM notifications WHERE user_id = :id', { id: userId });
  await db.execute('DELETE FROM users WHERE id = :id', { id: userId });
}

async function create(name, email, phone, roleId, tempPasswordHash) {
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change)
     VALUES (:name, :email, :phone, :hash, :role_id, 1, 1)`,
    { name, email, phone, hash: tempPasswordHash, role_id: roleId }
  );
  return result.insertId;
}

async function setActive(userId, active) {
  await db.execute('UPDATE users SET is_active = :active WHERE id = :id', { active: active ? 1 : 0, id: userId });
}

async function update(userId, name, email, phone, roleId) {
  await db.execute(
    'UPDATE users SET name = :name, email = :email, phone = :phone, role_id = :role_id WHERE id = :id',
    { name, email, phone, role_id: roleId, id: userId }
  );
}

async function adminForceResetPassword(userId, tempPasswordHash) {
  await db.execute(
    `UPDATE users SET password_hash = :hash, force_password_change = 1,
            password_changed_at = NULL, failed_login_count = 0, locked_until = NULL
     WHERE id = :id`,
    { hash: tempPasswordHash, id: userId }
  );
}

/** docs/schema.sql Section AI — self-service email signature, appended to every email that user sends through the system. */
async function updateSignature(userId, signature) {
  await db.execute('UPDATE users SET email_signature = :signature WHERE id = :id', { signature, id: userId });
}

/** User-reported batch item 20 — the per-user "configurable option" opt-in/out for action-notification emails. */
async function updateNotificationPreference(userId, enabled) {
  await db.execute('UPDATE users SET email_action_notifications_enabled = :enabled WHERE id = :id', { enabled: enabled ? 1 : 0, id: userId });
}

module.exports = {
  findByEmail, findById, incrementFailedLogins, resetFailedLogins, lockUntil,
  updateLastLogin, updatePassword, flagPasswordExpired, setTwoFactor,
  listActive, listAllForAdmin, statusCounts, create, update, setActive, adminForceResetPassword,
  updateSignature, updateNotificationPreference, archive, unarchive, deleteUser,
};
