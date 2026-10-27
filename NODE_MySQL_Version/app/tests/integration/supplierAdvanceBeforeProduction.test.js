'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of SupplierAdvanceBeforeProductionTest.php. Owner report (2026-10-27):
 * confirming the supplier signed (7d) passes Stage 5's gate, which ticked all
 * of Step 7, ticked Step 8 with nothing entered, and opened Group C. Step 7
 * now waits for the supplier advance (7e), Step 8 for a real production /
 * shipment entry, Group C for Group B.
 */
describe('supplier advance before production', () => {
  let agent;
  let orderId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function setStages(numbers, status) {
    for (const n of numbers) {
      await db.execute(
        'UPDATE order_stages SET status = :status WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)',
        { status, id: orderId, n }
      );
    }
  }

  beforeEach(async () => {
    orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Advance Quarry Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `ADV-SPO-${orderId}`, {
      material_stone_type: null, grade: null, surface_finish: null,
      dimensions: null, dimensional_tolerance: null, quantity: '100',
      unit: 'sqm', colour_reference: null, special_requirements: null,
      unit_price_inr: '450', basic_value_inr: '45000', gst_rate_pct: null,
      gst_amount_inr: null, total_payable_inr: '45000', advance_pct: '30',
      advance_amount_inr: '13500', balance_amount_inr: '31500',
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
    }, 'signed');
    // As right after 7d: stages 1-5 passed, Stage 6 (freight) opened.
    await setStages([1, 2, 3, 4, 5], 'gate_passed');
    await setStages([6], 'in_progress');
  });

  const page = async () => (await agent.get(`/orders/${orderId}`)).text;
  function icon(html, id) {
    const m = html.match(new RegExp(`id="${id}"[^>]*>\\s*<summary><span class="step-card-icon (\\w+)"`));
    expect(m).not.toBeNull();
    return m[1];
  }
  const opens = (html, id) => new RegExp(`<details[^>]*id="${id}"[^>]*\\sopen[\\s>]`).test(html);

  it('after 7d the supplier advance is next and nothing later is ticked', async () => {
    const html = await page();
    expect(icon(html, 'step-group-stage-5')).toBe('current');
    expect(icon(html, 'step-7e-supplier-advance-paid')).toBe('current');
    expect(opens(html, 'step-7e-supplier-advance-paid')).toBe(true);
    expect(icon(html, 'step-group-stage-production')).toBe('locked');
    expect(opens(html, 'phase-production')).toBe(true);
    expect(opens(html, 'phase-shipment')).toBe(false);
    expect(html).toContain('0 of 2 steps done');
  });

  it('once the advance is paid, Step 8 is next until something is entered', async () => {
    await db.execute('UPDATE order_supplier_po SET advance_paid_at = CURDATE() WHERE order_id = :id', { id: orderId });
    let html = await page();
    expect(icon(html, 'step-group-stage-5')).toBe('done');
    expect(icon(html, 'step-group-stage-production')).toBe('current');
    expect(opens(html, 'phase-shipment')).toBe(false);

    await db.execute("UPDATE orders SET production_status_text = 'Cutting in progress' WHERE id = :id", { id: orderId });
    html = await page();
    expect(icon(html, 'step-group-stage-production')).toBe('done');
    expect(opens(html, 'phase-shipment')).toBe(true);
    expect(html).toContain('2 of 2 steps done');
  });
});
