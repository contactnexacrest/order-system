'use strict';

const db = require('../../src/config/db');
const reorderRequestController = require('../../src/controllers/reorderRequestController');
const orderReorderRequestRepository = require('../../src/repositories/orderReorderRequestRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/** Task #41 — the Kanban UI template's Reorder Requests board: Pending / Approved / Rejected columns. */
describe('Reorder Request Kanban board (Task #41)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Reorder Kanban Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-reorder-kanban-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
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
    const req = { query: {}, user, permissions: {} };
    const res = fakeRes();
    await reorderRequestController.index(req, res);
    return res;
  }

  it('a user with no preference gets the standard list view, not the board', async () => {
    const userId = await createTestUser();

    const res = await render(userId);

    expect(res.view).toBe('reorder_requests/index');
  });

  it('a user who prefers kanban gets the board view', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    expect(res.view).toBe('reorder_requests/index_kanban');
    expect(res.locals.pending).toBeDefined();
  });

  it('a pending request appears in the pending column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    const requestId = await orderReorderRequestRepository.create(clientId, sourceOrderId, 'Please reorder the same items.');

    const res = await render(userId);

    expect(res.locals.pending.some((r) => r.id === requestId)).toBe(true);
    expect(res.locals.approved.some((r) => r.id === requestId)).toBe(false);
  });

  it('an approved request moves to the approved column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    const requestId = await orderReorderRequestRepository.create(clientId, sourceOrderId, 'Approve me please.');
    const newOrderId = await createTestOrder(await createTestClient());
    await orderReorderRequestRepository.markApproved(requestId, userId, newOrderId);

    const res = await render(userId);

    expect(res.locals.approved.some((r) => r.id === requestId)).toBe(true);
    expect(res.locals.pending.some((r) => r.id === requestId)).toBe(false);
  });

  it('a rejected request moves to the rejected column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    const requestId = await orderReorderRequestRepository.create(clientId, sourceOrderId, 'Reject me please.');
    await orderReorderRequestRepository.markRejected(requestId, userId, 'Pricing no longer valid.');

    const res = await render(userId);

    expect(res.locals.rejected.some((r) => r.id === requestId)).toBe(true);
    expect(res.locals.pending.some((r) => r.id === requestId)).toBe(false);
  });
});
