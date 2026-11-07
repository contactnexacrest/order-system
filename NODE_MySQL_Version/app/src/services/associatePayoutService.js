'use strict';

/**
 * Owner decision D-264 (7 Nov 2026): an associate's commission is fixed by itself
 * the moment the buyer has paid the order in full (the last buyer receipt cleared
 * with the rupees credited recorded) — in the order's currency and in rupees at the
 * rate of ALL the buyer's payments — and never changes afterwards. The payout is
 * created at once, the associate is told, and the order's profit uses the fixed
 * amount. A daily job holds it while a buyer claim is open, works it out again (at
 * the same fixed rate) after an agreed discount or settled claim, and carries an
 * abroad associate's small amounts to the next run. The team incentive keeps the
 * MD's lock (D-245). Paying it records what the payment really cost; the
 * difference is a company cost ("Associate payout costs"), never the order's.
 *
 * PHP twin: app/src/Services/AssociatePayoutService.php.
 */

const db = require('../config/db');
const { short } = require('../helpers/dates');
const incentiveRepository = require('../repositories/incentiveRepository');

const CLAIM_HOLD_REASON = 'A buyer claim on this order is open — the commission waits until it is resolved.';
const UNPAID_HOLD_REASON = 'The buyer did not pay this order in full — no commission is due on it.';

const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const round2 = (v) => require('../helpers/money').round2(v);
const ymd = (v) => (v instanceof Date ? v.toISOString().slice(0, 10) : String(v || '').slice(0, 10));
const today = () => new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
const nowSql = () => new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
const round6 = (v) => Math.round(Number(v) * 1e6) / 1e6;

function associateCommission() { return require('./associateCommission'); }
function associateRepository() { return require('../repositories/associateRepository'); }
function orderRepository() { return require('../repositories/orderRepository'); }
function associateService() { return require('./associateService'); }

/** Someone to record the fixed commission's cost line as: whoever cleared the last payment, else the first MD / admin. */
async function actingUserId(orderId, userId = null) {
  if (userId && Number(userId) > 0) return Number(userId);
  const r = await db.queryOne('SELECT COALESCE(balance_cleared_by, freight_cleared_by, advance_cleared_by) AS u FROM order_payment_status WHERE order_id = :o', { o: orderId });
  if (r && Number(r.u) > 0) return Number(r.u);
  const u = await db.queryOne('SELECT id FROM users ORDER BY is_super_admin DESC, id LIMIT 1');
  return u ? Number(u.id) : 0;
}

/** Whether the associate is paid in rupees (based in India). */
function isIndia(associate) {
  return ((associate && associate.based_in) || 'india') === 'india';
}

/** "₹83.45" — the fixed rate, to the paisa. */
function rateText(rate) {
  return `₹${money(rate)}`;
}

/** India only, once fixed: "You will be paid ₹104,312.50 (fixed at ₹83.45 per USD on 12 Nov 2026; will not change)". */
function fixedRupeeLine(inr, rate, currency, fixedOn) {
  return `You will be paid ₹${money(inr)} (fixed at ${rateText(rate)} per ${currency} on ${short(ymd(fixedOn))}; will not change)`;
}

function currencyName(cur) {
  return { USD: 'US dollars', EUR: 'euros', GBP: 'pounds sterling', INR: 'rupees' }[cur] || cur;
}

async function chargesText() {
  return (await associateCommission().settings()).abroad_charges === 'SHA'
    ? "NexaCrest pays its own bank's charges; your own bank may charge you on receipt."
    : "NexaCrest pays its own and the intermediary bank's charges.";
}

/** The "How your commission is fixed" box, worded for India or abroad. */
async function howFixedBox(associate) {
  const title = 'How your commission is fixed — it never changes afterwards';
  if (isIndia(associate)) {
    return { title, text: "Your commission is a percentage of the goods value (FOB) in the order's currency. On the day the buyer completes payment, it is fixed in rupees at the exchange rate the buyer's payment was actually converted at. That rupee amount is what you are paid. Later changes in the exchange rate do not raise or lower it. NexaCrest pays all bank charges." };
  }
  const cur = String((associate && associate.currency_code) || 'USD');
  return { title, text: `Your commission is a percentage of the goods value (FOB). It is fixed in ${currencyName(cur)} on the day the buyer completes payment, and you are paid exactly that amount in ${currencyName(cur)} to your verified account. ${await chargesText()} Any conversion into your local currency is done by your own bank.` };
}

/** The message the associate gets when the commission is fixed (portal notice and e-mail, mockup 7). */
async function fixedMessage(associate, order, a, dueOn, runOn) {
  const cur = String(a.currency);
  const level = associateCommission().rateLabel(a.level || null);
  const head = `Your client ${order.company_legal_name || 'Your client'} has paid order ${order.order_reference} in full. `;
  const when = `It is due on ${short(ymd(dueOn))} and will be paid in the ${short(ymd(runOn))} payout run to your verified bank account`;
  if (isIndia(associate)) {
    return `${head}Your commission is now fixed at ₹${money(a.amount_inr)} (${cur} ${money(a.amount_fc)}, ${level} of the invoiced goods value, at ${rateText(a.rate_inr)} per ${cur} — the rate at which the buyer's payments were converted by the bank). `
      + `This amount will not change, whatever the exchange rate does from now on. ${when}, after TDS as the law requires. `
      + 'Please upload your invoice for this commission under My commission. NexaCrest pays all bank charges.';
  }
  return `${head}Your commission is now fixed at ${cur} ${money(a.amount_fc)} (${level} of the invoiced goods value). `
    + `This amount will not change. ${when}, paid in ${currencyName(cur)}. `
    + `Please upload your invoice for this commission under My commission. ${await chargesText()}`;
}

/** Called after a buyer payment is cleared or its rupees recorded: fixes the commission if the order is now fully paid. Never throws. */
async function afterPaymentChange(orderId, userId = null) {
  try {
    await autoFix(orderId, userId);
  } catch (e) {
    console.error(`[D-264] associate commission not fixed for order ${orderId}: ${e.message}`);
  }
}

/** Owner rule D-212: the commission cost line says what it is, and the rate it was fixed at. */
function costDescription(a, fixedOn) {
  return `${require('./incentiveService').associateCostDescription(a)}; fixed on ${short(ymd(fixedOn))} at ${rateText(a.rate_inr)} per ${a.currency}`;
}

/**
 * Fixes the associate's commission when the buyer has paid in full and the rupees of every
 * receipt are recorded. Resolves true when it fixed it now; false when there is nothing to fix.
 */
async function autoFix(orderId, userId = null) {
  orderId = Number(orderId);
  const order = await orderRepository().find(orderId);
  if (!order || ['cancelled', 'lost'].includes(order.status)) return false;
  const row = await incentiveRepository.forOrder(orderId);
  if (row && (row.associate_fixed_at || row.locked_at)) return false;
  const incentiveService = require('./incentiveService');
  const paidOn = await incentiveService.fullyPaidOn(orderId);
  if (paidOn === null) return false;
  const ac = associateCommission();
  const a = await ac.forOrder(orderId, order, row || { associate_mode: 'auto' });
  if (!a || a.state !== 'paid' || Number(a.amount_fc) <= 0 || a.rate_estimated) return false;
  const uid = await actingUserId(orderId, userId);
  const t = today();
  const set = await ac.settings();
  const dueOn = await ac.dueOn(paidOn, set);
  const runOn = await ac.payoutRunOn(dueOn, set);
  const rate = round6(a.rate_inr);
  const done = await db.transaction(async (c) => {
    await c.execute('INSERT IGNORE INTO order_incentives (order_id, updated_by) VALUES (:id, :u)', { id: orderId, u: uid });
    // The order's row is claimed first, so two requests at the same moment fix it once (as the D-245 lock does).
    const claim = await c.queryOne('SELECT associate_fixed_at, locked_at FROM order_incentives WHERE order_id = :id FOR UPDATE', { id: orderId });
    const existing = await c.queryOne("SELECT COUNT(*) AS n FROM incentive_payouts WHERE order_id = :id AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL", { id: orderId });
    if ((claim && (claim.associate_fixed_at || claim.locked_at)) || Number(existing.n) > 0) return false;
    const payoutId = await incentiveRepository.addPayout({
      order_id: orderId, payee_kind: 'associate', associate_id: a.id, role: 'associate',
      amount_inr: a.amount_inr, amount_fc: a.amount_fc, currency_code: a.currency, incentive_month: String(runOn).slice(0, 7),
      payout_run_on: runOn, locked_on: t,
    }, c);
    await c.execute('UPDATE incentive_payouts SET due_on = :d, fixed_rate = :r, fixed_on = :f WHERE id = :id', { d: dueOn, r: rate, f: t, id: payoutId });
    if (a.status === 'suspended') await incentiveRepository.holdPayout(payoutId, 'The associate is suspended.', c);
    const entryId = await incentiveRepository.addCostEntry(orderId, 'associate', costDescription(a, t), a.amount_inr, paidOn, uid, c);
    await incentiveRepository.saveOrder(orderId, {
      fully_paid_on: paidOn,
      associate_id: a.id,
      associate_order_number: a.counts ? a.counting_number : null,
      associate_rate_pct: a.rate,
      associate_amount_fc: a.amount_fc,
      associate_currency: a.currency,
      associate_amount_inr: a.amount_inr,
      associate_basis_fc: a.basis_fc,
      associate_basis_inr: a.basis_inr,
      associate_counts: a.counts ? 1 : 0,
      associate_due_on: dueOn,
      associate_cost_entry_id: entryId,
      associate_fixed_at: nowSql(),
      associate_fixed_rate: rate,
    }, uid, c);
    await incentiveRepository.addHistory(orderId, 'associate_fixed', null, `Associate commission fixed: ${a.currency} ${money(a.amount_fc)} = ₹${money(a.amount_inr)}`
      + ` at ${rateText(rate)} per ${a.currency}; due ${short(ymd(dueOn))}, payout run ${short(ymd(runOn))}`, 'Fixed automatically when the buyer paid in full (D-264).', uid, c);
    return true;
  });
  if (!done) return false;
  const associate = await associateRepository().find(Number(a.id));
  if (associate) {
    await associateService().notify(associate, a.status === 'suspended' ? 'payout_held' : 'commission_earned',
      (await fixedMessage(associate, order, a, dueOn, runOn)) + (a.status === 'suspended' ? ' It is on hold while your account is suspended.' : ''),
      Number(order.client_id), orderId);
  }
  return true;
}

/** Orders of an associate's client, not cancelled or lost, whose commission is neither fixed nor locked. */
async function candidateOrderIds() {
  const rows = await db.query(
    `SELECT o.id FROM orders o JOIN clients c ON c.id = o.client_id
       LEFT JOIN order_incentives oi ON oi.order_id = o.id
      WHERE c.associate_id IS NOT NULL AND oi.locked_at IS NULL AND oi.associate_fixed_at IS NULL
        AND o.status NOT IN ('cancelled','lost') AND o.is_sample_data = 0 ORDER BY o.id`
  );
  return rows.map((r) => Number(r.id));
}

/** Daily: fix what was missed, hold / release for buyer claims, work out again after a settlement, carry small abroad amounts. */
async function daily(t = null) {
  t = t || today();
  const out = { fixed: 0, held: 0, released: 0, recomputed: 0, carried: 0 };
  for (const orderId of await candidateOrderIds()) {
    try {
      if (await autoFix(orderId)) out.fixed++;
    } catch (e) {
      console.error(`[D-264] daily fix failed for order ${orderId}: ${e.message}`);
    }
  }
  const ac = associateCommission();
  const set = await ac.settings();
  const rows = await db.query("SELECT p.* FROM incentive_payouts p WHERE p.payee_kind = 'associate' AND p.status IN ('due','held') AND p.fixed_rate IS NOT NULL AND p.clawback_of_payout_id IS NULL ORDER BY p.id");
  for (let p of rows) {
    if (await recompute(p)) {
      out.recomputed++;
      p = (await incentiveRepository.findPayout(Number(p.id))) || p;
    }
    const claim = set.claim_hold && !!p.due_on && await ac.claimOpen(Number(p.order_id), ymd(p.due_on));
    const associate = await associateRepository().find(Number(p.associate_id));
    const order = await orderRepository().find(Number(p.order_id));
    if (p.status === 'due' && claim && Number(p.amount_inr) > 0) {
      if (await incentiveRepository.holdPayout(Number(p.id), CLAIM_HOLD_REASON)) {
        out.held++;
        if (associate && order) {
          await associateService().notify(associate, 'payout_held', `Your commission on order ${order.order_reference} is on hold: ${CLAIM_HOLD_REASON}`, Number(order.client_id), Number(order.id));
        }
      }
    } else if (p.status === 'held' && String(p.hold_reason || '') === CLAIM_HOLD_REASON && !claim) {
      if (await incentiveRepository.releasePayout(Number(p.id))) {
        out.released++;
        if (associate && order) {
          await associateService().notify(associate, 'commission_earned', `The buyer claim on order ${order.order_reference} is resolved — your commission is no longer on hold and is paid in the ${short(ymd(p.payout_run_on))} payout run.`, Number(order.client_id), Number(order.id));
        }
      }
    }
  }
  out.carried = await carrySmallAbroad(t);
  return out;
}

function recomputedMessage(associate, order, cur, fc, inr, rate, unpaid) {
  if (unpaid) return `Order ${order.order_reference}: the buyer did not pay the order in full, so no commission is due on it.`;
  return `Order ${order.order_reference}: after an agreed discount or a settled buyer claim, your commission is now ${cur} ${money(fc)}`
    + (isIndia(associate) ? ` = ₹${money(inr)}, at the same fixed rate of ${rateText(rate)} per ${cur}` : '') + '.';
}

/** Writes a new commission amount on the unpaid payout, the order's fixed figures and its cost line. */
async function applyAmount(orderId, p, fc, inr, extra = {}) {
  await db.execute("UPDATE incentive_payouts SET amount_fc = :fc, amount_inr = :inr WHERE id = :id AND status <> 'paid'", { fc, inr, id: p.id });
  const row = await incentiveRepository.forOrder(orderId);
  await incentiveRepository.saveOrder(orderId, { associate_amount_fc: fc, associate_amount_inr: inr, ...extra }, await actingUserId(orderId));
  if (row && row.associate_cost_entry_id) {
    await db.execute('UPDATE order_cost_entries SET amount_inr = :a WHERE id = :id', { a: inr, id: row.associate_cost_entry_id });
  }
}

/**
 * After an agreed discount or a settled claim lowers the goods value, the commission is worked out
 * again at the SAME fixed rate (never a new rate). An MD correction is kept as it is.
 */
async function recompute(p) {
  const orderId = Number(p.order_id);
  const row = await incentiveRepository.forOrder(orderId);
  if (!row || !row.associate_fixed_at || (row.associate_override_fc !== null && row.associate_override_fc !== undefined) || p.status === 'paid') return false;
  const order = await orderRepository().find(orderId);
  const a = order ? await associateCommission().forOrder(orderId, order, row) : null;
  if (!a || Number(a.id) !== Number(p.associate_id)) return false;
  const fc = round2(a.amount_fc);
  const inr = round2(a.amount_inr);
  if (Math.abs(fc - Number(p.amount_fc)) < 0.005 && Math.abs(inr - Number(p.amount_inr)) < 0.005) return false;
  const rate = Number(row.associate_fixed_rate);
  await applyAmount(orderId, p, fc, inr, { associate_basis_fc: a.basis_fc, associate_basis_inr: round2(Number(a.basis_fc) * rate) });
  await incentiveRepository.addHistory(orderId, 'associate_recomputed', `${p.currency_code} ${money(p.amount_fc)} = ₹${money(p.amount_inr)}`,
    `${p.currency_code} ${money(fc)} = ₹${money(inr)} at the same fixed rate ${rateText(rate)}`,
    a.state === 'unpaid' ? 'The buyer did not pay in full.' : 'Agreed discount / settled buyer claim.', await actingUserId(orderId));
  if (a.state === 'unpaid' && p.status === 'due') await incentiveRepository.holdPayout(Number(p.id), UNPAID_HOLD_REASON);
  const associate = await associateRepository().find(Number(p.associate_id));
  if (associate && order) {
    await associateService().notify(associate, 'commission_earned', recomputedMessage(associate, order, String(p.currency_code), fc, inr, rate, a.state === 'unpaid'), Number(order.client_id), orderId);
  }
  return true;
}

/** The MD corrects a fixed commission before it is paid, with a reason (audited; the associate is told). Resolves an error or null. */
async function changeCommission(orderId, amountFc, reason, user) {
  orderId = Number(orderId);
  const why = String(reason || '').trim();
  if ([...why].length < 10 || [...why].length > 500) return 'Say why the commission is changed (10 to 500 characters).';
  const raw = String(amountFc ?? '').trim();
  if (raw === '' || !Number.isFinite(Number(raw)) || Number(raw) < 0 || Number(raw) > 100000000) return 'Enter the new commission in the order currency (0 or more).';
  const row = await incentiveRepository.forOrder(orderId);
  if (!row || !row.associate_fixed_at) return "This order's associate commission is not fixed yet — it is fixed when the buyer has paid in full.";
  const p = (await incentiveRepository.payoutsForOrder(orderId)).find((x) => x.payee_kind === 'associate' && (x.clawback_of_payout_id === null || x.clawback_of_payout_id === undefined));
  if (!p || p.status === 'paid') return 'This commission is already paid, so it can no longer be changed.';
  const rate = Number(row.associate_fixed_rate);
  const fc = round2(Number(raw));
  const inr = round2(fc * rate);
  const before = `${p.currency_code} ${money(p.amount_fc)} = ₹${money(p.amount_inr)}`;
  await applyAmount(orderId, p, fc, inr, { associate_override_fc: fc, associate_override_reason: why });
  await incentiveRepository.addHistory(orderId, 'associate_changed', before, `${p.currency_code} ${money(fc)} = ₹${money(inr)} at the same fixed rate ${rateText(rate)}`, why, Number(user.id));
  await require('../repositories/auditLogRepository').log(Number(user.id), 'ASSOCIATE_COMMISSION_CHANGED', 'incentive_payouts', Number(p.id), 'amount_fc',
    Number(p.amount_fc).toFixed(2), fc.toFixed(2), why);
  const associate = await associateRepository().find(Number(p.associate_id));
  const order = await orderRepository().find(orderId);
  if (associate && order) {
    await associateService().notify(associate, 'commission_earned', `NexaCrest has corrected your commission on order ${order.order_reference} to ${p.currency_code} ${money(fc)}`
      + (isIndia(associate) ? ` = ₹${money(inr)} (at the same fixed rate of ${rateText(rate)} per ${p.currency_code})` : '') + `. Reason: ${why}`, Number(order.client_id), orderId);
  }
  return null;
}

async function usdEquivalent(p) {
  if (String(p.currency_code || '').toUpperCase() === 'USD') return Number(p.amount_fc);
  const usd = Number((await require('../repositories/pricingRepository').settings()).pricing_fx_rate || 0);
  return usd > 0 ? Number(p.amount_inr) / usd : Number(p.amount_fc);
}

function carriedNote(min) {
  return `Carried to the next payout run: the amount due is below the USD ${Number(min).toLocaleString('en-US', { maximumFractionDigits: 0 })} minimum for a transfer abroad.`;
}

/**
 * On or after a payout run: an associate abroad whose amount due in that run is below the minimum
 * (default USD 100) is paid in the next run instead. Resolves the payouts carried.
 */
async function carrySmallAbroad(t = null) {
  t = t || today();
  const ac = associateCommission();
  const min = (await ac.settings()).abroad_min_usd;
  if (min <= 0) return 0;
  const rows = await db.query(
    `SELECT p.* FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id
      WHERE p.payee_kind = 'associate' AND p.status = 'due' AND a.based_in = 'abroad' AND p.amount_inr > 0 AND p.payout_run_on IS NOT NULL AND p.payout_run_on <= :t
      ORDER BY p.associate_id, p.id`, { t }
  );
  const byAssociate = new Map();
  for (const p of rows) {
    const k = Number(p.associate_id);
    if (!byAssociate.has(k)) byAssociate.set(k, []);
    byAssociate.get(k).push(p);
  }
  let carried = 0;
  const next = await ac.payoutRunOn(ac.addDays(t, 1));
  for (const list of byAssociate.values()) {
    let total = 0;
    for (const p of list) total += await usdEquivalent(p);
    if (total + 0.005 >= min) continue;
    for (const p of list) {
      await db.execute("UPDATE incentive_payouts SET payout_run_on = :r, incentive_month = :m, note = :n WHERE id = :id AND status = 'due'",
        { r: next, m: String(next).slice(0, 7), n: carriedNote(min), id: p.id });
      carried++;
    }
  }
  return carried;
}

/**
 * What paying an associate really cost against the fixed rupees: abroad, the rupees the bank
 * debited (+ TDS kept) + our and the intermediary bank's charges − the fixed commission;
 * in India, the bank charges. A company cost of the month — never on the order.
 */
function payoutDifference(payout, bankDebitedInr, bankChargesInr, india) {
  const charges = Number(bankChargesInr || 0);
  if (india || bankDebitedInr === null || bankDebitedInr === undefined) return round2(charges);
  return round2(Number(bankDebitedInr) + Number(payout.tds_inr || 0) + charges - Number(payout.amount_inr));
}

async function recordPaymentCosts(payoutId, bankDebitedInr, bankChargesInr) {
  const p = await incentiveRepository.findPayout(Number(payoutId));
  if (!p || p.payee_kind !== 'associate') return;
  const india = isIndia(await associateRepository().find(Number(p.associate_id)));
  const debited = india ? null : bankDebitedInr;
  const diff = payoutDifference(p, debited, bankChargesInr, india);
  await db.execute('UPDATE incentive_payouts SET bank_debited_inr = :d, bank_charges_inr = :c, payout_difference_inr = :x WHERE id = :id',
    { d: debited, c: bankChargesInr === undefined ? null : bankChargesInr, x: diff, id: payoutId });
}

/**
 * Owner rule D-212: the payout-cost line, e.g. "Associate commission payout — NC-EXP-0142 — Demo Associate —
 * USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer charges ₹1,850.00".
 */
async function payoutCostDescription(p, order = null, associate = null) {
  order = order || await orderRepository().find(Number(p.order_id));
  associate = associate || await associateRepository().find(Number(p.associate_id));
  const s = `Associate commission payout — ${(order && order.order_reference) || p.order_id} — ${(associate && associate.name) || 'associate'} — `;
  const charges = Number(p.bank_charges_inr || 0);
  if (isIndia(associate)) return `${s}₹${money(p.paid_inr)} paid, UTR ${p.paid_reference || '—'}; transfer charges ₹${money(charges)}`;
  const exchange = round2(Number(p.payout_difference_inr || 0) - charges);
  return `${s}${p.currency_code} ${money(p.amount_fc)} sent, SWIFT ref ${p.paid_reference || '—'}; exchange difference ₹${money(exchange)} and transfer charges ₹${money(charges)}`;
}

/** Paid associate payouts of a payout month with a payout cost, each with its description. */
async function payoutCostsForMonth(month) {
  const rows = await db.query("SELECT p.* FROM incentive_payouts p WHERE p.payee_kind = 'associate' AND p.status = 'paid' AND p.payout_difference_inr IS NOT NULL AND p.incentive_month = :m ORDER BY p.id", { m: month });
  const out = [];
  for (const p of rows) out.push({ ...p, description: await payoutCostDescription(p) });
  return out;
}

module.exports = {
  CLAIM_HOLD_REASON, UNPAID_HOLD_REASON, isIndia, rateText, fixedRupeeLine, howFixedBox, fixedMessage, afterPaymentChange, autoFix, costDescription,
  candidateOrderIds, daily, recompute, recomputedMessage, changeCommission, carrySmallAbroad, carriedNote, payoutDifference, recordPaymentCosts,
  payoutCostDescription, payoutCostsForMonth,
};
