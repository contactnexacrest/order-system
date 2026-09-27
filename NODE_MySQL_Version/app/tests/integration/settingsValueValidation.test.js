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
 * QA-5 (SET-02 — external QA report cross-verification): company_settings
 * defines a value_type per row (number/boolean/date/json/string), but
 * SettingsController::update() never actually checked a submitted value
 * against it — any string could be saved into a 'number' setting like
 * session_timeout_minutes, later parsing to NaN or a negative threshold
 * everywhere it's read.
 */
describe('Admin settings reject values that violate their value_type (QA-5 SET-02)', () => {
  let agent;
  let originalValue;
  const settingKey = 'session_timeout_minutes'; // value_type = 'number'

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

    const row = await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = :k", { k: settingKey });
    originalValue = row.setting_value;
  });

  afterAll(async () => {
    await db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v: originalValue, k: settingKey });
    await db.pool.end();
  });

  async function attemptUpdate(newValue) {
    const settingsPage = await agent.get('/settings');
    const csrfToken = extractCsrf(settingsPage.text);
    return agent
      .post('/settings/update')
      .type('form')
      .send({
        _csrf: csrfToken,
        reason: 'jest test-mode settings validation regression check',
        [`settings[${settingKey}]`]: newValue,
      });
  }

  it.each(['not-a-number', '-5', ''])('rejects "%s" for a number-typed setting and saves nothing', async (badValue) => {
    await attemptUpdate(badValue);

    const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: settingKey });
    expect(row.setting_value).toBe(originalValue);
  });

  it('accepts a valid non-negative number for the same setting', async () => {
    const res = await attemptUpdate('45');

    const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: settingKey });
    expect(row.setting_value).toBe('45');
    expect(res.status).toBe(302);
  });
});
