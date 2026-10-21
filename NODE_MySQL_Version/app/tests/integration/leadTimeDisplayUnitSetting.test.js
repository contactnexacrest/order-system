'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of LeadTimeDisplayUnitSettingTest.php. 90-item backlog item 16:
 * lead-time days-vs-weeks display setting. Covers
 * companySettingsRepository's lead_time_display_unit row actually
 * changing what the Order Detail page renders — the stored day figures
 * themselves never change, only the label and the rendered text.
 */
describe('Lead-time display unit setting (90-item backlog item 16)', () => {
  let agent;
  const originalValue = {};

  beforeAll(async () => {
    originalValue.v = (await companySettingsRepository.get('lead_time_display_unit')) || 'days';
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterEach(async () => {
    await companySettingsRepository.set('lead_time_display_unit', originalValue.v);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('defaults to days and renders plain integers', async () => {
    await companySettingsRepository.set('lead_time_display_unit', 'days');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const order = await orderRepository.find(orderId);
    await orderRepository.updateDetails(orderId, { ...order, lead_time_min_days: 45, lead_time_max_days: 60, sailing_lead_time_min_days: 18, sailing_lead_time_max_days: 24 });

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('Production Lead Time (days)');
    expect(res.text).toContain('Sailing Lead Time (days)');
    expect(res.text).toContain('45–60');
    expect(res.text).toContain('18–24');
  });

  it('weeks setting converts the rendered range', async () => {
    await companySettingsRepository.set('lead_time_display_unit', 'weeks');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const order = await orderRepository.find(orderId);
    await orderRepository.updateDetails(orderId, { ...order, lead_time_min_days: 45, lead_time_max_days: 60 });

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('Production Lead Time (weeks)');
    expect(res.text).toContain('6.4–8.6');
  });

  it('orders with no lead time set still render an em dash', async () => {
    await companySettingsRepository.set('lead_time_display_unit', 'days');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('Production Lead Time (days)');
  });
});
