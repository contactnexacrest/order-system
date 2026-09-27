'use strict';

const db = require('../../src/config/db');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * QA-5 (GATE-01 — external QA report cross-verification, Owner Decision #1:
 * "No stage passes until its document is approved"): Stage 1's real gate is
 * the QT reaching document_reviews-approved status, not merely being
 * generated as a draft. Before this fix, documentController.generate()
 * passed Stage 1 the instant a QT draft was rendered — a staff member could
 * generate a QT and immediately unlock Stage 2 (Buyer PO) with the
 * quotation still unreviewed, or even rejected moments later, since
 * rejection sends the document back to draft without ever re-locking Stage
 * 1.
 */
describe('Stage 1 gate — QT approval, not generation (QA-5 GATE-01)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft', pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status, pdf_file_id: pdfFileId }
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

  async function stageStatus(orderId, stageNumber) {
    const stage = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber);
    return stage.status;
  }

  it('stays locked while the QT is still an unreviewed draft', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'QT', 'draft');

    expect(await stageStatus(orderId, 1)).toBe('in_progress');
    expect(await stageGateService.isUnlocked(orderId, 2)).toBe(false);
  });

  it('passes only once the QT is fully approved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    const reviewer = await createTestUser('Export Executive');

    // finalizeApproval() renders a real PDF via puppeteer-core, which is
    // deliberately stubbed out under Jest — stub just that heavy side
    // effect so the real gate logic above it is still exercised for real.
    const fileId = await createTestFile(orderId, null);
    const finalizeSpy = jest.spyOn(documentGenerationService, 'finalizeApproval').mockImplementation(async (docId) => {
      await documentRepository.markApproved(docId, fileId);
    });

    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    const review = (await documentReviewRepository.forDocument(documentId))[0];
    expect(await stageStatus(orderId, 1)).toBe('in_progress');

    await reviewWorkflowService.approve(review.id, reviewer, null);

    expect(await stageStatus(orderId, 1)).toBe('gate_passed');
    expect(await stageGateService.isUnlocked(orderId, 2)).toBe(true);
    finalizeSpy.mockRestore();
  });

  it('remains locked when one of two required reviewers is still pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    await setMinReviewers(documentId, 2);
    const reviewer1 = await createTestUser('Export Executive');
    const reviewer2 = await createTestUser('Export Executive');

    await reviewWorkflowService.assignReviewers(documentId, [reviewer1, reviewer2], 1);
    const reviews = await documentReviewRepository.forDocument(documentId);
    await reviewWorkflowService.approve(reviews[0].id, reviews[0].reviewer_id, null);

    expect(await stageStatus(orderId, 1)).toBe('in_progress');
  });

  it('a rejected QT is never used to pass Stage 1 early', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
    const reviewer = await createTestUser('Export Executive');

    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    const review = (await documentReviewRepository.forDocument(documentId))[0];
    await reviewWorkflowService.reject(review.id, reviewer, 'Jest test rejection — needs corrections');

    expect(await stageStatus(orderId, 1)).toBe('in_progress');
  });
});
