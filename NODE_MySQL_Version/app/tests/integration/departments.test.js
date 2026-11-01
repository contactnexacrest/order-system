'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const departmentRepository = require('../../src/repositories/departmentRepository');
const permissionRepository = require('../../src/repositories/permissionRepository');
const { todayIst } = require('../../src/helpers/dbDateTime');

/**
 * Port of DepartmentsTest.php. Owner decision D-214 (2026-10-31), phase 1:
 * departments, designations (each its own role), memberships, "Log in as",
 * switching, Admin's "Preview as", the department recorded on every audit
 * row, and personal exceptions with an end date.
 */
describe('Departments, Log in as and Preview as', () => {
  const PASSWORD = 'JestDepartments!1';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  let adminId;
  let admin;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-dept-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function signIn(user) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const res = await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    agent.landedOn = res.headers.location;
    return agent;
  }

  async function designationId(title) {
    return Number((await db.queryOne('SELECT id FROM department_designations WHERE title = :t', { t: title })).id);
  }

  async function join(userId, title, from = todayIst()) {
    return departmentRepository.addMembership(userId, await designationId(title), from, adminId);
  }

  /** Posts a form from `page` and returns the flash the next page shows. */
  async function post(agent, page, path, fields) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(path).type('form').send({ _csrf: csrf, ...fields });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') } : { type: null, message: '' };
  }

  async function lastAudit(action) {
    return (await db.queryOne('SELECT * FROM audit_log WHERE action_type = :a ORDER BY id DESC LIMIT 1', { a: action })) || {};
  }

  beforeEach(async () => {
    const a = await createUser('Admin');
    adminId = a.id;
    admin = await signIn(a);
  });

  it('gives every department its own ladder', async () => {
    const titles = (await departmentRepository.designations()).map((d) => d.title);
    for (const t of ['Sales/Export Executive', 'Sales/Export Manager', 'Sales Director', 'Procurement Executive', 'Procurement Manager', 'Procurement Director',
      'Logistics Executive', 'Logistics Manager', 'Logistics Director', 'Accounts Executive', 'Finance Manager', 'Finance Director',
      'Managing Director', 'Executive Director', 'Internal Auditor', 'External CA', 'System Administrator', 'Order Coordinator']) {
      expect(titles).toContain(t);
    }
    expect((await departmentRepository.departments(true)).map((d) => d.code)).not.toContain('OPS');
  });

  it('lets someone in two departments choose one, and gives them that department\'s rights', async () => {
    const ravi = await createUser('Export Executive');
    const sales = await join(ravi.id, 'Sales/Export Executive');
    const proc = await join(ravi.id, 'Procurement Manager');
    const agent = await signIn(ravi);
    expect(agent.landedOn).toBe('/choose-department');
    expect((await agent.get('/orders')).headers.location).toBe('/choose-department');

    expect(await post(agent, '/choose-department', '/choose-department', { membership_id: '999999' }))
      .toEqual({ type: 'error', message: 'Choose one of your departments.' });
    await post(agent, '/choose-department', '/choose-department', { membership_id: String(proc) });
    expect((await agent.get('/suppliers')).status).toBe(200); // Procurement Manager may manage suppliers
    expect((await lastAudit('DEPARTMENT_SELECTED')).acting_as).toBe('Procurement Manager · Procurement');

    expect(await post(agent, '/', '/switch-department', { membership_id: String(sales) }))
      .toEqual({ type: 'success', message: 'You are now working as Sales/Export Executive · Sales & Export.' });
    expect((await agent.get('/suppliers')).status).toBe(403);
    const row = await lastAudit('DEPARTMENT_SWITCHED');
    expect(row.old_value).toBe('Procurement Manager · Procurement');
    expect(row.acting_as).toBe('Sales/Export Executive · Sales & Export');
  });

  it('skips the choice for one membership or a remembered default', async () => {
    const one = await createUser('Viewer / Auditor');
    await join(one.id, 'Logistics Executive');
    expect((await signIn(one)).landedOn).toBe('/');
    expect((await lastAudit('DEPARTMENT_SELECTED')).acting_as).toBe('Logistics Executive · Logistics');

    const two = await createUser('Viewer / Auditor');
    await join(two.id, 'Logistics Executive');
    const proc = await join(two.id, 'Procurement Executive');
    await departmentRepository.setDefault(two.id, proc);
    expect((await signIn(two)).landedOn).toBe('/');
    expect((await lastAudit('DEPARTMENT_SELECTED')).acting_as).toBe('Procurement Executive · Procurement');

    const none = await createUser('Export Executive');
    expect((await signIn(none)).landedOn).toBe('/');
  });

  it('never switches into someone else\'s or an ended membership', async () => {
    const a = await createUser('Export Executive');
    const b = await createUser('Export Executive');
    await join(a.id, 'Sales/Export Executive');
    const bMembership = await join(b.id, 'Procurement Manager');
    const agentA = await signIn(a);
    expect((await post(agentA, '/', '/switch-department', { membership_id: String(bMembership) })).message)
      .toBe('That department is not one of yours (or it has ended).');

    const agentB = await signIn(b);
    expect((await agentB.get('/suppliers')).status).toBe(200);
    await db.execute('UPDATE user_department_memberships SET starts_on = DATE_SUB(CURDATE(), INTERVAL 5 DAY), ends_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :id', { id: bMembership });
    expect((await agentB.get('/suppliers')).status).toBe(403); // an ended membership stops at once
  });

  it('previews exactly the designation and ends cleanly', async () => {
    const own = await permissionRepository.findByKey('manage_suppliers');
    await permissionRepository.grantOverride(adminId, own.id, adminId, 'own exception for the test');
    expect((await admin.get('/admin/departments')).status).toBe(200);

    const result = await post(admin, '/admin/departments', '/admin/preview-as', { designation_id: String(await designationId('Logistics Executive')) });
    expect(result.message).toBe('Previewing the app as Logistics Executive · Logistics. Use "Exit preview" at the top to return.');
    expect((await admin.get('/admin/departments')).status).toBe(403); // no Admin rights while previewing
    expect((await admin.get('/suppliers')).status).toBe(403); // own exceptions are off in a preview
    expect((await lastAudit('DEPARTMENT_PREVIEW_STARTED')).acting_as).toBe('Preview: Logistics Executive · Logistics');

    expect((await post(admin, '/', '/preview/exit', {})).message).toBe('Preview ended.');
    expect((await admin.get('/admin/departments')).status).toBe(200);
    expect(await lastAudit('DEPARTMENT_PREVIEW_ENDED')).not.toEqual({});
  });

  it('adds departments and designations with checks', async () => {
    const tag = crypto.randomBytes(3).toString('hex').toUpperCase().slice(0, 5);
    const page = '/admin/departments';
    expect((await post(admin, page, '/admin/departments', { name: `Quality ${tag}`, code: '9X', color: '#336699' })).message)
      .toBe('Enter a short code: 2 to 20 capital letters, digits or _ (e.g. QUALITY).');
    expect((await post(admin, page, '/admin/departments', { name: `Quality ${tag}`, code: `q${tag}`, color: '#336699' })).message)
      .toBe(`Department "Quality ${tag}" created. Add its designations below.`);
    expect((await post(admin, page, '/admin/departments', { name: `Quality ${tag}`, code: `R${tag}`, color: '#336699' })).message)
      .toBe(`A department named "Quality ${tag}" already exists.`);
    const dept = await db.queryOne('SELECT id FROM departments WHERE code = :c', { c: `Q${tag}` });
    expect(dept).toBeTruthy(); // the code is stored in capitals

    const body = { department_id: String(dept.id), title: `QC Manager ${tag}`, level: 'manager', role_id: 'new' };
    expect((await post(admin, page, '/admin/designations', { ...body, level: 'boss' })).message).toBe('Choose the level.');
    expect((await post(admin, page, '/admin/designations', body)).message)
      .toBe(`"QC Manager ${tag}" added to Quality ${tag}. Its role has no permissions yet — set them with "Permissions".`);
    expect((await post(admin, page, '/admin/designations', body)).message).toBe(`"QC Manager ${tag}" already exists in Quality ${tag}.`);
    const d = await departmentRepository.findDesignation(await designationId(`QC Manager ${tag}`));
    expect(d.role_name).toBe(`QC Manager ${tag}`);

    // QA Round 8, R8-N10: approval limits change only with "Manage approval limits" (MD, ED).
    admin = await signIn(await createUser('Managing Director'));
    expect((await post(admin, page, `/admin/designations/${d.id}/update`, { title: d.title, level: 'manager', approval_limit_inr: '-5', is_active: '1' })).message)
      .toBe('Enter the approval limit in rupees, or leave it empty for none.');
    await post(admin, page, `/admin/designations/${d.id}/update`, { title: d.title, level: 'manager', approval_limit_inr: '500000', is_active: '1' });
    expect(String((await departmentRepository.findDesignation(d.id)).approval_limit_inr)).toBe('500000.00');
    expect((await lastAudit('DESIGNATION_UPDATED')).new_value).toContain('limit 500000');
  });

  // QA Round 8, R8-N10: a designation's approval limit and level are MD/ED only, never on their own designation, and audited old → new.
  it('changes approval limits only by the MD or ED and never on their own designation', async () => {
    const LIMITS_REFUSED = "Only the MD or ED can change an approval limit or make a designation 'director'.";
    const OWN_LIMIT_REFUSED = 'You cannot change the approval limit of a designation you hold yourself.';
    const page = '/admin/departments';
    const pm = await departmentRepository.findDesignation(await designationId('Procurement Manager'));
    const before = pm.approval_limit_inr;
    const body = { title: pm.title, level: pm.level, is_active: '1' };
    const update = (agent, d, fields) => post(agent, page, `/admin/designations/${d.id}/update`, fields);

    const sysadmin = await signIn(await createUser('System Administrator'));
    expect((await update(sysadmin, pm, { ...body, approval_limit_inr: '999999999' })).message).toBe(LIMITS_REFUSED);
    expect((await update(sysadmin, pm, { ...body, level: 'director', approval_limit_inr: before === null ? '' : String(before) })).message).toBe(LIMITS_REFUSED);
    const after = await departmentRepository.findDesignation(pm.id);
    expect([after.approval_limit_inr, after.level]).toEqual([before, pm.level]); // the System Administrator could not lift the limit
    expect((await update(sysadmin, pm, { ...body, approval_limit_inr: before === null ? '' : String(before) })).message).toBe(`"${pm.title}" saved.`); // the rest of the form still saves
    expect((await post(sysadmin, page, '/admin/designations', { department_id: String(pm.department_id), title: `Deputy Boss ${crypto.randomBytes(2).toString('hex')}`, level: 'director', role_id: 'new' })).message).toBe(LIMITS_REFUSED);
    const sysPage = (await sysadmin.get(page)).text;
    expect(sysPage).toContain('Only the MD or ED changes approval limits.');
    expect(sysPage).not.toContain('name="approval_limit_inr" min="0"');

    const mdUser = await createUser('Managing Director');
    const md = await signIn(mdUser);
    expect((await update(md, pm, { ...body, approval_limit_inr: '750000' })).message).toBe(`"${pm.title}" saved.`);
    expect(String((await departmentRepository.findDesignation(pm.id)).approval_limit_inr)).toBe('750000.00');
    const audit = await lastAudit('APPROVAL_LIMIT_CHANGED');
    expect([Number(audit.user_id), audit.field_name, audit.old_value, audit.new_value])
      .toEqual([mdUser.id, 'approval_limit_inr', `${pm.level} · ${before === null ? 'no limit' : Number(before).toFixed(2)}`, `${pm.level} · 750000.00`]);
    const mdDesignation = await departmentRepository.findDesignation(await designationId('Managing Director'));
    expect((await update(md, mdDesignation, { title: mdDesignation.title, level: mdDesignation.level, approval_limit_inr: '1', is_active: '1' })).message).toBe(OWN_LIMIT_REFUSED);
    expect((await md.get(page)).text).toContain('Your own designation — another MD/ED changes it.');

    // A Procurement Director cannot lift their own designation's limit — whether or not they also hold MD/ED rights.
    const pd = await departmentRepository.findDesignation(await designationId('Procurement Director'));
    const pdBody = { title: pd.title, level: pd.level, approval_limit_inr: '999999999', is_active: '1' };
    // Without "Manage permissions" the Procurement Director cannot even reach the designation form.
    const director = await signIn(await createUser('Procurement Director'));
    const csrf = csrfOf((await director.get(director.landedOn)).text);
    expect((await director.post(`/admin/designations/${pd.id}/update`).type('form').send({ _csrf: csrf, ...pdBody })).status).toBe(403);
    const director2 = await createUser('Procurement Director');
    for (const key of ['manage_permissions', 'manage_approval_limits']) {
      await db.execute("INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) SELECT :u, id, 1, 'Jest' FROM permissions WHERE permission_key = :k", { u: director2.id, k: key });
    }
    expect((await update(await signIn(director2), pd, pdBody)).message).toBe(OWN_LIMIT_REFUSED);
    expect((await departmentRepository.findDesignation(pd.id)).approval_limit_inr).toBe(pd.approval_limit_inr);

    await departmentRepository.updateDesignation(Number(pm.id), pm.title, pm.level, true, before === null ? null : Number(before));
  });

  it('adds and ends memberships on the person\'s page', async () => {
    const ravi = await createUser('Export Executive');
    const page = `/users/${ravi.id}/edit`;
    const designation = String(await designationId('Procurement Manager'));
    expect((await post(admin, page, `/users/${ravi.id}/memberships`, { designation_id: designation, starts_on: '2026-11-01' })).message)
      .toBe('Jest Test User is now Procurement Manager in Procurement (from 2026-11-01).');
    expect((await post(admin, page, `/users/${ravi.id}/memberships`, { designation_id: designation, starts_on: '2026-11-02' })).message)
      .toBe('Jest Test User is already Procurement Manager in Procurement.');
    expect((await post(admin, page, `/users/${ravi.id}/memberships`, { designation_id: String(await designationId('Logistics Manager')), starts_on: '2026-02-30' })).message)
      .toBe('Enter the date the membership starts.');

    const m = (await departmentRepository.membershipsForUser(ravi.id))[0];
    expect((await post(admin, page, `/users/${ravi.id}/memberships/${m.id}/end`, { ends_on: '2026-10-31' })).message)
      .toBe('Enter the last day of the membership (not before it started).');
    expect((await post(admin, page, `/users/${ravi.id}/memberships/${m.id}/end`, { ends_on: '2026-12-31' })).message)
      .toBe('Membership Procurement Manager · Procurement ends on 2026-12-31.');

    const founder = await createUser('Managing Director');
    await db.execute('UPDATE users SET is_protected_account = 1 WHERE id = :id', { id: founder.id });
    expect((await post(admin, page, `/users/${founder.id}/memberships`, { designation_id: designation })).message)
      .toContain('protected founder account');
  });

  it('records on audit rows the department the change was made in', async () => {
    const sysadmin = await createUser('Viewer / Auditor');
    await join(sysadmin.id, 'System Administrator');
    const agent = await signIn(sysadmin);
    const tag = crypto.randomBytes(3).toString('hex').toUpperCase().slice(0, 5);
    await post(agent, '/admin/departments', '/admin/departments', { name: `Audit Check ${tag}`, code: `A${tag}`, color: '#336699' });
    const row = await db.queryOne("SELECT acting_as FROM audit_log WHERE user_id = :u AND action_type = 'DEPARTMENT_CREATED' ORDER BY id DESC LIMIT 1", { u: sysadmin.id });
    expect(row.acting_as).toBe('System Administrator · Administration');
    await post(admin, '/admin/departments', '/admin/departments', { name: `Audit Other ${tag}`, code: `B${tag}`, color: '#336699' });
    expect((await lastAudit('DEPARTMENT_CREATED')).acting_as).toBeNull(); // a person with no department
  });

  it('stops a personal exception after its end date', async () => {
    const ravi = await createUser('Viewer / Auditor');
    const perm = await permissionRepository.findByKey('manage_suppliers');
    await permissionRepository.grantOverride(ravi.id, perm.id, adminId, 'covering for a colleague', todayIst());
    expect((await permissionRepository.effectivePermissions(ravi.id, null)).manage_suppliers).toBe(true);
    await db.execute('UPDATE user_permissions SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = :u', { u: ravi.id });
    expect((await permissionRepository.effectivePermissions(ravi.id, null)).manage_suppliers).toBeFalsy();

    expect((await post(admin, '/admin/permissions', '/admin/permissions/grant', {
      user_id: String(ravi.id), permission_id: String(perm.id), reason: 'covering for a colleague', expires_at: '2020-01-01',
    })).message).toBe('The "Until" date must be today or later (or leave it empty for no end date).');
  });

  it('notifies whoever holds the permission in any of their departments', async () => {
    const ravi = await createUser('Viewer / Auditor');
    expect(await permissionRepository.usersWithPermission('manage_suppliers')).not.toContain(ravi.id);
    await join(ravi.id, 'Logistics Executive');
    await join(ravi.id, 'Procurement Manager');
    expect(await permissionRepository.usersWithPermission('manage_suppliers')).toContain(ravi.id);
  });
});
