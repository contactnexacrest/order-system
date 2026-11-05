'use strict';

/**
 * Owner decision D-215 (5a): an order's own payment schedule. The first
 * step is the advance and the last the balance (order_payment_status, as
 * always); the steps in between live here, each tracked as its own payment
 * — remittance with bank reference, cleared, pushed to Zoho Books. An order
 * without rows here works exactly as before. Port of
 * App\Repositories\PaymentMilestoneRepository.
 */
const db = require('../config/db');
const zohoGuard = require('../services/zohoGuard');

const MAX_STEPS = 4;

async function forOrder(orderId) {
  return db.query('SELECT * FROM order_payment_milestones WHERE order_id = :o ORDER BY seq', { o: orderId });
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_payment_milestones WHERE id = :id', { id });
}

async function totalPct(orderId) {
  const row = await db.queryOne('SELECT COALESCE(SUM(pct), 0) AS total FROM order_payment_milestones WHERE order_id = :o', { o: orderId });
  return parseFloat(row.total);
}

/** D-215: the milestone money received so far (what the buyer no longer owes). */
async function receivedTotal(orderId) {
  const row = await db.queryOne('SELECT COALESCE(SUM(received_amount), 0) AS total FROM order_payment_milestones WHERE order_id = :o', { o: orderId });
  return Math.round(parseFloat(row.total) * 100) / 100;
}

/** D-215: the milestones' planned amounts (each its share of the FOB value) — what the balance leg leaves out. */
async function plannedTotal(orderId, fobTotal) {
  const total = (await forOrder(orderId)).reduce((sum, m) => sum + Math.round(Number(fobTotal) * parseFloat(m.pct)) / 100, 0);
  return Math.round(total * 100) / 100;
}

/**
 * QA batch 4, MON-2 (owner double-confirmed 5 Nov 2026): the order's middle payment milestones in rupees,
 * for profit, CAFIN, the ledger and "fully paid". A milestone received with the INR credited on every
 * receipt is its INR actual (plus the bank charges deducted, counted once as the order's Bank Charges
 * cost); received with only a rate, received × rate (estimated); not received yet, its planned share of
 * the FOB value at fallbackRate (estimated). Port of PaymentMilestoneRepository::inInr.
 */
async function inInr(orderId, fobTotal, fallbackRate) {
  const { round2 } = require('../helpers/money');
  const remittanceInr = require('../services/remittanceInr');
  const remittanceDifference = require('../services/remittanceDifference');
  let total = 0;
  let estimated = false;
  const rows = [];
  for (const m of await forOrder(orderId)) {
    const planned = round2(Number(fobTotal) * parseFloat(m.pct) / 100);
    const received = m.received_amount !== null && m.received_amount !== undefined ? Number(m.received_amount) : null;
    const rate = m.exchange_rate !== null && m.exchange_rate !== undefined ? Number(m.exchange_rate) : null;
    let credited = null;
    let charges = 0;
    if (received !== null) {
      const receipts = await db.query("SELECT inr_credited FROM order_payment_receipts WHERE order_id = :o AND leg = 'milestone' AND milestone_id = :m", { o: orderId, m: m.id });
      credited = remittanceInr.legInrActual(receipts);
      charges = await remittanceDifference.chargesInr(orderId, 'milestone', Number(m.id));
    }
    let inr;
    let basis;
    if (credited !== null) {
      inr = round2(credited + charges);
      basis = 'actual';
    } else if (received !== null && rate !== null) {
      inr = round2(received * rate);
      basis = 'rate';
    } else if (received === null && fallbackRate !== null && fallbackRate !== undefined && planned > 0) {
      inr = round2(planned * Number(fallbackRate));
      basis = 'planned';
    } else {
      inr = 0;
      basis = null;
    }
    if (basis !== 'actual' && (planned > 0 || received !== null)) {
      estimated = true;
    }
    total += inr;
    rows.push({ ...m, planned_amount: planned, credited_inr: credited, charges_inr: charges, inr, inr_basis: basis });
  }
  return { inr: round2(total), estimated, rows };
}

/**
 * QA batch 4, ORD-09 (owner double-confirmed 5 Nov 2026): what the order's milestones take off the
 * balance — one formula for the buyer pages, the commercial invoice and the staff checks. A milestone
 * received (or settled short) counts its money received plus any shortfall written off; a shortfall
 * carried to the balance stays in the balance. A milestone not received yet counts its planned share
 * for the staff side (plannedForOpen) and nothing on the buyer side. Port of balanceDeduction().
 */
async function balanceDeduction(orderId, fobTotal, plannedForOpen) {
  const { round2 } = require('../helpers/money');
  let total = 0;
  for (const m of await forOrder(orderId)) {
    const row = await db.queryOne("SELECT COALESCE(SUM(shortfall_amount), 0) AS t FROM order_payment_shortfalls WHERE order_id = :o AND leg = 'milestone' AND milestone_id = :m AND treatment = 'written_off'", { o: orderId, m: m.id });
    const off = Number(row ? row.t : 0);
    if ((m.received_amount !== null && m.received_amount !== undefined) || off > 0) {
      total += Number(m.received_amount || 0) + off;
    } else if (plannedForOpen) {
      total += round2(Number(fobTotal) * parseFloat(m.pct) / 100);
    }
  }
  return round2(total);
}

async function anyMoneyRecorded(orderId) {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM order_payment_milestones WHERE order_id = :o AND received_amount IS NOT NULL', { o: orderId });
  return Number(row.n) > 0;
}

/** Steps not yet cleared. */
async function notCleared(orderId) {
  return (await forOrder(orderId)).filter((m) => m.cleared_on === null);
}

const r2 = (n) => Math.round(Number(n) * 100) / 100;

/** Replaces the order's middle steps and sets its balance % to what is left (advance + steps + balance = 100). Saved together or not at all. */
async function replaceSchedule(orderId, steps, advancePct) {
  await db.transaction(async (tx) => {
    // QA Round 7, R7-N11: two saves at once used to deadlock (DELETE + INSERT); one at a time per order.
    await tx.query('SELECT id FROM orders WHERE id = :o FOR UPDATE', { o: orderId });
    await tx.execute('DELETE FROM order_payment_milestones WHERE order_id = :o', { o: orderId });
    let total = 0;
    for (let i = 0; i < steps.length; i++) {
      await tx.execute('INSERT INTO order_payment_milestones (order_id, seq, pct, due_text) VALUES (:o, :seq, :pct, :due_text)',
        { o: orderId, seq: i + 2, pct: r2(steps[i].pct), due_text: steps[i].due_text });
      total += r2(steps[i].pct);
    }
    await tx.execute('UPDATE orders SET balance_pct = :b WHERE id = :o', { b: r2(100 - advancePct - total), o: orderId });
  });
}

async function recordReceived(id, amount, receivedOn, rate, bankReference, staffNote, userId) {
  await db.execute(
    `UPDATE order_payment_milestones
        SET received_amount = :amount, received_on = :received_on, exchange_rate = :rate, bank_reference = :ref,
            staff_note = :note, recorded_by = :user, recorded_at = NOW()
      WHERE id = :id AND received_amount IS NULL`,
    {
      amount: r2(amount), received_on: receivedOn, rate: Math.round(Number(rate) * 10000) / 10000, ref: String(bankReference).trim(),
      note: staffNote !== null && staffNote !== undefined && String(staffNote).trim() !== '' ? String(staffNote).trim() : null, user: userId, id,
    }
  );
}

async function markCleared(id, clearedOn, userId) {
  await db.execute('UPDATE order_payment_milestones SET cleared_on = :d, cleared_by = :u WHERE id = :id AND cleared_on IS NULL', { d: clearedOn, u: userId, id });
}

async function setZohoReference(id, reference) {
  await db.execute('UPDATE order_payment_milestones SET zoho_reference = :r WHERE id = :id', { r: reference, id });
}

async function setZohoApplied(id) {
  await db.execute('UPDATE order_payment_milestones SET zoho_applied_at = NOW() WHERE id = :id', { id });
}

/** Same rule as the advance and balance: in production, test and sample orders never go to Zoho; in test, only TEST- customers do. */
function zohoAllowed(rows) {
  return rows.filter((r) => (zohoGuard.isProduction()
    ? Number(r.is_test_or_sample) !== 1
    : String(r.company_legal_name || '').startsWith(zohoGuard.TEST_PREFIX)));
}

const SYNC_COLUMNS = 'm.*, o.buyer_inquiry_ref, o.currency_code, o.client_id, o.company_legal_name, (o.is_test_data = 1 OR o.is_sample_data = 1) AS is_test_or_sample';

/** Cleared steps not yet in Zoho Books, with the order and buyer. */
async function pendingZohoSync() {
  return zohoAllowed(await db.query(
    `SELECT ${SYNC_COLUMNS} FROM order_payment_milestones m JOIN orders o ON o.id = m.order_id
      WHERE m.cleared_on IS NOT NULL AND m.zoho_reference IS NULL ORDER BY m.cleared_on, m.id`
  ));
}

/** Steps in Zoho Books but not yet applied to the Commercial Invoice. */
async function heldInZoho() {
  return zohoAllowed(await db.query(
    `SELECT ${SYNC_COLUMNS} FROM order_payment_milestones m JOIN orders o ON o.id = m.order_id
      WHERE m.zoho_reference IS NOT NULL AND m.zoho_applied_at IS NULL ORDER BY m.id`
  ));
}

module.exports = {
  balanceDeduction,
  inInr,
  MAX_STEPS, forOrder, find, totalPct, receivedTotal, plannedTotal, anyMoneyRecorded, notCleared, replaceSchedule, recordReceived, markCleared,
  setZohoReference, setZohoApplied, pendingZohoSync, heldInZoho,
};
