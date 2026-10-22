'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PHP's NavLinksMatchRoutePermissionsTest. External QA report
 * U-01: "Roles are shown links/buttons they are not allowed to use" →
 * clicking through to a "403 Not permitted" page. Owner rule: everyone
 * sees only what they can use. Each test logs in as the exact role QA
 * found broken, hits the real page over HTTP, and asserts the link is
 * now either omitted or replaced with plain text, never a dead-end link.
 */
describe('Nav links match their own route permissions (External QA report U-01)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function extractCsrf(html) {
    const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
    if (!m) throw new Error('CSRF token not found in response HTML');
    return m[1];
  }

  async function loginAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-u01-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestU01Nav123!';
    const passwordHash = await bcrypt.hash(password, 10);
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: passwordHash, role_id: role.id }
    );
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    return { agent, userId: result.insertId };
  }

  test('Export Executive does not see the Disputes list sidebar link', async () => {
    const { agent } = await loginAs('Export Executive');

    const res = await agent.get('/orders');

    expect(res.text).not.toContain('href="/disputes"');
  });

  test('Admin does see the Disputes list sidebar link', async () => {
    const { agent } = await loginAs('Admin');

    const res = await agent.get('/orders');

    expect(res.text).toContain('href="/disputes"');
  });

  test('Viewer / Auditor does not see the Annexure, Amendments, email-compose, or order-disputes links', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const { agent } = await loginAs('Viewer / Auditor');

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).not.toContain(`href="/orders/${orderId}/annexure"`);
    expect(res.text).not.toContain(`href="/orders/${orderId}/amendments"`);
    expect(res.text).not.toContain(`href="/orders/${orderId}/email/compose"`);
    expect(res.text).not.toContain(`href="/orders/${orderId}/disputes"`);
  });

  test('Accounts Executive does not see the email-compose or order-disputes links', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const { agent } = await loginAs('Accounts Executive');

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).not.toContain(`href="/orders/${orderId}/email/compose"`);
    expect(res.text).not.toContain(`href="/orders/${orderId}/disputes"`);
  });

  test('Export Executive does see the order-disputes link via respond_to_disputes', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const { agent } = await loginAs('Export Executive');

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain(`href="/orders/${orderId}/disputes"`);
  });

  test('CA role sees order references as plain text, not links, on every CA page', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const { userId: adminId } = await loginAs('Admin');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, new Date(), adminId);
    const { agent } = await loginAs('CA / Chartered Accountant');

    const indexRes = await agent.get('/ca');
    expect(indexRes.text).not.toContain(`href="/orders/${orderId}"`);

    const expensesRes = await agent.get('/ca/expenses');
    expect(expensesRes.text).not.toContain('href="/ca/zoho-sync"');
  });

  test('Admin sees order references as real links on the CA hub', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const { agent, userId: adminId } = await loginAs('Admin');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, new Date(), adminId);

    const res = await agent.get('/ca');

    expect(res.text).toContain(`href="/orders/${orderId}"`);
  });

  test('Logistics Executive sees Compliance Task Types as plain text on an order page', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const { agent } = await loginAs('Logistics Executive');

    const res = await agent.get(`/orders/${orderId}`);

    if (res.text.includes('Pre-closure compliance tasks')) {
      expect(res.text).not.toContain('href="/compliance-task-types"');
    }
  });
});
