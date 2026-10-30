'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const permittedLinks = require('../../src/helpers/permittedLinks');
const orderAccessService = require('../../src/services/orderAccessService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

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
    agent.userId = (await db.queryOne('SELECT id FROM users WHERE email = :email', { email })).id;
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

  // QA Round 6, R6-N02: every page route under a rule needs exactly what
  // the rule says, so a link hidden by the rule is one the route refuses.
  it('every page route under a rule needs what the rule says', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8');
    let checked = 0;
    for (const line of source.split('\n')) {
      const route = line.match(/^app\.get\('([^']+)',/);
      if (!route) continue;
      const one = line.match(/requirePermission\('([^']+)'\)/);
      const any = line.match(/requirePermissionAny\(\[([^\]]+)\]\)/);
      if (!one && !any) continue;
      const samplePath = route[1].replace(/:[A-Za-z]+/g, '1');
      const rule = permittedLinks.requiredFor(samplePath);
      if (rule === null) continue;
      const required = one ? [one[1]] : [...any[1].matchAll(/'([^']+)'/g)].map((k) => k[1]);
      expect([route[1], [...rule].sort()]).toEqual([route[1], required.sort()]);
      checked++;
    }
    expect(checked).toBeGreaterThan(70);
  });

  it('links that would answer 403 are plain text (QA Round 6, R6-N02)', async () => {
    const exporter = await loginAs('Export Executive');
    const mine = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'USD', null, null, exporter.userId);
    const other = await createTestOrder(await createTestClient());
    const perms = { manage_orders: true };
    const canSee = await orderAccessService.orderVisibilityChecker(exporter.userId, perms);
    const html = permittedLinks.filterHtml(`<a href="/orders/${mine}">A</a> <a href="/orders/${other}">B</a> <a href="/reports/order/${other}">C</a> <a href="/orders/${other}/disputes">D</a>`, perms, canSee);
    expect(html).toContain(`href="/orders/${mine}"`);
    expect(html).not.toContain(`href="/orders/${other}`);
    expect(html).not.toContain(`href="/reports/order/${other}"`);

    const agent = await loginAs('Viewer / Auditor');
    const permission = await db.queryOne("SELECT id FROM permissions WHERE permission_key = 'manage_incentives'");
    await db.execute('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, 1)', { u: agent.userId, p: permission.id });
    const settings = (await agent.get('/incentives/settings')).text;
    expect(settings).toContain('Incentive payouts');
    expect(settings).not.toContain('href="/incentives/payouts"');
  });
});
