'use strict';

const db = require('../../src/config/db');
const permissionRepository = require('../../src/repositories/permissionRepository');
const departmentRepository = require('../../src/repositories/departmentRepository');
const superAdminService = require('../../src/services/superAdminService');

/**
 * CI 6 Nov 2026: usersWithPermission() checked every active user one by one, so with thousands
 * of users the daily alert job took ~30 s per run. It now checks only the people who could hold
 * the permission. This proves the answer is exactly the same as checking everyone.
 */
async function everyoneChecked(key) {
  const out = [];
  for (const u of await db.query('SELECT id, role_id FROM users WHERE is_active = 1 ORDER BY id')) {
    if (await superAdminService.isEffective(u.id)) { out.push(Number(u.id)); continue; }
    let roleIds = await departmentRepository.activeRoleIdsForUser(u.id);
    if (roleIds.length === 0) roleIds = [u.role_id];
    for (const r of roleIds) {
      if ((await permissionRepository.effectivePermissions(u.id, r))[key]) { out.push(Number(u.id)); break; }
    }
  }
  return out;
}

describe('usersWithPermission checks only possible holders, same answer', () => {
  jest.setTimeout(120000);
  const made = [];
  let keyId;

  beforeAll(async () => {
    keyId = Number((await db.queryOne("SELECT id FROM permissions WHERE permission_key = 'manage_company_settings'")).id);
    const role = async (n) => Number((await db.queryOne('SELECT id FROM roles WHERE name = :n', { n })).id);
    const user = async (label, roleId) => {
      const r = await db.execute(
        "INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled) VALUES (:n, :e, 'x', :r, 1, 0, 0)",
        { n: `Jest UWP ${label}`, e: `uwp-${label}-${Date.now()}@email.com`, r: roleId }
      );
      made.push(Number(r.insertId));
      return Number(r.insertId);
    };
    const granted = await user('granted', await role('Export Executive'));
    await db.execute('INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) VALUES (:u, :p, 1, :r)', { u: granted, p: keyId, r: 'test grant' });
    const revoked = await user('revoked', await role('Admin'));
    await db.execute('INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) VALUES (:u, :p, 0, :r)', { u: revoked, p: keyId, r: 'test revoke' });
    const expired = await user('expired', await role('Export Executive'));
    await db.execute("INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason, expires_at) VALUES (:u, :p, 1, :r, '2020-01-01')", { u: expired, p: keyId, r: 'test expired' });
    await user('plain', await role('Export Executive'));
  });

  afterAll(async () => {
    if (made.length) {
      await db.execute(`DELETE FROM user_permissions WHERE user_id IN (${made.join(',')})`);
      await db.execute(`DELETE FROM users WHERE id IN (${made.join(',')})`);
    }
    await db.pool.end();
  });

  it.each(['manage_company_settings', 'manage_orders', 'pay_incentives', 'view_all_orders'])('%s', async (key) => {
    const fast = (await permissionRepository.usersWithPermission(key)).map(Number);
    expect(fast).toEqual(await everyoneChecked(key));
  });

  it('a personal grant counts, a personal or expired-grant revoke does not', async () => {
    const ids = (await permissionRepository.usersWithPermission('manage_company_settings')).map(Number);
    const [granted, revoked, expired, plain] = made;
    expect(ids).toContain(granted);
    expect(ids).not.toContain(revoked);
    expect(ids).not.toContain(expired);
    expect(ids).not.toContain(plain);
  });
});
