'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../../src/config/db');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

// QA Round 6, R6-N16: clients.email is unique, and Jest runs files in
// parallel on one database — this file's fixed test emails are its own.
const JANE_EMAIL = `jane-${process.pid}-${Date.now()}@buyer.example`;

/**
 * Port of EmailApprovalPreviewTest.php. The Email Send Approvals screen
 * used to show only Order/To/Subject/Status/Scheduled/Requested/Action —
 * an approver had no way to see the actual email body or the attached PDF
 * before deciding to Approve or Reject. email_log.body_snapshot and the
 * document behind document_id were always captured at request time
 * (Level 1); this covers the approvals screen now actually surfacing
 * both, so "approve" is an informed decision rather than a blind one.
 */
describe('email approvals screen — exact preview + attachment link', () => {
  beforeEach(async () => {
    await db.execute(`UPDATE clients SET email = NULL WHERE email IN (${[JANE_EMAIL].map((e, i) => `:e${i}`).join(', ')})`, Object.fromEntries([JANE_EMAIL].map((e, i) => [`e${i}`, e])));
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocument(orderId, typeCode, status, pdfFileId) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-EMLPREVIEW-${Math.random().toString(16).slice(2, 10)}`, status, pdf_file_id: pdfFileId }
    );
    return result.insertId;
  }

  async function createTestFile(orderId) {
    const result = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', :path, :uuid, 'test-file.pdf', 1024, 'application/pdf')`,
      { order_id: orderId, path: `/tmp/jest-emlpreview-file-${Math.random().toString(16).slice(2, 10)}.pdf`, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
    );
    return result.insertId;
  }

  async function setClientEmail(clientId) {
    await db.execute('UPDATE clients SET email = :email, contact_person = :contact WHERE id = :id', {
      email: JANE_EMAIL,
      contact: 'Jane Buyer',
      id: clientId,
    });
  }

  function renderApprovals(pending, permissions) {
    const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../views')));
    return env.render('email/approvals.njk', { pending, permissions, csrfToken: 'test-token' });
  }

  it('shows the exact body and attachment link when the viewer can download PDFs', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);
    const pdfFileId = await createTestFile(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', pdfFileId);

    await emailDispatchService.requestSend(orderId, documentId, 'send_qt', null, 1);

    const pending = await emailLogRepository.awaitingDispatch();
    const row = pending.find((r) => r.document_id === documentId);
    expect(row).toBeTruthy();
    expect(row.document_type_code).toBe('QT');
    expect(row.body_snapshot).toContain('Please find attached our Quotation');

    const html = renderApprovals(pending, { download_pdf: true });

    expect(html).toContain('Exact Preview');
    expect(html).toContain('Please find attached our Quotation');
    expect(html).toContain(`/documents/${documentId}/download`);
    expect(html).toContain('View attachment');
  });

  it('hides the attachment link without download_pdf permission', async () => {
    const clientId = await createTestClient();
    await setClientEmail(clientId);
    const orderId = await createTestOrder(clientId);
    const pdfFileId = await createTestFile(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', pdfFileId);

    await emailDispatchService.requestSend(orderId, documentId, 'send_qt', null, 1);

    const pending = await emailLogRepository.awaitingDispatch();
    const html = renderApprovals(pending, {});

    expect(html).not.toContain(`/documents/${documentId}/download`);
    expect(html).toContain("you don't have permission to open it");
  });
});
