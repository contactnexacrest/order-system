'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const divisionProfit = require('../../src/services/divisionProfitService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/** Every route in src/server.js with the permission(s) it requires (any one), or null. */
function routes() {
  const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8');
  return [...source.matchAll(/app\.(get|post)\('([^']+)',([^\n]*)/g)].map(([, method, pattern, rest]) => {
    const one = rest.match(/requirePermission\('([^']+)'\)/);
    const any = rest.match(/requirePermissionAny\(\[([^\]]+)\]\)/);
    let permissions = null;
    if (one) permissions = [one[1]];
    else if (any) permissions = [...any[1].matchAll(/'([^']+)'/g)].map((k) => k[1]);
    return { method: method.toUpperCase(), pattern, permissions };
  });
}

/**
 * Port of DivisionOverheadAndInvestorTest.php. Owner decision D-257 Phase 5
 * (5 Nov 2026): the division profit report shares the company's own costs
 * out as overhead by revenue — only with two or more active divisions and
 * only for "All divisions"; with one division the Order Profitability report
 * is exactly what it was. The Investor (view only) role works end to end:
 * it holds only view_reports, view_cockpit and see_all_divisions, every
 * other permission-gated route refuses it, the only write it can make is
 * choosing a division in the header, and its pages open without error.
 */
describe('D-257 Phase 5 — overhead sharing and the Investor role', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
  });

  async function createUser(roleName, divisionId) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-p5-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestPhaseFive123!';
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Phase 5 User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id, d: divisionId }
    );
    return { id: r.insertId, email, password, division_id: divisionId };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  function as(user, fn) {
    const seesAll = user.division_id === null;
    return requestContext.run({ userId: user.id, divisionIds: seesAll ? null : [user.division_id], divisionSeesAll: seesAll, divisionUser: { division_id: user.division_id } }, fn);
  }

  it('leaves the profit report exactly as it was with one division', async () => {
    const md = await createUser('Managing Director', null);
    await createTestOrder(await createTestClient());
    expect(await as(md, () => divisionProfit.summary([{ order_id: 1, revenue_inr: 1, profit_after_incentive_inr: 1 }], null, null, true))).toBeNull();
    const page = (await (await loginAs(md)).get('/reports/order-profitability')).text;
    expect(page).not.toContain('division-profit');
    expect(page).not.toContain('Profit by division');
    expect(page).toContain('Order Profitability');
  });

  it('shares the overhead by revenue with two divisions', async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = 2");
    const md = await createUser('Managing Director', null);
    const domStaff = await createUser('Procurement Manager', 2);
    const exp = await createTestOrder(await createTestClient());
    const dom = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET division_id = 2 WHERE id = :id', { id: dom });
    const tag = Math.random().toString(16).slice(2, 10);
    const ins = (z, c, a, cur, d, div, o) => db.execute(
      'INSERT INTO ca_expenses (zoho_expense_id, category, amount, currency_code, expense_date, division_id, order_id) VALUES (:z, :c, :a, :cur, :d, :div, :o)',
      { z, c, a, cur, d, div, o }
    );
    await ins(`T-${tag}-1`, 'Rent', '30000.00', 'INR', '2031-01-15', null, null);
    await ins(`T-${tag}-2`, 'Domestic office', '5000.00', 'INR', '2031-01-16', 2, null);
    await ins(`T-${tag}-3`, 'Inspection', '99999.00', 'INR', '2031-01-17', null, exp);
    await ins(`T-${tag}-4`, 'Software', '700.00', 'USD', '2031-01-18', null, null);
    const rows = [
      { order_id: exp, revenue_inr: 200000, profit_before_commission_inr: 60000, profit_after_incentive_inr: 50000 },
      { order_id: dom, revenue_inr: 100000, profit_before_commission_inr: 25000, profit_after_incentive_inr: 20000 },
    ];
    const s = await as(md, () => divisionProfit.summary(rows, '2031-01-01', '2031-01-31', true));
    expect(s.overhead_inr).toBe('30000.00');
    const by = Object.fromEntries(s.rows.map((r) => [r.code, r]));
    expect([by.EXP.revenue_inr, by.EXP.revenue_share_pct, by.EXP.profit_inr, by.EXP.own_costs_inr, by.EXP.overhead_share_inr, by.EXP.profit_after_overhead_inr])
      .toEqual(['200000.00', '66.67', '50000.00', '0.00', '20000.00', '30000.00']);
    expect([by.DOM.revenue_inr, by.DOM.revenue_share_pct, by.DOM.profit_inr, by.DOM.own_costs_inr, by.DOM.overhead_share_inr, by.DOM.profit_after_overhead_inr])
      .toEqual(['100000.00', '33.33', '20000.00', '5000.00', '10000.00', '5000.00']);
    const before = await as(md, () => divisionProfit.summary(rows, '2031-01-01', '2031-01-31', false));
    expect(before.profit_label).toBe('Profit before commission & incentive');
    expect(before.rows.find((r) => r.code === 'EXP').profit_inr).toBe('60000.00');
    expect((await as(md, () => divisionProfit.summary([], '2031-01-01', '2031-01-31', true))).overhead_unshared).toBe(true);
    expect(await as(domStaff, () => divisionProfit.summary(rows, '2031-01-01', '2031-01-31', true))).toBeNull();
    const page = (await (await loginAs(md)).get('/reports/order-profitability?date_from=2031-01-01&date_to=2031-01-31')).text;
    expect(page).toContain('data-testid="division-profit"');
    expect(page).toContain('₹30,000.00 in this period');
  });

  // D-398 (20 Nov 2026, owner): a Merchant Trade deal counts in its division's row — received ₹ and margin.
  it('counts Merchant Trade deals in their division\'s row', async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = 3");
    const md = await createUser('Managing Director', null);
    const exp = await createTestOrder(await createTestClient());
    const tag = Math.random().toString(16).slice(2, 8).toUpperCase();
    const deal = async (ref, stage, date) => (await db.execute(
      `INSERT INTO merchant_deals (division_id, reference, po_no, stage, deal_date, supplier_name, supplier_country, purchase_currency, buyer_name, buyer_country, sale_currency, origin_country, advance_backing, switch_bl, created_by, updated_by)
       VALUES (3, :r, :p, :st, :d, 'TEST Supplier', 'Vietnam', 'USD', 'TEST Buyer', 'UAE', 'USD', 'Vietnam', 'none', 0, :u1, :u2)`,
      { r: `TEST-MTT/${ref}`, p: `TEST-PO/${ref}`, st: stage, d: date, u1: md.id, u2: md.id })).insertId;
    const pay = (id, leg, fc, inr, ref, on) => db.execute(
      `INSERT INTO merchant_payments (deal_id, leg, moved_on, amount_fc, fx_rate, amount_inr, bank_reference, description, recorded_by)
       VALUES (:id, :leg, :on, :fc, '83.3333', :inr, :ref, 'TEST', :u)`, { id, leg, on, fc, inr, ref, u: md.id });
    const ids = [];
    try {
      const d1 = await deal(tag, 'settled', '2032-02-10');
      ids.push(d1);
      await db.execute(`INSERT INTO merchant_deal_lines (deal_id, line_no, description, hsn_code, quantity, unit, buy_price_fc, sell_price_fc, is_active, created_by)
        VALUES (:d, 1, 'TEST granite', '68022390', '10.000', 'CBM', '84.00', '120.00', 1, :u)`, { d: d1, u: md.id });
      await pay(d1, 'sell', '1200.00', '100000.00', `UTR-S-${tag}`, '2032-02-20');
      await pay(d1, 'buy', '840.00', '70000.00', `UTR-B-${tag}`, '2032-02-20');
      const d2 = await deal(`${tag}-X`, 'contracted', '2031-12-31');
      ids.push(d2);
      await pay(d2, 'sell', '10.00', '830.00', `UTR-X-${tag}`, '2031-12-31');

      const rows = [{ order_id: exp, revenue_inr: 300000, profit_before_commission_inr: 60000, profit_after_incentive_inr: 50000 }];
      const by = Object.fromEntries((await as(md, () => divisionProfit.summary(rows, '2032-02-01', '2032-02-29', true))).rows.map((r) => [r.code, r]));
      expect([by.MTT.revenue_inr, by.MTT.profit_inr, by.MTT.deals, by.MTT.orders]).toEqual(['100000.00', '30000.00', 1, 0]);
      expect(by.MTT.revenue_share_pct).toBe('25.00');
      expect([by.EXP.revenue_inr, by.EXP.deals]).toEqual(['300000.00', 0]);
      const before = await as(md, () => divisionProfit.summary(rows, '2032-02-01', '2032-02-29', false));
      expect(before.rows.find((r) => r.code === 'MTT').profit_inr).toBe('30000.00');
      const page = (await (await loginAs(md)).get('/reports/order-profitability?date_from=2032-02-01&date_to=2032-02-29')).text;
      expect(page).toContain('<th>Orders / deals</th>');
      expect(page).toContain('0 + 1 deal</td>');
    } finally {
      for (const id of ids) {
        await db.execute('DELETE FROM merchant_payments WHERE deal_id = :id', { id });
        await db.execute('DELETE FROM merchant_deal_lines WHERE deal_id = :id', { id });
        await db.execute('DELETE FROM merchant_deals WHERE id = :id', { id });
      }
    }
  });

  // D-399 (20 Nov 2026, owner): the ready-made role for a whole Merchant Trade deal.
  it('gives the Merchant Trade Executive both sides of a deal', async () => {
    const keys = (await db.query(`SELECT p.permission_key AS k FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
      WHERE r.name = 'Merchant Trade Executive' AND rp.is_enabled = 1`)).map((x) => x.k);
    for (const k of ['view_selling_prices', 'view_buyer_payments', 'manage_purchasing', 'view_purchase_prices', 'manage_payments']) expect(keys).toContain(k);
    expect(keys).not.toContain('see_all_divisions');
    expect(keys).not.toContain('approve_payment_terms');
    expect(keys).not.toContain('approve_documents');
    const chapters = (await db.query(`SELECT c.slug AS s FROM sop_role_chapters c JOIN roles r ON r.id = c.role_id WHERE r.name = 'Merchant Trade Executive'`)).map((x) => x.s);
    expect(chapters).toContain('31-merchant-trade');
  });

  it('keeps the Investor (view only) role view-only, end to end', async () => {
    const investor = await createUser('Investor (view only)', null);
    const held = (await db.query(
      `SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id
        WHERE r.name = 'Investor (view only)' AND rp.is_enabled = 1 ORDER BY p.permission_key`
    )).map((r) => r.permission_key);
    expect(held).toEqual(['see_all_divisions', 'view_cockpit', 'view_reports']);
    const agent = await loginAs(investor);
    const notRefused = [];
    const writesOpen = [];
    const failed = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.permissions !== null)) {
      const concrete = route.pattern.replace(/:[A-Za-z]+/g, '999999');
      const mayPass = route.permissions.some((p) => held.includes(p));
      if (!mayPass) {
        const res = route.method === 'GET' ? await agent.get(concrete) : await agent.post(concrete).type('form').send({});
        if (res.status !== 403) notRefused.push(`${route.method} ${route.pattern} → ${res.status}`);
        checked++;
        continue;
      }
      if (route.method === 'POST') {
        writesOpen.push(route.pattern);
        continue;
      }
      if (!route.pattern.includes(':')) {
        const res = await agent.get(concrete);
        if (res.status >= 500) failed.push(`GET ${route.pattern} → ${res.status}`);
      }
    }
    expect(checked).toBeGreaterThan(300);
    expect(notRefused).toEqual([]);
    expect(writesOpen).toEqual(['/divisions/choose']);
    expect(failed).toEqual([]);
    for (const route of routes()) expect(route.pattern.toLowerCase()).not.toContain('margin-impact');
  }, 180000);
});
