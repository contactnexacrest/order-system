'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientPortalController = require('../../src/controllers/clientPortalController');
const notificationRepository = require('../../src/repositories/notificationRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of NotificationAuditGapsTest.php. Task #35 — full notification
 * audit across the user's 0-17 export process sequence. The sequence's
 * own words are "SYSTEM MUST SEND NOTIFICATION FOR ALL ACTIONS TO
 * NOTIFICATION EMAIL AND CORROSPONDING PERSON STAFF/ADMIN/CLIENT AS AND
 * WHEN NEEDED. THIS IS NON NEGOTIABLE." Covers four client-initiated
 * actions that landed in a staff review queue (a passive nav-badge
 * count only) with no active notification: step 0 (Quotation-stage
 * intake), step 4d (PI-stage intake), step 14 (Order Feedback) and the
 * reorder-request flow.
 */
describe('Notification audit gaps (Task #35)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Notification Audit User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-notif-audit-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = () => {};
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, permissions: {}, ...overrides };
  }

  // ---------------------------------------------------------------
  // Step 0 — Quotation-stage public intake form
  // ---------------------------------------------------------------

  it('quotation intake submission notifies manage_orders holders', async () => {
    const userId = await createTestUser('Admin');

    const agent = request.agent(app);
    const formPage = await agent.get('/quotation-details');
    const csrf = extractCsrf(formPage.text);
    await agent.post('/quotation-details/submit').type('form').send({
      _csrf: csrf,
      company_legal_name: 'Notification Audit Test Co',
      billing_address_line1: '1 Test Street',
      billing_city: 'Testville',
      billing_country: 'United Kingdom',
      vat_eori_tax_no: 'GB123456789',
      contact_person: 'Jane Test',
      email: `jane-${Math.random().toString(16).slice(2, 8)}@example.test`,
      country_of_destination: 'Not sure',
      incoterm_preference: 'FOB',
    });

    const notifications = await notificationRepository.forUser(userId);
    const match = notifications.find((n) => n.type === 'client_intake_submitted');
    expect(match).toBeTruthy();

    const log = (await db.query("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CLIENT_INTAKE_SUBMITTED'"))[0];
    expect(Number(log.c)).toBeGreaterThan(0);
  });

  // ---------------------------------------------------------------
  // Step 4d — PI-stage public intake form
  // ---------------------------------------------------------------

  it('PI intake submission notifies manage_orders holders', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);

    const agent = request.agent(app);
    const formPage = await agent.get(`/pi-details/${token}`);
    const csrf = extractCsrf(formPage.text);
    await agent.post(`/pi-details/${token}`).type('form').send({
      _csrf: csrf,
      company_legal_name: 'PI Notification Test Ltd',
      billing_address_line1: '10 Billing Street',
      billing_city: 'Rotterdam',
      billing_country: 'Netherlands',
      vat_eori_tax_no: 'NL123456789B01',
      contact_person: 'Jamie Buyer',
      email: 'jamie@buyer.example',
      phone: '+31 10 123 4567',
      port_of_discharge_text: 'Rotterdam',
      country_of_destination: 'Netherlands',
      incoterm_confirmed: 'FOB',
      payment_terms_confirmation: 'CONFIRMED',
      quotation_acceptance_reference: 'We accept Quotation Z',
      coo_type: 'Non-preferential',
      confirm_lock: '1',
    });

    const notifications = await notificationRepository.forUser(userId);
    const match = notifications.find((n) => n.type === 'pi_intake_submitted' && Number(n.related_order_id) === orderId);
    expect(match).toBeTruthy();

    const log = (await db.query("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'PI_INTAKE_SUBMITTED' AND entity_type = 'orders' AND entity_id = :id", { id: orderId }))[0];
    expect(Number(log.c)).toBe(1);
  });

  // ---------------------------------------------------------------
  // Step 14 — Order Feedback
  // ---------------------------------------------------------------

  it('order feedback submission notifies manage_orders holders', async () => {
    const userId = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // External QA report N-12: feedback is only accepted once an order
    // has reached this status.
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: orderId });
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '4', comments: 'Great communication throughout.' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    const notifications = await notificationRepository.forUser(userId);
    const match = notifications.find((n) => n.type === 'order_feedback_submitted' && Number(n.related_order_id) === orderId);
    expect(match).toBeTruthy();
    expect(match.message).toContain('4/5');

    const log = (await db.query("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'ORDER_FEEDBACK_SUBMITTED' AND entity_type = 'orders' AND entity_id = :id", { id: orderId }))[0];
    expect(Number(log.c)).toBe(1);
  });

  // ---------------------------------------------------------------
  // Reorder request (same gap pattern, adjacent to the numbered steps)
  // ---------------------------------------------------------------

  it('reorder request submission notifies manage_orders holders', async () => {
    const userId = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { product_description: { 0: 'A reordered widget' } } });

    await clientPortalController.submitReorder(req, fakeRes());

    const notifications = await notificationRepository.forUser(userId);
    const match = notifications.find((n) => n.type === 'reorder_request_submitted' && Number(n.related_order_id) === orderId);
    expect(match).toBeTruthy();

    const log = (await db.query("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'REORDER_REQUEST_SUBMITTED' AND entity_type = 'orders' AND entity_id = :id", { id: orderId }))[0];
    expect(Number(log.c)).toBe(1);
  });

  // ---------------------------------------------------------------
  // A role with no manage_orders permission must receive none of these
  // ---------------------------------------------------------------

  it('a user without manage_orders receives no feedback notification', async () => {
    const viewerId = await createTestUser('Viewer / Auditor');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // External QA report N-12: feedback is only accepted once an order
    // has reached this status — set here so this test's "no
    // notification" assertion is about the permission gate, not a
    // vacuous pass from the submission being refused outright.
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: orderId });
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { rating: '5' } });

    await clientPortalController.submitFeedback(req, fakeRes());

    const notifications = await notificationRepository.forUser(viewerId);
    const match = notifications.find((n) => n.type === 'order_feedback_submitted');
    expect(match).toBeFalsy();
  });
});
