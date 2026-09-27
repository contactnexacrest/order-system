'use strict';

const db = require('../../src/config/db');
const sessionAuth = require('../../src/middleware/sessionAuth');
const clientAuth = require('../../src/middleware/clientAuth');
const userRepository = require('../../src/repositories/userRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const authService = require('../../src/services/authService');
const clientPortalService = require('../../src/services/clientPortalService');
const { createTestClient } = require('../support/fixtures');

/**
 * QA-5 (AUTH-10 / CP-06 / CP-12 — external QA report cross-verification):
 * deactivating a staff user or client used to only ever be checked at
 * login time — an already-open session (staff or client portal) kept
 * working indefinitely afterward, and the client portal had no idle
 * timeout at all (staff already had one). A revenge-minded employee or
 * client, deactivated the moment their behaviour is noticed, must lose
 * access on their very next request, not whenever they happen to log out.
 */
describe('Session expiry / deactivation guards (QA-5 AUTH-10 / CP-06 / CP-12)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function provisionClientLogin(clientId) {
    await db.execute(
      "INSERT INTO client_logins (client_id, password_hash) VALUES (:cid, 'x')",
      { cid: clientId }
    );
  }

  function fakeReqRes() {
    const session = {
      // Mimics express-session's regenerate(): replaces the session's
      // contents with a fresh (empty) one, which is why logout() clears
      // currentUserId()/currentClientId() rather than just deleting a key.
      regenerate(cb) {
        for (const key of Object.keys(session)) {
          if (key !== 'regenerate') delete session[key];
        }
        cb(null);
      },
    };
    const req = { session };
    const res = { redirectedTo: null, redirect: (url) => { res.redirectedTo = url; } };
    return { req, res };
  }

  async function runMiddleware(mw, req, res) {
    let nextCalled = false;
    await mw(req, res, () => { nextCalled = true; });
    return nextCalled;
  }

  it('logs out a staff user deactivated mid-session on their very next request', async () => {
    const userId = await createTestUser('Export Executive');
    const { req, res } = fakeReqRes();
    req.session[authService.SESSION_USER_ID] = userId;
    const mw = sessionAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(true);

    await userRepository.setActive(userId, false);

    expect(await runMiddleware(mw, req, res)).toBe(false);
    expect(res.redirectedTo).toBe('/login');
    expect(authService.currentUserId(req)).toBeNull();
  });

  it('logs out a client deactivated at the company level', async () => {
    const clientId = await createTestClient();
    await provisionClientLogin(clientId);
    const { req, res } = fakeReqRes();
    req.session[clientPortalService.SESSION_CLIENT_ID] = clientId;
    const mw = clientAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(true);

    await clientRepository.setActive(clientId, false);

    expect(await runMiddleware(mw, req, res)).toBe(false);
    expect(res.redirectedTo).toBe('/client/login');
    expect(clientPortalService.currentClientId(req)).toBeNull();
  });

  it('logs out a client whose portal login alone was disabled', async () => {
    const clientId = await createTestClient();
    await provisionClientLogin(clientId);
    const { req, res } = fakeReqRes();
    req.session[clientPortalService.SESSION_CLIENT_ID] = clientId;
    const mw = clientAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(true);

    await db.execute('UPDATE client_logins SET is_active = 0 WHERE client_id = :cid', { cid: clientId });

    expect(await runMiddleware(mw, req, res)).toBe(false);
  });

  it('enforces the same idle timeout on the client portal as staff', async () => {
    await companySettingsRepository.set('session_timeout_minutes', '5');

    const clientId = await createTestClient();
    await provisionClientLogin(clientId);
    const { req, res } = fakeReqRes();
    req.session[clientPortalService.SESSION_CLIENT_ID] = clientId;
    req.session['_client_last_activity_ts'] = Date.now() - (10 * 60 * 1000);
    const mw = clientAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(false);
    expect(clientPortalService.currentClientId(req)).toBeNull();
  });
});
