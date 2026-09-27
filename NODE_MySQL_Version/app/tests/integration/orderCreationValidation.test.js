'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * QA-5 ORD-04/ORD-05/ORD-06: order creation used to (a) accept an inactive
 * client via a direct/forged POST, since store() re-looked the client up
 * with clientRepository.find() (no is_active filter) rather than trusting
 * the create-form's own active-only dropdown, (b) crash with an unhandled
 * ER_TRUNCATED_WRONG_VALUE_FOR_FIELD on a non-numeric unit price — by which
 * point the order row and its stage/payment-status rows were already
 * committed with no transaction wrapping them, leaving a real half-created
 * order behind — and (c) accept a negative quantity outright, silently
 * producing a negative FOB value.
 */
describe('Order creation input validation (QA-5 ORD-04/ORD-05/ORD-06)', () => {
  let agent;
  let incoterm;
  let currency;
  let preset;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');

    [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id FROM currencies WHERE code = 'USD'"),
      db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"),
    ]);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestClient() {
    const num = `TEST-${Math.random().toString(16).slice(2, 10)}`;
    const result = await db.execute(
      'INSERT INTO clients (client_unique_number, company_legal_name, billing_address, created_by) VALUES (:num, :name, :addr, NULL)',
      { num, name: 'ORD-04/05/06 Regression Test Buyer Ltd', addr: '1 Test Street, Test City' }
    );
    return result.insertId;
  }

  async function orderCount() {
    const row = await db.queryOne('SELECT COUNT(*) AS c FROM orders');
    return parseInt(row.c, 10);
  }

  async function attemptCreate(clientId, { quantity, unitPrice }) {
    const formPage = await agent.get(`/orders/create?client_id=${clientId}`);
    const csrf = extractCsrf(formPage.text);
    return agent
      .post('/orders')
      .type('form')
      .send({
        _csrf: csrf,
        client_id: String(clientId),
        incoterm_id: String(incoterm.id),
        currency_id: String(currency.id),
        payment_preset_id: String(preset.id),
        'product_description[]': 'ORD-04/05/06 regression test granite slab',
        'product_hs_code[]': '680293',
        'product_quantity[]': quantity,
        'product_unit_price[]': unitPrice,
      });
  }

  it('rejects an inactive client', async () => {
    const clientId = await createTestClient();
    await clientRepository.setActive(clientId, false);

    const before = await orderCount();
    await attemptCreate(clientId, { quantity: '10', unitPrice: '5.00' });

    expect(await orderCount()).toBe(before);
  });

  it('accepts an active client with valid data', async () => {
    const clientId = await createTestClient();

    const before = await orderCount();
    const res = await attemptCreate(clientId, { quantity: '10', unitPrice: '5.00' });

    expect(res.status).toBe(302);
    expect(await orderCount()).toBe(before + 1);
  });

  it('rejects a non-numeric unit price without crashing or leaving a half-created order', async () => {
    const clientId = await createTestClient();

    const before = await orderCount();
    const res = await attemptCreate(clientId, { quantity: '10', unitPrice: 'abc' });

    expect(res.status).toBe(302);
    expect(await orderCount()).toBe(before);
  });

  it('rejects a negative quantity', async () => {
    const clientId = await createTestClient();

    const before = await orderCount();
    await attemptCreate(clientId, { quantity: '-5', unitPrice: '5.00' });

    expect(await orderCount()).toBe(before);
  });

  it('rejects a zero quantity', async () => {
    const clientId = await createTestClient();

    const before = await orderCount();
    await attemptCreate(clientId, { quantity: '0', unitPrice: '5.00' });

    expect(await orderCount()).toBe(before);
  });

  it('rejects a negative unit price', async () => {
    const clientId = await createTestClient();

    const before = await orderCount();
    await attemptCreate(clientId, { quantity: '10', unitPrice: '-5.00' });

    expect(await orderCount()).toBe(before);
  });
});
