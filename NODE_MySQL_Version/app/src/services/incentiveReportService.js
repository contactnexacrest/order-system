'use strict';

/**
 * Owner decision D-245 P5: the incentive & commission reports — one row per order × payee
 * (a staff member's share or an associate's commission). Twin of
 * PHP App\Services\IncentiveReportService.
 *
 * - Locked orders: the payouts written when the MD locked the order (locked / held / paid,
 *   with due date and payout run).
 * - Open orders (not locked, not cancelled or lost, with a Proforma Invoice or a buyer
 *   payment): the ESTIMATED shares from the same calculation the order page shows —
 *   state "estimated", or "ready" once the buyer has paid in full and no claim is open.
 *
 * Every total is the plain sum of its rows (tested).
 */
const db = require('../config/db');
const incentiveRepository = require('../repositories/incentiveRepository');
const orderRepository = require('../repositories/orderRepository');
const testModeService = require('./testModeService');

const STATES = {
  estimated: 'Estimated (not locked)',
  ready: 'Ready to lock',
  locked: 'Locked, not paid',
  held: 'Held',
  paid: 'Paid',
};

const HEADERS = ['Order', 'Client', 'Order date', 'Payee', 'Payee type', 'Role', 'Amount (INR)', 'GST (INR)', 'TDS (INR)', 'State', 'Due on', 'Payout run', 'Paid on'];

const { round2 } = require('../helpers/money'); // PHP round(x, 2) (fresh-eyes review M3)

function ymd(v) {
  if (v === null || v === undefined || v === '') return null;
  if (v instanceof Date) {
    const p = (n) => String(n).padStart(2, '0');
    return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())}`;
  }
  return String(v).slice(0, 10);
}

function today() {
  return ymd(new Date());
}

async function testFlag() {
  return (await testModeService.isEnabled()) ? 1 : 0;
}

async function lockedRows() {
  const associateCommission = require('./associateCommission');
  await incentiveRepository.allRoles();
  const rows = await db.query(
    `SELECT p.*, o.order_reference, o.buyer_inquiry_ref, o.company_legal_name, o.created_at AS order_created_at,
            u.name AS user_name, a.name AS associate_name
     FROM incentive_payouts p
     JOIN orders o ON o.id = p.order_id
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN associates a ON a.id = p.associate_id
     WHERE o.is_test_data = :t AND o.is_sample_data = 0${require('./divisionService').sql('o.division_id')}
     ORDER BY o.id, p.payee_kind DESC, p.id`, // D-257 (fresh-eyes review L1): only the orders of the request's division(s)
    { t: await testFlag() }
  );
  const out = [];
  for (const p of rows) {
    const associate = p.payee_kind === 'associate';
    const state = p.status === 'paid' ? 'paid' : (p.status === 'held' ? 'held' : 'locked');
    const gst = p.status === 'paid' ? Number(p.gst_inr || 0) : await associateCommission.gstFor(p);
    out.push({
      order_id: Number(p.order_id),
      order_reference: String(p.buyer_inquiry_ref || p.order_reference),
      client: String(p.company_legal_name),
      order_date: ymd(p.order_created_at),
      payee_kind: associate ? 'associate' : 'staff',
      user_id: p.user_id !== null ? Number(p.user_id) : null,
      associate_id: p.associate_id !== null ? Number(p.associate_id) : null,
      payee: String(associate ? (p.associate_name || 'Associate') : (p.user_name || 'Staff')),
      role: associate ? 'Associate commission' : incentiveRepository.roleLabel(String(p.role)),
      amount_inr: round2(p.amount_inr),
      gst_inr: round2(gst),
      tds_inr: p.tds_inr !== null ? round2(p.tds_inr) : 0,
      state,
      due_on: ymd(p.due_on),
      payout_run_on: ymd(p.payout_run_on),
      locked_on: ymd(p.locked_on),
      paid_on: ymd(p.paid_on),
    });
  }
  return out;
}

/** D-245 P6: rows per page on the register (totals are always over the whole filtered set). */
const PER_PAGE = 100;

/**
 * Open orders that carry an estimate: not locked, not cancelled/lost, with a PI or a buyer payment.
 * f: the register filters — only open orders that can match them are estimated (order date from/to,
 * associate, order ids); D-245 P6: the register no longer works out every open order.
 */
async function openOrderIds(f = {}) {
  if (['locked', 'held', 'paid'].includes(String(f.state || ''))) return [];
  // D-257 (fresh-eyes review L1): only the orders of the request's division(s).
  let where = require('./divisionService').sql('o.division_id');
  const params = { t: await testFlag() };
  if (f.from) { where += ' AND DATE(o.created_at) >= :from'; params.from = String(f.from); }
  if (f.to) { where += ' AND DATE(o.created_at) <= :to'; params.to = String(f.to); }
  if (Number(f.associate_id || 0) > 0) {
    where += ' AND EXISTS (SELECT 1 FROM clients c WHERE c.id = o.client_id AND c.associate_id = :aid)';
    params.aid = Number(f.associate_id);
  }
  if (Array.isArray(f.order_ids) && f.order_ids.length > 0) {
    const ids = f.order_ids.map((v) => parseInt(v, 10) || 0);
    where += ` AND o.id IN (${ids.join(',')})`;
  }
  const rows = await db.query(
    `SELECT o.id FROM orders o
     LEFT JOIN order_incentives oi ON oi.order_id = o.id
     LEFT JOIN order_payment_status ps ON ps.order_id = o.id
     WHERE oi.locked_at IS NULL AND o.is_test_data = :t AND o.is_sample_data = 0
       AND o.status NOT IN ('cancelled','lost')
       AND (ps.advance_cleared_at IS NOT NULL OR ps.balance_cleared_at IS NOT NULL OR ps.freight_cleared_at IS NOT NULL
            OR EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE d.order_id = o.id AND dt.code = 'PI'))${where}
     ORDER BY o.id`,
    params
  );
  return rows.map((r) => Number(r.id));
}

/** Estimated rows of one open order. */
async function estimatedRows(orderId) {
  const incentiveService = require('./incentiveService');
  const associateCommission = require('./associateCommission');
  const order = await orderRepository.find(orderId);
  if (!order) return [];
  const calc = await incentiveService.calculate(orderId);
  // calc.state is 'ready' exactly when readyReason() is null for an unlocked order (D-245 P5: not asked twice).
  const ready = calc.state === 'ready';
  const state = ready ? 'ready' : 'estimated';
  const staffRun = ready ? await associateCommission.payoutRunOn(today()) : null;
  const base = {
    order_id: Number(orderId),
    order_reference: String(order.buyer_inquiry_ref || order.order_reference),
    client: String(order.company_legal_name),
    order_date: ymd(order.created_at),
    gst_inr: 0, tds_inr: 0, state, locked_on: null, paid_on: null,
  };
  const rows = [];
  for (const p of calc.per_person) {
    if (Number(p.amount) <= 0) continue;
    rows.push({ ...base, payee_kind: 'staff', user_id: Number(p.id), associate_id: null, payee: String(p.name),
      role: [...new Set(p.roles)].join(', '), amount_inr: round2(p.amount), due_on: null, payout_run_on: staffRun });
  }
  const a = calc.associate;
  if (a && a.state !== 'unpaid' && Number(a.amount_inr) > 0) {
    rows.push({ ...base, payee_kind: 'associate', user_id: null, associate_id: Number(a.id), payee: String(a.name),
      role: 'Associate commission', amount_inr: round2(a.amount_inr), due_on: ymd(a.due_on), payout_run_on: ymd(a.payout_run_on) });
  }
  return rows;
}

/**
 * The estimated rows of many open orders, in their order (D-245 P5, performance): settings are read
 * once for the run (batchCache) and a few orders are worked out at a time on the connection pool.
 */
async function estimatedRowsFor(ids) {
  return require('../helpers/batchCache').within(async () => {
    const out = [];
    const CHUNK = 6;
    for (let i = 0; i < ids.length; i += CHUNK) {
      const parts = await Promise.all(ids.slice(i, i + CHUNK).map((id) => estimatedRows(id)));
      for (const part of parts) out.push(...part);
    }
    return out;
  });
}

/** Locked rows and estimated rows (before filtering); f limits which open orders are estimated. */
async function allRows(f = {}) {
  const rows = await lockedRows();
  rows.push(...await estimatedRowsFor(await openOrderIds(f)));
  return rows;
}

/** One page of the filtered rows (D-245 P6). Totals stay over the whole set. */
function page(rows, pageNo, perPage = PER_PAGE) {
  const pages = Math.max(1, Math.ceil(rows.length / perPage));
  const p = Math.min(Math.max(1, Number(pageNo) || 1), pages);
  return { rows: rows.slice((p - 1) * perPage, p * perPage), page: p, pages };
}

/** People and associates to choose from — without working out any estimate. */
async function payeeOptions() {
  const people = (await db.query(
    `SELECT u.id, u.name FROM users u WHERE u.id IN (SELECT user_id FROM user_incentive_roles)
       OR u.id IN (SELECT user_id FROM incentive_payouts WHERE user_id IS NOT NULL) OR u.id IN (SELECT user_id FROM order_incentive_people) ORDER BY u.name`
  )).map((r) => ({ id: Number(r.id), name: String(r.name) }));
  const associates = (await db.query('SELECT id, name FROM associates ORDER BY name')).map((r) => ({ id: Number(r.id), name: String(r.name) }));
  return { people, associates };
}

/** Filters: state, month (YYYY-MM of the payout run), user_id, associate_id, from / to (order date). */
function filter(rows, f = {}) {
  const state = String(f.state || '');
  const month = String(f.month || '');
  const userId = Number(f.user_id || 0);
  const associateId = Number(f.associate_id || 0);
  const from = String(f.from || '');
  const to = String(f.to || '');
  const orderIds = Array.isArray(f.order_ids) ? f.order_ids.map(Number) : [];
  const out = rows.filter((r) => (orderIds.length === 0 || orderIds.includes(r.order_id))
    && (state === '' || r.state === state)
    && (month === '' || String(r.payout_run_on || '').slice(0, 7) === month)
    && (userId === 0 || r.user_id === userId)
    && (associateId === 0 || r.associate_id === associateId)
    && (from === '' || r.order_date >= from)
    && (to === '' || r.order_date <= to));
  const key = (r) => [r.order_id, r.payee_kind === 'associate' ? 1 : 0, r.payee];
  out.sort((a, b) => {
    const ka = key(a); const kb = key(b);
    for (let i = 0; i < ka.length; i++) {
      if (ka[i] < kb[i]) return -1;
      if (ka[i] > kb[i]) return 1;
    }
    return 0;
  });
  return out;
}

async function rows(f = {}) {
  return filter(await allRows(f), f);
}

function totals(list) {
  const t = { amount_inr: 0, gst_inr: 0, tds_inr: 0, count: list.length };
  for (const r of list) {
    t.amount_inr += Number(r.amount_inr);
    t.gst_inr += Number(r.gst_inr);
    t.tds_inr += Number(r.tds_inr);
  }
  return { amount_inr: round2(t.amount_inr), gst_inr: round2(t.gst_inr), tds_inr: round2(t.tds_inr), count: t.count };
}

/** "To pay": this month's payout run, next month's, and not locked yet (estimated). payeeKind: '' | staff | associate. */
async function toPay(payeeKind = '', on = null, f = {}) {
  const incentiveService = require('./incentiveService');
  const d = on || today();
  const thisMonth = d.slice(0, 7);
  const [y, m] = thisMonth.split('-').map(Number);
  const nextMonth = m === 12 ? `${y + 1}-01` : `${y}-${String(m + 1).padStart(2, '0')}`;
  const all = (await rows(f)).filter((r) => payeeKind === '' || r.payee_kind === payeeKind);
  const groups = {
    this: { label: `This month's payout run (${incentiveService.monthLabel(thisMonth)})`, rows: [] },
    next: { label: `Next month's payout run (${incentiveService.monthLabel(nextMonth)})`, rows: [] },
    open: { label: 'Not locked yet (estimated)', rows: [] },
  };
  for (const r of all) {
    if (r.state === 'paid') continue;
    const run = String(r.payout_run_on || '').slice(0, 7);
    const lockedish = ['locked', 'held'].includes(r.state);
    if (lockedish && run !== '' && run <= thisMonth) groups.this.rows.push(r);
    else if (lockedish && run === nextMonth) groups.next.rows.push(r);
    else if (['estimated', 'ready'].includes(r.state)) groups.open.rows.push(r);
  }
  for (const g of Object.values(groups)) g.totals = totals(g.rows);
  return groups;
}

/** Owed at a date: locked on or before it and not paid by it; for today or later also orders ready to lock. */
async function owedAt(date, f = {}) {
  const out = [];
  const orderIds = Array.isArray(f.order_ids) ? f.order_ids.map(Number) : [];
  for (const r of await lockedRows()) {
    if (orderIds.length > 0 && !orderIds.includes(r.order_id)) continue;
    const lockedBy = r.locked_on === null || r.locked_on <= date;
    const unpaidThen = r.paid_on === null || r.paid_on > date;
    if (lockedBy && unpaidThen && Number(r.amount_inr) > 0) {
      out.push({ ...r, state: r.state === 'paid' ? 'locked' : r.state });
    }
  }
  if (date >= today()) {
    for (const r of await estimatedRowsFor(await openOrderIds(f))) if (r.state === 'ready') out.push(r);
  }
  return out;
}

async function statement(userId, associateId) {
  if (!userId && !associateId) return [];
  return rows({ user_id: userId || 0, associate_id: associateId || 0 });
}

/** Exactly the on-screen columns. */
function csvRows(list) {
  return list.map((r) => ({
    Order: r.order_reference,
    Client: r.client,
    'Order date': r.order_date,
    Payee: r.payee,
    'Payee type': r.payee_kind === 'associate' ? 'Associate' : 'Staff',
    Role: r.role,
    'Amount (INR)': Number(r.amount_inr).toFixed(2),
    'GST (INR)': Number(r.gst_inr).toFixed(2),
    'TDS (INR)': Number(r.tds_inr).toFixed(2),
    State: STATES[r.state] || r.state,
    'Due on': r.due_on || '',
    'Payout run': r.payout_run_on || '',
    'Paid on': r.paid_on || '',
  }));
}

module.exports = { STATES, HEADERS, PER_PAGE, openOrderIds, estimatedRows, allRows, page, payeeOptions, filter, rows, totals, toPay, owedAt, statement, csvRows };
