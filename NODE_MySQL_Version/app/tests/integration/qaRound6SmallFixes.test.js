'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const incentiveService = require('../../src/services/incentiveService');
const orderRepository = require('../../src/repositories/orderRepository');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder, createTestClientLogin, createTestDocument } = require('../support/fixtures');

/**
 * Port of QaRound6SmallFixesTest.php. QA Round 6, Batch 4 (owner approved
 * the plan 2026-10-30): an unexpected file on the client's message
 * (R6-N03), "Mark all paid" with nothing selected (R6-N11), duplicate reward
 * thresholds (R6-N12), payment terms on a cancelled order (R6-N13), a
 * payment report before the PI or on a cancelled order (R6-N24), invalid
 * dropdown options refused (R6-N25), self-approval of payment terms by
 * someone other than Admin/MD/ED (D-117, Q-R6-1) and insurance only on CIF
 * (D-049, Q-R5-2).
 */
describe('QA Round 6 — smaller fixes', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  async function staff(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-r6s-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Small Fixes', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestSmall6!', 10), role_id: role.id }
    )).insertId;
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: 'JestSmall6!' });
    agent.userId = id;
    return agent;
  }

  async function post(agent, path, fields, page) {
    const csrf = csrfOf((await agent.get(page)).text);
    return agent.post(path).type('form').send({ _csrf: csrf, ...fields });
  }

  async function flashAfter(agent, res) {
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim() } : { type: null, message: '' };
  }

  async function orderNeedingApproval(createdBy = null) {
    const orderId = await createTestOrder(await createTestClient());
    const preset = await db.queryOne('SELECT id FROM payment_presets WHERE requires_md_approval = 1 LIMIT 1');
    expect(preset).not.toBeNull();
    await db.execute('UPDATE orders SET payment_preset_id = :p, payment_terms_approved_at = NULL, created_by = :u WHERE id = :o', { p: preset.id, u: createdBy, o: orderId });
    return orderId;
  }

  const approvedAt = async (orderId) => (await db.queryOne('SELECT payment_terms_approved_at AS v FROM orders WHERE id = :o', { o: orderId })).v;

  it('payment terms of a cancelled order cannot be approved (R6-N13)', async () => {
    const admin = await staff('Admin');
    const orderId = await orderNeedingApproval();
    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :o", { o: orderId });
    const res = await post(admin, `/orders/${orderId}/payment-terms/approve`, {}, `/orders/${orderId}`);
    expect((await flashAfter(admin, res)).message).toContain('cannot be approved');
    expect(await approvedAt(orderId)).toBeNull();
  });

  it('only Admin, MD or ED may approve the payment terms of their own order (D-117)', async () => {
    const exporter = await staff('Export Executive');
    const permission = await db.queryOne("SELECT id FROM permissions WHERE permission_key = 'approve_payment_terms'");
    await db.execute('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, 1)', { u: exporter.userId, p: permission.id });
    const admin = await staff('Admin');

    const own = await orderNeedingApproval(exporter.userId);
    await db.execute('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :u)', { o: own, u: exporter.userId });
    let res = await post(exporter, `/orders/${own}/payment-terms/approve`, {}, `/orders/${own}`);
    expect((await flashAfter(exporter, res)).message).toContain('must be approved by someone else');
    expect(await approvedAt(own)).toBeNull();

    const someoneElses = await orderNeedingApproval(admin.userId);
    await db.execute('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :u)', { o: someoneElses, u: exporter.userId });
    res = await post(exporter, `/orders/${someoneElses}/payment-terms/approve`, {}, `/orders/${someoneElses}`);
    expect((await flashAfter(exporter, res)).type).toBe('success');

    const adminsOwn = await orderNeedingApproval(admin.userId);
    res = await post(admin, `/orders/${adminsOwn}/payment-terms/approve`, {}, `/orders/${adminsOwn}`);
    expect((await flashAfter(admin, res)).type).toBe('success');
  });

  it('"Mark all paid" with nothing selected is a warning (R6-N11)', async () => {
    const admin = await staff('Admin');
    const month = todayIst().slice(0, 7);
    const res = await post(admin, '/incentives/payouts/paid-all', { month, paid_on: todayIst(), paid_via: 'salary', paid_reference: 'Payroll TEST' }, `/incentives/payouts?month=${month}`);
    const flash = await flashAfter(admin, res);
    expect(flash.type).toBe('warning');
    expect(flash.message).toContain('Nothing was recorded');
  });

  it('the reward table refuses two rows with the same profit (R6-N12)', async () => {
    const admin = await staff('Admin');
    const before = (await incentiveService.settings()).table;
    const res = await post(admin, '/incentives/settings', {
      min_profit_inr: '100000', new_client_bonus_inr: '0', cutoff_day: '20',
      table_from: ['300000', '300000'], table_reward: ['40000', '90000'],
      split: { sales: '40', export: '20', marketing: '15', logistics: '15', accounts: '10' },
    }, '/incentives/settings');
    expect((await flashAfter(admin, res)).message).toContain('same order profit');
    expect((await incentiveService.settings()).table).toEqual(before);
  });

  it('the payment report waits for the PI and closes on a cancelled order (R6-N24)', async () => {
    const email = `jest-r6s-client-${Math.random().toString(16).slice(2, 10)}@example.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, 'JestClient6!');
    const orderId = await createTestOrder(clientId);
    const client = request.agent(app);
    const csrf = csrfOf((await client.get('/client/login')).text);
    await client.post('/client/login').type('form').send({ _csrf: csrf, email, password: 'JestClient6!' });
    const page = `/client/orders/${orderId}`;
    const report = async () => flashAfter(client, await post(client, `${page}/report-payment`, { payment_type: 'advance', transaction_ref: 'UTR-JEST', amount: '100', payment_date: todayIst() }, page));
    const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM client_payment_reports WHERE order_id = :o', { o: orderId })).n);

    expect((await report()).message).toContain('once we have sent you the Proforma Invoice');
    await createTestDocument(orderId, 'PI', 'sent');
    expect((await report()).type).toBe('success');
    expect(await count()).toBe(1);

    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :o", { o: orderId });
    expect((await report()).message).toContain('cancelled');
    expect(await count()).toBe(1);
    expect((await client.get(page)).text).not.toContain('/report-payment"');
  });

  it('an unexpected file on the client\'s message is refused, the typed message kept (R6-N03)', async () => {
    const email = `jest-r6s-msg-${Math.random().toString(16).slice(2, 10)}@example.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, 'JestClient6!');
    const orderId = await createTestOrder(clientId);
    const client = request.agent(app);
    const loginCsrf = csrfOf((await client.get('/client/login')).text);
    await client.post('/client/login').type('form').send({ _csrf: loginCsrf, email, password: 'JestClient6!' });
    const page = `/client/orders/${orderId}`;
    const csrf = csrfOf((await client.get(page)).text);
    const res = await client.post(`${page}/comments`).set('Host', 'portal.example.test').set('Referer', `http://portal.example.test${page}`)
      .field('_csrf', csrf).field('body', 'Please confirm the crate count.')
      .attach('unexpected_field', Buffer.from('%PDF-1.4 x'), 'x.pdf');
    expect(res.status).toBe(302);
    const html = (await client.get(res.headers.location)).text;
    expect(html).toContain('could not be accepted');
    expect(html).toContain('Please confirm the crate count.</textarea>');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM order_comments WHERE order_id = :o', { o: orderId })).n)).toBe(0);
  });

  it('invalid dropdown options are refused (R6-N25)', async () => {
    const admin = await staff('Admin');
    const row = await db.queryOne("SELECT id, option_value FROM dropdown_options WHERE list_key = 'unit' ORDER BY id LIMIT 1");
    const csrf = csrfOf((await admin.get('/admin/dropdown-options')).text);
    const res = await admin.post('/admin/dropdown-options/unit/update').set('Content-Type', 'application/x-www-form-urlencoded')
      .send(`_csrf=${encodeURIComponent(csrf)}&option_value%5B${row.id}%5D=&sort_order%5B${row.id}%5D=abc`);
    expect((await flashAfter(admin, res)).message).toContain('nothing was saved');
    expect((await db.queryOne('SELECT option_value FROM dropdown_options WHERE id = :id', { id: row.id })).option_value).toBe(row.option_value);
  });

  it('a dropdown option edit is saved under its own id (R6-N25)', async () => {
    const admin = await staff('Admin');
    const rows = await db.query("SELECT id, option_value, sort_order, is_active FROM dropdown_options WHERE list_key = 'unit' ORDER BY id");
    const target = rows[rows.length - 1];
    const fields = [`_csrf=${encodeURIComponent(csrfOf((await admin.get('/admin/dropdown-options')).text))}`];
    for (const r of rows) {
      fields.push(`option_value%5B${r.id}%5D=${encodeURIComponent(r.id === target.id ? `${r.option_value} (renamed)` : r.option_value)}`);
      fields.push(`sort_order%5B${r.id}%5D=${r.sort_order}`);
      if (r.is_active) fields.push(`is_active%5B${r.id}%5D=1`);
    }
    const res = await admin.post('/admin/dropdown-options/unit/update').set('Content-Type', 'application/x-www-form-urlencoded').send(fields.join('&'));
    expect((await flashAfter(admin, res)).message).toBe('unit updated.');
    expect((await db.queryOne('SELECT option_value FROM dropdown_options WHERE id = :id', { id: target.id })).option_value).toBe(`${target.option_value} (renamed)`);
    for (const r of rows.filter((x) => x.id !== target.id)) {
      expect((await db.queryOne('SELECT option_value FROM dropdown_options WHERE id = :id', { id: r.id })).option_value).toBe(r.option_value);
    }
    await db.execute('UPDATE dropdown_options SET option_value = :v WHERE id = :id', { v: target.option_value, id: target.id });
  });

  it('only a CIF order keeps an insurance amount (D-049)', async () => {
    const admin = await staff('Admin');
    for (const [incoterm, expected] of [['CIF', '120.00'], ['CFR', null], ['FOB', null]]) {
      const orderId = await createTestOrder(await createTestClient(), incoterm);
      const order = await orderRepository.find(orderId);
      const page = `/orders/${orderId}/edit`;
      const res = await post(admin, page, { incoterm_id: String(order.incoterm_id), currency_id: String(order.currency_id), indicative_freight_amount: '950', indicative_insurance_amount: '120' }, page);
      expect([incoterm, (await flashAfter(admin, res)).type]).toEqual([incoterm, 'success']);
      const stored = (await db.queryOne('SELECT indicative_insurance_amount AS v FROM orders WHERE id = :o', { o: orderId })).v;
      expect([incoterm, stored === null ? null : String(stored)]).toEqual([incoterm, expected]);
    }
  });
});
