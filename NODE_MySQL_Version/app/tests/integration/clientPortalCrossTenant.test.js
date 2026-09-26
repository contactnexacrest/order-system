'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderCommentRepository = require('../../src/repositories/orderCommentRepository');
const clientPaymentReportRepository = require('../../src/repositories/clientPaymentReportRepository');
const { createTestClient, createTestOrder, createTestClientLogin, createTestFile } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * QA-4 P0.2 (docs/QA/TEST_PLAN.md Section 6): every /client/orders/{id}/...
 * and /client/documents/{id}/download route must refuse a client hitting
 * another client's order/document, never a silent cross-tenant read or an
 * existence-leaking 404. Real HTTP requests against the live Express app,
 * as Client A, hitting Client B's real resources — the natural fit for
 * this on the Node stack, where (unlike PHP) a full router/session/CSRF
 * stack is available to test through.
 */
describe('Client portal cross-tenant isolation (QA-4 P0.2)', () => {
  let agentA;
  let clientAId;
  let clientBId;
  let orderB;
  const emailA = `jest-tenant-a-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
  const emailB = `jest-tenant-b-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
  const password = 'JestTenantTest123!';

  beforeAll(async () => {
    clientAId = await createTestClient(emailA);
    clientBId = await createTestClient(emailB);
    await createTestClientLogin(clientAId, password);
    await createTestClientLogin(clientBId, password);
    orderB = await createTestOrder(clientBId);

    agentA = request.agent(app);
    const loginPage = await agentA.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agentA.post('/client/login').type('form').send({ _csrf: csrf, email: emailA, password });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).toBe('/client');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  // CSRF tokens are session-scoped, not order-scoped, and verifyCsrf runs
  // before the controller's ownership check in the route chain — so a
  // valid token must come from a page Client A can actually reach (the
  // dashboard), not from the order under test (which 404s for Client A
  // and carries no form at all).
  async function validCsrf() {
    const page = await agentA.get('/client/account');
    return extractCsrf(page.text);
  }

  it('showOrder blocks Client A from Client B\'s order', async () => {
    const res = await agentA.get(`/client/orders/${orderB}`);
    expect(res.status).toBe(404);
  });

  it('showReorderForm blocks Client A from Client B\'s order', async () => {
    const res = await agentA.get(`/client/orders/${orderB}/reorder`);
    expect(res.status).toBe(404);
  });

  it('submitReorder blocks Client A and creates no reorder request', async () => {
    const before = (await db.query('SELECT COUNT(*) AS c FROM order_reorder_requests'))[0].c;
    const csrf = await validCsrf();
    const res = await agentA
      .post(`/client/orders/${orderB}/reorder`)
      .type('form')
      .send({ _csrf: csrf, 'product_description[]': 'Attempted cross-tenant reorder' });
    expect(res.status).toBe(404);
    const after = (await db.query('SELECT COUNT(*) AS c FROM order_reorder_requests'))[0].c;
    expect(after).toBe(before);
  });

  it('reportPayment blocks Client A and creates no payment report', async () => {
    const before = (await db.query('SELECT COUNT(*) AS c FROM client_payment_reports'))[0].c;
    const csrf = await validCsrf();
    const res = await agentA
      .post(`/client/orders/${orderB}/report-payment`)
      .type('form')
      .send({ _csrf: csrf, payment_type: 'advance', transaction_ref: 'HACK-UTR-001' });
    expect(res.status).toBe(404);
    const after = (await db.query('SELECT COUNT(*) AS c FROM client_payment_reports'))[0].c;
    expect(after).toBe(before);
  });

  it('acknowledgeOc blocks Client A and does not advance Client B\'s order stage', async () => {
    const before = await db.query(
      `SELECT sm.stage_number, os.status FROM order_stages os JOIN stages_master sm ON sm.id = os.stage_id WHERE os.order_id = :id ORDER BY sm.stage_number`,
      { id: orderB }
    );
    const csrf = await validCsrf();
    const res = await agentA.post(`/client/orders/${orderB}/acknowledge-oc`).type('form').send({ _csrf: csrf });
    expect(res.status).toBe(404);
    const after = await db.query(
      `SELECT sm.stage_number, os.status FROM order_stages os JOIN stages_master sm ON sm.id = os.stage_id WHERE os.order_id = :id ORDER BY sm.stage_number`,
      { id: orderB }
    );
    expect(after).toEqual(before);
  });

  it('raiseDispute blocks Client A and creates no dispute', async () => {
    const before = (await db.query('SELECT COUNT(*) AS c FROM disputes'))[0].c;
    const csrf = await validCsrf();
    const res = await agentA
      .post(`/client/orders/${orderB}/disputes`)
      .type('form')
      .send({ _csrf: csrf, description: 'Attempted cross-tenant dispute' });
    expect(res.status).toBe(404);
    const after = (await db.query('SELECT COUNT(*) AS c FROM disputes'))[0].c;
    expect(after).toBe(before);
  });

  it('postComment blocks Client A and creates no comment', async () => {
    const before = (await db.query('SELECT COUNT(*) AS c FROM order_comments'))[0].c;
    const csrf = await validCsrf();
    const res = await agentA
      .post(`/client/orders/${orderB}/comments`)
      .type('form')
      .send({ _csrf: csrf, body: 'Attempted cross-tenant message' });
    expect(res.status).toBe(404);
    const after = (await db.query('SELECT COUNT(*) AS c FROM order_comments'))[0].c;
    expect(after).toBe(before);
  });

  it('downloadCommentAttachment blocks Client A from a real attachment on Client B\'s order', async () => {
    const fileId = await createTestFile(orderB, clientBId);
    const commentId = await orderCommentRepository.create(orderB, 'client', null, clientBId, "Client B's own message");
    await orderCommentRepository.attachFile(commentId, fileId);

    const res = await agentA.get(`/client/orders/${orderB}/comment-attachments/${fileId}/download`);
    expect(res.status).toBe(404);
    expect(res.text).not.toContain('test-file.pdf');
  });

  it('downloadPaymentScreenshot blocks Client A from a real report on Client B\'s order', async () => {
    const fileId = await createTestFile(orderB, clientBId);
    const reportId = await clientPaymentReportRepository.create(orderB, 'advance', 'REAL-UTR-001', null, 500.0, '2026-01-01', fileId);

    const res = await agentA.get(`/client/orders/${orderB}/payment-reports/${reportId}/screenshot`);
    expect(res.status).toBe(404);
  });

  it('downloadDocument blocks Client A from a real sent document on Client B\'s order', async () => {
    const docType = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
    const fileId = await createTestFile(orderB, clientBId);
    const docResult = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :dt, 'SC/QT/TEST/JEST001', 'sent', :pdf)`,
      { order_id: orderB, dt: docType.id, pdf: fileId }
    );

    const res = await agentA.get(`/client/documents/${docResult.insertId}/download`);
    expect(res.status).toBe(404);
    expect(res.text).not.toContain('test-file.pdf');
  });
});
