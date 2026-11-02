'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
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

// QA Round 6, R6-N16: clients.email is unique, and Jest runs files in
// parallel on one database — this file's fixed test emails are its own.
const BUYER_EMAIL = `buyer-${process.pid}-${Date.now()}@example.test`;

/**
 * QA-5 (external QA report cross-verification): maker-checker separation
 * — the person who created/requested an item must not also be the one who
 * approves it, unless their role is ticked in Admin → Self-approval rules
 * (D-221; QA Round 8, R8-N09: no Super Admin or manage_permissions blanket
 * exemption; default MD and ED on every rule). Covers REV-05 (document self-review), EML-03 (email
 * self-approval), AMD-05 (amendment self MD-approval), plus REV-06
 * (inactive reviewer) and REV-07 (duplicate reviewer assignment stalls the
 * document).
 */
describe('Maker-checker guard (QA-5)', () => {
  beforeEach(async () => {
    await db.execute(`UPDATE clients SET email = NULL WHERE email IN (${[BUYER_EMAIL].map((e, i) => `:e${i}`).join(', ')})`, Object.fromEntries([BUYER_EMAIL].map((e, i) => [`e${i}`, e])));
  });

  // These approvals are asserted to stay 'approved' — the email-on
  // behaviour. With mail_sending_enabled off (the seed default since
  // 2026-10-25) an approved buyer-sendable document is marked 'sent'
  // instead; that path is covered by emailOffAutoSent.test.js.
  let originalMailSendingEnabled;
  beforeAll(async () => {
    originalMailSendingEnabled = await companySettingsRepository.get('mail_sending_enabled');
    await companySettingsRepository.set('mail_sending_enabled', '1');
  });

  afterAll(async () => {
    await companySettingsRepository.set('mail_sending_enabled', originalMailSendingEnabled);
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

  // QA Round 8, R8-N09: one choke point, seven rules, default MD and ED, no blanket exemption.
  it('every rule defaults to MD and ED with no blanket exemption', async () => {
    const rules = ['payment_terms', 'document_review', 'email_send', 'cancellation', 'purchase_award', 'amendment', 'short_payment', 'price_discount'];
    expect(Object.keys(require('../../src/services/selfApprovalRules').RULES)).toEqual(rules);
    const md = await createTestUser('Managing Director');
    const ed = await createTestUser('Executive Director');
    const admin = await createTestUser('Admin');
    const sysadmin = await createTestUser('System Administrator');
    const superExporter = await createTestUser('Export Executive');
    await makeSuperAdmin(superExporter);
    // The four rules added in QA Round 8 (the first three are unticked and re-ticked by
    // selfApprovalRules.test.js, which runs in parallel on the same database).
    for (const rule of rules.slice(3)) {
      expect([rule, await makerCheckerGuard.selfApprovalAllowed(rule, md)]).toEqual([rule, true]);
      expect([rule, await makerCheckerGuard.selfApprovalAllowed(rule, ed)]).toEqual([rule, true]);
    }
    for (const rule of rules) {
      expect([rule, await makerCheckerGuard.selfApprovalAllowed(rule, admin)]).toEqual([rule, false]);
      expect([rule, await makerCheckerGuard.selfApprovalAllowed(rule, sysadmin)]).toEqual([rule, false]);
      expect([rule, await makerCheckerGuard.selfApprovalAllowed(rule, superExporter)]).toEqual([rule, false]);
    }
    expect(await makerCheckerGuard.selfApprovalAllowed('no_such_rule', md)).toBe(false);
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

  // Owner decision 1 Nov 2026 (A-2, Q-NEW-3): no exception — not even a Super Admin or an Admin reviews their own document.
  it('lets nobody review their own document, not even a Super Admin or an Admin', async () => {
    const orderId = await createTestOrder(await createTestClient());
    for (const superAdmin of [true, false]) {
      const generator = await createTestUser('Admin');
      if (superAdmin) await makeSuperAdmin(generator);
      const documentId = await createTestDocumentGeneratedBy(orderId, generator);
      await expect(reviewWorkflowService.assignReviewers(documentId, [generator], 1))
        .rejects.toThrow("This document's own generator cannot be assigned as its reviewer — a different person must review it.");
      expect(await documentReviewRepository.forDocument(documentId)).toHaveLength(0);
    }
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
    await db.execute('UPDATE clients SET email = :e WHERE id = :id', { e: BUYER_EMAIL, id: clientId });
    const fileId = await createTestFile(orderId, null);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const requester = await createTestUser('Export Executive');
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', BUYER_EMAIL, 'Subject', 'Body', null, requester);

    await expect(emailDispatchService.approveSend(emailLogId, requester)).rejects.toThrow('You requested this send — a different person must approve it.');
  });

  // Owner decision 1 Nov 2026 (A-3, Q-MAIL-3): no exception — not even a Super Admin approves their own send.
  it('lets not even a Super Admin approve their own email send', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE clients SET email = :e WHERE id = :id', { e: BUYER_EMAIL, id: clientId });
    const fileId = await createTestFile(orderId, null);
    const documentId = await createTestDocument(orderId, 'QT', 'approved', fileId);
    const requester = await createTestUser('Export Executive');
    await makeSuperAdmin(requester);
    const emailLogId = await emailLogRepository.create(orderId, documentId, 'send_qt', BUYER_EMAIL, 'Subject', 'Body', null, requester);

    await expect(emailDispatchService.approveSend(emailLogId, requester)).rejects.toThrow('You requested this send — a different person must approve it.');
    expect((await emailLogRepository.find(emailLogId)).status).toBe('pending_approval');

    // Someone else approves it.
    await emailDispatchService.approveSend(emailLogId, await createTestUser('Admin'));
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

  it('an MD can MD-approve their own amendment but a Super Admin exporter cannot', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const md = await createTestUser('Managing Director');
    const amendmentId = await createTestAmendment(orderId, md);
    await amendmentService.approveByMd(amendmentId, md);
    expect((await amendmentRepository.find(amendmentId)).status).toBe('md_approved');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'AMENDMENT_SELF_APPROVED' AND entity_id = :id", { id: amendmentId })).n)).toBe(1);

    const superUser = await createTestUser('Export Executive');
    await makeSuperAdmin(superUser);
    const other = await createTestAmendment(orderId, superUser);
    await expect(amendmentService.approveByMd(other, superUser)).rejects.toThrow('You requested this amendment — a different privileged user must MD-approve it.');
    expect((await amendmentRepository.find(other)).status).toBe('pending');
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
