'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

/**
 * Port of GalleryControllerTest.php — pure disk-backed knowledgebase
 * gallery (no upload UI, no database). Runs against the real
 * docs/gallery directory, same precedent as the SOP viewer's own
 * docs/SOP. A temporary nested fixture folder is created and removed
 * per test to exercise folder navigation without permanently altering
 * the committed seed image.
 */
describe('Gallery (disk-backed knowledgebase)', () => {
  const ROOT_DIR = path.join(__dirname, '..', '..', '..', 'docs', 'gallery');
  let agent;

  function extractCsrf(html) {
    const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
    if (!m) throw new Error('CSRF token not found in response HTML');
    return m[1];
  }

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(() => {
    const sub = path.join(ROOT_DIR, '__jest_fixture__');
    if (fs.existsSync(sub)) {
      for (const f of fs.readdirSync(sub)) fs.unlinkSync(path.join(sub, f));
      fs.rmdirSync(sub);
    }
  });

  it('lists the seed image at root', async () => {
    const res = await agent.get('/gallery');

    expect(res.status).toBe(200);
    expect(res.text).toContain('001-dashboard.png');
    expect(res.text).toContain('/gallery/file?p=001-dashboard.png');
  });

  it('serves the seed image with the correct bytes', async () => {
    const res = await agent.get('/gallery/file?p=001-dashboard.png');

    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toBe('image/png');
    expect(res.body.length).toBe(fs.statSync(path.join(ROOT_DIR, '001-dashboard.png')).size);
  });

  it('rejects path traversal outside the gallery root', async () => {
    const res = await agent.get('/gallery/file?p=' + encodeURIComponent('../../../../etc/passwd'));

    expect(res.status).toBe(404);
    expect(res.text).toContain('File not found.');
  });

  it('rejects a path that does not exist', async () => {
    const res = await agent.get('/gallery/file?p=nonexistent-file.png');

    expect(res.status).toBe(404);
  });

  it('navigates into a nested folder and shows a breadcrumb', async () => {
    const sub = path.join(ROOT_DIR, '__jest_fixture__');
    fs.mkdirSync(sub);
    fs.writeFileSync(path.join(sub, 'nested.png'), 'not-a-real-png-but-fine-for-listing');

    const res = await agent.get('/gallery?p=' + encodeURIComponent('__jest_fixture__'));

    expect(res.status).toBe(200);
    expect(res.text).toContain('nested.png');
    expect(res.text).toContain('__jest_fixture__');
    expect(res.text).toContain('Up one level');
  });

  it('falls back to root when asked for a path outside the gallery root, without leaking its contents', async () => {
    const res = await agent.get('/gallery?p=' + encodeURIComponent('../../app/src'));

    expect(res.status).toBe(200);
    expect(res.text).not.toContain('Controllers');
    expect(res.text).toContain('001-dashboard.png');
  });

  it('requires authentication', async () => {
    const res = await request(app).get('/gallery');
    expect(res.status).toBe(302);
  });
});
