'use strict';

const db = require('../config/db');
const associateRepository = require('../repositories/associateRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const clientRepository = require('../repositories/clientRepository');
const userRepository = require('../repositories/userRepository');

/**
 * Port of IncentiveService.php. Team incentive program and associate
 * commission.
 *
 * Owner decision D-245 P4 (4 Nov 2026):
 * - The team incentive is a % of the order's profit after the associate's
 *   commission (never below 0) or a fixed amount: the global rule (Pricing
 *   settings), a product's own rule, or the MD's amount for this one order,
 *   with a reason. With the program off there is no global incentive, but the
 *   MD may still set one by hand on one order.
 * - The roles are an editable list with usual shares adding up to 100%; on
 *   an order the MD ticks who gets it (unticked shares spread proportionally
 *   over the ticked roles) or sets the shares; equal split inside a role.
 * - Nothing locks by itself: an order is "ready to lock" once the buyer has
 *   fully paid and no claim is open; the MD locks it (one or all ready) and
 *   may unlock it with a reason until anything is paid. Every change is kept
 *   in the order's incentive history.
 * - Associate commission (D-245 P3, associateCommission) is deducted first
 *   and locks together with the order.
 * - Staff payouts go into the payout run after the lock date; associate
 *   commission keeps its due date and the run after it.
 * - The price check's "incentive cut" on a line the MD approved below the
 *   floor is shown only as an explanation: the incentive is a % of the
 *   order's actual profit, which that lower price already reduced, so it is
 *   never subtracted a second time.
 */

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** PHP round(x, 2) — the shared money helper (fresh-eyes review M3). */
const { round2 } = require('../helpers/money');

function pad(n) {
  return String(n).padStart(2, '0');
}

function lastDayOf(year, month) {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** PHP number_format($x, 2): 1,234.50 */
function fmt2(x) {
  return Number(x).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function settings() {
  const raw = await incentiveRepository.settings();
  return {
    enabled: (raw.program_enabled ?? '0') === '1',
    reminder_days: Math.max(0, Math.min(28, parseInt(raw.lock_reminder_days ?? '3', 10) || 0)),
  };
}

/** The incentive month (YYYY-MM) a full payment on date belongs to. */
/** QA Round 6, R6-N29: a real incentive month, YYYY-MM with MM 01-12. */
function isMonth(month) {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(String(month));
}

/**
 * QA Round 6, R6-N10: the earliest date a payout of this incentive month
 * can have been paid — the 1st of the month before it (the cycle runs from
 * the cut-off day of the previous month). Port of
 * IncentiveService::earliestPaidOn().
 */
function earliestPaidOn(month) {
  if (!isMonth(month)) return '2000-01-01';
  let [y, m] = String(month).split('-').map(Number);
  m -= 1;
  if (m === 0) { m = 12; y -= 1; }
  return `${y}-${String(m).padStart(2, '0')}-01`;
}

/**
 * D-245 P4: the payout month (YYYY-MM) of something locked on `date` — the
 * month of the first payout run on or after it (Associates › Commission
 * settings: monthly on day N, or weekly).
 */
async function incentiveMonth(date) {
  return String(await require('./associateCommission').payoutRunOn(String(date).slice(0, 10))).slice(0, 7);
}

/** "November" for 2026-11. */
function monthName(month) {
  return MONTHS[Number(String(month).split('-')[1]) - 1];
}

function monthLabel(month) {
  const [y, m] = String(month).split('-').map(Number);
  return `${MONTHS[m - 1]} ${y}`;
}

/** The date the buyer's last payment cleared, once every buyer payment the order has is cleared; else null. */
async function fullyPaidOn(orderId) {
  const order = await orderRepository.find(orderId);
  const payment = await orderPaymentStatusRepository.find(orderId);
  if (!order || !payment) {
    return null;
  }
  const dates = [];
  if ((order.payment_method || '') === 'LC') {
    const lc = await orderLcDetailRepository.find(orderId);
    if (lc && Number(lc.lc_amount || 0) > 0) {
      if (!lc.lc_received_at) {
        return null;
      }
      dates.push(String(lc.lc_received_at).slice(0, 10));
    }
  }
  for (const leg of ['advance', 'balance', 'freight']) {
    if (Number(payment[`${leg}_amount`] || 0) <= 0) {
      continue;
    }
    if (!payment[`${leg}_cleared_at`]) {
      return null;
    }
    dates.push(String(payment[`${leg}_cleared_at`]).slice(0, 10));
  }
  // QA batch 4, MON-2: a middle payment milestone must be cleared too before the order is fully paid.
  for (const m of await require('../repositories/paymentMilestoneRepository').forOrder(orderId)) {
    if (Number(m.pct) <= 0) {
      continue;
    }
    if (!m.cleared_on) {
      return null;
    }
    dates.push(String(m.cleared_on).slice(0, 10));
  }
  return dates.length === 0 ? null : dates.sort()[dates.length - 1];
}

/** Whether this is the client's first order (its first real, not cancelled or lost, order). */
async function isClientsFirstOrder(order) {
  const row = await db.queryOne(
    `SELECT id FROM orders WHERE client_id = :c AND is_test_data = 0 AND is_sample_data = 0 AND status NOT IN ('cancelled','lost')
     ORDER BY created_at, id LIMIT 1`,
    { c: order.client_id }
  );
  return row !== null && Number(row.id) === Number(order.id);
}

/**
 * Whether the order falls in the client's first 10 days. Day 1 is the day
 * the client's first PO was received (agreement clause 4.1, Step 4d); an
 * order confirmed before that date was recorded counts from its order date
 * instead.
 */
async function withinFirstTenDays(order) {
  const row = await db.queryOne(
    `SELECT MIN(COALESCE(buyer_po_received_on, DATE(created_at))) AS day_one FROM orders
     WHERE client_id = :c AND is_test_data = 0 AND is_sample_data = 0 AND status NOT IN ('cancelled','lost')`,
    { c: order.client_id }
  );
  if (!row || !row.day_one) {
    return false;
  }
  const toDay = (s) => Date.UTC(...String(s).slice(0, 10).split('-').map((v, i) => (i === 1 ? Number(v) - 1 : Number(v))));
  const days = Math.floor((toDay(poDate(order)) - toDay(row.day_one)) / 86400000);
  return days <= 9;
}

/** The day the order's PO was received, else its order date. */
function poDate(order) {
  return String(order.buyer_po_received_on || order.created_at).slice(0, 10);
}

/** Rupees per unit of the order currency actually received for the products (advance or LC, and balance). */
async function productRate(order) {
  if (String(order.currency_code || 'INR').toUpperCase() === 'INR') {
    return 1;
  }
  const payment = (await orderPaymentStatusRepository.find(Number(order.id))) || {};
  let inr = 0;
  let foreign = 0;
  for (const leg of ['advance', 'balance']) {
    if (Number(payment[`${leg}_amount`] || 0) > 0 && payment[`${leg}_inr_actual`] !== null && payment[`${leg}_inr_actual`] !== undefined) {
      inr += Number(payment[`${leg}_inr_actual`]);
      foreign += Number(payment[`${leg}_amount`]);
    }
  }
  const lc = await orderLcDetailRepository.find(Number(order.id));
  if ((order.payment_method || '') === 'LC' && lc && Number(lc.lc_received_amount || 0) > 0) {
    inr += Number(lc.lc_inr_credited) + Number(lc.lc_bank_charges_inr || 0); // QA batch 4, MON-6: plus the rupees the bank kept as LC charges
    foreign += Number(lc.lc_received_amount);
  }
  if (foreign > 0) {
    return inr / foreign;
  }
  return Number(payment.balance_exchange_rate ?? payment.advance_exchange_rate ?? 0);
}

/**
 * The people on each role: those set on the order, else the order's
 * assignees with that incentive role, else everyone with that role.
 *
 * @returns {Promise<Object<string, Array<{id:number,name:string}>>>}
 */
async function peopleFor(orderId) {
  const names = {};
  for (const u of await userRepository.listActive()) {
    names[Number(u.id)] = String(u.name);
  }
  const row = await incentiveRepository.forOrder(orderId);
  let chosen;
  if (row && Number(row.people_customised) === 1) {
    chosen = await incentiveRepository.orderPeople(orderId);
  } else {
    const rolesByUser = await incentiveRepository.userRoles();
    const assigned = (await orderAssigneeRepository.forOrder(orderId)).map((a) => Number(a.user_id));
    chosen = {};
    for (const role of Object.keys(await incentiveRepository.roles())) {
      const holders = Object.keys(rolesByUser).map(Number).filter((uid) => rolesByUser[uid].includes(role)).sort((a, b) => a - b);
      const onOrder = holders.filter((uid) => assigned.includes(uid));
      chosen[role] = onOrder.length ? onOrder : holders;
    }
  }
  const out = {};
  for (const role of Object.keys(await incentiveRepository.roles())) {
    out[role] = [];
    for (const userId of chosen[role] || []) {
      if (names[userId] !== undefined) {
        out[role].push({ id: userId, name: names[userId] });
      }
    }
  }
  return out;
}

/** The order's own amount: global / pct / fixed. */
function orderRule(row) {
  const mode = String((row && row.incentive_mode) || 'global');
  return {
    mode: ['pct', 'fixed'].includes(mode) ? mode : 'global',
    value: row && row.incentive_value !== null && row.incentive_value !== undefined ? Number(row.incentive_value) : null,
    reason: (row && row.incentive_reason) || null,
  };
}

/**
 * The global (or product) rule's amount for the order: % of the profit
 * basis, or a fixed USD amount per unit converted at the order's rate. A line
 * whose product has its own rule uses it, weighted by its share of the goods
 * FOB; other lines use the global rule.
 */
async function globalAmount(orderId, order, basis) {
  const pricingService = require('./pricingService');
  const associateCommission = require('./associateCommission');
  const s = await pricingService.settings();
  const lines = await associateCommission.goodsLines(orderId);
  const total = lines.reduce((sum, l) => sum + Number(l.fob_value), 0);
  // Fresh-eyes review H1: a "USD x per unit" rule is converted at the USD rate, not the order currency's.
  const rate = await associateCommission.usdRate(order);
  const describe = (mode, value) => (mode === 'usd' ? `USD ${fmt2(value)} per unit` : `${pricingService.pct(value)} of profit`);
  if (lines.length === 0 || total <= 0) {
    const amount = s.incentive_mode === 'usd' ? 0 : basis * s.incentive_value / 100;
    return { amount: round2(amount), text: describe(s.incentive_mode, s.incentive_value) };
  }
  let amount = 0;
  let own = false;
  const productRepository = require('../repositories/productRepository');
  for (const l of lines) {
    let mode = s.incentive_mode;
    let value = s.incentive_value;
    if (l.catalog_product_id) {
      const product = await productRepository.find(Number(l.catalog_product_id));
      if (product && product.pricing_incentive_mode !== null && product.pricing_incentive_mode !== undefined) {
        mode = String(product.pricing_incentive_mode);
        value = Number(product.pricing_incentive_value);
        own = true;
      }
    }
    amount += mode === 'usd' ? value * Number(l.quantity || 0) * rate : basis * value / 100 * (Number(l.fob_value) / total);
  }
  return { amount: round2(amount), text: describe(s.incentive_mode, s.incentive_value) + (own ? ' (some products have their own rule)' : '') };
}

/**
 * The order's incentive as it stands. Before the order is locked every
 * figure is an estimate; the locked figures are on order_incentives.
 */
/** baseProfit: the order's computeForOrder(id, false) when the caller has it already (D-245 P5: not worked out twice). */
async function calculate(orderId, baseProfit = null) {
  const orderProfitabilityService = require('./orderProfitabilityService');
  const associateCommission = require('./associateCommission');
  const pricingService = require('./pricingService');
  const order = await orderRepository.find(orderId);
  const row = await incentiveRepository.forOrder(orderId);
  const profit = baseProfit || await orderProfitabilityService.computeForOrder(orderId, false);
  const entries = await incentiveRepository.costEntries(orderId);
  const already = Object.values(entries).reduce((sum, c) => sum + Number(c.amount_inr), 0);
  const profitBefore = round2(Number(profit.profit_inr) + already);
  const revenue = Number(profit.revenue_inr || 0);

  // Owner decision D-245 P3: the associate's commission comes off first.
  const associate = await associateCommission.forOrder(orderId, order, row || { associate_mode: 'auto' });
  const assocInr = associate ? Number(associate.amount_inr) : 0;
  const basisRaw = round2(profitBefore - assocInr);
  // P4: the profit used for the incentive never goes below 0.
  const basis = Math.max(0, basisRaw);
  const programOn = (await settings()).enabled;
  const rule = orderRule(row);
  const global = await globalAmount(orderId, order, basis);
  let amount;
  let source;
  if (rule.mode === 'fixed') {
    amount = round2(rule.value);
    source = 'order_fixed';
  } else if (rule.mode === 'pct') {
    amount = round2(basis * rule.value / 100);
    source = 'order_pct';
  } else if (!programOn) {
    amount = 0;
    source = 'off';
  } else {
    amount = global.amount;
    source = 'global';
  }
  const sp = await split(orderId, amount);
  const minMargin = Number((await pricingService.settings()).margin_pct);
  const marginAfter = revenue > 0 ? round2((profitBefore - assocInr - sp.team_total) / revenue * 100) : null;
  let cutUsd = 0;
  for (const l of await associateCommission.goodsLines(orderId)) {
    if (l.price_approved_by && l.price_approval_incentive_cut !== null && l.price_approval_incentive_cut !== undefined) {
      cutUsd += Number(l.price_approval_incentive_cut);
    }
  }
  return {
    profit_before: profitBefore,
    revenue,
    associate,
    basis_raw: basisRaw,
    basis,
    estimated: (await fullyPaidOn(orderId)) === null,
    program_on: programOn,
    rule,
    global_amount: global.amount,
    global_text: global.text,
    source,
    amount,
    team_total: sp.team_total,
    unallocated: sp.unallocated,
    roles: sp.roles,
    per_person: sp.per_person,
    lines: sp.lines,
    team_by_share: sp.team_by_share,
    kept_total: sp.kept_total,
    kept_not_eligible: sp.kept_not_eligible,
    kept_capped: sp.kept_capped,
    shares_customised: sp.customised,
    left_for_company: round2(basis - sp.team_total),
    margin_after_pct: marginAfter,
    min_margin_pct: minMargin,
    margin_warning: marginAfter !== null && sp.team_total > 0 && marginAfter < minMargin,
    over_profit: amount > basis + 0.005,
    incentive_cut_usd: round2(cutUsd),
    state: await state(orderId, row),
  };
}

/**
 * Who gets what: each active role with its tick, people and share on this
 * order. Unticked roles (or roles with nobody) give their share to the ticked
 * ones in proportion; custom shares (they add up to 100%) win.
 */
/**
 * D-259 (owner approval 6 Nov 2026): who shares in the team incentive. A person's own switch
 * (users.incentive_eligible) wins; empty follows their designation (the default active
 * membership's department designation); with no designation they are eligible.
 */
async function eligibility() {
  const designation = new Map();
  const rows = await db.query(
    `SELECT m.user_id, d.title, d.incentive_eligible FROM user_department_memberships m
     JOIN department_designations d ON d.id = m.designation_id
     WHERE m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
     ORDER BY m.is_default DESC, m.id`);
  for (const r of rows) {
    if (!designation.has(Number(r.user_id))) designation.set(Number(r.user_id), { title: String(r.title), eligible: Number(r.incentive_eligible) === 1 });
  }
  const out = new Map();
  for (const u of await db.query('SELECT id, incentive_eligible FROM users')) {
    const id = Number(u.id);
    const d = designation.get(id) || null;
    if (u.incentive_eligible !== null && u.incentive_eligible !== undefined) {
      out.set(id, { eligible: Number(u.incentive_eligible) === 1, source: 'person', designation: d ? d.title : null });
    } else {
      out.set(id, { eligible: d ? d.eligible : true, source: d ? 'designation' : 'default', designation: d ? d.title : null });
    }
  }
  return out;
}

/** D-259: the incentive month an unlocked order would be locked into today (the next staff payout run). */
async function estimateMonth() {
  return String(await require('./associateCommission').payoutRunOn(todayIst())).slice(0, 7);
}

/** D-259: only the Managing Director changes who is eligible for the team incentive. */
async function isMd(user) {
  if (!user || !user.role_id) return false;
  const r = await db.queryOne('SELECT name FROM roles WHERE id = :id', { id: Number(user.role_id) });
  return !!r && r.name === 'Managing Director';
}

/** D-259: the person's designation and whether it is eligible (null: no designation). */
async function designationEligibility(userId) {
  const r = await db.queryOne(
    `SELECT d.title, d.incentive_eligible FROM user_department_memberships m
     JOIN department_designations d ON d.id = m.designation_id
     WHERE m.user_id = :u AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
     ORDER BY m.is_default DESC, m.id LIMIT 1`, { u: userId });
  return r ? { title: String(r.title), eligible: Number(r.incentive_eligible) === 1 } : null;
}

/** D-259: whether this person shares in the team incentive now (their own switch, else their designation). */
async function isEligible(userId) {
  const u = await db.queryOne('SELECT incentive_eligible FROM users WHERE id = :u', { u: userId });
  if (u && u.incentive_eligible !== null && u.incentive_eligible !== undefined) return Number(u.incentive_eligible) === 1;
  const d = await designationEligibility(userId);
  return d ? d.eligible : true;
}

/** D-259: active people whose own switch differs from their designation's. */
async function eligibilityOverrides() {
  const out = [];
  for (const u of await db.query('SELECT id, name, incentive_eligible FROM users WHERE is_active = 1 AND incentive_eligible IS NOT NULL ORDER BY name')) {
    const d = await designationEligibility(Number(u.id));
    const own = Number(u.incentive_eligible) === 1;
    if (own !== (d ? d.eligible : true)) out.push({ name: String(u.name), designation: d ? d.title : null, eligible: own });
  }
  return out;
}

/**
 * D-259: the MD sets the person's switch. Matching the designation stores "follow the
 * designation" (NULL), so a later change to the designation still applies to them.
 * Resolves an error or null.
 */
async function setEligible(actor, userId, eligible) {
  if (!(await isMd(actor))) return 'Only the MD can change who is eligible for the team incentive.';
  const before = await isEligible(userId);
  const d = await designationEligibility(userId);
  const value = eligible === (d ? d.eligible : true) ? null : (eligible ? 1 : 0);
  await db.execute('UPDATE users SET incentive_eligible = :v WHERE id = :u', { v: value, u: userId });
  if (before !== eligible) {
    await require('../repositories/auditLogRepository').log(Number(actor.id), 'USER_UPDATED', 'users', userId, 'incentive_eligible', before ? '1' : '0', eligible ? '1' : '0');
  }
  return null;
}

/** Indian grouping, no decimals: 14000 => "14,000". */
function inr0(v) {
  return Math.round(Number(v)).toLocaleString('en-IN');
}

/**
 * D-259: one person's line in one role — their share, what they get and what the company
 * keeps (not eligible, or above the role's per-order / monthly cap). The monthly cap counts
 * what the person already has locked in that role for the month the order would lock into.
 */
async function applyEligibilityAndCaps(orderId, role, roleLabel, person, share, roleRow, elig) {
  const line = { user_id: Number(person.id), name: String(person.name), role, role_label: roleLabel,
    by_share: round2(share), gets: round2(share), kept: 0, reason: null, note: null };
  if (share <= 0) return line;
  const e = elig.get(Number(person.id)) || { eligible: true, source: 'default', designation: null };
  if (!e.eligible) {
    line.gets = 0;
    line.kept = line.by_share;
    line.reason = 'not_eligible';
    line.note = e.source === 'person'
      ? 'not eligible (switched off for this person), share stays with company'
      : `not eligible (${e.designation || 'designation'} designation), share stays with company`;
    return line;
  }
  const capOrder = roleRow ? roleRow.cap_per_order : null;
  if (capOrder !== null && capOrder !== undefined && line.gets > capOrder) {
    line.gets = round2(capOrder);
    line.reason = 'cap_order';
    line.note = `capped at ₹${inr0(capOrder)} per order`;
  }
  const capMonth = roleRow ? roleRow.cap_per_month : null;
  if (capMonth !== null && capMonth !== undefined) {
    const already = await incentiveRepository.lockedInMonth(Number(person.id), role, await estimateMonth(), orderId);
    const room = round2(Math.max(0, capMonth - already));
    if (line.gets > room) {
      line.gets = room;
      line.reason = 'cap_month';
      line.note = `capped: ₹${inr0(already)} already this month, monthly cap ₹${inr0(capMonth)}`;
    }
  }
  line.kept = round2(line.by_share - line.gets);
  return line;
}

async function split(orderId, amount) {
  const row = await incentiveRepository.forOrder(orderId);
  const customised = Number((row && row.shares_customised) || 0) === 1;
  const ticks = await incentiveRepository.orderRoles(orderId);
  const people = await peopleFor(orderId);
  const all = await incentiveRepository.allRoles();
  const roles = {};
  for (const [key, label] of Object.entries(await incentiveRepository.roles())) {
    const hasPeople = (people[key] || []).length > 0;
    const ticked = ticks[key] ? ticks[key].gets_it : true;
    roles[key] = { key, label, usual_pct: all[key].share_pct, ticked, gets_it: ticked && hasPeople, has_people: hasPeople,
      people: people[key] || [], custom_pct: ticks[key] ? ticks[key].share_pct : null, pct: 0, amount: 0, each: 0 };
  }
  const weights = {};
  for (const [key, r] of Object.entries(roles)) {
    if (r.gets_it) weights[key] = customised ? Number(r.custom_pct || 0) : r.usual_pct;
  }
  const sum = Object.values(weights).reduce((a, b) => a + b, 0);
  let teamTotal = 0;
  let byShare = 0;
  const perPerson = new Map();
  const lines = [];
  if (sum > 0 && amount > 0) {
    const keys = Object.keys(weights);
    const elig = await eligibility();
    let allocated = 0;
    for (let i = 0; i < keys.length; i++) {
      const key = keys[i];
      const pct = weights[key] / sum * 100;
      // The last ticked role takes the rounding, so the shares add up to the amount exactly.
      const roleAmount = i === keys.length - 1 ? round2(amount - allocated) : round2(amount * pct / 100);
      allocated += roleAmount;
      const n = roles[key].people.length;
      const each = round2(roleAmount / n);
      roles[key].pct = round2(pct);
      roles[key].amount = roleAmount;
      roles[key].each = each;
      for (let j = 0; j < n; j++) {
        const p = roles[key].people[j];
        const share = j === n - 1 ? round2(roleAmount - each * (n - 1)) : each;
        // D-259: not eligible / above a cap → the company keeps it; nobody else's amount goes up.
        const line = await applyEligibilityAndCaps(orderId, key, roles[key].label, p, share, all[key] || null, elig);
        lines.push(line);
        if (!perPerson.has(p.id)) perPerson.set(p.id, { id: p.id, name: p.name, amount: 0, roles: [] });
        const pp = perPerson.get(p.id);
        pp.amount = round2(pp.amount + line.gets);
        pp.roles.push(roles[key].label);
      }
    }
    byShare = round2(allocated);
    teamTotal = round2(lines.reduce((t, l) => t + l.gets, 0));
  } else if (sum > 0) {
    for (const [key, w] of Object.entries(weights)) roles[key].pct = round2(w / sum * 100);
  }
  const keptNotEligible = round2(lines.reduce((t, l) => t + (l.reason === 'not_eligible' ? l.kept : 0), 0));
  const keptCapped = round2(lines.reduce((t, l) => t + (l.reason !== null && l.reason !== 'not_eligible' ? l.kept : 0), 0));
  return { roles, team_total: teamTotal, unallocated: round2(Math.max(0, amount - byShare)), per_person: [...perPerson.values()], customised,
    lines, team_by_share: byShare, kept_total: round2(keptNotEligible + keptCapped), kept_not_eligible: keptNotEligible, kept_capped: keptCapped };
}

/** open | ready | locked | paid */
async function state(orderId, row = undefined) {
  const r = row === undefined ? await incentiveRepository.forOrder(orderId) : row;
  if (r && r.locked_at) {
    for (const p of await incentiveRepository.payoutsForOrder(orderId)) {
      if (p.status === 'paid') return 'paid';
    }
    return 'locked';
  }
  return (await readyReason(orderId)) === null ? 'ready' : 'open';
}

function todayIst() {
  return new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
}

/** Why the order is not ready to lock yet, or null when it is (buyer fully paid, no claim open). */
async function readyReason(orderId) {
  const order = await orderRepository.find(orderId);
  if (!order || ['cancelled', 'lost'].includes(order.status)) return 'The order is cancelled or lost.';
  if ((await fullyPaidOn(orderId)) === null) return 'The buyer has not paid in full yet.';
  if (await require('./associateCommission').claimOpen(orderId, todayIst())) return 'A buyer claim on this order is still open.';
  return null;
}

/** Order ids ready to lock (fully paid, no open claim, not locked). */
async function readyToLock() {
  const out = [];
  for (const orderId of await incentiveRepository.unlockedOrderIdsWithPayments()) {
    if ((await readyReason(orderId)) === null) out.push(orderId);
  }
  return out;
}

/** Locks the order if it is ready. Resolves null on success, else why not. */
async function lockIfReady(orderId, userId, note = null) {
  const row = await incentiveRepository.forOrder(orderId);
  if (row && row.locked_at) return 'This order\'s incentive is already locked.';
  const reason = await readyReason(orderId);
  if (reason !== null) return reason;
  const calc = await calculate(orderId);
  if (calc.over_profit) {
    return `The team incentive (₹${fmt2(calc.amount)}) is more than the order's profit (₹${fmt2(calc.basis)}) — lower it before locking.`;
  }
  if (!(await lock(orderId, String(await fullyPaidOn(orderId)), userId, calc, note))) return 'This order\'s incentive is already locked.';
  return null;
}

/** Locks every order that is ready. */
async function lockAllReady(userId) {
  let locked = 0;
  const skipped = [];
  for (const orderId of await readyToLock()) {
    const error = await lockIfReady(orderId, userId, 'Locked with "Lock all ready".');
    if (error === null) {
      locked++;
    } else {
      const o = await orderRepository.find(orderId);
      skipped.push(`${(o && (o.buyer_inquiry_ref || o.order_reference)) || orderId}: ${error}`);
    }
  }
  return { locked, skipped };
}

function nowSql() {
  const d = new Date(Date.now() + 5.5 * 3600 * 1000);
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

/** "15% of profit (global rule)", "fixed ₹1,00,000 set for this order" … */
function ruleText(calc) {
  const pricingService = require('./pricingService');
  if (calc.source === 'order_fixed') return `fixed ₹${fmt2(calc.amount)} set for this order`;
  if (calc.source === 'order_pct') return `${pricingService.pct(calc.rule.value)} of profit set for this order`;
  if (calc.source === 'off') return 'program off — none';
  return `${calc.global_text} (global rule)`;
}

/**
 * Locks the order's incentive: writes the payouts and cost entries. Resolves false (and
 * writes nothing) when the order is already locked — fresh-eyes review M1: the order's
 * row is claimed with SELECT … FOR UPDATE inside the transaction, so two lock requests
 * at the same moment ("Lock all ready" in one tab, the order in another) write once.
 */
async function lock(orderId, paidOn, userId, calc = null, note = null) {
  calc = calc || await calculate(orderId);
  const associateCommission = require('./associateCommission');
  const { short } = require('../helpers/dates');
  const today = todayIst();
  // P4: staff are paid in the payout run after the lock date.
  const staffRun = await associateCommission.payoutRunOn(today);
  const month = String(staffRun).slice(0, 7);
  // Owner decision D-245 P3: due N days after full payment, paid in the payout run that follows.
  const commissionSet = await associateCommission.settings();
  const due = calc.associate ? await associateCommission.dueOn(paidOn, commissionSet) : null;
  const runOn = due ? await associateCommission.payoutRunOn(due, commissionSet) : null;
  const a = calc.associate;
  const earned = !!(a && a.state !== 'unpaid' && a.amount_fc > 0);
  const order = await orderRepository.find(orderId);
  const claimed = await db.transaction(async (c) => {
    await c.execute('INSERT IGNORE INTO order_incentives (order_id, updated_by) VALUES (:id, :u)', { id: orderId, u: userId });
    const claim = await c.queryOne('SELECT locked_at FROM order_incentives WHERE order_id = :id FOR UPDATE', { id: orderId });
    // The same guard on the payouts: an order never gets a second set (one per order, payee and role).
    const existing = await c.queryOne('SELECT COUNT(*) AS n FROM incentive_payouts WHERE order_id = :id AND clawback_of_payout_id IS NULL', { id: orderId });
    if ((claim && claim.locked_at) || Number(existing.n) > 0) return false;
    let teamEntryId = null;
    // D-259: what each person gets after eligibility and caps; what the company keeps is recorded per line.
    for (const l of calc.lines || []) {
      if (l.kept > 0) {
        await incentiveRepository.addKept({ order_id: orderId, user_id: l.user_id, role: l.role, by_share_inr: l.by_share, paid_inr: l.gets,
          kept_inr: l.kept, kept_reason: l.reason, kept_note: l.note, incentive_month: month, locked_on: today }, c);
      }
    }
    if (calc.team_total > 0) {
      for (const l of calc.lines || []) {
        if (l.gets > 0) {
          await incentiveRepository.addPayout({ order_id: orderId, payee_kind: 'staff', user_id: l.user_id, role: l.role, amount_inr: l.gets,
            incentive_month: month, payout_run_on: staffRun, locked_on: today }, c);
        }
      }
      teamEntryId = await incentiveRepository.addCostEntry(orderId, 'team', `Team incentive for order ${(order && (order.buyer_inquiry_ref || order.order_reference)) || orderId}`
        + ` — ${ruleText(calc)}, locked ${short(today)}, paid in the ${short(staffRun)} payout run`, calc.team_total, paidOn, userId, c);
    }
    let associateEntryId = null;
    if (earned) {
      const payoutId = await incentiveRepository.addPayout({
        order_id: orderId, payee_kind: 'associate', associate_id: a.id, role: 'associate',
        amount_inr: a.amount_inr, amount_fc: a.amount_fc, currency_code: a.currency, incentive_month: String(runOn).slice(0, 7),
        payout_run_on: runOn, locked_on: today,
      }, c);
      await c.execute('UPDATE incentive_payouts SET due_on = :d WHERE id = :id', { d: due, id: payoutId });
      // AS-3: a suspended associate's commission is held until the MD releases it.
      if (a.status === 'suspended') {
        await incentiveRepository.holdPayout(payoutId, 'The associate is suspended.', c);
      }
      associateEntryId = await incentiveRepository.addCostEntry(orderId, 'associate', associateCostDescription(a), a.amount_inr, paidOn, userId, c);
    }
    await incentiveRepository.saveOrder(orderId, {
      locked_at: nowSql(),
      locked_by: userId,
      unlock_reason: null,
      fully_paid_on: paidOn,
      incentive_month: month,
      profit_basis_inr: calc.basis,
      team_total_inr: calc.team_total,
      team_by_share_inr: calc.team_by_share !== undefined ? calc.team_by_share : calc.team_total,
      kept_not_eligible_inr: calc.kept_not_eligible || 0,
      kept_capped_inr: calc.kept_capped || 0,
      associate_id: a ? a.id : null,
      associate_order_number: a && a.counts ? a.counting_number : null,
      associate_rate_pct: a ? a.rate : null,
      associate_amount_fc: a ? a.amount_fc : null,
      associate_currency: a ? a.currency : null,
      associate_amount_inr: a ? a.amount_inr : null,
      associate_basis_fc: a ? a.basis_fc : null,
      associate_basis_inr: a ? a.basis_inr : null,
      associate_counts: a ? (a.counts ? 1 : 0) : null,
      associate_due_on: earned ? due : null,
      team_cost_entry_id: teamEntryId,
      associate_cost_entry_id: associateEntryId,
    }, userId, c);
    await incentiveRepository.addHistory(orderId, 'lock', null, `Team ₹${fmt2(calc.team_total)}`
      + (a ? `; associate ${earned ? `₹${fmt2(a.amount_inr)}` : 'none'}` : '') + `; staff payout run ${staffRun}`, note, userId, c);
    return true;
  });
  if (!claimed) return false;
  if (a) {
    const associateService = require('./associateService');
    const associate = await associateRepository.find(a.id);
    if (associate && order) {
      if (!earned) {
        await associateService.notify(associate, 'commission_earned', `Order ${order.order_reference} is closed for commission: `
          + (a.state === 'unpaid' ? 'the buyer did not pay the order in full, so no commission is due on it.' : 'no commission is due on it.'), Number(order.client_id), orderId);
      } else {
        await associateService.notify(associate, a.status === 'suspended' ? 'payout_held' : 'commission_earned',
          `Your client paid order ${order.order_reference} in full. Commission earned: ${a.currency} ${fmt2(a.amount_fc)}`
          + ` (${require('./associateCommission').rateLabel(a.level || null)} on the invoiced FOB of the goods, ${a.currency} ${fmt2(a.basis_fc)}).`
          + (a.status === 'suspended' ? ' It is on hold while your account is suspended.'
            : ` It is due on ${short(due)} and paid in the ${short(runOn)} payout run, against your invoice`
              + (String(associate.gstin || '').trim() !== '' ? ' (GST is added on top once your tax invoice is on file)' : '') + '.'),
          Number(order.client_id), orderId);
      }
    }
  }
  return true;
}

/** Owner rule D-212: the commission cost line says what it is, for whom, at which level and on what basis. */
function associateCostDescription(a) {
  return `Associate commission — ${a.name} (${a.level_label}) on the invoiced FOB of the goods, ${a.currency} ${fmt2(a.basis_fc)}`
    + (Number(a.reduction_fc) > 0 ? ` (after agreed discount / settled claim of ${a.currency} ${fmt2(a.reduction_fc)})` : '')
    + (Number(a.in_range_fc) < Number(a.goods_fc) ? '; lines the MD approved below the floor earn nothing' : '');
}

/** PHP rtrim(rtrim(number_format($v, 2), '0'), '.') */
function trimNum(v) {
  return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/0+$/, '').replace(/\.$/, '');
}

/** Unlocks the order so its incentive can change again; needs a reason; refused once anything is paid. Resolves an error or null. */
async function unlock(orderId, userId, reason = '') {
  const { short } = require('../helpers/dates');
  const why = String(reason || '').trim();
  if ([...why].length < 10 || [...why].length > 500) return 'Say why the incentive is unlocked (10 to 500 characters).';
  const row = await incentiveRepository.forOrder(orderId);
  if (!row || !row.locked_at) return 'This order\'s incentive is not locked.';
  for (const p of await incentiveRepository.payoutsForOrder(orderId)) {
    if (p.status === 'paid') {
      return 'Part of this order\'s incentive is already paid, so it can no longer be unlocked.';
    }
  }
  // Fresh-eyes review M1: checked again inside the transaction, with the rows locked, so a
  // payout marked paid at the same moment is never deleted (only unpaid ones are).
  const refused = await db.transaction(async (c) => {
    const relock = await c.queryOne('SELECT locked_at FROM order_incentives WHERE order_id = :id FOR UPDATE', { id: orderId });
    const paidNow = await c.queryOne("SELECT COUNT(*) AS n FROM incentive_payouts WHERE order_id = :id AND status = 'paid' FOR UPDATE", { id: orderId });
    if (!relock || !relock.locked_at) return 'This order\'s incentive is not locked.';
    if (Number(paidNow.n) > 0) return 'Part of this order\'s incentive is already paid, so it can no longer be unlocked.';
    await incentiveRepository.deleteOrderPayouts(orderId, c);
    await incentiveRepository.deleteOrderKept(orderId, c);
    await c.execute('DELETE FROM order_cost_entries WHERE order_id = :id AND incentive_kind IS NOT NULL', { id: orderId });
    await incentiveRepository.saveOrder(orderId, {
      locked_at: null, locked_by: null, unlock_reason: why, fully_paid_on: null, incentive_month: null, profit_basis_inr: null, team_total_inr: null,
      team_by_share_inr: null, kept_not_eligible_inr: null, kept_capped_inr: null,
      associate_id: null, associate_order_number: null, associate_rate_pct: null,
      associate_amount_fc: null, associate_currency: null, associate_amount_inr: null,
      associate_basis_fc: null, associate_basis_inr: null, associate_counts: null, associate_due_on: null,
      team_cost_entry_id: null, associate_cost_entry_id: null,
    }, userId, c);
    await incentiveRepository.addHistory(orderId, 'unlock', `Locked ${short(String(row.locked_at).slice(0, 10))}, team ₹${fmt2(row.team_total_inr)}`, 'Open', why, userId, c);
    return null;
  });
  return refused;
}

/**
 * Daily: N days (setting) before the next payout run, remind the MD of orders
 * ready to lock but not locked. Resolves the reminders sent.
 */
async function lockReminders(today = null) {
  const associateCommission = require('./associateCommission');
  const notificationRepository = require('../repositories/notificationRepository');
  const { short } = require('../helpers/dates');
  const day = today || todayIst();
  const days = (await settings()).reminder_days;
  const run = await associateCommission.payoutRunOn(day);
  const remindOn = new Date(`${run}T00:00:00Z`);
  remindOn.setUTCDate(remindOn.getUTCDate() - days);
  if (days <= 0 || remindOn.toISOString().slice(0, 10) !== day) return 0;
  const raw = await incentiveRepository.settings();
  if ((raw.lock_reminder_sent_for || '') === run) return 0;
  const ready = await readyToLock();
  if (ready.length === 0) return 0;
  const users = await db.query(
    `SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id
      WHERE p.permission_key = 'manage_pricing' AND u.is_active = 1`
  );
  let sent = 0;
  for (const u of users) {
    await notificationRepository.createInAppOnly(Number(u.id), 'incentive_lock_reminder', null,
      `${ready.length} order(s) are ready to lock before the ${short(run)} payout run (Team incentives › Ready to lock) — lock them so the team is paid in that run.`);
    sent++;
  }
  if (users.length) await incentiveRepository.setSetting('lock_reminder_sent_for', run, Number(users[0].id));
  return sent;
}

/**
 * Records a payout as paid. When every payout of its kind on the order is
 * paid, the order's cost entry takes the amounts actually paid and goes to
 * Zoho Books with the next sync.
 */
/**
 * @returns {Promise<boolean>} whether this call recorded the payment — false
 * when the payout was already paid, e.g. by a second click a moment earlier
 * (QA Round 6, R6-N17), so the caller logs it once.
 */
async function markPaid(payoutId, paidOn, paidInr, tdsInr, userId, paidVia = null, paidReference = null) {
  const payout = await incentiveRepository.findPayout(payoutId);
  if (!payout || payout.status !== 'due') {
    return false;
  }
  if (!(await incentiveRepository.markPayoutPaid(payoutId, paidOn, paidInr, tdsInr, userId, paidVia, paidReference))) {
    return false;
  }
  const kind = payout.payee_kind === 'staff' ? 'team' : 'associate';
  const rows = (await incentiveRepository.payoutsForOrder(Number(payout.order_id))).filter((p) => p.payee_kind === payout.payee_kind);
  if (rows.some((p) => p.status !== 'paid')) {
    return true;
  }
  const entry = (await incentiveRepository.costEntries(Number(payout.order_id)))[kind];
  if (entry) {
    // Owner decision 2 Nov 2026 (D-235): an associate's cost is the gross commission — the TDS kept back is still part of it.
    await incentiveRepository.settleCostEntry(Number(entry.id), round2(rows.reduce((sum, p) => sum + Number(p.paid_inr) + (payout.payee_kind === 'associate' ? Number(p.tds_inr || 0) : 0), 0)),
      rows.map((p) => String(p.paid_on).slice(0, 10)).sort().pop(), paidNote(rows));
  }
  return true;
}

/**
 * Owner rule D-212: how the payouts of one order and kind were paid, for
 * its cost line — "with the salary (payroll), ref Nov-2026 payroll" — each
 * distinct way and reference once; null when none was recorded. Port of
 * IncentiveService::paidNote().
 */
function paidNote(rows) {
  const parts = new Set();
  for (const p of rows) {
    if (!p.paid_via && !p.paid_reference) continue;
    const part = `${incentiveRepository.PAID_VIA[p.paid_via || ''] || ''}${p.paid_reference ? `, ref ${p.paid_reference}` : ''}`.replace(/^[ ,]+|[ ,]+$/g, '');
    parts.add(part);
  }
  return parts.size ? [...parts].join('; ') : null;
}

module.exports = {
  productRate,
  associateCostDescription,
  round2,
  fmt2,
  settings,
  incentiveMonth,
  isMonth,
  earliestPaidOn,
  monthLabel,
  monthName,
  fullyPaidOn,
  isClientsFirstOrder,
  withinFirstTenDays,
  poDate,
  peopleFor,
  calculate,
  orderRule,
  globalAmount,
  split,
  eligibility,
  estimateMonth,
  isMd,
  designationEligibility,
  isEligible,
  eligibilityOverrides,
  setEligible,
  applyEligibilityAndCaps,
  state,
  readyReason,
  readyToLock,
  lockIfReady,
  lockAllReady,
  ruleText,
  lockReminders,
  lock,
  unlock,
  markPaid,
};
