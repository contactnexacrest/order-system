'use strict';

const db = require('../../src/config/db');
const userController = require('../../src/controllers/userController');
const userRepository = require('../../src/repositories/userRepository');
const auditLogRepository = require('../../src/repositories/auditLogRepository');

/**
 * External QA report L-33: "Delete user: when only a password-reset
 * token or notification row blocks the delete, the message wrongly says
 * the user 'has order, document, or audit history'." userRepository.
 * deleteUser() now clears password_reset_tokens and notifications for
 * the user first — rows with zero history value — so a user with ONLY
 * those actually gets deleted instead of being wrongly blocked, while a
 * user with REAL history (an audit_log entry) still correctly gets the
 * "has ... history" message. Port of PHP's UserDeleteTransientRowsTest.
 */
describe('User delete clears transient rows first (External QA report L-33)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest L-33 Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-l33-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeReq(params, actorId) {
    return { params, body: {}, query: {}, user: { id: actorId }, session: {} };
  }
  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('deletes a user whose only references are a reset token and a notification', async () => {
    const actorId = await createTestUser('Admin');
    const targetId = await createTestUser('Export Executive');

    await db.execute(
      "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:id, SHA2('x', 256), DATE_ADD(NOW(), INTERVAL 1 HOUR))",
      { id: targetId }
    );
    await db.execute(
      "INSERT INTO notifications (user_id, type, message) VALUES (:id, 'test', 'L-33 test notification')",
      { id: targetId }
    );

    const req = fakeReq({ id: String(targetId) }, actorId);
    await userController.deleteUser(req, fakeRes());

    const [flashEntry] = req.session._flash || [];
    expect(flashEntry && flashEntry.type).toBe('success');
    expect(await userRepository.findById(targetId)).toBeNull();
  });

  it('still refuses a user with real audit log history', async () => {
    const actorId = await createTestUser('Admin');
    const targetId = await createTestUser('Export Executive');
    await auditLogRepository.log(targetId, 'LOGIN_SUCCESS', 'users', targetId);

    const req = fakeReq({ id: String(targetId) }, actorId);
    await userController.deleteUser(req, fakeRes());

    const [flashEntry] = req.session._flash || [];
    expect(flashEntry && flashEntry.type).toBe('error');
    expect(flashEntry && flashEntry.message).toContain('order, document, or audit history');
    expect(await userRepository.findById(targetId)).not.toBeNull();
  });
});
