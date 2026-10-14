'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const clientPortalService = require('../../src/services/clientPortalService');
const clientsController = require('../../src/controllers/clientsController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const clientAuth = require('../../src/middleware/clientAuth');
const flash = require('../../src/helpers/flash');
const passwordHash = require('../../src/helpers/passwordHash');
const { createTestClient } = require('../support/fixtures');

/**
 * docs/schema.sql Section AV — staff "Log in as this client" impersonation.
 * Three independent gates must ALL hold before the feature does anything:
 * (1) the impersonate_client permission (route-level, not re-tested here —
 * requirePermission() is exercised elsewhere), (2) the global
 * client_impersonation_enabled company_setting, (3) the per-client
 * allow_staff_impersonation flag. clientsController.impersonate() re-checks
 * (2) and (3) itself regardless of the route gate, which is what these
 * tests exercise directly.
 */
describe('Client impersonation (Section AV)', () => {
  let originalGlobalSwitch;

  beforeAll(async () => {
    originalGlobalSwitch = String((await companySettingsRepository.get('client_impersonation_enabled')) ?? '0');
  });

  afterEach(async () => {
    await companySettingsRepository.set('client_impersonation_enabled', originalGlobalSwitch, null);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function enableGlobally() {
    await companySettingsRepository.set('client_impersonation_enabled', '1', null);
  }

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

  // Reproduces express-session's real wipe-everything regenerate() behavior
  // (same fake used by dualSessionIsolation.test.js), so startImpersonation()/
  // endImpersonation() are exercised exactly as they run in production.
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
      // clientsController reads req.user (the staff member driving the request); the session
      // key mirrors how sessionAuth actually keeps a staff login alive, so startImpersonation()/
      // endImpersonation()'s regeneratePreserving() has something real to preserve.
      session['_auth_user_id'] = staffUserId;
      req.user = { id: staffUserId };
    }
    return req;
  }

  function fakeRes() {
    const res = { redirectedTo: null, statusCode: 200 };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = () => {};
    return res;
  }

  // --- Gate 2: global switch ---

  it('impersonate refuses when the global switch is off', async () => {
    await companySettingsRepository.set('client_impersonation_enabled', '0', null);
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);

    await clientsController.impersonate(req, fakeRes());

    expect(clientPortalService.currentClientId(req)).toBeNull();
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  // --- Gate 3: per-client flag ---

  it('impersonate refuses when the client flag is off', async () => {
    await enableGlobally();
    const clientId = await createTestClient(); // allow_staff_impersonation defaults to 0
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);

    await clientsController.impersonate(req, fakeRes());

    expect(clientPortalService.currentClientId(req)).toBeNull();
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  it('impersonate refuses for a deactivated client', async () => {
    await enableGlobally();
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    await clientRepository.setActive(clientId, false);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);

    await clientsController.impersonate(req, fakeRes());

    expect(clientPortalService.currentClientId(req)).toBeNull();
  });

  // --- All three gates satisfied ---

  it('impersonate succeeds when all three gates hold', async () => {
    await enableGlobally();
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);

    await clientsController.impersonate(req, fakeRes());

    expect(clientPortalService.currentClientId(req)).toBe(clientId);
    expect(clientPortalService.isImpersonating(req)).toBe(true);
    expect(clientPortalService.impersonatedByStaffId(req)).toBe(staffId);
    expect(await auditLogCount('CLIENT_IMPERSONATION_STARTED', clientId)).toBe(1);
  });

  // --- clientAuth middleware: an impersonated session needs no client_logins row ---

  it('clientAuth allows an impersonated session with no login row at all', async () => {
    await enableGlobally();
    const clientId = await createTestClient(); // never provisioned a client_logins row — e.g. pre-Stage-3
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    await clientsController.impersonate(req, fakeRes());

    let nextCalled = false;
    const res = fakeRes();
    await clientAuth.required()(req, res, () => { nextCalled = true; });

    expect(nextCalled).toBe(true);
    expect(res.redirectedTo).toBeNull();
  });

  it('clientAuth ends impersonation once the client has since been deactivated', async () => {
    await enableGlobally();
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    await clientsController.impersonate(req, fakeRes());
    await clientRepository.setActive(clientId, false);

    let nextCalled = false;
    const res = fakeRes();
    await clientAuth.required()(req, res, () => { nextCalled = true; });

    expect(nextCalled).toBe(false);
    expect(res.redirectedTo).toBe('/client/login');
    expect(clientPortalService.currentClientId(req)).toBeNull();
  });

  // --- Ending impersonation ---

  it('endImpersonation clears the session and returns the client id', async () => {
    await enableGlobally();
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    await clientsController.impersonate(req, fakeRes());

    const res = fakeRes();
    await clientPortalController.endImpersonation(req, res);

    expect(res.redirectedTo).toBe(`/clients/${clientId}`);
    expect(clientPortalService.currentClientId(req)).toBeNull();
    expect(clientPortalService.isImpersonating(req)).toBe(false);
    expect(await auditLogCount('CLIENT_IMPERSONATION_ENDED', clientId)).toBe(1);
    // the staff member's own session (set above) must survive — this is "stop viewing as
    // the client", never a staff logout.
    expect(req.session['_auth_user_id']).toBe(staffId);
  });

  it('endImpersonation on a non-impersonated session is a no-op', async () => {
    const req = fakeReq();

    const result = await clientPortalService.endImpersonation(req);

    expect(result).toBeNull();
  });

  /**
   * Port of ClientImpersonationTest::testARealPasswordLoginClearsAnyStale
   * ImpersonationFlagLeftFromBefore. 90-item backlog #6: PHP's
   * attemptLogin() had this bug because session_regenerate_id() only
   * rotates the session ID and leaves other keys untouched — Node's
   * attemptLogin() uses regeneratePreserving(req, [STAFF_SESSION_KEY], …),
   * which wipes the ENTIRE session (via the real req.session.regenerate())
   * and only restores the explicitly-listed staff key, so any stale
   * impersonation flag is destroyed as a side effect before the real
   * client id is even set. This pins that Node really is immune, not just
   * accidentally passing today.
   */
  it('a real password login clears any stale impersonation flag left from before (Node is immune by design)', async () => {
    await enableGlobally();
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    await clientsController.impersonate(req, fakeRes());
    expect(clientPortalService.isImpersonating(req)).toBe(true);

    const email = `real-login-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'Real-Client-Password-1!';
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email, id: clientId });
    const hash = await passwordHash.hash(password);
    await db.execute(
      'INSERT INTO client_logins (client_id, password_hash, force_password_change) VALUES (:cid, :hash, 0)',
      { cid: clientId, hash }
    );

    const result = await clientPortalService.attemptLogin(req, email, password);

    expect(result.status).toBe('ok');
    expect(clientPortalService.currentClientId(req)).toBe(clientId);
    expect(clientPortalService.isImpersonating(req)).toBe(false);
  });

  // --- Per-client toggle ---

  it('setImpersonationAllowed toggles the flag and logs it', async () => {
    const clientId = await createTestClient();
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    req.body.allow_staff_impersonation = '1';

    await clientsController.setImpersonationAllowed(req, fakeRes());

    const client = await clientRepository.find(clientId);
    expect(!!client.allow_staff_impersonation).toBe(true);
    expect(await auditLogCount('CLIENT_IMPERSONATION_ALLOWED_CHANGED', clientId)).toBe(1);
  });

  it('setImpersonationAllowed can turn it back off', async () => {
    const clientId = await createTestClient();
    await clientRepository.setAllowStaffImpersonation(clientId, true);
    const staffId = await createTestStaffUser();
    const req = fakeReq(staffId);
    req.params.id = String(clientId);
    // checkbox unchecked => body field absent

    await clientsController.setImpersonationAllowed(req, fakeRes());

    const client = await clientRepository.find(clientId);
    expect(!!client.allow_staff_impersonation).toBe(false);
  });
});
