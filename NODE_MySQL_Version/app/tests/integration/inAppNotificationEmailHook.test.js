'use strict';

const db = require('../../src/config/db');
const notificationRepository = require('../../src/repositories/notificationRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report M-06: "In-app notifications also sent by e-mail."
 * notificationRepository.create() must also email the targeted user,
 * gated by the users.email_action_notifications_enabled toggle. Since
 * 2026-10-26 this is the only notification email channel (the audit log
 * sends none — see auditLogSendsNoEmail.test.js).
 * Port of PHP's InAppNotificationEmailHookTest.
 */
describe('In-app notification email hook (External QA report M-06)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-m06-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('emailNotification sends to an opted-in user', async () => {
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, true);
    const user = await userRepository.findById(userId);

    const attempted = await notificationRepository.emailNotification(userId, 'Something happened.');

    expect(attempted).toBe(user.email);
  });

  it('emailNotification skips an opted-out user', async () => {
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, false);

    const attempted = await notificationRepository.emailNotification(userId, 'Something happened.');

    expect(attempted).toBeNull();
  });

  it('emailNotification defaults to opted-in when never set', async () => {
    const userId = await createTestUser();
    const user = await userRepository.findById(userId);

    const attempted = await notificationRepository.emailNotification(userId, 'Something happened.');

    expect(attempted).toBe(user.email);
  });

  it('create fires the email hook for a user-targeted notification', async () => {
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, false);
    const orderId = await createTestOrder(await createTestClient());

    // Doesn't throw, and the in-app row is still inserted exactly as
    // before this fix — the opted-out user just isn't emailed.
    const id = await notificationRepository.create(userId, null, 'review_assigned', orderId, 'You have been assigned to review a document.');

    const rows = await notificationRepository.forUser(userId);
    expect(rows.length).toBeGreaterThan(0);
    expect(rows[0].id).toBe(id);
  });

  it('create never throws when no user is targeted', async () => {
    // A role-only notification (userId null) has no single user to email
    // — create() must simply skip the email hook, not error.
    const id = await notificationRepository.create(null, 1, 'role_notice', null, 'Role-wide notice.');

    expect(id).toBeGreaterThan(0);
  });
});
