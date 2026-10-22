'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const PER_PAGE = 25;

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * External QA report U-02: "Orders list has no pagination — with ~200
 * orders the page is 17,600 px tall on desktop, 52,000 px on a phone;
 * will slow down as data grows." These assertions deliberately never
 * depend on an exact total order count (the test DB is shared across
 * this whole Jest run, so other tests' fixture orders are always mixed
 * in) — only on invariants that must hold regardless of how many orders
 * already exist: no single page ever renders more than the page size,
 * and when there's more than one page, the pager actually says so.
 */
describe('Orders list pagination', () => {
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

  async function createOrders(count) {
    // createTestOrder() always inserts sequence_no = 1, so each order
    // needs its own client (uq_client_sequence is unique per client+seq).
    for (let i = 0; i < count; i++) {
      await createTestOrder(await createTestClient());
    }
  }

  it('no single page ever renders more than the page size', async () => {
    await createOrders(PER_PAGE + 5);

    const res = await agent.get('/orders?status=all');

    const cardCount = (res.text.match(/<a class="entity-card/g) || []).length;
    expect(cardCount).toBeLessThanOrEqual(PER_PAGE);
  });

  it('the pager appears once there is more than one page and links to page 2', async () => {
    await createOrders(PER_PAGE + 5);

    const res = await agent.get('/orders?status=all');

    expect(res.text).toContain('page=2');
    expect(res.text).toMatch(/Page 1 of \d+/);
  });

  it('page 2 shows a different set of orders than page 1', async () => {
    await createOrders(PER_PAGE + 5);

    const page1 = await agent.get('/orders?status=all');
    const page1Ids = [...page1.text.matchAll(/\/orders\/(\d+)"/g)].map((m) => m[1]);

    const page2 = await agent.get('/orders?status=all&page=2');
    const page2Ids = [...page2.text.matchAll(/\/orders\/(\d+)"/g)].map((m) => m[1]);

    expect(page2Ids.length).toBeGreaterThan(0);
    expect(page1Ids.some((id) => page2Ids.includes(id))).toBe(false);
  });

  it('a page number past the last page clamps to the last page rather than showing nothing', async () => {
    await createOrders(3);

    const res = await agent.get('/orders?status=all&page=99999');

    expect(res.text).not.toContain('No orders match this filter');
  });
});
