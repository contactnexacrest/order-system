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
  const rows = await db.query(
    `SELECT cl.*, c.email AS client_email, c.company_legal_name, c.client_unique_number, c.is_active AS client_is_active
     FROM client_logins cl
     JOIN clients c ON c.id = cl.client_id
     WHERE c.email = :email`,
    { email }
  );

  // CP-08: clients.email has no uniqueness constraint — two different
  // client records (a data-entry mistake, or a deliberate edit) can end up
  // sharing the same address. Picking one of several matches here (the old
  // query's LIMIT 1) would silently resolve "log in with this email" to an
  // arbitrary one of them, letting whichever client wins the tie be
  // reached under a shared identifier. Refuse the login entirely instead —
  // this surfaces as an ordinary "invalid credentials" to whoever's
  // trying, and staff must resolve the duplicate (a distinct email, or a
  // genuine merge via clients.duplicate_of_client_id) before either
  // account can log in again.
  if (rows.length !== 1) {
    if (rows.length > 1) {
      console.error(`[CLIENT PORTAL] Ambiguous login email '${email}' matches ${rows.length} client_logins rows — refusing login until the duplicate is resolved.`);
    }
    return null;
  }

  return rows[0];
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
