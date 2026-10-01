'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Order Details redesign — a quick-glance summary row (current stage,
 * product count, payment legs resolved, compliance checklist resolved,
 * document count) added above the existing commercial-terms kv-grid, so
 * staff don't have to scroll the whole page to see where an order stands.
 */
describe('Order Details at-a-glance summary', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('shows product count and current stage', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderId, 1, 'Granite Slab', null, null, '10', false, 'sqm', '50', '680222');

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('Current Stage');
    expect(res.text).toContain('1 line');
    expect(res.text).toContain('Stage 1');
  });

  it('shows the compliance checklist line for a user with close_orders (Admin)', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('Compliance Checklist');
    expect(res.text).toMatch(/\d+ of \d+ resolved/);
  });
});
