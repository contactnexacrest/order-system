'use strict';

const db = require('../../src/config/db');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const permissionRepository = require('../../src/repositories/permissionRepository');
const reportRepository = require('../../src/repositories/reportRepository');
const reportController = require('../../src/controllers/reportController');
const cafinExportService = require('../../src/services/cafinExportService');
const incentiveReportService = require('../../src/services/incentiveReportService');
const incentiveService = require('../../src/services/incentiveService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of IncentiveReportsTest.php. Owner decision D-245 P5: one profit calculation
 * everywhere — profit before commission and incentive, the associate commission and team
 * incentive (estimated until the MD locks the order, then the locked figure replaces the
 * estimate — never both), the profit after; the register, "To pay", "Owed at month end" and
 * person statement add up to their rows; the CAFIN export carries the same figures with GST
 * and TDS apart (not costs).
 */
describe('Incentive & commission reports and the profit layer (D-245 P5)', () => {
  let admin;
  let people;
  const round2 = (v) => Math.round(v * 100) / 100;

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-p5-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest P5 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email, role_id: role.id }
    );
    return r.insertId;
  }

  beforeEach(async () => {
    admin = await createTestUser('Admin');
    people = { sales: await createTestUser('Export Executive'), logistics: await createTestUser('Logistics Executive') };
    await incentiveRepository.replaceUserRoles({ [people.sales]: ['sales'], [people.logistics]: ['logistics'] });
    await incentiveRepository.setSetting('program_enabled', '1', admin);
    incentiveRepository.forgetRoles();
  });

  afterEach(async () => {
    await incentiveRepository.replaceUserRoles({});
    await incentiveRepository.setSetting('program_enabled', '0', admin);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** USD 4,500 of goods; advance ₹1,50,000 + balance ₹2,50,000 credited, both cleared (fully paid) unless paid is false. */
  async function order(paid = true) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, Object.values(people));
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
        balance_amount = 2700, balance_cleared_at = :b, balance_inr_actual = :binr, balance_exchange_rate = 92.5926 WHERE order_id = :o`,
      { a: '2026-10-10 10:00:00', b: paid ? '2026-10-25 11:00:00' : null, binr: paid ? 250000 : null, o: orderId }
    );
    return orderId;
  }

  it('the estimate is replaced by the locked figure, never added twice', async () => {
    const orderId = await order();
    const before = await orderProfitabilityService.computeForOrder(orderId);
    const calc = await incentiveService.calculate(orderId);
    expect([before.incentive_state, before.incentive_inr, before.incentives_locked]).toEqual(['estimated', round2(calc.team_total), false]);
    expect(before.incentive_inr).toBeGreaterThan(0);
    expect(before.profit_after_incentive_inr).toBe(round2(before.profit_before_commission_inr - before.commission_inr - before.incentive_inr));
    expect(before.profit_inr).toBe(before.profit_before_commission_inr);

    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    const after = await orderProfitabilityService.computeForOrder(orderId);
    expect([after.incentive_state, after.incentive_inr, after.incentives_locked]).toEqual(['locked', before.incentive_inr, true]);
    expect(after.profit_before_commission_inr).toBe(before.profit_before_commission_inr);
    expect(after.profit_after_incentive_inr).toBe(before.profit_after_incentive_inr);
    expect(after.profit_inr).toBe(after.profit_after_incentive_inr);
    expect(after.other_costs_excl_incentives_inr).toBe(0);

    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-11-05', paid_inr = amount_inr WHERE order_id = :o", { o: orderId });
    expect((await orderProfitabilityService.computeForOrder(orderId)).incentive_state).toBe('paid');
  });

  it('an unpaid order with no profit never shows a negative incentive', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, Object.values(people));
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await orderCostEntryRepository.create(orderId, 'other', 'P5 test cost', 900000, null, admin);
    const p = await orderProfitabilityService.computeForOrder(orderId);
    expect(p.profit_before_commission_inr).toBeLessThan(0);
    expect([p.incentive_inr, p.incentive_state]).toEqual([0, 'estimated']);
    expect((await incentiveService.calculate(orderId)).basis).toBe(0);
  });

  it('GST and TDS on commission are shown apart and are not costs', async () => {
    const orderId = await order();
    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    const cost = (await orderProfitabilityService.computeForOrder(orderId)).total_cost_inr;
    const a = await db.execute("INSERT INTO associates (name, based_in, currency_code, status) VALUES ('P5 Test Associate', 'india', 'INR', 'active')");
    await db.execute(
      `INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_on, paid_inr, gst_inr, tds_inr)
       VALUES (:o, 'associate', :a, 'associate', 1000, '2026-12', 'paid', '2026-12-05', 980, 180, 20)`,
      { o: orderId, a: a.insertId }
    );
    expect(await cafinExportService.commissionTaxes(orderId)).toEqual([180, 20]);
    expect((await orderProfitabilityService.computeForOrder(orderId)).total_cost_inr).toBe(cost);
    const headers = cafinExportService.SUMMARY_HEADERS;
    const values = (await cafinExportService.summaryRows([orderId]))[0];
    const row = Object.fromEntries(headers.map((h, i) => [h, values[i]]));
    expect([row['GST on commission (INR, not a cost)'], row['TDS on commission (INR, a liability)'], row['Incentive state']]).toEqual(['180.00', '20.00', 'Locked']);
    const p = await orderProfitabilityService.computeForOrder(orderId);
    expect(row['Profit after commission & incentive (INR)']).toBe(p.profit_after_incentive_inr.toFixed(2));
    expect(await cafinExportService.lockedOnly([await order()])).toEqual([]);
    expect(await cafinExportService.lockedOnly([orderId])).toEqual([orderId]);
  });

  it('the profitability report switch and totals are the sum of its orders', async () => {
    const open = await order();
    const locked = await order();
    expect(await incentiveService.lockIfReady(locked, admin)).toBeNull();
    const all = await reportRepository.orderProfitabilityReport(null, null, undefined, 'all');
    const ids = all.rows.map((r) => r.order_id);
    expect(ids).toContain(open);
    expect(ids).toContain(locked);
    const onlyLocked = await reportRepository.orderProfitabilityReport(null, null, undefined, 'locked');
    expect(onlyLocked.rows.map((r) => r.order_id)).not.toContain(open);
    expect(onlyLocked.rows.map((r) => r.order_id)).toContain(locked);
    for (const data of [all, onlyLocked]) {
      for (const k of ['revenue_inr', 'cost_before_incentives_inr', 'profit_before_commission_inr', 'commission_inr', 'incentive_inr', 'profit_after_incentive_inr']) {
        expect([k, data.totals[k]]).toEqual([k, round2(data.rows.reduce((s, r) => s + Number(r[k]), 0))]);
      }
    }
    expect(Object.keys(reportController.profitabilityCsvRows(all.rows)[0])).toEqual(reportController.PROFITABILITY_HEADERS);
  });

  it('the register, To pay, Owed and statement add up to their rows', async () => {
    const open = await order();
    const locked = await order();
    expect(await incentiveService.lockIfReady(locked, admin)).toBeNull();

    const rows = await incentiveReportService.rows();
    const lockedRows = rows.filter((r) => r.order_id === locked);
    const openRows = rows.filter((r) => r.order_id === open);
    expect([...new Set(lockedRows.map((r) => r.state))]).toEqual(['locked']);
    expect([...new Set(openRows.map((r) => r.state))]).toEqual(['ready']);
    expect(lockedRows.reduce((s, r) => s + r.amount_inr, 0)).toBeCloseTo(Number((await incentiveRepository.forOrder(locked)).team_total_inr), 2);
    expect(openRows.reduce((s, r) => s + r.amount_inr, 0)).toBeCloseTo((await incentiveService.calculate(open)).team_total, 2);

    const t = incentiveReportService.totals(rows);
    expect(t.amount_inr).toBe(round2(rows.reduce((s, r) => s + r.amount_inr, 0)));
    expect(t.count).toBe(rows.length);
    expect(Object.keys(incentiveReportService.csvRows(rows)[0])).toEqual(incentiveReportService.HEADERS);

    const onlyLocked = await incentiveReportService.rows({ state: 'locked' });
    expect([...new Set(onlyLocked.map((r) => r.state))]).toEqual(['locked']);
    const mine = await incentiveReportService.statement(people.sales, null);
    expect(mine.length).toBeGreaterThan(0);
    expect([...new Set(mine.map((r) => r.user_id))]).toEqual([people.sales]);

    const groups = await incentiveReportService.toPay();
    expect([...groups.this.rows, ...groups.next.rows].some((r) => r.order_id === locked)).toBe(true);
    expect(groups.open.rows.some((r) => r.order_id === open)).toBe(true);
    for (const g of Object.values(groups)) expect(g.totals.amount_inr).toBe(round2(g.rows.reduce((s, r) => s + r.amount_inr, 0)));

    const d = new Date();
    const todayStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    expect((await incentiveReportService.owedAt(todayStr)).some((r) => r.order_id === locked)).toBe(true);
    expect((await incentiveReportService.owedAt('2020-01-31')).some((r) => [locked, open].includes(r.order_id))).toBe(false);
  });

  it('only managers see the reports; staff and clients never', async () => {
    const gate = ['manage_incentives', 'pay_incentives', 'view_price_buildup'];
    const can = async (userId) => {
      const u = await db.queryOne('SELECT role_id FROM users WHERE id = :id', { id: userId });
      const perms = await permissionRepository.effectivePermissions(userId, u.role_id);
      return gate.some((k) => perms[k]);
    };
    expect(await can(admin)).toBe(true);
    expect(await can(people.sales)).toBe(false);
    expect(await can(people.logistics)).toBe(false);

    // Whoever sees profit but not incentives (manage_order_financials only) gets no incentive columns.
    const render = async (permissions) => {
      let seen = null;
      const req = { query: {}, user: { id: admin }, permissions };
      const res = { renderView(view, data) { seen = data; } };
      await reportController.profitability(req, res);
      return seen;
    };
    expect((await render({ manage_order_financials: true })).seeIncentives).toBe(false);
    expect((await render({ manage_order_financials: true, manage_incentives: true })).seeIncentives).toBe(true);
  });
});
