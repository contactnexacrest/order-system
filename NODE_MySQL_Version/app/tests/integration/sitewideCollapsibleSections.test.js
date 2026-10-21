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
 * Port of SitewideCollapsibleSectionsTest.php. 90-item backlog "sitewide
 * collapsible sections beyond Settings": the independently-collapsible,
 * per-browser-remembered <details> pattern 90-item backlog #27 built for
 * Company Settings was inline in that page's own <script>, keyed to
 * class="settings-section" — nothing else in the app could reuse it. The
 * persistence logic moved to views/layout/base.njk (every page already
 * loads this layout), keyed to the generic class="collapsible-section"
 * instead, and the Admin Field Overrides screen is the first page beyond
 * Settings to use it.
 *
 * Deliberately NOT applied to the order page (views/orders/show.njk):
 * it already has an equivalent-or-better mechanism — each top-level
 * .section is a tab-like panel switched via an auto-built sidebar, found
 * by `el.classList.contains('section')`. Converting those to
 * <details class="collapsible-section"> (dropping the "section" class)
 * would silently break that panel switcher.
 */
describe('Sitewide collapsible sections (90-item backlog "sitewide collapsible sections beyond Settings")', () => {
  let agent;

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

  it('each Admin Overrides section is wrapped in a collapsible <details> section', async () => {
    const res = await agent.get('/admin/overrides');
    expect(res.status).toBe(200);

    const cases = [
      ['admin-overrides-document-types', 'Document Reference Formats'],
      ['admin-overrides-tc-clauses', 'Terms &amp; Conditions, Legal Terms &amp; Definitions'],
      ['admin-overrides-payment-presets', 'Payment Presets'],
    ];
    for (const [category, heading] of cases) {
      const re = new RegExp(
        `<details class="collapsible-section" data-category="${category}"\\s+open>\\s*<summary><h2>${heading}</h2></summary>`,
        's'
      );
      expect(res.text).toMatch(re);
    }
  });

  it('fields and forms still render inside the details section', async () => {
    const res = await agent.get('/admin/overrides');

    const re = /<details class="collapsible-section" data-category="admin-overrides-document-types"[^>]*>[\s\S]*?<form[^>]*action="\/admin\/overrides\/document-types"[^>]*>[\s\S]*?name="ref_format\[/;
    expect(res.text).toMatch(re);
  });

  it('the shared collapsible-section script is loaded from the sitewide layout', async () => {
    const res = await agent.get('/admin/overrides');

    expect(res.text).toContain("querySelectorAll('.collapsible-section[data-category]')");
    expect(res.text).toContain("'settings-section-open:' + details.dataset.category");
  });
});
