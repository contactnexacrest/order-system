'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');

/**
 * External QA report L-39: "Node: confirmation prompts break whenever a
 * name contains an apostrophe or a quote (e.g. client 'O'Brien Ltd') —
 * the click then runs the action without asking." {{ name }} dropped
 * directly into a JS string literal inside onsubmit="return confirm(...)"
 * relied on Nunjucks' own HTML-attribute autoescaping, but the browser
 * decodes that escaping (e.g. &#39; -> ') before handing the attribute
 * value to the JS engine as source text — so a name with an apostrophe
 * still breaks the JS string the instant it's decoded. Every such
 * confirm() across users/clients/ports/designations/permission-
 * definitions/reference-categories/compliance-task-types now reads its
 * message from a data-confirm attribute instead (see app.js's
 * initConfirmDialogs()), which is never re-parsed as JS source.
 */
describe('Confirm-dialog messages survive an apostrophe/quote in the name (External QA report L-39)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function extractCsrf(html) {
    const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
    if (!m) throw new Error('CSRF token not found in response HTML');
    return m[1];
  }

  async function loggedInAgent() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const PASSWORD = 'L39-Test-Password-1!';
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-l39-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest L-39 Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );

    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email, password: PASSWORD });
    expect(loginRes.status).toBe(302);
    return agent;
  }

  function dataConfirmNear(html, marker) {
    const idx = html.indexOf(marker);
    expect(idx).toBeGreaterThan(-1);
    const slice = html.slice(idx);
    const m = slice.match(/data-confirm="([^"]*)"/);
    expect(m).not.toBeNull();
    return m[1]
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&amp;/g, '&');
  }

  it("a user name containing an apostrophe survives intact in /users's data-confirm", async () => {
    const agent = await loggedInAgent();
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const name = "O'Brien & Sons \"Stone\" Ltd";
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES (:name, :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { name, email: `jest-l39-target-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );

    const page = await agent.get('/users');
    expect(page.status).toBe(200);
    expect(page.text).not.toContain('onsubmit="return confirm(');

    const message = dataConfirmNear(page.text, `/users/${result.insertId}/archive`);
    expect(message).toBe(`Archive ${name}? They will no longer be able to log in, and will move out of the Active/Inactive lists into Archived.`);
  });

  it("a compliance task type name containing a quote survives intact in its data-confirm", async () => {
    const agent = await loggedInAgent();
    const name = 'ECGC "Cover" Check';
    const result = await db.execute(
      "INSERT INTO compliance_task_types (name, is_active) VALUES (:name, 1)",
      { name }
    );

    const page = await agent.get('/compliance-task-types');
    expect(page.status).toBe(200);
    expect(page.text).not.toContain('onsubmit="return confirm(');

    const message = dataConfirmNear(page.text, `/compliance-task-types/${result.insertId}/toggle`);
    expect(message).toBe(`Deactivate "${name}"?`);
  });
});
