'use strict';

const csrfCheck = require('../../src/middleware/csrfCheck');

/**
 * Port of CsrfCheckRedirectTest.php. 90-item backlog #25: a CSRF mismatch
 * (almost always a genuinely stale session, not an attack) used to send a
 * bare, unstyled "419 — Session expired" page with no navigation at all —
 * a dead end whose own advice ("refresh the page") just re-submits into the
 * same wall. csrfCheck.verify() now redirects to the right login screen
 * instead, matching every other session-expiry path in this app.
 */
describe('csrfCheck redirect on mismatch (90-item backlog #25)', () => {
  function fakeReqRes(path, session) {
    const req = { method: 'POST', path, body: {}, session };
    const res = { statusCode: null, redirectedTo: null, sent: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.sent = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; return res; };
    return { req, res };
  }

  it('staff route CSRF mismatch no longer sends the raw 419 page', () => {
    const { req, res } = fakeReqRes('/settings/update', { _csrf_token: 'the-real-token' });
    req.body._csrf = 'a-stale-mismatched-token';
    let nextCalled = false;

    csrfCheck.verify()(req, res, () => { nextCalled = true; });

    expect(nextCalled).toBe(false);
    expect(res.statusCode).not.toBe(419);
    expect(res.sent).toBeNull();
    expect(res.redirectedTo).toBe('/login');
  });

  it('client portal route CSRF mismatch redirects to /client/login instead', () => {
    const { req, res } = fakeReqRes('/client/orders/5/comments', { _csrf_token: 'the-real-token' });
    req.body._csrf = 'a-stale-mismatched-token';
    let nextCalled = false;

    csrfCheck.verify()(req, res, () => { nextCalled = true; });

    expect(nextCalled).toBe(false);
    expect(res.statusCode).not.toBe(419);
    expect(res.sent).toBeNull();
    expect(res.redirectedTo).toBe('/client/login');
  });

  it('a missing CSRF token entirely also no longer sends the raw 419 page', () => {
    // The genuinely common real-world case: session expired entirely, so
    // there's no stored token to compare against at all.
    const { req, res } = fakeReqRes('/orders/5/shipping', {});
    req.body._csrf = 'whatever-was-in-the-stale-form';
    let nextCalled = false;

    csrfCheck.verify()(req, res, () => { nextCalled = true; });

    expect(nextCalled).toBe(false);
    expect(res.sent).toBeNull();
    expect(res.redirectedTo).toBe('/login');
  });

  it('a matching token still passes through normally', () => {
    const { req, res } = fakeReqRes('/settings/update', { _csrf_token: 'the-real-token' });
    req.body._csrf = 'the-real-token';
    let nextCalled = false;

    csrfCheck.verify()(req, res, () => { nextCalled = true; });

    expect(nextCalled).toBe(true);
    expect(res.redirectedTo).toBeNull();
  });
});
