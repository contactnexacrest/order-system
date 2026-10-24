'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

async function unlockThroughStage4(orderId) {
  for (let stage = 1; stage <= 4; stage++) {
    await stageGateService.passAndUnlockNext(orderId, stage, null);
  }
}

/**
 * Port of TwoColumnFormLayoutsTest.php. 90-item backlog "2-column
 * layouts for Report-a-Payment and Supplier PO": both forms were a
 * single long column of full-width <label>s — every other long form in
 * this app already uses the existing `.field-grid` CSS class (2
 * columns, collapsing to 1 on mobile) for exactly this reason. Pure
 * markup — no field, name, or value changes, so this is a presence/
 * position check, not a behavior test.
 */
describe('Two-column form layouts (90-item backlog "2-column layouts for Report-a-Payment and Supplier PO")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('Supplier PO form is wrapped in a two-column field-grid', async () => {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);

    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage4(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    const output = res.text;

    const formIdPos = output.indexOf('id="supplier-po-form"');
    const supplierSelectPos = output.indexOf('name="supplier_id"');
    const calcPos = output.indexOf('data-spo-calc="quantity"');
    const submitPos = output.indexOf('Save Supplier PO Terms</button>');

    expect(formIdPos).toBeGreaterThan(-1);
    expect(supplierSelectPos).toBeGreaterThan(-1);
    expect(calcPos).toBeGreaterThan(-1);
    expect(submitPos).toBeGreaterThan(-1);

    const gridStartAfterForm = output.indexOf('<div class="field-grid">', formIdPos);
    expect(gridStartAfterForm).toBeGreaterThan(formIdPos);
    expect(gridStartAfterForm).toBeLessThan(supplierSelectPos);
    expect(calcPos).toBeLessThan(submitPos);
  });

  it('client Report a Payment form is wrapped in a two-column field-grid', async () => {
    const email = `jest-two-col-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, 'JestTwoColTest123!');
    const orderId = await createTestOrder(clientId);

    const agent = request.agent(app);
    const loginPage = await agent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/client/login').type('form').send({ _csrf: csrf, email, password: 'JestTwoColTest123!' });
    expect(loginRes.status).toBe(302);

    const res = await agent.get(`/client/orders/${orderId}`);
    expect(res.status).toBe(200);
    const output = res.text;

    const formPos = output.indexOf(`action="/client/orders/${orderId}/report-payment"`);
    const paymentTypePos = output.indexOf('name="payment_type"');
    const screenshotPos = output.indexOf('name="screenshot"');
    const submitPos = output.indexOf('Submit Payment Details');

    expect(formPos).toBeGreaterThan(-1);
    expect(paymentTypePos).toBeGreaterThan(-1);

    const gridStart = output.indexOf('<div class="field-grid">', formPos);
    expect(gridStart).toBeGreaterThan(-1);
    expect(gridStart).toBeLessThan(paymentTypePos);
    expect(submitPos).toBeGreaterThan(screenshotPos);
  });
});
