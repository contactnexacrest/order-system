'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const divisionService = require('../../src/services/divisionService');
const reportRepository = require('../../src/repositories/reportRepository');
const dashboardRepository = require('../../src/repositories/dashboardRepository');
const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const cafinExportService = require('../../src/services/cafinExportService');
const referenceNumberService = require('../../src/services/referenceNumberService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const financialYear = require('../../src/helpers/financialYear');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DivisionIsolationTest.php. Owner decision D-257 (5 Nov 2026):
 * divisions. A second division (DOM, "planned" in the seed) is made ACTIVE
 * inside this test only, to prove: its staff never see Export's orders,
 * clients, reports, counts, tasks or CSV rows, and the reverse;
 * company-level people see both and "All"; the switcher, the report
 * Division filter and the user Division field appear only with two or
 * more active divisions; numbering is separate and Export's next number
 * unchanged; a division's own setting applies only to it; a module
 * switched off hides its menu and refuses its address.
 */
describe('D-257 divisions — isolation', () => {
  const DOM = 2;
  let expStaff;
  let domStaff;
  let md;
  let expClient;
  let domClient;
  let expOrder;
  let domOrder;
  let country;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName, divisionId = 1) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-div-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestDivision123!';
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Division User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id, d: divisionId }
    );
    return { id: result.insertId, email, password };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  async function csrfOf(agent, path = '/') {
    return (await agent.get(path)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  async function ref(orderId) {
    return (await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id: orderId })).order_reference;
  }

  async function activateSecondDivision() {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: DOM });
  }

  /** Runs fn as a request of this scope would (what sessionAuth sets up). */
  function asScope(ids, fn, seesAll = false) {
    return requestContext.run({ userId: 1, divisionIds: ids, divisionSeesAll: seesAll }, fn);
  }

  beforeEach(async () => {
    expStaff = await createUser('Sales/Export Manager', 1);
    domStaff = await createUser('Sales/Export Manager', DOM);
    md = await createUser('Managing Director', null);
    expClient = await createTestClient();
    domClient = await createTestClient();
    await db.execute('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, 1)', { c: expClient });
    await db.execute('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)', { c: domClient, d: DOM });
    expOrder = await createTestOrder(expClient);
    domOrder = await createTestOrder(domClient);
    country = `Divisionland ${Math.random().toString(16).slice(2, 8)}`;
    await db.execute('UPDATE orders SET division_id = :d, country_of_destination = :c WHERE id = :id', { d: DOM, c: country, id: domOrder });
  });

  afterEach(async () => {
    // Back to the seeded state: one active division; this test's records become ordinary Export test records.
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.execute('UPDATE orders SET division_id = 1 WHERE division_id <> 1');
    await db.execute('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
    await db.execute('DELETE FROM client_divisions WHERE division_id <> 1');
    await db.execute("DELETE FROM division_settings WHERE setting_key IN ('fdn_overdue_days_c', 'require_product_pick')"); // only this test's rows: the seed holds DOM, IMP and MTT settings
    await db.execute("UPDATE division_modules SET is_enabled = 1 WHERE module_key = 'supplier_purchase' AND division_id IN (1, :d)", { d: DOM });
  });

  it('with one active division nothing changes: no switcher, no Division field; Admin › Divisions is the only visible place', async () => {
    expect(await divisionService.isMulti()).toBe(false);
    const mdAgent = await loginAs(md);
    const page = (await mdAgent.get('/')).text;
    expect(page).not.toContain('division-switcher');
    expect(page).not.toContain('All divisions');
    // D-320: the left menu is short; Divisions sits in the Settings section's menu bar, shown on the Settings pages.
    expect(page).not.toContain('href="/admin/divisions"');
    expect((await mdAgent.get('/settings')).text).toContain('href="/admin/divisions"');
    const staffAgent = await loginAs(expStaff);
    const staffPage = (await staffAgent.get('/')).text;
    expect(staffPage).not.toContain('division-switcher');
    expect(staffPage).not.toContain('/admin/divisions');
    const admin = await createUser('Admin', null);
    const edit = (await (await loginAs(admin)).get(`/users/${expStaff.id}/edit`)).text;
    expect(edit).not.toContain('user-division');
    const divisions = (await mdAgent.get('/admin/divisions')).text;
    expect(divisions).toContain('opacity:.6');
  });

  it('staff of each division see only their own orders, clients, reports, counts, tasks and CSV rows', async () => {
    await activateSecondDivision();
    for (const orderId of [expOrder, domOrder]) {
      await db.execute('UPDATE order_payment_status SET advance_amount = 100, advance_remittance_received_at = NOW() WHERE order_id = :o', { o: orderId });
      await db.execute("INSERT INTO email_log (order_id, recipient_email, subject, body_snapshot, status) VALUES (:o, 'buyer@division.test', 'Jest division', 'x', 'pending_approval')", { o: orderId });
    }
    await db.execute(`UPDATE orders SET status = 'active', is_test_data = 0, is_sample_data = 0 WHERE id IN (${expOrder}, ${domOrder})`);
    const cases = [[expStaff, 1, expOrder, domOrder, expClient, domClient], [domStaff, DOM, domOrder, expOrder, domClient, expClient]];
    for (const [user, division, own, other, ownClient, otherClient] of cases) {
      const agent = await loginAs(user);
      const list = (await agent.get('/orders')).text;
      expect(list).toContain(await ref(own));
      expect(list).not.toContain(await ref(other));
      expect((await agent.get(`/orders/${other}`)).status).toBe(403);
      expect((await agent.get(`/orders/${other}/edit`)).status).toBe(403);
      expect((await agent.get(`/reports/order/${other}`)).status).toBe(403);
      expect((await agent.get(`/orders/${own}`)).status).toBe(200);
      expect((await agent.get(`/clients/${otherClient}`)).status).toBe(403);

      await asScope([division], async () => {
        const clients = (await clientRepository.all()).map((c) => Number(c.id));
        expect(clients).toContain(ownClient);
        expect(clients).not.toContain(otherClient);
        const payments = (await reportRepository.paymentsReport(null, null)).rows.map((r) => Number(r.id));
        expect(payments).toContain(own);
        expect(payments).not.toContain(other);
        expect((await reportRepository.distinctCountries()).includes(country)).toBe(own === domOrder);
        expect((await dashboardRepository.search('Jest')).map((r) => Number(r.order_id))).not.toContain(other);
        expect((await departmentTaskRepository.open()).map((r) => Number(r.order_id))).not.toContain(other);
        const cafin = await cafinExportService.orderIds(financialYear.current(), 'all');
        expect(cafin).toContain(own);
        expect(cafin).not.toContain(other);
        const before = await emailLogRepository.awaitingDispatchCount();
        await db.execute("INSERT INTO email_log (order_id, recipient_email, subject, body_snapshot, status) VALUES (:o, 'buyer@division.test', 'Jest division 2', 'x', 'pending_approval')", { o: other });
        expect(await emailLogRepository.awaitingDispatchCount()).toBe(before);
        expect((await emailLogRepository.awaitingDispatch()).map((r) => Number(r.order_id))).not.toContain(other);
      });
    }
  });

  it('company-level people see both divisions and can pick one or "All divisions"', async () => {
    await activateSecondDivision();
    const agent = await loginAs(md);
    const home = (await agent.get('/')).text;
    expect(home).toContain('division-switcher');
    expect(home).toContain('All divisions');
    let list = (await agent.get('/orders')).text;
    expect(list).toContain(await ref(expOrder));
    expect(list).toContain(await ref(domOrder));
    let token = await csrfOf(agent);
    await agent.post('/divisions/choose').type('form').send({ _csrf: token, division: String(DOM), back: '/orders' });
    list = (await agent.get('/orders')).text;
    expect(list).toContain(await ref(domOrder));
    expect(list).not.toContain(await ref(expOrder));
    token = await csrfOf(agent);
    await agent.post('/divisions/choose').type('form').send({ _csrf: token, division: 'all', back: '/orders' });
    list = (await agent.get('/orders')).text;
    expect(list).toContain(await ref(expOrder));
    expect(list).toContain(await ref(domOrder));
    expect((await agent.get('/orders/create')).text).toContain('name="division_id"');
    expect((await agent.get('/reports')).text).toContain('report-division-filter');

    const staff = await loginAs(domStaff);
    const staffHome = (await staff.get('/')).text;
    expect(staffHome).not.toContain('division-switcher');
    expect((await staff.get('/reports')).text).not.toContain('report-division-filter');
    await requestContext.run({ userId: domStaff.id, divisionIds: [DOM], divisionSeesAll: false, divisionUser: { division_id: DOM } }, async () => {
      expect(await divisionService.forNewRecord()).toBe(DOM);
    });
  });

  it('the Division field appears only with two or more active divisions', async () => {
    const admin = await createUser('Admin', null);
    const agent = await loginAs(admin);
    expect((await agent.get(`/users/${expStaff.id}/edit`)).text).not.toContain('user-division');
    await activateSecondDivision();
    expect((await agent.get(`/users/${expStaff.id}/edit`)).text).toContain('user-division');
    expect((await agent.get('/admin/departments')).text).toContain('department-division');
  });

  it("numbering is separate per division and Export's next number is unchanged", async () => {
    await activateSecondDivision();
    const d = new Date();
    const key = `order_ref:${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
    const seqOf = async () => Number(((await db.queryOne('SELECT last_seq FROM reference_sequences WHERE scope_key = :k', { k: key })) || { last_seq: 0 }).last_seq);
    const before = await seqOf();
    const dom = await asScope([DOM], () => referenceNumberService.generateOrderReference(false));
    expect(dom.startsWith('DOM/')).toBe(true);
    expect(await seqOf()).toBe(before);
    const exp = await asScope([1], () => referenceNumberService.generateOrderReference(false));
    expect(exp.startsWith('NC/SC/ORD/')).toBe(true);
    expect(exp.endsWith(String(before + 1).padStart(3, '0'))).toBe(true);
  });

  it("a division's own setting applies only to that division", async () => {
    await activateSecondDivision();
    await db.execute(
      "INSERT INTO division_settings (division_id, scope, setting_key, setting_value) VALUES (:d, 'company', 'fdn_overdue_days_c', '99'), (:d2, 'pricing', 'require_product_pick', '0')",
      { d: DOM, d2: DOM }
    );
    const company = await companySettingsRepository.companyValue('fdn_overdue_days_c');
    expect(await asScope([DOM], () => companySettingsRepository.get('fdn_overdue_days_c'))).toBe('99');
    expect(await asScope([1], () => companySettingsRepository.get('fdn_overdue_days_c'))).toBe(company);
    expect((await asScope([DOM], () => pricingRepository.settings())).require_product_pick).toBe('0');
    expect((await asScope([1], () => pricingRepository.settings())).require_product_pick).toBe('1');
  });

  it('a module switched off hides its menu and refuses its address', async () => {
    await activateSecondDivision();
    await db.execute("UPDATE division_modules SET is_enabled = 0 WHERE division_id = :d AND module_key = 'supplier_purchase'", { d: DOM });
    const proc = await createUser('Procurement Manager', DOM);
    const agent = await loginAs(proc);
    const refused = await agent.get('/procurement');
    expect(refused.status).toBe(403);
    expect(refused.text).toContain('not in use here');
    expect((await agent.get('/')).text).not.toContain('href="/procurement"');
    await db.execute('UPDATE users SET division_id = 1 WHERE id = :id', { id: proc.id });
    const expAgent = await loginAs(proc);
    expect((await expAgent.get('/procurement')).status).toBe(200);
    expect((await expAgent.get('/')).text).toContain('href="/procurement"');
  });

  it("Export's document data is exactly the same whether or not a division scope is in force", async () => {
    for (const code of ['QT', 'PI', 'OC', 'CI', 'PL']) {
      const plain = await documentDataAssembler.assemble(expOrder, code);
      const scoped = await asScope([1], () => documentDataAssembler.assemble(expOrder, code));
      delete plain.generated_at;
      delete scoped.generated_at;
      expect(scoped).toEqual(plain);
    }
  });

  it('a planned division is activated only once the pre-flight checklist is done', async () => {
    const agent = await loginAs(md);
    const page = (await agent.get(`/admin/divisions/${DOM}`)).text;
    expect(page).toContain('division-preflight');
    const token = await csrfOf(agent, `/admin/divisions/${DOM}`);
    await agent.post(`/admin/divisions/${DOM}/activate`).type('form').send({ _csrf: token, confirm: '1' });
    expect((await divisionService.find(DOM)).status).toBe('planned');
  });
});
