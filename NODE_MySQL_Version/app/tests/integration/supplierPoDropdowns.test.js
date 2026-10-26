'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const lookupRepository = require('../../src/repositories/lookupRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');
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
 * Port of SupplierPoDropdownsTest.php. 90-item backlog #51a: the Supplier
 * PO form's Surface Finish and GST Rate fields were plain free-text
 * inputs, unlike every other admin-managed field on the same screen
 * (Supplier Type, Unit), which already render from dropdown_options.
 * Also covers the Supplier Type additions: a new "Factory Owner" option,
 * and renaming the ambiguous "Processor" option to "Processor (Job-Work
 * Basis)". The auto-calculation wiring is pure client-side JS with no
 * server-visible output beyond the data-spo-calc hooks each field
 * carries — asserted here as a markup-presence regression guard.
 */
describe('Supplier PO dropdowns (90-item backlog #51a)', () => {
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

  it('Supplier PO form renders Surface Finish and GST Rate dropdowns', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage4(orderId);
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(true);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="surface_finish"');
    expect(res.text).not.toContain('<input type="text" name="surface_finish"');
    const surfaceFinishes = await lookupRepository.dropdownOptions('surface_finish');
    expect(surfaceFinishes.length).toBeGreaterThan(0);
    for (const option of surfaceFinishes) {
      expect(res.text).toContain(option.option_value);
    }

    expect(res.text).toContain('name="gst_rate_pct"');
    expect(res.text).not.toContain('<input type="text" name="gst_rate_pct"');
    const gstRates = await lookupRepository.dropdownOptions('gst_rate');
    expect(gstRates.length).toBeGreaterThan(0);
    for (const option of gstRates) {
      expect(res.text).toContain(`>${option.option_value}%<`);
    }
  });

  it('Supplier form renders clarified Supplier Type options', async () => {
    // Supplier types are now picked on the Suppliers directory form
    // (docs/schema.sql Section BZ), not an inline form on the order page.
    const res = await agent.get('/suppliers/create');
    expect(res.status).toBe(200);

    expect(res.text).toContain('value="Factory Owner"');
    expect(res.text).toContain('value="Processor (Job-Work Basis)"');
    expect(res.text).not.toContain('value="Processor"');
  });

  it('Supplier PO form wires auto-calculation hooks', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage4(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    for (const field of ['quantity', 'unit_price_inr', 'basic_value_inr', 'gst_rate_pct', 'gst_amount_inr', 'total_payable_inr', 'advance_pct', 'advance_amount_inr', 'balance_amount_inr']) {
      expect(res.text).toContain(`data-spo-calc="${field}"`);
    }
    expect(res.text).toContain("getElementById('supplier-po-form')");
  });
});
