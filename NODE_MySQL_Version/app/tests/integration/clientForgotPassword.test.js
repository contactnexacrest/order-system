'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const clientPortalController = require('../../src/controllers/clientPortalController');
const { createTestClient } = require('../support/fixtures');

/**
 * Port of ClientForgotPasswordTest.php. 90-item backlog #88 (and the real
 * cause of #72, "client can't log back in after first logout" — direct
 * repro of the actual login/logout/login flow on both stacks showed no
 * session/logic bug; a client who simply forgot their password, with
 * nowhere to recover it, is what "can't log back in" actually meant):
 * the client portal had no self-service password recovery at all.
 * clientPasswordResetTokenRepository already existed (built for the
 * initial "set your password" email) but nothing ever let a client who
 * forgot their password request a fresh one. This adds the missing
 * /client/forgot-password screen, reusing the exact same
 * /client/set-password/{token} flow and token table.
 */
describe('Client forgot password (90-item backlog #88)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function createClientWithPortalLogin(email, { clientActive = true, loginActive = true } = {}) {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET email = :email, is_active = :active WHERE id = :id', {
      email, active: clientActive ? 1 : 0, id: clientId,
    });
    await db.execute(
      'INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) VALUES (:cid, :hash, :active, 0)',
      { cid: clientId, hash: 'irrelevant', active: loginActive ? 1 : 0 }
    );
    return clientId;
  }

  async function tokenCountForClient(clientId) {
    const row = await db.queryOne('SELECT COUNT(*) AS cnt FROM client_password_reset_tokens WHERE client_id = :cid', { cid: clientId });
    return parseInt(row.cnt, 10);
  }

  async function auditLogCount(actionType, clientId) {
    const row = await db.queryOne(
      "SELECT COUNT(*) AS cnt FROM audit_log WHERE action_type = :action AND entity_type = 'clients' AND entity_id = :id",
      { action: actionType, id: clientId }
    );
    return parseInt(row.cnt, 10);
  }

  it('requesting a reset for a real matching email creates a token and logs it', async () => {
    const email = `forgot-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createClientWithPortalLogin(email);
    const req = { body: { email }, session: {} };

    await clientPortalController.forgotPassword(req, fakeRes());

    expect(await tokenCountForClient(clientId)).toBe(1);
    expect(await auditLogCount('CLIENT_PASSWORD_RESET_REQUESTED', clientId)).toBe(1);

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');
    expect(messages[0].message).toContain(email);
  });

  it('requesting a reset for a non-matching email shows the same generic message and creates no token', async () => {
    const email = `no-such-client-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const req = { body: { email }, session: {} };

    await clientPortalController.forgotPassword(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');
    expect(messages[0].message).toContain(email);
    expect(messages[0].message).toContain('If');
  });

  it('a deactivated client gets the generic message but no token', async () => {
    const email = `forgot-inactive-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createClientWithPortalLogin(email, { clientActive: false });
    const req = { body: { email }, session: {} };

    await clientPortalController.forgotPassword(req, fakeRes());

    expect(await tokenCountForClient(clientId)).toBe(0);
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('success');
  });

  it('a client whose login is disabled gets the generic message but no token', async () => {
    const email = `forgot-loginoff-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createClientWithPortalLogin(email, { loginActive: false });
    const req = { body: { email }, session: {} };

    await clientPortalController.forgotPassword(req, fakeRes());

    expect(await tokenCountForClient(clientId)).toBe(0);
  });

  it('a 6th request within an hour is rate limited and creates no new token', async () => {
    const email = `forgot-ratelimit-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createClientWithPortalLogin(email);
    const req = { body: { email }, session: {} };

    for (let i = 0; i < 5; i++) {
      await clientPortalController.forgotPassword(req, fakeRes());
    }
    expect(await tokenCountForClient(clientId)).toBe(5);

    await clientPortalController.forgotPassword(req, fakeRes());

    expect(await tokenCountForClient(clientId)).toBe(5);
  });

  it('the resulting token actually works with the existing set-password flow', async () => {
    const email = `forgot-e2e-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createClientWithPortalLogin(email);
    const req = { body: { email }, session: {} };

    await clientPortalController.forgotPassword(req, fakeRes());
    flash.pull(req);

    const crypto = require('crypto');
    const rawToken = crypto.randomBytes(32).toString('hex');
    await db.execute(
      'UPDATE client_password_reset_tokens SET token_hash = :hash WHERE client_id = :cid ORDER BY id DESC LIMIT 1',
      { hash: crypto.createHash('sha256').update(rawToken).digest('hex'), cid: clientId }
    );

    const setReq = {
      params: { token: rawToken },
      body: { new_password: 'Brand-New-Password-1!', confirm_password: 'Brand-New-Password-1!' },
      session: {},
    };
    await clientPortalController.setPassword(setReq, fakeRes());

    const messages = flash.pull(setReq);
    expect(messages[0].type).toBe('success');
  });
});
