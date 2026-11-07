'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const sopAccess = require('../../src/services/sopAccessService');
const sopController = require('../../src/controllers/sopController');
const galleryController = require('../../src/controllers/galleryController');
const permissionService = require('../../src/services/permissionService');
const departmentRepository = require('../../src/repositories/departmentRepository');

/**
 * Owner decision D-266 (7 Nov 2026): who sees which SOP chapter — port of
 * SopAccessD266Test.php (same cases, same figures, same wording).
 */
describe('SOP access by role (D-266)', () => {
  jest.setTimeout(60000);
  const EVERYONE = ['00-getting-started', '00-introduction', '12-client-portal', '17-order-edit-duplication', '19-complaints', '23-coordination'];
  let libraryBefore;
  let chaptersBefore;
  const rolesCreated = [];

  beforeEach(async () => {
    libraryBefore = (await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = 'sop_library_enabled'")).setting_value;
    chaptersBefore = {};
    for (const r of await db.query('SELECT slug, is_enabled FROM sop_chapters')) chaptersBefore[r.slug] = Number(r.is_enabled);
  });

  afterEach(async () => {
    await db.execute("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'sop_library_enabled'", { v: libraryBefore });
    await db.execute('DELETE FROM sop_chapters');
    for (const [slug, on] of Object.entries(chaptersBefore)) await db.execute('INSERT INTO sop_chapters (slug, is_enabled) VALUES (:s, :e)', { s: slug, e: on });
    while (rolesCreated.length) await db.execute('DELETE FROM sop_role_chapters WHERE role_id = :r', { r: rolesCreated.pop() });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D266 User', :email, 'x', :role, 1, 0, 0)`,
      { email: `jest-d266-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role: role.id }
    );
    return r.insertId;
  }

  /** A signed-in request for this user (permissions as the app loads them). */
  async function reqFor(userId, extra = {}) {
    const u = await db.queryOne('SELECT id, role_id FROM users WHERE id = :id', { id: userId });
    const user = { id: Number(u.id), role_id: u.role_id === null ? null : Number(u.role_id) };
    return { user, permissions: await permissionService.load(user.id, user.role_id, false), params: {}, query: {}, ...extra };
  }

  async function visible(userId, canViewCa = false) {
    const req = await reqFor(userId);
    return sopAccess.visibleSlugs(req.user.id, req.user.role_id, { ...req.permissions, ca_module_view: canViewCa });
  }

  function matching(...prefixes) {
    return sopAccess.allSlugs().filter((s) => prefixes.some((p) => s === p || s.startsWith(`${p}-`)));
  }

  function sorted(slugs) {
    return [...new Set(slugs)].sort();
  }

  async function lastAudit(action) {
    return db.queryOne('SELECT * FROM audit_log WHERE action_type = :a ORDER BY id DESC LIMIT 1', { a: action });
  }

  async function customRole() {
    const r = await db.execute('INSERT INTO roles (name, description, is_system_role) VALUES (:n, :d, 0)', { n: `D266 test role ${crypto.randomBytes(4).toString('hex')}`, d: 'Jest' });
    rolesCreated.push(r.insertId);
    return r.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, headers: {} };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; return res; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.set = (k, v) => { res.headers[k] = v; return res; };
    res.write = () => true;
    res.end = () => res;
    res.on = () => res;
    res.once = () => res;
    res.emit = () => true;
    return res;
  }

  it('every shipped chapter is configured and on at install', () => {
    expect(sorted(Object.keys(chaptersBefore).filter((s) => chaptersBefore[s] === 1))).toEqual(sopAccess.allSlugs());
    expect(libraryBefore).toBe('1');
  });

  it('seeded defaults per role', async () => {
    expect(await visible(await createUser('Logistics Executive'))).toEqual(sorted([...EVERYONE, ...matching('06', '07', '09')]));
    expect(await visible(await createUser('Procurement Executive'))).toEqual(sorted([...EVERYONE, ...matching('05', '22')]));
    expect(await visible(await createUser('Export Executive'))).toEqual(sorted([...EVERYONE, ...matching('01', '02', '03', '04', '10', '11', '13', '24', '27')]));
    expect(await visible(await createUser('Viewer / Auditor'))).toEqual(sorted([...EVERYONE, ...matching('01', '02', '03', '04', '05', '06', '07', '08', '09', '14')]));
    const ca = sopAccess.allSlugs().filter((s) => s.startsWith('ca-'));
    expect(await visible(await createUser('Finance Manager'), true)).toEqual(sorted([...EVERYONE, ...matching('03', '08', '14', '20'), ...ca]));
    expect(await visible(await createUser('Managing Director'), true)).toEqual(sopAccess.allSlugs());
    expect(await visible(await createUser('Admin'), true)).toEqual(sopAccess.allSlugs());
  });

  it('department roles add their chapters', async () => {
    const user = await createUser('Logistics Executive');
    const d = await db.queryOne(
      `SELECT dd.id FROM department_designations dd JOIN departments d ON d.id = dd.department_id JOIN roles r ON r.id = dd.role_id
       WHERE r.name = 'Procurement Executive' AND dd.is_active = 1 AND d.is_active = 1 ORDER BY dd.id LIMIT 1`
    );
    expect(d).not.toBeNull();
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    await departmentRepository.addMembership(user, d.id, yesterday, user);
    const seen = await visible(user);
    for (const slug of [...matching('06', '07', '09'), ...matching('05', '22')]) expect(seen).toContain(slug);
  });

  it('extra chapters for one person are added and removed with audit', async () => {
    const admin = await createUser('Admin');
    const user = await createUser('Logistics Executive');
    const slug = matching('24')[0];
    expect(await visible(user)).not.toContain(slug);
    await sopAccess.addUserExtra(user, slug, admin);
    expect(await visible(user)).toContain(slug);
    const a = await lastAudit('SOP_USER_CHAPTER_ADDED');
    expect([String(a.user_id), String(a.entity_id), a.new_value]).toEqual([String(admin), String(user), slug]);

    const res = fakeRes();
    await sopController.index(await reqFor(user), res);
    expect(res.statusCode).toBe(200);
    expect(res.renderedData.chapters.find((c) => c.slug === slug).extra).toBe(true);

    await sopAccess.removeUserExtra(user, slug, admin);
    expect(await visible(user)).not.toContain(slug);
    expect((await lastAudit('SOP_USER_CHAPTER_REMOVED')).old_value).toBe(slug);
  });

  it('library off leaves only Getting Started, except for MD and Admin', async () => {
    const admin = await createUser('Admin');
    const user = await createUser('Logistics Executive');
    await sopAccess.setLibraryEnabled(false, admin);
    const a = await lastAudit('SOP_LIBRARY_SWITCHED');
    expect([a.new_value, a.old_value]).toEqual(['off', 'on']);

    expect(await visible(user)).toEqual(['00-getting-started']);
    expect(await visible(admin, true)).toEqual(sopAccess.allSlugs());

    let res = fakeRes();
    await sopController.index(await reqFor(user), res);
    expect([res.statusCode, res.renderedView, res.renderedData.message]).toEqual([403, 'sop/forbidden', 'The SOP library is switched off. Ask your Admin if you need it.']);
    res = fakeRes();
    await galleryController.index(await reqFor(user), res);
    expect(res.statusCode).toBe(403);
    res = fakeRes();
    await sopController.show(await reqFor(user, { params: { chapter: '00-getting-started' } }), res);
    expect([res.statusCode, res.renderedView]).toEqual([200, 'sop/show']);

    res = fakeRes();
    await sopController.index(await reqFor(admin), res);
    expect([res.statusCode, res.renderedView]).toEqual([200, 'sop/index']);
  });

  it('a chapter switched off is hidden from everyone but MD and Admin', async () => {
    const admin = await createUser('Admin');
    const user = await createUser('Logistics Executive');
    const slug = matching('06')[0];
    await sopAccess.setChapterEnabled(slug, false, admin);
    expect(await visible(user)).not.toContain(slug);
    expect(await visible(admin, true)).toContain(slug);
    const a = await lastAudit('SOP_CHAPTER_SWITCHED');
    expect([a.field_name, a.old_value, a.new_value]).toEqual([slug, 'on', 'off']);
    await sopAccess.setChapterEnabled(slug, true, admin);
    expect(await visible(user)).toContain(slug);
  });

  it('a new chapter is only for MD and Admin until it is ticked', async () => {
    const admin = await createUser('Admin');
    const user = await createUser('Procurement Executive');
    const slug = matching('22')[0];
    await db.execute('DELETE FROM sop_chapters WHERE slug = :s', { s: slug }); // as if the file had just been added
    expect(await visible(user)).not.toContain(slug);
    expect(await visible(admin, true)).toContain(slug);

    const role = await customRole();
    await sopAccess.saveRoleSlugs(role, [slug, 'no-such-chapter'], admin);
    expect(await sopAccess.roleSlugs(role)).toEqual([slug]);
    expect(Object.keys(await sopAccess.chapterStates())).toContain(slug);
    expect(await visible(user)).toContain(slug);
    const a = await lastAudit('SOP_ROLE_CHAPTERS_SAVED');
    expect([String(a.entity_id), a.new_value]).toEqual([String(role), slug]);
  });

  it('a ca- chapter still needs the CA permission', async () => {
    const admin = await createUser('Admin');
    const role = await customRole();
    const ca = matching('ca-01')[0];
    await sopAccess.saveRoleSlugs(role, [ca], admin);
    const user = await createUser('Viewer / Auditor');
    await db.execute('UPDATE users SET role_id = :r WHERE id = :u', { r: role, u: user });
    expect(await visible(user, false)).not.toContain(ca);
    expect(await visible(user, true)).toContain(ca);
  });

  it('a hidden chapter shows the refusal page in the layout', async () => {
    const user = await createUser('Logistics Executive');
    const hidden = matching('15')[0];
    let res = fakeRes();
    await sopController.show(await reqFor(user, { params: { chapter: hidden } }), res);
    expect([res.statusCode, res.renderedView, res.renderedData.message, res.renderedData.backLabel]).toEqual([403, 'sop/forbidden', "This SOP chapter isn't available for your role. Ask your Admin to add it if you need it.", 'Back to your SOP chapters']);

    res = fakeRes();
    await sopController.index(await reqFor(user), res);
    expect(res.statusCode).toBe(200);
    expect(res.renderedData.contentHtml || '').not.toContain(`href="/sop/${hidden}"`);
  });

  it('screenshots follow the chapters the person may open', async () => {
    const user = await createUser('Logistics Executive');
    const seen = await visible(user);
    const dir = path.join(__dirname, '..', '..', '..', 'docs', 'SOP');
    const byImage = {};
    for (const slug of [...sopAccess.allSlugs(), 'README']) {
      const text = fs.readFileSync(path.join(dir, `${slug}.md`), 'utf8');
      for (const m of new Set([...text.matchAll(/\.\/images\/([\w.-]+)/g)].map((x) => x[1]))) (byImage[m] ||= []).push(slug);
    }
    let shown = null;
    let hidden = null;
    for (const [img, slugs] of Object.entries(byImage)) {
      const open = slugs.filter((s) => seen.includes(s) || s === 'README');
      if (open.length && shown === null) shown = img;
      if (!open.length && hidden === null) hidden = img;
    }
    expect(shown).not.toBeNull();
    expect(hidden).not.toBeNull();
    let res = fakeRes();
    await sopController.asset(await reqFor(user, { params: { file: hidden } }), res);
    expect([res.statusCode, res.body]).toEqual([403, 'Not permitted.']);
    res = fakeRes();
    await sopAccess.imageVisible(shown, user, (await reqFor(user)).permissions, seen).then((ok) => expect(ok).toBe(true));
  });
});
