'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const db = require('../../src/config/db');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const amendmentService = require('../../src/services/amendmentService');
const documentRepository = require('../../src/repositories/documentRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const mailSenderService = require('../../src/services/mailSenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-4 P0.4 (docs/QA/TEST_PLAN.md Section 6): "an amendment cannot activate
 * without MD approval; a document cannot be marked sent without passing
 * through document_reviews; an email cannot dispatch without
 * approve_email_send approval where required."
 */
describe('Amendment / document-review / email-approval gates (QA-4 P0.4)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestAmendment(orderId) {
    return amendmentRepository.create(
      `AMD-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId,
      'Jest test amendment',
      'importer',
      { note: 'snapshot' },
      null, null, null, null, null, null, null
    );
  }

  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft', pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status, pdf_file_id: pdfFileId }
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

  /** Same as createTestFile(), but writes real bytes to disk so fs.existsSync() checks pass. */
  async function createTestFileOnDisk(orderId) {
    const filePath = path.join(os.tmpdir(), `jest-dispatch-test-${Math.random().toString(16).slice(2, 10)}.pdf`);
    fs.writeFileSync(filePath, 'fake pdf bytes');
    const result = await db.execute(
      `INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
       VALUES (:order_id, 'GENERATED_AUTO', :path, :uuid, 'test.pdf', 14, 'application/pdf')`,
      { order_id: orderId, path: filePath, uuid: `${Math.random().toString(16).slice(2)}.pdf` }
    );
    return { fileId: result.insertId, filePath };
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

  async function setClientEmail(clientId, email) {
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email, id: clientId });
  }

  // ---------------------------------------------------------------
  // Gate 1: an amendment cannot activate without MD approval.
  // ---------------------------------------------------------------

  it('generateDocument refuses a pending amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);

    await expect(amendmentService.generateDocument(amendmentId, 1)).rejects.toThrow('must be MD-approved');
  });

  it('attachSignedCopyAndActivate refuses a pending amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);
    const fileId = await createTestFile(orderId);

    await expect(amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1)).rejects.toThrow('MD-approved first');
  });

  it('attachSignedCopyAndActivate refuses when no document has been generated yet', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);
    await amendmentService.approveByMd(amendmentId, 1);
    const fileId = await createTestFile(orderId);

    await expect(amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1)).rejects.toThrow('Generate the Amendment Agreement document');
  });

  it('approveByMd refuses an already-approved amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);
    await amendmentService.approveByMd(amendmentId, 1);

    await expect(amendmentService.approveByMd(amendmentId, 1)).rejects.toThrow('Only a pending amendment');
  });

  it('the full path activates only after MD approval and document generation', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const amendmentId = await createTestAmendment(orderId);

    await amendmentService.approveByMd(amendmentId, 1);
    expect((await amendmentRepository.find(amendmentId)).status).toBe('md_approved');

    const documentId = await createTestDocument(orderId, 'QT');
    await amendmentRepository.attachDocument(amendmentId, documentId);
    const fileId = await createTestFile(orderId);

    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1);

    const amendment = await amendmentRepository.find(amendmentId);
    expect(amendment.status).toBe('active');
    expect(amendment.signed_copy_file_id).toBe(fileId);
  });

  // ---------------------------------------------------------------
  // Gate 2: a document cannot be marked sent (or approved) without
  // passing through document_reviews.
  // ---------------------------------------------------------------

  it('does not auto-approve while a reviewer is still pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    const reviewer1 = await createTestUser('Export Executive');
    const reviewer2 = await createTestUser('Export Executive');
    await setMinReviewers(documentId, 2);

    await reviewWorkflowService.assignReviewers(documentId, [reviewer1, reviewer2], 1);
    const [review1] = await documentReviewRepository.forDocument(documentId);
    await reviewWorkflowService.approve(review1.id, reviewer1, null);

    expect((await documentRepository.find(documentId)).status).toBe('in_review');
  });

  it('approves only once every required reviewer approves', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    const reviewer1 = await createTestUser('Export Executive');
    const reviewer2 = await createTestUser('Export Executive');
    await setMinReviewers(documentId, 2);

    // finalizeIfFullyApproved()'s real job is deciding WHEN to call
    // documentGenerationService.finalizeApproval() — the actual PDF render
    // it triggers is out of scope here (and puppeteer-core is deliberately
    // stubbed to throw under Jest; see jest.config.js), so this test stubs
    // just that one heavy side effect while exercising the real threshold
    // logic that decides whether to call it at all.
    const { fileId } = await createTestFileOnDisk(orderId);
    const finalizeSpy = jest.spyOn(documentGenerationService, 'finalizeApproval').mockImplementation(async (docId) => {
      await documentRepository.markApproved(docId, fileId);
    });

    await reviewWorkflowService.assignReviewers(documentId, [reviewer1, reviewer2], 1);
    const reviews = await documentReviewRepository.forDocument(documentId);
    await reviewWorkflowService.approve(reviews[0].id, reviews[0].reviewer_id, null);
    expect(finalizeSpy).not.toHaveBeenCalled();
    await reviewWorkflowService.approve(reviews[1].id, reviews[1].reviewer_id, null);
    expect(finalizeSpy).toHaveBeenCalledTimes(1);
    finalizeSpy.mockRestore();

    const document = await documentRepository.find(documentId);
    expect(document.status).toBe('approved');
    expect(document.pdf_file_id).not.toBeNull();
  });

  it('a rejection sends the document back to draft rather than approving', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    const reviewer1 = await createTestUser('Export Executive');
    const reviewer2 = await createTestUser('Export Executive');
    await setMinReviewers(documentId, 2);

    await reviewWorkflowService.assignReviewers(documentId, [reviewer1, reviewer2], 1);
    const reviews = await documentReviewRepository.forDocument(documentId);
    await reviewWorkflowService.approve(reviews[0].id, reviews[0].reviewer_id, null);
    await reviewWorkflowService.reject(reviews[1].id, reviews[1].reviewer_id, 'Jest test rejection reason');

    expect((await documentRepository.find(documentId)).status).toBe('draft');
  });

  it('buildPreview refuses a non-approved document', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await setClientEmail(clientId, 'buyer@example.test');
    const documentId = await createTestDocument(orderId, 'QT', 'in_review');

    await expect(emailDispatchService.buildPreview(orderId, documentId, 'send_qt', 1)).rejects.toThrow('must clear internal review first');
  });

  // ---------------------------------------------------------------
  // Gate 3: an approved-then-later-reverted document must never
  // actually dispatch a stale send (the QA-4 fix to dispatch()).
  // ---------------------------------------------------------------

  it('refuses and marks failed when the document was reverted to draft after the send was queued', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await setClientEmail(clientId, 'buyer@example.test');
    const { fileId } = await createTestFileOnDisk(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);

    // A new reviewer is assigned to the already-approved document and
    // rejects it — reviewWorkflowService.reject() unconditionally reverts
    // status to 'draft', regardless of the document's current status,
    // without touching pdf_file_id or looking at any pending send already
    // queued against it.
    const reviewer = await createTestUser('Export Executive');
    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    const [review] = await documentReviewRepository.forDocument(documentId);
    await reviewWorkflowService.reject(review.id, reviewer, 'Jest: found an error after approval');
    expect((await documentRepository.find(documentId)).status).toBe('draft');

    const sendSpy = jest.spyOn(mailSenderService, 'send');
    const result = await emailDispatchService.dispatch(await emailLogRepository.find(emailLogId));

    // Assert before mockRestore() — restoring clears the recorded call
    // history (mockRestore() implies mockReset()), so asserting after it
    // would trivially pass even if this regression test's own fix were
    // reverted.
    expect(result).toBe(false);
    expect(sendSpy).not.toHaveBeenCalled();
    sendSpy.mockRestore();

    expect((await emailLogRepository.find(emailLogId)).status).toBe('failed');
  });

  it('still attempts to send a genuinely approved document', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await setClientEmail(clientId, 'buyer@example.test');
    const { fileId } = await createTestFileOnDisk(orderId);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);

    const sendSpy = jest.spyOn(mailSenderService, 'send').mockResolvedValue(false);
    await emailDispatchService.dispatch(await emailLogRepository.find(emailLogId));

    // Proves my fix's status re-check does not also block a genuinely
    // still-approved document — it must reach the actual send attempt.
    // (Asserted before mockRestore(), which clears call history.)
    expect(sendSpy).toHaveBeenCalledTimes(1);
    sendSpy.mockRestore();
  });
});
