'use strict';

const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const userRepository = require('../../src/repositories/userRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const clientLoginRepository = require('../../src/repositories/clientLoginRepository');
const authService = require('../../src/services/authService');
const clientPortalService = require('../../src/services/clientPortalService');
const { createTestClient } = require('../support/fixtures');

/**
 * QA-5 (AUTH-04/AUTH-05/CP-07 — external QA report cross-verification):
 * login used to check is_active/locked_until BEFORE verifying the
 * password, so an attacker submitting a wrong password for a guessed
 * email could learn — with zero knowledge of the real password — whether
 * that email belongs to a disabled account, a locked account, or no
 * account at all, purely from which status came back. The fix checks the
 * password first: a wrong password always returns the same generic
 * 'invalid_credentials', whatever the account's real state; only a
 * CORRECT password ever reveals 'account_disabled' or 'locked_out'.
 */
describe('Account enumeration guard (QA-5 AUTH-04/AUTH-05/CP-07)', () => {
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
    const email = `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { id: result.insertId, email };
  }

  async function createTestClientWithKnownPortalPassword() {
    const clientId = await createTestClient();
    const email = `jest-client-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email, id: clientId });
    const hash = await passwordHash.hash(KNOWN_PASSWORD);
    await db.execute('INSERT INTO client_logins (client_id, password_hash) VALUES (:cid, :hash)', { cid: clientId, hash });
    return { id: clientId, email };
  }

  it('a wrong password on a disabled staff account looks identical to invalid credentials', async () => {
    const { id, email } = await createTestUserWithKnownPassword('Export Executive');
    await userRepository.setActive(id, false);

    const result = await authService.attemptLogin(fakeReq(), email, 'definitely-the-wrong-password');

    expect(result.status).toBe('invalid_credentials');
  });

  it('a wrong password on a locked staff account looks identical to invalid credentials', async () => {
    const { id, email } = await createTestUserWithKnownPassword('Export Executive');
    await userRepository.lockUntil(id, new Date(Date.now() + 900000).toISOString().slice(0, 19).replace('T', ' '));

    const result = await authService.attemptLogin(fakeReq(), email, 'definitely-the-wrong-password');

    expect(result.status).toBe('invalid_credentials');
  });

  it('a correct password on a disabled staff account still reveals disabled', async () => {
    const { id, email } = await createTestUserWithKnownPassword('Export Executive');
    await userRepository.setActive(id, false);

    const result = await authService.attemptLogin(fakeReq(), email, KNOWN_PASSWORD);

    expect(result.status).toBe('account_disabled');
  });

  it('a correct password on a locked staff account still reveals locked_out', async () => {
    const { id, email } = await createTestUserWithKnownPassword('Export Executive');
    await userRepository.lockUntil(id, new Date(Date.now() + 900000).toISOString().slice(0, 19).replace('T', ' '));

    const result = await authService.attemptLogin(fakeReq(), email, KNOWN_PASSWORD);

    expect(result.status).toBe('locked_out');
  });

  it('an unknown email and a wrong password on a real disabled account return the same status', async () => {
    const { id, email } = await createTestUserWithKnownPassword('Export Executive');
    await userRepository.setActive(id, false);

    const unknownEmailResult = await authService.attemptLogin(fakeReq(), 'definitely-not-a-real-user@nexacrest.test', 'anything');
    const realDisabledResult = await authService.attemptLogin(fakeReq(), email, 'anything');

    expect(realDisabledResult.status).toBe(unknownEmailResult.status);
    expect(unknownEmailResult.status).toBe('invalid_credentials');
  });

  it('a wrong password on a deactivated client portal login looks identical to invalid credentials', async () => {
    const { id, email } = await createTestClientWithKnownPortalPassword();
    await clientRepository.setActive(id, false);

    const result = await clientPortalService.attemptLogin(fakeReq(), email, 'definitely-the-wrong-password');

    expect(result.status).toBe('invalid_credentials');
  });

  it('a correct password on a deactivated client portal login still reveals disabled', async () => {
    const { id, email } = await createTestClientWithKnownPortalPassword();
    await clientRepository.setActive(id, false);

    const result = await clientPortalService.attemptLogin(fakeReq(), email, KNOWN_PASSWORD);

    expect(result.status).toBe('account_disabled');
  });

  // QA-5 DEF-04 follow-on: locked_until is written as `new Date(...).toISOString()`
  // (a UTC wall-clock string) and, before this fix, was read back with a bare
  // `new Date(login.locked_until)` — which parses a naive "Y-m-d H:i:s" string
  // as LOCAL time. Once the process runs in IST (DEF-04) instead of UTC, that
  // misparse made every still-locked client portal account look already
  // unlocked, 5 hours 30 minutes early. Pins the correct behavior in place.
  it('a correct password on a locked client portal login still reveals locked_out', async () => {
    const { id, email } = await createTestClientWithKnownPortalPassword();
    await clientLoginRepository.lockUntil(id, new Date(Date.now() + 900000).toISOString().slice(0, 19).replace('T', ' '));

    const result = await clientPortalService.attemptLogin(fakeReq(), email, KNOWN_PASSWORD);

    expect(result.status).toBe('locked_out');
  });
});
