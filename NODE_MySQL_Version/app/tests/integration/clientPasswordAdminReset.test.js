'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const clientLoginRepository = require('../../src/repositories/clientLoginRepository');
const clientPortalService = require('../../src/services/clientPortalService');
const clientsController = require('../../src/controllers/clientsController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Order Detail Page Polish follow-up: for a non-technical client locked
 * out of the portal who can't follow their own "Forgot password" email
 * link, there was no path at all before this — not even via staff
 * impersonation, since the client's own /client/account "Change
 * Password" form requires their CURRENT password, which staff can never
 * know. clientsController.resetPortalPassword() mirrors
 * userController.forceResetPassword() exactly, against client_logins
 * instead of users.
 */
describe('Client portal password admin-reset', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestStaffUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test Staff', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-staff-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function auditLogCount(actionType, entityId) {
    const row = await db.queryOne(
      "SELECT COUNT(*) AS cnt FROM audit_log WHERE action_type = :action AND entity_type = 'clients' AND entity_id = :entity_id",
      { action: actionType, entity_id: entityId }
    );
    return parseInt(row.cnt, 10);
  }

  function fakeReq(staffUserId) {
    const session = {
      regenerate(cb) {
        for (const key of Object.keys(session)) {
          if (key !== 'regenerate') delete session[key];
        }
        cb(null);
      },
    };
    const req = { ip: 'test', session, body: {}, params: {} };
    if (staffUserId !== undefined) {
      session['_auth_user_id'] = staffUserId;
      req.user = { id: staffUserId };
    }
    return req;
  }

  function fakeRes() {
    const res = { redirectedTo: null, statusCode: 200, body: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    return res;
  }

  it('refuses when no portal account exists yet', async () => {
    const clientId = await createTestClient(); // never provisioned a client_logins row
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    req.body.reason = 'Client called asking for a reset';

    await clientsController.resetPortalPassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toContain('no client-portal account yet');
    expect(await auditLogCount('CLIENT_PASSWORD_ADMIN_RESET', clientId)).toBe(0);
  });

  it('refuses without a reason', async () => {
    const clientId = await createTestClient();
    await createTestClientLogin(clientId, 'irrelevant-starting-password');
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    req.body.reason = 'short';

    await clientsController.resetPortalPassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(await auditLogCount('CLIENT_PASSWORD_ADMIN_RESET', clientId)).toBe(0);
  });

  it('succeeds and the new temporary password actually logs in', async () => {
    const email = `reset-login-check-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, 'irrelevant-starting-password');
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    req.body.reason = 'Client called, forgot password, cannot access email';

    await clientsController.resetPortalPassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');
    const match = messages[0].message.match(/temporary password: (\S+)/);
    expect(match).not.toBeNull();
    const tempPassword = match[1];

    const login = await clientLoginRepository.findByClientId(clientId);
    expect(!!login.force_password_change).toBe(true);
    expect(await auditLogCount('CLIENT_PASSWORD_ADMIN_RESET', clientId)).toBe(1);

    const loginReq = fakeReq();
    const result = await clientPortalService.attemptLogin(loginReq, email, tempPassword);
    expect(result.status).toBe('ok');
    expect(result.force_password_change).toBe(true);
  });

  // ---------------------------------------------------------------
  // View rendering — real HTTP, through the live app (supertest)
  // ---------------------------------------------------------------

  describe('rendered markup', () => {
    let staffAgent;

    beforeAll(async () => {
      staffAgent = request.agent(app);
      const loginPage = await staffAgent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await staffAgent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
      expect(loginRes.status).toBe(302);
    });

    it('client page offers the reset button when a portal account exists', async () => {
      const clientId = await createTestClient();
      await createTestClientLogin(clientId, 'irrelevant-starting-password');

      const res = await staffAgent.get(`/clients/${clientId}`);

      expect(res.status).toBe(200);
      expect(res.text).toContain('Reset client-portal password');
      expect(res.text).toContain(`action="/clients/${clientId}/reset-password"`);
    });

    it('client page explains there is no portal account yet', async () => {
      const clientId = await createTestClient(); // no client_logins row

      const res = await staffAgent.get(`/clients/${clientId}`);

      expect(res.status).toBe(200);
      expect(res.text).not.toContain(`action="/clients/${clientId}/reset-password"`);
      expect(res.text).toContain('no portal account yet');
    });
  });
});
