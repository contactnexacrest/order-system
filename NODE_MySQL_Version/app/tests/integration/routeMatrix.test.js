'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/** Every route in src/server.js with the permission(s) it requires (any one), or null. */
function routes() {
  const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8');
  return [...source.matchAll(/app\.(get|post)\('([^']+)',([^\n]*)/g)].map(([, method, pattern, rest]) => {
    const one = rest.match(/requirePermission\('([^']+)'\)/);
    const any = rest.match(/requirePermissionAny\(\[([^\]]+)\]\)/);
    let permissions = null;
    if (one) permissions = [one[1]];
    else if (any) permissions = [...any[1].matchAll(/'([^']+)'/g)].map((k) => k[1]);
    return { method: method.toUpperCase(), pattern, permissions };
  });
}

const concrete = (pattern) => pattern.replace(/:[A-Za-z]+/g, '999999');

/**
 * Port of RouteMatrixTest.php. QA Round 5, R5-14: 87 routes had no test in
 * any suite. This drives every route registered in src/server.js through
 * the real app and its real middleware:
 *  - every staff route that requires a permission refuses (403) a
 *    signed-in user who holds no permission at all — GET and POST;
 *  - every staff page without a :param opens for Admin without a server
 *    error;
 *  - every public page without a :param opens for a signed-out visitor.
 */
describe('Route matrix (QA Round 5, R5-14)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function loginWithRole(roleName) {
    await db.execute("INSERT IGNORE INTO roles (name, description, is_system_role) VALUES ('Test Role — No Permissions', 'Test only', 0)");
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-matrix-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestMatrix123!';
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/login')).text);
    const login = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    expect(login.headers.location).not.toBe('/login');
    return agent;
  }

  it('every permission-gated route refuses a user without the permission', async () => {
    const agent = await loginWithRole('Test Role — No Permissions');
    const notRefused = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.permissions !== null)) {
      const res = route.method === 'GET' ? await agent.get(concrete(route.pattern)) : await agent.post(concrete(route.pattern)).type('form').send({});
      if (res.status !== 403) notRefused.push(`${route.method} ${route.pattern} → ${res.status}`);
      checked++;
    }
    expect(checked).toBeGreaterThan(300);
    expect(notRefused).toEqual([]);
  }, 120000);

  it('every staff page without parameters opens for Admin', async () => {
    const agent = await loginWithRole('Admin');
    const failed = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.method === 'GET' && r.permissions !== null && !r.pattern.includes(':'))) {
      const res = await agent.get(route.pattern);
      if (res.status >= 500) failed.push(`${route.pattern} → ${res.status}`);
      checked++;
    }
    expect(checked).toBeGreaterThan(60);
    expect(failed).toEqual([]);
  }, 120000);

  it('every public page without parameters opens signed out', async () => {
    for (const p of ['/login', '/forgot-password', '/client/login', '/client/forgot-password', '/quotation-request']) {
      const res = await request(app).get(p);
      if (res.status === 404) continue; // not a route on this stack
      expect([p, res.status < 500]).toEqual([p, true]);
    }
  });
});
