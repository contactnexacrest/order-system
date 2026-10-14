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
 * Port of AdminNavGroupVisibilityTest.php. 90-item backlog #34: the
 * sidebar's "Admin" <details> dropdown only renders when adminGroupVisible
 * is true — but that flag's or-list in views/layout/base.njk never
 * included permissions.manage_hs_codes/manage_compliance_task_types/
 * manage_dropdown_options/manage_email_templates, even though each of
 * those gates its own link inside the very same dropdown. A user whose
 * ONLY Admin-section permission was one of those four saw no "Admin"
 * group at all. This grants exactly one such permission via a
 * user_permissions override on a role ("Export Executive") that
 * otherwise has none of the Admin-group permissions.
 */
describe('Admin nav group visibility (90-item backlog #34)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const PASSWORD = 'Nav-Test-Password-1!';

  async function createTestStaffUserWithRealPassword() {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: 'Export Executive' });
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-navtest-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Nav Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
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

  it('renders the Admin group for a user whose only Admin permission is manage_compliance_task_types', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    await grantOnlyPermission(userId, 'manage_compliance_task_types');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/compliance-task-types"');
  });

  it('renders the Admin group for a user whose only Admin permission is manage_hs_codes', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    await grantOnlyPermission(userId, 'manage_hs_codes');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/hs-codes"');
  });

  it('renders the Admin group for a user whose only Admin permission is manage_dropdown_options', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    await grantOnlyPermission(userId, 'manage_dropdown_options');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/admin/dropdown-options"');
  });

  it('renders the Admin group for a user whose only Admin permission is manage_email_templates', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    await grantOnlyPermission(userId, 'manage_email_templates');

    const html = await loginAndGetDashboardHtml(email);

    expect(html).toContain('href="/email-templates"');
  });

  it('keeps the Admin group hidden for a user with no Admin permissions at all', async () => {
    const { email } = await createTestStaffUserWithRealPassword();

    const html = await loginAndGetDashboardHtml(email);

    expect(html).not.toContain('href="/compliance-task-types"');
    expect(html).not.toContain('>Admin</summary>');
  });
});
