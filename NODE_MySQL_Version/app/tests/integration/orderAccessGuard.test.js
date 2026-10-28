'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of OrderAccessGuardTest.php. QA Round 5, R5-24: the archived-order
 * and assignment rules used to be checked only on the order's main page —
 * its other pages and actions (edit, annexure, amendments, saving terms,
 * the Full Report, document downloads) stayed open. sessionAuth now
 * applies the same rule to every staff URL that belongs to an order, GET
 * and POST.
 */
describe('Order access on every order page and action (QA Round 5, R5-24)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function loginAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-access-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestAccess123!';
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/login')).text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.headers.location).not.toBe('/login');
    return { agent, userId: result.insertId };
  }

  it('refuses every page and action of an unassigned order, GET and POST', async () => {
    const { agent, userId } = await loginAs('Export Executive');
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    const csrf = extractCsrf((await agent.get('/orders/create')).text);

    for (const path of [`/orders/${orderId}`, `/orders/${orderId}/edit`, `/orders/${orderId}/annexure`, `/orders/${orderId}/amendments`, `/reports/order/${orderId}`, `/documents/${documentId}/download`]) {
      const res = await agent.get(path);
      expect([path, res.status]).toEqual([path, 403]);
      expect(res.text).toContain('assigned to a different user');
    }
    const post = await agent.post(`/orders/${orderId}/annexure/terms`).type('form').send({ _csrf: csrf, additional_terms: 'should not save' });
    expect(post.status).toBe(403);

    await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: userId });
    expect((await agent.get(`/orders/${orderId}/annexure`)).status).toBe(200);
  });

  it('refuses an archived order everywhere without view_archived_orders', async () => {
    // Viewer / Auditor sees every order (view_all_orders) but not archived ones.
    const { agent } = await loginAs('Viewer / Auditor');
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET is_archived = 1 WHERE id = :id', { id: orderId });

    for (const path of [`/orders/${orderId}/edit`, `/orders/${orderId}/annexure`, `/orders/${orderId}/amendments`]) {
      const res = await agent.get(path);
      expect([path, res.status]).toEqual([path, 403]);
      expect(res.text).toContain('has been archived');
    }
  });
});
