'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const orderPackingRepository = require('../../src/repositories/orderPackingRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of PackingListStepBadgeTest.php. User-reported batch item 19:
 * the Documents panel's own numbered sequence skipped straight past
 * "Generate Packing List" — it had no step badge at all, unlike every
 * other document-generate button in that panel. "Generate Packing List"
 * is now its own single-purpose step-card (Step 10b, right after Step 10a
 * "Save Packing & Crates", both split out of what used to be one shared
 * DB Stage 7 card) — it carries the 10b badge matching that card's own
 * heading.
 */
describe('Packing List step badge (90-item backlog item 19)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    await userRepository.findByEmail(TEST_ADMIN_EMAIL);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('Generate Packing List button has a step badge', async () => {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 6; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    // The Documents panel's Packing List button is gated on Stage 7
    // being unlocked AND packing data already saved.
    await orderPackingRepository.upsert(orderId, {
      actual_quantity_packed: '50',
      crate_count: '10',
      total_net_weight_kg: '2600',
      total_gross_weight_kg: '2700',
      total_cbm: '8.2',
      packing_date: null,
      shortfall_pct: null,
    });

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toMatch(/<span class="step-badge"[^>]*>10b<\/span>\s*<button[^>]*>Generate Packing List<\/button>/);
  });
});
