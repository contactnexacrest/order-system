'use strict';

const db = require('../config/db');
const encryptionService = require('../services/encryptionService');

/**
 * Port of AssociateBankRepository.php. Owner decision 1 Nov 2026 (AS-7): an
 * associate's bank details. A new or changed account is a request: payments
 * keep going to the verified account until two different Accounts people
 * have checked the new one; it is then usable after a waiting period. The
 * number is stored encrypted and shown masked; every version is kept.
 */

async function request(associateId, d, byAssociate, userId) {
  const number = String(d.account_number || '').replace(/\s+/g, '');
  const result = await db.execute(
    `INSERT INTO associate_bank_accounts (associate_id, holder_name, account_number_enc, account_last4, ifsc, swift, bank_name, bank_branch_address,
        intermediary_bank, account_currency, proof_file_id, requested_by_associate, requested_by_user)
     VALUES (:a, :h, :n, :l4, :ifsc, :swift, :bank, :addr, :inter, :cur, :proof, :ba, :u)`,
    {
      a: associateId, h: d.holder_name, n: encryptionService.encrypt(number), l4: number.slice(-4),
      ifsc: d.ifsc || null, swift: d.swift || null, bank: d.bank_name, addr: d.bank_branch_address || null,
      inter: d.intermediary_bank || null, cur: d.account_currency, proof: d.proof_file_id ?? null,
      ba: byAssociate ? 1 : 0, u: userId ?? null,
    }
  );
  return result.insertId;
}

/** Newest first, without the encrypted number. */
async function forAssociate(associateId) {
  return db.query(
    `SELECT b.id, b.associate_id, b.status, b.holder_name, b.account_last4, b.ifsc, b.swift, b.bank_name, b.bank_branch_address, b.intermediary_bank,
            b.account_currency, b.proof_file_id, b.requested_by_associate, b.requested_at, b.check_method, b.first_checked_by, b.first_checked_at,
            b.verified_by, b.verified_at, b.usable_from, b.rejected_reason, u1.name AS first_checked_by_name, u2.name AS verified_by_name
       FROM associate_bank_accounts b LEFT JOIN users u1 ON u1.id = b.first_checked_by LEFT JOIN users u2 ON u2.id = b.verified_by
      WHERE b.associate_id = :a ORDER BY b.id DESC`,
    { a: associateId }
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM associate_bank_accounts WHERE id = :id', { id });
}

/** The full account number (Finance / MD only — the caller checks and audit-logs). */
async function fullNumber(id) {
  const row = await find(id);
  return row ? encryptionService.decrypt(String(row.account_number_enc)) : null;
}

function todayIst() {
  return new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
}

/** The verified account payments go to today, if any. */
async function usable(associateId, onDate = null) {
  return db.queryOne(
    "SELECT * FROM associate_bank_accounts WHERE associate_id = :a AND status = 'verified' AND usable_from <= :d ORDER BY verified_at DESC, id DESC LIMIT 1",
    { a: associateId, d: onDate || todayIst() }
  );
}

async function pendingCount() {
  return Number((await db.queryOne("SELECT COUNT(*) AS n FROM associate_bank_accounts WHERE status = 'pending'")).n);
}

/** First of the two checks. Returns false if the request is no longer pending. */
async function firstCheck(id, userId, method) {
  const r = await db.execute(
    `UPDATE associate_bank_accounts SET first_checked_by = :u, first_checked_at = NOW(), check_method = :m
      WHERE id = :id AND status = 'pending' AND first_checked_by IS NULL`,
    { u: userId, m: method, id }
  );
  return r.affectedRows === 1;
}

/** Second check by a different person: verified, usable after the waiting days; until then the older verified account keeps being used. */
async function verify(id, userId, waitDays) {
  return db.transaction(async (c) => {
    const b = await c.queryOne("SELECT * FROM associate_bank_accounts WHERE id = :id AND status = 'pending' FOR UPDATE", { id });
    if (!b || b.first_checked_by === null || Number(b.first_checked_by) === Number(userId)) {
      return false;
    }
    // The older verified account stays in use until the new one's waiting days are over (usable() picks the newest usable one).
    await c.execute(
      `UPDATE associate_bank_accounts SET status = 'verified', verified_by = :u, verified_at = NOW(), usable_from = DATE_ADD(CURDATE(), INTERVAL ${parseInt(waitDays, 10) || 0} DAY) WHERE id = :id`,
      { u: userId, id }
    );
    return true;
  });
}

async function reject(id, reason) {
  const r = await db.execute("UPDATE associate_bank_accounts SET status = 'rejected', rejected_reason = :r WHERE id = :id AND status = 'pending'", { r: reason, id });
  return r.affectedRows === 1;
}

/** "HDFC Bank ••••4321" */
function masked(b) {
  return `${b.bank_name} ••••${b.account_last4}`;
}

module.exports = { request, forAssociate, find, fullNumber, usable, pendingCount, firstCheck, verify, reject, masked };
