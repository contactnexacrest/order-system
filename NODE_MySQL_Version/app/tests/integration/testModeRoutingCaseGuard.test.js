'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const testModeService = require('../../src/services/testModeService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * QA-5 (TM-03/TM-05/PAR-02 — external QA report cross-verification):
 * Express matches routes case-insensitively by default, unlike the PHP
 * stack's hand-rolled Router (a plain case-sensitive preg_match that 404s
 * on any other case). testModeGate's own path-prefix checks compared
 * req.path case-sensitively too, so /CLIENT/login and /SETTINGS/update
 * reached the exact same real handler as their lowercase originals while
 * sailing straight past both the client-facing block and the
 * admin-settings freeze. Fixed with app.set('case sensitive routing',
 * true) (restores parity with PHP: wrong case now 404s) plus a
 * case-insensitive matchesPrefix() in testModeGate itself, so the gate
 * doesn't depend on that app setting to stay safe.
 */
describe('Test Mode gates are not bypassable by path case (QA-5 TM-03/TM-05/PAR-02)', () => {
  let originalTestModeSettings;

  beforeAll(async () => {
    originalTestModeSettings = await testModeService.getSettings();
  });

  afterAll(async () => {
    await db.execute('UPDATE test_mode_settings SET is_enabled = :enabled, test_email = :email WHERE id = 1', {
      enabled: originalTestModeSettings.is_enabled,
      email: originalTestModeSettings.test_email,
    });
    await db.pool.end();
  });

  describe('client-facing block (TM-03)', () => {
    beforeEach(async () => {
      await db.execute('UPDATE test_mode_settings SET is_enabled = 1 WHERE id = 1');
    });

    it('blocks the canonical-case path', async () => {
      const res = await request(app).get('/client/login');
      expect(res.status).toBe(503);
    });

    it.each(['/CLIENT/login', '/Client/Login', '/cLiEnT/login'])('blocks %s just as it blocks the canonical case', async (path) => {
      const res = await request(app).get(path);
      expect(res.status).not.toBe(200);
      expect(res.text).not.toMatch(/name="password"/i);
    });
  });

  describe('admin-settings freeze (TM-05)', () => {
    let agent;
    let settingKey;
    let originalValue;

    beforeAll(async () => {
      agent = request.agent(app);
      const loginPage = await agent.get('/login');
      const loginCsrf = extractCsrf(loginPage.text);
      const loginRes = await agent
        .post('/login')
        .type('form')
        .send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
      expect(loginRes.status).toBe(302);
      expect(loginRes.headers.location).not.toBe('/login');

      const row = await db.queryOne("SELECT setting_key, setting_value FROM company_settings WHERE setting_key = 'md_title'");
      settingKey = row.setting_key;
      originalValue = row.setting_value;
    });

    beforeEach(async () => {
      await db.execute('UPDATE test_mode_settings SET is_enabled = 1 WHERE id = 1');
      await db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v: originalValue, k: settingKey });
    });

    afterAll(async () => {
      await db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v: originalValue, k: settingKey });
    });

    async function attemptUpdate(path) {
      const settingsPage = await agent.get('/settings');
      const csrfToken = extractCsrf(settingsPage.text);
      return agent
        .post(path)
        .type('form')
        .send({
          _csrf: csrfToken,
          reason: 'jest test-mode routing-case regression check',
          [`settings[${settingKey}]`]: `${originalValue}-CHANGED`,
        });
    }

    it('refuses the write on the canonical-case path', async () => {
      await attemptUpdate('/settings/update');
      const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: settingKey });
      expect(row.setting_value).toBe(originalValue);
    });

    it.each(['/SETTINGS/update', '/Settings/Update'])('refuses the write on %s just as it refuses the canonical case', async (path) => {
      await attemptUpdate(path);
      const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: settingKey });
      expect(row.setting_value).toBe(originalValue);
    });
  });
});
