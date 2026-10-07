'use strict';

const db = require('../../src/config/db');
const referenceDocController = require('../../src/controllers/referenceDocController');
const referenceLibraryRepository = require('../../src/repositories/referenceLibraryRepository');
const referenceLibraryCategoryRepository = require('../../src/repositories/referenceLibraryCategoryRepository');

/**
 * Port of ReferenceLibraryCategoriesTest.php (Batch 3 #13a, part 1). A
 * category is purely a grouping label (name) with an optional
 * required_permission: NULL (the default, and the only state that
 * existed before this feature) means visible to every authenticated
 * staff member; set, only a user holding that permission can see
 * documents filed under it. The fixed 8 internal_reference_docs are
 * never scoped by this — only the free-form custom entries
 * (reference_library_documents).
 */
describe('Reference Library categories (Batch 3 #13a part 1)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-reflibcat-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeReq(userId, body = {}, params = {}, permissions = {}) {
    return { user: { id: userId }, body, params, permissions, session: {} };
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  test('category repository CRUD', async () => {
    const id = await referenceLibraryCategoryRepository.create('CA / Accounts', 'ca_module_view');
    let category = await referenceLibraryCategoryRepository.find(id);
    expect(category.name).toBe('CA / Accounts');
    expect(category.required_permission).toBe('ca_module_view');

    await referenceLibraryCategoryRepository.update(id, 'CA / Accounts (renamed)', null);
    category = await referenceLibraryCategoryRepository.find(id);
    expect(category.name).toBe('CA / Accounts (renamed)');
    expect(category.required_permission).toBeNull();
  });

  test('deleting a category uncategorizes its documents rather than deleting them', async () => {
    const userId = await createTestUser('Admin');
    const categoryId = await referenceLibraryCategoryRepository.create('Restricted', 'ca_module_view');
    const docId = await referenceLibraryRepository.create('A restricted doc', 'content', userId, categoryId);

    await referenceLibraryCategoryRepository.deleteCategory(categoryId);

    expect(await referenceLibraryCategoryRepository.find(categoryId)).toBeNull();
    const doc = await referenceLibraryRepository.find(docId);
    expect(doc).not.toBeNull(); // the document itself must never be deleted
    expect(doc.category_id).toBeNull();
  });

  test('index shows an uncategorized document to every staff member', async () => {
    const userId = await createTestUser('Viewer / Auditor');
    await referenceLibraryRepository.create('Visible To Everyone', null, userId, null);

    const req = fakeReq(userId, {}, {}, {});
    const res = fakeRes();
    await referenceDocController.index(req, res);

    const titles = res.renderedData.customDocs.map((d) => d.title);
    expect(titles).toContain('Visible To Everyone');
  });

  test('index hides a restricted-category doc from a user without the permission', async () => {
    // 'Viewer / Auditor' holds view_reports/view_audit_log/view_product_catalog/view_orders/view_clients — not ca_module_view
    const viewerId = await createTestUser('Viewer / Auditor');
    const categoryId = await referenceLibraryCategoryRepository.create(`CA Only ${Math.random().toString(16).slice(2, 10)}`, 'ca_module_view');
    await referenceLibraryRepository.create('CA Working Paper', null, viewerId, categoryId);

    const req = fakeReq(viewerId, {}, {}, { view_reports: true, view_audit_log: true, view_product_catalog: true, view_orders: true, view_clients: true });
    const res = fakeRes();
    await referenceDocController.index(req, res);

    const titles = res.renderedData.customDocs.map((d) => d.title);
    expect(titles).not.toContain('CA Working Paper');
  });

  test('index shows a restricted-category doc to a user who holds the permission', async () => {
    const caUserId = await createTestUser('CA / Chartered Accountant'); // holds ca_module_view
    const categoryId = await referenceLibraryCategoryRepository.create(`CA Only ${Math.random().toString(16).slice(2, 10)}`, 'ca_module_view');
    await referenceLibraryRepository.create('CA Working Paper', null, caUserId, categoryId);

    const req = fakeReq(caUserId, {}, {}, { ca_module_view: true });
    const res = fakeRes();
    await referenceDocController.index(req, res);

    const titles = res.renderedData.customDocs.map((d) => d.title);
    expect(titles).toContain('CA Working Paper');
  });

  test('customShow direct URL is blocked for a restricted category without the permission', async () => {
    const viewerId = await createTestUser('Viewer / Auditor');
    const categoryId = await referenceLibraryCategoryRepository.create(`CA Only ${Math.random().toString(16).slice(2, 10)}`, 'ca_module_view');
    const docId = await referenceLibraryRepository.create('CA Working Paper', null, viewerId, categoryId);

    const req = fakeReq(viewerId, {}, { id: String(docId) }, {});
    const res = fakeRes();
    await referenceDocController.customShow(req, res);

    expect(res.statusCode).toBe(403);
    expect(res.renderedView).toBeUndefined();
  });

  test('customShow direct URL works for the real owner permission', async () => {
    const caUserId = await createTestUser('CA / Chartered Accountant');
    const categoryId = await referenceLibraryCategoryRepository.create(`CA Only ${Math.random().toString(16).slice(2, 10)}`, 'ca_module_view');
    const docId = await referenceLibraryRepository.create('CA Working Paper', null, caUserId, categoryId);

    const req = fakeReq(caUserId, {}, { id: String(docId) }, { ca_module_view: true });
    const res = fakeRes();
    await referenceDocController.customShow(req, res);

    expect(res.statusCode).toBe(200);
    expect(res.renderedView).toBe('reference_docs/custom_show');
    expect(res.renderedData.doc.title).toBe('CA Working Paper');
  });

  test('customCreate persists the chosen category', async () => {
    const userId = await createTestUser('Admin');
    const categoryId = await referenceLibraryCategoryRepository.create(`Logistics ${Math.random().toString(16).slice(2, 10)}`, null);

    const req = fakeReq(userId, { title: 'Freight Rate Sheet', content: 'x', category_id: String(categoryId) });
    const res = fakeRes();
    await referenceDocController.customCreate(req, res);

    const docs = (await referenceLibraryRepository.all()).filter((d) => d.title === 'Freight Rate Sheet');
    expect(docs).toHaveLength(1);
    expect(docs[0].category_id).toBe(categoryId);
  });

  test('customUpdate can clear the category back to uncategorized', async () => {
    const userId = await createTestUser('Admin');
    const categoryId = await referenceLibraryCategoryRepository.create(`Logistics ${Math.random().toString(16).slice(2, 10)}`, null);
    const docId = await referenceLibraryRepository.create('Freight Rate Sheet', 'x', userId, categoryId);

    const req = fakeReq(userId, { title: 'Freight Rate Sheet', content: 'x', category_id: '' }, { id: String(docId) });
    const res = fakeRes();
    await referenceDocController.customUpdate(req, res);

    const doc = await referenceLibraryRepository.find(docId);
    expect(doc.category_id).toBeNull();
  });

  test('categoryCreate requires a name', async () => {
    const userId = await createTestUser('Admin');
    const before = (await referenceLibraryCategoryRepository.all()).length;

    const req = fakeReq(userId, { name: '', required_permission: 'ca_module_view' });
    const res = fakeRes();
    await referenceDocController.categoryCreate(req, res);

    expect((await referenceLibraryCategoryRepository.all()).length).toBe(before);
  });
});
