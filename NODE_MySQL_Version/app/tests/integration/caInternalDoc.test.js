'use strict';

// documentGenerationService.generateCaInternalAnnexure() renders a real PDF
// via pdfRenderService (headless Chrome through puppeteer-core), which Jest
// deliberately stubs out (see tests/support/mocks/puppeteerCoreStub.js) —
// mocked here the same way documentRevisionConcurrency.test.js does, so
// these tests exercise the actual CAFIN generation/exclusion logic, not
// real PDF rendering.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const { Writable } = require('stream');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const caExpenseRepository = require('../../src/repositories/caExpenseRepository');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const caController = require('../../src/controllers/caController');
const documentController = require('../../src/controllers/documentController');
const ordersController = require('../../src/controllers/ordersController');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const fileStoreController = require('../../src/controllers/fileStoreController');
const emailDispatchController = require('../../src/controllers/emailDispatchController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of CaInternalDocTest.php. The CA internal-only "Financial Annexure"
 * (RODTEP/export benefits + expenses linked to an order, per
 * caOrderLinking.test.js) must be:
 *   - off by default for every order,
 *   - toggleable/generatable only by someone holding ca_internal_doc_manage
 *     (not ca_module_view/inr_actual_edit),
 *   - structurally impossible to surface on the client portal (category =
 *     'internal', same mechanism as SUPPO/BLI/COOPREP/AMD),
 *   - excluded from the general staff dossier ZIP (which needs only the
 *     much broader manage_orders permission), and
 *   - gated by view_cafin (owner decision 2026-10-28) on every download
 *     route and on the order page, so a staff member without it can't
 *     see or fetch it just by knowing/guessing its document or file id.
 */
describe('CA internal-only financial annexure (Point 2 follow-up)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-cafin-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode, status, pdfFileId) {
    const docType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :dt, :ref, :status, :pdf)`,
      { order_id: orderId, dt: docType.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status, pdf: pdfFileId }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  /** documentController.download()'s success path streams via fs.createReadStream(...).pipe(res) — needs a real Writable. */
  function fakeDownloadRes() {
    const res = new Writable({ write(chunk, enc, cb) { cb(); } });
    res.statusCode = 200;
    res.headers = {};
    res.status = function (code) { this.statusCode = code; return this; };
    res.send = function (body) { this.body = body; };
    res.setHeader = function (k, v) { this.headers[k] = v; };
    return res;
  }

  it('is disabled by default for a new order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    expect(!!order.ca_internal_doc_enabled).toBe(false);
  });

  it('setCaInternalDocEnabled toggles the flag both ways', async () => {
    const orderId = await createTestOrder(await createTestClient());

    await orderRepository.setCaInternalDocEnabled(orderId, true);
    expect(!!(await orderRepository.find(orderId)).ca_internal_doc_enabled).toBe(true);

    await orderRepository.setCaInternalDocEnabled(orderId, false);
    expect(!!(await orderRepository.find(orderId)).ca_internal_doc_enabled).toBe(false);
  });

  // Owner request (2026-10-27): CAFIN is available whenever it is wanted — no per-order "enable" step.
  it('generateCaInternalAnnexure needs no enable step', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    expect(!!(await orderRepository.find(orderId)).ca_internal_doc_enabled).toBe(false);

    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, userId);

    expect(result.document_reference).toContain('CAFIN');
    fs.unlinkSync((await fileStoreRepository.find(result.pdf_file_id)).server_path);
  });

  it('generateCaInternalAnnexure produces a real PDF and an internal-only document row', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setCaInternalDocEnabled(orderId, true);

    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-JCAFIN-1', 15000, '2026-06-01', 'INR', null, userId);
    const expenseId = await caExpenseRepository.insert('ZOHO-JCAFIN-1', 'ECGC insurance', null, 'ECGC', 8800, 'INR', '2026-06-01');
    await caExpenseRepository.linkToOrder(expenseId, orderId);

    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, userId);

    expect(result.document_reference).toContain('CAFIN');

    const document = await documentRepository.find(result.document_id);
    expect(document.document_type_code).toBe('CAFIN');
    expect(document.document_type_category).toBe('internal');

    const file = await fileStoreRepository.find(result.pdf_file_id);
    expect(!!file.internal_only).toBe(true);
    expect(fs.existsSync(file.server_path)).toBe(true);
    const pdfBytes = fs.readFileSync(file.server_path);
    expect(pdfBytes.slice(0, 4).toString()).toBe('%PDF');

    fs.unlinkSync(file.server_path);
  });

  it('the CAFIN document never appears in customerFacingForOrder even if marked sent', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setCaInternalDocEnabled(orderId, true);

    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, userId);
    const file = await fileStoreRepository.find(result.pdf_file_id);

    await db.execute("UPDATE documents SET status = 'sent' WHERE id = :id", { id: result.document_id });

    const customerFacing = await documentRepository.customerFacingForOrder(orderId);
    expect(customerFacing.map((d) => d.document_type_code)).not.toContain('CAFIN');

    fs.unlinkSync(file.server_path);
  });

  it('forOrderExcludingInternalCaDocs excludes the CAFIN file but keeps other files', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const ordinaryFileId = await createTestFile(orderId, null);
    const cafinFileId = await createTestFile(orderId, null);
    await createTestDocument(orderId, 'CAFIN', 'draft', cafinFileId);

    const all = await fileStoreRepository.forOrder(orderId);
    const allIds = all.map((f) => f.id);
    expect(allIds).toContain(ordinaryFileId);
    expect(allIds).toContain(cafinFileId);

    const excluding = await fileStoreRepository.forOrderExcludingInternalCaDocs(orderId);
    const excludingIds = excluding.map((f) => f.id);
    expect(excludingIds).toContain(ordinaryFileId);
    expect(excludingIds).not.toContain(cafinFileId);
  });

  it('toggleInternalDoc controller enables and audit-logs', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());

    const req = { params: { id: String(orderId) }, body: { enabled: '1' }, user: { id: userId }, session: {} };
    await caController.toggleInternalDoc(req, fakeRes());

    expect(!!(await orderRepository.find(orderId)).ca_internal_doc_enabled).toBe(true);

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_ENABLED' AND entity_id = :id",
      { id: orderId }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('toggleInternalDoc controller disables and audit-logs', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setCaInternalDocEnabled(orderId, true);

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await caController.toggleInternalDoc(req, fakeRes());

    expect(!!(await orderRepository.find(orderId)).ca_internal_doc_enabled).toBe(false);

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_DISABLED' AND entity_id = :id",
      { id: orderId }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('generateInternalDoc controller generates without an enable step', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await caController.generateInternalDoc(req, fakeRes());

    const document = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CAFIN');
    expect(document).not.toBeNull();
    fs.unlinkSync((await fileStoreRepository.find(document.pdf_file_id)).server_path);
  });

  it('generateInternalDoc controller generates and audit-logs when enabled', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setCaInternalDocEnabled(orderId, true);

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await caController.generateInternalDoc(req, fakeRes());

    const doc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CAFIN');
    expect(doc).not.toBeNull();

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_GENERATED' AND entity_id = :id",
      { id: orderId }
    );
    expect(parseInt(row.c, 10)).toBe(1);

    const file = await fileStoreRepository.find(doc.pdf_file_id);
    fs.unlinkSync(file.server_path);
  });

  it('document download refuses CAFIN without view_cafin', async () => {
    // Export Executive holds download_pdf (the route-level permission for
    // /documents/:id/download) but NOT view_cafin — proving the
    // CAFIN-specific in-controller check is what actually blocks this,
    // not the route's own broader gate. Owner decision (2026-10-28):
    // ca_module_view alone is no longer enough either.
    const userId = await createTestUser('Export Executive');
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setCaInternalDocEnabled(orderId, true);
    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, userId);

    const req = { params: { documentId: String(result.document_id) }, query: {}, permissions: { ca_module_view: true } };
    const res = fakeRes();
    await documentController.download(req, res);

    expect(res.statusCode).toBe(403);
    expect(String(res.body).toLowerCase()).toContain('do not have permission');

    const file = await fileStoreRepository.find(result.pdf_file_id);
    fs.unlinkSync(file.server_path);
  });

  it('document download allows CAFIN with view_cafin', async () => {
    // Accounts Executive holds view_cafin (but NOT
    // ca_internal_doc_manage) — proving download access only needs the
    // view permission, never the generate one.
    const adminId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.setCaInternalDocEnabled(orderId, true);
    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, adminId);

    const req = { params: { documentId: String(result.document_id) }, query: {}, user: { id: 1 }, permissions: { view_cafin: true, view_orders: true, view_all_orders: true } };
    const res = fakeDownloadRes();
    const finished = new Promise((resolve) => res.on('finish', resolve));
    await documentController.download(req, res);
    await finished;

    expect(res.statusCode).toBe(200);
    expect(res.headers['Content-Type']).toBe('application/pdf');

    const file = await fileStoreRepository.find(result.pdf_file_id);
    fs.unlinkSync(file.server_path);
  });

  it('order show page offers toggle and generate controls to a privileged user', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.canManageCaInternalDoc).toBe(true);
  });

  it('order show page hides the toggle from a non-privileged CA viewer', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const req = { params: { id: String(orderId) }, permissions: { ca_module_view: true, view_all_orders: true }, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.canViewCaLinks).toBe(true);
    expect(res.renderedData.canManageCaInternalDoc).toBe(false);
  });

  // Owner decision (2026-10-28): "View & download CAFIN" (view_cafin) decides
  // who sees the CAFIN box and CAFIN in the document lists.
  it('order show page hides the CAFIN box and the CAFIN document without view_cafin', async () => {
    const adminId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, adminId);

    const hidden = fakeRes();
    await ordersController.show({ params: { id: String(orderId) }, permissions: { ca_module_view: true, manage_order_financials: true, view_all_orders: true }, session: {} }, hidden);
    expect(hidden.renderedData.canViewCafin).toBe(false);
    expect(hidden.renderedData.caInternalDoc).toBeNull();
    expect(hidden.renderedData.documents.map((d) => d.document_type_code)).not.toContain('CAFIN');

    const shown = fakeRes();
    await ordersController.show({ params: { id: String(orderId) }, permissions: { view_cafin: true, view_all_orders: true }, session: {} }, shown);
    expect(shown.renderedData.canViewCafin).toBe(true);
    expect(shown.renderedData.caInternalDoc.document_reference).toBe(result.document_reference);
    expect(shown.renderedData.documents.map((d) => d.document_type_code)).toContain('CAFIN');

    fs.unlinkSync((await fileStoreRepository.find(result.pdf_file_id)).server_path);
  });

  it('the seed gives view_cafin to Admin, MD, ED, Accounts Executive and CA only', async () => {
    const rows = await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
       WHERE p.permission_key = 'view_cafin' AND rp.is_enabled = 1 ORDER BY r.name`
    );
    expect(rows.map((r) => r.name).sort()).toEqual(['Accounts Executive', 'Admin', 'CA / Chartered Accountant', 'Executive Director', 'Managing Director'].sort());
  });

  // QA Round 5, R5-21: /file-store/:id/download applies the same gates.
  describe('file-store download (QA Round 5, R5-21)', () => {
    async function fileDownload(fileId, user, permissions) {
      const res = fakeDownloadRes();
      const finished = new Promise((resolve) => res.on('finish', resolve));
      await fileStoreController.download({ params: { id: String(fileId) }, user, permissions }, res);
      if (res.statusCode === 200) await finished;
      return res;
    }

    it('refuses the CAFIN file without view_cafin and serves it with it', async () => {
      const adminId = await createTestUser('Admin');
      const orderId = await createTestOrder(await createTestClient());
      const result = await documentGenerationService.generateCaInternalAnnexure(orderId, adminId);

      const refused = await fileDownload(result.pdf_file_id, { id: adminId }, { view_orders: true, view_all_orders: true, ca_module_view: true });
      expect(refused.statusCode).toBe(403);

      const allowed = await fileDownload(result.pdf_file_id, { id: adminId }, { view_orders: true, view_all_orders: true, view_cafin: true });
      expect(allowed.statusCode).toBe(200);

      fs.unlinkSync((await fileStoreRepository.find(result.pdf_file_id)).server_path);
    });

    it('refuses a file of an order the user is not assigned to', async () => {
      const userId = await createTestUser('Export Executive');
      const orderId = await createTestOrder(await createTestClient());
      const fileId = await createTestFile(orderId, null);
      fs.writeFileSync((await fileStoreRepository.find(fileId)).server_path, '%PDF-test');

      const refused = await fileDownload(fileId, { id: userId }, { view_orders: true });
      expect(refused.statusCode).toBe(403);
      expect(String(refused.body)).toContain('assigned to a different user');

      await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: userId });
      const allowed = await fileDownload(fileId, { id: userId }, { view_orders: true });
      expect(allowed.statusCode).toBe(200);

      fs.unlinkSync((await fileStoreRepository.find(fileId)).server_path);
    });

    it('refuses a removed (inactive) file', async () => {
      const userId = await createTestUser('Admin');
      const orderId = await createTestOrder(await createTestClient());
      const fileId = await createTestFile(orderId, null);
      fs.writeFileSync((await fileStoreRepository.find(fileId)).server_path, '%PDF-test');
      await db.execute('UPDATE file_store SET is_active = 0 WHERE id = :id', { id: fileId });

      const res = await fileDownload(fileId, { id: userId }, { view_orders: true, view_all_orders: true });
      expect(res.statusCode).toBe(404);

      fs.unlinkSync((await fileStoreRepository.find(fileId)).server_path);
    });
  });

  // QA Round 5, R5-23: an internal document's send screen is never offered.
  it('the email compose screen refuses CAFIN', async () => {
    const adminId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, adminId);

    const req = { params: { id: String(orderId), documentId: String(result.document_id) }, query: {}, user: { id: adminId }, permissions: {}, session: {} };
    const res = fakeRes();
    await emailDispatchController.compose(req, res);

    expect(res.renderedView).toBeUndefined();
    expect(res.redirectedTo).toBe(`/orders/${orderId}`);
    expect(flash.pull(req)[0].message).toContain('internal document and can never be sent to the buyer');

    fs.unlinkSync((await fileStoreRepository.find(result.pdf_file_id)).server_path);
  });
});
