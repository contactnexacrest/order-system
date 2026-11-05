'use strict';

const crypto = require('crypto');
const db = require('../config/db');

/**
 * Port of AssociateRegistrationRepository.php. Owner decision D-253 (4 Nov 2026, A1):
 * the MD's one-use, time-limited associate registration links and the
 * registrations they bring, reviewed like Quotation Intake.
 */
const STATUSES = { pending: 'Waiting for review', accepted: 'Accepted', rejected: 'Rejected' };

const sha256 = (v) => crypto.createHash('sha256').update(String(v)).digest('hex');

async function linkDays() {
  const v = parseInt((await require('./incentiveRepository').settings()).associate_registration_link_days ?? '7', 10);
  return Number.isNaN(v) || v <= 0 ? 7 : Math.min(v, 90);
}

/** Creates a link; returns the raw token (shown once). */
async function createLink(note, userId) {
  const raw = crypto.randomBytes(24).toString('hex');
  await db.execute('INSERT INTO associate_registration_links (token_hash, note, created_by, expires_at) VALUES (:h, :n, :u, DATE_ADD(NOW(), INTERVAL :d DAY))',
    { h: sha256(raw), n: note || null, u: userId, d: await linkDays() });
  return raw;
}

async function usableLink(raw) {
  return db.queryOne('SELECT * FROM associate_registration_links WHERE token_hash = :h AND used_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()', { h: sha256(raw) });
}

/** Takes the link for this registration at once (one use, safe against a double submit). */
async function claimLink(linkId) {
  const r = await db.execute('UPDATE associate_registration_links SET used_at = NOW() WHERE id = :id AND used_at IS NULL AND cancelled_at IS NULL AND expires_at > NOW()', { id: linkId });
  return r.affectedRows === 1;
}

async function cancelLink(linkId) {
  await db.execute('UPDATE associate_registration_links SET cancelled_at = NOW() WHERE id = :id AND used_at IS NULL AND cancelled_at IS NULL', { id: linkId });
}

async function links() {
  return db.query('SELECT l.*, u.name AS created_by_name, (l.expires_at <= NOW()) AS expired FROM associate_registration_links l JOIN users u ON u.id = l.created_by ORDER BY l.id DESC LIMIT 100');
}

async function create(linkId, d, ip) {
  const r = await db.execute(`INSERT INTO associate_registrations (link_id, name, email, phone, country, based_in, city, consent_at, ip_address)
                              VALUES (:l, :n, :e, :p, :c, :b, :ci, NOW(), :ip)`,
  { l: linkId, n: d.name, e: d.email, p: d.phone, c: d.country, b: d.based_in, ci: d.city, ip: ip || null });
  return r.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM associate_registrations WHERE id = :id', { id });
}

async function all(status) {
  let sql = 'SELECT r.*, u.name AS reviewed_by_name FROM associate_registrations r LEFT JOIN users u ON u.id = r.reviewed_by';
  const params = {};
  if (status && STATUSES[status]) {
    sql += ' WHERE r.status = :s';
    params.s = status;
  }
  return db.query(`${sql} ORDER BY r.id DESC LIMIT 200`, params);
}

/** Moves a pending registration to accepted / rejected exactly once. */
async function decide(id, status, reason, userId, associateId) {
  const r = await db.execute("UPDATE associate_registrations SET status = :s, reject_reason = :r, reviewed_by = :u, reviewed_at = NOW(), associate_id = :a WHERE id = :id AND status = 'pending'",
    { s: status, r: reason || null, u: userId, a: associateId || null, id });
  return r.affectedRows === 1;
}

async function pendingCount() {
  const row = await db.queryOne("SELECT COUNT(*) AS n FROM associate_registrations WHERE status = 'pending'");
  return Number(row ? row.n : 0);
}

module.exports = { STATUSES, linkDays, createLink, usableLink, claimLink, cancelLink, links, create, find, all, decide, pendingCount };
