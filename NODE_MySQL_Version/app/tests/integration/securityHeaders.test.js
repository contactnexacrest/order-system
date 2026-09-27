'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const env = require('../../src/config/env');

/**
 * QA-5 UP-02/AUTH-14: every file-download action (dispute/amendment/BL/PO
 * evidence, chat attachments, client-portal payment screenshots) served back
 * a Content-Type trusted from whatever the browser declared at upload time,
 * and the site had no X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy, or HSTS header anywhere — leaving it open to MIME-sniffed
 * stored XSS via upload, clickjacking of authenticated pages (e.g. an
 * order's approve/close action framed under bait by a disgruntled insider),
 * token leakage through the Referer header, and protocol downgrade. A
 * single middleware in server.js now sets these on every response, ahead of
 * every route and express.static, so this is exercised via real HTTP
 * requests rather than the middleware in isolation.
 */
describe('Sitewide security headers (QA-5 UP-02/AUTH-14)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('are set on an ordinary page response', async () => {
    const res = await request(app).get('/login');
    expect(res.headers['x-content-type-options']).toBe('nosniff');
    expect(res.headers['x-frame-options']).toBe('DENY');
    expect(res.headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
  });

  it('are set on a 404 response', async () => {
    const res = await request(app).get('/this-route-does-not-exist');
    expect(res.headers['x-content-type-options']).toBe('nosniff');
    expect(res.headers['x-frame-options']).toBe('DENY');
    expect(res.headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
  });

  it('are set on a static asset served from /public', async () => {
    const res = await request(app).get('/css/app.css');
    expect(res.status).toBe(200);
    expect(res.headers['x-content-type-options']).toBe('nosniff');
    expect(res.headers['x-frame-options']).toBe('DENY');
  });

  it('HSTS is omitted locally but sent in production', async () => {
    // The test suite always runs with APP_ENV=local (see .env) — confirm
    // that's actually true before relying on it, then flip process.env to
    // prove the conditional logic itself, not just one branch of it.
    expect(env.isLocal()).toBe(true);
    const localRes = await request(app).get('/login');
    expect(localRes.headers['strict-transport-security']).toBeUndefined();

    const original = process.env.APP_ENV;
    process.env.APP_ENV = 'production';
    try {
      const prodRes = await request(app).get('/login');
      expect(prodRes.headers['strict-transport-security']).toBe('max-age=31536000; includeSubDomains');
    } finally {
      process.env.APP_ENV = original;
    }
  });
});
