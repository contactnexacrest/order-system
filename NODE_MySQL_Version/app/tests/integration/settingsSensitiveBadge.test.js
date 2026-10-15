'use strict';

const request = require('supertest');
const app = require('../../src/server');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const db = require('../../src/config/db');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of SettingsSensitiveBadgeTest.php. 90-item backlog #28:
 * sensitive-setting alert badges. Investigation confirmed this was
 * already fully implemented — every company_settings row flagged
 * is_sensitive=1 renders a visible <span class="badge badge-sensitive">
 * sensitive</span> badge, carries data-sensitive="1" through to its
 * field, and confirmSettingsSave() warns distinctly before a save that
 * touches one goes through. No code change was required for this item —
 * this test locks in that existing, already-correct behavior.
 */
describe('Settings sensitive badge (90-item backlog #28)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('sensitive setting renders the sensitive badge', async () => {
    const res = await agent.get('/settings');
    expect(res.status).toBe(200);

    expect(res.text).toMatch(/bank_account_no[\s\S]*?<span class="badge badge-sensitive">sensitive<\/span>/);
    expect(res.text).toMatch(/name="settings\[bank_account_no\]"[^>]*data-sensitive="1"/);
  });

  it('save confirmation JS warns distinctly for sensitive field changes', async () => {
    const res = await agent.get('/settings');

    expect(res.text).toContain('changedSensitive');
    expect(res.text).toContain('banking/RBI-sensitive field');
  });
});
