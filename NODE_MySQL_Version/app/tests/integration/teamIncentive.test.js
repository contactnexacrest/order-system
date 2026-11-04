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
const zohoPurchaseRepository = require('../../src/repositories/zohoPurchaseRepository');
const incentiveService = require('../../src/services/incentiveService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of TeamIncentiveTest.php. Owner decision 2026-10-30, reworked by
 * D-245 P4 (4 Nov 2026): the team incentive is a % of the order's profit
 * after the associate's commission (never below 0) or a fixed amount — global
 * rule, product rule or the MD's amount for one order with a reason; editable
 * roles whose shares add up to 100%; the MD ticks who gets it or sets the
 * shares; nothing locks by itself (the MD locks a ready order, one or all;
 * unlock with a reason until paid); every change in the order's history.
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
    await incentiveRepository.setSetting('program_enabled', '1', admin);
    incentiveRepository.forgetRoles();
    rolesBefore = JSON.parse(JSON.stringify(await incentiveRepository.allRoles()));
  });

  let rolesBefore = {};

  afterEach(async () => {
    await incentiveRepository.replaceUserRoles({});
    await incentiveRepository.setSetting('program_enabled', '0', admin);
    // The roles are shared by every test: put them back as they were.
    for (const key of Object.keys(await incentiveRepository.allRoles())) {
      if (!rolesBefore[key]) await incentiveRepository.deleteRole(key);
    }
    await incentiveRepository.saveRoles(Object.values(rolesBefore), admin);
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
      out[key] = Math.round(((out[key] || 0) + Number(p.amount_inr)) * 100) / 100;
    }
    return out;
  }

  /** Calls an incentive controller action as the given user (default: the admin, who holds manage_pricing). */
  async function call(action, orderId, body, as = null, permissions = { manage_pricing: true, manage_incentives: true }) {
    const controller = require('../../src/controllers/incentiveController');
    const req = { params: { id: String(orderId) }, body, query: {}, session: {}, user: { id: as || admin }, permissions };
    const res = { redirect() {}, status() { return this; }, send() {}, renderView() {} };
    await controller[action](req, res);
    return flash.pull(req)[0] || { type: 'none', message: '' };
  }

  async function history(orderId) {
    return (await incentiveRepository.history(orderId)).map((h) => h.action);
  }

  it('the global rule is a % of profit shared over the roles with people, and nothing locks by itself', async () => {
    const orderId = await paidOrder(await createTestClient());
    const calc = await incentiveService.calculate(orderId);
    const expected = Math.round(calc.basis * 0.15 * 100) / 100;
    expect([calc.source, calc.state, calc.amount, calc.team_total, calc.unallocated]).toEqual(['global', 'ready', expected, expected, 0]);
    // Sales Manager and Export Compliance have nobody on this order: their shares go to the others in proportion (30:15:15:15:10).
    expect(calc.roles.sales_manager.gets_it).toBe(false);
    expect(calc.roles.sales.pct).toBe(Math.round(30 / 85 * 10000) / 100);
    expect(await incentiveRepository.forOrder(orderId)).toBeNull(); // nothing locks by itself, even fully paid
    expect(await incentiveService.readyToLock()).toContain(orderId);

    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    const row = await incentiveRepository.forOrder(orderId);
    const run = await require('../../src/services/associateCommission').payoutRunOn(new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10));
    expect([row.team_total_inr, row.incentive_month, Number(row.locked_by)]).toEqual([expected.toFixed(2), String(run).slice(0, 7), admin]);
    const sum = Object.values(await payouts(orderId)).reduce((a, b) => a + b, 0);
    expect(Math.round(sum * 100) / 100).toBe(expected); // the payouts add up to the incentive exactly
    expect((await incentiveRepository.costEntries(orderId)).team.amount_inr).toBe(expected.toFixed(2));
    expect(await history(orderId)).toEqual(['lock']);
    expect(await incentiveService.state(orderId)).toBe('locked');
  });

  it('the MD sets the order\'s amount with a reason and chooses who gets it', async () => {
    const orderId = await paidOrder(await createTestClient());
    expect((await call('saveOrder', orderId, { incentive_mode: 'fixed', incentive_value: '100000' })).message).toContain('Say why');
    expect((await call('saveOrder', orderId, { incentive_mode: 'fixed', incentive_value: '100000', incentive_reason: 'Large first order, weekend work' })).type).toBe('success');
    let calc = await incentiveService.calculate(orderId);
    expect([calc.source, calc.amount, calc.team_total]).toEqual(['order_fixed', 100000, 100000]);
    expect([calc.roles.sales.amount, calc.roles.export.amount, calc.roles.marketing.amount, calc.roles.logistics.amount, calc.roles.logistics.each, calc.roles.accounts.amount])
      .toEqual([35294.12, 17647.06, 17647.06, 17647.06, 8823.53, 11764.7]);

    // One person does Sales and Export; Marketing and Accounts are unticked: 30:15:15 over Sales, Export, Logistics.
    const flashOk = await call('saveOrderPeople', orderId, { gets_it: { sales: '1', export: '1', logistics: '1' },
      people: { sales: [String(people.sales)], export: [String(people.sales)], logistics: [String(people.logistics1), String(people.logistics2)] },
      people_note: 'Priya did sales and documents' });
    expect(flashOk.type).toBe('success');
    calc = await incentiveService.calculate(orderId);
    expect([calc.roles.sales.amount, calc.roles.export.amount, calc.roles.logistics.amount, calc.roles.logistics.each, calc.roles.marketing.gets_it]).toEqual([50000, 25000, 25000, 12500, false]);
    expect(calc.per_person.find((p) => p.id === people.sales).amount).toBe(75000); // one person, two roles

    // Custom shares must add up to 100%; one person may get it all.
    const post = { gets_it: { sales: '1', export: '1', logistics: '1' }, shares_customised: '1',
      people: { sales: [String(people.sales)], export: [String(people.sales)], logistics: [String(people.logistics1)] } };
    expect((await call('saveOrderPeople', orderId, { ...post, share_pct: { sales: '50', export: '20', logistics: '20' } })).message).toContain('add up to 100%');
    expect((await call('saveOrderPeople', orderId, { ...post, share_pct: { sales: '100', export: '0', logistics: '0' } })).type).toBe('success');
    calc = await incentiveService.calculate(orderId);
    expect([calc.roles.sales.amount, calc.per_person.find((p) => p.id === people.sales).amount]).toEqual([100000, 100000]);

    // A % of this order's profit; more than the profit is refused.
    expect((await call('saveOrder', orderId, { incentive_mode: 'pct', incentive_value: '25', incentive_reason: 'Quarter of the profit this time' })).type).toBe('success');
    expect((await incentiveService.calculate(orderId)).amount).toBe(Math.round(calc.basis * 0.25 * 100) / 100);
    expect((await call('saveOrder', orderId, { incentive_mode: 'fixed', incentive_value: '99999999', incentive_reason: 'Far too much on purpose' })).message).toContain('cannot be more than');
    expect(await history(orderId)).toEqual(['amount', 'people', 'people', 'amount']); // every change is kept (newest first); refused ones are not
  });

  it('the profit used never goes below zero and an unpaid order is only estimated', async () => {
    const orderId = await paidOrder(await createTestClient(), null);
    await db.execute("INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by) VALUES (:o, 'other', 'Jest big cost', 9000000, '2026-10-01', :u)", { o: orderId, u: admin });
    const calc = await incentiveService.calculate(orderId);
    expect(calc.basis_raw).toBeLessThan(0);
    expect([calc.basis, calc.amount, calc.estimated, calc.state]).toEqual([0, 0, true, 'open']);
    expect(await incentiveService.lockIfReady(orderId, admin)).toBe('The buyer has not paid in full yet.');
    expect(await incentiveRepository.forOrder(orderId)).toBeNull();
  });

  it('with the program off the MD may still set an incentive by hand', async () => {
    await incentiveRepository.setSetting('program_enabled', '0', admin);
    const orderId = await paidOrder(await createTestClient());
    let calc = await incentiveService.calculate(orderId);
    expect([calc.source, calc.amount]).toEqual(['off', 0]);
    expect((await call('saveOrder', orderId, { incentive_mode: 'fixed', incentive_value: '20000', incentive_reason: 'Special effort on this order' })).type).toBe('success');
    calc = await incentiveService.calculate(orderId);
    expect([calc.source, calc.amount, calc.team_total]).toEqual(['order_fixed', 20000, 20000]);
    expect(calc.left_for_company).toBeCloseTo(calc.basis - 20000, 2);
  });

  it('below the minimum margin warns and more than the profit cannot be locked', async () => {
    const orderId = await paidOrder(await createTestClient());
    const { basis } = await incentiveService.calculate(orderId);
    await incentiveRepository.saveOrder(orderId, { incentive_mode: 'fixed', incentive_value: basis - 1000 }, admin);
    const calc = await incentiveService.calculate(orderId);
    expect([calc.margin_warning, calc.over_profit]).toEqual([true, false]);
    await incentiveRepository.saveOrder(orderId, { incentive_mode: 'fixed', incentive_value: basis + 100 }, admin);
    expect((await incentiveService.calculate(orderId)).over_profit).toBe(true);
    expect(await incentiveService.lockIfReady(orderId, admin)).toContain('more than the order');
    expect((await incentiveRepository.forOrder(orderId)).locked_at).toBeNull();
  });

  it('lock all, unlock with a reason, and a paid order never changes', async () => {
    const a = await paidOrder(await createTestClient());
    const b = await paidOrder(await createTestClient());
    const result = await incentiveService.lockAllReady(admin);
    expect(result.locked).toBeGreaterThanOrEqual(2);
    expect([await incentiveService.state(a), await incentiveService.state(b)]).toEqual(['locked', 'locked']);
    expect(await incentiveService.readyToLock()).not.toContain(a);

    expect(await incentiveService.unlock(a, admin, 'short')).toContain('Say why');
    expect(await incentiveService.unlock(a, admin, 'A claim came in after locking')).toBeNull();
    expect([await incentiveRepository.payoutsForOrder(a), await incentiveService.state(a)]).toEqual([[], 'ready']);
    expect(await incentiveRepository.costEntries(a)).not.toHaveProperty('team');
    expect(await history(a)).toEqual(['unlock', 'lock']);
    expect((await incentiveRepository.history(a))[0].reason).toBe('A claim came in after locking');

    const rows = await incentiveRepository.payoutsForOrder(b);
    const today = new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
    await incentiveService.markPaid(Number(rows[0].id), today, Number(rows[0].amount_inr), null, admin);
    expect(await incentiveService.state(b)).toBe('paid');
    expect(await incentiveService.unlock(b, admin, 'Trying to change a paid order')).toContain('already paid');
    expect((await call('saveOrder', b, { incentive_mode: 'fixed', incentive_value: '1', incentive_reason: 'Trying to change it' })).message).toContain('locked');
  });

  it('only the MD changes or locks an order\'s incentive', async () => {
    const orderId = await paidOrder(await createTestClient());
    const ed = (await createTestUser('Executive Director')).id;
    const edPerms = { view_price_buildup: true };
    expect((await call('saveOrder', orderId, { incentive_mode: 'fixed', incentive_value: '5000', incentive_reason: 'ED trying to change' }, ed, edPerms)).message).toContain('Only the MD');
    expect((await call('lock', orderId, {}, ed, edPerms)).message).toContain('Only the MD');
    expect((await call('saveOrderPeople', orderId, { gets_it: { sales: '1' } }, ed, edPerms)).message).toContain('Only the MD');
    expect(await incentiveRepository.forOrder(orderId)).toBeNull();
    expect((await call('lock', orderId, {})).type).toBe('success');
  });

  it('the roles are an editable list whose shares add up to 100', async () => {
    const controller = require('../../src/controllers/incentiveController');
    const roles = async (changes) => {
      const out = {};
      for (const [k, r] of Object.entries(await incentiveRepository.allRoles())) {
        out[k] = { label: r.label, share_pct: String(r.share_pct), ...(r.is_active ? { is_active: '1' } : {}) };
      }
      for (const [k, c] of Object.entries(changes)) {
        out[k] = { ...out[k], ...c };
        if (c.is_active === null) delete out[k].is_active;
      }
      return out;
    };
    const save = async (body) => {
      const req = { params: {}, body: { lock_reminder_days: '3', ...body }, query: {}, session: {}, user: { id: admin }, permissions: { manage_pricing: true, manage_incentives: true } };
      await controller.saveSettings(req, { redirect() {} });
      incentiveRepository.forgetRoles();
      return flash.pull(req)[0];
    };
    const del = async (key) => {
      const req = { params: { key }, body: {}, query: {}, session: {}, user: { id: admin }, permissions: { manage_pricing: true, manage_incentives: true } };
      await controller.deleteRole(req, { redirect() {} });
      incentiveRepository.forgetRoles();
      return flash.pull(req)[0];
    };
    expect((await save({ roles: await roles({ sales: { share_pct: '35' } }) })).message).toContain('add up to 100%');
    expect((await save({ roles: await roles({ sales: { share_pct: '25' } }), new_role_label: 'Quality Check', new_role_share: '5' })).type).toBe('success');
    const all = await incentiveRepository.allRoles();
    expect([(await incentiveRepository.roles()).quality_check, all.quality_check.share_pct, all.sales.share_pct]).toEqual(['Quality Check', 5, 25]);
    expect((await del('sales')).message).toContain('in use'); // used by people: only deactivate
    expect((await del('quality_check')).message).toContain('Set the role');
    expect((await save({ roles: await roles({ sales: { share_pct: '30' }, quality_check: { share_pct: '0', is_active: null } }) })).type).toBe('success');
    expect((await del('quality_check')).type).toBe('success');
    expect(await incentiveRepository.allRoles()).not.toHaveProperty('quality_check');
  });

  it('the associate\'s commission is deducted first and locks with the order', async () => {
    // D-245 P3: the associate on the ladder — step 1 (Level 1, 2%) of the invoiced FOB of the goods.
    const associate = await associateRepository.create({ name: 'Jest Associate', based_in: 'abroad', country: 'USA', currency_code: 'USD',
      email: null, phone: null, tax_id: null, agreement_signed_on: '2026-09-01', status: 'active', notes: null }, admin);
    const client = await createTestClient();
    await associateRepository.linkClient(client, associate, '2026-09-05');

    const orderId = await paidOrder(client);
    const profitBefore = (await orderProfitabilityService.computeForOrder(orderId)).profit_inr;
    const calc = await incentiveService.calculate(orderId);
    expect([calc.associate.level_label, calc.associate.rate, calc.associate.amount_fc, calc.associate.currency]).toEqual(['Level 1 — 2%', 2, 90, 'USD']);
    expect(calc.associate.amount_inr).toBeCloseTo(8000, 2); // USD 90.00 at the ₹88.89 actually received per dollar
    expect(calc.basis).toBeCloseTo(profitBefore - 8000, 2); // the team incentive is worked out after the commission
    expect(calc.amount).toBeCloseTo((profitBefore - 8000) * 0.15, 2);

    await incentiveService.lockIfReady(orderId, admin);
    expect((await payouts(orderId)).associate).toBeCloseTo(8000, 2); // the commission locks together with the order
    expect((await incentiveRepository.costEntries(orderId)).associate.amount_inr).toBe('8000.00');

    const later = await paidOrder(client);
    await db.execute('UPDATE orders SET created_at = DATE_ADD(created_at, INTERVAL 15 DAY) WHERE id = :id', { id: later });
    expect((await incentiveService.calculate(later)).associate).toBeNull(); // after day 10 it is a repeat order: no commission
    await incentiveRepository.saveOrder(later, { associate_mode: 'yes' }, admin);
    expect((await incentiveService.calculate(later)).associate.amount_fc).toBe(90); // forced on: commission again
  });

  it('the payout month is the payout run after the date', async () => {
    await incentiveRepository.setSetting('associate_payout_run', 'monthly', admin);
    await incentiveRepository.setSetting('associate_payout_day', '5', admin);
    expect(await incentiveService.incentiveMonth('2026-10-05')).toBe('2026-10');
    expect(await incentiveService.incentiveMonth('2026-10-06')).toBe('2026-11');
    expect(await incentiveService.incentiveMonth('2026-12-25')).toBe('2027-01');
  });

  it('the MD is reminded before the payout run of orders ready to lock', async () => {
    await incentiveRepository.setSetting('associate_payout_run', 'monthly', admin);
    await incentiveRepository.setSetting('associate_payout_day', '5', admin);
    await incentiveRepository.setSetting('lock_reminder_days', '3', admin);
    await incentiveRepository.setSetting('lock_reminder_sent_for', '', admin);
    await paidOrder(await createTestClient());
    expect(await incentiveService.lockReminders('2026-11-01')).toBe(0); // not 3 days before the run
    expect(await incentiveService.lockReminders('2026-11-02')).toBeGreaterThanOrEqual(1);
    expect(await incentiveService.lockReminders('2026-11-02')).toBe(0); // once per run
    const count = await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'incentive_lock_reminder'", { u: admin });
    expect(Number(count.n)).toBe(1);
    await incentiveRepository.setSetting('lock_reminder_sent_for', '', admin);
  });

  it('nothing is ready until the buyer has fully paid', async () => {
    const orderId = await paidOrder(await createTestClient(), null);
    expect(await incentiveService.fullyPaidOn(orderId)).toBeNull();
    expect(await incentiveService.lockIfReady(orderId, admin)).not.toBeNull();
    expect(await incentiveRepository.forOrder(orderId)).toBeNull();
    expect(await incentiveRepository.payoutsForOrder(orderId)).toEqual([]);
  });

  it('paying everyone sends the team incentive to Zoho Books as paid', async () => {
    const orderId = await paidOrder(await createTestClient());
    await db.execute("UPDATE orders SET company_legal_name = 'TEST-Incentive Sync Buyer' WHERE id = :o", { o: orderId });
    const toSync = async () => (await zohoPurchaseRepository.costEntriesToSync(null)).map((r) => Number(r.id));
    await incentiveService.lockIfReady(orderId, admin);
    const total = (await incentiveRepository.forOrder(orderId)).team_total_inr;
    expect(await toSync()).not.toContain(Number((await incentiveRepository.costEntries(orderId)).team.id)); // locked but not paid: not picked up by the Zoho sync
    const rows = await incentiveRepository.payoutsForOrder(orderId);
    const today = new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
    await incentiveService.markPaid(Number(rows[0].id), today, Number(rows[0].amount_inr), null, admin);
    expect((await incentiveRepository.costEntries(orderId)).team.paid_from).toBeNull(); // not all paid yet: not sent
    for (const r of rows.slice(1)) {
      await incentiveService.markPaid(Number(r.id), today, Number(r.amount_inr), null, admin);
    }
    const entry = (await incentiveRepository.costEntries(orderId)).team;
    expect([entry.paid_from, String(entry.incurred_at).slice(0, 10), entry.amount_inr]).toEqual(['company_account', today, total]);
    expect(String(entry.description)).toMatch(/^Team incentive for order/); // D-212: says what it is and for which order
    expect(await toSync()).toContain(Number(entry.id)); // all paid: the sync sends it as a Books expense
  });

  it('the incentive cost line cannot be removed by hand', async () => {
    const orderId = await paidOrder(await createTestClient());
    await incentiveService.lockIfReady(orderId, admin);
    const entryId = Number((await incentiveRepository.costEntries(orderId)).team.id);
    const req = { params: { id: String(orderId), entryId: String(entryId) }, body: {}, query: {}, session: {}, user: { id: admin }, permissions: {} };
    const res = { redirect() {}, status() { return this; }, send() {} };
    await orderFinancialsController.deleteCostEntry(req, res);
    expect(flash.pull(req)[0].type).toBe('error');
    expect(await incentiveRepository.costEntries(orderId)).toHaveProperty('team');
  });

  it('every page opens and staff see only their own amounts', async () => {
    const orderId = await paidOrder(await createTestClient());
    await incentiveService.lockIfReady(orderId, admin);
    const month = (await incentiveRepository.forOrder(orderId)).incentive_month;
    const agent = request.agent(app);
    let login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect((await agent.get('/incentives/settings')).text).toContain('Incentive roles and their usual shares');
    expect((await agent.get('/incentives/ready')).text).toContain('Ready to lock');
    expect((await agent.get('/associates')).text).toContain('Add an associate');
    const payoutsPage = (await agent.get(`/incentives/payouts?month=${month}`)).text;
    expect(payoutsPage).toContain(`${incentiveService.monthLabel(month)} payout run`);
    expect(payoutsPage.replace(/<[^>]*>/g, '').toLowerCase()).not.toContain('profit'); // Accounts never sees profit

    const own = (await payouts(orderId));
    const fmt0 = (v) => Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 });
    const staff = request.agent(app);
    const staffEmail = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: people.logistics1 })).email;
    login = await staff.get('/login');
    await staff.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: staffEmail, password: 'JestIncentive1!' });
    const mine = (await staff.get('/my-incentives')).text;
    expect(mine).toContain(fmt0(own.logistics1));
    expect(mine).not.toContain(fmt0(own.sales)); // only their own amount
    expect(mine.replace(/<[^>]*>/g, '').toLowerCase()).not.toContain('profit');
  });

  it('saving the settings form stores each person\'s incentive roles', async () => {
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const page = await agent.get('/incentives/settings');
    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    // Sent exactly as a browser sends it, through the real form parser.
    const form = (days) => [
      `_csrf=${encodeURIComponent(csrf)}`, 'program_enabled=1', `lock_reminder_days=${days}`, 'user_roles_present=1',
      `user_roles%5Bu${people.sales}%5D%5B%5D=sales`, `user_roles%5Bu${people.sales}%5D%5B%5D=marketing`, `user_roles%5Bu${people.accounts}%5D%5B%5D=accounts`,
    ].join('&');
    await agent.post('/incentives/settings').type('form').send(form(3));
    const roles = await incentiveRepository.userRoles();
    expect(Object.fromEntries(Object.entries(roles).map(([k, v]) => [k, [...v].sort()]))).toEqual({ [people.sales]: ['marketing', 'sales'], [people.accounts]: ['accounts'] });

    await agent.post('/incentives/settings').type('form').send(form(40));
    expect((await agent.get('/incentives/settings')).text).toContain('0 to 28 days'); // refused
  });

  it('only you and the MD manage incentives, Accounts pays and staff see their own', async () => {
    const can = async (roleName, perm) => {
      const u = await createTestUser(roleName);
      return !!(await permissionRepository.effectivePermissions(u.id, u.roleId))[perm];
    };
    expect(await can('Managing Director', 'manage_incentives')).toBe(true);
    expect(await can('Managing Director', 'manage_pricing')).toBe(true);
    expect(await can('Executive Director', 'manage_incentives')).toBe(false);
    expect(await can('Executive Director', 'manage_pricing')).toBe(false);
    expect(await can('Accounts Executive', 'manage_incentives')).toBe(false);
    expect(await can('Accounts Executive', 'pay_incentives')).toBe(true);
    expect(await can('Export Executive', 'pay_incentives')).toBe(false);
    expect(await can('Logistics Executive', 'view_own_incentives')).toBe(true);
    expect(await can('CA / Chartered Accountant', 'view_own_incentives')).toBe(false);
  });
});
