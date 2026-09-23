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

async function listAllForAdmin() {
  return db.query(
    `SELECT u.id, u.name, u.email, u.phone, u.is_active, u.force_password_change,
            u.two_fa_enabled, u.last_login_at, u.locked_until, u.created_at, u.is_super_admin,
            u.is_protected_account,
            r.id AS role_id, r.name AS role_name
     FROM users u LEFT JOIN roles r ON r.id = u.role_id
     ORDER BY u.is_active DESC, u.name`
  );
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

module.exports = {
  findByEmail, findById, incrementFailedLogins, resetFailedLogins, lockUntil,
  updateLastLogin, updatePassword, flagPasswordExpired, setTwoFactor,
  listActive, listAllForAdmin, create, update, setActive, adminForceResetPassword,
};
