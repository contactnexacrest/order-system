'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * QA-5 (CONC-03 — external QA report cross-verification): computing "the
 * next sequence number for this client" (SELECT MAX(sequence_no)+1) and
 * inserting the new order used to be two separate, unlocked statements.
 * Two concurrent order-creation requests for the same client — a
 * double-submit, or two staff members working the same client at once —
 * could both read the same MAX, both build the identical order_reference
 * from it, and the second INSERT would 500 on order_reference's UNIQUE
 * constraint. Fixed with SELECT ... FOR UPDATE inside a transaction
 * (orderRepository.createWithNextSequence()); this test fires real
 * concurrent HTTP requests at the live app to prove the race is closed,
 * not just that the SQL looks right in isolation.
 */
describe('Order sequence-number race under concurrent creation (QA-5 CONC-03)', () => {
  let agent;
  let clientId;

  beforeAll(async () => {
    agent = request.agent(app);

    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent
      .post('/login')
      .type('form')
      .send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');

    const createClientPage = await agent.get('/clients/create');
    const clientCsrf = extractCsrf(createClientPage.text);
    const clientRes = await agent
      .post('/clients')
      .type('form')
      .send({
        _csrf: clientCsrf,
        company_legal_name: 'CONC-03 Regression Test Buyer Ltd',
        billing_address: '1 Race Condition Ave, Test City',
      });
    expect(clientRes.status).toBe(302);
    clientId = parseInt(clientRes.headers.location.match(/\/clients\/(\d+)/)[1], 10);
    expect(clientId).toBeGreaterThan(0);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('assigns every concurrently-created order for the same client a distinct sequence number and reference', async () => {
    const [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id FROM currencies WHERE code = 'USD'"),
      db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"),
    ]);

    const createOrderPage = await agent.get(`/orders/create?client_id=${clientId}`);
    const orderCsrf = extractCsrf(createOrderPage.text);

    const CONCURRENT_REQUESTS = 8;
    const responses = await Promise.all(
      Array.from({ length: CONCURRENT_REQUESTS }, () =>
        agent
          .post('/orders')
          .type('form')
          .send({
            _csrf: orderCsrf,
            client_id: String(clientId),
            incoterm_id: String(incoterm.id),
            currency_id: String(currency.id),
            payment_preset_id: String(preset.id),
            'product_description[]': 'CONC-03 test granite slab, polished',
            'product_hs_code[]': '680293',
          })
      )
    );

    // Every single request must have succeeded — none should have 500'd on
    // a duplicate-key error from a lost race.
    for (const res of responses) {
      expect(res.status).toBe(302);
      expect(res.headers.location).toMatch(/^\/orders\/\d+$/);
    }

    const orderIds = responses.map((res) => parseInt(res.headers.location.match(/\/orders\/(\d+)/)[1], 10));
    expect(new Set(orderIds).size).toBe(CONCURRENT_REQUESTS); // every response pointed at a distinct order

    const rows = await db.query(
      `SELECT sequence_no, order_reference FROM orders WHERE id IN (${orderIds.map((id) => parseInt(id, 10)).join(',')})`
    );
    expect(rows.length).toBe(CONCURRENT_REQUESTS);

    const sequenceNos = rows.map((r) => r.sequence_no);
    expect(new Set(sequenceNos).size).toBe(CONCURRENT_REQUESTS);

    const references = rows.map((r) => r.order_reference);
    expect(new Set(references).size).toBe(CONCURRENT_REQUESTS);
  });
});
