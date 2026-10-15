'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const notificationRepository = require('../../src/repositories/notificationRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * 90-item backlog "nav badges": sessionAuth's requireAuth middleware sets
 * req.unreadCount ONCE, before the request reaches a controller. The
 * /notifications page's own controller then calls markAllRead() — but
 * renderView's common context still reads the now-stale req.unreadCount,
 * so the sidebar badge kept showing the pre-markAllRead count on the very
 * page that just cleared it. PHP's equivalent (NotificationController ->
 * View::render) re-reads the count fresh at render time and never had
 * this bug — this is a Node-only fix.
 */
describe('Notifications sidebar badge is not stale after visiting /notifications (90-item backlog "nav badges")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestStaffUserWithRealPassword() {
    const passwordHash = require('../../src/helpers/passwordHash');
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: 'Admin' });
    const PASSWORD = 'Notif-Badge-Test-1!';
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-notif-badge-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Notif Badge Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { userId: result.insertId, email, password: PASSWORD };
  }

  it('clears the sidebar notification badge on the same request that marks notifications read', async () => {
    const { userId, email, password } = await createTestStaffUserWithRealPassword();
    await notificationRepository.create(userId, null, 'test_event', null, 'Jest test notification');

    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email, password });
    expect(loginRes.status).toBe(302);

    expect(await notificationRepository.unreadCountForUser(userId)).toBe(1);

    const res = await agent.get('/notifications');
    expect(res.status).toBe(200);

    expect(res.text).not.toContain('nav-badge">1<');
    expect(res.text).not.toContain('(1 unread)');
    expect(await notificationRepository.unreadCountForUser(userId)).toBe(0);
  });
});
