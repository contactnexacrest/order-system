'use strict';

const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const authService = require('../../src/services/authService');
const clientPortalService = require('../../src/services/clientPortalService');
const { createTestClient, createTestClientLogin } = require('../support/fixtures');

/**
 * QA-5 CP-13: staff (_auth_user_id) and client-portal
 * (_client_portal_client_id) logins are two independent, coexisting
 * namespaces in the same session — a staff member testing the client
 * portal in another tab of the same browser, for instance. Both
 * authService.js and clientPortalService.js used req.session.regenerate()
 * on login AND logout, which — unlike PHP's session_regenerate_id() —
 * replaces req.session's ENTIRE contents with a fresh, empty object. Any
 * one of the four calls (staff login, staff logout, client login, client
 * logout) silently wiped the OTHER namespace out. The fake session's
 * regenerate() below reproduces express-session's real wipe-everything
 * behavior, so this only passes if the fix actually preserves the other
 * key across it — it would fail against the unfixed code.
 */
describe('Staff and client-portal sessions are isolated across login/logout (QA-5 CP-13)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const KNOWN_PASSWORD = 'CorrectHorseBatteryStaple42!';

  async function createTestUserWithKnownPassword() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
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
    await createTestClientLogin(clientId, KNOWN_PASSWORD);
    return { id: clientId, email };
  }

  function fakeReq() {
    const session = {
      regenerate(cb) {
        for (const key of Object.keys(session)) {
          if (key !== 'regenerate') delete session[key];
        }
        cb(null);
      },
    };
    return { ip: 'test', session };
  }

  it('staff login preserves an existing client-portal session', async () => {
    const staff = await createTestUserWithKnownPassword();
    const client = await createTestClientWithKnownPortalPassword();
    const req = fakeReq();
    req.session[clientPortalService.SESSION_CLIENT_ID] = client.id;

    const result = await authService.attemptLogin(req, staff.email, KNOWN_PASSWORD);

    expect(result.status).toBe('ok');
    expect(authService.currentUserId(req)).toBe(staff.id);
    expect(clientPortalService.currentClientId(req)).toBe(client.id);
  });

  it('client-portal login preserves an existing staff session', async () => {
    const staff = await createTestUserWithKnownPassword();
    const client = await createTestClientWithKnownPortalPassword();
    const req = fakeReq();
    req.session[authService.SESSION_USER_ID] = staff.id;

    const result = await clientPortalService.attemptLogin(req, client.email, KNOWN_PASSWORD);

    expect(result.status).toBe('ok');
    expect(clientPortalService.currentClientId(req)).toBe(client.id);
    expect(authService.currentUserId(req)).toBe(staff.id);
  });

  it('staff logout preserves an active client-portal session', async () => {
    const staff = await createTestUserWithKnownPassword();
    const client = await createTestClientWithKnownPortalPassword();
    const req = fakeReq();
    req.session[authService.SESSION_USER_ID] = staff.id;
    req.session[clientPortalService.SESSION_CLIENT_ID] = client.id;

    await authService.logout(req);

    expect(authService.currentUserId(req)).toBeNull();
    expect(clientPortalService.currentClientId(req)).toBe(client.id);
  });

  it('client-portal logout preserves an active staff session', async () => {
    const staff = await createTestUserWithKnownPassword();
    const client = await createTestClientWithKnownPortalPassword();
    const req = fakeReq();
    req.session[authService.SESSION_USER_ID] = staff.id;
    req.session[clientPortalService.SESSION_CLIENT_ID] = client.id;

    await clientPortalService.logout(req);

    expect(clientPortalService.currentClientId(req)).toBeNull();
    expect(authService.currentUserId(req)).toBe(staff.id);
  });
});
