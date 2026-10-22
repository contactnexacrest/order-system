'use strict';

const db = require('../../src/config/db');
const permissionRepository = require('../../src/repositories/permissionRepository');

/**
 * Port of PermissionGrantOverrideReturnsRealIdTest.php. External QA report
 * R-08: re-granting a permission the user already has (INSERT ... ON
 * DUPLICATE KEY UPDATE taking the UPDATE branch) made the driver's
 * insertId return 0 — no new AUTO_INCREMENT value was ever generated — so
 * permissionAdminController.grantOverride()'s audit log entry pointed at
 * user_permissions #0, losing which grant actually changed.
 */
describe('permissionRepository.grantOverride() returns the real row id, not 0 (External QA report R-08)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-r08-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  test('first grant returns a non-zero id', async () => {
    const userId = await createTestUser('Viewer / Auditor');
    const adminId = await createTestUser('Admin');
    const permission = await db.queryOne('SELECT id FROM permissions LIMIT 1');

    const id = await permissionRepository.grantOverride(userId, permission.id, adminId, 'Initial grant for testing.');

    expect(id).toBeGreaterThan(0);
  });

  test('re-granting an already-granted permission returns the same real id, not 0', async () => {
    const userId = await createTestUser('Viewer / Auditor');
    const adminId = await createTestUser('Admin');
    const permission = await db.queryOne('SELECT id FROM permissions LIMIT 1');

    const firstId = await permissionRepository.grantOverride(userId, permission.id, adminId, 'Initial grant for testing.');
    const secondId = await permissionRepository.grantOverride(userId, permission.id, adminId, 'Re-granted — reason updated.');

    expect(secondId).toBeGreaterThan(0); // the exact bug this fix closes
    expect(secondId).toBe(firstId);
  });
});
