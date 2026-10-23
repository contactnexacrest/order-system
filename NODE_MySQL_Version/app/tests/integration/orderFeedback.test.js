'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const orderFeedbackRepository = require('../../src/repositories/orderFeedbackRepository');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of OrderFeedbackTest.php. docs/schema.sql Section BV — export
 * process step 14: client Order Feedback, a 1-5 star rating + comments,
 * one row per order.
 *
 * (Steps 15-16, the Complaint/Process-Issue feature that originally
 * tagged this same migration's Disputes table, moved to its own
 * orderComplaint.test.js — see docs/schema.sql Section BX.)
 */
describe('Order Feedback (Section BV)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Feedback Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-feedback-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null, view: null, locals: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, permissions: {}, ...overrides };
  }

  /** External QA report N-12: feedback is only offered once an order has reached this status. */
  async function markShippedDocsSent(orderId) {
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: orderId });
  }

  // ---------------------------------------------------------------
  // orderFeedbackRepository
  // ---------------------------------------------------------------

  it('find returns null when no feedback yet', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect(await orderFeedbackRepository.find(orderId)).toBeNull();
  });

  it('upsert creates then updates the same row', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    await orderFeedbackRepository.upsert(orderId, clientId, 4, 'Good service overall.');
    const first = await orderFeedbackRepository.find(orderId);
    expect(Number(first.rating)).toBe(4);
    expect(first.comments).toBe('Good service overall.');

    await orderFeedbackRepository.upsert(orderId, clientId, 5, 'Actually, excellent.');
    const second = await orderFeedbackRepository.find(orderId);
    expect(Number(second.rating)).toBe(5);
    expect(second.comments).toBe('Actually, excellent.');
    expect(second.id).toBe(first.id);

    const count = (await db.query('SELECT COUNT(*) AS c FROM order_feedback WHERE order_id = :id', { id: orderId }))[0].c;
    expect(Number(count)).toBe(1);
  });

  it('upsert allows null comments', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    await orderFeedbackRepository.upsert(orderId, clientId, 3, null);
    const row = await orderFeedbackRepository.find(orderId);
    expect(Number(row.rating)).toBe(3);
    expect(row.comments).toBeNull();
  });

  it('rating outside one to five violates the DB CHECK constraint', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    await expect(orderFeedbackRepository.upsert(orderId, clientId, 6, 'Should never reach the DB.')).rejects.toThrow();
  });

  // ---------------------------------------------------------------
  // clientPortalController.submitFeedback()
  // ---------------------------------------------------------------

  it('submitFeedback saves a valid rating', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await markShippedDocsSent(orderId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '5', comments: 'Excellent handling of our shipment.' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    const row = await orderFeedbackRepository.find(orderId);
    expect(Number(row.rating)).toBe(5);
    expect(row.comments).toBe('Excellent handling of our shipment.');
    expect(flash.pull(req)[0].type).toBe('success');
  });

  it('submitFeedback rejects a rating of zero', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await markShippedDocsSent(orderId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '0' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    expect(await orderFeedbackRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('submitFeedback rejects a rating of six', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await markShippedDocsSent(orderId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '6' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    expect(await orderFeedbackRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('submitFeedback rejects a non-numeric rating', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await markShippedDocsSent(orderId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: 'five' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    expect(await orderFeedbackRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('submitFeedback can be resubmitted and updates the same row', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await markShippedDocsSent(orderId);

    await clientPortalController.submitFeedback(fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '2', comments: 'Initial impression — slow replies.' } }), fakeRes());
    await clientPortalController.submitFeedback(fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '5', comments: 'Updating — issue was resolved quickly.' } }), fakeRes());

    const row = await orderFeedbackRepository.find(orderId);
    expect(Number(row.rating)).toBe(5);
    expect(row.comments).toBe('Updating — issue was resolved quickly.');
  });

  /**
   * External QA report N-12: step 14 is a post-shipment step — a client
   * could previously rate an order still in production/shipping.
   * createTestOrder() defaults to status 'active', so this exercises
   * that default directly (no markShippedDocsSent() call).
   */
  it('submitFeedback is refused on an active order', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '5', comments: 'Too early to say.' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    expect(await orderFeedbackRepository.find(orderId)).toBeNull();
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('submitFeedback returns 404 for an unknown order', async () => {
    const clientId = await createTestClient();
    const req = fakeReq({ params: { id: '999999999' }, session: { _client_portal_client_id: clientId }, body: { rating: '5' } });
    const res = fakeRes();

    await clientPortalController.submitFeedback(req, res);

    expect(res.statusCode).toBe(404);
  });

  // ---------------------------------------------------------------
  // ordersController.show() — orderFeedback is populated regardless of
  // manage_order_financials (rating/comments aren't financial data)
  // ---------------------------------------------------------------

  it('show() populates orderFeedback even without manage_order_financials', async () => {
    const userId = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderFeedbackRepository.upsert(orderId, clientId, 3, 'Average — packaging could improve.');
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: userId }, permissions: { manage_order_financials: false } });
    const res = fakeRes();

    await ordersController.show(req, res);

    expect(res.locals.orderFeedback).not.toBeNull();
    expect(Number(res.locals.orderFeedback.rating)).toBe(3);
  });

  // ---------------------------------------------------------------
  // View rendering — real HTTP, through the live app (supertest)
  // ---------------------------------------------------------------

  describe('rendered markup', () => {
    let agent;
    const clientEmail = `jest-feedback-client-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const clientPassword = 'JestFeedbackTest123!';
    let clientId;
    let orderId;

    beforeAll(async () => {
      clientId = await createTestClient(clientEmail);
      await createTestClientLogin(clientId, clientPassword);
      orderId = await createTestOrder(clientId);
      await markShippedDocsSent(orderId);

      agent = request.agent(app);
      const loginPage = await agent.get('/client/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await agent.post('/client/login').type('form').send({ _csrf: csrf, email: clientEmail, password: clientPassword });
      expect(loginRes.status).toBe(302);
    });

    it('client order page shows the feedback form with no prior submission', async () => {
      const res = await agent.get(`/client/orders/${orderId}`);
      expect(res.status).toBe(200);
      expect(res.text).toContain(`action="/client/orders/${orderId}/feedback"`);
      expect(res.text).toContain('name="rating"');
      expect(res.text).toContain('Submit Feedback');
    });

    it('client order page prefills an existing submission and offers update', async () => {
      await orderFeedbackRepository.upsert(orderId, clientId, 4, 'Already submitted once.');

      const res = await agent.get(`/client/orders/${orderId}`);
      expect(res.text).toContain('value="4" selected');
      expect(res.text).toContain('Already submitted once.');
      expect(res.text).toContain('Update Feedback');
    });

    /**
     * External QA report N-12: the form itself must not even be offered
     * on an active order. A separate client/order (not the shared one
     * above, already moved to shipped_docs_sent) — createTestOrder()
     * hardcodes sequence_no=1, so a second order for the SAME client
     * would collide on uq_client_sequence.
     */
    it('client order page hides the feedback form on an active order', async () => {
      const activeClientEmail = `jest-feedback-active-client-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
      const activeClientId = await createTestClient(activeClientEmail);
      await createTestClientLogin(activeClientId, clientPassword);
      const activeOrderId = await createTestOrder(activeClientId);

      const activeAgent = request.agent(app);
      const loginPage = await activeAgent.get('/client/login');
      const csrf = extractCsrf(loginPage.text);
      await activeAgent.post('/client/login').type('form').send({ _csrf: csrf, email: activeClientEmail, password: clientPassword });

      const res = await activeAgent.get(`/client/orders/${activeOrderId}`);

      expect(res.status).toBe(200);
      expect(res.text).not.toContain(`action="/client/orders/${activeOrderId}/feedback"`);
      expect(res.text).toContain('Feedback will be available once');
    });

    it('staff order page shows the Order Feedback section', async () => {
      const staffAgent = request.agent(app);
      const loginPage = await staffAgent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await staffAgent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
      expect(loginRes.status).toBe(302);

      const noFeedbackOrderId = await createTestOrder(await createTestClient());
      const res = await staffAgent.get(`/orders/${noFeedbackOrderId}`);
      expect(res.status).toBe(200);
      expect(res.text).toContain('Order Feedback');
      expect(res.text).toContain("hasn't submitted feedback");
    });
  });
});
