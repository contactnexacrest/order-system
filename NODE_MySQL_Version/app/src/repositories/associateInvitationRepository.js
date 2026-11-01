'use strict';

const crypto = require('crypto');
const db = require('../config/db');

/** Port of AssociateInvitationRepository.php — an associate's one-off client links and personal-code registrations (AS-5). */
const STATUSES = {
  created: 'Link created', opened: 'Opened', registered: 'Registered', accepted: 'Accepted — your client',
  not_accepted: 'Not accepted', expired: 'Expired', cancelled: 'Cancelled',
};
const LINK_DAYS = 30;

async function createLink(associateId, company, country) {
  const token = crypto.randomBytes(16).toString('hex');
  const r = await db.execute(
    `INSERT INTO associate_invitations (associate_id, kind, token, company_name, country, status, expires_at)
     VALUES (:a, 'client_link', :t, :c, :k, 'created', DATE_ADD(NOW(), INTERVAL ${LINK_DAYS} DAY))`,
    { a: associateId, t: token, c: company, k: country }
  );
  return { id: r.insertId, token };
}

/** A buyer who registered through the personal code gets a row of their own, so it shows like any invitation. */
async function createForCode(associateId, company, country) {
  const r = await db.execute(
    "INSERT INTO associate_invitations (associate_id, kind, company_name, country, status) VALUES (:a, 'personal_code', :c, :k, 'opened')",
    { a: associateId, c: company, k: country }
  );
  return r.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM associate_invitations WHERE id = :id', { id });
}

async function findByToken(token) {
  if (!/^[a-f0-9]{32}$/.test(String(token || ''))) {
    return null;
  }
  return db.queryOne('SELECT * FROM associate_invitations WHERE token = :t', { t: token });
}

/** A link that can still be used: created / opened, not past its date (expires_at is IST wall time, like NOW()). */
function isUsable(inv) {
  if (!['created', 'opened'].includes(inv.status)) return false;
  // QA Round 8, R8-N04: one registration per link — a link a buyer already submitted through is used.
  if (inv.submission_id) return false;
  if (inv.expires_at === null || inv.expires_at === undefined) return true;
  const nowIst = new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
  return String(inv.expires_at) > nowIst;
}

async function setStatus(id, status, extra = {}) {
  const sets = ['status = :s'];
  const params = { s: status, id };
  for (const [k, v] of Object.entries(extra)) {
    sets.push(`${k} = :${k}`);
    params[k] = v ?? null;
  }
  if (['accepted', 'not_accepted'].includes(status)) {
    sets.push('decided_at = NOW()');
  }
  if (status === 'opened') {
    sets.push('opened_at = COALESCE(opened_at, NOW())');
  }
  await db.execute(`UPDATE associate_invitations SET ${sets.join(', ')} WHERE id = :id`, params);
}

/** Links past their 30 days become "expired". */
async function expireOld() {
  const r = await db.execute("UPDATE associate_invitations SET status = 'expired' WHERE status IN ('created', 'opened') AND kind = 'client_link' AND expires_at < NOW()");
  return r.affectedRows;
}

async function forAssociate(associateId) {
  await expireOld();
  return db.query('SELECT * FROM associate_invitations WHERE associate_id = :a ORDER BY id DESC', { a: associateId });
}

/**
 * QA Round 8, R8-N04: takes a one-off link for this registration, at once
 * and only if no other registration took it first (two buyers submitting
 * through the same link at the same moment: only one wins).
 */
async function claim(id, submissionId) {
  const r = await db.execute(
    `UPDATE associate_invitations SET submission_id = :s, status = 'opened', opened_at = COALESCE(opened_at, NOW())
      WHERE id = :id AND submission_id IS NULL AND status IN ('created', 'opened') AND (expires_at IS NULL OR expires_at > NOW())`,
    { s: submissionId, id }
  );
  return r.affectedRows === 1;
}

module.exports = { STATUSES, LINK_DAYS, createLink, createForCode, find, findByToken, isUsable, claim, setStatus, expireOld, forAssociate };
