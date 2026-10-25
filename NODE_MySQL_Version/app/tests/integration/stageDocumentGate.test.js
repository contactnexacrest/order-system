'use strict';

// Approving the Quotation swaps its DRAFT watermark for the final one, which
// renders a PDF — puppeteer is stubbed under Jest, so fake the render.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const orderBuyerPoDocumentRepository = require('../../src/repositories/orderBuyerPoDocumentRepository');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const buyerActionService = require('../../src/services/buyerActionService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of StageDocumentGateTest.php. Owner decision (2026-10-25): "if in any
 * stage we generate the document, unless that document is approved that
 * stage is not considered complete — it completes when the document is
 * reviewed when send email is disabled; when send email is enabled, only when
 * that document is sent." Each stage gate action is refused until its
 * document is complete; internal never-shown-to-buyer documents (Supplier PO,
 * BLI) only ever need approval.
 */
describe('Stage gates wait for their document', () => {
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

  async function orderAtStage2() {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    return orderId;
  }

  async function approveQuotationThroughReview(orderId) {
    const documentId = await createTestDocument(orderId, 'QT', 'draft');
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

  it('blocks a stage on a draft document and unblocks it on approval while email is off', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const orderId = await orderAtStage2();

    const notGenerated = await documentGenerationService.stageDocumentBlockedBy(orderId, 2);
    expect(notGenerated).toHaveLength(1);
    expect(notGenerated[0]).toContain('Buyer PO');

    const documentId = await createTestDocument(orderId, 'BUYERPO', 'draft');
    expect(await documentGenerationService.stageDocumentBlockedBy(orderId, 2)).toHaveLength(1);

    await db.execute("UPDATE documents SET status = 'approved' WHERE id = :id", { id: documentId });
    expect(await documentGenerationService.stageDocumentBlockedBy(orderId, 2)).toEqual([]);
  });

  it('needs a buyer document sent, not just approved, while email is on', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '1');
    const orderId = await orderAtStage2();
    const documentId = await createTestDocument(orderId, 'BUYERPO', 'approved');

    expect(await documentGenerationService.stageDocumentBlockedBy(orderId, 2)).toHaveLength(1);

    await db.execute("UPDATE documents SET status = 'sent' WHERE id = :id", { id: documentId });
    expect(await documentGenerationService.stageDocumentBlockedBy(orderId, 2)).toEqual([]);
  });

  it('only needs an internal document approved, even while email is on', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '1');
    const orderId = await orderAtStage2();
    await createTestDocument(orderId, 'SUPPO', 'approved');

    expect(await documentGenerationService.stageDocumentBlockedBy(orderId, 5)).toEqual([]);
  });

  it('refuses Confirm Buyer PO Received until the Buyer PO is approved', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const orderId = await orderAtStage2();
    await createTestDocument(orderId, 'BUYERPO', 'draft');
    await orderBuyerPoDocumentRepository.attach(orderId, await createTestFile(orderId, null));
    const page = await agent.get(`/orders/${orderId}`);
    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];

    await agent.post(`/orders/${orderId}/buyer-po`).type('form').send({ _csrf: csrf, buyers_po_ref: 'PO-123' });
    const after = await agent.get(`/orders/${orderId}`);

    expect(after.text).toContain('only be completed once its document is approved');
    expect((await orderStageRepository.findByOrderAndStageNumber(orderId, 2)).status).toBe('in_progress');
  });

  it('does not pass Stage 1 on Quotation approval while email is on', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '1');
    const orderId = await createTestOrder(await createTestClient());

    await approveQuotationThroughReview(orderId);

    expect((await orderStageRepository.findByOrderAndStageNumber(orderId, 1)).status).toBe('in_progress');
  });

  it('passes Stage 1 on Quotation approval while email is off', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '0');
    const orderId = await createTestOrder(await createTestClient());

    await approveQuotationThroughReview(orderId);

    expect((await orderStageRepository.findByOrderAndStageNumber(orderId, 1)).status).toBe('gate_passed');
  });

  it('does not let the buyer acknowledge an approved but unsent OC while email is on', async () => {
    await companySettingsRepository.set('mail_sending_enabled', '1');
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 3; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    const documentId = await createTestDocument(orderId, 'OC', 'approved');

    expect((await buyerActionService.acknowledgeOc(orderId)).ok).toBe(false);
    expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeNull();

    await db.execute("UPDATE documents SET status = 'sent' WHERE id = :id", { id: documentId });
    expect((await buyerActionService.acknowledgeOc(orderId)).ok).toBe(true);
    expect((await orderStageRepository.findByOrderAndStageNumber(orderId, 4)).status).toBe('gate_passed');
  });
});
