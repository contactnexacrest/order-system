'use strict';

const db = require('../config/db');
const encryptionService = require('../services/encryptionService');

/**
 * Port of AssociateBankRepository.php. Owner decision 1 Nov 2026 (AS-7): an
 * associate's bank details. A new or changed account is a request: payments
 * keep going to the verified account until two different Accounts people
 * have checked the new one; it is then usable after a waiting period. The
 * number is stored encrypted and shown masked; every version is kept.
 *
 * Owner decisions 11 Nov 2026 (D-293): a new request starts as
 * 'awaiting_confirmation' (associateBankConfirmation.start) — the associate
 * confirms it in the portal ('pending' = confirmed, waiting for staff) or it
 * is cancelled ('This is not me', or 7 days without an answer). Staff then
 * verify it: one person (default) or two (setting).
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
            b.account_currency, b.proof_file_id, b.requested_by_associate, b.requested_by_user, b.requested_at, b.check_method, b.first_checked_by, b.first_checked_at,
            b.verified_by, b.verified_at, b.usable_from, b.rejected_reason, b.confirm_by, b.confirmed_at, b.reminded_at, b.cancelled_at, b.cancelled_how,
            b.check_bank_proof, b.check_phone, b.check_test_payment, b.test_payment_ref, b.verify_note, b.self_verify_reason,
            u1.name AS first_checked_by_name, u2.name AS verified_by_name, u3.name AS requested_by_name
       FROM associate_bank_accounts b LEFT JOIN users u1 ON u1.id = b.first_checked_by LEFT JOIN users u2 ON u2.id = b.verified_by LEFT JOIN users u3 ON u3.id = b.requested_by_user
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

/** D-293: does the associate already have a verified account (then a new one is a change: the longer wait)? */
async function hasVerified(associateId, exceptId = null) {
  return !!(await db.queryOne("SELECT 1 AS x FROM associate_bank_accounts WHERE associate_id = :a AND status = 'verified' AND id <> :x LIMIT 1", { a: associateId, x: exceptId || 0 }));
}

/** D-293: the request waiting for the associate's confirmation (newest), still within its 7 days. */
async function awaiting(associateId) {
  return db.queryOne("SELECT * FROM associate_bank_accounts WHERE associate_id = :a AND status = 'awaiting_confirmation' AND confirm_by > NOW() ORDER BY id DESC LIMIT 1", { a: associateId });
}

/** D-293: the request now waits for the associate's confirmation for `days` days; older open requests are replaced. */
async function markAwaiting(id, associateId, days) {
  await db.execute(`UPDATE associate_bank_accounts SET status = 'cancelled', cancelled_at = NOW(), cancelled_how = 'replaced'
                     WHERE associate_id = :a AND id <> :id AND status IN ('awaiting_confirmation','pending')`, { a: associateId, id });
  await db.execute(`UPDATE associate_bank_accounts SET status = 'awaiting_confirmation', confirm_by = DATE_ADD(NOW(), INTERVAL ${parseInt(days, 10) || 7} DAY) WHERE id = :id`, { id });
}

async function setCode(id, hash, minutes) {
  await db.execute(`UPDATE associate_bank_accounts SET confirm_code_hash = :h, confirm_code_expires = DATE_ADD(NOW(), INTERVAL ${parseInt(minutes, 10) || 10} MINUTE), confirm_code_attempts = 0 WHERE id = :id`, { h: hash, id });
}

async function addCodeAttempt(id) {
  await db.execute('UPDATE associate_bank_accounts SET confirm_code_attempts = confirm_code_attempts + 1 WHERE id = :id', { id });
}

/** Is the code still valid (not expired)? */
async function codeLive(id) {
  return !!(await db.queryOne('SELECT 1 AS x FROM associate_bank_accounts WHERE id = :id AND confirm_code_hash IS NOT NULL AND confirm_code_expires > NOW()', { id }));
}

/** The associate confirmed: now waiting for staff ('pending'). False if no longer waiting. */
async function markConfirmed(id) {
  const r = await db.execute(`UPDATE associate_bank_accounts SET status = 'pending', confirmed_at = NOW(), confirm_code_hash = NULL, confirm_code_expires = NULL
                               WHERE id = :id AND status = 'awaiting_confirmation'`, { id });
  return r.affectedRows === 1;
}

/** Cancelled: 'not_me' (the associate), 'expired' (7 days) or 'replaced'. False if no longer waiting. */
async function cancel(id, how) {
  const r = await db.execute(`UPDATE associate_bank_accounts SET status = 'cancelled', cancelled_at = NOW(), cancelled_how = :h, confirm_code_hash = NULL
                               WHERE id = :id AND status IN ('awaiting_confirmation','pending')`, { h: how, id });
  return r.affectedRows === 1;
}

async function markReminded(id) {
  await db.execute('UPDATE associate_bank_accounts SET reminded_at = NOW() WHERE id = :id', { id });
}

/** Requests still waiting for the associate after their confirm-by time. */
async function overdue() {
  return db.query("SELECT * FROM associate_bank_accounts WHERE status = 'awaiting_confirmation' AND confirm_by IS NOT NULL AND confirm_by < NOW() ORDER BY id");
}

/** D-293: the "how I checked" ticks. */
async function saveChecks(id, checks, selfReason) {
  await db.execute(`UPDATE associate_bank_accounts SET check_bank_proof = :bp, check_phone = :ph, check_test_payment = :tp, test_payment_ref = :ref, verify_note = :note,
                           self_verify_reason = COALESCE(:sr, self_verify_reason) WHERE id = :id`,
  { bp: checks.check_bank_proof, ph: checks.check_phone, tp: checks.check_test_payment, ref: checks.test_payment_ref, note: checks.verify_note, sr: selfReason ?? null, id });
}

/** D-293, one person: verified straight from 'pending' (confirmed); usable after the waiting days. */
async function verifyOne(id, userId, waitDays) {
  const r = await db.execute(
    `UPDATE associate_bank_accounts SET status = 'verified', verified_by = :u, verified_at = NOW(), usable_from = DATE_ADD(CURDATE(), INTERVAL ${parseInt(waitDays, 10) || 0} DAY)
      WHERE id = :id AND status = 'pending'`, { u: userId, id });
  return r.affectedRows === 1;
}

/** "HDFC Bank ••••4321" */
function masked(b) {
  return `${b.bank_name} ••••${b.account_last4}`;
}

module.exports = {
  request, forAssociate, find, fullNumber, usable, pendingCount, firstCheck, verify, reject, masked,
  hasVerified, awaiting, markAwaiting, setCode, addCodeAttempt, codeLive, markConfirmed, cancel, markReminded, overdue, saveChecks, verifyOne,
};
