'use strict';

const request = require('supertest');
const bcrypt = require('bcrypt');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const associateRepository = require('../../src/repositories/associateRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const permissionRepository = require('../../src/repositories/permissionRepository');
const incentiveService = require('../../src/services/incentiveService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of TeamIncentiveTest.php. Owner decision 2026-10-30 (mockups
 * approved): the team incentive program and associate commission.
 */
describe('Team incentive program and associates', () => {
  let admin;
  let people;

  async function createTestUser(roleName, password = null) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-incentive-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: password ? await bcrypt.hash(password, 4) : 'x', role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id, email };
  }

  beforeEach(async () => {
    admin = (await createTestUser('Admin')).id;
    people = {
      sales: (await createTestUser('Export Executive')).id,
      export: (await createTestUser('Export Executive')).id,
      marketing: (await createTestUser('Export Executive')).id,
      logistics1: (await createTestUser('Logistics Executive', 'JestIncentive1!')).id,
      logistics2: (await createTestUser('Logistics Executive')).id,
      accounts: (await createTestUser('Accounts Executive')).id,
    };
    await incentiveRepository.replaceUserRoles({
      [people.sales]: ['sales'], [people.export]: ['export'], [people.marketing]: ['marketing'],
      [people.logistics1]: ['logistics'], [people.logistics2]: ['logistics'], [people.accounts]: ['accounts'],
    });
    const values = {
      program_enabled: '1', program_start_date: '2026-01-01', min_profit_inr: '300000',
      reward_table: '[{"from":300000,"reward":40000},{"from":500000,"reward":60000}]', new_client_bonus_inr: '10000',
      role_split: '{"sales":40,"export":20,"marketing":15,"logistics":15,"accounts":10}', cutoff_day: '20',
    };
    for (const [k, v] of Object.entries(values)) {
      await incentiveRepository.setSetting(k, v, admin);
    }
  });

  afterEach(async () => {
    await incentiveRepository.replaceUserRoles({});
    await incentiveRepository.setSetting('program_enabled', '0', admin);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** A USD order: products USD 4,500; advance USD 1,800 (₹1,50,000) and balance USD 2,700 (₹2,50,000), balance cleared on balanceCleared. */
  async function paidOrder(clientId, balanceCleared = '2026-10-25') {
    // The fixture makes one order per client: a further order for the same client is made under a spare client and moved.
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, Object.values(people));
    const next = await db.queryOne('SELECT COALESCE(MAX(sequence_no), 0) + 1 AS n FROM orders WHERE client_id = :c', { c: clientId });
    await db.execute('UPDATE orders SET client_id = :c, sequence_no = :n WHERE id = :o', { c: clientId, n: next.n, o: orderId });
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
        balance_amount = 2700, balance_cleared_at = :b, balance_inr_actual = :binr, balance_exchange_rate = 92.5926 WHERE order_id = :o`,
      { a: '2026-10-10 10:00:00', b: balanceCleared === null ? null : `${balanceCleared} 11:00:00`, binr: balanceCleared === null ? null : 250000, o: orderId }
    );
    return orderId;
  }

  async function payouts(orderId) {
    const out = {};
    for (const p of await incentiveRepository.payoutsForOrder(orderId)) {
      const key = p.payee_kind === 'staff' ? Object.keys(people).find((k) => people[k] === Number(p.user_id)) : 'associate';
      out[key] = Number(p.amount_inr);
    }
    return out;
  }

  it('the reward is split by role with the new client bonus and becomes the order\'s cost', async () => {
    const orderId = await paidOrder(await createTestClient());
    const profitBefore = (await orderProfitabilityService.computeForOrder(orderId)).profit_inr;
    expect(profitBefore).toBeGreaterThanOrEqual(300000);
    expect(profitBefore).toBeLessThan(500000);

    await incentiveService.lockIfDue(orderId, admin);

    // Sales 40% + 40/55 of the ₹10,000 new client bonus; Logistics shared
    expect(await payouts(orderId)).toEqual({ sales: 23272.73, export: 8000, marketing: 8727.27, logistics1: 3000, logistics2: 3000, accounts: 4000 });
    const row = await incentiveRepository.forOrder(orderId);
    expect([String(row.fully_paid_on).slice(0, 10), row.incentive_month, row.team_total_inr, row.new_client_bonus_inr]).toEqual(['2026-10-25', '2026-11', '50000.00', '10000.00']);

    const entry = (await incentiveRepository.costEntries(orderId)).team;
    expect([entry.category, entry.amount_inr, entry.paid_from]).toEqual(['commission', '50000.00', null]);
    const after = await orderProfitabilityService.computeForOrder(orderId);
    expect(after.profit_inr).toBeCloseTo(profitBefore - 50000, 2); // the team incentive is the order's cost
    expect(after.team_incentive_inr).toBe(50000);
  });

  it('the amount set on the order, the exclusion, the minimum and the switch', async () => {
    const client = await createTestClient();
    const first = await paidOrder(client);
    await incentiveService.lockIfDue(first, admin);

    const repeat = await paidOrder(client);
    await incentiveRepository.saveOrder(repeat, { reward_override_inr: 70000 }, admin);
    let calc = await incentiveService.calculate(repeat, '2026-10-25');
    expect([calc.source, calc.reward, calc.new_client_bonus, calc.team_total]).toEqual(['override', 70000, 0, 70000]); // a repeat order gets no new client bonus

    await incentiveRepository.saveOrder(repeat, { reward_override_inr: 0, is_excluded: 1 }, admin);
    calc = await incentiveService.calculate(repeat);
    expect([calc.source, calc.team_total]).toEqual(['excluded', 0]);

    await incentiveRepository.saveOrder(repeat, { is_excluded: 0 }, admin);
    await incentiveRepository.setSetting('min_profit_inr', '900000', admin);
    expect((await incentiveService.calculate(repeat)).source).toBe('below_min');
    await incentiveRepository.setSetting('min_profit_inr', '300000', admin);
    await incentiveRepository.setSetting('program_enabled', '0', admin);
    calc = await incentiveService.calculate(repeat);
    expect([calc.source, calc.team_total]).toEqual(['off', 0]);
    await incentiveRepository.setSetting('program_enabled', '1', admin);
    expect((await incentiveService.calculate(repeat, '2025-12-31')).source).toBe('before_start');
  });

  it('the associate\'s commission is deducted first, on the client\'s first ten days only', async () => {
    const associate = await associateRepository.create({
      name: 'Jest Associate', based_in: 'abroad', country: 'USA', currency_code: 'USD', email: null, phone: null, tax_id: null,
      agreement_signed_on: '2026-09-01', rate_1_5: 0.70, rate_6_10: 1.00, rate_11_20: 1.50, rate_21_40: 1.75, rate_41_plus: 2.00, status: 'active', notes: null,
    }, admin);
    const client = await createTestClient();
    await associateRepository.linkClient(client, associate, '2026-09-05');

    const orderId = await paidOrder(client);
    const profitBefore = (await orderProfitabilityService.computeForOrder(orderId)).profit_inr;
    const calc = await incentiveService.calculate(orderId);
    expect([calc.associate.order_number, calc.associate.rate, calc.associate.amount_fc, calc.associate.currency]).toEqual([1, 0.70, 31.5, 'USD']);
    expect(calc.associate.amount_inr).toBeCloseTo(2800, 2); // USD 31.50 at the ₹88.89 actually received per dollar
    expect(calc.basis).toBeCloseTo(profitBefore - 2800, 2);

    await incentiveService.lockIfDue(orderId, admin);
    expect((await payouts(orderId)).associate).toBeCloseTo(2800, 2);
    expect((await incentiveRepository.costEntries(orderId)).associate.amount_inr).toBe('2800.00');

    const later = await paidOrder(client);
    await db.execute('UPDATE orders SET created_at = DATE_ADD(created_at, INTERVAL 15 DAY) WHERE id = :id', { id: later });
    expect((await incentiveService.calculate(later)).associate).toBeNull(); // after day 10 it is a repeat order: no commission
    await incentiveRepository.saveOrder(later, { associate_mode: 'yes' }, admin);
    expect((await incentiveService.calculate(later)).associate.order_number).toBe(1); // still the same client: the same commission order

    const otherClient = await createTestClient();
    const second = await paidOrder(otherClient);
    await associateRepository.linkClient(otherClient, associate, '2026-09-20');
    expect((await incentiveService.calculate(second)).associate.order_number).toBe(2);
  });

  it('the incentive month follows the cut-off day', () => {
    expect(incentiveService.incentiveMonth('2026-10-20', '20')).toBe('2026-10');
    expect(incentiveService.incentiveMonth('2026-10-21', '20')).toBe('2026-11');
    expect(incentiveService.incentiveMonth('2026-12-25', '20')).toBe('2027-01');
    expect(incentiveService.incentiveMonth('2026-10-31', 'last')).toBe('2026-10');
    expect(incentiveService.cycleLabel('2026-11', '20')).toBe('21 Oct to 20 Nov');
    expect(incentiveService.cycleLabel('2026-11', 'last')).toBe('1 Nov to 30 Nov');
  });

  it('nothing is fixed until the buyer has fully paid', async () => {
    const orderId = await paidOrder(await createTestClient(), null);
    expect(await incentiveService.fullyPaidOn(orderId)).toBeNull();
    await incentiveService.lockIfDue(orderId, admin);
    expect(await incentiveRepository.forOrder(orderId)).toBeNull();
    expect(await incentiveRepository.payoutsForOrder(orderId)).toEqual([]);
  });

  it('paying everyone sends the team incentive to Zoho Books as paid', async () => {
    const orderId = await paidOrder(await createTestClient());
    await incentiveService.lockIfDue(orderId, admin);
    const rows = await incentiveRepository.payoutsForOrder(orderId);
    await incentiveService.markPaid(Number(rows[0].id), '2026-11-30', Number(rows[0].amount_inr), null, admin);
    expect((await incentiveRepository.costEntries(orderId)).team.paid_from).toBeNull(); // not all paid yet: not sent
    for (const r of rows.slice(1)) {
      await incentiveService.markPaid(Number(r.id), '2026-11-30', Number(r.amount_inr), null, admin);
    }
    const entry = (await incentiveRepository.costEntries(orderId)).team;
    expect([entry.paid_from, String(entry.incurred_at).slice(0, 10), entry.amount_inr]).toEqual(['company_account', '2026-11-30', '50000.00']);
    expect(await incentiveService.unlock(orderId, admin)).toBe('Part of this order\'s incentive is already paid, so its figures can no longer be worked out again.');
  });

  it('the incentive cost line cannot be removed by hand', async () => {
    const orderId = await paidOrder(await createTestClient());
    await incentiveService.lockIfDue(orderId, admin);
    const entryId = Number((await incentiveRepository.costEntries(orderId)).team.id);
    const req = { params: { id: String(orderId), entryId: String(entryId) }, body: {}, query: {}, session: {}, user: { id: admin }, permissions: {} };
    const res = { redirect() {}, status() { return this; }, send() {} };
    await orderFinancialsController.deleteCostEntry(req, res);
    expect(flash.pull(req)[0].type).toBe('error');
    expect(await incentiveRepository.costEntries(orderId)).toHaveProperty('team');
  });

  it('every page opens and staff see only their own amounts', async () => {
    const orderId = await paidOrder(await createTestClient());
    await incentiveService.lockIfDue(orderId, admin);
    const agent = request.agent(app);
    let login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect((await agent.get('/incentives/settings')).text).toContain('Incentive cut-off day');
    expect((await agent.get('/associates')).text).toContain('Add an associate');
    const payoutsPage = (await agent.get('/incentives/payouts?month=2026-11')).text;
    expect(payoutsPage).toContain('November 2026 incentive');
    expect(payoutsPage).toContain('23,272.73');
    expect(payoutsPage.replace(/<[^>]*>/g, '').toLowerCase()).not.toContain('profit'); // Accounts never sees profit

    const staff = request.agent(app);
    const staffEmail = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: people.logistics1 })).email;
    login = await staff.get('/login');
    await staff.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: staffEmail, password: 'JestIncentive1!' });
    const mine = (await staff.get('/my-incentives')).text;
    expect(mine).toContain('3,000');
    expect(mine).not.toContain('23,273'); // only their own amount
    expect(mine.replace(/<[^>]*>/g, '').toLowerCase()).not.toContain('profit');
  });

  it('saving the settings form stores each person\'s incentive roles', async () => {
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const page = await agent.get('/incentives/settings');
    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    // Sent exactly as a browser sends it, through the real form parser.
    const form = (salesShare) => [
      `_csrf=${encodeURIComponent(csrf)}`, 'program_enabled=1', 'min_profit_inr=300000', 'cutoff_day=20', 'new_client_bonus_inr=10000',
      'table_from%5B0%5D=300000', 'table_reward%5B0%5D=40000', 'table_from%5B1%5D=500000', 'table_reward%5B1%5D=60000',
      'table_from%5B2%5D=', 'table_reward%5B2%5D=', 'table_from%5B3%5D=', 'table_reward%5B3%5D=', 'table_from%5B4%5D=', 'table_reward%5B4%5D=',
      `split%5Bsales%5D=${salesShare}`, 'split%5Bexport%5D=20', 'split%5Bmarketing%5D=15', 'split%5Blogistics%5D=15', 'split%5Baccounts%5D=10',
      `user_roles%5Bu${people.sales}%5D%5B%5D=sales`, `user_roles%5Bu${people.sales}%5D%5B%5D=marketing`, `user_roles%5Bu${people.accounts}%5D%5B%5D=accounts`,
    ].join('&');
    await agent.post('/incentives/settings').type('form').send(form(40));
    const roles = await incentiveRepository.userRoles();
    expect(Object.fromEntries(Object.entries(roles).map(([k, v]) => [k, [...v].sort()]))).toEqual({ [people.sales]: ['marketing', 'sales'], [people.accounts]: ['accounts'] });

    await agent.post('/incentives/settings').type('form').send(form(45));
    expect((await agent.get('/incentives/settings')).text).toContain('The role shares must add up to 100%'); // refused
  });

  it('only you and the MD manage incentives, Accounts pays and staff see their own', async () => {
    const can = async (roleName, perm) => {
      const u = await createTestUser(roleName);
      return !!(await permissionRepository.effectivePermissions(u.id, u.roleId))[perm];
    };
    expect(await can('Managing Director', 'manage_incentives')).toBe(true);
    expect(await can('Executive Director', 'manage_incentives')).toBe(false);
    expect(await can('Accounts Executive', 'manage_incentives')).toBe(false);
    expect(await can('Accounts Executive', 'pay_incentives')).toBe(true);
    expect(await can('Export Executive', 'pay_incentives')).toBe(false);
    expect(await can('Logistics Executive', 'view_own_incentives')).toBe(true);
    expect(await can('CA / Chartered Accountant', 'view_own_incentives')).toBe(false);
  });
});
