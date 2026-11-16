'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const permittedLinks = require('../../src/helpers/permittedLinks');
const departmentRepository = require('../../src/repositories/departmentRepository');
const permissionRepository = require('../../src/repositories/permissionRepository');
const reportRepository = require('../../src/repositories/reportRepository');
const superAdminService = require('../../src/services/superAdminService');
const navigation = require('../../src/services/navigation');
const reportAccess = require('../../src/services/reportAccess');
const { indexData } = require('../../src/controllers/reportController');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Owner decision D-379 (15 Nov 2026): report access per role — spec docs/decisions/D-379-report-access-spec.md §6.
 *
 *  - the seeded default levels (spec §4) and the permission manage_report_access (MD, ED; not Admin);
 *  - Own / Department / All limit the rows of Aggregate, Sales performance, Ageing, Find an order and
 *    Disputes (the CSV of each is built from the same rows, and its URL is refused like the page);
 *  - Off answers 403 by direct URL (page and CSV); the base permission (money too) is still needed;
 *  - an extra only raises the level (a lower one is refused); the highest level over memberships counts;
 *  - Admin sees Management (and Find an order) only; the Investor cannot open the per-client report;
 *  - the Reports page shows only the allowed sections and the Own / Department banner;
 *  - the menu shows Sales targets to MD / ED and the Sales Director only; the team view has no money / Edit;
 *  - "See Reports as this role" draws the page as the role; Reset to defaults.
 *
 * PHP twin: PHP_MySQL_Version/app/tests/Integration/ReportAccessTest.php
 */
describe('D-379: report access per role', () => {
  const PASSWORD = 'JestReportAccess!1';
  let departments = [];
  let designations = [];
  let roles = [];

  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(async () => {
    for (const id of designations) {
      await db.execute('DELETE FROM user_department_memberships WHERE designation_id = :d', { d: id });
      await db.execute('DELETE FROM department_designations WHERE id = :d', { d: id });
    }
    for (const id of departments) {
      await db.execute('DELETE FROM order_department_owners WHERE department_id = :d', { d: id });
      await db.execute('DELETE FROM departments WHERE id = :d', { d: id });
    }
    for (const id of roles) {
      await db.execute('DELETE FROM role_report_access WHERE role_id = :r', { r: id });
      await db.execute('UPDATE users SET role_id = NULL WHERE role_id = :r', { r: id });
      await db.execute('DELETE FROM role_permissions WHERE role_id = :r', { r: id });
      await db.execute('DELETE FROM roles WHERE id = :r', { r: id });
    }
    departments = [];
    designations = [];
    roles = [];
    reportAccess.resetCache();
  });

  // ---------------------------------------------------------------- helpers

  async function roleId(name) {
    const r = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: name });
    return r ? Number(r.id) : 0;
  }

  async function userWithRole(rid) {
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :e, NULL, :hash, :r, 1, 0, 0)`,
      { e: `jest-ra-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash(PASSWORD, 4), r: rid }
    );
    return Number(result.insertId);
  }

  const createTestUser = async (roleName) => userWithRole(await roleId(roleName));

  /** Runs fn as a signed-in request of this person would (what sessionAuth puts in the request context). */
  async function asUser(userId, fn) {
    const user = await db.queryOne('SELECT id, role_id FROM users WHERE id = :id', { id: userId });
    const memberships = await departmentRepository.activeMembershipsForUser(userId);
    const def = memberships.filter((m) => Number(m.is_default) === 1);
    const acting = memberships.length === 1 || def.length > 0 ? (def[0] || memberships[0]) : null;
    const effectiveRoleId = acting ? Number(acting.role_id) : (user.role_id !== null ? Number(user.role_id) : null);
    const isSuperAdmin = await superAdminService.isEffective(userId);
    const permissions = await permissionRepository.effectivePermissions(userId, effectiveRoleId, false);
    if (isSuperAdmin) for (const key of await permissionRepository.allKeys()) permissions[key] = true;
    return requestContext.run({ userId: Number(userId), effectiveRoleId, previewing: false, actingAs: null, permissions, isSuperAdmin }, fn);
  }

  async function signIn(userId) {
    const u = await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId });
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  async function csrfOf(agent) {
    return (await agent.get('/')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  /** @returns {Promise<{code: number, body: string, flash: ?string, type: ?string}>} one POST and the flash it leaves */
  async function post(agent, url, body = {}) {
    // The token is read before the POST is built: a supertest request opens its own server when it is created, and
    // another request awaited in between (the token page) left that server refusing the connection.
    const csrf = await csrfOf(agent);
    const res = await agent.post(url).type('form').send({ ...body, _csrf: csrf });
    let flash = null;
    let type = null;
    if (res.status === 302 && res.headers.location) {
      const page = (await agent.get(res.headers.location.split('#')[0])).text;
      const m = page.match(/<div class="alert alert-(error|success|warning|info)">([^<]*)<\/div>/);
      if (m) {
        type = m[1];
        flash = m[2].trim();
      }
    }
    return { code: res.status, body: res.text, flash, type };
  }

  /** A fresh department of its own (so its members are only this test's people), with designations for these roles. */
  async function department(roleNames) {
    const code = `RA${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    const dept = Number(await departmentRepository.createDepartment(code, `Report Access ${code}`, '#2f6fb3'));
    departments.push(dept);
    const out = { id: dept };
    for (const name of roleNames) {
      out[name] = Number(await departmentRepository.createDesignation(dept, `${name} ${code}`, 'executive', await roleId(name)));
      designations.push(out[name]);
    }
    return out;
  }

  const member = (userId, designationId) => departmentRepository.addMembership(userId, designationId, new Date(Date.now() - 86400000).toISOString().slice(0, 10), userId);

  async function customRole() {
    const name = `Jest D-379 Role ${crypto.randomBytes(3).toString('hex')}`;
    const result = await db.execute("INSERT INTO roles (name, description, is_system_role) VALUES (:n, 'Test only', 0)", { n: name });
    const id = Number(result.insertId);
    roles.push(id);
    return id;
  }

  async function grant(rid, permissionKeys) {
    const params = { r: rid };
    const list = permissionKeys.map((k, i) => { params[`k${i}`] = k; return `:k${i}`; }).join(', ');
    await db.execute(`INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled) SELECT :r, id, 1 FROM permissions WHERE permission_key IN (${list})`, params);
  }

  async function outstandingAdvance(orderId) {
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute('UPDATE order_payment_status SET advance_amount = 100, advance_cleared_at = NULL WHERE order_id = :o', { o: orderId });
  }

  const dispute = (orderId) => db.execute(
    "INSERT INTO disputes (order_id, notice_date, description, status) VALUES (:o, CURDATE(), 'Jest D-379 dispute', 'Open')", { o: orderId }
  );

  const reference = async (orderId) => String((await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id: orderId })).order_reference);

  const ids = (rows, key) => [...new Set(rows.map((r) => Number(r[key])))];

  /**
   * Orders around one Export Executive (E) of a fresh department, a Sales/Export Manager (M) of the same
   * department and a Logistics Executive (X) of another:
   *   mine   — E is an assignee;      credit — E is the salesperson credited (no assignee);
   *   owned  — E is the department owner;  other — only X is assigned.
   */
  async function scenario() {
    const sales = await department(['Export Executive', 'Sales/Export Manager']);
    const logistics = await department(['Logistics Executive']);
    const e = await createTestUser('Export Executive');
    const m = await createTestUser('Sales/Export Manager');
    const x = await createTestUser('Logistics Executive');
    await member(e, sales['Export Executive']);
    await member(m, sales['Sales/Export Manager']);
    await member(x, logistics['Logistics Executive']);

    const client = await createTestClient();
    const mine = Number(await createTestOrder(client, 'FOB', 'Standard — New Buyer', 'USD', null, null, e));
    const credit = Number(await createTestOrder(client));
    await db.execute('UPDATE orders SET sales_credit_user_id = :u WHERE id = :o', { u: e, o: credit });
    const owned = Number(await createTestOrder(client));
    await db.execute('INSERT INTO order_department_owners (order_id, department_id, user_id, assigned_by) VALUES (:o, :d, :u, :b)', { o: owned, d: sales.id, u: e, b: e });
    const other = Number(await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, x));
    for (const o of [mine, credit, owned, other]) {
      await outstandingAdvance(o);
      await dispute(o);
    }
    return { e, m, x, mine, credit, owned, other, client };
  }

  // ---------------------------------------------------------------- defaults

  test('the default levels are seeded for every role of the table', async () => {
    const spot = [
      ['Export Executive', 'aggregate', 'own'], ['Sales/Export Manager', 'ageing', 'department'], ['Sales Director', 'sales_targets', 'department'],
      ['Admin', 'audit_log', 'all'], ['Investor (view only)', 'cockpit', 'all'], ['Managing Director', 'cafin', 'all'],
      ['Executive Director', 'incentives', 'all'], ['Logistics Manager', 'freight_cost', 'department'], ['Viewer / Auditor', 'audit_log', 'all'],
      ['Procurement Executive', 'supplier_performance', 'own'], ['Export Compliance Executive', 'missing_ebrc', 'all'],
    ];
    for (const [role, key, level] of spot) {
      expect([role, key, await reportAccess.roleLevel(await roleId(role), key)]).toEqual([role, key, level]);
    }
    expect(await reportAccess.roleLevel(await roleId('Admin'), 'aggregate')).toBe('off'); // no row = off
    expect(await reportAccess.roleLevel(await roleId('Investor (view only)'), 'per_client')).toBe('off');

    // Every role of the table holds exactly its defaults (spec §4).
    for (const group of Object.keys(reportAccess.DEFAULTS)) {
      const names = group === 'MD' ? reportAccess.MD_ROLE_NAMES : [group];
      for (const name of names) {
        const rid = await roleId(name);
        if (rid === 0) continue; // "Founder & …" designations are not roles on a fresh install
        expect([name, await reportAccess.roleLevels(rid)]).toEqual([name, reportAccess.defaultsForRoleName(name)]);
      }
    }
    expect(Object.keys(reportAccess.REPORTS)).toHaveLength(24);
    expect(Object.keys(reportAccess.SECTIONS)).toEqual(['sales', 'payments', 'disputes', 'freight', 'people', 'finance', 'management']);

    const holders = (await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE p.permission_key = 'manage_report_access' ORDER BY r.name`
    )).map((r) => r.name);
    expect(holders).toContain('Managing Director');
    expect(holders).toContain('Executive Director');
    expect(holders).not.toContain('Admin'); // Admin does not get it by default
  });

  // ---------------------------------------------------------------- scope

  test('Own, Department and All limit the rows of every scoped report', async () => {
    const s = await scenario();
    const ownSet = [s.mine, s.credit, s.owned].sort((a, b) => a - b);
    const today = todayIst();

    // Own (Export Executive): assignee, department owner or salesperson credited — nothing else.
    await asUser(s.e, async () => {
      expect(await reportAccess.level('aggregate')).toBe('own');
      // Aggregate (and its CSV, built from these rows): own orders only.
      expect(ids(await reportRepository.aggregate(null, null, null, null, null), 'id').sort((a, b) => a - b)).toEqual(ownSet);
      expect((await reportRepository.salesPerformanceReport(today, today)).current.orders_created).toBe(3); // Sales performance counts own orders only
      expect(ids((await reportRepository.ageingReport()).rows, 'order_id').sort((a, b) => a - b)).toEqual(ownSet);
      expect(ids((await reportRepository.disputesReport(null, null)).rows, 'order_id').sort((a, b) => a - b)).toEqual(ownSet);
      expect(ids(await reportRepository.searchOrders(await reference(s.other)), 'id')).not.toContain(s.other); // another person's order is not found
      expect(ids(await reportRepository.searchOrders(await reference(s.mine)), 'id')).toContain(s.mine);
    });

    // Department (Sales/Export Manager): orders whose assignee or department owner is in their department, plus own.
    await asUser(s.m, async () => {
      expect(await reportAccess.level('aggregate')).toBe('department');
      const byReport = {
        aggregate: ids(await reportRepository.aggregate(null, null, null, null, null), 'id'),
        ageing: ids((await reportRepository.ageingReport()).rows, 'order_id'),
        disputes: ids((await reportRepository.disputesReport(null, null)).rows, 'order_id'),
      };
      for (const [report, rows] of Object.entries(byReport)) {
        expect([report, rows.includes(s.mine)]).toEqual([report, true]); // the colleague's assigned order
        expect([report, rows.includes(s.owned)]).toEqual([report, true]); // the colleague's department-owned order
        expect([report, rows.includes(s.other)]).toEqual([report, false]); // another department's order
      }
      expect((await reportRepository.salesPerformanceReport(today, today)).current.orders_created).toBeGreaterThanOrEqual(2);
      expect(ids(await reportRepository.searchOrders(await reference(s.other)), 'id')).not.toContain(s.other);
    });

    // All (Viewer / Auditor): every order.
    await asUser(await createTestUser('Viewer / Auditor'), async () => {
      expect(await reportAccess.level('aggregate')).toBe('all');
      expect(ids(await reportRepository.aggregate(null, null, null, null, null), 'id')).toContain(s.other);
      expect(ids((await reportRepository.ageingReport()).rows, 'order_id')).toContain(s.other);
      expect(ids((await reportRepository.disputesReport(null, null)).rows, 'order_id')).toContain(s.other);
      expect(ids(await reportRepository.searchOrders(await reference(s.other)), 'id')).toContain(s.other);
    });

    // Through the routes: the pages show the scoped rows; the CSV is the same route.
    const agent = await signIn(s.e);
    const page = await agent.get('/reports/aggregate');
    expect(page.status).toBe(200);
    expect(page.text).toContain(await reference(s.mine));
    expect(page.text).not.toContain(await reference(s.other));
    const search = await agent.get(`/reports?q=${encodeURIComponent(await reference(s.other))}`);
    expect(search.text).toContain('No orders match');
  }, 60000);

  test('Off answers 403 by direct URL for the page and its CSV', async () => {
    let agent = await signIn(await createTestUser('Export Executive'));
    for (const p of ['/reports/freight-cost', '/reports/freight-cost?format=csv', '/cockpit', '/audit-log', '/reports/staff', '/ca', '/ca/cafin-export',
      '/reports/order-profitability?format=csv', '/reports/incentives?format=csv', '/sales-targets']) {
      expect([p, (await agent.get(p)).status]).toEqual([p, 403]);
    }
    // Admin holds every permission of these reports, but its level is Off.
    agent = await signIn(await createTestUser('Admin'));
    for (const p of ['/reports/aggregate', '/reports/aggregate?format=csv', '/reports/payments', '/reports/disputes?format=csv', '/ca']) {
      const r = await agent.get(p);
      expect([p, r.status]).toEqual([p, 403]);
      expect(r.text).toContain('403 — Not permitted');
    }
    expect((await agent.get('/reports/queues')).status).toBe(200); // Admin: Operations queues is All
  }, 60000);

  test('the base permission is still needed', async () => {
    // A role with All for Freight cost but without view_logistics_costs, and for Aggregate without the selling prices.
    const role = await customRole();
    await grant(role, ['view_reports']);
    await reportAccess.saveRoleLevels(role, { freight_cost: 'all', aggregate: 'all', queues: 'all' }, 1, 'Jest D-379 base permission');
    const user = await userWithRole(role);
    await asUser(user, async () => {
      expect(await reportAccess.level('freight_cost')).toBe('off');
      expect(await reportAccess.level('aggregate')).toBe('off'); // money permissions are a ceiling
      expect(await reportAccess.level('queues')).toBe('all');
    });
    const agent = await signIn(user);
    expect((await agent.get('/reports/freight-cost')).status).toBe(403);
    expect((await agent.get('/reports/aggregate')).status).toBe(403);
    expect((await agent.get('/reports/queues')).status).toBe(200);

    // An extra cannot get around it either.
    const exec = await createTestUser('Export Executive');
    expect(await reportAccess.addUserExtra(exec, await roleId('Export Executive'), 'supplier_performance', 'all', 'Jest D-379 extra without the permission', 1)).toBeNull();
    await asUser(exec, async () => {
      expect(await reportAccess.level('supplier_performance')).toBe('off'); // no view_purchase_prices
    });

    // The Reports tab says what is missing.
    const md = await signIn(await createTestUser('Managing Director'));
    const tab = await md.get(`/admin/roles/${role}/reports`);
    expect(tab.status).toBe(200);
    expect(tab.text).toContain('missing for this role: view_logistics_costs');
    expect(tab.text).toContain('needs: view_reports, selling prices');
  }, 60000);

  test('the Investor cannot open the per-client report', async () => {
    const client = await createTestClient();
    const investor = await createTestUser('Investor (view only)');
    await asUser(investor, async () => {
      expect(await reportAccess.level('per_client')).toBe('off');
    });
    const agent = await signIn(investor);
    expect((await agent.get(`/reports/client/${client}`)).status).toBe(403);
    expect((await agent.get(`/reports/client/${client}?format=csv`)).status).toBe(403);
    expect((await agent.get('/reports/performance')).status).toBe(403); // All for the Investor, but it needs selling prices
    expect((await agent.get('/reports/conversion')).status).toBe(200); // a report without amounts opens
  }, 60000);

  // ---------------------------------------------------------------- extras and memberships

  test('an extra only raises the level', async () => {
    const exec = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    let agent = await signIn(md);
    const lower = await post(agent, `/users/${exec}/report-extras`, { report_key: 'aggregate', level: 'own', reason: 'Jest D-379 not higher' });
    expect(lower.type).toBe('error');
    expect(String(lower.flash)).toContain('An extra can only raise the level');
    expect((await post(agent, `/users/${exec}/report-extras`, { report_key: 'aggregate', level: 'department', reason: 'short' })).type).toBe('error'); // a reason of 10 characters
    reportAccess.resetCache();
    expect(await reportAccess.userExtras(exec)).toEqual({});

    const ok = await post(agent, `/users/${exec}/report-extras`, { report_key: 'aggregate', level: 'department', reason: 'Jest D-379 covers the manager' });
    expect(ok.type).toBe('success');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'USER_REPORT_EXTRA_ADDED' AND entity_id = :u", { u: exec })).n)).toBe(1);
    const edit = (await agent.get(`/users/${exec}/edit`)).text;
    expect(edit).toContain('id="report-extras"');
    expect(edit).toContain('Jest D-379 covers the manager');

    reportAccess.resetCache();
    await asUser(exec, async () => {
      expect(await reportAccess.level('aggregate')).toBe('department');
      expect(await reportAccess.level('ageing')).toBe('own'); // only the report of the extra
    });

    expect((await post(agent, `/users/${exec}/report-extras/remove`, { report_key: 'aggregate' })).type).toBe('success');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'USER_REPORT_EXTRA_REMOVED' AND entity_id = :u", { u: exec })).n)).toBe(1);
    reportAccess.resetCache();
    await asUser(exec, async () => {
      expect(await reportAccess.level('aggregate')).toBe('own');
    });

    // Only manage_report_access holders manage extras (Admin does not hold it by default).
    agent = await signIn(await createTestUser('Admin'));
    expect((await post(agent, `/users/${exec}/report-extras`, { report_key: 'aggregate', level: 'all', reason: 'Jest D-379 admin try' })).code).toBe(403);
    expect((await agent.get(`/users/${exec}/edit`)).text).not.toContain('id="report-extras"');
  }, 60000);

  test('the highest level over every membership counts', async () => {
    const dept = await department(['Sales/Export Manager']);
    const user = await createTestUser('Export Executive'); // aggregate: own
    await member(user, dept['Sales/Export Manager']); // aggregate: department
    await asUser(user, async () => {
      expect(await reportAccess.level('aggregate')).toBe('department');
      expect(await reportAccess.level('queues')).toBe('department'); // Export Executive is Off for queues; the manager role gives Department
    });
  });

  // ---------------------------------------------------------------- Reports page and menu

  test('the Reports page shows only the allowed sections and the banner', async () => {
    const sectionsOf = (html) => [...html.matchAll(/data-report-section="([a-z]+)"/g)].map((m) => m[1]);
    const reportsOf = (html) => [...html.matchAll(/data-report="([a-z_]+)"/g)].map((m) => m[1]);

    let agent = await signIn(await createTestUser('Export Executive'));
    let page = await agent.get('/reports');
    expect(page.status).toBe(200);
    expect(sectionsOf(page.text)).toEqual(['sales', 'payments', 'disputes']);
    expect(page.text).toContain(reportAccess.BANNER_OWN);
    expect(reportsOf(page.text)).not.toContain('freight_cost');

    agent = await signIn(await createTestUser('Sales/Export Manager'));
    page = await agent.get('/reports');
    expect(page.text).toContain(reportAccess.BANNER_DEPARTMENT.replace("'", '&#39;'));

    agent = await signIn(await createTestUser('Managing Director'));
    page = await agent.get('/reports');
    expect(sectionsOf(page.text)).toEqual(Object.keys(reportAccess.SECTIONS));
    expect(page.text).not.toContain('id="reports-scope-banner"');

    // A role with no report at all: /reports itself is refused (the gate is "at least one report").
    const role = await customRole();
    await grant(role, ['view_reports']);
    agent = await signIn(await userWithRole(role));
    expect((await agent.get('/reports')).status).toBe(403);
  }, 60000);

  // Spec §6 "Admin sees Management only": Management, plus the order lookup the defaults give Admin (Find an order = All).
  test('Admin sees Management only', async () => {
    const admin = await createTestUser('Admin');
    const page = (await (await signIn(admin)).get('/reports')).text;
    expect([...page.matchAll(/data-report="([a-z_]+)"/g)].map((m) => m[1]).sort()).toEqual(['audit_log', 'cockpit', 'order_search', 'queues']);
    await asUser(admin, async () => {
      for (const key of ['aggregate', 'payments', 'ageing', 'profitability', 'cafin', 'ca_reports', 'staff_productivity', 'freight_cost']) {
        expect([key, await reportAccess.level(key)]).toEqual([key, 'off']);
      }
    });
  });

  test('the menu shows Sales targets to MD / ED and the Sales Director only', async () => {
    const expected = {
      'Managing Director': true, 'Executive Director': true, 'Sales Director': true,
      'Sales/Export Manager': false, 'Export Executive': false, Admin: false, 'Finance Director': false,
    };
    for (const [role, shown] of Object.entries(expected)) {
      const user = await createTestUser(role);
      await asUser(user, async () => {
        const perms = requestContext.get().permissions;
        const built = navigation.build((p) => !!perms[p], await reportAccess.navFlags(), {}, '/', {});
        expect([role, built.sidebar.map((s) => s.key).includes('sales_targets')]).toEqual([role, shown]);
      });
    }
    // The menu's Reports entries follow report access too: Freight cost for Logistics, not for Sales.
    const mayOpen = async (p) => permittedLinks.mayOpen(p, requestContext.get().permissions, null, null, await reportAccess.levels());
    await asUser(await createTestUser('Logistics Manager'), async () => {
      expect(await mayOpen('/reports/freight-cost')).toBe(true);
    });
    await asUser(await createTestUser('Export Executive'), async () => {
      expect(await mayOpen('/reports/freight-cost')).toBe(false);
      expect(await mayOpen('/reports')).toBe(true);
    });
  }, 60000);

  test('the team view has no money and no Edit', async () => {
    const dept = await department(['Sales Director', 'Export Executive']);
    const director = await createTestUser('Sales Director');
    await member(director, dept['Sales Director']);
    await asUser(director, async () => {
      expect(await reportAccess.level('sales_targets')).toBe('department');
    });
    let agent = await signIn(director);
    const r = await agent.get('/sales-targets');
    expect(r.status).toBe(200);
    const start = r.text.indexOf('id="sales-targets-team"');
    expect(start).toBeGreaterThan(-1); // the read-only team view
    const end = r.text.indexOf('</table>', start);
    const view = r.text.slice(start, end > -1 ? end : r.text.length);
    expect(view).toContain('id="target-team-view"');
    for (const absent of ['>Edit<', '₹', 'target-calculator', '/sales-targets/settings', 'Associates', 'Multiple']) {
      expect([absent, view.includes(absent)]).toEqual([absent, false]);
    }
    expect((await agent.get('/sales-targets/settings')).status).toBe(403);
    expect((await post(agent, `/sales-targets/person/${director}`, { containers: '5', reason: 'Jest D-379 not allowed' })).code).toBe(403);

    // MD: the full page (All).
    agent = await signIn(await createTestUser('Managing Director'));
    expect((await agent.get('/sales-targets')).text).toContain('id="target-team"');
  }, 60000);

  // ---------------------------------------------------------------- Reports tab, preview, reset

  test('preview renders the Reports page as the role', async () => {
    let agent = await signIn(await createTestUser('Managing Director'));
    const investor = await roleId('Investor (view only)');
    const r = await agent.get(`/admin/roles/${investor}/reports/preview`);
    expect(r.status).toBe(200);
    expect(r.text).toContain('Preview as Investor (view only)');
    const start = r.text.indexOf('id="reports-page"');
    const end = r.text.indexOf('</main>', start);
    const page = r.text.slice(start, end > -1 ? end : r.text.length);
    // Investor: performance and trends need selling prices it does not have.
    expect([...page.matchAll(/data-report="([a-z_]+)"/g)].map((m) => m[1]).sort()).toEqual(['cockpit', 'conversion', 'queues']);
    expect(page).not.toContain('href="/reports/queues"'); // read-only: no links

    agent = await signIn(await createTestUser('Admin'));
    expect((await agent.get(`/admin/roles/${investor}/reports/preview`)).status).toBe(403); // manage_report_access only
    expect((await agent.get(`/admin/roles/${investor}/reports`)).status).toBe(403);
  }, 60000);

  test('the Reports tab saves with a reason and resets to defaults', async () => {
    const role = await customRole();
    const agent = await signIn(await createTestUser('Managing Director'));
    const tab = await agent.get(`/admin/roles/${role}/reports`);
    expect(tab.status).toBe(200);
    expect(tab.text).toContain('name="levels[aggregate]" value="off" checked');
    expect(tab.text).toContain('See Reports as this role');
    expect(tab.text).toContain('Reset to defaults');

    const noReason = await post(agent, `/admin/roles/${role}/reports`, { 'levels[aggregate]': 'all' });
    expect(noReason.type).toBe('error');
    reportAccess.resetCache();
    expect(await reportAccess.roleLevel(role, 'aggregate')).toBe('off');

    const saved = await post(agent, `/admin/roles/${role}/reports`, {
      'levels[aggregate]': 'all', 'levels[payments]': 'own', 'levels[queues]': 'off', 'levels[bogus]': 'all', reason: 'Jest D-379 tab save',
    });
    expect(saved.type).toBe('success');
    reportAccess.resetCache();
    expect(await reportAccess.roleLevel(role, 'aggregate')).toBe('all');
    expect(await reportAccess.roleLevel(role, 'payments')).toBe('own');
    const audit = await db.query(
      "SELECT field_name, old_value, new_value, reason FROM audit_log WHERE action_type = 'REPORT_ACCESS_CHANGED' AND entity_id = :r ORDER BY field_name", { r: role }
    );
    // One row per changed report, old → new.
    expect(audit.map((a) => [a.field_name, a.old_value, a.new_value, a.reason])).toEqual([
      ['aggregate', 'off', 'all', 'Jest D-379 tab save'], ['payments', 'off', 'own', 'Jest D-379 tab save'],
    ]);

    expect((await post(agent, `/admin/roles/${role}/reports/reset`, {})).type).toBe('error'); // reset needs a reason
    expect((await post(agent, `/admin/roles/${role}/reports/reset`, { reason: 'Jest D-379 reset' })).type).toBe('success');
    reportAccess.resetCache();
    const allOff = Object.fromEntries(Object.keys(reportAccess.REPORTS).map((k) => [k, 'off']));
    expect(await reportAccess.roleLevels(role)).toEqual(allOff); // a role not in the defaults goes back to Off

    // A role of the defaults resets to its seeded levels (checked without changing the seeded role).
    expect(reportAccess.defaultsForRoleName('Export Executive').aggregate).toBe('own');
    expect(reportAccess.defaultsForRoleName('Founder & Executive Director')).toEqual(reportAccess.defaultsForRoleName('Managing Director'));
  }, 60000);

  // The Reports page controller data also works without a request (preview): nothing is queried for a previewed role.
  test('index data for a preview queries nothing', async () => {
    const exec = await roleId('Export Executive');
    await asUser(await createTestUser('Managing Director'), async () => {
      reportAccess.previewAsRole(exec);
      let data;
      try {
        data = await indexData('anything', { id: exec, name: 'Export Executive' });
      } finally {
        reportAccess.previewAsRole(null);
      }
      expect(data.searchResults).toEqual([]);
      expect(data.clients).toEqual([]);
      expect(data.banner).toBe(reportAccess.BANNER_OWN);
    });
  });
});
