'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');

/**
 * External QA report R-01/R-03/R-04/R-05: no form anywhere validated a
 * field against what the database (or Express's own body-size limit)
 * would actually accept — text longer than its column (R-01), an
 * impossible date like 30-Feb (R-03), an id referencing a row that
 * doesn't exist (R-04), or simply a long form body (R-05) all crashed
 * the request as an uncaught error: a raw HTTP 500, no flash message,
 * every field the user had typed lost. server.js's own error-handling
 * middleware (the single chokepoint every affected form's request
 * already passes through, since asyncHandler funnels every rejected
 * controller promise to next(err)) now catches each of these and
 * redirects back with a friendly message instead. Exercised here against
 * real routes+columns through the real Express app, not a stubbed one.
 */
describe('Over-long/invalid form fields no longer crash the request (External QA report R-01/R-03/R-04/R-05)', () => {
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

  it('redirects back with a friendly message instead of crashing on an impossible date (R-03)', async () => {
    const agent = await loggedInAgent();
    const holidaysPage = await agent.get('/holidays');
    const csrf = extractCsrf(holidaysPage.text);

    const res = await agent.post('/holidays').type('form').send({
      _csrf: csrf,
      holiday_date: '2026-02-30', // 30-Feb does not exist
      description: 'R-03 test holiday',
    });

    expect(res.status).not.toBe(500);
    expect(res.status).toBe(302);
    const redirected = await agent.get(res.headers.location);
    // No apostrophe in this slice — Nunjucks auto-escapes "isn't" to "isn&#39;t" in the rendered HTML.
    expect(redirected.text.toLowerCase()).toContain('dates or numbers you entered');
  });

  it('redirects back with a friendly message instead of crashing on a foreign key violation (R-04)', async () => {
    const agent = await loggedInAgent();
    const documentType = await db.queryOne('SELECT id FROM document_types LIMIT 1');
    const signatoriesPage = await agent.get('/signatories');
    const csrf = extractCsrf(signatoriesPage.text);

    const res = await agent
      .post(`/signatories/document-types/${documentType.id}`)
      .type('form')
      .send({ _csrf: csrf, user_id: '999999999' }); // no such user

    expect(res.status).not.toBe(500);
    expect(res.status).toBe(302);
    const redirected = await agent.get(res.headers.location);
    expect(redirected.text.toLowerCase()).toContain('no longer exists');
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

  it('accepts a form body over the old 100kb limit without crashing (R-05)', async () => {
    const agent = await loggedInAgent();
    const formPage = await agent.get('/clients/create');
    const csrf = extractCsrf(formPage.text);

    // Padding across a handful of unused fields (well under Express's own
    // default 1000-parameter limit) rather than one long value — isolates
    // the body-size limit from any single column's own length check
    // (R-01), which the fuzzer found crashing this exact way too.
    const padding = {};
    for (let i = 0; i < 5; i++) {
      padding[`junk_field_${i}`] = 'x'.repeat(32000); // 160KB total, over the old 100kb default
    }

    const res = await agent.post('/clients').type('form').send({
      _csrf: csrf,
      company_legal_name: 'R-05 Body Size Test Ltd',
      billing_address: '1 Test Street',
      ...padding,
    });

    expect(res.status).not.toBe(500);
    expect(res.status).toBe(302);
  });

  it('still returns a friendly 413 instead of crashing for a body over the new limit', async () => {
    const agent = await loggedInAgent();
    const formPage = await agent.get('/clients/create');
    const csrf = extractCsrf(formPage.text);

    const res = await agent.post('/clients').type('form').send({
      _csrf: csrf,
      company_legal_name: 'R-05 Oversized Test Ltd',
      billing_address: 'x'.repeat(3 * 1024 * 1024), // 3MB, over the new 2mb limit
    });

    expect(res.status).toBe(413);
  });
});
