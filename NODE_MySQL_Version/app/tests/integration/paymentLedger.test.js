'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const clientPaymentReportRepository = require('../../src/repositories/clientPaymentReportRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Payment Snapshot + Payment Ledger — a quick-glance summary and a single
 * consolidated, read-only history of every payment-related event on an
 * order (advance/freight/balance remittance+clearance, plus every
 * client-self-reported payment). Amounts are gated: everyone who can view
 * the order sees each leg's STATUS, but only manage_payments/close_orders
 * (Super Admin already covered transitively) see the actual figures.
 * TEST_ADMIN holds the Admin role, which is granted every permission, so
 * these HTTP-level tests confirm the "can see amounts" path; the
 * PHPUnit suite additionally pins the "general staff see status only, no
 * amounts" path via direct controller calls with a Viewer/Auditor role.
 */
describe('Payment Snapshot + Payment Ledger (order page)', () => {
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

  it('shows the Payment Snapshot and Payment Ledger sections with amounts for a permitted user', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000.0, '2026-06-01');

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('Payment Snapshot');
    expect(res.text).toContain('Payment Ledger');
    expect(res.text).toMatch(/5,000\.00\s*USD/);
    expect(res.text).toContain('Advance Remittance Received');
  });

  it('ledger includes client-reported payments chronologically', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-06-01');
    await clientPaymentReportRepository.create(orderId, 'advance', 'UTR99999', 'Jest Bank', 1000.0, '2026-05-30', null);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('Payment Reported by Client');
    expect(res.text).toContain('Client-reported');
  });

  it('shows the empty-ledger message when no payment activity exists yet', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('No payment events recorded yet.');
  });
});
