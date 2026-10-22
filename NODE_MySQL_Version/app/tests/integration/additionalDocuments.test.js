'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const ordersController = require('../../src/controllers/ordersController');
const clientsController = require('../../src/controllers/clientsController');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of AdditionalDocumentsTest.php (Batch 3 #13b). Free-form extra
 * documents attached to an order or a client that don't fit any fixed
 * document type. Reuses file_store (schema.sql Section AZ's
 * is_additional_document marker column) rather than a new table; delete
 * is a soft-delete (is_active = 0) — the file on disk is never touched,
 * same "nothing is ever really deleted" rule as the rest of the app.
 */
describe('Additional Documents (Batch 3 #13b)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-adddoc-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  // -----------------------------------------------------------------
  // Repository
  // -----------------------------------------------------------------

  test('markAsAdditionalDocument sets the flag and notes', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);

    await fileStoreRepository.markAsAdditionalDocument(fileId, 'A note.');

    const file = await fileStoreRepository.find(fileId);
    expect(file.is_additional_document).toBe(1);
    expect(file.notes).toBe('A note.');
  });

  test('additionalDocumentsForOrder excludes ordinary file_store rows', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const ordinaryFileId = await createTestFile(orderId, null); // e.g. a buyer PO copy — never marked
    const additionalFileId = await createTestFile(orderId, null);
    await fileStoreRepository.markAsAdditionalDocument(additionalFileId, null);

    const rows = await fileStoreRepository.additionalDocumentsForOrder(orderId);
    const ids = rows.map((r) => r.id);

    expect(ids).toContain(additionalFileId);
    expect(ids).not.toContain(ordinaryFileId);
  });

  test('additionalDocumentsForClient excludes ordinary file_store rows', async () => {
    const clientId = await createTestClient();
    const ordinaryFileId = await createTestFile(null, clientId);
    const additionalFileId = await createTestFile(null, clientId);
    await fileStoreRepository.markAsAdditionalDocument(additionalFileId, null);

    const rows = await fileStoreRepository.additionalDocumentsForClient(clientId);
    const ids = rows.map((r) => r.id);

    expect(ids).toContain(additionalFileId);
    expect(ids).not.toContain(ordinaryFileId);
  });

  test('deactivate soft-deletes and excludes the file from the list', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    expect((await fileStoreRepository.additionalDocumentsForOrder(orderId)).map((r) => r.id)).toContain(fileId);

    await fileStoreRepository.deactivate(fileId);

    const file = await fileStoreRepository.find(fileId);
    expect(file.is_active).toBe(0); // the row itself is never hard-deleted, only soft-deleted
    expect((await fileStoreRepository.additionalDocumentsForOrder(orderId)).map((r) => r.id)).not.toContain(fileId);
  });

  // -----------------------------------------------------------------
  // Order controller
  // -----------------------------------------------------------------

  test('order upload requires a title', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');
    const req = { params: { id: String(orderId) }, body: { title: '  ' }, user: { id: userId }, session: {} };

    await ordersController.uploadAdditionalDocument(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(messages[0].message).toContain('title is required');
    expect(await fileStoreRepository.additionalDocumentsForOrder(orderId)).toHaveLength(0);
  });

  test('order delete refuses a file belonging to another order', async () => {
    const orderA = await createTestOrder(await createTestClient());
    const orderB = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderA, null);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(orderB), fileId: String(fileId) }, user: { id: userId }, session: {} };
    await ordersController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(1);
  });

  test('order delete refuses a file that is not marked as an additional document', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null); // never marked — e.g. a generated document's own file
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(orderId), fileId: String(fileId) }, user: { id: userId }, session: {} };
    await ordersController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(1);
  });

  test('order delete deactivates the correct file', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(orderId), fileId: String(fileId) }, body: { reason: 'No longer required by the buyer.' }, user: { id: userId }, session: {} };
    await ordersController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(0);
  });

  // External QA report L-18: "deleteAdditionalDocument (order + client) still takes no reason; no 'show removed' view."
  test('order delete refuses without a reason', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(orderId), fileId: String(fileId) }, body: { reason: 'too short' }, user: { id: userId }, session: {} };
    await ordersController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(1);
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  test('order delete logs the reason on the audit entry and it appears on the removed-documents view', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    await fileStoreRepository.markAsAdditionalDocument(fileId, 'Special inspection note.');
    const userId = await createTestUser('Admin');

    const req = {
      params: { id: String(orderId), fileId: String(fileId) },
      body: { reason: 'Buyer withdrew the special inspection requirement.' },
      user: { id: userId },
      session: {},
    };
    await ordersController.deleteAdditionalDocument(req, fakeRes());

    const removed = await fileStoreRepository.removedAdditionalDocumentsForOrder(orderId);
    expect(removed).toHaveLength(1);
    expect(removed[0].removal_reason).toBe('Buyer withdrew the special inspection requirement.');
    expect(removed[0].removed_at).not.toBeNull();

    const res = fakeRes();
    res.renderView = (viewPath, data) => { res.rendered = { viewPath, data }; };
    await ordersController.showRemovedAdditionalDocuments({ params: { id: String(orderId) } }, res);

    expect(res.rendered.viewPath).toBe('orders/removed_additional_documents');
    expect(res.rendered.data.removedDocuments[0].removal_reason).toBe('Buyer withdrew the special inspection requirement.');
  });

  // -----------------------------------------------------------------
  // Client controller
  // -----------------------------------------------------------------

  test('client upload requires a title', async () => {
    const clientId = await createTestClient();
    const userId = await createTestUser('Admin');
    const req = { params: { id: String(clientId) }, body: { title: '' }, user: { id: userId }, session: {} };

    await clientsController.uploadAdditionalDocument(req, fakeRes());

    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
    expect(await fileStoreRepository.additionalDocumentsForClient(clientId)).toHaveLength(0);
  });

  test('client delete refuses a file belonging to another client', async () => {
    const clientA = await createTestClient();
    const clientB = await createTestClient();
    const fileId = await createTestFile(null, clientA);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(clientB), fileId: String(fileId) }, user: { id: userId }, session: {} };
    await clientsController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(1);
  });

  test('client delete deactivates the correct file', async () => {
    const clientId = await createTestClient();
    const fileId = await createTestFile(null, clientId);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(clientId), fileId: String(fileId) }, body: { reason: 'No longer required by the buyer.' }, user: { id: userId }, session: {} };
    await clientsController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(0);
  });

  test('client delete refuses without a reason', async () => {
    const clientId = await createTestClient();
    const fileId = await createTestFile(null, clientId);
    await fileStoreRepository.markAsAdditionalDocument(fileId, null);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(clientId), fileId: String(fileId) }, body: { reason: 'too short' }, user: { id: userId }, session: {} };
    await clientsController.deleteAdditionalDocument(req, fakeRes());

    expect((await fileStoreRepository.find(fileId)).is_active).toBe(1);
    const messages = flash.pull(req);
    expect(messages[0].type).toBe('error');
  });

  test('client delete logs the reason on the audit entry and it appears on the removed-documents view', async () => {
    const clientId = await createTestClient();
    const fileId = await createTestFile(null, clientId);
    await fileStoreRepository.markAsAdditionalDocument(fileId, 'Standing NDA.');
    const userId = await createTestUser('Admin');

    const req = {
      params: { id: String(clientId), fileId: String(fileId) },
      body: { reason: 'Superseded by a new NDA signed this quarter.' },
      user: { id: userId },
      session: {},
    };
    await clientsController.deleteAdditionalDocument(req, fakeRes());

    const removed = await fileStoreRepository.removedAdditionalDocumentsForClient(clientId);
    expect(removed).toHaveLength(1);
    expect(removed[0].removal_reason).toBe('Superseded by a new NDA signed this quarter.');
    expect(removed[0].removed_at).not.toBeNull();

    const res = fakeRes();
    res.renderView = (viewPath, data) => { res.rendered = { viewPath, data }; };
    await clientsController.showRemovedAdditionalDocuments({ params: { id: String(clientId) } }, res);

    expect(res.rendered.viewPath).toBe('clients/removed_additional_documents');
    expect(res.rendered.data.removedDocuments[0].removal_reason).toBe('Superseded by a new NDA signed this quarter.');
  });

  // -----------------------------------------------------------------
  // Rendered UI — order/client show() pages pass additionalDocuments to
  // the view and actually render the section (gated on permissions.manage_orders
  // directly in the .njk templates, same convention as viewOnlyTier.test.js).
  // -----------------------------------------------------------------

  describe('rendered UI — order/client show pages', () => {
    function extractCsrf(html) {
      const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
      if (!m) throw new Error('CSRF token not found in response HTML');
      return m[1];
    }

    async function loginAs(roleName) {
      const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
      const email = `jest-adddoc-ui-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
      const password = 'JestAddDoc123!';
      const passwordHash = await bcrypt.hash(password, 10);
      await db.execute(
        `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
         VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
        { email, hash: passwordHash, role_id: role.id }
      );
      const agent = request.agent(app);
      const loginPage = await agent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
      expect(loginRes.status).toBe(302);
      expect(loginRes.headers.location).not.toBe('/login');
      return agent;
    }

    test('order show page renders the Additional Documents section', async () => {
      const clientId = await createTestClient();
      const orderId = await createTestOrder(clientId);
      const fileId = await createTestFile(orderId, null);
      await fileStoreRepository.markAsAdditionalDocument(fileId, 'Special inspection note.');
      const agent = await loginAs('Admin');

      const res = await agent.get(`/orders/${orderId}`);

      expect(res.status).toBe(200);
      expect(res.text).toContain('Additional Documents');
      expect(res.text).toContain('test-file.pdf');
      expect(res.text).toContain('Special inspection note.');
    });

    test('client show page renders the Additional Documents section', async () => {
      const clientId = await createTestClient();
      const fileId = await createTestFile(null, clientId);
      await fileStoreRepository.markAsAdditionalDocument(fileId, 'Standing NDA.');
      const agent = await loginAs('Admin');

      const res = await agent.get(`/clients/${clientId}`);

      expect(res.status).toBe(200);
      expect(res.text).toContain('Additional Documents');
      expect(res.text).toContain('Standing NDA.');
    });

    test('order and client show pages link to the removed-additional-documents view', async () => {
      const clientId = await createTestClient();
      const orderId = await createTestOrder(clientId);
      const agent = await loginAs('Admin');

      const orderRes = await agent.get(`/orders/${orderId}`);
      expect(orderRes.text).toContain(`/orders/${orderId}/additional-documents/removed`);

      const clientRes = await agent.get(`/clients/${clientId}`);
      expect(clientRes.text).toContain(`/clients/${clientId}/additional-documents/removed`);
    });

    test('order show page hides the Attach Document form for a view-only user', async () => {
      const clientId = await createTestClient();
      const orderId = await createTestOrder(clientId);
      const agent = await loginAs('Viewer / Auditor');

      const res = await agent.get(`/orders/${orderId}`);

      expect(res.status).toBe(200);
      expect(res.text).toContain('Additional Documents');
      expect(res.text).not.toContain('Attach Document');
    });
  });
});
