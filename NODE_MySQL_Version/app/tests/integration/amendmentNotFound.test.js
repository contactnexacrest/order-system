'use strict';

const db = require('../../src/config/db');
const amendmentController = require('../../src/controllers/amendmentController');
const flash = require('../../src/helpers/flash');

/**
 * QA-5 (AMD-06 — external QA report cross-verification): mdApprove(),
 * reject(), and generateDocument() all built their redirect from
 * `amendment && amendment.order_id`, falling back to '' when the
 * amendment lookup itself came back null (an unknown or already-deleted
 * id) — producing a malformed `/orders//amendments` redirect instead of a
 * clean 404, and masking what should have been an obvious "this id
 * doesn't exist" signal behind a broken URL.
 */
describe('Amendment not-found guard (QA-5 AMD-06)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('mdApprove on an unknown amendment id returns 404, not a malformed redirect', async () => {
    const req = { params: { amendmentId: '999999' }, body: {}, user: { id: 1 }, session: {} };
    const res = fakeRes();

    await amendmentController.mdApprove(req, res);

    expect(res.statusCode).toBe(404);
    expect(res.redirectedTo).toBeNull();
    flash.pull(req);
  });

  it('reject on an unknown amendment id returns 404, not a malformed redirect', async () => {
    const req = { params: { amendmentId: '999999' }, body: {}, user: { id: 1 }, session: {} };
    const res = fakeRes();

    await amendmentController.reject(req, res);

    expect(res.statusCode).toBe(404);
    expect(res.redirectedTo).toBeNull();
    flash.pull(req);
  });

  it('generateDocument on an unknown amendment id returns 404, not a malformed redirect', async () => {
    const req = { params: { amendmentId: '999999' }, body: {}, user: { id: 1 }, session: {} };
    const res = fakeRes();

    await amendmentController.generateDocument(req, res);

    expect(res.statusCode).toBe(404);
    expect(res.redirectedTo).toBeNull();
    flash.pull(req);
  });
});
