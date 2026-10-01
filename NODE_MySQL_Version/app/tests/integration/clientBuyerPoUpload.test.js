'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderBuyerPoDocumentRepository = require('../../src/repositories/orderBuyerPoDocumentRepository');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * "there must be an option on the client side, so he can upload the buyer
 * PO after signing" — the client portal now offers a self-service Buyer PO
 * upload, sharing order_buyer_po_documents (the same table the internal
 * staff upload writes to, see ordersController.uploadBuyerPoDocument) so
 * every copy — staff- or client-uploaded — shows up in one place with
 * version history. Cross-tenant blocking is covered in
 * clientPortalCrossTenant.test.js; this suite covers the legitimate-owner
 * path end-to-end, with a real multipart file upload through the live app
 * (Node's full router/multer stack makes this testable here, unlike PHP's
 * CLI test environment where move_uploaded_file() never succeeds).
 */
describe('Client Buyer PO self-upload', () => {
  let agent;
  let clientId;
  let orderId;
  const email = `jest-buyerpo-client-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
  const password = 'JestBuyerPoTest123!';

  beforeAll(async () => {
    clientId = await createTestClient(email);
    await createTestClientLogin(clientId, password);
    orderId = await createTestOrder(clientId);

    agent = request.agent(app);
    const loginPage = await agent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/client/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).toBe('/client');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function csrfFromOrderPage() {
    const page = await agent.get(`/client/orders/${orderId}`);
    return extractCsrf(page.text);
  }

  it('lets the real owner upload a Buyer PO copy and records it', async () => {
    const before = (await orderBuyerPoDocumentRepository.forOrder(orderId)).length;
    const csrf = await csrfFromOrderPage();

    const res = await agent
      .post(`/client/orders/${orderId}/buyer-po`)
      .field('_csrf', csrf)
      .attach('document', Buffer.from('%PDF-1.4 fake buyer po'), 'signed-buyer-po.pdf');

    expect(res.status).toBe(302);
    expect(res.headers.location).toBe(`/client/orders/${orderId}`);

    const after = await orderBuyerPoDocumentRepository.forOrder(orderId);
    expect(after.length).toBe(before + 1);
    expect(after[0].original_filename).toBe('signed-buyer-po.pdf');
  });

  it('shows the uploaded copy on the order page afterwards', async () => {
    const page = await agent.get(`/client/orders/${orderId}`);
    expect(page.text).toContain('Buyer PO');
    expect(page.text).toContain('signed-buyer-po.pdf');
  });

  it('shows an error and creates nothing when no file is selected', async () => {
    const before = (await orderBuyerPoDocumentRepository.forOrder(orderId)).length;
    const csrf = await csrfFromOrderPage();

    const res = await agent
      .post(`/client/orders/${orderId}/buyer-po`)
      .type('form')
      .send({ _csrf: csrf });

    expect(res.status).toBe(302);
    const after = await orderBuyerPoDocumentRepository.forOrder(orderId);
    expect(after.length).toBe(before);
  });
});
