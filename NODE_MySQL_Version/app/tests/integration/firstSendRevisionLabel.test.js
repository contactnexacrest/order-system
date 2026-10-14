'use strict';

// documentGenerationService.finalizeApproval()/markSuperseded() render a
// real PDF via pdfRenderService (headless Chrome through puppeteer-core),
// which Jest deliberately stubs out (see
// tests/support/mocks/puppeteerCoreStub.js) — mocked here instead, same
// pattern as documentSupersede.test.js, so the filename/label logic under
// test resolves instantly instead of trying to launch a real browser.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of FirstSendRevisionLabelTest.php. 90-item backlog #44: the email
 * attachment a buyer receives (and the same file's client-portal download
 * name — both read file_store.original_filename) literally contained
 * "(approved)", and showed "Rev.0" / "Rev.00" even on a document's very
 * first-ever send — confusing wording for a buyer seeing a document for
 * the first time, since there is nothing before it to be "revision 0" of.
 * From the second client-facing issue onward (client_revision_number >= 1)
 * nothing changes — this is a genuine revision and must still say so.
 */
describe('First-ever-send document labeling (90-item backlog #44)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocumentWithRevision(orderId, typeCode, status, clientRevisionNumber) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, client_revision_number)
       VALUES (:order_id, :type_id, :ref, :status, :rev)`,
      {
        order_id: orderId,
        type_id: type.id,
        ref: `JEST-REVLABEL-${Math.random().toString(16).slice(2, 10)}`,
        status,
        rev: clientRevisionNumber,
      }
    );
    return result.insertId;
  }

  async function approvedFilenameFor(documentId) {
    await documentGenerationService.finalizeApproval(documentId);
    const document = await documentRepository.find(documentId);
    const file = await fileStoreRepository.find(document.pdf_file_id);
    return file.original_filename;
  }

  it('first-ever send filename has no "(approved)" tag and no Rev.0', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocumentWithRevision(orderId, 'QT', 'draft', 0);

    const filename = await approvedFilenameFor(documentId);

    expect(filename).not.toContain('(approved)');
    expect(filename).not.toContain('Rev.0');
  });

  it('a second send still shows its real revision number', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocumentWithRevision(orderId, 'QT', 'draft', 1);

    const filename = await approvedFilenameFor(documentId);

    expect(filename).not.toContain('(approved)');
    expect(filename).toContain('Rev.01');
  });

  it('markSuperseded on a revision-0 document renders without error', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocumentWithRevision(orderId, 'QT', 'approved', 0);

    await documentGenerationService.markSuperseded(documentId);

    const document = await documentRepository.find(documentId);
    expect(document.status).toBe('superseded');
  });
});
