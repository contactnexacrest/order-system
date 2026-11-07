'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const sopController = require('../../src/controllers/sopController');
const permissionService = require('../../src/services/permissionService');

/**
 * Port of SopCaChapterRbacTest.php. 90-item backlog "SOP/Reference-
 * Library RBAC": the Reference Library already scopes custom entries to
 * a required_permission per category, but the SOP viewer's CA /
 * Accounting chapters — which document the same supplier-cost/
 * profitability/government-benefit detail the live CA module itself
 * gates behind ca_module_view — were visible to any logged-in staff
 * member, same as the main numbered chapters. Reading the live screens
 * already needed that permission; reading the SOP chapter describing
 * them didn't.
 */
describe('SOP CA chapter RBAC (90-item backlog "SOP/Reference-Library RBAC")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  /** D-266: the SOP now follows the signed-in person's role, so each case signs in a real user. */
  async function staff(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest SOP User', :email, 'x', :role, 1, 0, 0)`,
      { email: `jest-sop-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role: role.id }
    );
    const user = { id: r.insertId, role_id: role.id };
    return { user, permissions: await permissionService.load(user.id, user.role_id, false) };
  }

  function fakeRes() {
    const res = {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; return res; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    return res;
  }

  it('index lists CA chapters for a user with ca_module_view', async () => {
    const req = await staff('Admin'); // Admin holds every permission
    const res = fakeRes();

    await sopController.index(req, res);

    const slugs = res.renderedData.chapters.map((c) => c.slug);
    expect(slugs).toContain('ca-01-overview');
  });

  it('index hides CA chapters from a user without ca_module_view', async () => {
    const req = await staff('Viewer / Auditor'); // no ca_module_view
    const res = fakeRes();

    await sopController.index(req, res);

    const slugs = res.renderedData.chapters.map((c) => c.slug);
    expect(slugs).not.toContain('ca-01-overview');
  });

  it('show allows a CA chapter for a user with ca_module_view', async () => {
    const req = { ...(await staff('Admin')), params: { chapter: 'ca-01-overview' } };
    const res = fakeRes();

    await sopController.show(req, res);

    expect(res.statusCode).toBeUndefined();
    expect(res.renderedView).toBe('sop/show');
  });

  it('show blocks a CA chapter for a user without ca_module_view', async () => {
    const req = { ...(await staff('Viewer / Auditor')), params: { chapter: 'ca-01-overview' } };
    const res = fakeRes();

    await sopController.show(req, res);

    expect(res.statusCode).toBe(403);
    expect(res.renderedView).toBe('sop/forbidden'); // D-266: the refusal page in the normal layout ("Not permitted")
  });

  it('show still allows a main chapter for a user without ca_module_view', async () => {
    const req = { ...(await staff('Viewer / Auditor')), params: { chapter: '01-stage1-enquiry-quotation' } };
    const res = fakeRes();

    await sopController.show(req, res);

    expect(res.statusCode).toBeUndefined();
    expect(res.renderedView).toBe('sop/show');
  });
});
