'use strict';

const db = require('../config/db');

async function create(userId, tokenHash, expiresAt, requestedIp) {
  const result = await db.execute(
    'INSERT INTO password_reset_tokens (user_id, token_hash, requested_ip, expires_at) VALUES (:user_id, :token_hash, :ip, :expires_at)',
    { user_id: userId, token_hash: tokenHash, ip: requestedIp, expires_at: expiresAt }
  );
  return result.insertId;
}

async function findValidByHash(tokenHash) {
  const row = await db.queryOne(
    `SELECT prt.*, u.email AS user_email, u.name AS user_name, u.is_active AS user_is_active
     FROM password_reset_tokens prt
     JOIN users u ON u.id = prt.user_id
     WHERE prt.token_hash = :hash
       AND prt.used_at IS NULL
       AND prt.expires_at > NOW()
     LIMIT 1`,
    { hash: tokenHash }
  );
  if (!row || !row.user_is_active) return null;
  return row;
}

async function countRecentForUser(userId, withinMinutes) {
  const minutes = parseInt(withinMinutes, 10);
  const row = await db.queryOne(
    `SELECT COUNT(*) AS c FROM password_reset_tokens
     WHERE user_id = :user_id AND created_at > (NOW() - INTERVAL ${minutes} MINUTE)`,
    { user_id: userId }
  );
  return row ? parseInt(row.c, 10) : 0;
}

async function markUsed(id) {
  await db.execute('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id', { id });
}

async function invalidateAllForUser(userId) {
  await db.execute('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL', { user_id: userId });
}

module.exports = { create, findValidByHash, countRecentForUser, markUsed, invalidateAllForUser };
