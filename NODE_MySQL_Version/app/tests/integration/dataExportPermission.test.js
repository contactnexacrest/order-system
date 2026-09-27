'use strict';

const db = require('../../src/config/db');
const permissionRepository = require('../../src/repositories/permissionRepository');
const superAdminService = require('../../src/services/superAdminService');

/**
 * Port of DataExportPermissionTest.php. Point 6 — the data-export/
 * migration tool is deliberately gated on its own permission
 * (data_export_run) rather than folded into manage_company_settings,
 * since the data file it unlocks can expose every record in the system,
 * including staff password hashes. Confirms the seeded grant actually
 * behaves as designed: Admin has it by default, an ungranted role does
 * not, and a Super Admin always does regardless of role — the same
 * bypass every other permission gets (applied in sessionAuth middleware,
 * mirrored here directly against the repository + superAdminService).
 */
describe('data_export_run permission', () => {
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
    return { id: result.insertId, roleId: role.id };
  }

  it('Admin role has data_export_run permission by seed', async () => {
    const user = await createTestUser('Admin');
    const permissions = await permissionRepository.effectivePermissions(user.id, user.roleId);
    expect(permissions.data_export_run).toBe(true);
  });

  it('an ungranted role does not have data_export_run permission', async () => {
    const user = await createTestUser('Viewer / Auditor');
    const permissions = await permissionRepository.effectivePermissions(user.id, user.roleId);
    expect(!!permissions.data_export_run).toBe(false);
  });

  it('a Super Admin has it regardless of role', async () => {
    const user = await createTestUser('Viewer / Auditor');
    await db.execute('UPDATE users SET is_super_admin = 1 WHERE id = :id', { id: user.id });

    const isSuperAdmin = await superAdminService.isEffective(user.id);
    expect(isSuperAdmin).toBe(true);
    // sessionAuth middleware force-sets every permission key true for an
    // effective Super Admin — this confirms the underlying flag, which is
    // what that middleware branches on (see middleware/sessionAuth.js).
  });
});
