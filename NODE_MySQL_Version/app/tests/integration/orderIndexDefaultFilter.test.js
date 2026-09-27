'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * The Orders list used to default to showing every order (all statuses)
 * when no ?status= query param was given, with "All" and "no param at
 * all" sharing the same URL. Staff wanted the screen to open on Active by
 * default, since that's what's actually in play at any time — a
 * completed/lost order buried in the same list wasn't useful as a
 * default view. "All" is now its own explicit choice (?status=all).
 */
describe('Orders list defaults to Active', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('no query param shows only active orders', async () => {
    const activeOrderId = await createTestOrder(await createTestClient());
    const activeOrder = await orderRepository.find(activeOrderId);

    const lostOrderId = await createTestOrder(await createTestClient());
    await orderRepository.markLost(lostOrderId, 'Buyer went silent', 1);
    const lostOrder = await orderRepository.find(lostOrderId);

    const res = await agent.get('/orders');

    expect(res.text).toContain(activeOrder.order_reference);
    expect(res.text).not.toContain(lostOrder.order_reference);
    expect(res.text).toContain('filter-chip active" href="/orders?status=active"');
  });

  it('explicit ?status=all shows every order', async () => {
    const activeOrderId = await createTestOrder(await createTestClient());
    const activeOrder = await orderRepository.find(activeOrderId);

    const lostOrderId = await createTestOrder(await createTestClient());
    await orderRepository.markLost(lostOrderId, 'Buyer went silent', 1);
    const lostOrder = await orderRepository.find(lostOrderId);

    const res = await agent.get('/orders?status=all');

    expect(res.text).toContain(activeOrder.order_reference);
    expect(res.text).toContain(lostOrder.order_reference);
  });
});
