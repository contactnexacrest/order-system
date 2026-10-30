'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
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

  // Owner decision 2026-10-30 (QA Round 6, Q-R6-3): respond_to_disputes
  // now opens the list too, scoped to the orders the user may see.
  test('Export Executive sees the Disputes list sidebar link', async () => {
    const { agent } = await loginAs('Export Executive');

    const res = await agent.get('/orders');

    expect(res.text).toContain('href="/disputes"');
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
    const { agent, userId } = await loginAs('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, userId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain(`href="/orders/${orderId}/disputes"`);
  });

  test('CA role sees every order as a link on the CA pages', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const { userId: adminId } = await loginAs('Admin');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, new Date(), adminId);
    const { agent } = await loginAs('CA / Chartered Accountant');

    // Owner decision 2026-10-30: the CA holds view_all_orders (read-only) —
    // every order is a real link, not only those assigned to the CA.
    expect((await agent.get('/ca')).text).toContain(`href="/orders/${orderId}"`); // the CA opens every order, assigned or not

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
    const { agent, userId } = await loginAs('Logistics Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, userId);

    // The Compliance Checklist card (shown to close_orders holders) names
    // where its task list is managed — a link only for those who can open
    // /compliance-task-types (Admin-only), plain text for everyone else.
    await orderComplianceTaskRepository.initializeForOrder(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.text).toContain('id="compliance-checklist"');
    expect(res.text).toContain('managed from Compliance Task Types');
    expect(res.text).not.toContain('href="/compliance-task-types"');

    const admin = await loginAs('Admin');
    const adminRes = await admin.agent.get(`/orders/${orderId}`);
    expect(adminRes.text).toContain('<a href="/compliance-task-types">Compliance Task Types</a>');
  });
});
