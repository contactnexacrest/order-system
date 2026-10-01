'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const complianceTaskTypeRepository = require('../../src/repositories/complianceTaskTypeRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Compliance/pre-closure task checklist (docs/schema.sql Section AR) —
 * "the person who has permission to close the order must able to see this
 * otherwise no meaning for this." Gated entirely on the existing
 * close_orders permission (Export Executive, Logistics Executive, Admin/MD/
 * ED, Super Admin by default) — no new permission for the checklist itself.
 */
describe('Compliance checklist (per order)', () => {
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
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  function findRow(rows, taskTypeId) {
    const row = rows.find((r) => r.task_type_id === taskTypeId);
    if (!row) throw new Error(`No row found for task_type_id ${taskTypeId}`);
    return row;
  }

  it('forOrder defaults missing rows to not_started', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));

    const row = findRow(await orderComplianceTaskRepository.forOrder(orderId), taskTypeId);
    expect(row.status).toBe('not_started');
    expect(row.resolved_at).toBeNull();
  });

  it('setStatus then forOrder reflects the new status', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));
    const userId = await createTestUser('Export Executive');

    await orderComplianceTaskRepository.setStatus(orderId, taskTypeId, 'approved', null, userId);

    const row = findRow(await orderComplianceTaskRepository.forOrder(orderId), taskTypeId);
    expect(row.status).toBe('approved');
    expect(row.resolved_at).not.toBeNull();
    expect(row.resolved_by_name).toBe('Jest Test User');
  });

  it('setStatus twice upserts rather than duplicating', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));
    const userId = await createTestUser('Export Executive');

    await orderComplianceTaskRepository.setStatus(orderId, taskTypeId, 'pending_approval', null, userId);
    await orderComplianceTaskRepository.setStatus(orderId, taskTypeId, 'approved', null, userId);

    const matches = (await orderComplianceTaskRepository.forOrder(orderId)).filter((r) => r.task_type_id === taskTypeId);
    expect(matches).toHaveLength(1);
  });

  it('summary counts skipped as resolved alongside approved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const adminId = await createTestUser('Admin');
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', adminId);
    const otherTypeId = await complianceTaskTypeRepository.create('Jest Fumigation Certificate', adminId);
    const userId = await createTestUser('Export Executive');
    const totalActiveTypes = (await complianceTaskTypeRepository.all(false)).length;

    await orderComplianceTaskRepository.setStatus(orderId, taskTypeId, 'approved', null, userId);
    await orderComplianceTaskRepository.setStatus(orderId, otherTypeId, 'skipped', 'Not applicable to this buyer', userId);

    const summary = await orderComplianceTaskRepository.summaryForOrder(orderId);
    expect(summary.total).toBe(totalActiveTypes);
    expect(summary.approved).toBe(2);
    expect(summary.outstanding).toBe(totalActiveTypes - 2);
  });

  it('controller rejects a user without close_orders permission', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));
    const userId = await createTestUser('Accounts Executive');

    const req = { params: { id: String(orderId) }, body: { task_type_id: String(taskTypeId), status: 'approved' }, user: { id: userId }, permissions: {}, session: {} };
    await ordersController.updateComplianceTask(req, fakeRes());

    const row = findRow(await orderComplianceTaskRepository.forOrder(orderId), taskTypeId);
    expect(row.status).toBe('not_started');
  });

  it('controller allows a user with close_orders permission', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));
    const userId = await createTestUser('Export Executive');

    const req = { params: { id: String(orderId) }, body: { task_type_id: String(taskTypeId), status: 'approved' }, user: { id: userId }, permissions: { close_orders: true }, session: {} };
    await ordersController.updateComplianceTask(req, fakeRes());

    const row = findRow(await orderComplianceTaskRepository.forOrder(orderId), taskTypeId);
    expect(row.status).toBe('approved');
  });

  it('skipped status without a reason is rejected', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));
    const userId = await createTestUser('Export Executive');

    const req = { params: { id: String(orderId) }, body: { task_type_id: String(taskTypeId), status: 'skipped' }, user: { id: userId }, permissions: { close_orders: true }, session: {} };
    await ordersController.updateComplianceTask(req, fakeRes());

    const row = findRow(await orderComplianceTaskRepository.forOrder(orderId), taskTypeId);
    expect(row.status).toBe('not_started');
  });

  it('skipped status with a reason succeeds and is audit logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const taskTypeId = await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));
    const userId = await createTestUser('Export Executive');

    const req = {
      params: { id: String(orderId) },
      body: { task_type_id: String(taskTypeId), status: 'skipped', skip_reason: 'Buyer exempt under scheme X' },
      user: { id: userId },
      permissions: { close_orders: true },
      session: {},
    };
    await ordersController.updateComplianceTask(req, fakeRes());

    const row = findRow(await orderComplianceTaskRepository.forOrder(orderId), taskTypeId);
    expect(row.status).toBe('skipped');
    expect(row.skip_reason).toBe('Buyer exempt under scheme X');

    const logRows = (await auditLogRepository.forOrder(orderId)).filter((r) => r.action_type === 'COMPLIANCE_TASK_STATUS_UPDATED');
    expect(logRows).toHaveLength(1);
    expect(logRows[0].user_id).toBe(userId);
  });

  function extractCsrf(html) {
    const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
    if (!m) throw new Error('CSRF token not found in response HTML');
    return m[1];
  }

  it('order page shows the checklist only to users with close_orders permission', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await complianceTaskTypeRepository.create('Jest ECGC Cover', await createTestUser('Admin'));

    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('Compliance Checklist');
    expect(res.text).toContain('Jest ECGC Cover');
  });
});
