'use strict';

const db = require('../config/db');

/**
 * Port of PartPaymentService.php. Gap list G-5 (owner, 1 Nov 2026): part
 * payments and short payments from the buyer. Every remittance of the
 * advance, the freight Debit Note, the balance and each payment milestone is
 * its own receipt. The leg's existing columns keep the total received, the
 * latest date, the amount-weighted rate and the receipts' bank references —
 * so documents, CAFIN, Zoho and reports read what they always read. A leg
 * cannot be cleared while money is still owed, unless a manager with
 * "Approve short payment" gives a written reason; the shortfall is then
 * recorded.
 */

const LEGS = ['advance', 'freight', 'balance'];
const TOLERANCE = 0.01;
const round2 = (n) => Math.round(Number(n) * 100) / 100;
const round4 = (n) => Math.round(Number(n) * 10000) / 10000;

function label(leg, milestone = null) {
  return { advance: 'Advance', freight: 'Freight', balance: 'Balance' }[leg] || `Milestone ${Number((milestone && milestone.seq) || 0)}`;
}

function money(currency, amount) {
  return `${currency} ${Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim();
}

/** Oldest first, with who recorded it. `c` is an optional transaction connection. */
async function receipts(orderId, leg, milestoneId = null, c = db) {
  return c.query(
    `SELECT r.*, DATE_FORMAT(r.received_on, '%Y-%m-%d') AS received_on, u.name AS recorded_by_name
       FROM order_payment_receipts r LEFT JOIN users u ON u.id = r.recorded_by
      WHERE r.order_id = :o AND r.leg = :l AND r.milestone_id <=> :m ORDER BY r.received_on, r.id`,
    { o: orderId, l: leg, m: milestoneId }
  );
}

/** The remittance held on the leg's own columns when it has no receipt rows (sample data, before receipts were kept). */
async function legacyReceipt(orderId, leg, milestoneId, c = db) {
  if (leg === 'milestone') {
    const m = milestoneId !== null ? await c.queryOne("SELECT received_amount, DATE_FORMAT(received_on, '%Y-%m-%d') AS received_on, exchange_rate, bank_reference FROM order_payment_milestones WHERE id = :id", { id: milestoneId }) : null;
    return m && m.received_amount !== null && m.received_on !== null
      ? { amount: m.received_amount, received_on: m.received_on, exchange_rate: m.exchange_rate, bank_reference: m.bank_reference } : null;
  }
  const ops = await c.queryOne(`SELECT ${leg}_amount AS amount, DATE_FORMAT(${leg}_remittance_received_at, '%Y-%m-%d') AS received_on, ${leg}_exchange_rate AS exchange_rate, ${leg}_bank_reference AS bank_reference FROM order_payment_status WHERE order_id = :o`, { o: orderId });
  return ops && ops.amount !== null && ops.received_on !== null ? ops : null;
}

async function receivedTotal(orderId, leg, milestoneId = null) {
  const row = await db.queryOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS t FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m', { o: orderId, l: leg, m: milestoneId });
  if (!row || Number(row.n) === 0) {
    // A remittance written to the leg itself counts as its one receipt.
    const legacy = await legacyReceipt(orderId, leg, milestoneId);
    return legacy ? round2(legacy.amount) : 0;
  }
  return round2(row.t);
}

/** What the buyer owes on the leg; null when not known yet. */
async function expected(orderId, leg, milestone = null) {
  const orderRepository = require('../repositories/orderRepository');
  const orderProductRepository = require('../repositories/orderProductRepository');
  const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
  const paymentMilestoneRepository = require('../repositories/paymentMilestoneRepository');
  const paymentActionService = require('./paymentActionService');
  const order = await orderRepository.find(orderId);
  if (!order) {
    return null;
  }
  const fob = parseFloat(await orderProductRepository.totalFobValue(orderId)) || 0;
  if (leg === 'advance') {
    return round2(fob * (parseFloat(order.advance_pct) || 0) / 100);
  }
  if (leg === 'freight') {
    return paymentActionService.amountDue(order, 'freight');
  }
  if (leg === 'balance') {
    return orderPaymentStatusRepository.balanceDue(fob, order.advance_pct, await orderPaymentStatusRepository.find(orderId), await paymentMilestoneRepository.plannedTotal(orderId, fob));
  }
  return milestone ? round2(fob * parseFloat(milestone.pct) / 100) : null;
}

async function summary(orderId, leg, milestone = null) {
  const milestoneId = milestone ? Number(milestone.id) : null;
  const exp = await expected(orderId, leg, milestone);
  const received = await receivedTotal(orderId, leg, milestoneId);
  const shortfall = await db.queryOne(
    `SELECT s.*, DATE_FORMAT(s.approved_at, '%Y-%m-%d') AS approved_on, u.name AS approved_by_name FROM order_payment_shortfalls s LEFT JOIN users u ON u.id = s.approved_by
      WHERE s.order_id = :o AND s.leg = :l AND s.milestone_id <=> :m ORDER BY s.id DESC LIMIT 1`,
    { o: orderId, l: leg, m: milestoneId }
  );
  return {
    expected: exp,
    received,
    owed: exp !== null && exp !== undefined ? round2(exp - received) : null,
    receipts: await (async () => {
      const rows = await receipts(orderId, leg, milestoneId);
      if (rows.length) {
        return rows;
      }
      const legacy = await legacyReceipt(orderId, leg, milestoneId);
      return legacy ? [legacy] : [];
    })(),
    shortfall: shortfall || null,
  };
}

/** The receipts' bank references, each once, joined ", " (cut to the column's 100 characters). */
function joinReferences(rows) {
  const refs = [];
  for (const r of rows) {
    const ref = String(r.bank_reference || '').trim();
    if (ref !== '' && !refs.includes(ref)) {
      refs.push(ref);
    }
  }
  const joined = refs.join(', ');
  return [...joined].length > 100 ? `${[...joined].slice(0, 99).join('')}…` : joined;
}

/**
 * Adds a receipt to the advance, freight or balance and puts the leg's totals
 * back on order_payment_status, in one transaction. Returns false (nothing
 * recorded) for a second click with the same amount, day and rate.
 */
async function addLegReceipt(orderId, leg, amount, receivedOn, rate, bankReference, userId) {
  if (!LEGS.includes(leg)) {
    throw new Error(`Unknown payment leg ${leg}`);
  }
  return db.transaction(async (c) => {
    await c.queryOne('SELECT order_id FROM order_payment_status WHERE order_id = :o FOR UPDATE', { o: orderId });
    const dup = await c.queryOne(
      `SELECT 1 AS x FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id IS NULL
          AND ABS(amount - :a) < 0.005 AND received_on = :d AND ABS(COALESCE(exchange_rate, 0) - :r) < 0.00005 LIMIT 1`,
      { o: orderId, l: leg, a: round2(amount), d: String(receivedOn).slice(0, 10), r: round4(rate) }
    );
    if (dup) {
      return false;
    }
    const existing = await c.queryOne('SELECT COUNT(*) AS n FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id IS NULL', { o: orderId, l: leg });
    const legacy = Number(existing.n) === 0 ? await legacyReceipt(orderId, leg, null, c) : null;
    if (legacy) {
      // The remittance already on the leg becomes its first receipt, so the new one adds to it.
      await c.execute('INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, staff_note) VALUES (:o, :l, :a, :d, :r, :b, :n)',
        { o: orderId, l: leg, a: legacy.amount, d: legacy.received_on, r: legacy.exchange_rate, b: legacy.bank_reference, n: 'Recorded before part payments were kept separately' });
    }
    await c.execute(
      'INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, recorded_by) VALUES (:o, :l, :a, :d, :r, :b, :u)',
      { o: orderId, l: leg, a: round2(amount), d: String(receivedOn).slice(0, 10), r: round4(rate), b: String(bankReference || '').trim(), u: userId }
    );
    const rows = await receipts(orderId, leg, null, c);
    const total = round2(rows.reduce((t, r) => t + Number(r.amount), 0));
    const latest = rows.map((r) => String(r.received_on)).sort().pop();
    const weighted = rows.reduce((t, r) => t + Number(r.amount) * Number(r.exchange_rate !== null ? r.exchange_rate : rate), 0);
    const avgRate = total > 0 ? round4(weighted / total) : round4(rate);
    await c.execute(`UPDATE order_payment_status SET ${leg}_amount = :amount, ${leg}_remittance_received_at = :received_at, ${leg}_bank_reference = :ref WHERE order_id = :o`,
      { amount: total, received_at: latest, ref: joinReferences(rows), o: orderId });
    await c.execute(`UPDATE order_payment_status SET ${leg}_exchange_rate = :rate, ${leg}_exchange_rate_set_at = NOW(), ${leg}_exchange_rate_set_by = :u WHERE order_id = :o`,
      { rate: avgRate, u: userId, o: orderId });
    return true;
  });
}

/** Adds a milestone receipt to the history (the milestone row itself holds its one remittance). */
async function addMilestoneReceipt(milestone, amount, receivedOn, rate, bankReference, note, userId) {
  await db.execute(
    `INSERT INTO order_payment_receipts (order_id, leg, milestone_id, amount, received_on, exchange_rate, bank_reference, staff_note, recorded_by)
     VALUES (:o, 'milestone', :m, :a, :d, :r, :b, :n, :u)`,
    { o: milestone.order_id, m: milestone.id, a: round2(amount), d: String(receivedOn).slice(0, 10), r: round4(rate), b: String(bankReference || '').trim(), n: note && String(note).trim() !== '' ? String(note).trim() : null, u: userId }
  );
}

/**
 * Before a leg is marked cleared. Returns { error } to refuse, or { short } —
 * the shortfall to record once cleared (null when nothing is owed).
 */
async function clearCheck(orderId, leg, milestone, canApprove, reason) {
  const s = await summary(orderId, leg, milestone);
  if (s.owed === null || s.owed <= TOLERANCE) {
    return { short: null };
  }
  const order = await require('../repositories/orderRepository').find(orderId);
  const currency = String((order && order.currency_code) || '');
  const lbl = label(leg, milestone);
  if (!canApprove) {
    return { error: `${lbl} received so far: ${money(currency, s.received)} of ${money(currency, s.expected)} due — ${money(currency, s.owed)} is still owed. Record the rest when it arrives, or ask a manager with "Approve short payment" to clear it with a written reason.` };
  }
  const why = String(reason || '').trim();
  if ([...why].length < 10 || [...why].length > 500) {
    return { error: `${money(currency, s.owed)} of the ${lbl.toLowerCase()} is still owed. To clear it short, give the reason (10 to 500 characters) — it is recorded and shown on the order.` };
  }
  return { short: { expected: Number(s.expected), received: s.received, owed: s.owed, reason: why, currency, label: lbl } };
}

/** Records the approved shortfall after the leg is cleared; returns the sentence for the flash message. */
async function recordShortfall(orderId, leg, milestone, short, user) {
  const treatment = leg === 'advance' ? 'carried_to_balance' : 'written_off';
  await db.execute(
    `INSERT INTO order_payment_shortfalls (order_id, leg, milestone_id, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by)
     VALUES (:o, :l, :m, :e, :r, :s, :t, :reason, :u)`,
    { o: orderId, l: leg, m: milestone ? milestone.id : null, e: short.expected, r: short.received, s: short.owed, t: treatment, reason: short.reason, u: user.id }
  );
  await require('../repositories/auditLogRepository').log(user.id, 'PAYMENT_SHORTFALL_APPROVED', 'orders', orderId, leg + (milestone ? `:${milestone.seq}` : ''), String(short.expected), String(short.received), short.reason);
  return `Short by ${money(short.currency, short.owed)}: approved by ${user.name} — ${treatment === 'carried_to_balance' ? 'carried into the balance.' : 'written off.'}`;
}

function clearedMessage(leg, milestone = null) {
  return `The ${label(leg, milestone).toLowerCase()} is already cleared — no more receipts can be added to it.`;
}

/** Everything the order page shows for the legs (the view cannot query): keyed 'advance' | 'freight' | 'balance' | 'milestone:<id>'. */
async function pageData(orderId, milestones) {
  const out = {};
  for (const leg of LEGS) {
    out[leg] = await summary(orderId, leg);
  }
  for (const m of milestones || []) {
    out[`milestone:${m.id}`] = await summary(orderId, 'milestone', m);
  }
  return out;
}

module.exports = {
  LEGS, TOLERANCE, label, money, receipts, receivedTotal, expected, summary, joinReferences, addLegReceipt, addMilestoneReceipt,
  clearCheck, recordShortfall, clearedMessage, pageData,
};
