'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const permissionRepository = require('../../src/repositories/permissionRepository');
const permissionAdminController = require('../../src/controllers/permissionAdminController');

/**
 * Port of RolePermissionWipeGuardTest.php. External QA report L-37: saving
 * a role's permission page with nothing ticked silently removed every
 * permission from that role — including the Admin role — with no
 * confirmation, no reason, and no safeguard; all users on that role lose
 * access at once. The audit entry also only ever logged the new permission
 * COUNT, never which permissions were actually removed. Found when the
 * fuzzer emptied the Admin role on the UI copy; reproduced here directly
 * against the controller.
 */
describe('permissionAdminController — L-37 role permission wipe guard', () => {
  // Unlike the PHP suite (each test wrapped in a rolled-back transaction),
  // the Jest DB is shared, uncommitted state across the whole run — so
  // mutating the real seeded "Export Executive" role's permissions here
  // would otherwise leak into every other test file that asserts on its
  // default grants (e.g. disputeReply.test.js, idorAndInputValidation.test.js).
  // Snapshot and restore it around this suite.
  let roleId;
  let originalPermissionIds;

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    roleId = role.id;
    originalPermissionIds = await permissionRepository.enabledForRole(roleId);
  });

  afterAll(async () => {
    await permissionRepository.setForRole(roleId, originalPermissionIds);
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-rolewipe-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = () => {};
    return res;
  }

  function fakeReq(user, body, params) {
    return { user, body, params, session: {} };
  }

  it('refuses a save with nothing selected and changes nothing', async () => {
    const userId = await createTestUser('Admin');
    const user = { id: userId };
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    const before = await permissionRepository.enabledForRole(role.id);
    expect(before.length).toBeGreaterThan(0);

    const req = fakeReq(user, { permission_ids: [] }, { id: String(role.id) });
    const res = fakeRes();
    await permissionAdminController.rolePermissionsUpdate(req, res);

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toContain('remove every permission');
    expect(res.redirectedTo).toBe(`/admin/roles/${role.id}/permissions`);

    const after = await permissionRepository.enabledForRole(role.id);
    expect(after.sort()).toEqual(before.sort());
  });

  it('still works with at least one permission and logs before/after, not just a count', async () => {
    const userId = await createTestUser('Admin');
    const user = { id: userId };
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    const manageOrders = await db.queryOne("SELECT id FROM permissions WHERE permission_key = 'manage_orders'");

    const req = fakeReq(user, { permission_ids: [String(manageOrders.id)] }, { id: String(role.id) });
    const res = fakeRes();
    await permissionAdminController.rolePermissionsUpdate(req, res);

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');

    const after = await permissionRepository.enabledForRole(role.id);
    expect(after).toEqual([manageOrders.id]);

    const logRow = await db.queryOne(
      "SELECT old_value, new_value FROM audit_log WHERE action_type = 'ROLE_PERMISSIONS_UPDATED' ORDER BY id DESC LIMIT 1"
    );
    expect(logRow.old_value).toBeTruthy();
    expect(logRow.new_value).toBe('manage_orders');
  });
});
