'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of BenefitClaimCorrectionAndSupplierAdvanceTest.php. Owner requests
 * (2026-10-27): a wrongly typed government-benefit claim can be corrected
 * or deleted (reason required, audit-logged); and the supplier balance
 * (10a) waits for the supplier advance (7e) whenever the terms plan one.
 */
describe('Benefit claim corrections and the supplier advance (owner requests 2026-10-27)', () => {
  let userId;

  beforeAll(async () => {
    const admin = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });
    userId = admin.id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function call(fn, params, body) {
    const req = { user: { id: userId, role_id: null }, params, body, session: {} };
    await fn(req, { redirect: () => {}, status: () => ({ send: () => {} }) });
    const messages = flash.pull(req);
    return messages[messages.length - 1] || { type: '', message: '' };
  }

  const lastAudit = () => db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');

  it('corrects a wrong claim with a reason', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const id = await caExportBenefitRepository.record(orderId, 'RODTEP', '443432432', 50000, '2026-10-27', 'INR', null, userId);
    await caExportBenefitRepository.markReceived(id, 5000, '2026-10-27');
    const params = { id: String(orderId), benefitId: String(id) };
    const body = { claimed_amount: '5000', claimed_at: '2026-10-27', received_amount: '5000', received_at: '2026-10-27', reason: '' };

    expect((await call(orderFinancialsController.correctExportBenefit, params, body)).type).toBe('error');
    expect(Number((await caExportBenefitRepository.find(id)).claimed_amount)).toBe(50000);

    expect((await call(orderFinancialsController.correctExportBenefit, params, { ...body, reason: 'Typed 50,000 instead of 5,000' })).type).toBe('success');
    expect(Number((await caExportBenefitRepository.find(id)).claimed_amount)).toBe(5000);
    const audit = await lastAudit();
    expect(audit.action_type).toBe('CA_EXPORT_BENEFIT_CORRECTED');
    expect(audit.old_value).toContain('claimed 50000.00');
    expect(audit.new_value).toContain('claimed 5000.00');

    await call(orderFinancialsController.correctExportBenefit, params, { ...body, received_amount: '', received_at: '', reason: 'Money has not actually arrived yet' });
    expect((await caExportBenefitRepository.find(id)).received_amount).toBeNull();
  });

  it('deletes a duplicate claim with a reason, never another order\'s', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const id = await caExportBenefitRepository.record(orderId, 'RODTEP', 'ewrwrew', 50000, '2026-10-27', 'INR', null, userId);
    await call(orderFinancialsController.deleteExportBenefit, { id: String(orderId), benefitId: String(id) }, { reason: 'Recorded twice by mistake' });
    expect(await caExportBenefitRepository.find(id)).toBeNull();
    expect((await lastAudit()).action_type).toBe('CA_EXPORT_BENEFIT_DELETED');

    const other = await createTestOrder(await createTestClient());
    const kept = await caExportBenefitRepository.record(other, 'RODTEP', null, 100, '2026-10-27', 'INR', null, userId);
    await call(orderFinancialsController.deleteExportBenefit, { id: String(orderId), benefitId: String(kept) }, { reason: 'Recorded twice by mistake' });
    expect(await caExportBenefitRepository.find(kept)).not.toBeNull();
  });

  it('makes the supplier balance wait for the planned advance', async () => {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 5; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'heera granites' });
    await orderSupplierPoRepository.create(orderId, supplierId, `SUPPO-ADV-${orderId}`, { advance_amount_inr: '20000' }, 'issued');
    const today = new Date().toISOString().slice(0, 10);

    const refused = await call(ordersController.markSupplierBalancePaid, { id: String(orderId) }, { paid_date: today, amount_paid_inr: '30000' });
    expect(refused.message).toContain('Record the supplier advance (Step 7e) first');
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).balance_paid_at).toBeFalsy();

    await call(ordersController.markSupplierAdvancePaid, { id: String(orderId) }, { paid_date: today, amount_paid_inr: '20000' });
    await call(ordersController.markSupplierBalancePaid, { id: String(orderId) }, { paid_date: today, amount_paid_inr: '30000' });
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).balance_paid_at).toBeTruthy();
  });
});
