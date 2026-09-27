'use strict';

const db = require('../../src/config/db');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const userRepository = require('../../src/repositories/userRepository');
const amendmentService = require('../../src/services/amendmentService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const makerCheckerGuard = require('../../src/services/makerCheckerGuard');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * QA-5 (external QA report cross-verification): maker-checker separation
 * — the person who created/requested an item must not also be the one who
 * approves it, EXCEPT a Super Admin or anyone holding manage_permissions
 * (owner decision: that tier can already grant itself any approval role
 * through the permission system, so enforcing separation on them isn't a
 * real control). Covers REV-05 (document self-review), EML-03 (email
 * self-approval), AMD-05 (amendment self MD-approval), plus REV-06
 * (inactive reviewer) and REV-07 (duplicate reviewer assignment stalls the
 * document).
 */
describe('Maker-checker guard (QA-5)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function makeSuperAdmin(userId) {
    await db.execute('UPDATE users SET is_super_admin = 1 WHERE id = :id', { id: userId });
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

  async function createTestDocumentGeneratedBy(orderId, generatedBy) {
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, generated_by)
       VALUES (:order_id, :type_id, :ref, 'draft', :generated_by)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, generated_by: generatedBy }
    );
    return result.insertId;
  }

  async function createTestAmendment(orderId, createdBy) {
    return amendmentRepository.create(
      `AMD-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId,
      'Jest maker-checker amendment',
      'importer',
      { note: 'snapshot' },
      null, null, null, null, null, null, null,
      createdBy
    );
  }

  // ---------------------------------------------------------------
  // makerCheckerGuard itself
  // ---------------------------------------------------------------

  it('self-approval is allowed for a Super Admin but not an ordinary user', async () => {
    const ordinary = await createTestUser('Export Executive');
    expect(await makerCheckerGuard.selfApprovalAllowed(ordinary)).toBe(false);

    await makeSuperAdmin(ordinary);
    expect(await makerCheckerGuard.selfApprovalAllowed(ordinary)).toBe(true);
  });

  it('self-approval is allowed for a manage_permissions holder who is not a Super Admin', async () => {
    // Admin role holds every permission (including manage_permissions) via
    // seed.sql's wildcard grant, but createTestUser() never sets
    // is_super_admin — this exercises the "not Super Admin, but holds
    // manage_permissions" branch specifically.
    const adminRoleUser = await createTestUser('Admin');
    expect(await makerCheckerGuard.selfApprovalAllowed(adminRoleUser)).toBe(true);
  });

  // ---------------------------------------------------------------
  // REV-05 — document review self-approval
  // ---------------------------------------------------------------

  it('cannot assign the document\'s own generator as its reviewer', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const generator = await createTestUser('Export Executive');
    const documentId = await createTestDocumentGeneratedBy(orderId, generator);

    await expect(reviewWorkflowService.assignReviewers(documentId, [generator], 1)).rejects.toThrow('own generator cannot be assigned');
  });

  it('a Super Admin generator can review their own document', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const generator = await createTestUser('Export Executive');
    await makeSuperAdmin(generator);
    const documentId = await createTestDocumentGeneratedBy(orderId, generator);

    await reviewWorkflowService.assignReviewers(documentId, [generator], 1);

    const reviews = await documentReviewRepository.forDocument(documentId);
    expect(reviews).toHaveLength(1);
    expect(Number(reviews[0].reviewer_id)).toBe(generator);
  });

  // ---------------------------------------------------------------
  // REV-06 — inactive reviewer
  // ---------------------------------------------------------------

  it('an inactive user cannot be assigned as a reviewer', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT');
    const inactive = await createTestUser('Export Executive');
    await userRepository.setActive(inactive, false);

    await expect(reviewWorkflowService.assignReviewers(documentId, [inactive], 1)).rejects.toThrow('not an active user');
  });

  // ---------------------------------------------------------------
  // REV-07 — duplicate reviewer assignment
  // ---------------------------------------------------------------

  it('assigning the same reviewer twice does not create a second row', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT');
    const reviewer = await createTestUser('Export Executive');

    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);

    expect(await documentReviewRepository.forDocument(documentId)).toHaveLength(1);
  });

  it('the document still reaches approved after a duplicate assignment attempt', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT');
    const reviewer = await createTestUser('Export Executive');
    // document_types.min_reviewers_default is a single shared row other
    // integration test files in this same Jest process also mutate — pin
    // it back to 1 so this test's single-reviewer approval is
    // deterministic regardless of run order.
    await db.execute("UPDATE document_types SET min_reviewers_default = 1 WHERE code = 'QT'");
    // finalizeApproval() renders a real PDF via puppeteer-core, which is
    // deliberately stubbed out under Jest — stub just that heavy side
    // effect so the real threshold-decision logic above it is still
    // exercised for real.
    const finalizeSpy = jest.spyOn(documentGenerationService, 'finalizeApproval').mockImplementation(async (docId) => {
      await documentRepository.markApproved(docId, fileId);
    });
    const fileId = await createTestFile(orderId, null);

    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1); // duplicate, silently ignored

    const review = (await documentReviewRepository.forDocument(documentId))[0];
    await reviewWorkflowService.approve(review.id, reviewer, null);

    expect((await documentRepository.find(documentId)).status).toBe('approved');
    finalizeSpy.mockRestore();
  });

  // ---------------------------------------------------------------
  // EML-03 — email send self-approval
  // ---------------------------------------------------------------

  it('the requester cannot approve their own email send', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE clients SET email = :e WHERE id = :id', { e: 'buyer@example.test', id: clientId });
    const fileId = await createTestFile(orderId, null);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const requester = await createTestUser('Export Executive');
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, requester);

    await expect(emailDispatchService.approveSend(emailLogId, requester)).rejects.toThrow('a different privileged user must approve');
  });

  it('a Super Admin can approve their own email send', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE clients SET email = :e WHERE id = :id', { e: 'buyer@example.test', id: clientId });
    const fileId = await createTestFile(orderId, null);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const requester = await createTestUser('Export Executive');
    await makeSuperAdmin(requester);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, requester);

    await emailDispatchService.approveSend(emailLogId, requester);

    expect((await emailLogRepository.find(emailLogId)).status).toBe('approved');
  });

  // ---------------------------------------------------------------
  // AMD-05 — amendment self MD-approval
  // ---------------------------------------------------------------

  it('the requester cannot MD-approve their own amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Export Executive');
    const amendmentId = await createTestAmendment(orderId, requester);

    await expect(amendmentService.approveByMd(amendmentId, requester)).rejects.toThrow('a different privileged user must MD-approve');
  });

  it('a Super Admin can MD-approve their own amendment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Export Executive');
    await makeSuperAdmin(requester);
    const amendmentId = await createTestAmendment(orderId, requester);

    await amendmentService.approveByMd(amendmentId, requester);

    expect((await amendmentRepository.find(amendmentId)).status).toBe('md_approved');
  });

  it('an uninvolved user can still MD-approve normally', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Export Executive');
    const md = await createTestUser('Export Executive');
    const amendmentId = await createTestAmendment(orderId, requester);

    await amendmentService.approveByMd(amendmentId, md);

    expect((await amendmentRepository.find(amendmentId)).status).toBe('md_approved');
  });
});
