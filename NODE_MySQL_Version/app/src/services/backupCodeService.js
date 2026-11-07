'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const passwordHash = require('../helpers/passwordHash');
const auditLogRepository = require('../repositories/auditLogRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const userRepository = require('../repositories/userRepository');
const superAdminService = require('./superAdminService');

// Port of App\Services\BackupCodeService — emergency backup codes (owner
// decision D-265, 7 Nov 2026). If e-mail is down nobody receives a login code,
// including the people who would fix it. So the accounts that run the company
// (an effective Super Admin, or anyone holding "Manage company settings" — the
// MD and Admin roles) may generate 10 one-time backup codes on My Account and use
// one instead of the e-mailed code. Stored hashed (two_fa_backup_codes); shown
// once; a new set replaces the old one. Every use is audited and the
// company-settings holders are told.

const COUNT = 10;
const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I

async function eligible(userId) {
  if (await superAdminService.isEffective(userId)) return true;
  const user = await userRepository.findById(userId);
  if (!user) return false;
  const perms = await permissionRepository.effectivePermissions(userId, user.role_id === null ? null : Number(user.role_id));
  return !!perms.manage_company_settings;
}

async function remaining(userId) {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM two_fa_backup_codes WHERE user_id = :u AND used_at IS NULL', { u: userId });
  return Number(row.n);
}

/** "ABCD-EFGH" form, upper case, no separators or spaces counted. */
function normalise(code) {
  return String(code || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
}

/** @returns {Promise<string[]>} the new codes, shown once */
async function generate(userId) {
  const codes = [];
  const hashes = [];
  for (let i = 0; i < COUNT; i++) {
    let raw = '';
    for (let j = 0; j < 8; j++) raw += ALPHABET[crypto.randomInt(0, ALPHABET.length)];
    codes.push(`${raw.slice(0, 4)}-${raw.slice(4)}`);
    hashes.push(await passwordHash.hash(raw, 10));
  }
  await db.transaction(async (tx) => {
    await tx.execute('DELETE FROM two_fa_backup_codes WHERE user_id = :u', { u: userId });
    for (const h of hashes) {
      await tx.execute('INSERT INTO two_fa_backup_codes (user_id, code_hash) VALUES (:u, :h)', { u: userId, h });
    }
  });
  await auditLogRepository.log(userId, 'BACKUP_CODES_GENERATED', 'users', userId, null, null, null, `${COUNT} new backup codes; the earlier set no longer works`);
  return codes;
}

/** Uses one code if it matches an unused one. */
async function consume(userId, code) {
  const raw = normalise(code);
  if (raw.length !== 8) return false;
  const rows = await db.query('SELECT id, code_hash FROM two_fa_backup_codes WHERE user_id = :u AND used_at IS NULL', { u: userId });
  for (const row of rows) {
    if (await passwordHash.verify(raw, row.code_hash)) {
      const r = await db.execute('UPDATE two_fa_backup_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL', { id: row.id });
      return r.affectedRows === 1;
    }
  }
  return false;
}

/** Audit + an in-app notice and e-mail to everyone holding "Manage company settings". */
async function recordUse(userId) {
  const user = await userRepository.findById(userId);
  const left = await remaining(userId);
  const who = user ? user.email : `user #${userId}`;
  await auditLogRepository.log(userId, 'LOGIN_BACKUP_CODE_USED', 'users', userId, null, null, null, `Signed in with a backup code; ${left} left`);
  const message = `Security: ${who} signed in with an emergency backup code instead of the e-mailed login code (${left} backup code(s) left). If this was not expected, reset that login at once.`;
  for (const holderId of await permissionRepository.usersWithPermission('manage_company_settings')) {
    await notificationRepository.create(Number(holderId), null, 'backup_code_used', null, message);
  }
}

module.exports = { COUNT, eligible, remaining, normalise, generate, consume, recordUse };
