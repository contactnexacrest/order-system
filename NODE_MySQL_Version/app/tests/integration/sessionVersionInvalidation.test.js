'use strict';

const db = require('../../src/config/db');
const sessionAuth = require('../../src/middleware/sessionAuth');
const userRepository = require('../../src/repositories/userRepository');
const authService = require('../../src/services/authService');

/**
 * External QA report L-26: "Security: changing your own password does
 * not end your other logged-in sessions (a stolen session stays
 * valid)." users.session_version is now bumped on every password
 * change (self-service, forgot-password, or admin force-reset) and
 * snapshotted into the session at login time; sessionAuth's middleware
 * compares the two on every request, so a session that logged in
 * BEFORE the change — the attacker's stolen one, in the real scenario
 * this defends against — is refused on its very next request, the
 * same immediate-effect guarantee AUTH-10 already gives for
 * deactivation. Port of PHP's SessionVersionInvalidationTest.
 */
describe('Session version invalidation on password change (External QA report L-26)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest L-26 Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-l26-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeReqRes() {
    const session = {
      // Mimics express-session's regenerate(): replaces the session's
      // contents with a fresh (empty) one — see sessionExpiryGuard.
      // test.js's identical helper.
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

  it('logs out another session after this user\'s password changes', async () => {
    const userId = await createTestUser('Export Executive');
    const { req, res } = fakeReqRes();
    req.session[authService.SESSION_USER_ID] = userId;
    req.session['_session_version'] = authService.sessionVersion(req) ?? 0;
    const otherSessionVersion = req.session['_session_version'];
    const mw = sessionAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(true);

    // A DIFFERENT session/device changes the password — pass no req
    // (or a req without this session's state) so refreshSessionVersion
    // is never called for THIS session.
    await authService.changePassword(null, userId, 'NewPassword123!Zz');

    // Restore this (the OTHER, still-open) session's old state.
    req.session[authService.SESSION_USER_ID] = userId;
    req.session['_session_version'] = otherSessionVersion;

    expect(await runMiddleware(mw, req, res)).toBe(false);
    expect(res.redirectedTo).toBe('/login');
    expect(authService.currentUserId(req)).toBeNull();
  });

  it('keeps the session that made the change itself working', async () => {
    const userId = await createTestUser('Export Executive');
    const { req, res } = fakeReqRes();
    req.session[authService.SESSION_USER_ID] = userId;
    req.session['_session_version'] = authService.sessionVersion(req) ?? 0;
    const mw = sessionAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(true);

    await authService.changePassword(req, userId, 'NewPassword123!Zz');

    expect(await runMiddleware(mw, req, res)).toBe(true);
  });

  it('admin force-reset also invalidates the user\'s existing session', async () => {
    const userId = await createTestUser('Export Executive');
    const { req, res } = fakeReqRes();
    req.session[authService.SESSION_USER_ID] = userId;
    req.session['_session_version'] = authService.sessionVersion(req) ?? 0;
    const mw = sessionAuth.required();

    expect(await runMiddleware(mw, req, res)).toBe(true);

    await userRepository.adminForceResetPassword(userId, 'x');

    expect(await runMiddleware(mw, req, res)).toBe(false);
  });
});
