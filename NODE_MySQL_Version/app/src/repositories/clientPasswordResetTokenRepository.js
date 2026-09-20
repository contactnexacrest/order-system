'use strict';

const db = require('../config/db');

// Port of App\Repositories\ClientPasswordResetTokenRepository — mirrors
// passwordResetTokenRepository.js exactly, scoped to clients instead of
// staff users.

async function create(clientId, tokenHash, expiresAt, requestedIp) {
  const result = await db.execute(
    'INSERT INTO client_password_reset_tokens (client_id, token_hash, requested_ip, expires_at) VALUES (:client_id, :token_hash, :ip, :expires_at)',
    { client_id: clientId, token_hash: tokenHash, ip: requestedIp, expires_at: expiresAt }
  );
  return result.insertId;
}

async function findValidByHash(tokenHash) {
  const row = await db.queryOne(
    `SELECT cprt.*, c.email AS client_email, c.company_legal_name, c.is_active AS client_is_active
     FROM client_password_reset_tokens cprt
     JOIN clients c ON c.id = cprt.client_id
     WHERE cprt.token_hash = :hash AND cprt.used_at IS NULL AND cprt.expires_at > NOW()
     LIMIT 1`,
    { hash: tokenHash }
  );
  if (!row || !row.client_is_active) return null;
  return row;
}

async function countRecentForClient(clientId, withinMinutes) {
  const minutes = parseInt(withinMinutes, 10);
  const row = await db.queryOne(
    `SELECT COUNT(*) AS c FROM client_password_reset_tokens
     WHERE client_id = :client_id AND created_at > (NOW() - INTERVAL ${minutes} MINUTE)`,
    { client_id: clientId }
  );
  return row ? parseInt(row.c, 10) : 0;
}

async function markUsed(id) {
  await db.execute('UPDATE client_password_reset_tokens SET used_at = NOW() WHERE id = :id', { id });
}

async function invalidateAllForClient(clientId) {
  await db.execute('UPDATE client_password_reset_tokens SET used_at = NOW() WHERE client_id = :client_id AND used_at IS NULL', { client_id: clientId });
}

module.exports = { create, findValidByHash, countRecentForClient, markUsed, invalidateAllForClient };
