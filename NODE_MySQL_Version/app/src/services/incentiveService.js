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
 * commission (owner decision 2026-10-30, mockups approved).
 *
 * - An order's figures are fixed ("locked") when the buyer has fully paid:
 *   every buyer payment it has (advance or LC, balance, freight) cleared.
 * - Associate commission: the client's associate, on the client's first
 *   orders (Day 1 = the client's first PO received, plus 9 days: the
 *   agreement's 10 days), at the ladder rate for the associate's Nth client, on the
 *   product (FOB) value. It is deducted from the profit first.
 * - Team reward: the amount set on the order (above 0), else the reward
 *   table for the profit left after the associate, if it reaches the
 *   minimum and the program is on. Plus the new client bonus on a client's
 *   first order. Split by role, equally between the people in a role.
 * - Both become the order's cost (a commission cost entry) when locked,
 *   and are paid in the incentive month: cut-off day C means the 21st of
 *   last month to the 20th of this month for C = 20.
 */

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** PHP round(x, 2). */
function round2(x) {
  return Math.round((Number(x) + Number.EPSILON) * 100) / 100;
}

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
  let table = [];
  try {
    table = JSON.parse(raw.reward_table || '[]');
  } catch (e) {
    table = [];
  }
  table = (Array.isArray(table) ? table : [])
    .filter((row) => row && Number(row.from || 0) > 0 && Number(row.reward || 0) > 0)
    .map((row) => ({ from: Number(row.from), reward: Number(row.reward) }))
    .sort((a, b) => a.from - b.from);
  let savedSplit = {};
  try {
    savedSplit = JSON.parse(raw.role_split || '{}') || {};
  } catch (e) {
    savedSplit = {};
  }
  const split = {};
  for (const role of Object.keys(incentiveRepository.ROLES)) {
    split[role] = Number(savedSplit[role] || 0);
  }
  const cutoff = String(raw.cutoff_day ?? '20');
  return {
    enabled: (raw.program_enabled ?? '0') === '1',
    start_date: String(raw.program_start_date ?? ''),
    min_profit: Number(raw.min_profit_inr || 0),
    table,
    bonus: Number(raw.new_client_bonus_inr || 0),
    split,
    cutoff: cutoff === 'last' ? 'last' : String(Math.max(1, Math.min(28, parseInt(cutoff, 10) || 0))),
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

function incentiveMonth(date, cutoff) {
  const [y, m, d] = String(date).slice(0, 10).split('-').map(Number);
  if (cutoff === 'last' || d <= parseInt(cutoff, 10)) {
    return `${y}-${pad(m)}`;
  }
  return m === 12 ? `${y + 1}-01` : `${y}-${pad(m + 1)}`;
}

/** "21 Oct to 20 Nov" for the incentive month. */
function cycleLabel(month, cutoff) {
  const [y, m] = month.split('-').map(Number);
  const short = (mm) => MONTHS[mm - 1].slice(0, 3);
  if (cutoff === 'last') {
    return `1 ${short(m)} to ${lastDayOf(y, m)} ${short(m)}`;
  }
  const prev = m === 1 ? 12 : m - 1;
  return `${parseInt(cutoff, 10) + 1} ${short(prev)} to ${parseInt(cutoff, 10)} ${short(m)}`;
}

/** PHP date('S'): the English ordinal suffix of a day number. */
function ordinal(n) {
  const v = n % 100;
  if (v >= 11 && v <= 13) return `${n}th`;
  return `${n}${({ 1: 'st', 2: 'nd', 3: 'rd' })[n % 10] || 'th'}`;
}

/** "the 21st of last month to the 20th of this month" for the settings and My Incentives pages. */
function cycleSentence(cutoff) {
  if (cutoff === 'last') {
    return null;
  }
  const c = parseInt(cutoff, 10);
  return `the ${ordinal(c + 1)} of last month to the ${ordinal(c)} of this month`;
}

/** "November" for 2026-11. */
function monthName(month) {
  return MONTHS[Number(String(month).split('-')[1]) - 1];
}

/** "30 Nov": the last day of the incentive month, when it is paid with the salary. */
function payDay(month) {
  const [y, m] = String(month).split('-').map(Number);
  return `${lastDayOf(y, m)} ${MONTHS[m - 1].slice(0, 3)}`;
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
    inr += Number(lc.lc_inr_credited);
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
    for (const role of Object.keys(incentiveRepository.ROLES)) {
      const holders = Object.keys(rolesByUser).map(Number).filter((uid) => rolesByUser[uid].includes(role)).sort((a, b) => a - b);
      const onOrder = holders.filter((uid) => assigned.includes(uid));
      chosen[role] = onOrder.length ? onOrder : holders;
    }
  }
  const out = {};
  for (const role of Object.keys(incentiveRepository.ROLES)) {
    out[role] = [];
    for (const userId of chosen[role] || []) {
      if (names[userId] !== undefined) {
        out[role].push({ id: userId, name: names[userId] });
      }
    }
  }
  return out;
}

/** The order's incentive as it stands (or would stand, if the buyer had fully paid on paidOn). */
async function calculate(orderId, paidOn = null) {
  const orderProfitabilityService = require('./orderProfitabilityService');
  const order = await orderRepository.find(orderId);
  const s = await settings();
  const row = (await incentiveRepository.forOrder(orderId)) || { reward_override_inr: 0, is_excluded: 0, associate_mode: 'auto', locked_at: null };
  const profit = await orderProfitabilityService.computeForOrder(orderId);
  const entries = await incentiveRepository.costEntries(orderId);
  const already = Object.values(entries).reduce((sum, c) => sum + Number(c.amount_inr), 0);
  const profitBefore = round2(Number(profit.profit_inr) + already);

  let associate = null;
  const client = await clientRepository.find(Number(order.client_id));
  if (client && client.associate_id && row.associate_mode !== 'no'
    && (row.associate_mode === 'yes' || await withinFirstTenDays(order))) {
    const a = await associateRepository.find(Number(client.associate_id));
    if (a) {
      const number = row.associate_order_number ? Number(row.associate_order_number) : await associateRepository.orderNumberForClient(Number(a.id), Number(order.client_id));
      const rate = associateRepository.rateFor(a, number);
      const fc = round2((await orderProductRepository.totalFobValue(orderId)) * rate / 100);
      associate = {
        id: Number(a.id),
        name: String(a.name),
        order_number: number,
        rate,
        amount_fc: fc,
        currency: String(order.currency_code || 'INR'),
        amount_inr: round2(fc * (await productRate(order))),
      };
    }
  }
  const basis = round2(profitBefore - (associate ? associate.amount_inr : 0));

  let source = 'table';
  let reward = 0;
  if (Number(row.is_excluded) === 1) {
    source = 'excluded';
  } else if (Number(row.reward_override_inr) > 0) {
    source = 'override';
    reward = Number(row.reward_override_inr);
  } else if (!s.enabled) {
    source = 'off';
  } else if (paidOn !== null && s.start_date !== '' && paidOn < s.start_date) {
    source = 'before_start';
  } else if (basis < s.min_profit) {
    source = 'below_min';
  } else {
    for (const t of s.table) {
      if (basis >= t.from) {
        reward = t.reward;
      }
    }
    if (reward <= 0) {
      source = 'below_min';
    }
  }
  const bonus = (reward > 0 && s.bonus > 0 && await isClientsFirstOrder(order)) ? s.bonus : 0;

  const people = await peopleFor(orderId);
  const roles = {};
  const bonusRoles = ['sales', 'marketing'].filter((r) => people[r].length > 0 && s.split[r] > 0);
  const bonusWeight = bonusRoles.reduce((sum, r) => sum + s.split[r], 0);
  let allocated = 0;
  let unallocated = 0;
  for (const [role, label] of Object.entries(incentiveRepository.ROLES)) {
    let amount = round2(reward * s.split[role] / 100);
    if (bonusRoles.includes(role) && bonusWeight > 0) {
      amount = round2(amount + bonus * s.split[role] / bonusWeight);
    }
    const each = people[role].length ? round2(amount / people[role].length) : 0;
    if (people[role].length === 0) {
      unallocated += amount;
    } else {
      allocated += each * people[role].length;
    }
    roles[role] = { label, pct: s.split[role], amount, each, people: people[role] };
  }
  if (bonus > 0 && bonusRoles.length === 0) {
    unallocated += bonus;
  }

  return {
    profit_before: profitBefore,
    associate,
    basis,
    source,
    reward,
    new_client_bonus: bonus,
    team_total: round2(allocated),
    unallocated: round2(unallocated),
    roles,
    pct_of_profit: basis > 0 ? Math.round(allocated / basis * 1000) / 10 : null,
    min_profit: s.min_profit,
  };
}

function nowSql() {
  const d = new Date(Date.now() + 5.5 * 3600 * 1000);
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

async function lock(orderId, paidOn, userId) {
  const calc = await calculate(orderId, paidOn);
  const s = await settings();
  const month = incentiveMonth(paidOn, s.cutoff);
  await db.transaction(async (c) => {
    let teamEntryId = null;
    if (calc.team_total > 0) {
      for (const [role, r] of Object.entries(calc.roles)) {
        for (const p of r.people) {
          if (r.each > 0) {
            await incentiveRepository.addPayout({ order_id: orderId, payee_kind: 'staff', user_id: p.id, role, amount_inr: r.each, incentive_month: month }, c);
          }
        }
      }
      teamEntryId = await incentiveRepository.addCostEntry(orderId, 'team', `Team incentive — ${monthLabel(month)} incentive`, calc.team_total, paidOn, userId, c);
    }
    let associateEntryId = null;
    const a = calc.associate;
    if (a && a.amount_fc > 0) {
      await incentiveRepository.addPayout({
        order_id: orderId, payee_kind: 'associate', associate_id: a.id, role: 'associate',
        amount_inr: a.amount_inr, amount_fc: a.amount_fc, currency_code: a.currency, incentive_month: month,
      }, c);
      associateEntryId = await incentiveRepository.addCostEntry(orderId, 'associate',
        `Associate commission — ${a.name} (${a.rate.toFixed(2)}%, client no. ${a.order_number})`, a.amount_inr, paidOn, userId, c);
    }
    await incentiveRepository.saveOrder(orderId, {
      locked_at: nowSql(),
      fully_paid_on: paidOn,
      incentive_month: month,
      profit_basis_inr: calc.basis,
      team_total_inr: calc.team_total,
      new_client_bonus_inr: calc.new_client_bonus,
      associate_id: a ? a.id : null,
      associate_order_number: a ? a.order_number : null,
      associate_rate_pct: a ? a.rate : null,
      associate_amount_fc: a ? a.amount_fc : null,
      associate_currency: a ? a.currency : null,
      associate_amount_inr: a ? a.amount_inr : null,
      team_cost_entry_id: teamEntryId,
      associate_cost_entry_id: associateEntryId,
    }, userId, c);
  });
}

/** Fixes the incentive of every order whose buyer has fully paid since the last look. */
async function lockDue(userId) {
  let locked = 0;
  for (const orderId of await incentiveRepository.unlockedOrderIdsWithPayments()) {
    const paidOn = await fullyPaidOn(orderId);
    if (paidOn !== null) {
      await lock(orderId, paidOn, userId);
      locked++;
    }
  }
  return locked;
}

/** Fixes this one order's incentive if its buyer has fully paid and it is not fixed yet. */
async function lockIfDue(orderId, userId) {
  const row = await incentiveRepository.forOrder(orderId);
  if (row && row.locked_at) {
    return;
  }
  const order = await orderRepository.find(orderId);
  if (!order || Number(order.is_test_data) === 1 || Number(order.is_sample_data) === 1 || ['cancelled', 'lost'].includes(order.status)) {
    return;
  }
  const paidOn = await fullyPaidOn(orderId);
  if (paidOn !== null) {
    await lock(orderId, paidOn, userId);
  }
}

/** Undoes a lock so the incentive can be worked out again; refused once anything is paid. Returns an error or null. */
async function unlock(orderId, userId) {
  for (const p of await incentiveRepository.payoutsForOrder(orderId)) {
    if (p.status === 'paid') {
      return 'Part of this order\'s incentive is already paid, so its figures can no longer be worked out again.';
    }
  }
  await db.transaction(async (c) => {
    await incentiveRepository.deleteOrderPayouts(orderId, c);
    await c.execute('DELETE FROM order_cost_entries WHERE order_id = :id AND incentive_kind IS NOT NULL', { id: orderId });
    await incentiveRepository.saveOrder(orderId, {
      locked_at: null, fully_paid_on: null, incentive_month: null, profit_basis_inr: null, team_total_inr: null,
      new_client_bonus_inr: null, associate_id: null, associate_order_number: null, associate_rate_pct: null,
      associate_amount_fc: null, associate_currency: null, associate_amount_inr: null,
      team_cost_entry_id: null, associate_cost_entry_id: null,
    }, userId, c);
  });
  return null;
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
async function markPaid(payoutId, paidOn, paidInr, tdsInr, userId) {
  const payout = await incentiveRepository.findPayout(payoutId);
  if (!payout || payout.status !== 'due') {
    return false;
  }
  if (!(await incentiveRepository.markPayoutPaid(payoutId, paidOn, paidInr, tdsInr, userId))) {
    return false;
  }
  const kind = payout.payee_kind === 'staff' ? 'team' : 'associate';
  const rows = (await incentiveRepository.payoutsForOrder(Number(payout.order_id))).filter((p) => p.payee_kind === payout.payee_kind);
  if (rows.some((p) => p.status !== 'paid')) {
    return true;
  }
  const entry = (await incentiveRepository.costEntries(Number(payout.order_id)))[kind];
  if (entry) {
    await incentiveRepository.settleCostEntry(Number(entry.id), round2(rows.reduce((sum, p) => sum + Number(p.paid_inr), 0)),
      rows.map((p) => String(p.paid_on).slice(0, 10)).sort().pop());
  }
  return true;
}

module.exports = {
  round2,
  fmt2,
  settings,
  incentiveMonth,
  isMonth,
  earliestPaidOn,
  cycleLabel,
  monthLabel,
  monthName,
  payDay,
  cycleSentence,
  fullyPaidOn,
  isClientsFirstOrder,
  withinFirstTenDays,
  poDate,
  peopleFor,
  calculate,
  lock,
  lockDue,
  lockIfDue,
  unlock,
  markPaid,
};
