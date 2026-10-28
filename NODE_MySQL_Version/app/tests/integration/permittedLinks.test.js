'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const permittedLinks = require('../../src/helpers/permittedLinks');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of PermittedLinksTest.php. QA Round 5, R5-04 / R5-22: links to
 * pages the user can't open (each a 403) — in the SOP and Getting Started
 * text, the sidebar's intake/reorder links, the Dashboard's audit-log
 * button — are no longer shown as links.
 */
describe('Links only to pages the user may open (QA Round 5, R5-04 / R5-22)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function loginAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-links-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestLinks123!';
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    return agent;
  }

  it('every rule is the permission its route requires in server.js', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8');
    const samples = {
      '/orders/1/dossier': '/orders/:id/dossier',
      '/orders/1/additional-documents/removed': '/orders/:id/additional-documents/removed',
      '/clients/1/additional-documents/removed': '/clients/:id/additional-documents/removed',
      '/documents/1/download': '/documents/:documentId/download',
      '/settings': '/settings', '/audit-log': '/audit-log', '/ca': '/ca', '/disputes': '/disputes', '/complaints': '/complaints',
      '/reports': '/reports', '/clients': '/clients', '/orders': '/orders', '/client-intake': '/client-intake',
      '/pi-intake-review': '/pi-intake-review', '/reorder-requests': '/reorder-requests', '/admin/field-protection': '/admin/field-protection',
      '/company-assets': '/company-assets', '/signatories': '/signatories', '/ports': '/ports', '/port-routes': '/port-routes', '/users': '/users',
    };
    for (const [samplePath, routePath] of Object.entries(samples)) {
      const line = source.split('\n').find((l) => l.startsWith(`app.get('${routePath}',`));
      expect([samplePath, !!line]).toEqual([samplePath, true]);
      const one = line.match(/requirePermission\('([^']+)'\)/);
      const any = line.match(/requirePermissionAny\(\[([^\]]+)\]\)/);
      const required = one ? [one[1]] : [...any[1].matchAll(/'([^']+)'/g)].map((k) => k[1]);
      expect([samplePath, [...permittedLinks.requiredFor(samplePath)].sort()]).toEqual([samplePath, required.sort()]);
    }
  });

  it('a Viewer sees no link to a page it cannot open', async () => {
    const agent = await loginAs('Viewer / Auditor');

    const gettingStarted = (await agent.get('/getting-started')).text;
    for (const p of ['/settings', '/ca', '/disputes', '/complaints']) expect([p, gettingStarted.includes(`href="${p}"`)]).toEqual([p, false]);
    expect(gettingStarted).toContain('href="/reports"');
    expect(gettingStarted).toContain('href="/sop/14-reports"');
    expect(gettingStarted).not.toContain('href="/sop/ca-01-overview"');

    expect((await agent.get('/sop')).text).not.toContain('href="/sop/ca-');

    const dashboard = (await agent.get('/')).text;
    for (const p of ['/client-intake', '/pi-intake-review', '/reorder-requests']) expect([p, dashboard.includes(`href="${p}"`)]).toEqual([p, false]);
  });

  it('an Admin still sees every link', async () => {
    const agent = await loginAs('Admin');
    expect((await agent.get('/getting-started')).text).toContain('href="/settings"');
    expect((await agent.get('/')).text).toContain('href="/pi-intake-review"');
  });
});
