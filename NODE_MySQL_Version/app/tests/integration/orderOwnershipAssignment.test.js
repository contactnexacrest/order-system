'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const notificationRepository = require('../../src/repositories/notificationRepository');
const permissionRepository = require('../../src/repositories/permissionRepository');
const permissionCheck = require('../../src/middleware/permissionCheck');
const orderAssignmentService = require('../../src/services/orderAssignmentService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of OrderOwnershipAssignmentTest.php. Order ownership / assignment
 * visibility (see docs/migrations/2026-10-24b-order-owner-assignment.sql).
 * A single staff "owner" per order (orders.assigned_to), auto-set to the
 * creator, visible only to that owner plus the oversight tier (Admin,
 * Managing Director, Executive Director, Accounts Executive, Viewer /
 * Auditor — view_all_orders), reassignable only by Admin/MD/ED
 * (assign_order_owner). Deliberately visibility-only: never consulted by
 * the stage-gate/permission system, which this suite's own orthogonality
 * test exists specifically to prove.
 */
describe('Order ownership / assignment visibility', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createLoginableUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-ownership-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestOwnership123!';
    const passwordHash = await bcrypt.hash(password, 10);
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: passwordHash, role_id: role.id }
    );
    return { userId: result.insertId, roleId: role.id, email, password };
  }

  async function loginAs(roleName) {
    const { userId, roleId, email, password } = await createLoginableUser(roleName);
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    return { agent, userId, roleId };
  }

  async function seesAllOrdersFor(userId, roleId) {
    const permissions = await permissionRepository.effectivePermissions(userId, roleId);
    return !!permissions.view_all_orders;
  }

  async function callRequires(key, userId, permissions) {
    const req = { user: { id: userId }, permissions };
    let nextCalled = false;
    const res = { status() { return this; }, send() {} };
    await permissionCheck.requires(key)(req, res, () => { nextCalled = true; });
    return nextCalled;
  }

  // --- 1. Auto-assignment at creation ----------------------------------

  test('a new order is auto-assigned to its creator', async () => {
    const { agent, userId } = await loginAs('Export Executive');
    const clientId = await createTestClient();

    const [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id FROM currencies WHERE code = 'USD'"),
      db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"),
    ]);

    const createPage = await agent.get(`/orders/create?client_id=${clientId}`);
    const csrf = extractCsrf(createPage.text);
    const res = await agent.post('/orders').type('form').send({
      _csrf: csrf,
      client_id: String(clientId),
      incoterm_id: String(incoterm.id),
      currency_id: String(currency.id),
      payment_preset_id: String(preset.id),
      'product_description[]': 'Ownership auto-assignment test slab',
      'product_hs_code[]': '680293',
      'product_quantity[]': '10',
      'product_unit_price[]': '5.00',
    });
    expect(res.status).toBe(302);

    const row = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const order = await orderRepository.find(row.id);

    expect(Number(order.assigned_to)).toBe(userId);
    expect(Number(order.created_by)).toBe(userId);
  });

  // --- 2. Scoped visibility (Export/Logistics Executive) ----------------

  test('a scoped role sees only its own assigned orders in index and kanban', async () => {
    const exportUser = await createLoginableUser('Export Executive');
    const otherUser = await createLoginableUser('Export Executive');

    // createTestOrder() hardcodes sequence_no = 1, so two orders for the
    // SAME client in one test would collide on uq_client_sequence — a
    // separate client per order sidesteps that unrelated helper limitation.
    const mineId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, exportUser.userId);
    const theirsId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, otherUser.userId);

    const seesAll = await seesAllOrdersFor(exportUser.userId, exportUser.roleId);
    expect(seesAll).toBe(false);

    const list = await orderRepository.allVisibleTo(exportUser.userId, seesAll);
    const ids = list.map((o) => o.id);

    expect(ids).toContain(mineId);
    expect(ids).not.toContain(theirsId);
  });

  test('a scoped role kanban also only shows its own assigned orders', async () => {
    const logisticsUser = await createLoginableUser('Logistics Executive');
    const otherUser = await createLoginableUser('Logistics Executive');

    const mineId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, logisticsUser.userId);
    const theirsId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, otherUser.userId);

    const seesAll = await seesAllOrdersFor(logisticsUser.userId, logisticsUser.roleId);
    const list = await orderRepository.allVisibleTo(logisticsUser.userId, seesAll);
    const ids = list.map((o) => o.id);

    expect(ids).toContain(mineId);
    expect(ids).not.toContain(theirsId);
  });

  // --- 3. Oversight visibility -------------------------------------------

  test('oversight roles see every order regardless of assignment', async () => {
    const clientId = await createTestClient();
    const owner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, owner.userId);

    for (const roleName of ['Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'Viewer / Auditor']) {
      const viewer = await createLoginableUser(roleName);
      const seesAll = await seesAllOrdersFor(viewer.userId, viewer.roleId);
      expect(seesAll).toBe(true);

      const list = await orderRepository.allVisibleTo(viewer.userId, seesAll);
      const ids = list.map((o) => o.id);
      expect(ids).toContain(orderId);
    }
  });

  // --- 4. Direct access (/orders/:id) -------------------------------------

  test('direct access is 403 for a scoped non-owner and 200 for the owner and oversight roles', async () => {
    const clientId = await createTestClient();
    const owner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, owner.userId);

    const stranger = await loginAs('Logistics Executive');
    const strangerRes = await stranger.agent.get(`/orders/${orderId}`);
    expect(strangerRes.status).toBe(403);

    const ownerAgent = request.agent(app);
    const ownerLoginPage = await ownerAgent.get('/login');
    const ownerCsrf = extractCsrf(ownerLoginPage.text);
    const ownerLoginRes = await ownerAgent.post('/login').type('form').send({ _csrf: ownerCsrf, email: owner.email, password: owner.password });
    expect(ownerLoginRes.status).toBe(302);
    const ownerRes = await ownerAgent.get(`/orders/${orderId}`);
    expect(ownerRes.status).toBe(200);

    const admin = await loginAs('Admin');
    const adminRes = await admin.agent.get(`/orders/${orderId}`);
    expect(adminRes.status).toBe(200);
  });

  // --- 5. Assignment permission -------------------------------------------

  test('only Admin/MD/ED can pass the assign_order_owner gate', async () => {
    for (const roleName of ['Admin', 'Managing Director', 'Executive Director']) {
      const { userId, roleId } = await createLoginableUser(roleName);
      const permissions = await permissionRepository.effectivePermissions(userId, roleId);
      expect(await callRequires('assign_order_owner', userId, permissions)).toBe(true);
    }

    for (const roleName of ['Export Executive', 'Accounts Executive', 'Logistics Executive', 'Viewer / Auditor', 'CA / Chartered Accountant']) {
      const { userId, roleId } = await createLoginableUser(roleName);
      const permissions = await permissionRepository.effectivePermissions(userId, roleId);
      expect(await callRequires('assign_order_owner', userId, permissions)).toBe(false);
    }
  });

  test('the current non-privileged owner cannot pass the assign_order_owner gate either', async () => {
    // Being the order's own assignee grants no special reassignment right
    // of its own — only the assign_order_owner permission does.
    const owner = await createLoginableUser('Export Executive');
    const permissions = await permissionRepository.effectivePermissions(owner.userId, owner.roleId);
    expect(await callRequires('assign_order_owner', owner.userId, permissions)).toBe(false);
  });

  // --- 6. Write + notification ---------------------------------------------

  test('reassignment updates the owner and creates exactly one notification', async () => {
    const clientId = await createTestClient();
    const originalOwner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, originalOwner.userId);

    const newOwner = await createLoginableUser('Logistics Executive');
    const admin = await createLoginableUser('Admin');

    const before = (await notificationRepository.forUser(newOwner.userId)).length;
    await orderAssignmentService.reassign(orderId, newOwner.userId, admin.userId);
    const after = await notificationRepository.forUser(newOwner.userId);

    const order = await orderRepository.find(orderId);
    expect(Number(order.assigned_to)).toBe(newOwner.userId);
    expect(after.length).toBe(before + 1);
    expect(after[0].type).toBe('order_assigned');
  });

  test('reassigning to the same owner is a no-op and sends no notification', async () => {
    const clientId = await createTestClient();
    const owner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, owner.userId);
    const admin = await createLoginableUser('Admin');

    const before = (await notificationRepository.forUser(owner.userId)).length;
    await orderAssignmentService.reassign(orderId, owner.userId, admin.userId);
    const after = (await notificationRepository.forUser(owner.userId)).length;

    expect(after).toBe(before);
  });

  // --- 7. Orthogonality: assignment never touches the permission system ---

  test('an Accounts Executive not assigned can still reach the order and holds payment permissions', async () => {
    const clientId = await createTestClient();
    const exportOwner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, exportOwner.userId);

    const accounts = await loginAs('Accounts Executive');

    // Visibility: Accounts Executive is not the assigned owner, but
    // view_all_orders still lets them open it directly.
    const res = await accounts.agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    // Action permissions are completely unaffected by who owns the order
    // — this is the whole point of keeping assignment visibility-only.
    const permissions = await permissionRepository.effectivePermissions(accounts.userId, accounts.roleId);
    expect(!!permissions.manage_payments).toBe(true);
    expect(!!permissions.clear_payments).toBe(true);
  });

  test('an Export Executive without payment permission stays blocked regardless of owning the order', async () => {
    // The flip side: OWNING an order (being its assigned_to) grants no
    // action permission either — Export Executive can record a payment
    // (manage_payments) but never clear one (clear_payments), exactly as
    // before this feature, whether or not they're the order's assigned
    // owner.
    const clientId = await createTestClient();
    const exportUser = await createLoginableUser('Export Executive');
    await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, exportUser.userId);

    const permissions = await permissionRepository.effectivePermissions(exportUser.userId, exportUser.roleId);
    expect(!!permissions.manage_payments).toBe(true);
    expect(!!permissions.clear_payments).toBe(false);
  });
});
