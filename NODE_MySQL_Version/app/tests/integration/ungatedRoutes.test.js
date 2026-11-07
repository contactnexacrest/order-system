'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const superAdminService = require('../../src/services/superAdminService');
const { createTestClient, createTestClientLogin } = require('../support/fixtures');

/** Every route without a permission check: method, path ({} = a parameter), guard, +csrf. */
const EXPECTED = [
  // Owner decision 1 Nov 2026: the associate portal (its own sign-in, requireAssociateAuth) and the buyer's invitation link / e-mail code.
  'GET /associate associate',
  'GET /associate/agreement associate',
  'GET /associate/clients associate',
  'GET /associate/clients/{}/certificate associate',
  'GET /associate/commission associate',
  'GET /associate/documents/{} associate',
  'GET /associate/login public',
  'GET /associate/login/code public',
  'GET /associate/logout public',
  'GET /associate/messages associate',
  'GET /associate/orders associate',
  'GET /associate/orders/{} associate',
  'GET /associate/prices associate',
  'GET /associate/catalogue associate',
  'GET /associate/catalogue/{} associate',
  'GET /associate/price-requests/{} associate',
  'GET /pricing/line-quote staff',
  'GET /associate/profile associate',
  'GET /associate/set-password/{} public',
  'GET /invite/{} public',
  'GET /quotation-details/verify public',
  'POST /associate/bank associate +csrf',
  'POST /associate/code associate +csrf',
  // Owner decisions 4 Nov 2026: the associate's code change (D-254) and the public associate registration form (D-253).
  'POST /associate/code/propose associate +csrf',
  'GET /associate-register/{} public',
  'POST /associate-register/{} public +csrf',
  'POST /associate/commission/{}/invoice associate +csrf',
  'POST /associate/documents associate +csrf',
  'POST /associate/invitations associate +csrf',
  'POST /associate/invitations/{}/cancel associate +csrf',
  'POST /associate/login public +csrf',
  'POST /associate/login/code public +csrf',
  'POST /associate/logout public +csrf',
  'POST /associate/messages associate +csrf',
  // Owner decision D-255 (5 Nov 2026): the catalogue and price requests replace the price-range acknowledgement.
  'POST /associate/catalogue/{}/price associate +csrf',
  'POST /associate/profile associate +csrf',
  'POST /associate/set-password/{} public +csrf',
  'POST /quotation-details/verify public +csrf',
  'GET / staff',
  'GET /2fa public',
  'GET /account staff',
  'GET /buyer-action/{} public',
  'GET /buyer-action/{}/buyer-po-document public',
  'GET /buyer-action/{}/order-confirmation public',
  'GET /buyer-action/{}/proforma-invoice public',
  'GET /client client',
  'GET /client/account client',
  'GET /client/documents/{}/download client',
  'GET /client/forgot-password public',
  'GET /client/login public',
  'GET /client/login/code public',
  'GET /client/orders/{} client',
  'GET /client/orders/{}/comment-attachments/{}/download client',
  'GET /client/orders/{}/payment-reports/{}/screenshot client',
  'GET /client/orders/{}/reorder client',
  'GET /choose-department staff',
  'GET /client/set-password/{} public',
  'GET /company-assets/preview staff',
  'GET /force-password-change staff',
  'GET /forgot-password public',
  'GET /gallery staff',
  'GET /gallery/file staff',
  'GET /getting-started staff',
  // Owner request 2 Nov 2026: the illustrated supplier / logistics payment guide, any staff member.
  'GET /help/supplier-logistics-payments staff',
  'GET /login public',
  'GET /notifications staff',
  'GET /payment-action/{} public',
  'GET /payment-action/{}/document public',
  'GET /pi-details/{} public',
  'GET /quotation-details public',
  'GET /quotation-details/edit/{} public',
  'GET /reference-docs staff',
  'GET /reference-docs/custom/{} staff',
  'GET /reference-docs/custom/{}/download staff',
  'GET /reference-docs/{} staff',
  'GET /reset-password/{} public',
  'GET /reviews staff',
  'GET /sop staff',
  'GET /sop-assets/{} staff',
  'GET /sop/{} staff',
  'GET /super-admin super-admin',
  // Owner decision 1 Nov 2026 (D-221): Admin → Self-approval rules, Super Admins only.
  'GET /admin/self-approval super-admin',
  'POST /admin/self-approval super-admin +csrf',
  'GET /supplier-action/{} public',
  'GET /supplier-action/{}/po-document public',
  'GET /test-mode super-admin',
  'GET /vendor-details/{} public',
  'POST /2fa public +csrf',
  'POST /2fa/backup public +csrf',
  'POST /2fa/resend public +csrf',
  'POST /account/backup-codes staff +csrf',
  'POST /account/notifications staff +csrf',
  'POST /account/password staff +csrf',
  'POST /account/signature staff +csrf',
  'POST /account/ui-template staff +csrf',
  'POST /buyer-action/{}/acknowledge-oc public +csrf',
  'POST /buyer-action/{}/buyer-po public +csrf',
  'POST /buyer-action/{}/report-payment public +csrf',
  'POST /choose-department staff +csrf',
  'POST /client/account/password client +csrf',
  'POST /client/end-impersonation public +csrf',
  'POST /client/forgot-password public +csrf',
  'POST /client/login public +csrf',
  'POST /client/login/code public +csrf',
  'POST /client/login/code/resend public +csrf',
  'POST /client/logout public +csrf',
  'POST /client/orders/{}/acknowledge-oc client +csrf',
  'POST /client/orders/{}/buyer-po client +csrf',
  'POST /client/orders/{}/comments client +csrf',
  'POST /client/orders/{}/complaints client +csrf',
  'POST /client/orders/{}/complaints/{}/replies client +csrf',
  'POST /client/orders/{}/disputes client +csrf',
  'POST /client/orders/{}/feedback client +csrf',
  'POST /client/orders/{}/reorder client +csrf',
  'POST /client/orders/{}/report-payment client +csrf',
  'POST /client/set-password/{} public +csrf',
  'POST /email-log/{}/cancel staff +csrf',
  'POST /force-password-change staff +csrf',
  'POST /forgot-password public +csrf',
  'POST /login public +csrf',
  'POST /logout public +csrf',
  'POST /payment-action/{}/report public +csrf',
  'POST /pi-details/{} public +csrf',
  'POST /preview/exit staff +csrf',
  'POST /quotation-details/edit/{} public +csrf',
  'POST /quotation-details/submit public +csrf',
  'POST /reset-password/{} public +csrf',
  'POST /reviews/{}/approve staff +csrf',
  'POST /reviews/{}/reject staff +csrf',
  'POST /super-admin/delegations super-admin +csrf',
  'POST /super-admin/delegations/{}/revoke super-admin +csrf',
  'POST /super-admin/set-permanent super-admin +csrf',
  'POST /supplier-action/{}/acknowledgment public +csrf',
  'POST /switch-department staff +csrf',
  'POST /test-mode/delete-test-data super-admin +csrf',
  'POST /test-mode/disable super-admin +csrf',
  'POST /test-mode/enable super-admin +csrf',
  'POST /test-mode/test-email super-admin +csrf',
  'POST /vendor-details/{} public +csrf',
];

const PASSWORD = 'UngatedRoutes123!';

/**
 * Every route in src/server.js without a requirePermission, with its
 * middleware names in the order they are registered.
 */
function routes() {
  const source = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'server.js'), 'utf8');
  return [...source.matchAll(/app\.(get|post)\('([^']+)',([^\n]*)/g)]
    .filter(([, , , rest]) => !rest.includes('requirePermission'))
    .map(([, method, pattern, rest]) => {
      const middleware = [...rest.matchAll(/\b(requireAuth|requireClientAuth|requireAssociateAuth|requireSuperAdmin|verifyCsrf|asyncHandler)\b/g)].map((m) => m[1]);
      let guard = 'public';
      if (middleware.includes('requireSuperAdmin')) guard = 'super-admin';
      else if (middleware.includes('requireClientAuth')) guard = 'client';
      else if (middleware.includes('requireAssociateAuth')) guard = 'associate';
      else if (middleware.includes('requireAuth')) guard = 'staff';
      return { method: method.toUpperCase(), pattern, guard, csrf: middleware.includes('verifyCsrf'), middleware };
    });
}

const concrete = (pattern) => pattern.replace(/:[A-Za-z]+/g, '999999');
const label = (route) => `${route.method} ${route.pattern}`;

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/** The flash messages a page shows (the dev-only 2FA code box is not one). */
function flashes(html) {
  return [...html.matchAll(/<div class="alert alert-(error|success|warning|info)">([^<]*)<\/div>/g)].map((m) => m[2].trim());
}

function send(agent, method, url, body = {}) {
  return method === 'GET' ? agent.get(url) : agent.post(url).type('form').send(body);
}

/**
 * Port of UngatedRoutesTest.php. QA Round 5, R5-14 (second half):
 * routeMatrix.test.js covers every route that needs a permission. The 92
 * routes above need none — they are guarded by sign-in (staff or client),
 * by Super Admin, by a CSRF token, or are public token links — and the QA
 * "Missing Test Cases" sheet listed 30 of them as tested on one stack
 * only, or not at all. This drives every one of them through the real app:
 *  - the list itself is pinned, so a new route without a permission check
 *    fails here until it is added (and tested);
 *  - signed out, every staff route goes to /login and every client route
 *    to /client/login; a staff sign-in does not open the client portal and
 *    a client sign-in does not open staff pages;
 *  - every Super Admin route refuses an Admin (403);
 *  - every POST is refused without a CSRF token, or with a wrong one;
 *  - with the guards passed, no route gives a server error for an unknown
 *    id or token, and every page without a :param opens;
 *  - the sign-in, password and Super Admin flows do what they say.
 */
describe('Routes without a permission check (QA Round 5, R5-14)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName, { superAdmin = false, twoFa = false } = {}) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-ungated-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, :two_fa, :super)`,
      { email, hash: await bcrypt.hash(PASSWORD, 10), role_id: role.id, two_fa: twoFa ? 1 : 0, super: superAdmin ? 1 : 0 }
    );
    return { id: result.insertId, email };
  }

  async function staffAgent(user) {
    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/login')).text);
    const login = await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    expect(login.headers.location).toBe('/');
    agent.csrf = extractCsrf((await agent.get('/account')).text);
    return agent;
  }

  async function createClientWithLogin() {
    const email = `jest-ungated-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = await createTestClient(email);
    await createTestClientLogin(id, PASSWORD);
    return { id, email };
  }

  async function clientAgent(client) {
    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/client/login')).text);
    const login = await agent.post('/client/login').type('form').send({ _csrf: csrf, email: client.email, password: PASSWORD });
    expect(login.headers.location).toBe('/client');
    agent.csrf = extractCsrf((await agent.get('/client/account')).text);
    return agent;
  }

  /** An associate signed in to the associate portal (password, then the e-mailed code — shown on screen in development). */
  async function associateAgent() {
    const associateRepository = require('../../src/repositories/associateRepository');
    const associatePortalService = require('../../src/services/associatePortalService');
    const email = `jest-ungated-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = await associateRepository.create({ name: 'Jest Ungated Associate', based_in: 'india', currency_code: 'INR', email, status: 'active' }, null);
    await associateRepository.setFields(id, { portal_enabled: 1 });
    await associatePortalService.setPassword(await associatePortalService.issueToken(id, 'set_password', 60), PASSWORD);
    const agent = request.agent(app);
    const loginCsrf = extractCsrf((await agent.get('/associate/login')).text);
    await agent.post('/associate/login').type('form').send({ _csrf: loginCsrf, email, password: PASSWORD });
    const page = await agent.get('/associate/login/code');
    const code = page.text.match(/your login code is (\d{6})/)[1];
    const ok = await agent.post('/associate/login/code').type('form').send({ _csrf: extractCsrf(page.text), code });
    expect(ok.headers.location).toBe('/associate');
    agent.csrf = extractCsrf((await agent.get('/associate/profile')).text);
    return agent;
  }

  /** A signed-out agent that already holds a session CSRF token. */
  async function publicAgent() {
    const agent = request.agent(app);
    agent.csrf = extractCsrf((await agent.get('/login')).text);
    return agent;
  }

  let agents = null;
  /** One signed-in agent per guard, made once. */
  async function agentFor(guard) {
    if (agents === null) {
      agents = {
        staff: await staffAgent(await createUser('Admin')),
        'super-admin': await staffAgent(await createUser('Admin', { superAdmin: true })),
        client: await clientAgent(await createClientWithLogin()),
        associate: await associateAgent(),
      };
    }
    return guard === 'public' ? publicAgent() : agents[guard];
  }

  it('the routes without a permission check are exactly the ones tested here', () => {
    const actual = routes().map((r) => `${r.method} ${r.pattern.replace(/:[A-Za-z]+/g, '{}')} ${r.guard}${r.csrf ? ' +csrf' : ''}`).sort();
    // A route without a permission check was added, removed or re-guarded —
    // update EXPECTED here and in UngatedRoutesTest.php, and test it.
    expect(actual).toEqual([...EXPECTED].sort());
  });

  it('every POST is CSRF-checked, after its sign-in guard and before its handler', () => {
    const wrong = routes()
      .filter((r) => r.method === 'POST')
      .filter((r) => {
        const csrfAt = r.middleware.indexOf('verifyCsrf');
        const handlerAt = r.middleware.lastIndexOf('asyncHandler');
        const guardAt = Math.max(r.middleware.indexOf('requireAuth'), r.middleware.indexOf('requireClientAuth'), r.middleware.indexOf('requireAssociateAuth'), r.middleware.indexOf('requireSuperAdmin'));
        return csrfAt === -1 || csrfAt > handlerAt || csrfAt < guardAt;
      })
      .map(label);
    expect(wrong).toEqual([]);
  });

  it('signed out, every staff route goes to /login', async () => {
    const wrong = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.guard === 'staff' || r.guard === 'super-admin')) {
      const res = await send(request(app), route.method, concrete(route.pattern));
      if (res.status !== 302 || res.headers.location !== '/login') wrong.push(`${label(route)} → ${res.status} ${res.headers.location}`);
      checked++;
    }
    expect(checked).toBe(42); // D-265: + POST /account/backup-codes
    expect(wrong).toEqual([]);
  }, 60000);

  it('signed out, every client route goes to the client login', async () => {
    const wrong = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.guard === 'client')) {
      const res = await send(request(app), route.method, concrete(route.pattern));
      if (res.status !== 302 || res.headers.location !== '/client/login') wrong.push(`${label(route)} → ${res.status} ${res.headers.location}`);
      checked++;
    }
    expect(checked).toBe(17);
    expect(wrong).toEqual([]);
  }, 60000);

  it('a staff sign-in does not open the client portal and a client sign-in does not open staff pages', async () => {
    const staff = await agentFor('staff');
    const client = await agentFor('client');
    const wrong = [];
    for (const route of routes()) {
      if (route.guard === 'client') {
        const res = await send(staff, route.method, concrete(route.pattern), { _csrf: staff.csrf });
        if (res.headers.location !== '/client/login') wrong.push(`staff ${label(route)} → ${res.status} ${res.headers.location}`);
      } else if (route.guard === 'staff') {
        const res = await send(client, route.method, concrete(route.pattern), { _csrf: client.csrf });
        if (res.headers.location !== '/login') wrong.push(`client ${label(route)} → ${res.status} ${res.headers.location}`);
      } else if (route.guard === 'associate') {
        // Owner decision 1 Nov 2026 (AS-1): neither a staff nor a buyer sign-in opens the associate portal.
        for (const [who, agent] of [['staff', staff], ['client', client]]) {
          const res = await send(agent, route.method, concrete(route.pattern), { _csrf: agent.csrf });
          if (res.headers.location !== '/associate/login') wrong.push(`${who} ${label(route)} → ${res.status} ${res.headers.location}`);
        }
      }
    }
    expect(wrong).toEqual([]);
    // and neither sign-in was ended by it
    expect((await staff.get('/account')).status).toBe(200);
    expect((await client.get('/client/account')).status).toBe(200);
  }, 60000);

  /** Owner decision 1 Nov 2026 (AS-1): signed out — or signed in as staff or a buyer — every associate page goes to the associate login. */
  it('every associate route needs the associate\'s own sign-in', async () => {
    const staff = await agentFor('staff');
    const client = await agentFor('client');
    const wrong = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.guard === 'associate')) {
      for (const [who, agent] of [['out', request(app)], ['staff', staff], ['client', client]]) {
        const res = await send(agent, route.method, concrete(route.pattern), { _csrf: agent.csrf });
        if (res.status !== 302 || res.headers.location !== '/associate/login') wrong.push(`${label(route)} (${who}) → ${res.status} ${res.headers.location}`);
      }
      checked++;
    }
    expect(checked).toBe(24);
    expect(wrong).toEqual([]);
  }, 60000);

  it('every Super Admin route refuses an Admin', async () => {
    const admin = await agentFor('staff');
    const wrong = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.guard === 'super-admin')) {
      const res = await send(admin, route.method, concrete(route.pattern), { _csrf: admin.csrf });
      if (res.status !== 403) wrong.push(`${label(route)} → ${res.status}`);
      checked++;
    }
    expect(checked).toBe(11);
    expect(wrong).toEqual([]);
  }, 60000);

  it('every POST is refused without a CSRF token or with a wrong one, and nothing runs', async () => {
    const wrong = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.method === 'POST')) {
      // a signed-in agent shows the refusal kept the session and left no message behind
      const agent = await agentFor(['client', 'associate'].includes(route.guard) ? route.guard : 'super-admin');
      const home = { client: '/client/account', associate: '/associate/profile' }[route.guard] || '/account';
      const loginPath = route.pattern.startsWith('/client/') ? '/client/login' : (route.pattern.startsWith('/associate/') ? '/associate/login' : '/login');
      for (const body of [{}, { _csrf: 'not-the-session-token' }]) {
        const res = await agent.post(concrete(route.pattern)).type('form').send(body);
        const after = await agent.get(home);
        if (res.status !== 302 || res.headers.location !== loginPath || after.status !== 200 || flashes(after.text).length !== 0) {
          wrong.push(`${label(route)} ${body._csrf ? 'wrong token' : 'no token'} → ${res.status} ${res.headers.location}; after: ${after.status} ${flashes(after.text).join(' | ')}`);
        }
      }
      checked++;
    }
    expect(checked).toBe(69); // D-265: + 5 login-code POSTs
    expect(wrong).toEqual([]);
  }, 120000);

  it('no route gives a server error for an unknown id or token', async () => {
    const failed = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.pattern.includes(':'))) {
      const agent = await agentFor(route.guard);
      const res = await send(agent, route.method, concrete(route.pattern), { _csrf: agent.csrf });
      if (res.status >= 500) failed.push(`${label(route)} → ${res.status}`);
      checked++;
    }
    expect(checked).toBe(59);
    expect(failed).toEqual([]);
  }, 120000);

  it('every page without parameters opens', async () => {
    const failed = [];
    let checked = 0;
    for (const route of routes().filter((r) => r.method === 'GET' && !r.pattern.includes(':'))) {
      const agent = await agentFor(route.guard);
      const res = await agent.get(route.pattern);
      const redirectsOnPurpose = ['/2fa', '/client/login/code', '/gallery/file', '/company-assets/preview', '/choose-department', '/associate/login/code', '/associate/logout', '/associate/agreement' /* 404 until a signed agreement is on file */, '/associate/prices' /* D-255: to the catalogue */, '/pricing/line-quote' /* D-255: 404 JSON without a product */].includes(route.pattern);
      if (res.status >= 500 || (res.status !== 200 && !redirectsOnPurpose) || (res.status === 200 && (res.text || res.body || '').length === 0)) {
        failed.push(`${label(route)} → ${res.status}`);
      }
      checked++;
    }
    expect(checked).toBe(39); // D-265: + GET /client/login/code
    expect(failed).toEqual([]);
  }, 120000);

  it('login refuses a wrong password and signs in with the right one', async () => {
    const user = await createUser('Admin');
    const agent = await publicAgent();
    const wrong = await agent.post('/login').type('form').send({ _csrf: agent.csrf, email: user.email, password: 'not-the-password' });
    expect(wrong.headers.location).toBe('/login');
    expect(flashes((await agent.get('/login')).text)).toEqual(['Invalid email or password.']);
    expect((await agent.get('/account')).headers.location).toBe('/login');

    const csrf = extractCsrf((await agent.get('/login')).text);
    const right = await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    expect(right.headers.location).toBe('/');
    expect((await agent.get('/account')).status).toBe(200);
  });

  it('logout ends the staff session', async () => {
    const agent = await staffAgent(await createUser('Admin'));
    const res = await agent.post('/logout').type('form').send({ _csrf: agent.csrf });
    expect(res.status).toBe(302);
    expect((await agent.get('/account')).headers.location).toBe('/login');
  });

  it('the 2FA page needs a pending sign-in and refuses a wrong code', async () => {
    const signedOut = await request(app).get('/2fa');
    expect([signedOut.status, signedOut.headers.location]).toEqual([302, '/login']);

    const user = await createUser('Admin', { twoFa: true });
    const agent = await publicAgent();
    global.__D265_MANUAL_LOGIN_CODE__ = true; // see the raw code step (tests/support/loginCodeAuto.js)
    let login;
    try {
      login = await agent.post('/login').type('form').send({ _csrf: agent.csrf, email: user.email, password: PASSWORD });
    } finally {
      global.__D265_MANUAL_LOGIN_CODE__ = false;
    }
    expect(login.headers.location).toBe('/2fa');
    const page = await agent.get('/2fa');
    expect(page.status).toBe(200);
    const code = page.text.match(/code is: <strong>(\d{6})<\/strong>/)[1];
    const csrf = extractCsrf(page.text);

    await agent.post('/2fa').type('form').send({ _csrf: csrf, code: code === '000000' ? '111111' : '000000' });
    expect(flashes((await agent.get('/2fa')).text)).toEqual(['Invalid or expired verification code.']);
    expect((await agent.get('/account')).headers.location).toBe('/login');

    const ok = await agent.post('/2fa').type('form').send({ _csrf: csrf, code });
    expect(ok.headers.location).toBe('/');
    expect((await agent.get('/account')).status).toBe(200);
  });

  it('force-password-change refuses a mismatch and saves a match', async () => {
    const user = await createUser('Admin');
    const agent = await staffAgent(user);
    const hash = async () => (await db.queryOne('SELECT password_hash FROM users WHERE id = :id', { id: user.id })).password_hash;
    const before = await hash();

    await agent.post('/force-password-change').type('form').send({ _csrf: agent.csrf, new_password: 'BrandNewPass123!', confirm_password: 'BrandNewPass124!' });
    expect(flashes((await agent.get('/force-password-change')).text)).toEqual(['Passwords do not match.']);
    expect(await hash()).toBe(before);

    await agent.post('/force-password-change').type('form').send({ _csrf: agent.csrf, new_password: 'BrandNewPass123!', confirm_password: 'BrandNewPass123!' });
    expect(await bcrypt.compare('BrandNewPass123!', await hash())).toBe(true);
  });

  it('a password reset link works once and an unknown one is refused', async () => {
    const agent = await publicAgent();
    await agent.post('/reset-password/not-a-real-token').type('form').send({ _csrf: agent.csrf, new_password: 'ResetPass12345!', confirm_password: 'ResetPass12345!' });
    expect(flashes((await agent.get('/forgot-password')).text).join(' ')).toContain('invalid or has expired');

    const user = await createUser('Admin');
    const token = crypto.randomBytes(32).toString('hex');
    await db.execute(
      'INSERT INTO password_reset_tokens (user_id, token_hash, requested_ip, expires_at) VALUES (:id, :hash, NULL, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
      { id: user.id, hash: crypto.createHash('sha256').update(token).digest('hex') }
    );
    const hash = async () => (await db.queryOne('SELECT password_hash FROM users WHERE id = :id', { id: user.id })).password_hash;

    expect((await agent.get(`/reset-password/${token}`)).status).toBe(200);
    await agent.post(`/reset-password/${token}`).type('form').send({ _csrf: agent.csrf, new_password: 'ResetPass12345!', confirm_password: 'ResetPass12345?' });
    expect(flashes((await agent.get(`/reset-password/${token}`)).text)).toEqual(['Passwords do not match.']);

    await agent.post(`/reset-password/${token}`).type('form').send({ _csrf: agent.csrf, new_password: 'ResetPass12345!', confirm_password: 'ResetPass12345!' });
    expect(await bcrypt.compare('ResetPass12345!', await hash())).toBe(true);
    await agent.get('/login'); // shows (and clears) the success message

    await agent.post(`/reset-password/${token}`).type('form').send({ _csrf: agent.csrf, new_password: 'SecondTry12345!', confirm_password: 'SecondTry12345!' });
    expect(flashes((await agent.get('/forgot-password')).text).join(' ')).toContain('invalid or has expired'); // a used link must not work twice
    expect(await bcrypt.compare('ResetPass12345!', await hash())).toBe(true);
  });

  it('client login, logout and the set-password link', async () => {
    const client = await createClientWithLogin();
    const agent = await publicAgent();
    const csrf = extractCsrf((await agent.get('/client/login')).text);

    await agent.post('/client/login').type('form').send({ _csrf: csrf, email: client.email, password: 'not-the-password' });
    expect(flashes((await agent.get('/client/login')).text)).toEqual(['Incorrect email or password.']);
    expect((await agent.get('/client/account')).headers.location).toBe('/client/login');

    expect((await agent.post('/client/login').type('form').send({ _csrf: csrf, email: client.email, password: PASSWORD })).headers.location).toBe('/client');
    const portalCsrf = extractCsrf((await agent.get('/client/account')).text);
    await agent.post('/client/logout').type('form').send({ _csrf: portalCsrf });
    expect((await agent.get('/client/account')).headers.location).toBe('/client/login');

    await agent.get('/client/set-password/not-a-real-token');
    expect(flashes((await agent.get('/client/login')).text).join(' ')).toContain('invalid or has expired');

    const token = crypto.randomBytes(32).toString('hex');
    await db.execute(
      'INSERT INTO client_password_reset_tokens (client_id, token_hash, requested_ip, expires_at) VALUES (:id, :hash, NULL, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
      { id: client.id, hash: crypto.createHash('sha256').update(token).digest('hex') }
    );
    const fresh = await publicAgent();
    await fresh.post(`/client/set-password/${token}`).type('form').send({ _csrf: fresh.csrf, new_password: 'ClientNew12345!', confirm_password: 'ClientNew12345!' });
    const relogin = await fresh.post('/client/login').type('form').send({ _csrf: fresh.csrf, email: client.email, password: 'ClientNew12345!' });
    expect(relogin.headers.location).toBe('/client');
  });

  it('a client password change needs the current password', async () => {
    const client = await createClientWithLogin();
    const agent = await clientAgent(client);
    const hash = async () => (await db.queryOne('SELECT password_hash FROM client_logins WHERE client_id = :id', { id: client.id })).password_hash;

    await agent.post('/client/account/password').type('form').send({ _csrf: agent.csrf, current_password: 'wrong', new_password: 'ClientNew12345!', confirm_password: 'ClientNew12345!' });
    expect(flashes((await agent.get('/client/account')).text)).toEqual(['Current password is incorrect.']);
    expect(await bcrypt.compare(PASSWORD, await hash())).toBe(true);

    await agent.post('/client/account/password').type('form').send({ _csrf: agent.csrf, current_password: PASSWORD, new_password: 'ClientNew12345!', confirm_password: 'ClientNew12345!' });
    expect(await bcrypt.compare('ClientNew12345!', await hash())).toBe(true);
  });

  it('a Super Admin can grant and revoke a delegation', async () => {
    const delegate = await createUser('Admin');
    const superAdmin = await staffAgent(await createUser('Admin', { superAdmin: true }));
    await superAdmin.post('/super-admin/delegations').type('form').send({ _csrf: superAdmin.csrf, delegate_user_id: String(delegate.id), reason: 'Cover while the owner travels' });
    expect(await superAdminService.isEffective(delegate.id)).toBe(true);

    const delegateAgent = await staffAgent(delegate);
    expect((await delegateAgent.get('/super-admin')).status).toBe(200); // the delegate now opens the Super Admin screen

    const delegation = await db.queryOne('SELECT id FROM super_admin_delegations WHERE delegate_user_id = :id AND revoked_at IS NULL', { id: delegate.id });
    await superAdmin.post(`/super-admin/delegations/${delegation.id}/revoke`).type('form').send({ _csrf: superAdmin.csrf, reason: 'Owner is back now' });
    expect(await superAdminService.isEffective(delegate.id)).toBe(false);
    expect((await delegateAgent.get('/super-admin')).status).toBe(403);
  });

  it('SOP images cannot reach files outside the images folder', async () => {
    const agent = await agentFor('staff');
    for (const file of ['..%2F..%2F.env', '..%2F01-overview.md', '.env', 'no-such-image.png']) {
      const res = await agent.get(`/sop-assets/${file}`);
      expect([file, res.status, res.text.trim()]).toEqual([file, 404, 'Image not found.']);
    }
  });
});
