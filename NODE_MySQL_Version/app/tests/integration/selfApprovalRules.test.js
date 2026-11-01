'use strict';

// Approval finalises the document (renders its PDF) — mocked as in buyerPoConsigneeFields.test.js.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
}));

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const userRepository = require('../../src/repositories/userRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const makerCheckerGuard = require('../../src/services/makerCheckerGuard');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const selfApprovalRules = require('../../src/services/selfApprovalRules');
const selfApprovalController = require('../../src/controllers/selfApprovalController');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of SelfApprovalRulesTest.php. Owner decision 1 Nov 2026 (D-221):
 * Admin → Self-approval rules — per rule the roles that may self-approve;
 * defaults MD and ED; Super Admins only change it; every change and every
 * self-approval is in the audit log.
 */
describe('Self-approval rules (D-221)', () => {
  const REVIEW_REFUSED = "This document's own generator cannot be assigned as its reviewer — a different person must review it.";
  const SEND_REFUSED = 'You requested this send — a different person must approve it.';
  let mail;
  let saved;

  beforeEach(async () => {
    mail = await companySettingsRepository.get('mail_sending_enabled');
    await companySettingsRepository.set('mail_sending_enabled', '1', null);
    saved = {};
    for (const rule of Object.keys(selfApprovalRules.RULES)) saved[rule] = await selfApprovalRules.roleIds(rule);
    await db.execute("UPDATE document_types SET min_reviewers_default = 1 WHERE code = 'QT'");
  });

  afterEach(async () => {
    for (const [rule, ids] of Object.entries(saved)) await selfApprovalRules.set(rule, ids);
    await companySettingsRepository.set('mail_sending_enabled', mail, null);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role, 1, 0, 0)`,
      { email: `jest-sa-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role: role.id }
    );
    return r.insertId;
  }

  async function roleId(name) {
    return Number((await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: name })).id);
  }

  async function audits(action, entityId) {
    return Number((await db.queryOne('SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :a AND entity_id = :e', { a: action, e: entityId })).n);
  }

  async function documentBy(userId) {
    const orderId = await createTestOrder(await createTestClient());
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
    const r = await db.execute(
      "INSERT INTO documents (order_id, document_type_id, document_reference, status, generated_by) VALUES (:o, :t, :ref, 'draft', :g)",
      { o: orderId, t: type.id, ref: `JEST-SA-${Math.random().toString(16).slice(2, 10)}`, g: userId }
    );
    return r.insertId;
  }

  async function sendRequestedBy(userId) {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
    const d = await db.execute(
      "INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id) VALUES (:o, :t, :ref, 'approved', :f)",
      { o: orderId, t: type.id, ref: `JEST-SA-${Math.random().toString(16).slice(2, 10)}`, f: fileId }
    );
    return emailLogRepository.create(orderId, d.insertId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, userId);
  }

  it('the defaults are the MD and the ED on all three rules', async () => {
    for (const rule of Object.keys(selfApprovalRules.RULES)) {
      expect((await selfApprovalRules.roleNames(rule)).sort()).toEqual(['Executive Director', 'Managing Director']);
    }
  });

  it('the MD and the ED review their own document and it is logged', async () => {
    for (const role of ['Managing Director', 'Executive Director']) {
      const me = await user(role);
      const doc = await documentBy(me);
      await reviewWorkflowService.assignReviewers(doc, [me], me);
      const review = (await documentReviewRepository.forDocument(doc))[0];
      await reviewWorkflowService.approve(Number(review.id), me, null);
      expect(await audits('DOCUMENT_SELF_REVIEWED', doc)).toBe(1);
    }
  });

  it('everyone else is still refused with the same message', async () => {
    for (const role of ['Admin', 'Export Executive', 'Finance Director']) {
      const me = await user(role);
      await expect(reviewWorkflowService.assignReviewers(await documentBy(me), [me], me)).rejects.toThrow(REVIEW_REFUSED);
      await expect(emailDispatchService.approveSend(await sendRequestedBy(me), me)).rejects.toThrow(SEND_REFUSED);
      expect(await makerCheckerGuard.paymentTermsSelfApprovalAllowed(me)).toBe(false);
    }
  });

  it('the MD approves their own send and it is logged', async () => {
    const md = await user('Managing Director');
    const send = await sendRequestedBy(md);
    await emailDispatchService.approveSend(send, md);
    expect((await emailLogRepository.find(send)).status).toBe('approved');
    expect(await audits('EMAIL_SEND_SELF_APPROVED', send)).toBe(1);
    expect(await makerCheckerGuard.paymentTermsSelfApprovalAllowed(md)).toBe(true);
  });

  it('unticking the ED refuses the ED, and the change is logged', async () => {
    const superId = await user('Admin');
    await db.execute('UPDATE users SET is_super_admin = 1 WHERE id = :id', { id: superId });
    const session = {};
    const md = String(await roleId('Managing Director'));
    // QA Round 8: the page holds seven rules; the other four are posted as they are (MD and ED), so only these three change.
    const both = [md, String(await roleId('Executive Director'))];
    const res = { redirect(l) { this.location = l; } };
    const req = { user: await userRepository.findById(superId), session, body: { payment_terms: [md], document_review: [md], email_send: [md], cancellation: both, purchase_award: both, amendment: both, short_payment: both } };
    await selfApprovalController.save(req, res);
    expect(session._flash[0].message).toBe('Self-approval rules saved. They apply from the next approval.');
    const log = await db.queryOne("SELECT old_value, new_value FROM audit_log WHERE action_type = 'SELF_APPROVAL_RULE_CHANGED' AND field_name = 'document_review' ORDER BY id DESC LIMIT 1");
    expect([String(log.old_value).split(', ').sort(), log.new_value]).toEqual([['Executive Director', 'Managing Director'], 'Managing Director']);

    const ed = await user('Executive Director');
    await expect(reviewWorkflowService.assignReviewers(await documentBy(ed), [ed], ed)).rejects.toThrow(REVIEW_REFUSED);
    expect(await makerCheckerGuard.paymentTermsSelfApprovalAllowed(ed)).toBe(false);

    delete session._flash;
    await selfApprovalController.save(req, res);
    expect(session._flash[0].message).toBe('Nothing changed.');
  });

  it('an assigned self-review is refused once the rule is withdrawn', async () => {
    const md = await user('Managing Director');
    const doc = await documentBy(md);
    await reviewWorkflowService.assignReviewers(doc, [md], md);
    await selfApprovalRules.set('document_review', []);
    const review = (await documentReviewRepository.forDocument(doc))[0];
    await expect(reviewWorkflowService.approve(Number(review.id), md, null)).rejects.toThrow(REVIEW_REFUSED);
  });
});
