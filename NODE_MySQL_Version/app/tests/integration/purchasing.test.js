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
    const mgr = await member('Procurement Manager', null);
    await post(mgr, page, `/procurement/${id}/quotes`, { supplier_id: supplierA, unit_price_inr: '1500', gst_percent: '18', lead_time_days: '14' });
    await post(mgr, page, `/procurement/${id}/award`, { quote_id: String((await purchasingRepository.quotes(id))[0].id) });
    const body = { received_quantity: '118', received_on: todayIst(), qc_result: 'passed_with_remarks' };
    expect((await post(mgr, page, `/procurement/${id}/receive`, body)).message).toBe('Say what the quality check found (at least 5 characters).');
    expect((await post(mgr, page, `/procurement/${id}/receive`, { ...body, qc_note: '2 slabs chipped at edge' })).message).toBe(`Receipt recorded for PR-${id}: quality check passed with remarks.`);
    const perf = (await purchasingRepository.supplierPerformance()).find((s) => Number(s.id) === supplierA);
    expect([perf.quotes_given, perf.awards_won, perf.received, perf.on_time, perf.qc_failed].map(Number)).toEqual([1, 1, 1, 1, 0]);
  });

  it('answers 404 for a requirement on an order the person cannot see', async () => {
    const id = await raise();
    const outsider = await signIn(await createUser('Procurement Executive'));
    expect((await outsider.get(`/procurement/${id}`)).status).toBe(404);
  });
});
