'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientsController = require('../../src/controllers/clientsController');
const clientRepository = require('../../src/repositories/clientRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

function fakeRes() {
  const res = { statusCode: null, body: null, redirectedTo: null };
  res.status = (code) => { res.statusCode = code; return res; };
  res.send = (body) => { res.body = body; };
  res.redirect = (url) => { res.redirectedTo = url; };
  res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
  return res;
}

function fakeReq(userId, body = {}, params = {}) {
  return { session: {}, user: { id: userId }, body, params };
}

const tempFiles = [];

/** Unlike tests/support/fixtures.js's createTestFile(), this writes a real file at server_path too, so a "downloads are visible" case can reach an actual 200 instead of 404-ing on "file missing from storage". */
async function createSentDocumentWithRealPdf(orderId) {
  const docType = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
  const filePath = path.join(os.tmpdir(), `jest-m02-test-file-${Math.random().toString(16).slice(2, 10)}.pdf`);
  fs.writeFileSync(filePath, '%PDF-1.4 test');
  tempFiles.push(filePath);

  const fileResult = await db.execute(
    `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
     VALUES (:order_id, 'RECEIVED', :path, :uuid, 'test-file.pdf', 13, 'application/pdf')`,
    { order_id: orderId, path: filePath, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
  );

  const docResult = await db.execute(
    `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
     VALUES (:order_id, :dt, 'SC/QT/TEST/001', 'sent', :pdf)`,
    { order_id: orderId, dt: docType.id, pdf: fileResult.insertId }
  );
  return docResult.insertId;
}

/**
 * External QA report M-02: "Portal visibility of history/downloads
 * configurable globally and per client, independently." The order-
 * history half of this already existed (see
 * clientOrderHistoryVisibility.test.js) — this covers the document-
 * downloads half added to close the gap: a global default
 * (company_settings.client_portal_show_document_downloads) with a
 * per-client override (clients.document_downloads_visibility_override,
 * null = inherit), gated both on the page (the Documents section) and
 * server-side (downloadDocument() itself, so the link being hidden
 * isn't the only thing stopping a direct URL guess/replay). Port of
 * PHP's ClientDocumentDownloadsVisibilityTest.
 */
describe('Client document downloads visibility (External QA report M-02)', () => {
  let adminUserId;

  beforeAll(async () => {
    const admin = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });
    adminUserId = admin.id;
  });

  afterAll(async () => {
    for (const filePath of tempFiles) {
      if (fs.existsSync(filePath)) fs.unlinkSync(filePath);
    }
    await db.pool.end();
  });

  it('override null follows the global default', async () => {
    const clientId = await createTestClient();

    await companySettingsRepository.set('client_portal_show_document_downloads', '1');
    expect(await clientRepository.documentDownloadsVisible(await clientRepository.find(clientId))).toBe(true);

    await companySettingsRepository.set('client_portal_show_document_downloads', '0');
    expect(await clientRepository.documentDownloadsVisible(await clientRepository.find(clientId))).toBe(false);
  });

  it('per-client override wins over the global default', async () => {
    const clientId = await createTestClient();

    await companySettingsRepository.set('client_portal_show_document_downloads', '0');
    await clientRepository.setDocumentDownloadsVisibilityOverride(clientId, true);
    expect(await clientRepository.documentDownloadsVisible(await clientRepository.find(clientId))).toBe(true);

    await companySettingsRepository.set('client_portal_show_document_downloads', '1');
    await clientRepository.setDocumentDownloadsVisibilityOverride(clientId, false);
    expect(await clientRepository.documentDownloadsVisible(await clientRepository.find(clientId))).toBe(false);
  });

  it('order page hides the Documents section when downloads are hidden, and the direct download URL is refused server-side too', async () => {
    const email = `jest-m02-hidden-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const password = 'JestM02Test123!';
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, password);
    const orderId = await createTestOrder(clientId);
    const documentId = await createSentDocumentWithRealPdf(orderId);
    await clientRepository.setDocumentDownloadsVisibilityOverride(clientId, false);

    const agent = request.agent(app);
    const loginPage = await agent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    await agent.post('/client/login').type('form').send({ _csrf: csrf, email, password });

    const orderPage = await agent.get(`/client/orders/${orderId}`);
    expect(orderPage.text).not.toContain('Download PDF');

    const downloadRes = await agent.get(`/client/documents/${documentId}/download`);
    expect(downloadRes.status).toBe(404);
  });

  it('order page shows the Documents section and the download actually works when downloads are visible', async () => {
    const email = `jest-m02-visible-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const password = 'JestM02Test123!';
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, password);
    const orderId = await createTestOrder(clientId);
    const documentId = await createSentDocumentWithRealPdf(orderId);
    await clientRepository.setDocumentDownloadsVisibilityOverride(clientId, true);

    const agent = request.agent(app);
    const loginPage = await agent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    await agent.post('/client/login').type('form').send({ _csrf: csrf, email, password });

    const orderPage = await agent.get(`/client/orders/${orderId}`);
    expect(orderPage.text).toContain('Download PDF');

    const downloadRes = await agent.get(`/client/documents/${documentId}/download`);
    expect(downloadRes.status).toBe(200);
  });

  it('setDocumentDownloadsVisibilityOverride 404s for a missing client', async () => {
    const res = fakeRes();
    await clientsController.setDocumentDownloadsVisibilityOverride(fakeReq(adminUserId, { document_downloads_visibility_override: '1' }, { id: '999999999' }), res);

    expect(res.statusCode).toBe(404);
  });

  it('setDocumentDownloadsVisibilityOverride writes the value and an audit log entry', async () => {
    const clientId = await createTestClient();
    const res = fakeRes();
    await clientsController.setDocumentDownloadsVisibilityOverride(fakeReq(adminUserId, { document_downloads_visibility_override: '0' }, { id: String(clientId) }), res);

    const client = await clientRepository.find(clientId);
    expect(Number(client.document_downloads_visibility_override)).toBe(0);

    const log = await db.queryOne(
      "SELECT * FROM audit_log WHERE action_type = 'CLIENT_DOCUMENT_DOWNLOADS_VISIBILITY_CHANGED' AND entity_type = 'clients' AND entity_id = :id",
      { id: clientId }
    );
    expect(log).toBeTruthy();
  });

  it('setDocumentDownloadsVisibilityOverride clears back to inherit on an empty post', async () => {
    const clientId = await createTestClient();
    await clientRepository.setDocumentDownloadsVisibilityOverride(clientId, true);

    const res = fakeRes();
    await clientsController.setDocumentDownloadsVisibilityOverride(fakeReq(adminUserId, { document_downloads_visibility_override: '' }, { id: String(clientId) }), res);

    const client = await clientRepository.find(clientId);
    expect(client.document_downloads_visibility_override).toBeNull();
  });
});
