'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const reportController = require('../../src/controllers/reportController');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Phase 2 item 1 of the approved remediation plan: "customer-wise /
 * product-wise / country-wise reports; today's incoming/supplier
 * payments." The supplier half needed new tracking added first — only
 * the AMOUNT owed to a supplier was ever recorded, never whether or
 * when it was actually paid, unlike the buyer side. Port of PHP's
 * SupplierPaymentDatesTest.php.
 */
describe('Supplier payment dates (Phase 2 item 1)', () => {
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

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  function fakeReq(params, body, userId) {
    const permissions = new Proxy({}, { get: () => true });
    return { params, body: body || {}, query: {}, permissions, session: {}, user: { id: userId } };
  }
  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  it('markAdvancePaid/markBalancePaid persist date and user', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    const supplierPoId = await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JPAY-1', {
      advance_amount_inr: '20000', balance_amount_inr: '30000',
    }, 'issued');
    const userId = await createTestUser('Admin');

    await orderSupplierPoRepository.markAdvancePaid(supplierPoId, '2026-10-20', userId);
    await orderSupplierPoRepository.markBalancePaid(supplierPoId, '2026-10-21', userId);

    const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(supplierPo.advance_paid_at).toBe('2026-10-20');
    expect(Number(supplierPo.advance_paid_by)).toBe(userId);
    expect(supplierPo.balance_paid_at).toBe('2026-10-21');
    expect(Number(supplierPo.balance_paid_by)).toBe(userId);
  });

  it('paidOnDate finds both legs on their own date', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    const supplierPoId = await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JPAY-2', {
      advance_amount_inr: '20000', balance_amount_inr: '30000',
    }, 'issued');
    const userId = await createTestUser('Admin');
    // Distinct, otherwise-unused dates — other tests in this suite also
    // write real rows with real dates.
    await orderSupplierPoRepository.markAdvancePaid(supplierPoId, '2031-03-11', userId);
    await orderSupplierPoRepository.markBalancePaid(supplierPoId, '2031-03-12', userId);

    const onThe11th = (await orderSupplierPoRepository.paidOnDate('2031-03-11')).map((r) => r.id);
    const onThe12th = (await orderSupplierPoRepository.paidOnDate('2031-03-12')).map((r) => r.id);
    const onAnotherDay = (await orderSupplierPoRepository.paidOnDate('2031-01-01')).map((r) => r.id);

    expect(onThe11th).toContain(supplierPoId);
    expect(onThe12th).toContain(supplierPoId);
    expect(onAnotherDay).not.toContain(supplierPoId);
  });

  it('order page offers Mark Paid buttons only until paid', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    const supplierPoId = await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JPAY-3', {
      advance_amount_inr: '20000', balance_amount_inr: '30000',
    }, 'issued');
    await unlockThrough(orderId, 5);
    const userId = await createTestUser('Admin');

    const resBefore = fakeRes();
    await ordersController.show(fakeReq({ id: String(orderId) }, null, userId), resBefore);
    expect(resBefore.renderedData.canManageOrderFinancials).toBe(true);
    expect(resBefore.renderedData.supplierPo.advance_paid_at).toBeFalsy();
    expect(resBefore.renderedData.supplierPo.balance_paid_at).toBeFalsy();

    await orderSupplierPoRepository.markAdvancePaid(supplierPoId, '2026-10-20', userId);

    const resAfter = fakeRes();
    await ordersController.show(fakeReq({ id: String(orderId) }, null, userId), resAfter);
    expect(resAfter.renderedData.supplierPo.advance_paid_at).toBeTruthy();
    expect(resAfter.renderedData.supplierPo.balance_paid_at).toBeFalsy();
  });

  it('todaysPayments combines incoming and outgoing legs for the given date', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-10-21');
    const clearUserId = await createTestUser('Admin');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-10-21 10:00:00', clearUserId);

    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    const supplierPoId = await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JPAY-4', {
      advance_amount_inr: '20000',
    }, 'issued');
    await orderSupplierPoRepository.markAdvancePaid(supplierPoId, '2026-10-21', clearUserId);

    const res = fakeRes();
    await reportController.todaysPayments({ query: { date: '2026-10-21' }, permissions: new Proxy({}, { get: () => true }) }, res);

    expect(res.renderedData.supplierRows.some((r) => r.supplier_legal_name === 'Jest Test Supplier')).toBe(true);
    expect(res.renderedData.incomingRows.some((r) => Number(r.amount) === 1000)).toBe(true);
    expect(res.renderedData.supplierRows.some((r) => Number(r.amount) === 20000)).toBe(true);
  });

  /**
   * External QA report Q-RPT-1 (owner review, 2026-10-23): "Viewer and
   * other view_reports roles can open Today's Payments, which also
   * lists supplier (outgoing INR) payments — cost information." The
   * supplierRows data (and the Outgoing section it drives) is now only
   * computed for whoever holds manage_supplier_payments. Port of PHP's
   * testTodaysPaymentsHidesTheSupplierSectionWithoutManageSupplierPayments.
   */
  it('todaysPayments hides supplier data without manage_supplier_payments', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const adminId = await createTestUser('Admin');
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Restricted Supplier' });
    const supplierPoId = await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JPAY-5', {
      advance_amount_inr: '45000',
    }, 'issued');
    await orderSupplierPoRepository.markAdvancePaid(supplierPoId, '2031-05-05', adminId);

    async function loadRealPermissions(roleName) {
      const userId = await createTestUser(roleName);
      const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
      return require('../../src/services/permissionService').load(userId, role.id);
    }

    const resViewer = fakeRes();
    await reportController.todaysPayments({ query: { date: '2031-05-05' }, permissions: await loadRealPermissions('Viewer / Auditor') }, resViewer);

    const resExport = fakeRes();
    await reportController.todaysPayments({ query: { date: '2031-05-05' }, permissions: await loadRealPermissions('Export Executive') }, resExport);

    const resAccounts = fakeRes();
    await reportController.todaysPayments({ query: { date: '2031-05-05' }, permissions: await loadRealPermissions('Accounts Executive') }, resAccounts);

    expect(resViewer.renderedData.canManageSupplierPayments).toBe(false);
    expect(resViewer.renderedData.supplierRows).toEqual([]);
    expect(resExport.renderedData.canManageSupplierPayments).toBe(false);
    expect(resExport.renderedData.supplierRows).toEqual([]);
    expect(resAccounts.renderedData.canManageSupplierPayments).toBe(true);
    expect(resAccounts.renderedData.supplierRows.some((r) => r.supplier_legal_name === 'Jest Restricted Supplier')).toBe(true);
  });
});
