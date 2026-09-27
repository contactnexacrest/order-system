'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * QA-5 UP-03 (PHP names this defect, but Node has its own version of the
 * same "oversized upload gives a confusing error" class): multer's own
 * middleware throws a MulterError('LIMIT_FILE_SIZE') before any route
 * handler or verifyCsrf runs (upload.single(...) is registered before
 * verifyCsrf on every upload route — see server.js), which previously fell
 * straight through to the generic 500 handler with no indication of what
 * went wrong. A dedicated handler now catches it and responds 413 with a
 * clear message.
 */
describe('Oversized file upload gets a clear error, not a generic 500 (QA-5 UP-03 parity)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrfToken = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrfToken, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('responds 413 with a file-too-large message for a file over the 5MB limit', async () => {
    // /company-assets/replace uses `upload` (5MB limit) — the smallest
    // configured multer limit in the app, so this is the cheapest route to
    // exceed deterministically.
    const oversized = Buffer.alloc(6 * 1024 * 1024, 'a');

    const res = await agent
      .post('/company-assets/replace')
      .attach('file', oversized, 'huge-logo.png');

    expect(res.status).toBe(413);
    expect(res.text).toContain('File too large');
  }, 20000);
});
