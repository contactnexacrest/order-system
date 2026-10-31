'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const departmentRepository = require('../../src/repositories/departmentRepository');
const purchasingRepository = require('../../src/repositories/purchasingRepository');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PurchasingTest.php. Owner decision D-214 (2026-10-31), phase 3:
 * Procurement. Sales raises a requirement (no prices), Procurement records
 * quotes, compares, awards (an award above the designation's approval limit
 * waits for an approver who is not the awarder), and records receipt with
 * the quality check.
 */
describe('Procurement (D-214 phase 3)', () => {
  const PASSWORD = 'JestPurchasing!1';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const inDays = (n) => { const d = new Date(`${todayIst()}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  let orderId;
  let supplierA;
  let supplierB;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createSupplier(name) {
    return (await db.execute('INSERT INTO suppliers (supplier_legal_name, is_active) VALUES (:n, 1)', { n: `${name} ${crypto.randomBytes(3).toString('hex')}` })).insertId;
  }

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-pur-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function signIn(user) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  /** A user working as a designation (its role's rights and approval limit), on the order. */
  async function member(designation, limit) {
    const d = await db.queryOne('SELECT dd.id, r.name FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE dd.title = :t', { t: designation });
    await db.execute('UPDATE department_designations SET approval_limit_inr = :l WHERE id = :id', { l: limit, id: d.id });
    const user = await createUser(d.name);
    await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: user.id });
    await departmentRepository.addMembership(user.id, d.id, todayIst(), user.id);
    const agent = await signIn(user);
    agent.userId = user.id;
    return agent;
  }

  async function post(agent, page, path, fields) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(path).type('form').send({ _csrf: csrf, ...fields });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') } : { type: null, message: '' };
  }

  async function raise() {
    const admin = await signIn(await createUser('Admin'));
    await post(admin, `/orders/${orderId}`, `/orders/${orderId}/purchase-requirements`, { description: 'Black granite slabs 2cm', quantity: '120', unit: 'sqm', needed_by: inDays(20) });
    return Number((await db.queryOne('SELECT MAX(id) AS id FROM purchase_requirements WHERE order_id = :o', { o: orderId })).id);
  }

  beforeEach(async () => {
    orderId = await createTestOrder(await createTestClient(), 'FOB');
    supplierA = await createSupplier('Quarry A');
    supplierB = await createSupplier('Quarry B');
  });

  it('lets Sales raise a requirement, with checks', async () => {
    const sales = await signIn(await createUser('Export Executive'));
    await db.execute('INSERT INTO order_assignees (order_id, user_id) SELECT :o, id FROM users ORDER BY id DESC LIMIT 1', { o: orderId });
    const page = `/orders/${orderId}`;
    const path = `/orders/${orderId}/purchase-requirements`;
    const body = { description: 'Black granite slabs 2cm', quantity: '120', unit: 'sqm', needed_by: inDays(20) };
    expect((await post(sales, page, path, { ...body, quantity: '0' })).message).toBe('Enter the quantity needed (more than 0).');
    expect((await post(sales, page, path, { ...body, needed_by: '2020-01-01' })).message).toBe('Enter the date it is needed by (today or later).');
    const result = await post(sales, page, path, body);
    expect(result.type).toBe('success');
    expect(result.message).toMatch(/^Purchase requirement PR-\d+ raised for Procurement: Black granite slabs 2cm, needed by \d{4}-\d{2}-\d{2}\.$/);
    const r = (await purchasingRepository.forOrder(orderId))[0];
    expect(r.status).toBe('open');
    expect(String(r.quantity)).toBe('120.000');
  });

  it('compares quotes cheapest-with-GST first and approves an award within the limit', async () => {
    const id = await raise();
    const mgr = await member('Procurement Manager', 500000);
    const page = `/procurement/${id}`;
    expect((await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '40', lead_time_days: '14' })).message).toBe('Enter the GST % (0 to 28).');
    await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '18', lead_time_days: '14' });
    await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierB, unit_price_inr: '1450', gst_percent: '18', lead_time_days: '21' });
    const quotes = await purchasingRepository.quotes(id);
    expect(quotes.map((q) => Number(q.supplier_id))).toEqual([supplierB, supplierA]);
    expect((await purchasingRepository.find(id)).status).toBe('quoted');

    const result = await post(mgr, page, `/procurement/${id}/award`, { quote_id: String(quotes[0].id) });
    expect(result.message).toContain('for ₹205,320.00. Approved — within your approval limit.');
    expect((await purchasingRepository.find(id)).status).toBe('approved');
  });

  it('makes an award above the limit wait for another approver', async () => {
    const id = await raise();
    const page = `/procurement/${id}`;
    const exec = await member('Procurement Executive', 0);
    await post(exec, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '18', lead_time_days: '14' });
    const quote = (await purchasingRepository.quotes(id))[0];
    expect((await post(exec, page, `/procurement/${id}/award`, { quote_id: String(quote.id) })).message).toContain('It is above your approval limit and waits for an approver.');
    expect((await purchasingRepository.find(id)).status).toBe('awarded');
    // The route itself refuses a person without "Approve purchases".
    const csrf = csrfOf((await exec.get(page)).text);
    expect((await exec.post(`/procurement/${id}/approve`).type('form').send({ _csrf: csrf })).status).toBe(403);

    const mgr = await member('Procurement Manager', 100000);
    expect((await post(mgr, page, `/procurement/${id}/approve`, {})).message).toBe('This award (₹212,400.00) is above your approval limit of ₹100,000.00 — a higher approver must approve it.');

    const dir = await member('Procurement Director', null);
    expect((await post(dir, page, `/procurement/${id}/approve`, {})).message).toBe(`Purchase PR-${id} approved: ${quote.supplier_legal_name}, ₹212,400.00.`);
    const r = await purchasingRepository.find(id);
    expect(r.status).toBe('approved');
    expect(Number(r.approved_by)).not.toBe(exec.userId);
  });

  it('records receipt with the quality check, which feeds supplier performance', async () => {
    const id = await raise();
    const page = `/procurement/${id}`;
    const mgr = await member('Procurement Manager', 500000); // R7-N01: a manager with no limit set no longer self-approves
    await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '18', lead_time_days: '14' });
    await post(mgr, page, `/procurement/${id}/award`, { quote_id: String((await purchasingRepository.quotes(id))[0].id) });
    const body = { received_quantity: '118', received_on: todayIst(), qc_result: 'passed_with_remarks' };
    expect((await post(mgr, page, `/procurement/${id}/receive`, body)).message).toBe('Say what the quality check found (at least 5 characters).');
    expect((await post(mgr, page, `/procurement/${id}/receive`, { ...body, qc_note: '2 slabs chipped at edge' })).message).toBe(`Receipt recorded for PR-${id}: quality check passed with remarks.`);
    const perf = (await purchasingRepository.supplierPerformance()).find((s) => Number(s.id) === supplierA);
    expect([perf.quotes_given, perf.awards_won, perf.received, perf.on_time, perf.qc_failed].map(Number)).toEqual([1, 1, 1, 1, 0]);
  });

  /** A user who holds a designation's role directly, with no department membership (QA Round 7, R7-N01). */
  async function roleOnly(designation, limit) {
    const d = await db.queryOne('SELECT dd.id, r.name FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE dd.title = :t', { t: designation });
    await db.execute('UPDATE department_designations SET approval_limit_inr = :l WHERE id = :id', { l: limit, id: d.id });
    const user = await createUser(d.name);
    await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: user.id });
    const agent = await signIn(user);
    agent.userId = user.id;
    return agent;
  }

  // QA Round 7, R7-N01 (High): the approval limit applies whatever the department
  // state; with no known limit below director level nothing self-approves (fail closed).
  it('applies the approval limit without a department membership', async () => {
    const id = await raise();
    const page = `/procurement/${id}`;
    const mgr = await roleOnly('Procurement Manager', 100000);
    await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '18', lead_time_days: '14' });
    const quote = (await purchasingRepository.quotes(id))[0];
    expect((await post(mgr, page, `/procurement/${id}/award`, { quote_id: String(quote.id) })).message).toContain('It is above your approval limit and waits for an approver.');
    expect((await purchasingRepository.find(id)).status).toBe('awarded');

    const mgr2 = await roleOnly('Procurement Manager', 100000);
    expect((await post(mgr2, page, `/procurement/${id}/approve`, {})).message).toBe('This award (₹212,400.00) is above your approval limit of ₹100,000.00 — a higher approver must approve it.');

    const id2 = await raise();
    const page2 = `/procurement/${id2}`;
    const mgr3 = await roleOnly('Procurement Manager', null);
    await post(mgr3, page2, `/procurement/${id2}/quotes`, { supplier_id: supplierA, unit_price_inr: '10', gst_percent: '0', lead_time_days: '14' });
    const q2 = (await purchasingRepository.quotes(id2))[0];
    expect((await post(mgr3, page2, `/procurement/${id2}/award`, { quote_id: String(q2.id) })).message).toContain('It is above your approval limit and waits for an approver.');
    const mgr4 = await roleOnly('Procurement Manager', null);
    expect((await post(mgr4, page2, `/procurement/${id2}/approve`, {})).message).toMatch(/^This award \(₹1,200\.00\) is above your approval limit of ₹0\.00/);

    const dir = await roleOnly('Procurement Director', null);
    expect((await post(dir, page, `/procurement/${id}/approve`, {})).message).toMatch(new RegExp(`^Purchase PR-${id} approved`));
  });

  // QA Round 7, R7-N12: a double submit or a second person acting on the same
  // purchase changes nothing — a duplicate raise is refused, and an award,
  // approval or receipt applies only from the state it expects.
  it('applies each procurement step only once under a double submit', async () => {
    const admin = await signIn(await createUser('Admin'));
    const body = { description: 'Black granite slabs 2cm', quantity: '120', unit: 'sqm', needed_by: inDays(20) };
    await post(admin, `/orders/${orderId}`, `/orders/${orderId}/purchase-requirements`, body);
    const id = Number((await db.queryOne('SELECT MAX(id) AS id FROM purchase_requirements WHERE order_id = :o', { o: orderId })).id);
    const again = await post(admin, `/orders/${orderId}`, `/orders/${orderId}/purchase-requirements`, body);
    expect(again.type).toBe('warning');
    expect(again.message).toBe(`The same requirement is already open as PR-${id} — nothing new raised.`);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM purchase_requirements WHERE order_id = :o', { o: orderId })).n)).toBe(1);

    const mgr = await member('Procurement Manager', 500000);
    const page = `/procurement/${id}`;
    await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '18', lead_time_days: '14' });
    const quoteId = Number((await purchasingRepository.quotes(id))[0].id);
    await post(mgr, page, `/procurement/${id}/award`, { quote_id: String(quoteId) });
    expect((await purchasingRepository.find(id)).status).toBe('approved');
    // A stale second award or approval no longer applies.
    expect(await purchasingRepository.award(id, quoteId, mgr.userId, true)).toBe(false);
    expect(await purchasingRepository.approve(id, mgr.userId)).toBe(false);
    expect((await purchasingRepository.find(id)).status).toBe('approved');

    const receipt = { received_quantity: '118', received_on: todayIst(), qc_result: 'passed' };
    expect((await post(mgr, page, `/procurement/${id}/receive`, receipt)).type).toBe('success');
    expect((await post(mgr, page, `/procurement/${id}/receive`, receipt)).type).toBe('error');
    expect(await purchasingRepository.receive(id, 118, todayIst(), 'passed', null, mgr.userId)).toBe(false);
  });

  it('answers 404 for a requirement on an order the person cannot see', async () => {
    const id = await raise();
    const outsider = await signIn(await createUser('Procurement Executive'));
    expect((await outsider.get(`/procurement/${id}`)).status).toBe(404);
  });

  // QA Round 7, R7-N07: the Supplier performance link shows only to whoever can open it (manage_purchasing).
  it('shows the Suppliers link only with manage_purchasing', async () => {
    const roleRepository = require('../../src/repositories/roleRepository');
    const roleId = await roleRepository.create(`Approver Only ${crypto.randomBytes(3).toString('hex')}`, null);
    for (const key of ['approve_purchases', 'view_orders']) {
      await db.execute('INSERT INTO role_permissions (role_id, permission_id, is_enabled) SELECT :r, id, 1 FROM permissions WHERE permission_key = :k', { r: roleId, k: key });
    }
    const approver = await createUser('Admin');
    await db.execute('UPDATE users SET role_id = :r WHERE id = :u', { r: roleId, u: approver.id });
    for (const [user, sees] of [[approver, false], [await createUser('Procurement Manager'), true]]) {
      const agent = await signIn(user);
      const html = (await agent.get('/procurement')).text;
      expect([sees, html.includes('href="/procurement/suppliers"')]).toEqual([sees, sees]);
    }
  });
});
