'use strict';

const db = require('../../src/config/db');
const testModeGate = require('../../src/middleware/testModeGate');

/**
 * QA-5 TM-06 (Owner Decision #4: "All decision-making screens frozen; only
 * operational screens available"): /hs-codes, /email-templates,
 * /admin/roles, and /admin/permission-definitions were entirely missing
 * from testModeGate's FROZEN_PREFIXES list — HS codes, buyer-facing email
 * wording, and the roles/permissions that decide who can do what could all
 * still be created or edited while Test Mode was supposedly frozen.
 */
describe('Test Mode admin freeze covers HS codes / email templates / roles / permission definitions (QA-5 TM-06)', () => {
  let originalIsEnabled;

  beforeAll(async () => {
    const settings = await db.queryOne('SELECT is_enabled FROM test_mode_settings WHERE id = 1');
    originalIsEnabled = settings.is_enabled;
  });

  afterAll(async () => {
    await db.execute('UPDATE test_mode_settings SET is_enabled = :enabled WHERE id = 1', { enabled: originalIsEnabled });
    await db.pool.end();
  });

  function fakeReqRes(method, path) {
    const req = { method, path, session: {}, get: () => undefined };
    const res = { redirectedTo: null, redirect: (url) => { res.redirectedTo = url; } };
    return { req, res };
  }

  async function runMiddleware(mw, req, res) {
    let nextCalled = false;
    await mw(req, res, () => { nextCalled = true; });
    return nextCalled;
  }

  const NEWLY_FROZEN_PATHS = [
    '/hs-codes',
    '/hs-codes/3/update',
    '/email-templates',
    '/email-templates/5/update',
    '/admin/roles',
    '/admin/roles/2/update',
    '/admin/permission-definitions',
    '/admin/permission-definitions/1/update',
  ];

  it.each(NEWLY_FROZEN_PATHS)('blocks a POST to %s while Test Mode is on', async (path) => {
    await db.execute('UPDATE test_mode_settings SET is_enabled = 1 WHERE id = 1');
    const mw = testModeGate.blockAdminWritesInTestMode();
    const { req, res } = fakeReqRes('POST', path);

    const nextCalled = await runMiddleware(mw, req, res);

    expect(nextCalled).toBe(false);
    expect(res.redirectedTo).not.toBeNull();
  });

  it.each(NEWLY_FROZEN_PATHS)('lets a POST to %s through once Test Mode is off', async (path) => {
    await db.execute('UPDATE test_mode_settings SET is_enabled = 0 WHERE id = 1');
    const mw = testModeGate.blockAdminWritesInTestMode();
    const { req, res } = fakeReqRes('POST', path);

    const nextCalled = await runMiddleware(mw, req, res);

    expect(nextCalled).toBe(true);
    expect(res.redirectedTo).toBeNull();
  });
});
