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

const SETTING_KEY = 'storage_base_path';

/**
 * Port of SettingsStorageBasePathReadOnlyTest.php. 90-item backlog #33:
 * storage_base_path was a plain, fully-editable Settings field, but
 * editing it has zero effect on the app — the real path only ever comes
 * from STORAGE_BASE_PATH in .env. Fixed by making it genuinely
 * read-only — client-side (forced readonly + a "read-only" badge in
 * settings/index.njk) and server-side (settingsController.update()
 * refuses the whole submission if a crafted POST still includes this
 * key, same fail-closed pattern as is_protected).
 */
describe('Settings storage_base_path is read-only (90-item backlog #33)', () => {
  let agent;
  let originalValue;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');

    const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: SETTING_KEY });
    originalValue = row.setting_value;
  });

  afterAll(async () => {
    await db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v: originalValue, k: SETTING_KEY });
    await db.pool.end();
  });

  it('settings page renders storage_base_path as read-only', async () => {
    const res = await agent.get('/settings');
    expect(res.status).toBe(200);

    expect(res.text).toContain('>read-only<');
    const match = res.text.match(/name="settings\[storage_base_path\]"[^>]*readonly/s);
    expect(match).not.toBeNull();
  });

  it('update rejects a change to storage_base_path even via direct POST', async () => {
    const settingsPage = await agent.get('/settings');
    const csrfToken = extractCsrf(settingsPage.text);
    const res = await agent
      .post('/settings/update')
      .type('form')
      .send({
        _csrf: csrfToken,
        reason: 'jest storage_base_path readonly regression check — attempted bypass',
        [`settings[${SETTING_KEY}]`]: '/some/other/path',
      });
    expect(res.status).toBe(302);

    const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: SETTING_KEY });
    expect(row.setting_value).toBe(originalValue);
  });
});
