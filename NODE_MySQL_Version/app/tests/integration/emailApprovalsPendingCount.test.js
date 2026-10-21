'use strict';

const db = require('../../src/config/db');
const emailLogRepository = require('../../src/repositories/emailLogRepository');

/**
 * Port of EmailApprovalsPendingCountTest.php. User-reported batch item
 * 16: the Email Approvals sidebar link (layout/base.njk) gave no
 * indication anything was waiting, unlike Quotation Intake Review / PI
 * Intake Review / Notifications, which all carry a live-COUNT badge.
 * awaitingDispatchCount() backs that badge, counting the exact same
 * rows the Email Approvals page itself lists (pending_approval +
 * approved-but-not-yet-dispatched) so the badge number always matches
 * what staff see when they click through.
 */
describe('Email Approvals pending count (90-item backlog item 16)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-emailbadge-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('awaitingDispatchCount reflects pending and approved-but-not-yet-dispatched rows', async () => {
    const before = await emailLogRepository.awaitingDispatchCount();
    const userId = await createTestUser();

    const pendingId = await emailLogRepository.create(null, null, null, 'buyer@example.test', 'Subject', 'Body', null, userId);
    expect(await emailLogRepository.awaitingDispatchCount()).toBe(before + 1);

    const toApproveId = await emailLogRepository.create(null, null, null, 'buyer2@example.test', 'Subject', 'Body', null, userId);
    expect(await emailLogRepository.awaitingDispatchCount()).toBe(before + 2);

    await emailLogRepository.approve(toApproveId, userId);
    expect(await emailLogRepository.awaitingDispatchCount()).toBe(before + 2);

    await emailLogRepository.reject(pendingId, userId, 'Wrong recipient');
    expect(await emailLogRepository.awaitingDispatchCount()).toBe(before + 1);

    await emailLogRepository.cancel(toApproveId, userId, 'No longer needed');
    expect(await emailLogRepository.awaitingDispatchCount()).toBe(before);
  });
});
