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
 * Port of SettingsCollapsibleSectionsTest.php. 90-item backlog #27: the
 * Settings screen renders ~20 <fieldset> categories as one long unbroken
 * scroll with no way to collapse a category you don't need right now.
 * Fixed by wrapping each category's fieldset in a plain <details>/<summary>
 * section plus Expand all/Collapse all buttons. Inputs inside a closed
 * <details> still submit normally, so this is a pure presentation change
 * with no effect on what gets saved.
 *
 * 90-item backlog "sitewide collapsible sections beyond Settings": each
 * section now also carries the sitewide "collapsible-section" class (the
 * open/closed-per-browser persistence itself moved to layout/base.njk so
 * every page gets it, not just this one) — "settings-section" stays
 * alongside it unchanged, since toggleAllSettingsSections() and this
 * test's own selectors still key off it.
 */
describe('Settings collapsible sections (90-item backlog #27)', () => {
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

  it('each settings category is wrapped in a collapsible details section', async () => {
    const res = await agent.get('/settings');
    expect(res.status).toBe(200);

    expect(res.text).toMatch(/<details class="collapsible-section settings-section" data-category="company"[^>]*>\s*<summary>Company/s);
    expect(res.text).toContain('<div class="settings-toolbar">');
    expect(res.text).toContain('toggleAllSettingsSections(true)');
    expect(res.text).toContain('toggleAllSettingsSections(false)');
  });

  it('fields remain inside the fieldset nested within the details section', async () => {
    const res = await agent.get('/settings');

    expect(res.text).toMatch(/<details class="collapsible-section settings-section" data-category="company"[^>]*>[\s\S]*?<fieldset>[\s\S]*?name="settings\[/);
  });

  // Owner request (2026-10-25): "by default Company Settings should be
  // compressed" — every section now starts collapsed (it used to start
  // open); each browser still remembers a section the user opened
  // (layout/base.njk's per-section open/closed persistence).
  it('details sections default to collapsed', async () => {
    const res = await agent.get('/settings');
    expect(res.status).toBe(200);
    expect(res.text).toMatch(/<details class="collapsible-section settings-section" data-category="[^"]+">/);
    expect(res.text).not.toMatch(/<details class="collapsible-section settings-section" data-category="[^"]+"\s+open>/);
  });
});
