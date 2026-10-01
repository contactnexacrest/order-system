'use strict';

const db = require('../../src/config/db');
const caController = require('../../src/controllers/caController');

/**
 * Port of CaHubNavTest.php. Point 3 (2026-10-01): the CA / Accounting hub
 * page used to be a single paragraph of inline middot-separated links —
 * this covers its replacement with card sections (same pattern as the
 * Reports hub), and specifically that the Zoho Books / Financial Year Lock
 * card — gated on the stricter ca_module_manage, not the plain
 * ca_module_view every other card needs — is only shown to a user who
 * actually holds it.
 */
describe('CA hub navigation (Point 3)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  it('passes the hub data the index.njk card sections now render from', async () => {
    const req = { permissions: { ca_module_view: true, ca_module_manage: true } };
    const res = fakeRes();

    await caController.index(req, res);

    expect(res.renderedView).toBe('ca/index');
    expect(Array.isArray(res.renderedData.settlements)).toBe(true);
    expect(typeof res.renderedData.usersById).toBe('object');
    expect(res.renderedData.canManageCa).toBe(true);
  });

  it('exposes canManageCa true for a user with ca_module_manage', async () => {
    const req = { permissions: { ca_module_view: true, ca_module_manage: true } };
    const res = fakeRes();

    await caController.index(req, res);

    expect(res.renderedData.canManageCa).toBe(true);
  });

  it('exposes canManageCa false for a view-only CA user', async () => {
    // 'CA / Chartered Accountant' is seeded with ca_module_view only,
    // deliberately not ca_module_manage (docs/SOP/ca-01-overview.md) —
    // mirrored here directly via req.permissions since this controller
    // trusts sessionAuth middleware to have already loaded them.
    const req = { permissions: { ca_module_view: true } };
    const res = fakeRes();

    await caController.index(req, res);

    expect(res.renderedData.canManageCa).toBe(false);
    // The settlements register itself must still be passed through
    // regardless of the manage permission.
    expect(Array.isArray(res.renderedData.settlements)).toBe(true);
  });

  it('defaults canManageCa to false when req.permissions is missing entirely', async () => {
    const req = {};
    const res = fakeRes();

    await caController.index(req, res);

    expect(res.renderedData.canManageCa).toBe(false);
  });
});
