'use strict';

const db = require('../../src/config/db');
const disputeController = require('../../src/controllers/disputeController');
const disputeRepository = require('../../src/repositories/disputeRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Task #40 — the Kanban UI template's Disputes board: one column per
 * dispute_status dropdown option (admin-configurable, in their own
 * order), rather than the fixed stage columns Orders uses. Same
 * uiTemplateService.resolve() switch as Task #37's Orders board.
 */
describe('Dispute Kanban board (Task #40)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Dispute Kanban Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-dispute-kanban-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
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
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; res.body = JSON.stringify(locals); };
    return res;
  }

  async function render(userId) {
    const user = await userRepository.findById(userId);
    const req = { query: {}, user, permissions: { view_all_orders: true, view_archived_orders: true } };
    const res = fakeRes();
    await disputeController.index(req, res);
    return res;
  }

  it('a user with no preference gets the standard list view, not the board', async () => {
    const userId = await createTestUser();

    const res = await render(userId);

    expect(res.view).toBe('disputes/index');
  });

  it('a user who prefers kanban gets the board view', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    expect(res.view).toBe('disputes/index_kanban');
    expect(res.locals.columns).toBeDefined();
  });

  it('the company default applies to a user with no personal preference', async () => {
    const userId = await createTestUser();
    await db.execute("UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'");

    try {
      const res = await render(userId);
      expect(res.view).toBe('disputes/index_kanban');
    } finally {
      await db.execute("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");
    }
  });

  it('a dispute appears in its own status column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const orderId = await createTestOrder(await createTestClient());
    await disputeRepository.create(orderId, '2026-10-23', 'Buyer', 'Carton arrived crushed', null, null);

    const res = await render(userId);

    expect(res.locals.columns.Open.disputes.some((d) => d.description === 'Carton arrived crushed')).toBe(true);
  });

  it("moving a dispute's status moves it to the new column", async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const orderId = await createTestOrder(await createTestClient());
    const disputeId = await disputeRepository.create(orderId, '2026-10-23', 'Buyer', 'Escalated issue text', null, null);
    await disputeRepository.updateStatus(disputeId, 'Escalated');

    const res = await render(userId);

    expect(res.locals.columns.Escalated.disputes.some((d) => d.id === disputeId)).toBe(true);
    expect(res.locals.columns.Open.disputes.some((d) => d.id === disputeId)).toBe(false);
  });

  it('every configured status column exists even when empty', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    for (const label of ['Open', 'Under Review', 'Resolved', 'Escalated']) {
      expect(res.locals.columns[label]).toBeDefined();
      expect(Array.isArray(res.locals.columns[label].disputes)).toBe(true);
    }
  });
});
