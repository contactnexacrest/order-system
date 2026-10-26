'use strict';

const fs = require('fs');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const clientPaymentReportRepository = require('../../src/repositories/clientPaymentReportRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const paymentActionService = require('../../src/services/paymentActionService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

/**
 * Port of PaymentActionLinkTest.php. Owner request (2026-10-27): a no-login
 * Payment Action Link — the client sends payment details for the advance
 * (PI), freight (Debit Note) and balance (CI), one payment at a time;
 * payment only, never the Buyer PO. The link also goes out inside the PI /
 * Debit Note / CI emails.
 */
describe('Payment Action Link (owner request 2026-10-27)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setup() {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute("UPDATE orders SET payment_method = 'CASH_ADVANCE' WHERE id = :id", { id: orderId });
    await orderProductRepository.add(orderId, 1, 'Absolute black granite', null, null, '50', false, 'PCS', '150.00');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    return { orderId, clientId, token: await orderRepository.generatePaymentActionToken(orderId) };
  }

  async function issuePi(orderId, clientId) {
    const fileId = await createTestFile(orderId, clientId);
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    const content = '%PDF-1.4 proforma';
    fs.writeFileSync(file.server_path, content);
    await db.execute('UPDATE file_store SET file_size_bytes = :n WHERE id = :id', { n: content.length, id: fileId });
    const documentId = await createTestDocument(orderId, 'PI', 'sent');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: fileId, id: documentId });
    return documentId;
  }

  it('shows only the payment due, one at a time', async () => {
    const { orderId, clientId, token } = await setup();
    let page = await request(app).get(`/payment-action/${token}`);
    expect(page.text).toContain('Nothing to pay right now');
    expect(page.text).not.toContain('name="transaction_ref"');
    expect(page.text).not.toContain('buyers_po_ref');

    await issuePi(orderId, clientId);
    page = await request(app).get(`/payment-action/${token}`);
    expect(page.text).toContain('Advance Payment');
    expect(page.text).toContain('USD 3,000.00');
    expect(page.text).toContain(`/payment-action/${token}/document`);
    const download = await request(app).get(`/payment-action/${token}/document`);
    expect(download.status).toBe(200);

    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-10-27', 1);
    page = await request(app).get(`/payment-action/${token}`);
    expect(page.text).toContain('balance payment');
    expect(page.text).toContain('Nothing to pay right now');
    expect((await paymentActionService.current(await orderRepository.find(orderId))).legs).toEqual({ advance: 'done', balance: 'current' });
  });

  it('records the client\'s payment details with a screenshot', async () => {
    const { orderId, clientId, token } = await setup();
    await issuePi(orderId, clientId);
    const agent = request.agent(app);
    let page = await agent.get(`/payment-action/${token}`);

    await agent.post(`/payment-action/${token}/report`)
      .field('_csrf', csrfOf(page.text)).field('transaction_ref', 'UTR-1').field('amount', '3000').field('payment_date', '2026-10-27');
    expect(await clientPaymentReportRepository.forOrder(orderId)).toHaveLength(0);

    page = await agent.get(`/payment-action/${token}`);
    expect(page.text).toContain('Attach the payment screenshot');
    await agent.post(`/payment-action/${token}/report`)
      .field('_csrf', csrfOf(page.text)).field('transaction_ref', 'UTR-1').field('amount', '3000').field('payment_date', '2026-10-27')
      .attach('screenshot', Buffer.from('%PDF-1.4 remittance'), 'remittance.pdf');
    const reports = await clientPaymentReportRepository.forOrder(orderId);
    expect(reports).toHaveLength(1);
    expect(reports[0].payment_type).toBe('advance');
    expect((await agent.get(`/payment-action/${token}`)).text).toContain("we're checking your advance payment");
  });

  it('shows the expired page for an unknown token', async () => {
    expect((await request(app).get('/payment-action/bogus')).text).toContain('Link No Longer Valid');
  });

  it('puts the payment link in the PI email', async () => {
    const { orderId, clientId, token } = await setup();
    const documentId = await issuePi(orderId, clientId);
    const admin = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });
    const preview = await emailDispatchService.buildPreview(orderId, documentId, 'send_pi', admin.id);
    expect(preview.body).toContain('upload the payment screenshot');
    expect(preview.body).toContain(`/payment-action/${token}`);
  });

  it('lets staff generate the link from the order page', async () => {
    const { orderId, token } = await setup();
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: csrfOf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const page = await agent.get(`/orders/${orderId}`);
    expect(page.text).toContain('Payment Action Link');
    expect(page.text).toContain(`/orders/${orderId}/payment-action-link`);
    await agent.post(`/orders/${orderId}/payment-action-link`).type('form').send({ _csrf: csrfOf(page.text) });
    expect(await orderRepository.findByValidPaymentActionToken(token)).toBeNull();
  });
});
