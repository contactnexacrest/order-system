'use strict';

const db = require('../../src/config/db');
const accountController = require('../../src/controllers/accountController');
const userRepository = require('../../src/repositories/userRepository');
const passwordHash = require('../../src/helpers/passwordHash');
const flash = require('../../src/helpers/flash');

/**
 * 90-item backlog #26 — a voluntary, logged-in self-service password
 * change for staff users (accountController.updatePassword). Mirrors
 * the existing client-portal self-service password change
 * (clientPortalController.changePassword): current password must be
 * re-verified, then passwordPolicyService, then a match check.
 */
describe('account self-service password change (backlog #26)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function createTestUserWithPassword(password) {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const hash = await passwordHash.hash(password, 10);
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, hash, role_id: role.id }
    );
    return result.insertId;
  }

  it('rejects a wrong current password', async () => {
    const userId = await createTestUserWithPassword('OldPass123!');
    const req = { user: { id: userId }, body: { current_password: 'WrongPass!', new_password: 'NewPass456!', confirm_password: 'NewPass456!' }, session: {} };

    await accountController.updatePassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toContain('incorrect');

    const user = await userRepository.findById(userId);
    expect(await passwordHash.verify('OldPass123!', user.password_hash)).toBe(true);
  });

  it('rejects mismatched confirmation', async () => {
    const userId = await createTestUserWithPassword('OldPass123!');
    const req = { user: { id: userId }, body: { current_password: 'OldPass123!', new_password: 'NewPass456!', confirm_password: 'Different789!' }, session: {} };

    await accountController.updatePassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toContain('match');
  });

  it('rejects a policy-violating new password', async () => {
    const userId = await createTestUserWithPassword('OldPass123!');
    const req = { user: { id: userId }, body: { current_password: 'OldPass123!', new_password: 'short', confirm_password: 'short' }, session: {} };

    await accountController.updatePassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  it('succeeds with the correct current password and a valid new password', async () => {
    const userId = await createTestUserWithPassword('OldPass123!');
    const req = { user: { id: userId }, body: { current_password: 'OldPass123!', new_password: 'BrandNewPass789!', confirm_password: 'BrandNewPass789!' }, session: {} };

    await accountController.updatePassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');

    const user = await userRepository.findById(userId);
    expect(await passwordHash.verify('BrandNewPass789!', user.password_hash)).toBe(true);
    expect(await passwordHash.verify('OldPass123!', user.password_hash)).toBe(false);
  });
});
