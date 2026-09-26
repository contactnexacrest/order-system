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

function extractFlash(html) {
  const m = html.match(/class="alert alert-(error|success)"[^>]*>([^<]*)/);
  return m ? { type: m[1], text: m[2].trim() } : null;
}

async function stageStatuses(orderId) {
  const rows = await db.query(
    `SELECT sm.stage_number, os.status FROM order_stages os
     JOIN stages_master sm ON sm.id = os.stage_id
     WHERE os.order_id = :order_id ORDER BY sm.stage_number`,
    { order_id: orderId }
  );
  return rows.reduce((acc, r) => { acc[r.stage_number] = r.status; return acc; }, {});
}

describe('Stage-gate integrity (QA-1 regression): closeOrder + other stage-skip endpoints', () => {
  let agent;
  let orderId;

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

    // Fresh client + order, created through the real HTTP endpoints (not
    // inserted directly), so the order is in the exact state a genuine new
    // order is in: Stage 1 in_progress, Stages 2-9 locked.
    const createClientPage = await agent.get('/clients/create');
    const clientCsrf = extractCsrf(createClientPage.text);
    const clientRes = await agent
      .post('/clients')
      .type('form')
      .send({
        _csrf: clientCsrf,
        company_legal_name: 'QA-1 Regression Test Buyer Ltd',
        billing_address: '1 Test Street, Test City',
      });
    expect(clientRes.status).toBe(302);
    const clientId = parseInt(clientRes.headers.location.match(/\/clients\/(\d+)/)[1], 10);
    expect(clientId).toBeGreaterThan(0);

    const [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id FROM currencies WHERE code = 'USD'"),
      db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"),
    ]);

    const createOrderPage = await agent.get(`/orders/create?client_id=${clientId}`);
    const orderCsrf = extractCsrf(createOrderPage.text);
    const orderRes = await agent
      .post('/orders')
      .type('form')
      .send({
        _csrf: orderCsrf,
        client_id: String(clientId),
        incoterm_id: String(incoterm.id),
        currency_id: String(currency.id),
        payment_preset_id: String(preset.id),
        'product_description[]': 'Test granite slab, polished',
        'product_hs_code[]': '680293',
      });
    expect(orderRes.status).toBe(302);
    orderId = parseInt(orderRes.headers.location.match(/\/orders\/(\d+)/)[1], 10);
    expect(orderId).toBeGreaterThan(0);

    const stages = await stageStatuses(orderId);
    expect(stages[1]).toBe('in_progress');
    expect(stages[9]).toBe('locked');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  // QA-4 (extending QA-1 per docs/QA/TEST_PLAN.md P0.1): every one of the
  // 8 stage-advancing routes (Stage 1 has no predecessor to skip) must
  // refuse on this freshly created order (only Stage 1 in_progress) — not
  // just the ones QA-1's first pass happened to cover.
  const advancingRoutes = [
    { stage: 2, label: 'recordBuyerPo', path: 'buyer-po', fields: { buyers_po_ref: 'HACK-BPO-001' } },
    { stage: 3, label: 'clearAdvancePayment', path: 'payment/advance/clear', fields: { advance_cleared_at: '2026-01-01' } },
    { stage: 4, label: 'recordOcAcknowledgment', path: 'oc-acknowledgment', fields: { acknowledged_note: 'Forged evidence, at least ten characters.' } },
    { stage: 5, label: 'confirmSupplierSigned', path: 'supplier-po/signed', fields: {} },
    { stage: 6, label: 'clearFreightPayment', path: 'payment/freight/clear', fields: {} },
    { stage: 7, label: 'recordBlIssued', path: 'bl-issued', fields: { bl_number: 'HACK-BL-001', bl_date: '2026-01-01' } },
    { stage: 8, label: 'clearBalancePayment', path: 'payment/balance/clear', fields: { balance_cleared_at: '2026-01-01' } },
    { stage: 9, label: 'closeOrder', path: 'close', fields: { courier_tracking_number: 'HACK-TRACKING-001' } },
  ];

  it.each(advancingRoutes)('refuses $label (Stage $stage) on a freshly created order, and mutates nothing', async ({ path, fields }) => {
    const before = await stageStatuses(orderId);
    expect(before[1]).toBe('in_progress'); // Stage 1 only passes via QT generation, not exercised here

    const orderPage = await agent.get(`/orders/${orderId}`);
    const csrf = extractCsrf(orderPage.text);
    const res = await agent
      .post(`/orders/${orderId}/${path}`)
      .type('form')
      .send({ _csrf: csrf, ...fields });

    expect(res.status).toBe(302);
    const redirected = await agent.get(res.headers.location);
    const flash = extractFlash(redirected.text);
    expect(flash).toEqual({ type: 'error', text: expect.stringContaining('has not been unlocked') });

    const after = await stageStatuses(orderId);
    expect(after).toEqual(before);
  });
});
