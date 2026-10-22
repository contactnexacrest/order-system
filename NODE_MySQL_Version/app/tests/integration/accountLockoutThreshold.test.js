'use strict';

const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const userRepository = require('../../src/repositories/userRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const authService = require('../../src/services/authService');

/**
 * External QA report L-03: with "lock after N", the account used to lock
 * on the (N+1)th failed attempt, not the Nth. attemptLogin() called
 * loginAttemptRepository.recentFailedCount() to decide whether to lock
 * BEFORE calling loginAttemptRepository.record() for the attempt that was
 * just made — so the count it read never included the very failure that
 * triggered the check. On the Nth failure the count was only N-1 (N-1 >= N
 * is false), and the account only actually locked on the (N+1)th attempt,
 * once that count finally reached N. Fixed by recording the attempt first.
 */
describe('Account lockout threshold (QA-5 L-03)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const KNOWN_PASSWORD = 'CorrectHorseBatteryStaple42!';

  function fakeReq() {
    return { ip: 'test' };
  }

  async function createTestUserWithKnownPassword(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const hash = await passwordHash.hash(KNOWN_PASSWORD);
    const email = `jest-lockout-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { id: result.insertId, email };
  }

  beforeEach(async () => {
    // Deterministic threshold, independent of whatever seed.sql ships.
    await companySettingsRepository.set('failed_login_lockout_count', '3');
    await companySettingsRepository.set('lockout_duration_minutes', '15');
  });

  it('locks on the configured Nth failure, not the (N+1)th', async () => {
    const { id, email } = await createTestUserWithKnownPassword('Export Executive');

    // Failures 1 and 2 (below the threshold of 3): must NOT lock yet.
    await authService.attemptLogin(fakeReq(), email, 'wrong-password');
    let user = await userRepository.findById(id);
    expect(user.locked_until).toBeNull();

    await authService.attemptLogin(fakeReq(), email, 'wrong-password');
    user = await userRepository.findById(id);
    expect(user.locked_until).toBeNull();

    // Failure 3 — exactly the configured threshold — must lock NOW, not
    // on a 4th attempt.
    await authService.attemptLogin(fakeReq(), email, 'wrong-password');
    user = await userRepository.findById(id);
    expect(user.locked_until).not.toBeNull();

    // And the correct password is now refused too, proving the lock is
    // real, not just a DB column with no effect.
    const result = await authService.attemptLogin(fakeReq(), email, KNOWN_PASSWORD);
    expect(result.status).toBe('locked_out');
  });
});
