'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const documentController = require('../../src/controllers/documentController');
const ordersController = require('../../src/controllers/ordersController');
const documentRepository = require('../../src/repositories/documentRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const paymentTermsApprovalService = require('../../src/services/paymentTermsApprovalService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of PaymentTermsApprovalTest.php. QA Round 5, R5-03 (owner decision
 * 2026-10-28, Option A): on a payment preset marked "Requires MD
 * approval", MD/ED (holders of "Approve payment terms") approve the
 * order's terms before the Quotation can be generated or sent. "Extended
 * Terms — Before Shipment" no longer needs approval.
 */
describe('Payment terms approval before the Quotation (QA Round 5, R5-03)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-terms-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  const order = async (preset, assignedTo = null) => createTestOrder(await createTestClient(), 'FOB', preset, 'USD', null, null, assignedTo);
  const fakeRes = () => {
    const res = { redirect() {}, status() { return res; }, send() {} };
    res.renderView = (view, data) => { res.renderedData = data; };
    return res;
  };

  it('only the presets marked for approval need it', async () => {
    expect(await paymentTermsApprovalService.isPending(await order('Standard — New Buyer'))).toBe(false);
    expect(await paymentTermsApprovalService.isPending(await order('Extended Terms — Before Shipment'))).toBe(false);
    expect(await paymentTermsApprovalService.isPending(await order('Established Buyer — Post-BL'))).toBe(true);
    expect(await paymentTermsApprovalService.isPending(await order('Irrevocable Letter of Credit at Sight'))).toBe(true);
  });

  it('refuses to generate the Quotation until the terms are approved', async () => {
    const orderId = await order('Established Buyer — Post-BL');
    const req = { params: { id: String(orderId) }, body: { document_type: 'QT' }, user: { id: await createTestUser('Export Executive') }, permissions: { generate_documents: true, view_selling_prices: true }, session: {} };
    await documentController.generate(req, fakeRes());

    expect(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'QT')).toBeNull();
    expect(flash.pull(req)[0].message).toContain('need MD/ED approval');
  });

  it('approving records who and when and lifts the block', async () => {
    const orderId = await order('Established Buyer — Post-BL');
    const mdId = await createTestUser('Managing Director');
    await ordersController.approvePaymentTerms({ params: { id: String(orderId) }, user: { id: mdId }, permissions: { approve_payment_terms: true }, session: {} }, fakeRes());

    const state = await paymentTermsApprovalService.state(orderId);
    expect(state.approved).toBe(true);
    expect(state.approved_by_name).toBe('Jest Test User');
    expect(await paymentTermsApprovalService.isPending(orderId)).toBe(false);
    const audit = await db.queryOne("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'PAYMENT_TERMS_APPROVED' AND entity_id = :id AND user_id = :u", { id: orderId, u: mdId });
    expect(Number(audit.c)).toBe(1);
  });

  it('refuses to send an unapproved Quotation', async () => {
    const orderId = await order('Established Buyer — Post-BL');
    const documentId = await createTestDocument(orderId, 'QT', 'approved');
    await expect(emailDispatchService.buildPreview(orderId, documentId, 'send_qt', await createTestUser('Admin'))).rejects.toThrow('need MD/ED approval');
  });

  it('the order page offers the approval only to approvers', async () => {
    const orderId = await order('Established Buyer — Post-BL');
    const res = fakeRes();
    await ordersController.show({ params: { id: String(orderId) }, user: { id: 1 }, permissions: { view_all_orders: true }, session: {} }, res);
    expect(res.renderedData.paymentTermsApproval.required).toBe(true);
    expect(res.renderedData.paymentTermsApproval.approved).toBe(false);
    expect(res.renderedData.canApprovePaymentTerms).toBe(false);

    const approverRes = fakeRes();
    await ordersController.show({ params: { id: String(orderId) }, user: { id: 1 }, permissions: { view_all_orders: true, approve_payment_terms: true }, session: {} }, approverRes);
    expect(approverRes.renderedData.canApprovePaymentTerms).toBe(true);
  });
});
