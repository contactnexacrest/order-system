'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');

/**
 * External QA report R-01: no form anywhere validated a text field
 * against its database column's actual max length — submitting text
 * longer than the column crashed the request as an uncaught mysql2 error
 * (SQLSTATE 22001, "string data, right truncated"/ER_DATA_TOO_LONG): a
 * raw HTTP 500, no flash message, every field the user had typed lost.
 * server.js's own error-handling middleware (the single chokepoint every
 * one of the ~60 affected forms' requests already pass through, since
 * asyncHandler funnels every rejected controller promise to next(err))
 * now catches this specifically and redirects back with a friendly
 * message instead. Exercised here against a real route+column
 * (POST /clients, clients.company_legal_name VARCHAR(255)) through the
 * real Express app, not a stubbed one.
 */
describe('Over-long form field no longer crashes the request (External QA report R-01)', () => {
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
    const PASSWORD = 'R01-Test-Password-1!';
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-r01-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest R-01 Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );

    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email, password: PASSWORD });
    expect(loginRes.status).toBe(302);
    return agent;
  }

  it('redirects back with a friendly flash message instead of crashing with a 500', async () => {
    const agent = await loggedInAgent();
    const formPage = await agent.get('/clients/create');
    const csrf = extractCsrf(formPage.text);

    const res = await agent.post('/clients').type('form').send({
      _csrf: csrf,
      company_legal_name: 'x'.repeat(500), // column is VARCHAR(255)
      billing_address: '1 Test Street',
    });

    expect(res.status).not.toBe(500);
    expect(res.status).toBe(302);

    const clientsPage = await agent.get(res.headers.location);
    expect(clientsPage.text.toLowerCase()).toContain('too long');
  });

  it('responds with a JSON error instead of a redirect for an AJAX request', async () => {
    const agent = await loggedInAgent();
    const formPage = await agent.get('/clients/create');
    const csrf = extractCsrf(formPage.text);

    const res = await agent
      .post('/clients')
      .type('form')
      .set('X-Requested-With', 'XMLHttpRequest')
      .send({ _csrf: csrf, company_legal_name: 'x'.repeat(500), billing_address: '1 Test Street' });

    expect(res.status).toBe(422);
    expect(res.body.error.toLowerCase()).toContain('too long');
  });
});
