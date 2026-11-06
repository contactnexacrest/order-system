'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const flash = require('../../src/helpers/flash');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const incentiveService = require('../../src/services/incentiveService');
const incentiveReportService = require('../../src/services/incentiveReportService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of IncentiveEligibilityTest.php. D-259 (owner approval 6 Nov 2026, mockups mock12): team
 * incentive eligibility and caps. A person not eligible gets nothing and the company keeps their
 * share; a per-order or monthly cap per role limits a person's amount and the company keeps the
 * rest; nobody else's amount goes up; locked orders never change; only the MD changes
 * eligibility and caps.
 */
describe('D-259 team incentive eligibility and caps', () => {
  let admin;
  let md;
  let people;
  let rolesBefore = {};

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-eligibility-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`, { email, role_id: role.id });
    return result.insertId;
  }

  beforeEach(async () => {
    admin = await createTestUser('Admin');
    md = await createTestUser('Managing Director');
    incentiveRepository.forgetRoles();
    rolesBefore = JSON.parse(JSON.stringify(await incentiveRepository.allRoles()));
    people = {};
    const map = {};
    for (const role of ['sales', 'sales_manager', 'export', 'export_compliance', 'marketing', 'logistics', 'accounts']) {
      people[role] = await createTestUser('Export Executive');
      map[people[role]] = [role];
    }
    await incentiveRepository.replaceUserRoles(map);
    await incentiveRepository.setSetting('program_enabled', '1', admin);
  });

  afterEach(async () => {
    const none = {};
    for (const key of Object.keys(await incentiveRepository.allRoles())) none[key] = { order: null, month: null };
    await incentiveRepository.saveCaps(none, admin);
    await incentiveRepository.replaceUserRoles({});
    await incentiveRepository.setSetting('program_enabled', '0', admin);
    await incentiveRepository.saveRoles(Object.values(rolesBefore), admin);
    await db.execute('UPDATE users SET incentive_eligible = NULL WHERE incentive_eligible IS NOT NULL');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** A fully paid USD order (₹4,00,000 received) whose team incentive the MD fixed at ₹60,000. */
  async function order() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, Object.values(people));
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
        balance_amount = 2700, balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000, balance_exchange_rate = 92.5926 WHERE order_id = :o`, { o: orderId });
    await incentiveRepository.saveOrder(orderId, { incentive_mode: 'fixed', incentive_value: 60000, incentive_reason: 'Worked example of the mockup' }, admin);
    return orderId;
  }

  /** The mockup's worked example: Sales capped per order, Sales Manager not eligible, Logistics at the monthly cap. */
  async function workedExampleSetUp() {
    await incentiveRepository.saveCaps({ sales: { order: 15000, month: 40000 }, logistics: { order: null, month: 20000 } }, admin);
    await db.execute('UPDATE users SET incentive_eligible = 0 WHERE id = :u', { u: people.sales_manager });
    const other = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const month = await incentiveService.estimateMonth();
    await incentiveRepository.addPayout({ order_id: other, payee_kind: 'staff', user_id: people.logistics, role: 'logistics', amount_inr: 14000,
      incentive_month: month, payout_run_on: `${month}-05`, locked_on: new Date().toISOString().slice(0, 10) });
  }

  async function layer(orderId) {
    return (await orderProfitabilityService.incentiveLayer(orderId, await orderProfitabilityService.computeForOrder(orderId, false))).incentive_inr;
  }

  it('the worked example keeps ₹12,000 and pays ₹48,000', async () => {
    const orderId = await order();
    await workedExampleSetUp();
    const calc = await incentiveService.calculate(orderId);
    const byRole = {};
    for (const l of calc.lines) byRole[l.role] = [l.by_share, l.gets, l.kept, l.reason];
    expect(byRole.sales).toEqual([18000, 15000, 3000, 'cap_order']);
    expect(byRole.sales_manager).toEqual([6000, 0, 6000, 'not_eligible']);
    expect(byRole.logistics).toEqual([9000, 6000, 3000, 'cap_month']);
    expect(byRole.export).toEqual([9000, 9000, 0, null]); // nobody else's amount goes up
    expect([calc.team_by_share, calc.team_total, calc.kept_total, calc.kept_not_eligible, calc.kept_capped]).toEqual([60000, 48000, 12000, 6000, 6000]);
    expect(calc.lines.find((l) => l.role === 'logistics').note).toContain('capped: ₹14,000 already this month, monthly cap ₹20,000');
    expect(await layer(orderId)).toBe(48000); // the profit layer uses what is actually payable

    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    const payouts = await incentiveRepository.payoutsForOrder(orderId);
    expect(Math.round(payouts.reduce((t, p) => t + Number(p.amount_inr), 0) * 100) / 100).toBe(48000);
    expect(payouts.map((p) => Number(p.user_id))).not.toContain(people.sales_manager);
    const kept = Object.fromEntries((await incentiveRepository.keptForOrder(orderId)).map((k) => [k.kept_reason, Number(k.kept_inr)]));
    expect(kept).toEqual({ cap_order: 3000, not_eligible: 6000, cap_month: 3000 });
    const row = await incentiveRepository.forOrder(orderId);
    expect([row.team_total_inr, row.team_by_share_inr, row.kept_not_eligible_inr, row.kept_capped_inr].map(Number)).toEqual([48000, 60000, 6000, 6000]);
    expect(Number((await incentiveRepository.costEntries(orderId)).team.amount_inr)).toBe(48000);

    // The register: the kept-only line for the person not eligible, and totals that add up.
    const rows = (await incentiveReportService.rows({ with_kept: true, order_ids: [orderId] })).filter((r) => r.payee_kind === 'staff');
    const t = incentiveReportService.totals(rows);
    expect([t.amount_inr, t.kept_not_eligible_inr, t.kept_capped_inr]).toEqual([48000, 6000, 6000]);
    expect((await incentiveReportService.rows({ order_ids: [orderId] })).filter((r) => r.kept_only)).toHaveLength(0); // never in "to pay"

    // Unlocking removes the kept lines with the payouts.
    expect(await incentiveService.unlock(orderId, admin, 'A claim came in after locking')).toBeNull();
    expect(await incentiveRepository.keptForOrder(orderId)).toEqual([]);
  });

  it('with no caps and everyone eligible nothing changes', async () => {
    const orderId = await order();
    const calc = await incentiveService.calculate(orderId);
    expect([calc.amount, calc.team_total, calc.kept_total]).toEqual([60000, 60000, 0]);
    for (const l of calc.lines) expect([l.gets, l.kept, l.reason]).toEqual([l.by_share, 0, null]);
    expect(await layer(orderId)).toBe(60000);
    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    expect(await incentiveRepository.keptForOrder(orderId)).toEqual([]);
    expect(Number((await incentiveRepository.forOrder(orderId)).team_total_inr)).toBe(60000);
  });

  it('a locked order never changes', async () => {
    const orderId = await order();
    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    const snap = async () => (await incentiveRepository.payoutsForOrder(orderId)).map((p) => `${p.user_id}:${p.amount_inr}`);
    const before = await snap();
    await workedExampleSetUp();
    expect(await snap()).toEqual(before);
    expect(Number((await incentiveRepository.costEntries(orderId)).team.amount_inr)).toBe(60000);
    expect(await layer(orderId)).toBe(60000);
  });

  it('the pages show the switch, the caps and what is kept', async () => {
    const orderId = await order();
    await workedExampleSetUp();
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const show = (await agent.get(`/orders/${orderId}`)).text;
    expect(show).toContain('Kept by company');
    expect(show).toContain('not eligible (switched off for this person), share stays with company');
    expect(show).toContain('12,000.00 kept by company');
    expect((await agent.get('/incentives/settings')).text).toContain('id="incentive-caps"');
    expect((await agent.get('/admin/departments')).text).toContain('Incentive eligible');
    const edit = (await agent.get(`/users/${people.sales}/edit`)).text;
    expect(edit).toContain('Eligible for team incentive');
    expect(edit).toContain('Only the MD can change this.'); // greyed for anyone but the MD
    expect((await agent.get('/reports/incentives')).text).toContain('Kept — not eligible (INR)');
  });

  it('only the MD changes eligibility and caps', async () => {
    const actor = async (id) => db.queryOne('SELECT * FROM users WHERE id = :id', { id });
    const person = people.sales;
    expect(await incentiveService.setEligible(await actor(admin), person, false)).toContain('Only the MD');
    expect(await incentiveService.isEligible(person)).toBe(true);
    expect(await incentiveService.setEligible(await actor(md), person, false)).toBeNull();
    expect(await incentiveService.isEligible(person)).toBe(false);
    expect(await incentiveService.setEligible(await actor(md), person, true)).toBeNull();
    expect((await db.queryOne('SELECT incentive_eligible FROM users WHERE id = :u', { u: person })).incentive_eligible).toBeNull(); // follows the designation again

    // The settings form: the caps are the MD's (manage_pricing); anyone else is refused.
    const ed = await createTestUser('Executive Director');
    const save = async (as, permissions, caps) => {
      const controller = require('../../src/controllers/incentiveController');
      const req = { params: {}, body: { lock_reminder_days: '3', caps_present: '1', caps }, query: {}, session: {}, user: { id: as }, permissions };
      await controller.saveSettings(req, { redirect() {} });
      return flash.pull(req)[0] || { type: 'none', message: '' };
    };
    expect((await save(ed, { manage_incentives: true }, { sales: { order: '100', month: '' } })).message).toContain('Only the MD can change the incentive caps');
    expect((await incentiveRepository.allRoles()).sales.cap_per_order).toBeNull();
    expect((await save(md, { manage_pricing: true, manage_incentives: true }, { sales: { order: '-5', month: '' } })).message).toContain('Enter the Sales cap per order');
    expect((await save(md, { manage_pricing: true, manage_incentives: true }, { sales: { order: '15000', month: '40000' } })).type).toBe('success');
    const sales = (await incentiveRepository.allRoles()).sales;
    expect([sales.cap_per_order, sales.cap_per_month]).toEqual([15000, 40000]);
    expect((await save(md, { manage_pricing: true, manage_incentives: true }, { sales: { order: '', month: '' } })).type).toBe('success');
    expect((await incentiveRepository.allRoles()).sales.cap_per_order).toBeNull(); // empty = no cap
  });
});
