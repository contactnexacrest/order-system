'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');
const orderRepository = require('../repositories/orderRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const caFinancialSnapshotService = require('./caFinancialSnapshotService');
const orderLedgerService = require('./orderLedgerService');
const orderProfitabilityService = require('./orderProfitabilityService');

/**
 * Port of CafinExportService.php. CAFIN as CSV for many orders (owner
 * request 2026-10-30): the internal CA Financial Annexure is a snapshot of
 * the complete order, so it can also be taken out for every order at once —
 * never for the client. Two files:
 *
 * - summary: one row per order with the key figures (the same figures the
 *   CAFIN's expenses summary, profit and cash position show);
 * - detail: every CAFIN line of every order, with the order and the section.
 *
 * Both come from caFinancialSnapshotService / orderProfitabilityService, the
 * same calculation as the PDF, so the CSV always agrees with it. Test and
 * sample orders are never included.
 */

const STATUSES = {
  all: 'All orders',
  active: 'In progress (incl. documents sent)',
  complete: 'Fully settled',
  lost: 'Lost or cancelled',
};

/** The order ids for the financial year (by order date) and status, oldest first. */
async function orderIds(fy, status) {
  const { start, end } = financialYear.bounds(fy);
  const statusSql = {
    active: "AND o.status IN ('active','shipped_docs_sent','disputed')",
    complete: "AND o.status = 'complete'",
    lost: "AND o.status IN ('lost','cancelled')",
  }[status] || '';
  const rows = await db.query(
    `SELECT o.id FROM orders o
     WHERE o.is_test_data = 0 AND o.is_sample_data = 0
       AND DATE(o.created_at) BETWEEN :f AND :t ${statusSql}
     ORDER BY o.created_at, o.id`,
    { f: start, t: end }
  );
  return rows.map((r) => Number(r.id));
}

/** The financial years that have real orders, newest first. */
async function financialYears() {
  const rows = await db.query('SELECT created_at FROM orders WHERE is_test_data = 0 AND is_sample_data = 0');
  const years = financialYear.labelsPresentIn(rows.map((r) => r.created_at));
  if (!years.includes(financialYear.current())) years.push(financialYear.current());
  return years.sort().reverse();
}

const SUMMARY_HEADERS = [
  'Order reference', 'Buyer enquiry ref', 'Client', 'Destination country', 'Status', 'Order date', 'Currency', 'FOB value',
  'Revenue (INR)', 'Revenue estimated', 'Govt export benefits (INR)', 'Total income (INR)', 'Supplier cost (INR)',
  'C1 Freight & insurance (INR)', 'C2 Logistics partner bills (INR)', 'C3 Other order costs (INR)', 'of which team incentive (INR)',
  'of which associate commission (INR)', 'C4 Order expenses (INR)', 'C5 Expenses booked in Zoho Books (INR)', 'Total expenses excl. supplier (INR)',
  'Total cost (INR)', 'Order profit (INR)', 'Margin %',
  'Cash: received from buyer (INR)', 'Cash: benefits received (INR)', 'Cash: paid to supplier (INR)', 'Cash: paid to logistics (INR)',
  'Cash: expenses paid (INR)', 'Cash: net so far (INR)',
];

function num(v) {
  return (Math.round((Number(v) || 0) * 100) / 100).toFixed(2);
}

/** "₹1,23,456.00" / "−₹5.00" / "—" → "123456.00" / "-5.00" / "" — numbers Excel can add up. */
function plain(formatted) {
  const original = String(formatted ?? '');
  const t = original.replace(/[₹, ]/g, '').trim();
  if (t === '' || t === '—') return '';
  const n = t.replace(/−/g, '-');
  const bare = n.replace(/%$/, '');
  return bare !== '' && !Number.isNaN(Number(bare)) && /^-?\d*\.?\d+$/.test(bare) ? bare : original;
}

/** One row per order (lists in SUMMARY_HEADERS order). */
async function summaryRows(ids) {
  const rows = [];
  for (const orderId of ids) {
    const o = await orderRepository.find(orderId);
    const p = await orderProfitabilityService.computeForOrder(orderId);
    const cash = (await orderLedgerService.forOrder(orderId, String(o.currency_code || 'INR'), true)).totals;
    const c1 = p.freight_cost_inr + p.insurance_cost_inr;
    rows.push([
      String(o.order_reference), String(o.buyer_inquiry_ref || ''), String(o.company_legal_name), String(o.country_of_destination || ''),
      String(o.status), String(o.created_at).slice(0, 10), String(o.currency_code || ''),
      num(await orderProductRepository.totalFobValue(orderId)),
      num(p.revenue_inr), p.revenue_is_estimated ? 'Yes' : 'No', num(p.export_benefits_inr),
      num(p.revenue_inr + p.export_benefits_inr), num(p.supplier_cost_inr),
      num(c1), num(p.logistics_cost_inr), num(p.other_costs_inr), num(p.team_incentive_inr),
      num(p.associate_commission_inr), num(p.expenses_inr), num(p.zoho_expenses_inr),
      num(c1 + p.logistics_cost_inr + p.other_costs_inr + p.expenses_inr + p.zoho_expenses_inr),
      num(p.total_cost_inr), num(p.profit_inr), p.margin_pct !== null && p.margin_pct !== undefined ? Number(p.margin_pct).toFixed(2) : '',
      num(cash.buyer_inr), num(cash.benefits_inr), num(cash.supplier_inr), num(cash.logistics_inr),
      num(cash.expenses_inr), num(cash.net_inr),
    ]);
  }
  return rows;
}

const DETAIL_HEADERS = ['Order reference', 'Client', 'Section', 'Item', 'Description', 'Date', 'Amount', 'Currency', 'Amount (INR)', 'Note'];

function splitAmount(text) {
  const s = String(text);
  const i = s.indexOf(' ');
  return i === -1 ? [s, ''] : [s.slice(0, i), s.slice(i + 1)];
}

/** Every CAFIN line of every order. */
async function detailRows(ids) {
  const rows = [];
  for (const orderId of ids) {
    const o = await orderRepository.find(orderId);
    const s = await caFinancialSnapshotService.build(orderId);
    const add = (section, item, desc = '', date = '', amount = '', currency = '', inr = '', note = '') => {
      rows.push([String(o.order_reference), String(o.company_legal_name), section, String(item), String(desc), String(date), plain(amount), String(currency), plain(inr), String(note)]);
    };
    for (const d of s.details) {
      add('A. Order details', d[0], d[1]);
      add('A. Order details', d[2], d[3]);
    }
    for (const p of s.products) {
      add('A. Products', p.description, `HS ${p.hs_code} · ${p.quantity} × ${p.unit_price}`, '', p.amount, s.currency);
    }
    add('A. Products', 'TOTAL FOB', '', '', s.fob_total, s.currency);
    for (const r of s.receipts) {
      add('A. Money received from the buyer', r.leg, `rate ${r.rate} · received ${r.received}`, r.cleared, r.amount, s.currency, r.inr, r.basis);
    }
    add('A. Money received from the buyer', 'TOTAL', '', '', s.receipts_foreign, s.currency, s.receipts_inr);
    for (const r of ((s.supplier || {}).rows || [])) {
      add('B. Supplier cost', r[0], r[1]);
      add('B. Supplier cost', r[2], r[3]);
    }
    for (const b of ((s.supplier || {}).bills || [])) {
      add('B. Supplier bills & payments', b.leg, `${b.bill} · ${b.paid} · ${b.utr}`, '', '', 'INR', b.total, b.unpaid ? 'unpaid' : '');
    }
    for (const f of s.freight_rows) {
      add('C1. Freight & insurance', f[0], f[1], '', '', 'INR', f[2]);
    }
    for (const l of s.logistics) {
      add('C2. Logistics partner bills', l.partner, `${l.for} · ${l.bill} · ${l.paid} · ${l.utr}`, '', '', 'INR', l.total, l.unpaid ? 'unpaid' : '');
    }
    for (const c of s.costs) {
      add('C3. Other order costs', c.category, c.description, c.date, '', 'INR', c.amount, c.tag || '');
    }
    for (const e of s.expenses) {
      add('C4. Order expenses', e.category, `${e.details} · ${e.paid_by}`, e.date, '', 'INR', e.amount, e.receipt);
    }
    for (const z of s.zoho) {
      const [cur, amt] = splitAmount(z.amount);
      add('C5. Expenses booked in Zoho Books', z.category, `${z.description} · ${z.vendor}`, z.date, amt, cur, cur === 'INR' ? amt : '', z.tag || '');
    }
    for (const line of s.summary) {
      add('C. Expenses summary', line[0], '', '', '', 'INR', line[1]);
    }
    add('C. Expenses summary', 'TOTAL EXPENSES (excluding supplier)', '', '', '', 'INR', s.expenses_grand);
    for (const b of s.benefits) {
      const [cur, amt] = splitAmount(b.received !== '—' ? b.received : b.claimed);
      add('D. Government export benefits', b.scheme, `ref ${b.reference} · claimed ${b.claimed} on ${b.claimed_date}`, b.received_date, amt, cur, cur === 'INR' ? amt : '', b.status);
    }
    for (const line of s.pl) {
      add('E. Profit', line[1], '', '', '', String(line[3]) === '' ? '' : (String(line[3]).endsWith('%') ? '%' : 'INR'), line[3], line[2] || '');
    }
    ['Received from buyer', 'Benefits received', 'Paid to supplier', 'Paid to logistics', 'Expenses', 'Net so far'].forEach((label, i) => {
      add('E. Cash position so far', label, '', '', '', 'INR', s.cash[i]);
    });
    for (const item of s.open) {
      add('F. Open items for the CA', item.text, '', '', '', '', '', item.warn ? 'needs attention' : '');
    }
  }
  return rows;
}

module.exports = { STATUSES, orderIds, financialYears, SUMMARY_HEADERS, summaryRows, DETAIL_HEADERS, detailRows, plain };
