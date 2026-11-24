'use strict';

/**
 * Owner decision D-410 (24 Nov 2026, confirm 1 + confirm 2): every order of an associate's client earns.
 * PHP twin: src/Services/AssociateRules.php (same rules, same wording).
 *
 *  - Every order of the client earns while the agreement is active (the old "client's first 10 days only" rule
 *    is gone for orders whose Proforma Invoice is issued from the cut-over date on — owner choice 2A).
 *  - An order earns only when its value of the goods (FOB) is at least the minimum (setting, ₹5,00,000);
 *    the client's first order always earns, whatever its size.
 *  - Ladder steps: an order of at least the minimum counts as one step once its advance has cleared (LC orders:
 *    once the LC is confirmed; no advance and no LC: once the buyer has paid in full). Orders of the same client
 *    within N days (setting, default 15) of the first order of a group are one step. Cancelled or lost orders
 *    never count. The history is worked out again every time, so it follows the rules from the start (2A).
 *  - The client's price includes a % chosen by the MD (setting: starting rate / a fixed % (default 5%) /
 *    ladder rate; per-associate override). The associate's lowest price uses min(their rate, that %); any
 *    commission above that % is paid from NexaCrest's margin.
 *
 * No cache across requests: the history is read fresh on every call (inside a report batch, batchCache keeps
 * it for that batch only). reset() exists for parity with PHP.
 */
const db = require('../config/db');
const batchCache = require('../helpers/batchCache');
const incentiveRepository = require('../repositories/incentiveRepository');
const pricingRepository = require('../repositories/pricingRepository');
const pricingEngine = require('./pricingEngine');

const PRICE_RULES = { starting: 'Starting rate', fixed: 'A fixed %', ladder: 'Ladder rate' };
const PRICE_RULE_TEXT = {
  starting: "The client's price always includes the first ladder step. The price never rises. When the associate is above it, we pay the rest from our margin.",
  fixed: "The client's price always includes this %. The price never rises. Below it, the associate can lower the price to win the deal (or we keep the difference); above it, we pay the rest from our margin.",
  ladder: "The client's price includes the associate's full current %. Nothing comes from our margin, but the client's price goes up each time the associate moves up the ladder.",
};
const GROUP_DAYS_MIN = 7;
const GROUP_DAYS_MAX = 45;

const commission = () => require('./associateCommission');
const incentiveService = () => require('./incentiveService');
const ymd = (v) => (v instanceof Date ? v.toISOString().slice(0, 10) : String(v || '').slice(0, 10));
const fmtPct = (v) => `${String(Number(v).toFixed(2)).replace(/\.?0+$/, '')}%`;
const inrWhole = (v) => require('../helpers/moneyFormat').indian(Number(v), 0); // PHP MoneyFormat::indian(v, 0) — rupees in Indian grouping

function reset() {}

/** Same client within this many days of the first order of a group = one ladder step (default 15, 7–45). */
async function groupDays() {
  const v = parseInt((await incentiveRepository.settings()).associate_group_days ?? '15', 10);
  return Math.max(GROUP_DAYS_MIN, Math.min(GROUP_DAYS_MAX, v > 0 ? v : 15));
}

/** The minimum value of the goods (FOB, ₹) an order needs to earn commission and to count on the ladder. */
async function minOrderInr() {
  return Number((await incentiveRepository.settings()).associate_counting_threshold_inr ?? 500000);
}

/** The day the D-410 rules started (migration 2026-11-24a); null = the rules apply to every order. */
async function cutover() {
  const v = String((await incentiveRepository.settings()).associate_rules_from ?? '').trim();
  return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : null;
}

/** Whether the D-410 rules decide this order: its PI is not yet issued, or was issued on or after the cut-over date (2A). */
async function newRules(order) {
  const from = await cutover();
  if (from === null) return true;
  const fixedAt = order.associate_level_fixed_at ? ymd(order.associate_level_fixed_at) : '';
  return fixedAt === '' || fixedAt >= from;
}

/** The step moment: advance cleared; LC orders: LC confirmed; no advance and no LC: paid in full. */
async function stepTriggered(order) {
  const id = Number(order.id);
  if (String(order.payment_method || '') === 'LC') {
    const lc = await require('../repositories/orderLcDetailRepository').find(id);
    return !!(lc && lc.lc_confirmed_at);
  }
  if (Number(order.advance_pct || 0) > 0) {
    const ps = await require('../repositories/orderPaymentStatusRepository').find(id);
    return !!(ps && ps.advance_cleared_at);
  }
  return (await incentiveService().fullyPaidOn(id)) !== null;
}

/** The order's value of the goods in rupees for the minimum (in range, estimated until the buyer's payments are in). */
async function orderValueInr(order) {
  const c = commission();
  return c.estimateInr(order, await c.inRangeValue(Number(order.id)));
}

function days(from, to) {
  const d = (s) => Date.UTC(...ymd(s).split('-').map((v, i) => (i === 1 ? Number(v) - 1 : Number(v))));
  return Math.floor((d(to) - d(from)) / 86400000);
}

async function historyUncached(associateId) {
  const rows = await db.query(
    `SELECT o.* FROM orders o JOIN clients c ON c.id = o.client_id LEFT JOIN order_incentives oi ON oi.order_id = o.id
      WHERE (o.associate_id = :a OR (o.associate_id IS NULL AND c.associate_id = :a2))
        AND o.status NOT IN ('cancelled','lost') AND (oi.associate_mode IS NULL OR oi.associate_mode <> 'no')
      ORDER BY COALESCE(o.buyer_po_received_on, DATE(o.created_at)), o.id`,
    { a: associateId, a2: associateId }
  );
  const gd = await groupDays();
  const min = await minOrderInr();
  const start = {};
  let step = 0;
  const out = [];
  for (const o of rows) {
    const po = incentiveService().poDate(o);
    const row = { order_id: Number(o.id), client_id: Number(o.client_id), po_date: po, reference: String(o.order_reference),
      status: 'not_yet', step: null, group_order_id: null, group_reference: null };
    if (await stepTriggered(o)) {
      const value = await orderValueInr(o);
      const client = Number(o.client_id);
      if (start[client] && days(start[client].po, po) < gd) {
        row.status = 'same_group';
        row.step = start[client].step;
        row.group_order_id = start[client].order_id;
        row.group_reference = start[client].reference;
      } else if (value === null || value < min) {
        row.status = 'below_min';
      } else {
        step += 1;
        start[client] = { po, step, order_id: Number(o.id), reference: String(o.order_reference) };
        row.status = 'counted';
        row.step = step;
      }
    }
    out.push(row);
  }
  return out;
}

/**
 * The associate's orders in date order with their ladder status:
 * counted (step n) · same_group (of order x) · below_min · not_yet (step moment not reached).
 */
async function history(associateId) {
  return batchCache.memoRead(`associate_history_${Number(associateId)}`, () => historyUncached(Number(associateId)));
}

/** Ladder steps counted so far, leaving one order out (the order being priced). */
async function stepsCounted(associateId, excludeOrderId = null) {
  return (await history(associateId)).filter((r) => r.status === 'counted' && r.order_id !== Number(excludeOrderId || 0)).length;
}

/** The ladder row of one order, or null. */
async function stepFor(associateId, orderId) {
  return (await history(associateId)).find((r) => r.order_id === Number(orderId)) || null;
}

/** A counted group of the same client whose window covers this order (it then shares that group's step and level). */
async function groupCovering(associateId, order) {
  const po = incentiveService().poDate(order);
  const gd = await groupDays();
  return (await history(associateId)).find((r) => r.status === 'counted' && r.order_id !== Number(order.id) && r.client_id === Number(order.client_id)
    && r.po_date <= po && days(r.po_date, po) < gd) || null;
}

/** Whether this order earns: at least the minimum, or the client's first order. */
async function earnsOnValue(order, valueInr) {
  if (await incentiveService().isClientsFirstOrder(order)) return true;
  return valueInr !== null && valueInr !== undefined && valueInr >= (await minOrderInr());
}

/** One-line ladder status for staff screens (the "Ladder step" column). */
async function stepText(row) {
  if (!row) return '—';
  switch (row.status) {
    case 'counted': return `Step ${row.step}`;
    case 'same_group': return `Same step as ${row.group_reference || `#${row.group_order_id}`} (same client within ${await groupDays()} days)`;
    case 'below_min': return `Does not count — below ₹${inrWhole(await minOrderInr())}`;
    default: return 'Counts when the advance has cleared';
  }
}

// ------------------------------------------------------------------ price rule

/** The company setting: { rule: starting|fixed|ladder, pct: number|null }. */
async function companyPriceRule() {
  const s = await pricingRepository.settings();
  let rule = String(s.associate_price_rule ?? 'fixed');
  rule = PRICE_RULES[rule] ? rule : 'fixed';
  return { rule, pct: rule === 'fixed' ? Number(s.associate_price_fixed_pct ?? 5) : null };
}

/** The rule that applies to one associate: their own (D-410 override) or the company's; `own` says which. */
async function priceRuleFor(associate) {
  const own = String(associate.price_rule_override || '');
  if (PRICE_RULES[own]) {
    return { rule: own, pct: own === 'fixed' ? Number(associate.price_rule_fixed_pct || 0) : null, own: true };
  }
  return { ...(await companyPriceRule()), own: false };
}

/** The first step's % of the associate's ladder (their fixed level when they have a fixed %). */
async function startingPct(associate) {
  if ((associate.commission_mode || 'ladder') === 'fixed') {
    const l = associate.commission_level_id ? await pricingRepository.findLevel(Number(associate.commission_level_id)) : null;
    return l && (l.kind || 'pct') === 'pct' ? Number(l.value) : null;
  }
  const steps = await commission().ladderFor(associate);
  return steps.length && (steps[0].kind || 'pct') === 'pct' ? Number(steps[0].value) : null;
}

/** The % included in the client's price for this associate at this level (null = a USD-per-unit level: priced as today). */
async function includedPct(associate, level) {
  if (!level || (level.kind || 'pct') !== 'pct') return null;
  const r = await priceRuleFor(associate);
  if (r.rule === 'starting') {
    const s = await startingPct(associate);
    return s !== null ? s : Number(level.value);
  }
  if (r.rule === 'ladder') return Number(level.value);
  return Number(r.pct);
}

/** The % the company setting puts in the client's price for an associate at this level (no per-associate override). */
async function companyIncludedPct(level) {
  if (!level || (level.kind || 'pct') !== 'pct') return null;
  const r = await companyPriceRule();
  if (r.rule === 'starting') {
    const steps = await pricingRepository.ladder();
    return steps.length && (steps[0].kind || 'pct') === 'pct' ? Number(steps[0].value) : Number(level.value);
  }
  return r.rule === 'ladder' ? Number(level.value) : Number(r.pct);
}

/** The part of the commission paid from NexaCrest's margin, in % of the FOB (0 when the price covers it). */
function marginPct(ratePct, incPct) {
  if (ratePct === null || ratePct === undefined || incPct === null || incPct === undefined) return 0;
  return Math.max(0, require('../helpers/money').phpRound(Number(ratePct) - Number(incPct), 4));
}

/** An associate level's prices under the company setting, with the part paid from the margin (product page, levels page). */
async function levelPrices(raw, level) {
  const inc = await companyIncludedPct(level);
  if (inc === null) {
    return { ...pricingEngine.associate(raw, String(level.kind), Number(level.value)), included_pct: null, margin_pct: 0 };
  }
  return { ...pricingEngine.associateIncluded(raw, Number(level.value), inc), included_pct: inc, margin_pct: marginPct(Number(level.value), inc) };
}

/** "Right now" line for the associate page: what the price includes, the rate, and what comes from the margin. */
async function rightNow(associate, level) {
  const inc = await includedPct(associate, level);
  if (inc === null) {
    return 'Right now: this associate has a fixed USD amount per unit — the client price includes it, as before.';
  }
  const rate = Number(level.value);
  const m = marginPct(rate, inc);
  const tail = m > 0 ? `${fmtPct(m)} comes from our margin.` : (inc > rate ? `the associate has ${fmtPct(inc - rate)} room to negotiate.` : 'nothing comes from our margin.');
  return `Right now for this associate: client price includes ${fmtPct(inc)} · associate is at ${fmtPct(rate)} · ${tail}`;
}

/** Paid from our margin (₹) on this associate's orders with the % fixed at their PI, in the financial year of today. */
async function marginSoFar(associateId, today = null) {
  const t = today || new Date().toISOString().slice(0, 10);
  const y = Number(t.slice(0, 4));
  const fyStart = `${Number(t.slice(5, 7)) >= 4 ? y : y - 1}-04-01`;
  const rows = await db.query(
    `SELECT id, associate_rate_pct, associate_included_pct FROM orders
      WHERE associate_id = :a AND associate_level_fixed_at >= :f AND associate_included_pct IS NOT NULL AND associate_rate_pct IS NOT NULL
        AND status NOT IN ('cancelled','lost')`,
    { a: associateId, f: fyStart }
  );
  // Owner 24 Nov 2026 (recommendation 5): only earned commission (the buyer paid in full) counts; orders whose
  // buyer is still paying are shown apart as "expected", since they may never be paid.
  let inr = 0;
  let n = 0;
  let openInr = 0;
  let openN = 0;
  for (const o of rows) {
    const m = marginPct(Number(o.associate_rate_pct), Number(o.associate_included_pct));
    if (m <= 0) continue;
    const c = await commission().forOrder(Number(o.id));
    if (!c || Number(c.rate || 0) <= 0 || c.state === 'unpaid') continue;
    const part = pricingEngine.round2(Number(c.amount_inr) * m / Number(c.rate));
    if (c.state === 'paid') { inr += part; n += 1; } else { openInr += part; openN += 1; }
  }
  return { inr: pricingEngine.round2(inr), orders: n, open_inr: pricingEngine.round2(openInr), open_orders: openN };
}

/**
 * "What each choice would cost us": over the last 12 months of associate orders with a level fixed at their PI,
 * the commission above the % each choice puts in the price (₹, at the order's own commission rupees).
 */
async function whatIf(fixedPct, today = null) {
  const t = new Date(`${today || new Date().toISOString().slice(0, 10)}T00:00:00Z`);
  t.setUTCMonth(t.getUTCMonth() - 12);
  const from = t.toISOString().slice(0, 10);
  const rows = await db.query(
    `SELECT o.id, o.associate_id, o.associate_rate_pct FROM orders o
      WHERE o.associate_id IS NOT NULL AND o.associate_level_fixed_at >= :f AND o.associate_rate_pct IS NOT NULL
        AND o.status NOT IN ('cancelled','lost') AND o.is_test_data = 0 AND o.is_sample_data = 0`,
    { f: from }
  );
  const out = { starting: 0, fixed: 0, ladder: 0, fixed_pct: fixedPct, orders: 0 };
  for (const o of rows) {
    const c = await commission().forOrder(Number(o.id));
    const rate = Number(o.associate_rate_pct);
    if (!c || rate <= 0) continue;
    const a = await require('../repositories/associateRepository').find(Number(o.associate_id));
    const sp = a ? await startingPct(a) : null;
    const start = sp !== null ? sp : rate;
    const per = Number(c.amount_inr) / rate;
    out.starting += per * Math.max(0, rate - start);
    out.fixed += per * Math.max(0, rate - fixedPct);
    out.orders += 1;
  }
  for (const k of ['starting', 'fixed', 'ladder']) out[k] = pricingEngine.round2(out[k]);
  return out;
}

/** How many associates follow their own price choice instead of the company setting. */
async function ownRuleCount() {
  const row = await db.queryOne("SELECT COUNT(*) AS n FROM associates WHERE price_rule_override IS NOT NULL AND price_rule_override <> ''");
  return Number(row.n);
}

module.exports = {
  PRICE_RULES, PRICE_RULE_TEXT, GROUP_DAYS_MIN, GROUP_DAYS_MAX,
  reset, groupDays, minOrderInr, cutover, newRules, stepTriggered, orderValueInr, history, stepsCounted, stepFor, groupCovering,
  earnsOnValue, stepText, companyPriceRule, priceRuleFor, startingPct, includedPct, companyIncludedPct, levelPrices, marginPct,
  rightNow, marginSoFar, whatIf, ownRuleCount,
};
