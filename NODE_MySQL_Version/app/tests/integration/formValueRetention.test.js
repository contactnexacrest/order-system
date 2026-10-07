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
 * Batch 3 #4: a validation failure on the Client or Order creation form used
 * to lose every value the buyer/staff had already typed — flash.set() only
 * carried the error message across the redirect, never the submitted data,
 * so the create() view always re-rendered from a blank body. flash.setOld()/
 * pullOld() now carries the raw submission across that one redirect so the
 * re-shown form is pre-filled, including each row of a multi-line product
 * table. Mirrors PHP's FormValueRetentionTest.php via the real HTTP routes.
 */
describe('Form value retention on validation failure (Batch 3 #4)', () => {
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
      { num, name: 'Batch 3 #4 Regression Test Buyer Ltd', addr: '1 Test Street, Test City' }
    );
    return result.insertId;
  }

  async function orderCount() {
    const row = await db.queryOne('SELECT COUNT(*) AS c FROM orders');
    return parseInt(row.c, 10);
  }

  it('re-populates the client create form after a validation failure', async () => {
    const formPage = await agent.get('/clients/create');
    const csrf = extractCsrf(formPage.text);

    const storeRes = await agent.post('/clients').type('form').send({
      _csrf: csrf,
      company_legal_name: '',
      billing_address: '',
      contact_person: 'Jane Node Buyer',
      email: 'jane.node@example.com',
    });
    expect(storeRes.status).toBe(302);

    const reshown = await agent.get('/clients/create');
    expect(reshown.text).toContain('jane.node@example.com');
    expect(reshown.text).toContain('Jane Node Buyer');
  });

  it('clears old() values after one re-render (one-shot, not reusable on a later visit)', async () => {
    const formPage = await agent.get('/clients/create');
    const csrf = extractCsrf(formPage.text);

    await agent.post('/clients').type('form').send({
      _csrf: csrf,
      company_legal_name: '',
      billing_address: '',
      contact_person: 'One Shot Node Co',
    });

    const firstReshow = await agent.get('/clients/create');
    expect(firstReshow.text).toContain('One Shot Node Co');

    const laterVisit = await agent.get('/clients/create');
    expect(laterVisit.text).not.toContain('One Shot Node Co');
  });

  it('re-populates order create scalar fields after an invalid HS code', async () => {
    const clientId = await createTestClient();
    const formPage = await agent.get(`/orders/create?client_id=${clientId}`);
    const csrf = extractCsrf(formPage.text);

    const before = await orderCount();
    const storeRes = await agent.post('/orders').type('form').send({
      _csrf: csrf,
      client_id: String(clientId),
      incoterm_id: String(incoterm.id),
      currency_id: String(currency.id),
      payment_preset_id: String(preset.id),
      est_lead_time_text: '45-60 days from advance receipt',
      special_requirements: 'Handle with extreme care',
      'product_description[]': 'Granite slab — Batch 3 #4 Node regression',
      'product_hs_code[]': 'NOT-A-REAL-CODE',
      'product_quantity[]': '10',
      'product_unit_price[]': '5.00',
    });
    expect(storeRes.status).toBe(302);
    expect(await orderCount()).toBe(before);

    const reshown = await agent.get('/orders/create');
    expect(reshown.text).toContain('45-60 days from advance receipt');
    expect(reshown.text).toContain('Handle with extreme care');
    expect(reshown.text).toContain('Granite slab — Batch 3 #4 Node regression');
    expect(reshown.text).toContain('NOT-A-REAL-CODE');
  });

  it('re-populates every product row after a validation failure, not just the first', async () => {
    const clientId = await createTestClient();
    const formPage = await agent.get(`/orders/create?client_id=${clientId}`);
    const csrf = extractCsrf(formPage.text);

    const before = await orderCount();
    const storeRes = await agent.post('/orders').type('form').send({
      _csrf: csrf,
      client_id: String(clientId),
      incoterm_id: String(incoterm.id),
      currency_id: String(currency.id),
      payment_preset_id: String(preset.id),
      'product_description[]': ['First line granite node', 'Second line marble node'],
      'product_dimensions[]': ['600x600', '300x300'],
      'product_hs_code[]': ['680293', 'BAD-CODE'],
      'product_quantity[]': ['10', '20'],
      'product_unit_price[]': ['5.00', '7.50'],
    });
    expect(storeRes.status).toBe(302);
    expect(await orderCount()).toBe(before);

    const reshown = await agent.get('/orders/create');
    expect(reshown.text).toContain('First line granite node');
    expect(reshown.text).toContain('Second line marble node');
    expect(reshown.text).toContain('600x600');
    expect(reshown.text).toContain('300x300');
  });
});
