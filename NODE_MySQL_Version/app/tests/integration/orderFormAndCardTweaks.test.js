'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderFormAndCardTweaksTest.php. Owner requests (2026-10-25),
 * redone after the session that first built them lost its uncommitted work:
 * - "Assignee name is cutting" on the Orders cards — the assignee tag may
 *   now wrap inside the card header instead of being clipped.
 * - The order page's client name links to that client's details.
 * - Indicative Freight / Insurance is disabled while the Incoterm is FOB:
 *   each Incoterm option carries its code (data-code) for the page script,
 *   placed after `selected` so `value="N" selected` still renders intact.
 */
describe('Order form and card tweaks', () => {
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

  it("links the order page's client name to the client's details", async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain(`<span class="k">Client</span><span class="v"><a href="/clients/${clientId}">`);
  });

  it('marks the freight fieldset and incoterm codes on the edit form', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');

    const res = await agent.get(`/orders/${orderId}/edit`);

    expect(res.status).toBe(200);
    expect(res.text).toContain('id="freight-insurance-fieldset"');
    expect(res.text).toContain('id="incoterm-select"');
    expect(res.text).toMatch(/<option value="\d+" selected data-code="FOB">FOB<\/option>/);
    expect(res.text).toContain('function updateFreightFieldsAvailability(clearOnDisable)');
  });

  it('marks the freight fieldset and incoterm codes on the create form', async () => {
    const res = await agent.get('/orders/create');

    expect(res.status).toBe(200);
    expect(res.text).toContain('id="freight-insurance-fieldset"');
    expect(res.text).toMatch(/<option value="\d+"\s+data-code="CIF">CIF<\/option>/);
  });

  it('lets the assignee tag wrap inside the card header', () => {
    const css = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'css', 'app.css'), 'utf8');

    expect(css).toContain('.entity-card-header{display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;}');
    expect(css).toContain('.entity-card-header .tag-navy{white-space:normal;max-width:100%;text-align:left;}');
  });
});
