'use strict';

const db = require('../../src/config/db');
const complaintController = require('../../src/controllers/complaintController');
const orderComplaintRepository = require('../../src/repositories/orderComplaintRepository');
const orderComplaintService = require('../../src/services/orderComplaintService');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/** Task #44 — the Kanban UI template's Complaints board: Open / Acknowledged / Resolved columns. */
describe('Complaint Kanban board (Task #44)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Complaint Kanban Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-complaint-kanban-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function kanbanTemplateId() {
    return (await uiTemplateRepository.findByKey('kanban')).id;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, view: null, locals: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  async function render(userId) {
    const user = await userRepository.findById(userId);
    const req = { query: {}, user, permissions: { view_all_orders: true, view_archived_orders: true } };
    const res = fakeRes();
    await complaintController.index(req, res);
    return res;
  }

  it('a user with no preference gets the standard list view, not the board', async () => {
    const userId = await createTestUser();

    const res = await render(userId);

    expect(res.view).toBe('complaints/index');
  });

  it('a user who prefers kanban gets the board view', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    expect(res.view).toBe('complaints/index_kanban');
    expect(res.locals.columns).toBeDefined();
  });

  it('an open complaint appears in the open column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'Packaging was damp on arrival.');

    const res = await render(userId);

    expect(res.locals.columns.open.some((c) => c.id === complaintId)).toBe(true);
  });

  it('an acknowledged complaint moves to the acknowledged column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'Slow email replies last time.');
    await orderComplaintRepository.updateStatus(complaintId, 'acknowledged');

    const res = await render(userId);

    expect(res.locals.columns.acknowledged.some((c) => c.id === complaintId)).toBe(true);
    expect(res.locals.columns.open.some((c) => c.id === complaintId)).toBe(false);
  });

  it('a resolved complaint moves to the resolved column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const complaintId = await orderComplaintService.raiseAsClient(orderId, clientId, 'Already fixed by staff.');
    await orderComplaintRepository.updateStatus(complaintId, 'resolved');

    const res = await render(userId);

    expect(res.locals.columns.resolved.some((c) => c.id === complaintId)).toBe(true);
    expect(res.locals.columns.open.some((c) => c.id === complaintId)).toBe(false);
  });

  it('every status column exists even when empty', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    for (const status of ['open', 'acknowledged', 'resolved']) {
      expect(res.locals.columns[status]).toBeDefined();
      expect(Array.isArray(res.locals.columns[status])).toBe(true);
    }
  });
});
