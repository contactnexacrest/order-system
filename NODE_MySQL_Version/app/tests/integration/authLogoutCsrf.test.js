'use strict';

const fs = require('fs');
const path = require('path');
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
 * QA-5 AUTH-15: /logout and /client/logout were bare GET routes with no
 * CSRF check (csrfCheck.verify() only inspects req.method === 'POST', so a
 * GET route is never covered by it regardless of middleware). A plain
 * <img src="https://…/logout"> embedded on any page a logged-in victim's
 * browser loaded would silently end their session — logout CSRF — usable
 * by a disgruntled coworker or client to repeatedly force someone out
 * mid-approval as harassment, or to mask timing for a follow-on attack.
 * Both routes are now POST + verifyCsrf, like every other state-changing
 * action.
 */
describe('Logout requires POST + CSRF (QA-5 AUTH-15)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('server.js no longer registers /logout as a GET route', () => {
    const contents = fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8');
    expect(contents).not.toMatch(/app\.get\('\/logout'/);
  });

  it('server.js no longer registers /client/logout as a GET route', () => {
    const contents = fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8');
    expect(contents).not.toMatch(/app\.get\('\/client\/logout'/);
  });

  it('GET /logout no longer works (404, not a session-ending action)', async () => {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrfToken = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrfToken, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    const getLogout = await agent.get('/logout');
    expect(getLogout.status).toBe(404);

    // Session must still be active — a forged GET (e.g. an <img> tag on an
    // attacker page) must not have logged the user out.
    const dashboard = await agent.get('/');
    expect(dashboard.status).toBe(200);
  });

  it('POST /logout without a valid CSRF token is rejected and the session stays active', async () => {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrfToken = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrfToken, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    const forgedLogout = await agent.post('/logout').type('form').send({});
    expect(forgedLogout.status).toBe(419);

    const dashboard = await agent.get('/');
    expect(dashboard.status).toBe(200);
  });

  it('POST /logout with a valid CSRF token actually ends the session', async () => {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrfToken = extractCsrf(loginPage.text);
    await agent.post('/login').type('form').send({ _csrf: csrfToken, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    // The dashboard's own sidebar now renders the logout form with its
    // hidden CSRF field (the same token every authenticated page carries),
    // so pull it straight from there rather than the login page.
    const dashboard = await agent.get('/');
    const logoutCsrf = extractCsrf(dashboard.text);

    const logoutRes = await agent.post('/logout').type('form').send({ _csrf: logoutCsrf });
    expect(logoutRes.status).toBe(302);
    expect(logoutRes.headers.location).toBe('/login');

    const dashboardAfter = await agent.get('/');
    expect(dashboardAfter.status).toBe(302);
    expect(dashboardAfter.headers.location).toBe('/login');
  });
});
