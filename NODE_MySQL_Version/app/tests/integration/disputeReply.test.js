'use strict';

const db = require('../../src/config/db');
const disputeController = require('../../src/controllers/disputeController');
const disputeReplyRepository = require('../../src/repositories/disputeReplyRepository');
const disputeRepository = require('../../src/repositories/disputeRepository');
const permissionCheck = require('../../src/middleware/permissionCheck');
const permissionService = require('../../src/services/permissionService');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Point 10 — docs/schema.sql Section AO: manage_disputes and
 * respond_to_disputes replace the old single manage_orders gate on every
 * Dispute action, and a dispute now has its own reply thread
 * (dispute_replies), separate from order_comments.
 */
describe('Dispute reply thread + granular dispute permissions (Point 10)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id };
  }

  function fakeRes() {
    const res = { redirectedTo: null, statusCode: null, body: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    return res;
  }

  // ---------------------------------------------------------------
  // Seed grants: Export Executive is the "sales agent" role — gets
  // respond_to_disputes but NOT manage_disputes (privileged-only by
  // default, per the user's explicit ask).
  // ---------------------------------------------------------------

  it('grants Export Executive respond_to_disputes but not manage_disputes', async () => {
    const user = await createTestUser('Export Executive');
    const permissions = await permissionService.load(user.id, user.roleId);
    expect(permissions.respond_to_disputes).toBe(true);
    expect(permissions.manage_disputes).toBeFalsy();
  });

  it('grants Admin both dispute permissions via the all-permissions cross join', async () => {
    const user = await createTestUser('Admin');
    const permissions = await permissionService.load(user.id, user.roleId);
    expect(permissions.manage_disputes).toBe(true);
    expect(permissions.respond_to_disputes).toBe(true);
  });

  it('grants Viewer / Auditor neither dispute permission by default', async () => {
    const user = await createTestUser('Viewer / Auditor');
    const permissions = await permissionService.load(user.id, user.roleId);
    expect(permissions.manage_disputes).toBeFalsy();
    expect(permissions.respond_to_disputes).toBeFalsy();
  });

  // ---------------------------------------------------------------
  // permissionCheck.requiresAny — the dispute page must be reachable by
  // someone who can only reply, as well as someone who manages disputes
  // broadly.
  // ---------------------------------------------------------------

  it('requiresAny passes for a user holding only one of the keys', async () => {
    const user = await createTestUser('Export Executive');
    const permissions = await permissionService.load(user.id, user.roleId);
    const req = { user: { id: user.id }, permissions };
    const res = fakeRes();
    let nextCalled = false;
    await permissionCheck.requiresAny(['manage_disputes', 'respond_to_disputes'])(req, res, () => { nextCalled = true; });
    expect(nextCalled).toBe(true);
    expect(res.statusCode).toBeNull();
  });

  it('requiresAny blocks a user holding neither key', async () => {
    const user = await createTestUser('Viewer / Auditor');
    const permissions = await permissionService.load(user.id, user.roleId);
    const req = { user: { id: user.id }, permissions };
    const res = fakeRes();
    let nextCalled = false;
    await permissionCheck.requiresAny(['manage_disputes', 'respond_to_disputes'])(req, res, () => { nextCalled = true; });
    expect(nextCalled).toBe(false);
    expect(res.statusCode).toBe(403);
  });

  // ---------------------------------------------------------------
  // Reply thread — its own table, not order_comments.
  // ---------------------------------------------------------------

  it('postReply creates a reply visible via forDispute', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const disputeId = await disputeRepository.create(orderId, new Date().toISOString().slice(0, 10), 'Buyer', 'Test dispute', null, null);
    const user = await createTestUser('Export Executive');

    const req = { params: { disputeId: String(disputeId) }, body: { body: 'We are looking into this and will respond by Friday.' }, user: { id: user.id }, session: {} };
    await disputeController.postReply(req, fakeRes());

    const replies = await disputeReplyRepository.forDispute(disputeId);
    expect(replies).toHaveLength(1);
    expect(replies[0].body).toBe('We are looking into this and will respond by Friday.');
    expect(replies[0].author_user_id).toBe(user.id);

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');
  });

  it('postReply rejects an empty body and inserts nothing', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const disputeId = await disputeRepository.create(orderId, new Date().toISOString().slice(0, 10), 'Buyer', 'Test dispute', null, null);
    const user = await createTestUser('Export Executive');

    const req = { params: { disputeId: String(disputeId) }, body: { body: '   ' }, user: { id: user.id }, session: {} };
    await disputeController.postReply(req, fakeRes());

    expect(await disputeReplyRepository.forDispute(disputeId)).toHaveLength(0);
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  it('orders replies oldest first', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const disputeId = await disputeRepository.create(orderId, new Date().toISOString().slice(0, 10), 'Buyer', 'Test dispute', null, null);
    const user = await createTestUser('Export Executive');

    await disputeReplyRepository.create(disputeId, user.id, 'First reply');
    await disputeReplyRepository.create(disputeId, user.id, 'Second reply');

    const replies = await disputeReplyRepository.forDispute(disputeId);
    expect(replies).toHaveLength(2);
    expect(replies[0].body).toBe('First reply');
    expect(replies[1].body).toBe('Second reply');
    expect(replies[0].author_name).toBe('Jest Test User');
  });
});
