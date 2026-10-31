'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const departmentRepository = require('../../src/repositories/departmentRepository');
const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const purchasingRepository = require('../../src/repositories/purchasingRepository');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CoordinationTest.php. Owner decision D-214 (2026-10-31), phase 4:
 * the system coordinates. Each department's tasks come from the order's own
 * state (one step done = the next department's task appears), with due and
 * escalation days from Admin; one owner per department per order, chosen by
 * that department's manager.
 */
describe('Coordination (D-214 phase 4)', () => {
  const PASSWORD = 'JestCoordination!1';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const shiftDays = (n) => { const d = new Date(`${todayIst()}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  let orderId;

  afterAll(async () => {
    await db.execute("UPDATE department_task_types SET standard_days = 1, escalate_after_days = 2 WHERE task_key = 'clear_buyer_payment'");
    await db.pool.end();
  });

  beforeEach(async () => {
    orderId = await createTestOrder(await createTestClient(), 'FOB');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
  });

  async function taskKeys(departments = null) {
    return (await departmentTaskRepository.open(departments)).filter((t) => Number(t.order_id) === orderId).map((t) => t.task_key);
  }

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-coord-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function member(designation) {
    const d = await db.queryOne('SELECT dd.id, r.name FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE dd.title = :t', { t: designation });
    const user = await createUser(d.name);
    await departmentRepository.addMembership(user.id, d.id, todayIst(), user.id);
    return user;
  }

  async function signIn(user) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  async function post(agent, page, path, fields) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(path).type('form').send({ _csrf: csrf, ...fields });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') } : { type: null, message: '' };
  }

  it('hands the next task to the next department when a step is done', async () => {
    expect(await taskKeys()).toEqual([]);

    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000, todayIst());
    expect(await taskKeys()).toEqual(['clear_buyer_payment']);
    expect(await taskKeys(['FIN'])).toEqual(['clear_buyer_payment']);
    expect(await taskKeys(['SALES', 'LOG'])).toEqual([]);

    await db.execute('UPDATE order_payment_status SET advance_cleared_at = NOW() WHERE order_id = :o', { o: orderId });
    expect(await taskKeys()).toEqual(['raise_purchase_requirement']); // Finance done; Sales raises the purchase requirement

    const user = await createUser('Admin');
    const id = await purchasingRepository.create(orderId, null, 'Granite slabs', null, 50, 'sqm', shiftDays(10), user.id, null);
    expect(await taskKeys()).toEqual(['quote_and_award']); // Procurement gets quotes

    await purchasingRepository.setStatus(id, 'cancelled');
    expect(await taskKeys()).toEqual(['raise_purchase_requirement']);
  });

  it('marks a task overdue after its days and escalated after the escalation days', async () => {
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000, shiftDays(-2));
    await db.execute('UPDATE order_payment_status SET advance_remittance_received_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE order_id = :o', { o: orderId });
    const task = async () => (await departmentTaskRepository.open(['FIN'])).find((t) => Number(t.order_id) === orderId);

    await departmentTaskRepository.updateType('clear_buyer_payment', 5, 5);
    let t = await task();
    expect([Number(t.overdue), Number(t.escalated)]).toEqual([0, 0]);
    await departmentTaskRepository.updateType('clear_buyer_payment', 1, 5);
    t = await task();
    expect([Number(t.overdue), Number(t.escalated)]).toEqual([1, 0]);
    await departmentTaskRepository.updateType('clear_buyer_payment', 0, 1);
    t = await task();
    expect([Number(t.overdue), Number(t.escalated)]).toEqual([1, 1]);
    expect(String(t.due_on)).toBe(shiftDays(-2));
  });

  it('lets a manager set only their own department\'s owner', async () => {
    const logisticsExec = await member('Logistics Executive');
    const manager = await member('Logistics Manager');
    const log = await db.queryOne("SELECT * FROM departments WHERE code = 'LOG'");
    const sales = await db.queryOne("SELECT * FROM departments WHERE code = 'SALES'");
    await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: manager.id });
    const agent = await signIn(manager);
    const page = `/orders/${orderId}`;
    const path = `/orders/${orderId}/department-owners`;

    expect((await post(agent, page, path, { department_id: String(sales.id), user_id: String(logisticsExec.id) })).message)
      .toBe('You assign owners for your own department only — not Sales & Export.');
    const outsider = await createUser('Export Executive');
    expect((await post(agent, page, path, { department_id: String(log.id), user_id: String(outsider.id) })).message)
      .toBe('Choose a person who works in Logistics.');
    expect((await post(agent, page, path, { department_id: String(log.id), user_id: String(logisticsExec.id) })).message)
      .toBe('Jest Test User now owns this order for Logistics.');

    const owners = Object.fromEntries((await departmentTaskRepository.ownersForOrder(orderId)).map((o) => [o.code, o.user_id]));
    expect(Number(owners.LOG)).toBe(logisticsExec.id);
    expect(owners.SALES).toBeNull();
    expect((await db.queryOne('SELECT COUNT(*) AS n FROM order_assignees WHERE order_id = :o AND user_id = :u', { o: orderId, u: logisticsExec.id })).n).toBe(1);
    expect((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'order_department_owner'", { u: logisticsExec.id })).n).toBe(1);
  });

  it('sets the task days in Admin, with checks', async () => {
    const agent = await signIn(await createUser('Admin'));
    const standard = {};
    const escalate = {};
    for (const t of await departmentTaskRepository.types()) {
      standard[t.task_key] = String(t.standard_days);
      escalate[t.task_key] = String(t.escalate_after_days);
    }
    const page = '/admin/task-types';
    const send = async (std) => {
      const csrf = csrfOf((await agent.get(page)).text);
      const res = await agent.post(page).type('form').send(`_csrf=${encodeURIComponent(csrf)}&${Object.entries(std).map(([k, v]) => `standard_days[${k}]=${v}`).join('&')}&${Object.entries(escalate).map(([k, v]) => `escalate_after_days[${k}]=${v}`).join('&')}`);
      return (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/)[2].trim();
    };
    expect(await send({ ...standard, clear_buyer_payment: '-1' })).toMatch(/^Enter whole days from 0 to 90 for/);
    expect(await send({ ...standard, clear_buyer_payment: '3' })).toBe('Task days saved (1 changed).');
    expect(Number((await departmentTaskRepository.types()).find((t) => t.task_key === 'clear_buyer_payment').standard_days)).toBe(3);
  });

  it('lets a department work its own queue, and management see every department', async () => {
    const fin = await db.queryOne("SELECT id FROM departments WHERE code = 'FIN'");
    const mgmt = await db.queryOne("SELECT id FROM departments WHERE code = 'MGMT'");
    expect(await departmentTaskRepository.scopeFor({ department_id: fin.id })).toEqual(['FIN']); // though Finance sees every amount
    expect(await departmentTaskRepository.scopeFor({ department_id: mgmt.id })).toBeNull();
    expect(await departmentTaskRepository.scopeFor(null)).toBeNull();
  });
});
