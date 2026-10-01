'use strict';

// documentGenerationService.markSuperseded() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// deliberately stubs out (see tests/support/mocks/puppeteerCoreStub.js) —
// mocked here instead, same pattern as documentRevisionConcurrency.test.js
// and caInternalDoc.test.js, so the supersede/invalidation logic under test
// resolves instantly instead of trying to launch a real browser.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * User-reported bug (2026-10-01): "if multiple documents get created, and
 * if anyone got approved, then all other are invalid, and should not be
 * approved, until new document get generated - only this newly generated
 * document may get approved, but if newly get document is approved, the
 * earlier approved document gets invalid and the watermark must contain
 * INVALID DOCUMENT". documents.status already had a 'superseded' value in
 * the schema but no code path ever set it — this covers the fix:
 * reviewWorkflowService.finalizeIfFullyApproved() now calls
 * documentGenerationService.supersedeOtherApprovedRevisions() right after
 * approving a document, and reviewWorkflowService.approve() refuses to
 * approve a review whose document is no longer the latest revision.
 */
describe('Document supersede/invalidation cascade', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocumentWithRevision(orderId, typeCode, status, revisionNumber, pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, revision_number, client_revision_number, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :rev, :rev, :status, :pdf_file_id)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, rev: revisionNumber, status, pdf_file_id: pdfFileId }
    );
    return result.insertId;
  }

  async function createTestFile(orderId) {
    const result = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'RECEIVED', :path, :uuid, 'test-file.pdf', 1024, 'application/pdf')`,
      { order_id: orderId, path: `/tmp/jest-test-file-${Math.random().toString(16).slice(2, 10)}.pdf`, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
    );
    return result.insertId;
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function setMinReviewers(documentId, count) {
    const doc = await db.queryOne('SELECT document_type_id FROM documents WHERE id = :id', { id: documentId });
    await db.execute('UPDATE document_types SET min_reviewers_default = :count WHERE id = :id', { count, id: doc.document_type_id });
  }

  it('approving a newer revision supersedes the previous approved revision', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const oldFileId = await createTestFile(orderId);
    const oldDocumentId = await createTestDocumentWithRevision(orderId, 'QT', 'approved', 0, oldFileId);

    const newDocumentId = await createTestDocumentWithRevision(orderId, 'QT', 'draft', 1);
    const reviewer = await createTestUser('Export Executive');
    await setMinReviewers(newDocumentId, 1);
    await reviewWorkflowService.assignReviewers(newDocumentId, [reviewer], 1);
    const [review] = await documentReviewRepository.forDocument(newDocumentId);

    await reviewWorkflowService.approve(review.id, reviewer, null);

    const oldDocument = await documentRepository.find(oldDocumentId);
    expect(oldDocument.status).toBe('superseded');
    expect(oldDocument.pdf_file_id).not.toBe(oldFileId);

    const newDocument = await documentRepository.find(newDocumentId);
    expect(newDocument.status).toBe('approved');
  });

  it('a sent revision is also superseded by a newer approval', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const sentFileId = await createTestFile(orderId);
    const sentDocumentId = await createTestDocumentWithRevision(orderId, 'PI', 'sent', 0, sentFileId);

    const newDocumentId = await createTestDocumentWithRevision(orderId, 'PI', 'draft', 1);
    const reviewer = await createTestUser('Export Executive');
    await setMinReviewers(newDocumentId, 1);
    await reviewWorkflowService.assignReviewers(newDocumentId, [reviewer], 1);
    const [review] = await documentReviewRepository.forDocument(newDocumentId);

    await reviewWorkflowService.approve(review.id, reviewer, null);

    expect((await documentRepository.find(sentDocumentId)).status).toBe('superseded');
  });

  it('cannot approve a stale revision once a newer one has been generated', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const staleDocumentId = await createTestDocumentWithRevision(orderId, 'QT', 'draft', 0);
    const reviewer = await createTestUser('Export Executive');
    await setMinReviewers(staleDocumentId, 1);
    await reviewWorkflowService.assignReviewers(staleDocumentId, [reviewer], 1);
    const [review] = await documentReviewRepository.forDocument(staleDocumentId);

    // A newer revision is generated while that review is still pending
    // (e.g. a mistake was found and fixed out of band before the reviewer
    // got to it).
    await createTestDocumentWithRevision(orderId, 'QT', 'draft', 1);

    await expect(reviewWorkflowService.approve(review.id, reviewer, null)).rejects.toThrow('newer revision');
  });

  it('approving the actual latest revision still works normally', async () => {
    const orderId = await createTestOrder(await createTestClient());

    // Only one revision exists — it IS the latest, so approval must not be
    // blocked by the new guard.
    const documentId = await createTestDocumentWithRevision(orderId, 'QT', 'draft', 0);
    const reviewer = await createTestUser('Export Executive');
    await setMinReviewers(documentId, 1);
    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    const [review] = await documentReviewRepository.forDocument(documentId);

    await reviewWorkflowService.approve(review.id, reviewer, null);

    expect((await documentRepository.find(documentId)).status).toBe('approved');
  });

  it('markSuperseded directly flips status and repoints the pdf', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const originalFileId = await createTestFile(orderId);
    const documentId = await createTestDocumentWithRevision(orderId, 'QT', 'approved', 0, originalFileId);

    await documentGenerationService.markSuperseded(documentId);

    const document = await documentRepository.find(documentId);
    expect(document.status).toBe('superseded');
    expect(document.pdf_file_id).not.toBeNull();
    expect(document.pdf_file_id).not.toBe(originalFileId);
  });
});
