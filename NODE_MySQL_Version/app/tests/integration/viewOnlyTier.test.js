'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const permissionCheck = require('../../src/middleware/permissionCheck');
const permissionRepository = require('../../src/repositories/permissionRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of ViewOnlyTierTest.php (Batch 3 #13a, part 2). view_orders/
 * view_clients are a read-only tier for the Orders and Clients modules,
 * alongside manage_orders (which already implies full view+edit access).
 * The seeded 'Viewer / Auditor' role carries both. Routes use
 * permissionCheck.requiresAny(['manage_orders', 'view_orders'|
 * 'view_clients']) — never changed here — so this suite covers: (1)
 * requiresAny() itself passes a view-only user and blocks a user with
 * neither, and (2) the order/client show/index views, whose mutating UI
 * is gated on permissions.manage_orders directly in the .njk templates,
 * correctly hide it for a view-only user and show it for a manage_orders
 * user.
 */
describe('View-only tier for Orders/Clients (Batch 3 #13a part 2)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createLoginableUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-viewonly-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestViewOnly123!';
    const passwordHash = await bcrypt.hash(password, 10);
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: passwordHash, role_id: role.id }
    );
    return { userId: result.insertId, email, password };
  }

  async function effectivePermissionsFor(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const { userId } = await createLoginableUser(roleName);
    return { userId, permissions: await permissionRepository.effectivePermissions(userId, role.id) };
  }

  async function callRequiresAny(keys, userId, permissions) {
    const req = { user: { id: userId }, permissions };
    let nextCalled = false;
    const res = {
      status(code) { this.statusCode = code; return this; },
      send() { /* no-op */ },
    };
    const middleware = permissionCheck.requiresAny(keys);
    await middleware(req, res, () => { nextCalled = true; });
    return nextCalled;
  }

  test('requiresAny passes a view-only user for orders', async () => {
    const { userId, permissions } = await effectivePermissionsFor('Viewer / Auditor');
    expect(await callRequiresAny(['manage_orders', 'view_orders'], userId, permissions)).toBe(true);
  });

  test('requiresAny passes a view-only user for clients', async () => {
    const { userId, permissions } = await effectivePermissionsFor('Viewer / Auditor');
    expect(await callRequiresAny(['manage_orders', 'view_clients'], userId, permissions)).toBe(true);
  });

  test('requiresAny blocks a user with neither permission', async () => {
    // 'CA / Chartered Accountant' holds ca_module_view/inr_actual_view only — neither manage_orders nor view_orders/view_clients.
    const { userId, permissions } = await effectivePermissionsFor('CA / Chartered Accountant');
    expect(await callRequiresAny(['manage_orders', 'view_orders'], userId, permissions)).toBe(false);
    expect(await callRequiresAny(['manage_orders', 'view_clients'], userId, permissions)).toBe(false);
  });

  test('manage_orders alone also passes', async () => {
    const { userId, permissions } = await effectivePermissionsFor('Export Executive');
    expect(await callRequiresAny(['manage_orders', 'view_orders'], userId, permissions)).toBe(true);
  });

  test('Viewer / Auditor role carries both new permissions on their own', async () => {
    const { userId, permissions } = await effectivePermissionsFor('Viewer / Auditor');
    expect(await callRequiresAny(['view_orders'], userId, permissions)).toBe(true);
    expect(await callRequiresAny(['view_clients'], userId, permissions)).toBe(true);
  });

  describe('rendered UI — order/client show/index pages', () => {
    async function loginAs(roleName) {
      const { email, password } = await createLoginableUser(roleName);
      const agent = request.agent(app);
      const loginPage = await agent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
      expect(loginRes.status).toBe(302);
      expect(loginRes.headers.location).not.toBe('/login');
      return agent;
    }

    test('order show page hides mutating buttons for a view-only user', async () => {
      const orderId = await createTestOrder(await createTestClient());
      const agent = await loginAs('Viewer / Auditor');

      const res = await agent.get(`/orders/${orderId}`);

      expect(res.status).toBe(200);
      expect(res.text).not.toContain('Edit Order Details');
      expect(res.text).not.toContain('Add Product Line');
    });

    test('order show page shows mutating buttons for a manage_orders user', async () => {
      const orderId = await createTestOrder(await createTestClient());
      const agent = await loginAs('Export Executive');

      const res = await agent.get(`/orders/${orderId}`);

      expect(res.status).toBe(200);
      expect(res.text).toContain('Edit Order Details');
      expect(res.text).toContain('Add Product Line');
    });

    test('client show page hides Edit and New Order buttons for a view-only user', async () => {
      const clientId = await createTestClient();
      const agent = await loginAs('Viewer / Auditor');

      const res = await agent.get(`/clients/${clientId}`);

      expect(res.status).toBe(200);
      expect(res.text).not.toContain('>Edit<');
      expect(res.text).not.toContain('New Order for this client');
    });

    test('client show page shows Edit and New Order buttons for a manage_orders user', async () => {
      const clientId = await createTestClient();
      const agent = await loginAs('Export Executive');

      const res = await agent.get(`/clients/${clientId}`);

      expect(res.status).toBe(200);
      expect(res.text).toContain('>Edit<');
      expect(res.text).toContain('New Order for this client');
    });

    test('client index hides New Client and per-card Edit links for a view-only user', async () => {
      await createTestClient();
      const agent = await loginAs('Viewer / Auditor');

      const res = await agent.get('/clients');

      expect(res.status).toBe(200);
      expect(res.text).not.toContain('+ New Client');
      expect(res.text).not.toContain('entity-card-action');
    });

    test('client index shows New Client and per-card Edit links for a manage_orders user', async () => {
      await createTestClient();
      const agent = await loginAs('Export Executive');

      const res = await agent.get('/clients');

      expect(res.status).toBe(200);
      expect(res.text).toContain('+ New Client');
      expect(res.text).toContain('entity-card-action');
    });
  });
});
