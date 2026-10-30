'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const complaintController = require('../../src/controllers/complaintController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const orderComplaintRepository = require('../../src/repositories/orderComplaintRepository');
const orderComplaintReplyRepository = require('../../src/repositories/orderComplaintReplyRepository');
const orderComplaintService = require('../../src/services/orderComplaintService');
const notificationRepository = require('../../src/repositories/notificationRepository');
const permissionService = require('../../src/services/permissionService');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of OrderComplaintTest.php. docs/schema.sql Section BX — Complaint
 * / Process Issue, split out of the Disputes table (dispute_type,
 * reverted). A Complaint means the client does NOT want a
 * refund/cancellation — the opposite of a Dispute — so it is always
 * available on any order (never gated by
 * dispute_button_visible_to_client) and is a genuine two-way
 * conversation, not a staff-only resolution log.
 */
describe('Order Complaint / Process Issue (Section BX)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Complaint Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-complaint-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id };
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

  // ---------------------------------------------------------------
  // Permissions
  // ---------------------------------------------------------------

  it('grants Export Executive respond_to_complaints but not manage_complaints', async () => {
    const user = await createTestUser('Export Executive');
    const permissions = await permissionService.load(user.id, user.roleId);
    expect(permissions.respond_to_complaints).toBe(true);
    expect(permissions.manage_complaints).toBeFalsy();
  });

  it('grants Admin both complaint permissions via the all-permissions cross join', async () => {
    const user = await createTestUser('Admin');
    const permissions = await permissionService.load(user.id, user.roleId);
    expect(permissions.manage_complaints).toBe(true);
    expect(permissions.respond_to_complaints).toBe(true);
  });

  it('grants Viewer / Auditor neither complaint permission by default', async () => {
    const user = await createTestUser('Viewer / Auditor');
    const permissions = await permissionService.load(user.id, user.roleId);
    expect(permissions.manage_complaints).toBeFalsy();
    expect(permissions.respond_to_complaints).toBeFalsy();
  });

  // ---------------------------------------------------------------
  // orderComplaintRepository
  // ---------------------------------------------------------------

  it('create and find roundtrip', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Packaging was damp on arrival.');
    const row = await orderComplaintRepository.find(complaintId);

    expect(row).not.toBeNull();
    expect(row.status).toBe('open');
    expect(row.description).toBe('Packaging was damp on arrival.');
    expect(row.resolved_at).toBeNull();
  });

  it('updateStatus to resolved sets resolved_at', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Slow email replies last time.');

    await orderComplaintRepository.updateStatus(complaintId, 'resolved');

    const row = await orderComplaintRepository.find(complaintId);
    expect(row.status).toBe('resolved');
    expect(row.resolved_at).not.toBeNull();
  });

  it('updateStatus back to open clears resolved_at', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Something went wrong.');
    await orderComplaintRepository.updateStatus(complaintId, 'resolved');

    await orderComplaintRepository.updateStatus(complaintId, 'open');

    expect((await orderComplaintRepository.find(complaintId)).resolved_at).toBeNull();
  });

  it('all() filters by status', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const openId = await orderComplaintRepository.create(orderId, clientId, 'Still open.');
    const resolvedId = await orderComplaintRepository.create(orderId, clientId, 'Already fixed.');
    await orderComplaintRepository.updateStatus(resolvedId, 'resolved');

    const open = (await orderComplaintRepository.all('open')).map((c) => c.id);
    const resolved = (await orderComplaintRepository.all('resolved')).map((c) => c.id);

    expect(open).toContain(openId);
    expect(open).not.toContain(resolvedId);
    expect(resolved).toContain(resolvedId);
  });

  // ---------------------------------------------------------------
  // orderComplaintReplyRepository — genuine two-way conversation
  // ---------------------------------------------------------------

  it('reply thread mixes staff and client oldest first', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Initial complaint.');
    const user = await createTestUser('Export Executive');

    await orderComplaintReplyRepository.createAsStaff(complaintId, user.id, 'We are looking into it.');
    await orderComplaintReplyRepository.createAsClient(complaintId, clientId, 'Thanks, appreciated.');

    const replies = await orderComplaintReplyRepository.forComplaint(complaintId);
    expect(replies.length).toBe(2);
    expect(replies[0].author_type).toBe('staff');
    expect(replies[0].body).toBe('We are looking into it.');
    expect(replies[0].staff_author_name).toBe('Jest Complaint Test User');
    expect(replies[1].author_type).toBe('client');
    expect(replies[1].body).toBe('Thanks, appreciated.');
  });

  // ---------------------------------------------------------------
  // orderComplaintService — notifications + audit log
  // ---------------------------------------------------------------

  it('raiseAsClient notifies every complaint-permission holder', async () => {
    const manager = await createTestUser('Admin');
    const responder = await createTestUser('Export Executive');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    await orderComplaintService.raiseAsClient(orderId, clientId, 'Boxes arrived slightly dented.');

    expect(await notificationRepository.unreadCountForUser(manager.id)).toBeGreaterThan(0);
    expect(await notificationRepository.unreadCountForUser(responder.id)).toBeGreaterThan(0);
  });

  it('raiseAsStaff does not duplicate notifications to self', async () => {
    const user = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const before = await notificationRepository.unreadCountForUser(user.id);
    await orderComplaintService.raiseAsStaff(orderId, clientId, user.id, 'Client called in with a concern.');

    expect(await notificationRepository.unreadCountForUser(user.id)).toBe(before);
    expect((await orderComplaintRepository.forOrder(orderId)).length).toBe(1);
  });

  it('replyAsClient notifies staff again', async () => {
    const manager = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'First message.');
    await notificationRepository.markAllRead(manager.id);

    await orderComplaintService.replyAsClient(complaintId, clientId, 'Following up on this.');

    expect(await notificationRepository.unreadCountForUser(manager.id)).toBeGreaterThan(0);
  });

  it('replyAsStaff does not throw even without a mail transport configured', async () => {
    const user = await createTestUser('Export Executive');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'Need a reply.');

    await orderComplaintService.replyAsStaff(complaintId, user.id, 'We will fix this next time.');

    const replies = await orderComplaintReplyRepository.forComplaint(complaintId);
    expect(replies.length).toBe(1);
    expect(replies[0].author_type).toBe('staff');
  });

  // ---------------------------------------------------------------
  // complaintController (staff side)
  // ---------------------------------------------------------------

  it('staff create logs a complaint on the client\'s behalf', async () => {
    const user = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: user.id }, body: { description: 'Client phoned in a packaging concern.' } });

    await complaintController.create(req, fakeRes());

    expect((await orderComplaintRepository.forOrder(orderId)).length).toBe(1);
    expect(flash.pull(req)[0].type).toBe('success');
  });

  it('staff create rejects an empty description', async () => {
    const user = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ params: { id: String(orderId) }, user: { id: user.id }, body: { description: '  ' } });

    await complaintController.create(req, fakeRes());

    expect((await orderComplaintRepository.forOrder(orderId)).length).toBe(0);
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('postReply rejects an empty body', async () => {
    const user = await createTestUser('Export Executive');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Something.');
    const req = fakeReq({ params: { complaintId: String(complaintId) }, user: { id: user.id }, body: { body: '' } });

    await complaintController.postReply(req, fakeRes());

    expect((await orderComplaintReplyRepository.forComplaint(complaintId)).length).toBe(0);
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('updateStatus rejects an invalid status value', async () => {
    const user = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Something.');
    const req = fakeReq({ params: { complaintId: String(complaintId) }, user: { id: user.id }, body: { status: 'not_a_real_status' } });

    await complaintController.updateStatus(req, fakeRes());

    expect((await orderComplaintRepository.find(complaintId)).status).toBe('open');
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('updateStatus accepts acknowledged', async () => {
    const user = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintRepository.create(orderId, clientId, 'Something.');
    const req = fakeReq({ params: { complaintId: String(complaintId) }, user: { id: user.id }, body: { status: 'acknowledged' } });

    await complaintController.updateStatus(req, fakeRes());

    expect((await orderComplaintRepository.find(complaintId)).status).toBe('acknowledged');
  });

  it('index filters by status', async () => {
    const user = await createTestUser('Admin');
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderComplaintRepository.create(orderId, clientId, 'Open one.');
    const req = fakeReq({ user: { id: user.id }, query: { status: 'open' }, permissions: { view_all_orders: true, view_archived_orders: true } });
    const res = fakeRes();

    await complaintController.index(req, res);

    expect(res.locals.complaints.some((c) => c.description === 'Open one.')).toBe(true);
  });

  // ---------------------------------------------------------------
  // clientPortalController — always available, never gated by the
  // dispute toggle.
  // ---------------------------------------------------------------

  it('raiseComplaint works even when the dispute button is off', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE orders SET dispute_button_visible_to_client = 0 WHERE id = :id', { id: orderId });
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { description: 'The delivery was a day late.' } });

    await clientPortalController.raiseComplaint(req, fakeRes());

    expect((await orderComplaintRepository.forOrder(orderId)).length).toBe(1);
    expect(flash.pull(req)[0].type).toBe('success');
  });

  it('raiseComplaint rejects an empty description', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId }, body: { description: '' } });

    await clientPortalController.raiseComplaint(req, fakeRes());

    expect((await orderComplaintRepository.forOrder(orderId)).length).toBe(0);
    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('raiseComplaint returns 404 for another client\'s order', async () => {
    const ownerClientId = await createTestClient();
    const orderId = await createTestOrder(ownerClientId);
    const otherClientId = await createTestClient();
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: otherClientId }, body: { description: "Trying to complain on someone else's order." } });
    const res = fakeRes();

    await clientPortalController.raiseComplaint(req, res);

    expect(res.statusCode).toBe(404);
    expect((await orderComplaintRepository.forOrder(orderId)).length).toBe(0);
  });

  it('postComplaintReply appends to the conversation', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'First message.');
    const req = fakeReq({ params: { id: String(orderId), complaintId: String(complaintId) }, session: { _client_portal_client_id: clientId }, body: { body: 'Any update on this?' } });

    await clientPortalController.postComplaintReply(req, fakeRes());

    const replies = await orderComplaintReplyRepository.forComplaint(complaintId);
    expect(replies.length).toBe(1);
    expect(replies[0].author_type).toBe('client');
    expect(flash.pull(req)[0].type).toBe('success');
  });

  it('postComplaintReply returns 404 for another client\'s complaint', async () => {
    const ownerClientId = await createTestClient();
    const orderId = await createTestOrder(ownerClientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, ownerClientId, 'Owner only.');
    const otherClientId = await createTestClient();
    const req = fakeReq({ params: { id: String(orderId), complaintId: String(complaintId) }, session: { _client_portal_client_id: otherClientId }, body: { body: "Trying to reply to someone else's complaint." } });
    const res = fakeRes();

    await clientPortalController.postComplaintReply(req, res);

    expect(res.statusCode).toBe(404);
    expect((await orderComplaintReplyRepository.forComplaint(complaintId)).length).toBe(0);
  });

  // ---------------------------------------------------------------
  // View rendering — the client portal section is always present,
  // regardless of dispute_button_visible_to_client.
  // ---------------------------------------------------------------

  it('client portal order page offers complaint form regardless of dispute toggle', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE orders SET dispute_button_visible_to_client = 0 WHERE id = :id', { id: orderId });
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId } });
    const res = fakeRes();

    await clientPortalController.showOrder(req, res);

    expect(res.locals.orderComplaints).toBeDefined();
    expect(res.view).toBe('client_portal/order_show');
  });

  it('client portal order page shows existing complaint conversation', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'Delivery was late.');
    const user = await createTestUser('Export Executive');
    await orderComplaintService.replyAsStaff(complaintId, user.id, 'Apologies — we are reviewing our courier.');
    const req = fakeReq({ params: { id: String(orderId) }, session: { _client_portal_client_id: clientId } });
    const res = fakeRes();

    await clientPortalController.showOrder(req, res);

    const complaint = res.locals.orderComplaints.find((c) => c.id === complaintId);
    expect(complaint).toBeDefined();
    expect(complaint.description).toBe('Delivery was late.');
    expect(complaint.replies[0].body).toBe('Apologies — we are reviewing our courier.');
  });

  // ---------------------------------------------------------------
  // View rendering — real HTTP, through the live app (supertest), so a
  // template syntax error in complaints/*.njk or order_show.njk would
  // actually fail a test here.
  // ---------------------------------------------------------------

  describe('rendered markup', () => {
    let agent;
    const clientEmail = `jest-complaint-client-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const clientPassword = 'JestComplaintTest123!';
    let clientId;
    let orderId;

    beforeAll(async () => {
      clientId = await createTestClient(clientEmail);
      await createTestClientLogin(clientId, clientPassword);
      orderId = await createTestOrder(clientId);
      await db.execute('UPDATE orders SET dispute_button_visible_to_client = 0 WHERE id = :id', { id: orderId });

      agent = request.agent(app);
      const loginPage = await agent.get('/client/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await agent.post('/client/login').type('form').send({ _csrf: csrf, email: clientEmail, password: clientPassword });
      expect(loginRes.status).toBe(302);
    });

    it('client order page offers the complaint form but not a dispute form', async () => {
      const res = await agent.get(`/client/orders/${orderId}`);
      expect(res.status).toBe(200);
      expect(res.text).toContain('Report a Process Issue');
      expect(res.text).toContain(`action="/client/orders/${orderId}/complaints"`);
      expect(res.text).not.toContain('Raise a Dispute');
    });

    it('staff Complaints nav link and list page render for Admin', async () => {
      const staffAgent = request.agent(app);
      const loginPage = await staffAgent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await staffAgent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
      expect(loginRes.status).toBe(302);

      const dashboardRes = await staffAgent.get('/');
      expect(dashboardRes.text).toContain('>Complaints<');

      const complaintsRes = await staffAgent.get('/complaints');
      expect(complaintsRes.status).toBe(200);
      expect(complaintsRes.text).toContain('Complaints (All Orders)');
    });

    it('staff order complaints page renders the conversation', async () => {
      const staffAgent = request.agent(app);
      const loginPage = await staffAgent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await staffAgent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
      expect(loginRes.status).toBe(302);

      await orderComplaintService.raiseAsClient(orderId, clientId, 'A recurring packaging issue.');

      const res = await staffAgent.get(`/orders/${orderId}/complaints`);
      expect(res.status).toBe(200);
      expect(res.text).toContain('A recurring packaging issue.');
    });
  });
});
