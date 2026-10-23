'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderRepository = require('../../src/repositories/orderRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderKanbanBoardTest.php. Task #37 — the Kanban UI template's
 * real Orders list: every active order as a card in its own Stage 1-9
 * column, plus "Docs Sent" and "Complete" columns for the two closure
 * phases. Lost/Cancelled are rolled up into a compact summary rather
 * than given full board columns (they're dead ends, not pipeline
 * stages). Which template a user sees is driven entirely by
 * uiTemplateService.resolve() (built in Task #29) — this is the first
 * thing that actually consumes it to render a different view for the
 * same route.
 */
describe('Order Kanban board (Task #37)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Kanban Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-kanban-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
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
    const req = { query: {}, user, permissions: {} };
    const res = fakeRes();
    await ordersController.index(req, res);
    return res;
  }

  // ---------------------------------------------------------------
  // Which template actually renders
  // ---------------------------------------------------------------

  it('a user with no preference gets the standard list view, not the board', async () => {
    const userId = await createTestUser();

    const res = await render(userId);

    expect(res.view).toBe('orders/index');
  });

  it('a user who prefers kanban gets the board view', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    expect(res.view).toBe('orders/index_kanban');
    expect(res.locals.columns).toBeDefined();
  });

  it('the company default applies to a user with no personal preference', async () => {
    const userId = await createTestUser();
    await db.execute("UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'");

    try {
      const res = await render(userId);
      expect(res.view).toBe('orders/index_kanban');
    } finally {
      await db.execute("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");
    }
  });

  it('a personal preference overrides the company default', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    await db.execute("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");

    const res = await render(userId);

    expect(res.view).toBe('orders/index_kanban');
  });

  // ---------------------------------------------------------------
  // Board contents — correct column per order
  // ---------------------------------------------------------------

  it('a fresh order appears in the Stage 1 column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const orderId = await createTestOrder(await createTestClient());

    const res = await render(userId);

    const stage1Orders = res.locals.columns[1].orders;
    expect(stage1Orders.some((o) => Number(o.id) === orderId)).toBe(true);
    for (let stage = 2; stage <= 9; stage++) {
      expect(res.locals.columns[stage].orders.some((o) => Number(o.id) === orderId)).toBe(false);
    }
  });

  it('an order unlocked through stage 3 appears in the Stage 4 column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 3; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }

    const res = await render(userId);

    expect(res.locals.columns[4].orders.some((o) => Number(o.id) === orderId)).toBe(true);
  });

  it('shipped_docs_sent orders appear in their own column, not a stage column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: orderId });

    const res = await render(userId);

    expect(res.locals.shippedDocsSent.some((o) => Number(o.id) === orderId)).toBe(true);
    for (let stage = 1; stage <= 9; stage++) {
      expect(res.locals.columns[stage].orders.some((o) => Number(o.id) === orderId)).toBe(false);
    }
  });

  it('complete orders appear in the Complete column', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: orderId });

    const res = await render(userId);

    expect(res.locals.complete.some((o) => Number(o.id) === orderId)).toBe(true);
  });

  it('lost and cancelled orders are summarized as counts, not full columns', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());
    const lostOrderId = await createTestOrder(await createTestClient());
    const cancelledOrderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET status = 'lost' WHERE id = :id", { id: lostOrderId });
    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :id", { id: cancelledOrderId });

    const res = await render(userId);

    expect(res.locals.lostCount).toBeGreaterThanOrEqual(1);
    expect(res.locals.cancelledCount).toBeGreaterThanOrEqual(1);
    for (let stage = 1; stage <= 9; stage++) {
      expect(res.locals.columns[stage].orders.some((o) => Number(o.id) === lostOrderId)).toBe(false);
      expect(res.locals.columns[stage].orders.some((o) => Number(o.id) === cancelledOrderId)).toBe(false);
    }
    expect(res.locals.shippedDocsSent.some((o) => Number(o.id) === lostOrderId)).toBe(false);
    expect(res.locals.complete.some((o) => Number(o.id) === cancelledOrderId)).toBe(false);
  });

  it('every stage column exists even when empty', async () => {
    const userId = await createTestUser();
    await userRepository.updateUiTemplatePreference(userId, await kanbanTemplateId());

    const res = await render(userId);

    for (let stage = 1; stage <= 9; stage++) {
      expect(res.locals.columns[stage]).toBeDefined();
      expect(Array.isArray(res.locals.columns[stage].orders)).toBe(true);
    }
  });
});
