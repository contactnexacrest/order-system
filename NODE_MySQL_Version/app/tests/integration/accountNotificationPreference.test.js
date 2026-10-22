'use strict';

const db = require('../../src/config/db');
const accountController = require('../../src/controllers/accountController');

/**
 * External QA report L-29: "Node: switching off 'action notification
 * e-mails' on My Account is not saved (flag stays 1). PHP works." —
 * verified against the current code (controller, repository, and a full
 * HTTP round-trip through the real route were all exercised manually)
 * and found to already behave correctly: unchecking the box (the
 * checkbox key is simply absent from the POST body) does persist 0, and
 * re-checking it persists 1. This is a cross-verification regression
 * test locking in that correct behavior — verify, don't trust, applies
 * to "still open" as much as to a reported fix (same pattern as the L-06
 * false positive found earlier in this report).
 */
describe('Account notification preference toggle (External QA report L-29 — verified not reproducible)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, email_action_notifications_enabled)
       VALUES ('Jest L-29 Test User', :email, NULL, 'x', :role_id, 1, 0, 0, 1)`,
      { email: `jest-l29-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('unchecking the box (checkbox key absent) persists 0, not the old 1', async () => {
    const userId = await createTestUser();
    const req = { user: { id: userId }, body: {}, session: {} }; // unchecked checkbox never appears in the POST body

    await accountController.updateNotificationPreference(req, fakeRes());

    const row = await db.queryOne('SELECT email_action_notifications_enabled FROM users WHERE id = :id', { id: userId });
    expect(Number(row.email_action_notifications_enabled)).toBe(0);
  });

  it('re-checking the box persists 1', async () => {
    const userId = await createTestUser();
    await db.execute('UPDATE users SET email_action_notifications_enabled = 0 WHERE id = :id', { id: userId });

    const req = { user: { id: userId }, body: { email_action_notifications_enabled: '1' }, session: {} };
    await accountController.updateNotificationPreference(req, fakeRes());

    const row = await db.queryOne('SELECT email_action_notifications_enabled FROM users WHERE id = :id', { id: userId });
    expect(Number(row.email_action_notifications_enabled)).toBe(1);
  });
});
