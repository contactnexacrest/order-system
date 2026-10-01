'use strict';

const db = require('../../src/config/db');
const complianceTaskTypeRepository = require('../../src/repositories/complianceTaskTypeRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Admin-editable compliance/pre-closure task-type list (docs/schema.sql
 * Section AR) — covers the CRUD surface, including that deleting a type
 * still referenced by order_compliance_tasks is blocked by the FK (the
 * controller catches this and tells the admin to deactivate instead).
 */
describe('ComplianceTaskTypeRepository', () => {
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

  it('create then find returns all fields', async () => {
    const userId = await createTestUser('Admin');
    const id = await complianceTaskTypeRepository.create('Jest Test Task', userId);

    const row = await complianceTaskTypeRepository.find(id);
    expect(row.name).toBe('Jest Test Task');
    expect(row.is_active).toBe(1);
    expect(row.created_by).toBe(userId);
  });

  it('update overwrites name', async () => {
    const userId = await createTestUser('Admin');
    const id = await complianceTaskTypeRepository.create('Jest Original Name', userId);

    await complianceTaskTypeRepository.update(id, 'Jest Renamed');

    expect((await complianceTaskTypeRepository.find(id)).name).toBe('Jest Renamed');
  });

  it('toggleActive flips both ways', async () => {
    const userId = await createTestUser('Admin');
    const id = await complianceTaskTypeRepository.create('Jest Toggle Task', userId);
    expect((await complianceTaskTypeRepository.find(id)).is_active).toBe(1);

    await complianceTaskTypeRepository.toggleActive(id);
    expect((await complianceTaskTypeRepository.find(id)).is_active).toBe(0);

    await complianceTaskTypeRepository.toggleActive(id);
    expect((await complianceTaskTypeRepository.find(id)).is_active).toBe(1);
  });

  it('all excludes inactive by default but includes them on request', async () => {
    const userId = await createTestUser('Admin');
    const activeId = await complianceTaskTypeRepository.create('Jest Active Task', userId);
    const inactiveId = await complianceTaskTypeRepository.create('Jest Inactive Task', userId);
    await complianceTaskTypeRepository.toggleActive(inactiveId);

    const activeOnlyIds = (await complianceTaskTypeRepository.all(false)).map((r) => r.id);
    expect(activeOnlyIds).toContain(activeId);
    expect(activeOnlyIds).not.toContain(inactiveId);

    const withInactiveIds = (await complianceTaskTypeRepository.all(true)).map((r) => r.id);
    expect(withInactiveIds).toContain(activeId);
    expect(withInactiveIds).toContain(inactiveId);
  });

  it('remove deletes an unreferenced row', async () => {
    const userId = await createTestUser('Admin');
    const id = await complianceTaskTypeRepository.create('Jest Deletable Task', userId);

    await complianceTaskTypeRepository.remove(id);

    expect(await complianceTaskTypeRepository.find(id)).toBeNull();
  });

  it('removing a type still referenced by an order throws ER_ROW_IS_REFERENCED_2', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const id = await complianceTaskTypeRepository.create('Jest Referenced Task', userId);
    await orderComplianceTaskRepository.setStatus(orderId, id, 'approved', null, userId);

    await expect(complianceTaskTypeRepository.remove(id)).rejects.toMatchObject({ code: 'ER_ROW_IS_REFERENCED_2' });
  });
});
