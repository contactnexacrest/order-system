'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const userRepository = require('../../src/repositories/userRepository');
const actionNotificationService = require('../../src/services/actionNotificationService');

/**
 * Port of AuditLogNotificationHookTest.php. User-reported batch item 20
 * ("non-negotiable"): auditLogRepository.log() — the single choke point
 * every meaningful action in this codebase already funnels through
 * (47+ call sites) — must fire an action notification every time it's
 * called, to the configurable catch-all notification_email and to the
 * acting user's own email when opted in.
 */
describe('auditLogRepository notification hook (90-item backlog item 20, non-negotiable)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-notif-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('auditLogRepository is wired to call actionNotificationService', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../src/repositories/auditLogRepository.js'), 'utf8');
    expect(source).toContain('actionNotificationService.notify(');
  });

  it('notify resolves the configured catch-all address against real DB state', async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, false);

    const recipients = await actionNotificationService.notify(userId, 'FIELD_EDIT', 'orders', 1, 'incoterm_id', '1', '2', null);

    expect(recipients).toEqual(['ops-notify@nexacrestinternational.test']);

    await companySettingsRepository.set('notification_email', '');
  });

  it("notify includes both the catch-all and an opted-in user's address", async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();
    await userRepository.updateNotificationPreference(userId, true);
    const user = await userRepository.findById(userId);

    const recipients = await actionNotificationService.notify(userId, 'ORDER_CREATED', 'orders', 1, null, null, null, null);

    expect(recipients).toContain('ops-notify@nexacrestinternational.test');
    expect(recipients).toContain(user.email);

    await companySettingsRepository.set('notification_email', '');
  });

  it('calling log does not throw when notifications are fully configured', async () => {
    await companySettingsRepository.set('notification_email', 'ops-notify@nexacrestinternational.test');
    const userId = await createTestUser();

    await auditLogRepository.log(userId, 'TEST_ACTION', 'users', userId, null, null, null, null);

    const entries = await auditLogRepository.search({ userId, actionType: 'TEST_ACTION' });
    expect(entries.length).toBeGreaterThan(0);

    await companySettingsRepository.set('notification_email', '');
  });
});
