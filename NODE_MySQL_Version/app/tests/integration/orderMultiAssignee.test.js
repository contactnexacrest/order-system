'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderAssigneeRepository = require('../../src/repositories/orderAssigneeRepository');
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
 * Port of OrderMultiAssigneeTest.php. Order ownership / assignment
 * visibility, multi-assignee model (see docs/migrations/2026-10-24c-
 * order-multi-assignee.sql). Supersedes the single-owner orders.
 * assigned_to design with order_assignees: an order can have 0, 1, or
 * more assignees at once — e.g. an Export Executive on office work and a
 * Logistics Executive on CHA/factory work, concurrently, on the SAME
 * order — because a single owner column can never represent that. Still
 * visibility-only, never consulted by the stage-gate/action-permission
 * system, which this suite's own orthogonality test exists specifically
 * to prove.
 */
describe('Order multi-assignee visibility', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createLoginableUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-multiassign-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestMultiAssign123!';
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

  async function loginAsUser(email, password) {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.status).toBe(302);
    return agent;
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

  test('a new order is auto-assigned to its creator as sole assignee', async () => {
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
      'product_description[]': 'Multi-assignee auto-assignment test slab',
      'product_hs_code[]': '680293',
      'product_quantity[]': '10',
      'product_unit_price[]': '5.00',
    });
    expect(res.status).toBe(302);

    const row = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const assignees = await orderAssigneeRepository.forOrder(row.id);

    expect(assignees).toHaveLength(1);
    expect(Number(assignees[0].user_id)).toBe(userId);
  });

  // --- 2. Multi-assignee visibility: the actual scenario this model fixes ---

  test('an order assigned to both an Export and Logistics Executive is visible to both', async () => {
    const clientId = await createTestClient();
    const exportUser = await createLoginableUser('Export Executive');
    const logisticsUser = await createLoginableUser('Logistics Executive');
    const stranger = await createLoginableUser('Export Executive');

    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, [exportUser.userId, logisticsUser.userId]);

    const seesAllExport = await seesAllOrdersFor(exportUser.userId, exportUser.roleId);
    const exportList = await orderRepository.allVisibleTo(exportUser.userId, seesAllExport);
    expect(exportList.map((o) => o.id)).toContain(orderId);

    const seesAllLogistics = await seesAllOrdersFor(logisticsUser.userId, logisticsUser.roleId);
    const logisticsList = await orderRepository.allVisibleTo(logisticsUser.userId, seesAllLogistics);
    expect(logisticsList.map((o) => o.id)).toContain(orderId);

    const seesAllStranger = await seesAllOrdersFor(stranger.userId, stranger.roleId);
    const strangerList = await orderRepository.allVisibleTo(stranger.userId, seesAllStranger);
    expect(strangerList.map((o) => o.id)).not.toContain(orderId);
  });

  // --- 3. Scoped visibility still excludes non-assignees ------------------

  test('a scoped role sees only orders it is assigned to in index and kanban', async () => {
    const exportUser = await createLoginableUser('Export Executive');
    const otherUser = await createLoginableUser('Export Executive');

    const mineId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, exportUser.userId);
    const theirsId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, otherUser.userId);

    const seesAll = await seesAllOrdersFor(exportUser.userId, exportUser.roleId);
    expect(seesAll).toBe(false);

    const list = await orderRepository.allVisibleTo(exportUser.userId, seesAll);
    const ids = list.map((o) => o.id);

    expect(ids).toContain(mineId);
    expect(ids).not.toContain(theirsId);
  });

  // --- 4. Oversight visibility -------------------------------------------

  test('oversight roles see every order regardless of assignment', async () => {
    const clientId = await createTestClient();
    const owner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, owner.userId);

    for (const roleName of ['Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'Viewer / Auditor']) {
      const viewer = await createLoginableUser(roleName);
      const seesAll = await seesAllOrdersFor(viewer.userId, viewer.roleId);
      expect(seesAll).toBe(true);

      const list = await orderRepository.allVisibleTo(viewer.userId, seesAll);
      expect(list.map((o) => o.id)).toContain(orderId);
    }
  });

  // --- 5. Direct access (/orders/:id) -------------------------------------

  test('direct access is 403 for a non-assignee and 200 for every assignee and oversight role', async () => {
    const clientId = await createTestClient();
    const exportUser = await createLoginableUser('Export Executive');
    const logisticsUser = await createLoginableUser('Logistics Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, [exportUser.userId, logisticsUser.userId]);

    const stranger = await loginAs('Export Executive');
    const strangerRes = await stranger.agent.get(`/orders/${orderId}`);
    expect(strangerRes.status).toBe(403);

    const exportAgent = await loginAsUser(exportUser.email, exportUser.password);
    const exportRes = await exportAgent.get(`/orders/${orderId}`);
    expect(exportRes.status).toBe(200);

    const logisticsAgent = await loginAsUser(logisticsUser.email, logisticsUser.password);
    const logisticsRes = await logisticsAgent.get(`/orders/${orderId}`);
    expect(logisticsRes.status).toBe(200);

    const admin = await loginAs('Admin');
    const adminRes = await admin.agent.get(`/orders/${orderId}`);
    expect(adminRes.status).toBe(200);
  });

  // --- 6. Assignment permission -------------------------------------------

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

  test('an existing assignee themselves cannot pass the assign_order_owner gate either', async () => {
    // Being one of the order's own assignees grants no special
    // add/remove right of its own — only the assign_order_owner
    // permission does.
    const assignee = await createLoginableUser('Export Executive');
    const permissions = await permissionRepository.effectivePermissions(assignee.userId, assignee.roleId);
    expect(await callRequires('assign_order_owner', assignee.userId, permissions)).toBe(false);
  });

  // --- 7. Add is idempotent -----------------------------------------------

  test('adding an already-assigned user is a no-op with no duplicate row or notification', async () => {
    const clientId = await createTestClient();
    const owner = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, owner.userId);
    const admin = await createLoginableUser('Admin');

    const before = (await notificationRepository.forUser(owner.userId)).length;
    await orderAssignmentService.addAssignee(orderId, owner.userId, admin.userId);
    const after = (await notificationRepository.forUser(owner.userId)).length;

    expect(after).toBe(before);
    expect(await orderAssigneeRepository.forOrder(orderId)).toHaveLength(1);
  });

  // --- 8. Add/remove write + notification ---------------------------------

  test('add creates exactly one notification and remove creates none', async () => {
    const clientId = await createTestClient();
    const originalAssignee = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, originalAssignee.userId);

    const newAssignee = await createLoginableUser('Logistics Executive');
    const admin = await createLoginableUser('Admin');

    const before = (await notificationRepository.forUser(newAssignee.userId)).length;
    await orderAssignmentService.addAssignee(orderId, newAssignee.userId, admin.userId);
    const after = await notificationRepository.forUser(newAssignee.userId);

    expect(await orderAssigneeRepository.isAssignedTo(orderId, newAssignee.userId)).toBe(true);
    expect(after.length).toBe(before + 1);
    expect(after[0].type).toBe('order_assigned');

    const removeBefore = (await notificationRepository.forUser(originalAssignee.userId)).length;
    await orderAssignmentService.removeAssignee(orderId, originalAssignee.userId, admin.userId);
    const removeAfter = (await notificationRepository.forUser(originalAssignee.userId)).length;

    expect(await orderAssigneeRepository.isAssignedTo(orderId, originalAssignee.userId)).toBe(false);
    expect(removeAfter).toBe(removeBefore);
  });

  // --- 9. Zero-assignee state ----------------------------------------------

  test('removing every assignee leaves the order visible only to oversight roles', async () => {
    const clientId = await createTestClient();
    const soleAssignee = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, soleAssignee.userId);
    const admin = await createLoginableUser('Admin');

    await orderAssignmentService.removeAssignee(orderId, soleAssignee.userId, admin.userId);
    expect(await orderAssigneeRepository.forOrder(orderId)).toHaveLength(0);

    const removedAgent = await loginAsUser(soleAssignee.email, soleAssignee.password);
    const removedRes = await removedAgent.get(`/orders/${orderId}`);
    expect(removedRes.status).toBe(403);

    const adminAgent = await loginAs('Admin');
    const adminRes = await adminAgent.agent.get(`/orders/${orderId}`);
    expect(adminRes.status).toBe(200);
  });

  // --- 10. Orthogonality: assignment never touches the permission system --

  test('an Accounts Executive not assigned can still reach the order and holds payment permissions', async () => {
    const clientId = await createTestClient();
    const exportAssignee = await createLoginableUser('Export Executive');
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, exportAssignee.userId);

    const accounts = await loginAs('Accounts Executive');

    // Visibility: Accounts Executive is not an assignee, but
    // view_all_orders still lets them open it directly.
    const res = await accounts.agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    // Action permissions are completely unaffected by who's assigned to
    // the order — this is the whole point of keeping assignment
    // visibility-only.
    const permissions = await permissionRepository.effectivePermissions(accounts.userId, accounts.roleId);
    expect(!!permissions.manage_payments).toBe(true);
    expect(!!permissions.clear_payments).toBe(true);
  });

  test('an Export Executive without payment permission stays blocked regardless of being assigned', async () => {
    // The flip side: BEING an assignee grants no action permission
    // either — Export Executive can record a payment (manage_payments)
    // but never clear one (clear_payments), exactly as before this
    // feature, whether or not they're assigned to the order.
    const clientId = await createTestClient();
    const exportUser = await createLoginableUser('Export Executive');
    await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, exportUser.userId);

    const permissions = await permissionRepository.effectivePermissions(exportUser.userId, exportUser.roleId);
    expect(!!permissions.manage_payments).toBe(true);
    expect(!!permissions.clear_payments).toBe(false);
  });
});
