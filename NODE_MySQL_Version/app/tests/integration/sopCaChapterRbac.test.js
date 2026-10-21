'use strict';

const sopController = require('../../src/controllers/sopController');

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
  function fakeRes() {
    const res = {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; return res; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    return res;
  }

  it('index lists CA chapters for a user with ca_module_view', async () => {
    const req = { permissions: { ca_module_view: true } };
    const res = fakeRes();

    await sopController.index(req, res);

    const slugs = res.renderedData.chapters.map((c) => c.slug);
    expect(slugs).toContain('ca-01-overview');
  });

  it('index hides CA chapters from a user without ca_module_view', async () => {
    const req = { permissions: {} };
    const res = fakeRes();

    await sopController.index(req, res);

    const slugs = res.renderedData.chapters.map((c) => c.slug);
    expect(slugs).not.toContain('ca-01-overview');
  });

  it('show allows a CA chapter for a user with ca_module_view', async () => {
    const req = { permissions: { ca_module_view: true }, params: { chapter: 'ca-01-overview' } };
    const res = fakeRes();

    await sopController.show(req, res);

    expect(res.statusCode).toBeUndefined();
    expect(res.renderedView).toBe('sop/show');
  });

  it('show blocks a CA chapter for a user without ca_module_view', async () => {
    const req = { permissions: {}, params: { chapter: 'ca-01-overview' } };
    const res = fakeRes();

    await sopController.show(req, res);

    expect(res.statusCode).toBe(403);
    expect(res.body).toContain('Not permitted');
  });

  it('show still allows a main chapter for a user without ca_module_view', async () => {
    const req = { permissions: {}, params: { chapter: '01-stage1-enquiry-quotation' } };
    const res = fakeRes();

    await sopController.show(req, res);

    expect(res.statusCode).toBeUndefined();
    expect(res.renderedView).toBe('sop/show');
  });
});
