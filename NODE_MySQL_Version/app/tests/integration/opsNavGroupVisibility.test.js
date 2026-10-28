'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const permissionRepository = require('../../src/repositories/permissionRepository');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of OpsNavGroupVisibilityTest.php. External QA report U-18:
 * "Viewer / Auditor has view_orders and view_clients but no Orders or
 * Clients link in the menu — the only way to an order is through a
 * report link." opsGroupVisible in views/layout/base.njk never included
 * permissions.view_clients/view_orders, even though /clients and
 * /orders each accept either of those as an alternative to
 * manage_orders (server.js) — so the whole Operations dropdown never
 * rendered at all for a role holding only those two, and even once the
 * group renders, the Clients/Orders links themselves need their own
 * gate so a view_orders-only holder isn't shown a Clients link they
 * can't actually use, and vice versa.
 */
describe('Ops nav group visibility (External QA report U-18)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const PASSWORD = 'Nav-Test-Password-1!';


  /**
   * A role holding no permission at all — the CA role used to serve as
   * this clean base, until it was given view_orders (owner decision
   * 2026-10-28).
   */
  async function noPermissionRole() {
    await db.execute("INSERT IGNORE INTO roles (name, description, is_system_role) VALUES ('Test Role — No Permissions', 'Test only', 0)");
    return 'Test Role — No Permissions';
  }

  async function createTestStaffUserWithRealPassword(roleName = null) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName || (await noPermissionRole()) });
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-opsnavtest-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Ops Nav Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { userId: result.insertId, email };
  }

  async function grantOnlyPermission(userId, permissionKey) {
    const permission = await permissionRepository.findByKey(permissionKey);
    await permissionRepository.grantOverride(userId, permission.id, null, 'jest test');
  }

  async function loginAndGetDashboardHtml(email) {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrfToken = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrfToken, email, password: PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).toBe('/');

    const dashboard = await agent.get('/');
    expect(dashboard.status).toBe(200);
    return dashboard.text;
  }

  it('renders the Ops group with a Clients link for a user whose only Ops permission is view_clients', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    await grantOnlyPermission(userId, 'view_clients');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/clients"');
    expect(html).not.toContain('href="/orders"');
  });

  it('renders the Ops group with an Orders link for a user whose only Ops permission is view_orders', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    await grantOnlyPermission(userId, 'view_orders');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/orders"');
    expect(html).not.toContain('href="/clients"');
  });

  it('shows both links for the seeded Viewer / Auditor role', async () => {
    const { email } = await createTestStaffUserWithRealPassword('Viewer / Auditor');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/clients"');
    expect(html).toContain('href="/orders"');
  });

  it('keeps the Ops group hidden for a user with no Ops permissions at all', async () => {
    const { email } = await createTestStaffUserWithRealPassword();

    const html = await loginAndGetDashboardHtml(email);

    expect(html).not.toContain('href="/clients"');
    expect(html).not.toContain('href="/orders"');
    expect(html).not.toContain('>Operations</summary>');
  });
});
