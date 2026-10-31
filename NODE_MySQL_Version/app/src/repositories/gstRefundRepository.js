'use strict';

/**
 * Owner decision 2026-10-31 (option A, confirmed): GST under LUT. NexaCrest
 * exports under LUT and sells nothing in India, so the GST paid on purchases
 * comes back as a refund, claimed for a whole period (RFD-01), not per
 * order. Each claim, the money the bank credited for it (each credit goes
 * to Zoho Books as a journal) and any amount refused. The order's cost and
 * profit keep the GST — it left the bank. Port of GstRefundRepository.
 */
const db = require('../config/db');

const PARTS = { provisional: 'provisional', final: 'final / balance' };
const r2 = (v) => Math.round(Number(v) * 100) / 100;

/** Every claim, newest period first, with what was credited. */
async function claims() {
  return db.query(
    `SELECT c.*, COALESCE(SUM(r.amount), 0) AS amount_credited, COUNT(r.id) AS credits
       FROM gst_refund_claims c
       LEFT JOIN gst_refund_credits r ON r.claim_id = c.id
      GROUP BY c.id, c.period_from, c.period_to, c.claim_arn, c.filed_on, c.amount_claimed, c.amount_refused, c.refused_note, c.recorded_by, c.created_at
      ORDER BY c.period_from DESC, c.id DESC`
  );
}

async function findClaim(id) {
  return db.queryOne(
    'SELECT c.*, (SELECT COALESCE(SUM(amount), 0) FROM gst_refund_credits WHERE claim_id = c.id) AS amount_credited FROM gst_refund_claims c WHERE c.id = :id',
    { id }
  );
}

async function arnTaken(arn) {
  return !!(await db.queryOne('SELECT 1 AS x FROM gst_refund_claims WHERE claim_arn = :arn', { arn }));
}

async function createClaim(periodFrom, periodTo, arn, filedOn, amountClaimed, userId) {
  const result = await db.execute(
    'INSERT INTO gst_refund_claims (period_from, period_to, claim_arn, filed_on, amount_claimed, recorded_by) VALUES (:f, :t, :arn, :filed, :amt, :u)',
    { f: periodFrom, t: periodTo, arn, filed: filedOn, amt: r2(amountClaimed), u: userId }
  );
  return result.insertId;
}

/** An amount the officer refused: it adds to the claim's refused total (a real cost, booked in Zoho by the CA). */
async function addRefused(claimId, amount, note) {
  await db.execute(
    `UPDATE gst_refund_claims SET amount_refused = amount_refused + :amt,
            refused_note = TRIM(BOTH ' · ' FROM CONCAT(COALESCE(refused_note, ''), ' · ', :note)) WHERE id = :id`,
    { amt: r2(amount), note, id: claimId }
  );
}

async function addCredit(claimId, amount, creditedOn, bankReference, part, userId) {
  const result = await db.execute(
    'INSERT INTO gst_refund_credits (claim_id, amount, credited_on, bank_reference, part, recorded_by) VALUES (:c, :amt, :d, :ref, :part, :u)',
    { c: claimId, amt: r2(amount), d: creditedOn, ref: String(bankReference).trim(), part, u: userId }
  );
  return result.insertId;
}

async function creditsFor(claimId) {
  return db.query('SELECT * FROM gst_refund_credits WHERE claim_id = :c ORDER BY credited_on, id', { c: claimId });
}

/** The credit with its claim's period and claim no. */
async function findCredit(id) {
  return db.queryOne(
    'SELECT r.*, c.period_from, c.period_to, c.claim_arn FROM gst_refund_credits r JOIN gst_refund_claims c ON c.id = r.claim_id WHERE r.id = :id',
    { id }
  );
}

/** A credit typed wrongly can be removed only while it is not in Zoho Books. Returns whether it was removed. */
async function deleteCredit(id) {
  const result = await db.execute('DELETE FROM gst_refund_credits WHERE id = :id AND zoho_journal_id IS NULL', { id });
  return result.affectedRows === 1;
}

/** Credits not in Zoho Books yet (from the go-live date on), with their claim. */
async function creditsForZohoSync(startDate) {
  return db.query(
    `SELECT r.*, c.period_from, c.period_to, c.claim_arn FROM gst_refund_credits r JOIN gst_refund_claims c ON c.id = r.claim_id
      WHERE r.zoho_journal_id IS NULL AND (:start IS NULL OR r.credited_on >= :start2) ORDER BY r.credited_on, r.id`,
    { start: startDate, start2: startDate }
  );
}

async function setCreditZohoJournal(id, journalId) {
  await db.execute('UPDATE gst_refund_credits SET zoho_journal_id = :j, zoho_synced_at = NOW() WHERE id = :id', { j: journalId, id });
}

/** GST on the tax invoices (supplier and logistics bills) dated in a period — what the period's claim is built from. */
async function gstOnBills(from, to) {
  const row = await db.queryOne(
    "SELECT COALESCE(SUM(gst_inr), 0) AS gst FROM order_vendor_bills WHERE doc_kind = 'invoice' AND bill_date BETWEEN :f AND :t",
    { f: from, t: to }
  );
  return r2(row ? row.gst : 0);
}

/** GST on one order's tax invoices: { supplier, logistics, total }. */
async function gstOnOrderBills(orderId) {
  const out = { supplier: 0, logistics: 0 };
  for (const row of await db.query(
    "SELECT vendor_kind, COALESCE(SUM(gst_inr), 0) AS gst FROM order_vendor_bills WHERE order_id = :o AND doc_kind = 'invoice' GROUP BY vendor_kind",
    { o: orderId }
  )) {
    out[row.vendor_kind === 'supplier' ? 'supplier' : 'logistics'] += r2(row.gst);
  }
  return { ...out, total: r2(out.supplier + out.logistics) };
}

/** Refunds the bank credited between two dates (company-level income, e.g. the revenue report's period). */
async function creditedBetween(from, to) {
  const row = await db.queryOne('SELECT COALESCE(SUM(amount), 0) AS total FROM gst_refund_credits WHERE credited_on BETWEEN :f AND :t', { f: from, t: to });
  return r2(row ? row.total : 0);
}

module.exports = {
  PARTS, claims, findClaim, arnTaken, createClaim, addRefused, addCredit, creditsFor, findCredit, deleteCredit,
  creditsForZohoSync, setCreditZohoJournal, gstOnBills, gstOnOrderBills, creditedBetween,
};
