'use strict';

const db = require('../config/db');
const divisionService = require('./divisionService');
const G = require('./gstCalculator');
const I = require('./importService');

/**
 * Owner decision D-257 Phase 5 (5 Nov 2026): profit by division, with the
 * company's own costs shared out as overhead in proportion to each
 * division's revenue. Shown on the Order Profitability report only when two
 * or more divisions are active and the person is looking at "All
 * divisions" (company level); with one division the report stays exactly as
 * it was. CAFIN is not touched.
 *
 * Costs come from ca_expenses not linked to an order (an order's own
 * expenses are already in that order's cost): division_id empty = a
 * company-wide cost, shared by revenue; a division's own = charged to it
 * alone. Only ₹ expenses count. Port of PHP App\Services\DivisionProfitService.
 */

const paise = (v) => G.scaled(typeof v === 'number' ? v.toFixed(2) : String(v), 2);

async function summary(rows, dateFrom, dateTo, profitAfterIncentives) {
  if (!(await divisionService.isMulti()) || divisionService.currentIds() !== null) return null;
  const divisionOf = new Map();
  const ids = rows.map((r) => parseInt(r.order_id, 10));
  if (ids.length > 0) {
    for (const o of await db.query(`SELECT id, division_id FROM orders WHERE id IN (${ids.join(',')})`)) divisionOf.set(Number(o.id), Number(o.division_id));
  }
  const divisions = new Map();
  const blank = (d, id) => ({ code: String(d ? d.code : id), name: String(d ? d.name : id), revenue: 0n, profit: 0n, own: 0n, orders: 0 });
  for (const d of await divisionService.active()) divisions.set(Number(d.id), blank(d));
  const profitKey = profitAfterIncentives ? 'profit_after_incentive_inr' : 'profit_before_commission_inr';
  for (const r of rows) {
    const div = divisionOf.get(Number(r.order_id)) || 1;
    if (!divisions.has(div)) divisions.set(div, blank(await divisionService.find(div), div));
    const x = divisions.get(div);
    x.revenue += paise(r.revenue_inr);
    x.profit += paise(r[profitKey]);
    x.orders++;
  }
  const where = ['order_id IS NULL', "currency_code = 'INR'"];
  const params = {};
  if (dateFrom) {
    where.push('expense_date >= :f');
    params.f = dateFrom;
  }
  if (dateTo) {
    where.push('expense_date <= :t');
    params.t = dateTo;
  }
  let overhead = 0n;
  for (const c of await db.query(`SELECT division_id, SUM(amount) AS total FROM ca_expenses WHERE ${where.join(' AND ')} GROUP BY division_id`, params)) {
    if (c.division_id === null) overhead += paise(c.total);
    else if (divisions.has(Number(c.division_id))) divisions.get(Number(c.division_id)).own += paise(c.total);
  }
  const keys = [...divisions.keys()];
  const weights = keys.map((k) => (divisions.get(k).revenue > 0n ? divisions.get(k).revenue : 0n));
  const totalRevenue = weights.reduce((a, b) => a + b, 0n);
  const shares = totalRevenue > 0n ? I.spread(overhead, weights) : keys.map(() => 0n);
  return {
    rows: keys.map((k, i) => {
      const d = divisions.get(k);
      return {
        division_id: k,
        code: d.code,
        name: d.name,
        orders: d.orders,
        revenue_inr: G.money(d.revenue),
        revenue_share_pct: totalRevenue > 0n ? G.money(G.roundDiv(weights[i] * 10000n, totalRevenue)) : '0.00',
        profit_inr: G.money(d.profit),
        own_costs_inr: G.money(d.own),
        overhead_share_inr: G.money(shares[i]),
        profit_after_overhead_inr: G.money(d.profit - d.own - shares[i]),
      };
    }),
    overhead_inr: G.money(overhead),
    overhead_unshared: totalRevenue === 0n && overhead > 0n,
    profit_label: profitAfterIncentives ? 'Profit after commission & incentive' : 'Profit before commission & incentive',
  };
}

/** The same figures formatted for the report (Indian grouping). */
function forView(s) {
  if (!s) return null;
  return {
    ...s,
    overhead_fmt: G.inr(s.overhead_inr),
    rows: s.rows.map((r) => ({
      ...r, revenue_fmt: G.inr(r.revenue_inr), profit_fmt: G.inr(r.profit_inr), own_fmt: G.inr(r.own_costs_inr), overhead_fmt: G.inr(r.overhead_share_inr), after_fmt: G.inr(r.profit_after_overhead_inr),
    })),
  };
}

module.exports = { summary, forView };
