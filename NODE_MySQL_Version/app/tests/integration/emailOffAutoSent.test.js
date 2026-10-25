'use strict';

// Approval swaps the DRAFT watermark for the final one, which renders a
// PDF — puppeteer is stubbed under Jest, so fake the render (same as
// documentSupersede.test.js).
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of EmailOffAutoSentTest.php. Owner decision (2026-10-25): with
 * mail_sending_enabled off — now the seed default — there is no "Send to
 * Buyer" action; staff send the PDF themselves, so a buyer-sendable
 * document counts as sent the moment it is approved
 * (emailDispatchService.markSentIfMailDisabled()). Before this, a disabled
 * send could never mark anything sent, so Step 2 (Send Quotation) could
 * never be completed at all.
 */
describe('Email sending off: approved documents count as sent', () => {
  let originalMailSendingEnabled;
  let agent;

  async function createTestDocument(orderId, typeCode, status) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status }
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

  async function approveThroughReview(documentId) {
    const reviewer = await createTestUser('Export Executive');
    await reviewWorkflowService.assignReviewers(documentId, [reviewer], 1);
    const [review] = await documentReviewRepository.forDocument(documentId);
    await reviewWorkflowService.approve(review.id, review.reviewer_id, null);
  }

  // Other files mutate the single shared QT document_types row's
  // min_reviewers_default and don't always restore it — reset it so the
  // single-reviewer approvals here don't depend on test execution order.
  beforeEach(async () => {
    await db.execute("UPDATE document_types SET min_reviewers_default = 1 WHERE code = 'QT'");
  });

  beforeAll(async () => {
    originalMailSendingEnabled = await companySettingsRepository.get('mail_sending_enabled');
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await companySettingsRepository.set('mail_sending_enabled', originalMailSendingEnabled);
    await db.pool.end();
  });

  it('defaults to email sending off in the seed', () => {
    expect(originalMailSendingEnabled).toBe('0');
  });

  it('marks an approved Quotation sent when email is off, with an audit entry', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');

    await approveThroughReview(documentId);

    expect((await documentRepository.find(documentId)).status).toBe('sent');
    const audit = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'DOCUMENT_MARKED_SENT_MAIL_DISABLED' AND entity_id = :id",
      { id: documentId }
    );
    expect(Number(audit.c)).toBe(1);
  });

  it('leaves an approved Quotation approved when email is on', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '1');
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'draft');

    await approveThroughReview(documentId);

    expect((await documentRepository.find(documentId)).status).toBe('approved');
  });

  it('never marks an internal never-shown-to-buyer document sent', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'SUPPO', 'approved');

    await emailDispatchService.markSentIfMailDisabled(documentId);

    expect((await documentRepository.find(documentId)).status).toBe('approved');
  });

  it('starts the OC acknowledgment clock when an Order Confirmation is marked sent', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'OC', 'approved');

    await emailDispatchService.markSentIfMailDisabled(documentId);

    expect((await documentRepository.find(documentId)).status).toBe('sent');
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack).not.toBeNull();
    expect(Number(ack.document_id)).toBe(documentId);
  });

  it('falls back to the live client email when the order snapshot has none', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE orders SET client_email = NULL WHERE id = :id', { id: orderId });
    await db.execute("UPDATE clients SET email = 'added-later@example.test' WHERE id = :id", { id: clientId });
    const documentId = await createTestDocument(orderId, 'QT', 'approved');
    const userId = await createTestUser('Admin');

    const preview = await emailDispatchService.buildPreview(orderId, documentId, 'send_qt', userId);

    expect(preview.recipient_email).toBe('added-later@example.test');
  });

  it('hides Send to Buyer on the order page when email is off', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'QT', 'approved');

    await companySettingsRepository.set('mail_sending_enabled', '1');
    const mailOn = await agent.get(`/orders/${orderId}`);
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const mailOff = await agent.get(`/orders/${orderId}`);

    expect(mailOn.text).toContain('Send to Buyer (deferred');
    expect(mailOff.text).not.toContain('Send to Buyer (deferred');
    expect(mailOff.text).toContain('Email sending is turned off');
  });
});
