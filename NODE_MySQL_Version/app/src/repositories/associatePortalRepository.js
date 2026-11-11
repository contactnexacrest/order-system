'use strict';

const db = require('../config/db');
const dates = require('../helpers/dates');
const associateRepository = require('./associateRepository');
const orderProductRepository = require('./orderProductRepository');

/**
 * Port of AssociatePortalRepository.php. Owner decision 1 Nov 2026 (AS-2 …
 * AS-4): what the associate portal shows — only the associate's own clients
 * and their orders: order reference, a short product description, the
 * product FOB value, the stage in plain words, whether the buyer has paid in
 * full (no amounts), and the commission. Never unit prices, costs, profit,
 * suppliers, documents or buyer payment amounts.
 */

const incentive = () => require('../services/incentiveService');

/** Plain-word stage, as a buyer would see it. */
function stageLabel(order) {
  const status = String(order.status);
  if (status === 'cancelled') return 'Cancelled';
  if (status === 'lost') return 'Did not go ahead';
  if (['shipped_docs_sent', 'complete'].includes(status)) return 'Shipped';
  // stages_master: 1 quotation · 2 buyer PO · 3 PI · 4 OC/production · 5 supplier PO · 6 freight · 7 BL · 8 commercial invoice · 9 closure
  const n = Number(order.current_stage_number ?? 1) || 1;
  if (n <= 1) return 'Quotation';
  if (n <= 3) return 'Order confirmed';
  if (n <= 5) return 'In production';
  if (n <= 8) return 'Shipping';
  return 'Shipped';
}

function addDays(ymd, days) {
  const d = new Date(`${String(ymd).slice(0, 10)}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** The associate's clients with first order and the 10-day window. */
async function clients(associateId) {
  const out = [];
  for (const c of await associateRepository.clients(associateId)) {
    const first = await db.queryOne("SELECT MIN(DATE(created_at)) AS d FROM orders WHERE client_id = :c AND status NOT IN ('cancelled','lost')", { c: c.id });
    c.first_order_on = first && first.d ? String(first.d).slice(0, 10) : null;
    const po = await db.queryOne('SELECT MIN(buyer_po_received_on) AS d FROM orders WHERE client_id = :c AND buyer_po_received_on IS NOT NULL', { c: c.id });
    c.window_ends_on = po && po.d ? addDays(po.d, 9) : null;
    c.came_via = await cameVia(c);
    c.order_refs = (await db.query("SELECT order_reference FROM orders WHERE client_id = :c AND status NOT IN ('cancelled','lost') AND is_test_data = 0 ORDER BY id", { c: c.id })).map((r) => String(r.order_reference));
    out.push(c);
  }
  return out;
}

/** D-254: how a client came to the associate — which code, a buyer registration link, or linked by NexaCrest. */
async function cameVia(client) {
  const source = String(client.associate_source || '');
  const inv = client.associate_invitation_id ? await require('./associateInvitationRepository').find(Number(client.associate_invitation_id)) : null;
  if (source === 'code' || (inv && inv.kind === 'personal_code')) {
    return inv && inv.code_used ? `Code ${inv.code_used}` : 'Personal code';
  }
  if (source === 'link' || (inv && inv.kind === 'client_link')) {
    return 'Buyer registration link';
  }
  return ({ staff: 'Linked by NexaCrest', md_change: 'Changed by NexaCrest' })[source] || (source !== '' ? source : '—');
}

/** Every order of the associate's clients, newest first. */
async function orders(associateId, orderId = null) {
  let sql = `SELECT o.id, o.order_reference, o.status, o.created_at, o.client_id, sm.stage_number AS current_stage_number, o.buyer_po_received_on, o.associate_rate_pct, o.associate_rate_source,
                    c.company_legal_name, o.currency_code, oi.locked_at, oi.fully_paid_on, oi.associate_id AS paid_associate_id, oi.associate_amount_fc,
                    oi.associate_rate_pct AS locked_rate, oi.associate_mode, oi.incentive_month, oi.associate_fixed_at
               FROM orders o JOIN clients c ON c.id = o.client_id
               LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
               LEFT JOIN order_incentives oi ON oi.order_id = o.id
              WHERE c.associate_id = :a AND o.is_test_data = 0`;
  const params = { a: associateId };
  if (orderId !== null && orderId !== undefined) {
    sql += ' AND o.id = :o';
    params.o = orderId;
  }
  const rows = [];
  for (const o of await db.query(`${sql} ORDER BY o.created_at DESC, o.id DESC`, params)) {
    o.fob_value = await orderProductRepository.totalFobValue(Number(o.id));
    o.products = await productSummary(Number(o.id));
    o.stage = stageLabel(o);
    o.buyer_paid_in_full = (await incentive().fullyPaidOn(Number(o.id))) !== null;
    o.commission = await commission(associateId, o);
    rows.push(o);
  }
  return rows;
}

/** "Granite slabs + 2 more" — descriptions only, never prices. */
async function productSummary(orderId) {
  const lines = (await db.query('SELECT description FROM order_products WHERE order_id = :o AND is_active = 1 ORDER BY line_no, id', { o: orderId })).map((r) => String(r.description ?? ''));
  if (!lines.length) return '—';
  const first = [...lines[0]].slice(0, 60).join('');
  return lines.length > 1 ? `${first} + ${lines.length - 1} more` : first;
}

const NONE_REPEAT = 'No commission — this is a repeat order, placed more than 10 days after the first purchase order of this client';

/**
 * The commission line for one order (owner decision D-245 P3: on the invoiced FOB of
 * the goods, line by line, at the level fixed at PI, full payment only).
 */
async function commission(associateId, o) {
  const associateCommission = require('../services/associateCommission');
  const orderRepository = require('./orderRepository');
  const none = (label) => ({ state: 'none', label, rate: null, source: null, amount: null, level: null, detail: null });
  if (['cancelled', 'lost'].includes(o.status)) return none('No commission — order cancelled');
  // D-264: fixed by itself when the buyer paid in full (or with the MD's lock, before D-264).
  if (o.locked_at || o.associate_fixed_at) {
    if (Number(o.paid_associate_id || 0) !== Number(associateId)) return none(NONE_REPEAT);
    if (Number(o.associate_amount_fc) <= 0) return none('No commission — the buyer did not pay this order in full');
    const p = (await db.queryOne(
      "SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND associate_id = :a AND clawback_of_payout_id IS NULL ORDER BY id LIMIT 1",
      { o: o.id, a: associateId }
    )) || { status: 'due', paid_on: null, due_on: null, payout_run_on: null };
    const claim = !!p.due_on && (await associateCommission.settings()).claim_hold && await associateCommission.claimOpen(Number(o.id), p.due_on);
    const label = payoutLabel(p, claim, String(o.incentive_month || ''));
    const order = await orderRepository.find(Number(o.id));
    const fixed = order ? associateCommission.fixedLevel(order, associateId) : null;
    const lockedPct = o.locked_rate !== null && o.locked_rate !== undefined ? `${Number(o.locked_rate).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')}%` : null;
    const level = fixed ? associateCommission.levelLabel(fixed) : lockedPct;
    // D-292: the % only (never a level name) for the associate.
    const rateText = fixed ? associateCommission.rateLabel(fixed) : lockedPct;
    return { state: p.status === 'paid' ? 'paid' : (p.status === 'held' || claim ? 'held' : 'earned'), label,
      rate: o.locked_rate !== null && o.locked_rate !== undefined ? Number(o.locked_rate) : null, source: o.associate_rate_source ?? null,
      amount: Number(p.amount_fc !== undefined && p.amount_fc !== null ? p.amount_fc : o.associate_amount_fc), level, rate_text: rateText, detail: await associateCommission.forOrder(Number(o.id)),
      // D-264: what an associate in India is paid, fixed in rupees (never shown to an associate abroad).
      fixed_inr: p.amount_inr !== undefined && p.amount_inr !== null ? Number(p.amount_inr) : null,
      fixed_rate: p.fixed_rate !== undefined && p.fixed_rate !== null ? Number(p.fixed_rate) : null,
      fixed_on: p.fixed_on || null, currency: String(o.currency_code) };
  }
  if ((o.associate_mode || 'auto') === 'no') return none('No commission on this order (decided by NexaCrest)');
  if ((o.associate_rate_source ?? null) === 'none') return none('No commission under your agreement');
  const order = (await orderRepository.find(Number(o.id))) || {};
  const c = await associateCommission.forOrder(Number(o.id), order);
  if (!c) return none(NONE_REPEAT);
  // D-292: "Not earned yet — buyer still paying"; once paid in full (fixed the same day by itself, or by the daily job) "Earned — will be paid on <date>".
  const fullyPaidOn = await incentive().fullyPaidOn(Number(o.id));
  const label = fullyPaidOn !== null
    ? `Earned — will be paid on ${dates.short(await associateCommission.payoutRunOn(await associateCommission.dueOn(String(fullyPaidOn).slice(0, 10))))}`
    : NOT_EARNED_LABEL;
  return { state: 'expected', label, rate: c.rate, source: o.associate_rate_source ?? null, amount: c.amount_fc,
    level: c.level_label + (c.level_fixed ? '' : ' (for now — fixed when the Proforma Invoice is issued)'),
    rate_text: associateCommission.rateLabel(c.level) + (c.level_fixed ? '' : ' for now — final when we send the buyer the proforma invoice'), detail: c };
}

/**
 * D-264: a fixed commission is "Fixed — due …" until its due date, then "Due — paid in the … payout run";
 * held (a buyer claim, or by NexaCrest) or paid.
 */
/** D-292: the associate's commission statuses (exact wording, owner 11 Nov 2026). */
const NOT_EARNED_LABEL = 'Not earned yet — buyer still paying';
const CLAIM_LABEL = 'On hold — a buyer claim on this order is open';

/**
 * D-292: "Earned — will be paid on <date>", "Paid on <date> (ref)", "On hold — a buyer claim on this order is
 * open" ("Not earned yet — buyer still paying" in commission()). A hold by NexaCrest keeps its reason.
 */
function payoutLabel(p, claim, incentiveMonth = '', today = null) { // eslint-disable-line no-unused-vars
  const d = (v) => dates.short(String(v).slice(0, 10));
  const claimHeld = p.status === 'held' && String(p.hold_reason || '') === require('../services/associatePayoutService').CLAIM_HOLD_REASON;
  if (p.status === 'paid') return `Paid on ${d(p.paid_on)}${p.paid_reference ? ` (${p.paid_reference})` : ''}`;
  if (claim || claimHeld) return CLAIM_LABEL;
  if (p.status === 'held') return 'On hold' + (p.hold_reason ? ` — ${p.hold_reason}` : '');
  if (p.payout_run_on) return `Earned — will be paid on ${d(p.payout_run_on)}`;
  return `Earned — will be paid in the ${incentive().monthLabel(String(incentiveMonth))} payout`;
}

/** D-292: the % fixed on an order, as the associate sees it ("5%"), or null when none is fixed yet. */
function orderRateText(row) {
  if (!row.associate_level_kind || row.associate_level_value === null || row.associate_level_value === undefined) return null;
  return require('../services/associateCommission').rateLabel({ kind: String(row.associate_level_kind), value: Number(row.associate_level_value) });
}

/** D-264: "USD 1,250.00" (several currencies joined with " + "; "USD 0.00" when there is nothing). */
function fcText(byCurrency, def = 'USD') {
  const parts = [];
  for (const [cur, v] of Object.entries(byCurrency || {})) {
    if (Math.abs(Number(v)) >= 0.005) parts.push(`${cur} ${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
  }
  return parts.length ? parts.join(' + ') : `${def} 0.00`;
}

/** The associate's payouts (commission lines), newest first. */
async function payouts(associateId) {
  return db.query(
    `SELECT p.*, o.order_reference, c.company_legal_name, o.associate_level_kind, o.associate_level_value FROM incentive_payouts p JOIN orders o ON o.id = p.order_id JOIN clients c ON c.id = o.client_id
      WHERE p.payee_kind = 'associate' AND p.associate_id = :a ORDER BY p.incentive_month DESC, p.id DESC`,
    { a: associateId }
  );
}

/** D-263: the first month (YYYY-MM) of the portal window of N months, counting the current month (IST). */
function portalFromMonth(months, today = null) {
  const t = String(today || new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10));
  const d = new Date(Date.UTC(Number(t.slice(0, 4)), Number(t.slice(5, 7)) - 1, 1));
  d.setUTCMonth(d.getUTCMonth() - Math.max(0, months - 1));
  return d.toISOString().slice(0, 7);
}

/**
 * D-263: the payouts an associate sees in the portal — commission earned (incentive month) in the
 * last N months, plus anything still waiting to be paid or on hold whatever its month (money still owed).
 * Staff screens use payouts(), which is unlimited.
 */
async function portalPayouts(associateId, months, today = null) {
  const from = portalFromMonth(months, today);
  return (await payouts(associateId)).filter((p) => String(p.incentive_month) >= from || ['due', 'held'].includes(String(p.status)));
}

/**
 * INR commission totals for the portal: earned (incentive month) and paid (paid on) within the last N
 * months; waiting and on hold = all unpaid. Commission only — never an order count or order value
 * (owner rule, 6 Nov 2026). Returns {earned, waiting, paid, held, months}.
 */
async function totals(associateId, months = null, today = null) {
  if (months === null || months === undefined) months = require('../services/associateCommission').portalMonths(await require('./incentiveRepository').settings());
  const from = portalFromMonth(months, today);
  const t = { earned: 0, waiting: 0, paid: 0, held: 0 };
  // D-264: the associate sees the order currency (USD) as the main figure — per currency.
  const fc = { earned: {}, waiting: {}, paid: {}, held: {} };
  const add = (m, cur, v) => { m[cur] = Math.round(((m[cur] || 0) + v) * 100) / 100; };
  for (const p of await payouts(associateId)) {
    const amount = Number(p.amount_inr);
    const cur = String(p.currency_code || 'INR');
    const amountFc = p.amount_fc !== null && p.amount_fc !== undefined ? Number(p.amount_fc) : amount;
    if (String(p.incentive_month) >= from) { t.earned += amount; add(fc.earned, cur, amountFc); }
    if (p.status === 'due') { t.waiting += amount; add(fc.waiting, cur, amountFc); }
    else if (p.status === 'held') { t.held += amount; add(fc.held, cur, amountFc); }
    else if (p.status === 'paid' && String(p.paid_on || '').slice(0, 7) >= from) { t.paid += Number(p.paid_inr); add(fc.paid, cur, amountFc); }
  }
  for (const k of Object.keys(t)) t[k] = Math.round(t[k] * 100) / 100;
  return { ...t, months, fc };
}

module.exports = {
  cameVia, stageLabel, clients, orders, commission, payouts, portalFromMonth, portalPayouts, totals, payoutLabel, fcText, orderRateText, NOT_EARNED_LABEL, CLAIM_LABEL };
