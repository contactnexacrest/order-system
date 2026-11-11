'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const db = require('../../src/config/db');
const settingLabels = require('../../src/helpers/settingLabels');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

const decode = (s) => s.replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

/**
 * Port of SettingsPlainLabelsTest.php — owner decision D-332 (12 Nov 2026):
 * Settings › Company shows each setting by a plain-English name and each section
 * by a plain title; the technical key is only small grey text (for support); no
 * help sentence is a developer note. Same wording as the PHP stack.
 */
describe('Settings in plain words (D-332)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginRes = await agent.post('/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('settings and sections show plain names; the key is only small grey text', async () => {
    const res = await agent.get('/settings');
    expect(res.status).toBe(200);
    expect(res.text).toContain('<span class="setting-name" title="Technical name: session_timeout_staff_minutes">Staff: log out after … minutes idle</span>');
    expect(res.text).toContain('<small class="muted setting-key" title="Technical name of this setting (for support)">session_timeout_staff_minutes</small>');
    for (const title of ['Security and logging in', 'RBI purpose codes', 'Screen layout', 'Zoho Books', 'Company details']) {
      expect(res.text).toContain(`<summary>${title} <span`);
    }
    for (const old of ['<summary>Zoho_books', '<summary>Rbi', '<summary>Ui ']) expect(res.text).not.toContain(old);
    const titles = [...res.text.matchAll(/class="collapsible-section settings-section"[^>]*>\s*<summary>([^<]+) <span/g)].map((m) => decode(m[1]).trim());
    const sorted = [...titles].sort((a, b) => (a.toLowerCase() < b.toLowerCase() ? -1 : (a.toLowerCase() > b.toLowerCase() ? 1 : 0)));
    expect(titles).toEqual(sorted);
  });

  it('no help sentence on the page is a developer note', async () => {
    const res = await agent.get('/settings');
    const helps = [...res.text.matchAll(/<small class="muted setting-help">([^<]*)<\/small>/g)].map((m) => decode(m[1]));
    expect(helps.length).toBeGreaterThan(0);
    for (const h of helps) expect(h).not.toMatch(/SessionAuth|architecture doc|backlog|Addition beyond|\.php\b|Section [A-Z]{1,2}\b|\bD-\d{3}\b|WorkingDaysCalculator|QA report/);
  });

  it('every seeded setting has a plain label and the seed carries the plain sentences', async () => {
    const seed = fs.readFileSync(path.join(__dirname, '../../../docs/seed.sql'), 'utf8');
    const [rows] = await db.pool.query('SELECT setting_key FROM company_settings');
    for (const r of rows) expect(Object.keys(settingLabels.LABELS)).toContain(r.setting_key);
    for (const [key, [label, help]] of Object.entries(settingLabels.LABELS)) {
      expect(label).not.toBe('');
      expect(label.includes('_')).toBe(false);
      if (help !== null) expect(seed.includes(help.replace(/'/g, "''"))).toBe(true);
      expect(key).toMatch(/^[a-z0-9_]+$/);
    }
    for (const old of ['Enforced on every request (SessionAuth)', 'flagging per architecture doc practice', 'D-283: staff are signed out']) expect(seed).not.toContain(old);
    expect(settingLabels.label('something_new')).toBe('Something new');
  });

  it('PHP and Node use the same names, help sentences and section titles', () => {
    const php = fs.readFileSync(path.join(__dirname, '../../../../PHP_MySQL_Version/app/src/Helpers/SettingLabels.php'), 'utf8');
    const q = (s) => `'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
    for (const [key, [label, help]] of Object.entries(settingLabels.LABELS)) {
      expect(php).toContain(`'${key}' => [${q(label)}, ${help === null ? 'null' : q(help)}],`);
    }
    for (const [cat, title] of Object.entries(settingLabels.SECTIONS)) expect(php).toContain(`'${cat}' => ${q(title)},`);
    expect((php.match(/^ {8}'[a-z0-9_]+' => \[/gm) || []).length).toBe(Object.keys(settingLabels.LABELS).length);
  });
});
