'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');

/**
 * External QA report U-11: "Sidebar missing 4 menu groups (Email
 * Approvals, CA / Accounting, Products, Insights) on one page" —
 * /reference-docs/categories. Root cause: referenceDocController's
 * categoriesIndex() destructured its local "full list of permission
 * definitions, for the required-permission dropdown" as `permissions`,
 * which collided with and silently overwrote res.renderView()'s own
 * `permissions` context key — the current user's permission-check
 * object the sidebar's `{% if permissions.xxx %}` guards actually read.
 * A controller-level unit test with a hand-built fake res.renderView
 * (as other tests in this suite use) would never catch this, since it
 * doesn't replicate server.js's real common-context Object.assign — this
 * goes through the real HTTP route instead.
 */
describe('reference-docs/categories sidebar context shadowing (External QA report U-11)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function extractCsrf(html) {
    const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
    if (!m) throw new Error('CSRF token not found in response HTML');
    return m[1];
  }

  async function loginAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-refcat-sidebar-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestRefCat123!';
    const passwordHash = await bcrypt.hash(password, 10);
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: passwordHash, role_id: role.id }
    );
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    return agent;
  }

  test('an Admin still sees the Email Approvals and CA/Accounting sidebar links on this page', async () => {
    const agent = await loginAs('Admin');

    const res = await agent.get('/reference-docs/categories');

    expect(res.status).toBe(200);
    expect(res.text).toContain('Email Approvals');
    expect(res.text).toContain('CA / Accounting');
  });

  test('the same Admin sees those same sidebar links on an unrelated page, for comparison', async () => {
    const agent = await loginAs('Admin');

    const res = await agent.get('/orders');

    expect(res.status).toBe(200);
    expect(res.text).toContain('Email Approvals');
    expect(res.text).toContain('CA / Accounting');
  });
});
