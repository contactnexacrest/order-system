'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const divisionService = require('../../src/services/divisionService');
const superAdminService = require('../../src/services/superAdminService');
const clientPortalService = require('../../src/services/clientPortalService');
const clientAuth = require('../../src/middleware/clientAuth');
const clientLoginRepository = require('../../src/repositories/clientLoginRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const caExpenseRepository = require('../../src/repositories/caExpenseRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const permittedLinks = require('../../src/helpers/permittedLinks');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA fix batch 1 (5 Nov 2026): the access-control (ACC-*) and UI (UI-*)
 * findings of the full QA review that no test covered. Port of
 * QaBatch1AccessUiTest.php, plus ACC-1 (Node only: percent-encoded ids).
 */
describe('QA batch 1 — access and UI fixes', () => {
  const DOM = 2;
  const ROOT = path.join(__dirname, '../../../..');
  const PHP_VIEWS = path.join(ROOT, 'PHP_MySQL_Version/app/views');
  const NODE_VIEWS = path.join(ROOT, 'NODE_MySQL_Version/app/views');

  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.execute('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
  });

  async function createUser(roleName, divisionId = 1, superAdmin = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-qa1-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestQaBatch123!';
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id, is_super_admin)
       VALUES ('Jest QA User', :email, NULL, :hash, :role_id, 1, 0, 0, :d, :sa)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id, d: divisionId, sa: superAdmin ? 1 : 0 }
    );
    return { id: result.insertId, email, password };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  async function csrfOf(agent, page = '/') {
    return (await agent.get(page)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  function asScope(ids, fn) {
    return requestContext.run({ userId: 1, divisionIds: ids, divisionSeesAll: false }, fn);
  }

  function walk(dir, ext, out = []) {
    for (const f of fs.readdirSync(dir)) {
      const p = path.join(dir, f);
      if (fs.statSync(p).isDirectory()) walk(p, ext, out);
      else if (p.endsWith(ext)) out.push(p);
    }
    return out;
  }

  function fakeReq(method, reqPath, session = {}) {
    const s = Object.assign({ regenerate(cb) { cb(null); } }, session);
    return { method, path: reqPath, session: s, body: {}, params: {}, ip: 'test', get: () => '' };
  }

  function fakeRes() {
    return { redirectedTo: null, statusCode: 200, redirect(to) { this.redirectedTo = to; }, status(c) { this.statusCode = c; return this; }, send() { return this; } };
  }

  it('ACC-1: a percent-encoded order id is a 404, not a way round the access checks', async () => {
    const admin = await createUser('Admin', null, true);
    const agent = await loginAs(admin);
    const order = await createTestOrder(await createTestClient());
    const encoded = `/orders/${String(order).split('').map((c) => `%3${c}`).join('')}/edit`;
    expect((await agent.get(encoded)).status).toBe(404);
    expect((await agent.get(`/orders/${order}/edit`)).status).toBe(200);
  });

  it('ACC-2: a preview cannot save anything; "Exit preview" still works', async () => {
    const admin = await createUser('Admin', null);
    const agent = await loginAs(admin);
    const designation = await db.queryOne("SELECT id FROM department_designations WHERE title = 'Logistics Executive'");
    const previewCsrf = await csrfOf(agent, '/admin/departments');
    await agent.post('/admin/preview-as').type('form').send({ _csrf: previewCsrf, designation_id: String(designation.id) });
    const before = Number((await db.queryOne('SELECT COUNT(*) AS n FROM ports')).n);
    const portCsrf = await csrfOf(agent, '/');
    const res = await agent.post('/ports').type('form').send({ _csrf: portCsrf, name: 'QA Preview Port', city: 'X', country: 'India', port_role: 'loading' });
    expect(res.status).toBe(302);
    expect((await agent.get('/')).text).toContain('You are previewing the app: nothing can be saved during a preview.');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM ports')).n)).toBe(before);
    const exitCsrf = await csrfOf(agent, '/');
    const exit = await agent.post('/preview/exit').type('form').send({ _csrf: exitCsrf });
    expect(exit.headers.location).toBe('/admin/departments');
  });

  it('ACC-3: only a permanent Super Admin changes Super Admin access, never their own', async () => {
    const owner = await createUser('Admin', null, true);
    const delegate = await createUser('Admin', null);
    const other = await createUser('Admin', null);
    await superAdminService.grantDelegation(delegate.id, owner.id, 'Cover while the owner travels', null);
    await expect(superAdminService.setPermanentFlag(delegate.id, true, delegate.id, 'Make myself permanent'))
      .rejects.toThrow('Only a permanent Super Admin can change Super Admin access (a delegated Super Admin cannot).');
    await expect(superAdminService.grantDelegation(other.id, delegate.id, 'Hand out another one', null))
      .rejects.toThrow('Only a permanent Super Admin can change Super Admin access (a delegated Super Admin cannot).');
    await expect(superAdminService.setPermanentFlag(owner.id, false, owner.id, 'Remove my own access'))
      .rejects.toThrow('You cannot change your own Super Admin access — ask another Super Admin.');
    await db.execute('UPDATE users SET is_active = 0 WHERE id = :id', { id: other.id });
    await expect(superAdminService.setPermanentFlag(other.id, true, owner.id, 'Promote an inactive user')).rejects.toThrow('Choose an active user.');
  });

  it('ACC-5 / ACC-6: the CA lists, a typed order reference and the intake queue follow the division', async () => {
    const order = await createTestOrder(await createTestClient());
    const ref = (await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id: order })).order_reference;
    const exp = await db.execute("INSERT INTO ca_expenses (zoho_expense_id, category, description, amount, currency_code, expense_date, order_id) VALUES (:z, 'Other', 'QA batch 1 expense', 100, 'INR', CURDATE(), :o)",
      { z: `TEST-QA1-${Math.random().toString(16).slice(2, 12)}`, o: order });
    const token = Math.random().toString(16).slice(2, 34);
    const intake = await db.execute("INSERT INTO pi_intake_submissions (order_id, access_token_hash, access_token_plain, access_token_expires_at, status, submitted_at) VALUES (:o, SHA2(:t, 256), :t, DATE_ADD(NOW(), INTERVAL 1 DAY), 'pending_review', NOW())", { o: order, t: token });

    expect(Number(await orderRepository.findIdByReference(ref))).toBe(order);
    expect((await caExpenseRepository.all()).map((r) => Number(r.id))).toContain(exp.insertId);
    expect((await piIntakeRepository.pendingReview()).map((r) => Number(r.id))).toContain(intake.insertId);
    await asScope([DOM], async () => {
      expect(await orderRepository.findIdByReference(ref)).toBeNull();
      expect((await caExpenseRepository.all()).map((r) => Number(r.id))).not.toContain(exp.insertId);
      expect((await piIntakeRepository.pendingReview()).map((r) => Number(r.id))).not.toContain(intake.insertId);
    });
  });

  it('ACC-8: no self-move, and only into your own division without "See all divisions"', async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: DOM });
    const hr = await createUser('Export Executive', 1);
    const staff = await createUser('Export Executive', 1);
    await db.execute("INSERT INTO user_permissions (user_id, permission_id, is_enabled) SELECT :u, id, 1 FROM permissions WHERE permission_key = 'manage_users'", { u: hr.id });
    const agent = await loginAs(hr);
    const move = async (target, division) => {
      const moveCsrf = await csrfOf(agent, '/');
      await agent.post(`/users/${target}/division`).type('form').send({ _csrf: moveCsrf, division_id: division });
      return (await agent.get('/')).text;
    };
    expect(await move(hr.id, String(DOM))).toContain('You cannot change your own division — ask another administrator.');
    expect(await move(staff.id, String(DOM))).toContain('You can only place people in your own division.');
    expect(Number((await db.queryOne('SELECT division_id FROM users WHERE id = :id', { id: staff.id })).division_id)).toBe(1);
  });

  it('ACC-9: logging in as a client is for looking only, and ends when the staff member is deactivated', async () => {
    const client = await createTestClient();
    const staff = await createUser('Admin', null);
    const session = { _client_portal_client_id: client, _client_portal_impersonated_by_staff_id: staff.id, _auth_user_id: staff.id };
    const post = fakeReq('POST', '/client/orders/1/acknowledge-oc', session);
    const res = fakeRes();
    let next = false;
    await clientAuth.required()(post, res, () => { next = true; });
    expect(next).toBe(false);
    expect(res.redirectedTo).toBe('/client');

    const get = fakeReq('GET', '/client', session);
    next = false;
    await clientAuth.required()(get, fakeRes(), () => { next = true; });
    expect(next).toBe(true);

    await db.execute('UPDATE users SET is_active = 0 WHERE id = :id', { id: staff.id });
    const after = fakeReq('GET', '/client', session);
    next = false;
    await clientAuth.required()(after, fakeRes(), () => { next = true; });
    expect(next).toBe(false);
  });

  it('ACC-10: a client session ends when the password is changed or reset elsewhere', async () => {
    const client = await createTestClient();
    const hash = await bcrypt.hash('ClientPass123!', 10);
    await db.execute('INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) VALUES (:c, :h, 1, 0)', { c: client, h: hash });
    const session = { _client_portal_client_id: client, _client_portal_password_fp: clientPortalService.passwordFingerprint(hash) };
    let next = false;
    await clientAuth.required()(fakeReq('GET', '/client', session), fakeRes(), () => { next = true; });
    expect(next).toBe(true);
    await clientLoginRepository.updatePassword(client, await bcrypt.hash('ResetByStaff456!', 10), true);
    next = false;
    await clientAuth.required()(fakeReq('GET', '/client', session), fakeRes(), () => { next = true; });
    expect(next).toBe(false);
  });

  it('ACC-11: a module switched off in the order\'s division refuses that order\'s address', async () => {
    expect(await divisionService.disabledModuleForPath('/orders/5/shipping-bill')).toBeNull();
    await asScope([DOM], async () => {
      expect(await divisionService.disabledModuleForPath('/orders/5/shipping-bill')).toBe('Export shipping (BL, shipping bill, CHA, COO)');
      expect(await divisionService.disabledModuleForPath('/orders/5/ecgc-declaration')).toBe('ECGC');
    });
  });

  it('ACC-12: staff placed in a division that is not active cannot save anything', async () => {
    const staff = await createUser('Export Executive', DOM);
    const agent = await loginAs(staff);
    const clientCsrf = await csrfOf(agent, '/');
    const res = await agent.post('/clients').type('form').send({ _csrf: clientCsrf });
    expect(res.status).toBe(403);
    expect(res.text).toContain('is not active yet: nothing can be saved until the MD activates it');
  });

  it('ACC-15: a plain link to /associate/logout does not sign anyone out', async () => {
    const res = await request(app).get('/associate/logout');
    expect(res.status).toBe(302);
    expect(res.headers.location).toBe('/associate/login');
    const controller = require('../../src/controllers/associatePortalController');
    const req = fakeReq('GET', '/associate/logout', { _associate_portal_id: 123 });
    const r = fakeRes();
    await controller.logout(req, r);
    expect(req.session._associate_portal_id).toBe(123);
    expect(r.redirectedTo).toBe('/associate');
  });

  it('ACC-18: the full data dump needs CAFIN and all-division rights too', async () => {
    // Owner decision A-8 already keeps data_export_run from System Administrator; this user is granted it alone.
    const sysAdmin = await createUser('System Administrator', null);
    await db.execute("INSERT INTO user_permissions (user_id, permission_id, is_enabled) SELECT :u, id, 1 FROM permissions WHERE permission_key = 'data_export_run'", { u: sysAdmin.id });
    const agent = await loginAs(sysAdmin);
    const res = await agent.get('/admin/data-export/data');
    expect(res.status).toBe(403);
    expect(res.text).toContain('needs "View & download CAFIN" and "See all divisions" as well');
  });

  it('UI-1 / UI-9 / UI-4 / UI-11: app.js reason rule, report division and view-switch helpers (both stacks)', () => {
    for (const js of [path.join(ROOT, 'PHP_MySQL_Version/public_html/assets/js/app.js'), path.join(ROOT, 'NODE_MySQL_Version/app/public/js/app.js')]) {
      const text = fs.readFileSync(js, 'utf8');
      expect(text).toContain("(el.tagName === 'TEXTAREA' || type === 'text') && /reason/i.test(name)");
      expect(text).toContain('var reasonMin = el.minLength > 0 ? el.minLength : REASON_MIN_LENGTH;');
      expect(text).toContain('initKeepReportDivision();');
      expect(text).toContain('initSwitchViewKeepsFilters();');
    }
  });

  it('UI-2: no confirm() handler puts free text into a JS string', () => {
    const bad = [];
    for (const file of walk(NODE_VIEWS, '.njk')) {
      fs.readFileSync(file, 'utf8').split('\n').forEach((line, n) => {
        const m = /on(?:submit|click)="return confirm\('(.*)'\);"/.exec(line);
        if (!m) return;
        for (const e of m[1].matchAll(/\{\{(.*?)\}\}/g)) {
          const expr = e[1].trim();
          const safe = expr.includes('| dump') || /^'[^']*' if .* else '[^']*'$/.test(expr) || ['c.code', 'fy', 'candidateCount'].includes(expr);
          if (!safe) bad.push(`${path.basename(file)}:${n + 1} ${expr}`);
        }
      });
    }
    expect(bad).toEqual([]);
  });

  it('UI-3: every pattern compiles under the "v" flag current browsers use (both stacks)', () => {
    const bad = [];
    for (const file of [...walk(PHP_VIEWS, '.php'), ...walk(NODE_VIEWS, '.njk')]) {
      for (const m of fs.readFileSync(file, 'utf8').matchAll(/\bpattern="([^"]*)"/g)) {
        if (/<\?|\{\{|\{%/.test(m[1])) continue;
        const p = m[1].replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
        try { new RegExp(`^(?:${p})$`, 'v'); } catch (e) { bad.push(`${path.basename(file)}: ${m[1]}`); }
      }
    }
    expect(bad).toEqual([]);
  });

  it('UI-15: an oversized upload gets the friendly page with its limit and a way back', async () => {
    const agent = await loginAs(await createUser('Admin', null, true));
    const res = await agent.post('/company-assets/replace').set('Referer', 'http://127.0.0.1/company-assets').attach('file', Buffer.alloc(6 * 1024 * 1024, 'a'), 'huge-logo.png');
    expect(res.status).toBe(413);
    expect(res.text).toContain('File too large');
    expect(res.text).toContain('(maximum 5 MB)');
    expect(res.text).toContain('&larr; Back');
  }, 20000);

  it('UI-10 / UI-12: a decision is chosen, and the rate lists have menu entries and link rules', () => {
    for (const [view, open] of [['orders/show.njk', '<select name="decision" required>'], ['amendments/index.njk', '<select name="requested_by" required>']]) {
      expect(fs.readFileSync(path.join(NODE_VIEWS, view), 'utf8')).toMatch(new RegExp(`${open.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*<option value="">Choose…</option>`));
    }
    expect(fs.readFileSync(path.join(NODE_VIEWS, 'layout/base.njk'), 'utf8')).toContain('href="/admin/gst-rates"');
    expect(permittedLinks.requiredFor('/admin/gst-rates')).toEqual(['manage_divisions']);
    expect(permittedLinks.requiredFor('/import')).toEqual(['view_purchase_prices']);
  });
});
