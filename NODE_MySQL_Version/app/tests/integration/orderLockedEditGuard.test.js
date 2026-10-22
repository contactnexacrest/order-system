'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderRepository = require('../../src/repositories/orderRepository');
const orderEditGuard = require('../../src/services/orderEditGuard');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-oleg-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

/**
 * Port of OrderLockedEditGuardTest.php. 90-item backlog Phase 6 Item 4 —
 * is_locked (an order closed via Stage 9, or marked lost) previously had
 * teeth for document generation but NOT for the order's own core
 * details or product lines: orderEditGuard.allow() only ever asked "has
 * Stage 4 passed," never "is this order locked." This adds a second,
 * stricter check in front of the existing stage-4 one — once is_locked,
 * editing needs edit_locked_data specifically, plus a mandatory reason,
 * logged as ORDER_EDITED_WHILE_LOCKED.
 */
describe('Order locked edit guard (90-item backlog Phase 6 Item 4)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function lockOrder(orderId) {
    await orderRepository.markComplete(orderId);
    expect((await orderRepository.find(orderId)).is_locked).toBeTruthy();
  }

  async function otherCurrencyId(excludeId) {
    const row = await db.queryOne('SELECT id FROM currencies WHERE id != :id LIMIT 1', { id: excludeId });
    return row.id;
  }

  async function firstActiveHsCode() {
    const row = await db.queryOne('SELECT code FROM hs_codes WHERE is_active = 1 LIMIT 1');
    return row.code;
  }

  it('allow refuses a locked order edit without edit_locked_data permission', async () => {
    const userId = await createTestUser('Export Executive');
    const req = { session: {}, user: { id: userId }, permissions: { edit_locked_data: false } };
    expect(await orderEditGuard.allow(req, 9, true, 'A genuine reason, long enough.', 'orders', 1, 'order_details')).toBe(false);
  });

  it('allow refuses a locked order edit with too short a reason', async () => {
    const userId = await createTestUser('Admin');
    const req = { session: {}, user: { id: userId }, permissions: { edit_locked_data: true } };
    expect(await orderEditGuard.allow(req, 9, true, 'short', 'orders', 1, 'order_details')).toBe(false);
  });

  it('allow permits a locked order edit with permission and a reason', async () => {
    const userId = await createTestUser('Admin');
    const req = { session: {}, user: { id: userId }, permissions: { edit_locked_data: true } };
    expect(await orderEditGuard.allow(req, 9, true, 'Correcting a genuine mistake found after closure.', 'orders', 1, 'order_details')).toBe(true);
  });

  it('allow ignores stage entirely when not locked', async () => {
    // stage 9 but not locked — the existing post-confirmation (stage >= 4) path still governs, unaffected.
    const userId = await createTestUser('Export Executive');
    const req = { session: {}, user: { id: userId }, permissions: { edit_locked_data: false, edit_order_post_confirmation: false } };
    expect(await orderEditGuard.allow(req, 9, false, 'A genuine reason, long enough.', 'orders', 1, 'order_details')).toBe(false);
  });

  it('updateDetails refuses on a locked order without edit_locked_data', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await lockOrder(orderId);
    const original = await orderRepository.find(orderId);
    const userId = await createTestUser('Export Executive');
    const newCurrencyId = await otherCurrencyId(original.currency_id);
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId },
      permissions: { edit_locked_data: false },
      body: { incoterm_id: String(original.incoterm_id), currency_id: String(newCurrencyId), reason: 'Trying to edit a closed order.' },
    };

    await ordersController.updateDetails(req, fakeRes());

    expect((await orderRepository.find(orderId)).currency_id).toBe(original.currency_id);
  });

  it('updateDetails succeeds on a locked order with edit_locked_data and a reason', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await lockOrder(orderId);
    const original = await orderRepository.find(orderId);
    const userId = await createTestUser('Admin');
    const newCurrencyId = await otherCurrencyId(original.currency_id);
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId },
      permissions: { edit_locked_data: true },
      body: { incoterm_id: String(original.incoterm_id), currency_id: String(newCurrencyId), reason: 'Correcting a genuine currency mistake found after closure.' },
    };

    await ordersController.updateDetails(req, fakeRes());

    expect((await orderRepository.find(orderId)).currency_id).toBe(newCurrencyId);

    const log = await db.queryOne(
      "SELECT * FROM audit_log WHERE action_type = 'ORDER_EDITED_WHILE_LOCKED' AND entity_type = 'orders' AND entity_id = :id",
      { id: orderId }
    );
    expect(log).toBeTruthy();
  });

  it('addProduct refuses on a locked order without edit_locked_data', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await lockOrder(orderId);
    const userId = await createTestUser('Export Executive');
    const hsCode = await firstActiveHsCode();
    const before = await db.queryOne('SELECT COUNT(*) AS c FROM order_products WHERE order_id = :id', { id: orderId });
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId },
      permissions: { edit_locked_data: false },
      body: { description: 'Granite Slab', hs_code: hsCode, reason: 'Trying to add a line to a closed order.' },
    };

    await ordersController.addProduct(req, fakeRes());

    const after = await db.queryOne('SELECT COUNT(*) AS c FROM order_products WHERE order_id = :id', { id: orderId });
    expect(after.c).toBe(before.c);
  });
});
