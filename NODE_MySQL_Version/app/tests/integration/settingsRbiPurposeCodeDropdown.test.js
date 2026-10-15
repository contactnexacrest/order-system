'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const lookupRepository = require('../../src/repositories/lookupRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

const KEYS = ['rbi_purpose_code_advance', 'rbi_purpose_code_balance', 'rbi_purpose_code_freight'];

/**
 * Port of SettingsRbiPurposeCodeDropdownTest.php. 90-item backlog #31:
 * rbi_purpose_code_advance/balance/freight were plain free-text settings
 * with no standard list, unlike the admin dropdown-backed fields built
 * for #43/#66/#51a. Special-cased by setting_key in settings/index.njk
 * rather than adding a generic value_type='select', since
 * company_settings has no dropdown variant today and nothing else needs
 * one yet.
 */
describe('Settings RBI purpose code dropdowns (90-item backlog #31)', () => {
  let agent;
  const originalValues = {};

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');

    for (const key of KEYS) {
      const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: key });
      originalValues[key] = row.setting_value;
    }
  });

  afterAll(async () => {
    for (const key of KEYS) {
      await db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v: originalValues[key], k: key });
    }
    await db.pool.end();
  });

  it('settings page renders RBI purpose code dropdowns', async () => {
    const res = await agent.get('/settings');
    expect(res.status).toBe(200);

    const codes = await lookupRepository.dropdownOptions('rbi_purpose_code');
    expect(codes.length).toBeGreaterThan(0);

    for (const key of KEYS) {
      expect(res.text).toContain(`name="settings[${key}]" value=`);
      expect(res.text).toContain(`data-key="${key}"\n                data-initial-value=`);
    }
    for (const opt of codes) {
      expect(res.text).toContain(opt.option_value);
    }
  });

  it('selecting a different seeded code persists', async () => {
    const codes = await lookupRepository.dropdownOptions('rbi_purpose_code');
    const other = codes.find((o) => o.option_value !== originalValues.rbi_purpose_code_advance);
    expect(other).toBeDefined();

    const settingsPage = await agent.get('/settings');
    const csrfToken = extractCsrf(settingsPage.text);
    const res = await agent
      .post('/settings/update')
      .type('form')
      .send({
        _csrf: csrfToken,
        reason: 'jest rbi purpose code dropdown regression check',
        [`settings[rbi_purpose_code_advance]`]: other.option_value,
      });
    expect(res.status).toBe(302);

    const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :k', { k: 'rbi_purpose_code_advance' });
    expect(row.setting_value).toBe(other.option_value);
  });
});
