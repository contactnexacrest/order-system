'use strict';

const db = require('../config/db');

// Port of App\Repositories\ClientLoginRepository. client_logins — one row
// per client, created ONLY by clientPortalService.provisionIfNeeded() from
// the Stage 3 advance-cleared gate. No row = that client cannot log in,
// full stop.

async function findByClientId(clientId) {
  return db.queryOne('SELECT * FROM client_logins WHERE client_id = :cid', { cid: clientId });
}

/**
 * Joins clients so login can look up by the client's own email in one
 * query — the client portal has no separate login-identifier column.
 */
async function findByEmail(email) {
  return db.queryOne(
    `SELECT cl.*, c.email AS client_email, c.company_legal_name, c.client_unique_number, c.is_active AS client_is_active
     FROM client_logins cl
     JOIN clients c ON c.id = cl.client_id
     WHERE c.email = :email
     LIMIT 1`,
    { email }
  );
}

async function findById(id) {
  return db.queryOne(
    `SELECT cl.*, c.email AS client_email, c.company_legal_name, c.client_unique_number, c.is_active AS client_is_active
     FROM client_logins cl JOIN clients c ON c.id = cl.client_id
     WHERE cl.client_id = :cid`,
    { cid: id }
  );
}

async function create(clientId, passwordHash, createdByOrderId) {
  const result = await db.execute(
    'INSERT INTO client_logins (client_id, password_hash, created_by_order_id) VALUES (:cid, :hash, :order_id)',
    { cid: clientId, hash: passwordHash, order_id: createdByOrderId }
  );
  return result.insertId;
}

async function incrementFailedLogins(clientId) {
  await db.execute('UPDATE client_logins SET failed_login_count = failed_login_count + 1 WHERE client_id = :cid', { cid: clientId });
}

async function resetFailedLogins(clientId) {
  await db.execute('UPDATE client_logins SET failed_login_count = 0, locked_until = NULL WHERE client_id = :cid', { cid: clientId });
}

async function lockUntil(clientId, until) {
  await db.execute('UPDATE client_logins SET locked_until = :until WHERE client_id = :cid', { until, cid: clientId });
}

async function updateLastLogin(clientId) {
  await db.execute('UPDATE client_logins SET last_login_at = NOW() WHERE client_id = :cid', { cid: clientId });
}

async function updatePassword(clientId, passwordHash, forcePasswordChange) {
  await db.execute(
    'UPDATE client_logins SET password_hash = :hash, force_password_change = :force, password_changed_at = NOW() WHERE client_id = :cid',
    { hash: passwordHash, force: forcePasswordChange ? 1 : 0, cid: clientId }
  );
}

module.exports = {
  findByClientId, findByEmail, findById, create, incrementFailedLogins,
  resetFailedLogins, lockUntil, updateLastLogin, updatePassword,
};
