'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Users could update "Production Status" / "Estimated Shipment" on an order
 * with no record of who changed it, when, or what it said before — asked
 * directly: "when we update, where it gets logged and where can we see
 * it???". updateProductionStatus() now writes an audit log row per changed
 * field, visible on the order's own Audit Log page.
 */
describe('Production status / estimated shipment updates are audit-logged', () => {
  let agent;
  let orderId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
  });

  beforeEach(async () => {
    orderId = await createTestOrder();
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestOrder() {
    const num = `TEST-${Math.random().toString(16).slice(2, 10)}`;
    const clientResult = await db.execute(
      'INSERT INTO clients (client_unique_number, company_legal_name, billing_address, created_by) VALUES (:num, :name, :addr, NULL)',
      { num, name: 'Production Status Audit Test Buyer Ltd', addr: '1 Test Street, Test City' }
    );
    const clientId = clientResult.insertId;

    const [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id FROM currencies WHERE code = 'USD'"),
      db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"),
    ]);

    const orderResult = await db.execute(
      `INSERT INTO orders
         (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
          currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status)
       VALUES
         (:ref, :client_id, 1, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, 'TBC', 'NIL',
          CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'active')`,
      {
        ref: `NODEJEST-TEST-${Math.random().toString(16).slice(2, 10)}`,
        client_id: clientId,
        inquiry_ref: `NODEJEST-${Math.random().toString(16).slice(2, 8)}`,
        preset_id: preset.id,
        incoterm_id: incoterm.id,
        currency_id: currency.id,
      }
    );
    const newOrderId = orderResult.insertId;
    await orderStageRepository.initializeForOrder(newOrderId);
    return newOrderId;
  }

  async function postUpdate(fields) {
    const orderPage = await agent.get(`/orders/${orderId}`);
    const csrf = extractCsrf(orderPage.text);
    return agent
      .post(`/orders/${orderId}/production-status`)
      .type('form')
      .send({ _csrf: csrf, ...fields });
  }

  async function rowsFor(actionType) {
    const rows = (await auditLogRepository.forOrder(orderId))
      .filter((r) => r.action_type === actionType)
      .sort((a, b) => a.id - b.id);
    return rows;
  }

  it('logs the first update with the schema default as the old value', async () => {
    await postUpdate({ production_status_text: 'Cutting in progress — 20% complete' });

    const rows = await rowsFor('PRODUCTION_STATUS_UPDATED');
    expect(rows).toHaveLength(1);
    expect(rows[0].field_name).toBe('production_status_text');
    expect(rows[0].old_value).toBe('Not yet commenced');
    expect(rows[0].new_value).toBe('Cutting in progress — 20% complete');
  });

  it('captures the previous value as old_value on a subsequent update', async () => {
    await postUpdate({ production_status_text: 'Cutting in progress — 20% complete' });
    await postUpdate({ production_status_text: 'Polishing — 60% complete' });

    const rows = await rowsFor('PRODUCTION_STATUS_UPDATED');
    expect(rows).toHaveLength(2);
    expect(rows[1].old_value).toBe('Cutting in progress — 20% complete');
    expect(rows[1].new_value).toBe('Polishing — 60% complete');
  });

  it('does not create a duplicate log row when resubmitting the same text', async () => {
    await postUpdate({ production_status_text: 'Cutting in progress — 20% complete' });
    await postUpdate({ production_status_text: 'Cutting in progress — 20% complete' });

    expect(await rowsFor('PRODUCTION_STATUS_UPDATED')).toHaveLength(1);
  });

  it('logs an estimated shipment change under its own action type', async () => {
    await postUpdate({ est_shipment_date_text: 'Week of 15 October 2026' });

    const rows = await rowsFor('EST_SHIPMENT_DATE_UPDATED');
    expect(rows).toHaveLength(1);
    expect(rows[0].field_name).toBe('est_shipment_date_text');
    expect(rows[0].old_value).toBeNull();
    expect(rows[0].new_value).toBe('Week of 15 October 2026');
  });

  it('logs both fields from one submission', async () => {
    await postUpdate({
      production_status_text: 'Cutting in progress — 20% complete',
      est_shipment_date_text: 'Week of 15 October 2026',
    });

    expect(await rowsFor('PRODUCTION_STATUS_UPDATED')).toHaveLength(1);
    expect(await rowsFor('EST_SHIPMENT_DATE_UPDATED')).toHaveLength(1);
  });
});
