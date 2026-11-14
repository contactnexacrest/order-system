'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Owner, 14 Nov 2026 (D-373): an order's lines are always numbered 1, 2, 3 … with no gap, however often
 * lines are removed and added; every removal is written to the Audit Log. PHP twin:
 * tests/Integration/OrderLineNumberingTest.php.
 */
describe('D-373: order lines stay 1, 2, 3 … after removing and adding again', () => {
  const PASSWORD = 'JestLineNumbers!1';

  afterAll(async () => {
    await db.pool.end();
  });

  async function admin() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-linenos-${crypto.randomBytes(4).toString('hex')}@email.com`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  async function addLine(orderId, name) {
    return orderProductRepository.add(orderId, await orderProductRepository.nextLineNo(orderId), name, null, null, '4.680', false, 'CBM', '2865.84', '680293');
  }

  async function remove(agent, orderId, lineId) {
    const csrf = (await agent.get(`/orders/${orderId}`)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post(`/orders/${orderId}/products/${lineId}/delete`).type('form').send({ _csrf: csrf, reason: '' });
  }

  async function lines(orderId) {
    const rows = await db.query('SELECT line_no, description FROM order_products WHERE order_id = :o AND is_active = 1 ORDER BY line_no', { o: orderId });
    return rows.map((r) => [Number(r.line_no), r.description]);
  }

  test('new lines start again at 1, a removed middle line closes the gap, a copy goes below its original', async () => {
    const agent = await admin();
    const orderId = await createTestOrder(await createTestClient());

    const first = [await addLine(orderId, 'A'), await addLine(orderId, 'B'), await addLine(orderId, 'C')];
    for (const id of first) await remove(agent, orderId, id);
    expect(await lines(orderId)).toEqual([]);

    const x = await addLine(orderId, 'X');
    const y = await addLine(orderId, 'Y');
    await addLine(orderId, 'Z');
    expect(await lines(orderId)).toEqual([[1, 'X'], [2, 'Y'], [3, 'Z']]);

    await remove(agent, orderId, y);
    expect(await lines(orderId)).toEqual([[1, 'X'], [2, 'Z']]);

    await orderProductRepository.duplicate(x);
    expect(await lines(orderId)).toEqual([[1, 'X'], [2, 'X'], [3, 'Z']]);

    await addLine(orderId, 'W');
    expect((await lines(orderId)).map((l) => l[0])).toEqual([1, 2, 3, 4]);

    const log = await db.queryOne("SELECT old_value FROM audit_log WHERE action_type = 'ORDER_LINE_REMOVED' AND entity_type = 'order_products' AND entity_id = :id", { id: y });
    expect(log.old_value).toBe('Line 2: Y — 4.68 CBM');
  });
});
