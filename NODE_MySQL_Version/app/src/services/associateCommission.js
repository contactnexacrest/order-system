'use strict';

/**
 * Owner decision D-245 (4 Nov 2026), phase P3: an associate's commission.
 *
 * - Basis: the final invoiced FOB of the goods, line by line (goods lines only —
 *   no freight, insurance, services or charges). A line the MD approved below
 *   the floor earns nothing and does not count toward the ladder.
 * - Rate: the commission level fixed on the order when its Proforma Invoice was
 *   issued (fixed level, or the ladder step for the associate's counting orders).
 * - Converted to rupees at the rate the buyer's money actually realised (INR
 *   credited ÷ foreign amount, D-244); rupees the bank kept are added back, so
 *   bank charges never lower it.
 * - Only an agreed discount or a settled claim lowers it (an approved shortfall
 *   marked so); an unpaid shortfall means the order was not paid in full and
 *   earns nothing (no pro rata).
 * - Due N days after full payment, held while a buyer claim opened on or before
 *   the due date is open, paid in the payout run (monthly day N or weekly).
 * - GST on top for a GST-registered associate once their tax invoice is on file.
 *
 * PHP twin: src/Services/AssociateCommission.php.
 */
const db = require('../config/db');
const { short } = require('../helpers/dates');
const incentiveRepository = require('../repositories/incentiveRepository');
const pricingRepository = require('../repositories/pricingRepository');

const SETTLEMENT_KINDS = {
  agreed_discount: 'Agreed discount — lowers the associate commission basis',
  claim_settled: 'Settled buyer claim — lowers the associate commission basis',
  unpaid: 'Not paid by the buyer — no associate commission on this order',
};
const WEEKDAYS = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

/** PHP round(x, 2). */
function round2(x) {
  return Math.round((Number(x) + Number.EPSILON) * 100) / 100;
}
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ymd = (v) => (v instanceof Date ? v.toISOString().slice(0, 10) : String(v || '').slice(0, 10));

function addDays(date, days) {
  const d = new Date(`${ymd(date)}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

async function settings() {
  const s = await incentiveRepository.settings();
  const run = (s.associate_payout_run || 'monthly') === 'weekly' ? 'weekly' : 'monthly';
  let day = parseInt(s.associate_payout_day ?? '5', 10);
  if (!(day > 0)) day = 5;
  const months = parseInt(s.associate_protection_months ?? '6', 10);
  return {
    due_days: Math.max(0, parseInt(s.associate_commission_due_days ?? '30', 10) || 0),
    claim_hold: (s.associate_claim_hold ?? '1') === '1',
    run,
    day: run === 'weekly' ? Math.max(1, Math.min(7, day)) : Math.max(1, Math.min(28, day)),
    threshold_inr: Number(s.associate_counting_threshold_inr ?? 500000),
    gst_pct: Number(s.associate_gst_pct ?? 18),
    zoho_gst_tax_id: String(s.zoho_gst_commission_tax_id ?? '').trim(),
    registration_months: months > 0 ? months : 6,
  };
}

async function dueOn(fullyPaidOn, set = null) {
  set = set || await settings();
  return addDays(fullyPaidOn, set.due_days);
}

/** The first payout run on or after the due date. */
async function payoutRunOn(due, set = null) {
  set = set || await settings();
  const d = new Date(`${ymd(due)}T00:00:00Z`);
  if (set.run === 'weekly') {
    const w = d.getUTCDay() === 0 ? 7 : d.getUTCDay();
    return addDays(due, (set.day - w + 7) % 7);
  }
  const pad = (n) => String(n).padStart(2, '0');
  let candidate = `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(set.day)}`;
  if (candidate < ymd(due)) {
    const next = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 1));
    candidate = `${next.getUTCFullYear()}-${pad(next.getUTCMonth() + 1)}-${pad(set.day)}`;
  }
  return candidate;
}

function runLabel(set) {
  return set.run === 'weekly' ? `weekly, every ${WEEKDAYS[set.day]}` : `monthly, on day ${set.day}`;
}

/** A buyer complaint or claim opened on or before the due date and not resolved. */
async function claimOpen(orderId, due) {
  const row = await db.queryOne("SELECT 1 AS x FROM order_complaints WHERE order_id = :o AND status <> 'resolved' AND DATE(created_at) <= :d LIMIT 1", { o: orderId, d: ymd(due) });
  return !!row;
}

/** Whether a payout may be paid on `on`: due date reached and no buyer claim holding it. Returns the reason it may not, or null. */
async function payBlockReason(payout, on) {
  if ((payout.payee_kind || '') !== 'associate' || !payout.due_on || Number(payout.amount_inr) < 0) return null;
  const set = await settings();
  if (ymd(on) < ymd(payout.due_on)) {
    return `This commission is due on ${short(ymd(payout.due_on))} (${set.due_days} days after the buyer paid in full) — it cannot be paid before that.`;
  }
  if (set.claim_hold && await claimOpen(Number(payout.order_id), payout.due_on)) {
    return 'A buyer claim on this order is open — the commission waits until it is resolved.';
  }
  // Owner decision D-252 (A3): no commission is paid until the associate's KYC is complete.
  const a = payout.associate_id ? await require('../repositories/associateRepository').find(Number(payout.associate_id)) : null;
  if (a) {
    const miss = await require('./associateKyc').missing(a);
    if (miss.length) return `The associate's KYC is not complete — still to be accepted: ${miss.join(', ')}.`;
  }
  return null;
}

/** The associate who earns on this order (client's associate, mode not "no", first-order window or forced "yes"), or null. */
async function earningAssociate(order, incentiveRow = null) {
  const client = order.client_id ? await require('../repositories/clientRepository').find(Number(order.client_id)) : null;
  if (!client || !client.associate_id) return null;
  const row = incentiveRow || (order.id ? await incentiveRepository.forOrder(Number(order.id)) : null);
  const mode = String((row && row.associate_mode) || 'auto');
  if (mode === 'no') return null;
  if (mode !== 'yes' && order.id && !(await require('./incentiveService').withinFirstTenDays(order))) return null;
  const a = await require('../repositories/associateRepository').find(Number(client.associate_id));
  if (!a || Number(a.no_commission || 0) === 1) return null;
  return a;
}

/** The associate's earlier counting orders (fixed and counted when their order was locked). */
async function countingOrders(associateId, excludeOrderId = null) {
  const row = await db.queryOne(
    `SELECT COUNT(*) AS n FROM order_incentives oi JOIN orders o ON o.id = oi.order_id
      WHERE oi.associate_id = :a AND oi.associate_counts = 1 AND oi.locked_at IS NOT NULL AND oi.order_id <> :x
        AND o.status NOT IN ('cancelled','lost')`,
    { a: associateId, x: excludeOrderId || 0 }
  );
  return Number(row.n);
}

/**
 * The associate's level: their fixed level, or the ladder step. For the ladder, an
 * order expected to count (or not yet known: orderInRangeInr null) takes the step
 * for its place (counting orders + 1); a smaller order earns the current step and
 * does not count. Returns [level, counting orders so far (ladder only)].
 */
async function levelForAssociate(associate, excludeOrderId, orderInRangeInr = null) {
  if ((associate.commission_mode || 'ladder') === 'fixed') {
    return [associate.commission_level_id ? await pricingRepository.findLevel(Number(associate.commission_level_id)) : null, null];
  }
  const count = await countingOrders(Number(associate.id), excludeOrderId);
  const set = await settings();
  const position = (orderInRangeInr === null || orderInRangeInr >= set.threshold_inr) ? count + 1 : Math.max(count, 1);
  for (const step of await pricingRepository.ladder()) {
    if (position >= Number(step.from_order) && (step.to_order === null || position <= Number(step.to_order))) {
      return [await pricingRepository.findLevel(Number(step.level_id)), count];
    }
  }
  return [null, count];
}

function levelLabel(level) {
  if (!level) return 'no level';
  const v = Number(level.value).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
  return `${level.name} — ${(level.kind || 'pct') === 'pct' ? `${v}%` : `USD ${money(level.value)} per unit`}`;
}

/** The level fixed on the order at PI, or null when not fixed yet. */
function fixedLevel(order) {
  if (!order.associate_level_fixed_at || order.associate_level_kind === null || order.associate_level_kind === undefined) return null;
  return { id: order.associate_level_id !== null ? Number(order.associate_level_id) : null, name: String(order.associate_level_name),
    kind: String(order.associate_level_kind), value: Number(order.associate_level_value), fixed: true };
}

/** Whether the MD approved this line below the floor (no commission, not in range). */
function belowFloorApproved(line) {
  return Number(line.price_approval_commission_zero || 0) === 1 && !!line.price_approved_by;
}

async function goodsLines(orderId) {
  return (await require('../repositories/orderProductRepository').forOrder(orderId)).filter((l) => (l.line_type || 'goods') === 'goods');
}

/** FOB of the goods lines not approved below the floor, order currency. */
async function inRangeValue(orderId) {
  let sum = 0;
  for (const l of await goodsLines(orderId)) {
    if (!belowFloorApproved(l)) sum += Number(l.fob_value);
  }
  return round2(sum);
}

/** Called when the PI is issued: fixes the associate's commission level on the order (once). */
async function fixLevelAtPi(orderId) {
  const orderRepository = require('../repositories/orderRepository');
  const order = await orderRepository.find(orderId);
  if (!order || order.associate_level_fixed_at) return;
  const a = await earningAssociate(order);
  if (!a) return;
  const fx = Number((await pricingRepository.settings()).pricing_fx_rate || 0);
  const inRange = await inRangeValue(orderId);
  const estimate = String(order.currency_code || '').toUpperCase() === 'INR' ? inRange : (fx > 0 ? inRange * fx : null);
  const [level] = await levelForAssociate(a, orderId, estimate);
  if (!level) return;
  await db.execute(
    `UPDATE orders SET associate_id = :a, associate_level_id = :l, associate_level_name = :n, associate_level_kind = :k, associate_level_value = :v,
            associate_level_fixed_at = NOW(), associate_rate_pct = :r, associate_rate_source = :s WHERE id = :id AND associate_level_fixed_at IS NULL`,
    { a: a.id, l: level.id, n: level.name, k: level.kind, v: level.value, r: level.kind === 'pct' ? level.value : null,
      s: (a.commission_mode || 'ladder') === 'fixed' ? 'fixed' : 'ladder', id: orderId }
  );
  if (Number(order.is_test_data) !== 1 && Number(order.is_sample_data) !== 1) {
    await require('./associateService').notify(a, 'order_created', `The Proforma Invoice for order ${order.order_reference} was issued. Commission level fixed on it: ${levelLabel(level)}.`,
      Number(order.client_id), orderId);
  }
}

/** Approved shortfalls on the goods payments: agreed reductions and whether any amount was left unpaid. */
async function settlements(orderId) {
  let reduction = 0;
  let unpaid = false;
  for (const r of await db.query("SELECT leg, shortfall_amount, settlement_kind FROM order_payment_shortfalls WHERE order_id = :o AND treatment = 'written_off'", { o: orderId })) {
    const kind = String(r.settlement_kind || '');
    if (kind === 'agreed_discount' || kind === 'claim_settled') {
      if (r.leg !== 'freight') reduction += Number(r.shortfall_amount);
    } else {
      unpaid = true;
    }
  }
  return { reduction: round2(reduction), unpaid };
}

/** Rupees per unit of the order currency the buyer's goods payments realised (rupees the bank kept added back). */
async function realisedRate(order) {
  if (String(order.currency_code || 'INR').toUpperCase() === 'INR') return { rate: 1, estimated: false };
  let inr = 0;
  let foreign = 0;
  let estimated = false;
  for (const r of await db.query("SELECT amount, exchange_rate, inr_credited, rate_gap_choice, rate_gap_inr FROM order_payment_receipts WHERE order_id = :o AND leg IN ('advance','balance','milestone')", { o: order.id })) {
    const amount = Number(r.amount);
    if (r.inr_credited !== null && r.inr_credited !== undefined) {
      inr += Number(r.inr_credited);
      if (r.rate_gap_choice === 'bank_charges' && Number(r.rate_gap_inr) > 0) inr += Number(r.rate_gap_inr);
    } else {
      inr += amount * Number(r.exchange_rate);
      estimated = true;
    }
    foreign += amount;
  }
  if ((order.payment_method || '') === 'LC') {
    const lc = await require('../repositories/orderLcDetailRepository').find(Number(order.id));
    if (lc && Number(lc.lc_received_amount || 0) > 0) {
      inr += Number(lc.lc_inr_credited);
      foreign += Number(lc.lc_received_amount);
    }
  }
  if (foreign > 0) return { rate: inr / foreign, estimated };
  // Nothing received yet: the rate expected on the receipts, else the Admin pricing exchange rate (estimated either way).
  let rate = await require('./incentiveService').productRate(order);
  if (!(rate > 0)) rate = Number((await pricingRepository.settings()).pricing_fx_rate || 0);
  return { rate, estimated: true };
}

/** The order's associate commission as it stands, or null when no associate earns on it. */
async function forOrder(orderId, order = null, incentiveRow = null) {
  order = order || await require('../repositories/orderRepository').find(orderId);
  if (!order) return null;
  const a = await earningAssociate(order, incentiveRow);
  if (!a) return null;
  const set = await settings();
  let level = fixedLevel(order);
  const fixed = level !== null;
  if (!level) [level] = await levelForAssociate(a, orderId);
  const currency = String(order.currency_code || 'INR');
  const lines = [];
  let inRange = 0;
  let linesCommission = 0;
  let total = 0;
  for (const l of await goodsLines(orderId)) {
    const value = Number(l.fob_value);
    total += value;
    const zero = belowFloorApproved(l);
    let commission = 0;
    if (!zero) {
      inRange += value;
      if (level) commission = level.kind === 'pct' ? value * Number(level.value) / 100 : Number(l.quantity) * Number(level.value);
    }
    linesCommission += commission;
    lines.push({ line_no: Number(l.line_no), description: String(l.description), fob: round2(value), in_range: !zero, commission: round2(commission) });
  }
  const settle = await settlements(orderId);
  const basis = Math.max(0, inRange - settle.reduction);
  const commissionFc = inRange > 0 ? round2(linesCommission * basis / inRange) : 0;
  const realised = await realisedRate(order);
  const fullyPaidOn = await require('./incentiveService').fullyPaidOn(orderId);
  const state = settle.unpaid ? 'unpaid' : (fullyPaidOn !== null ? 'paid' : 'awaiting');
  const due = fullyPaidOn !== null ? await dueOn(fullyPaidOn, set) : null;
  const basisInr = round2(basis * realised.rate);
  const counts = state === 'paid' && basisInr >= set.threshold_inr;
  return {
    id: Number(a.id), name: String(a.name), status: String(a.status), gstin: String(a.gstin || ''), mode: String(a.commission_mode || 'ladder'),
    level, level_label: levelLabel(level), level_fixed: fixed,
    rate: level && level.kind === 'pct' ? Number(level.value) : null,
    currency, lines,
    goods_fc: round2(total), in_range_fc: round2(inRange), reduction_fc: settle.reduction, basis_fc: round2(basis),
    rate_inr: realised.rate, rate_estimated: realised.estimated, basis_inr: basisInr,
    amount_fc: state === 'unpaid' ? 0 : commissionFc,
    amount_inr: state === 'unpaid' ? 0 : round2(commissionFc * realised.rate),
    state, fully_paid_on: fullyPaidOn,
    due_on: due, payout_run_on: due !== null ? await payoutRunOn(due, set) : null,
    claim_hold: due !== null && set.claim_hold && await claimOpen(orderId, due),
    counts, threshold_inr: set.threshold_inr,
    counting_number: counts ? (await countingOrders(Number(a.id), orderId)) + 1 : null,
    gst_pct: set.gst_pct,
  };
}

/** The rupees of GST on top of a payout: only for a GST-registered associate whose tax invoice for it is on file. */
async function gstFor(payout) {
  if ((payout.payee_kind || '') !== 'associate' || Number(payout.amount_inr) <= 0) return 0;
  const a = await require('../repositories/associateRepository').find(Number(payout.associate_id));
  if (!a || String(a.gstin || '').trim() === '' || !(await require('../repositories/associateDocumentRepository').invoiceForPayout(Number(payout.id)))) return 0;
  return round2(Number(payout.amount_inr) * (await settings()).gst_pct / 100);
}

/** Owner rule D-212: what a commission payout is — for which order and associate, what it settles, and the bank reference once paid. */
async function payoutDescription(payout, order = null, associate = null) {
  order = order || await require('../repositories/orderRepository').find(Number(payout.order_id));
  associate = associate || await require('../repositories/associateRepository').find(Number(payout.associate_id));
  let s = `Associate commission to ${associate ? associate.name : 'associate'} on order ${order ? order.order_reference : payout.order_id}`
    + ` (payout no. ${payout.id}, settles their commission invoice for this order): ₹${money(payout.amount_inr)}`;
  if (Number(payout.gst_inr || 0) > 0) s += ` + GST ₹${money(payout.gst_inr)}`;
  if (Number(payout.tds_inr || 0) > 0) s += `, TDS ₹${money(payout.tds_inr)}`;
  if (payout.paid_reference) s += ` · bank ref ${payout.paid_reference}`;
  return s;
}

/** Daily: a buyer registration whose period has ended with no order from the buyer lapses (unlinked, associate told). */
async function lapseRegistrations(today = null) {
  today = today || new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
  const rows = await db.query(
    `SELECT c.id, c.company_legal_name, c.associate_id, c.associate_locked_until FROM clients c
      WHERE c.associate_id IS NOT NULL AND c.associate_locked_until IS NOT NULL AND c.associate_locked_until < :t
        AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.client_id = c.id AND o.status NOT IN ('cancelled','lost'))`,
    { t: today }
  );
  let n = 0;
  for (const c of rows) {
    const r = await db.execute('UPDATE clients SET associate_id = NULL, associate_registered_on = NULL, associate_locked_until = NULL, associate_source = NULL, associate_invitation_id = NULL WHERE id = :id AND associate_id = :a',
      { id: c.id, a: c.associate_id });
    if (!r || !r.affectedRows) continue;
    const until = ymd(c.associate_locked_until);
    await require('../repositories/auditLogRepository').log(null, 'ASSOCIATE_REGISTRATION_LAPSED', 'clients', Number(c.id), 'associate_id', String(c.associate_id), null,
      `Registration ended ${until} with no order from the buyer.`);
    const a = await require('../repositories/associateRepository').find(Number(c.associate_id));
    if (a) {
      await require('./associateService').notify(a, 'registration_lapsed', `Your registration of ${c.company_legal_name} ended on ${short(until)} with no order from the buyer, so it has lapsed and the buyer is no longer linked to you.`,
        Number(c.id), null);
    }
    n++;
  }
  return n;
}

module.exports = {
  SETTLEMENT_KINDS, WEEKDAYS, round2, addDays, settings, dueOn, payoutRunOn, runLabel, claimOpen, payBlockReason,
  earningAssociate, countingOrders, levelForAssociate, levelLabel, fixedLevel, fixLevelAtPi, belowFloorApproved, goodsLines, inRangeValue,
  settlements, realisedRate, forOrder, gstFor, payoutDescription, lapseRegistrations,
};
