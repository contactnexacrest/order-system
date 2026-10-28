'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const permissionRepository = require('../../src/repositories/permissionRepository');
const { createTestClient, createTestOrder, createTestDocument, createTestFile } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/** Every route in src/server.js with the permission(s) it requires (any one), or null. */
function routes() {
  const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8');
  const list = [];
  for (const m of source.matchAll(/app\.(get|post)\('([^']+)',([^\n]*)/g)) {
    const [, method, pattern, rest] = m;
    let permissions = null;
    const one = rest.match(/requirePermission\('([^']+)'\)/);
    const any = rest.match(/requirePermissionAny\(\[([^\]]+)\]\)/);
    if (one) permissions = [one[1]];
    else if (any) permissions = [...any[1].matchAll(/'([^']+)'/g)].map((k) => k[1]);
    const regex = new RegExp(`^${pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/:[A-Za-z]+/g, '[^/]+')}$`);
    list.push({ method: method.toUpperCase(), pattern, regex, permissions });
  }
  return list;
}

/**
 * Port of OrderPageActionsMatchPermissionsTest.php. QA Round 5, R5-20: the
 * order page offered forms and links the user's permissions did not allow
 * — the user filled a form in, submitted it and landed on a bare 403 with
 * their input lost. This renders the order page (every stage open,
 * documents on file) for roles without the broad permissions and checks
 * every form action and link against the permission its route actually
 * requires in src/server.js.
 */
describe('Order page offers only actions the user may use (QA Round 5, R5-20)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function deniedActionsShownTo(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-r520-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestR520Pass123!';
    const user = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    const orderId = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'USD', null, null, user.insertId);
    await db.execute("UPDATE order_stages SET status = 'in_progress' WHERE order_id = :id", { id: orderId });
    for (const code of ['QT', 'PI', 'OC', 'SUPPO', 'PL', 'BLI', 'CI', 'FDN', 'COOPREP', 'BUYERPO']) {
      const documentId = await createTestDocument(orderId, code, code === 'BUYERPO' ? 'draft' : 'approved');
      await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: await createTestFile(orderId, null), id: documentId });
    }

    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    const page = await agent.get(`/orders/${orderId}`);
    expect(page.status).toBe(200);
    const html = page.text;
    expect(html).toContain('id="step-1-quotation-prepare"');

    const permissions = await permissionRepository.effectivePermissions(user.insertId, role.id);
    const targets = [];
    for (const m of html.matchAll(/<form\b[^>]*>/g)) {
      const tag = m[0];
      const action = (tag.match(/action="([^"]*)"/) || [])[1];
      const method = ((tag.match(/method="([^"]*)"/) || [])[1] || 'GET').toUpperCase();
      if (action) targets.push([method, action]);
    }
    for (const m of html.matchAll(/<a\b[^>]*href="([^"]*)"/g)) targets.push(['GET', m[1]]);

    const all = routes();
    const denied = new Set();
    for (const [method, url] of targets) {
      const urlPath = url.replace(/&amp;/g, '&').split(/[?#]/)[0];
      if (!urlPath.startsWith('/')) continue;
      const route = all.find((r) => r.method === method && r.regex.test(urlPath));
      if (!route || route.permissions === null) continue;
      if (!route.permissions.some((key) => permissions[key])) denied.add(`${method} ${route.pattern} — needs ${route.permissions.join(' or ')}`);
    }
    return [...denied].sort();
  }

  // (CA / Chartered Accountant can't open order pages at all — no view_orders.)
  it.each(['Viewer / Auditor', 'Logistics Executive', 'Accounts Executive'])('%s is offered no action it may not use', async (roleName) => {
    expect(await deniedActionsShownTo(roleName)).toEqual([]);
  });
});
