'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const referenceLibraryRepository = require('../../src/repositories/referenceLibraryRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * A Reference Library document whose original filename carries a
 * non-ASCII character (an em dash, in the real case that surfaced this —
 * "Quarry SOP — Block Selection & Reservation.pdf") used to crash the
 * download route outright: a bare Content-Disposition filename="..."
 * with a raw UTF-8 byte isn't valid Latin-1 header content, and Node's
 * http module throws ("Invalid character in header content") rather than
 * send it. Fixed by adding an RFC 6266 filename* parameter with the name
 * percent-encoded, alongside an ASCII-safe filename= fallback.
 */
describe('Reference Library custom document download — non-ASCII filename', () => {
  let agent;
  let tmpFile;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    if (tmpFile) fs.rmSync(tmpFile, { force: true });
    await db.pool.end();
  });

  it('downloads without error and encodes the em dash correctly', async () => {
    tmpFile = path.join(os.tmpdir(), `jest-refdoc-${Date.now()}.pdf`);
    fs.writeFileSync(tmpFile, 'fake pdf bytes for test');

    const id = await referenceLibraryRepository.create('Jest Test — Em Dash Title', null, null);
    await referenceLibraryRepository.updateFile(id, tmpFile, 'Jest Test — Em Dash Title.pdf', 'application/pdf', null);

    const res = await agent.get(`/reference-docs/custom/${id}/download`).buffer(true).parse((response, callback) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => callback(null, Buffer.concat(chunks)));
    });

    expect(res.status).toBe(200);
    expect(res.body.toString()).toBe('fake pdf bytes for test');
    const disposition = res.headers['content-disposition'];
    expect(disposition).toContain('filename*=UTF-8\'\'');
    // The em dash (U+2014) percent-encodes to %E2%80%94 in UTF-8 — never
    // the double-encoded %C3%A2%E2%82%AC%E2%80%9D mojibake a Latin-1
    // round-trip through the raw bytes would have produced.
    expect(disposition).toContain('%E2%80%94');
    expect(disposition).not.toContain('%C3%A2');
    // The bare filename= fallback must stay ASCII-only (no raw em dash byte).
    const bareFilenameMatch = disposition.match(/filename="([^"]*)"/);
    expect(bareFilenameMatch[1]).not.toMatch(/[^\x20-\x7E]/);
  });
});
