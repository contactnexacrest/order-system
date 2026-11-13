'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const { todayIst } = require('../../src/helpers/dbDateTime');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../../src/repositories/orderExpenseRepository');
const userRepository = require('../../src/repositories/userRepository');
const orderExpenseController = require('../../src/controllers/orderExpenseController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ExpenseCostBankReferenceZoh005Test.php. QA ZOH-005 — owner decision
 * 13 Nov 2026 (Option A): rule D-212 applies to order expenses and order
 * costs. The forms take a "Bank reference (UTR / transaction ID)", required
 * when the money went out of the company account; the Zoho Books description
 * says what it is, for which order, what it was paid from and the UTR.
 */
describe('ZOH-005: bank reference on order expenses and order costs', () => {
  let adminId;

  beforeAll(async () => {
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const fakeReq = (params, body) => ({ params, body, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' }, permissions: { ca_fy_lock_override: true, manage_order_financials: true } });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : { type: null, message: '' };
  };
  const row = (extra) => ({ buyer_name: 'TEST-QZP Buyer A', buyer_inquiry_ref: 'TQZPA1', ...extra });

  it('ZOH-005: the expense description names what it is, the order, what paid and the UTR', () => {
    const e = { category: 'courier', details: 'Samples to buyer', paid_by: 'company_account', staff_name: null };
    expect(zohoBooksService.expenseDescription(row({ ...e, bank_reference: 'HDFCN26304123456' })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Courier — Samples to buyer · order expense paid from the company account · UTR HDFCN26304123456');
    expect(zohoBooksService.expenseDescription(row({ ...e, bank_reference: null })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Courier — Samples to buyer · order expense paid from the company account'); // an old expense only gains the paid-from
    expect(zohoBooksService.expenseDescription(row({ category: 'hotel', details: 'Hotel Kota, 1 night', paid_by: 'company_card', bank_reference: '4512-778899' })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Hotel — Hotel Kota, 1 night · order expense paid by company card · transaction ref 4512-778899');
    expect(zohoBooksService.expenseDescription(row({ category: 'hotel', details: 'Hotel Kota, 1 night', paid_by: 'company_card', bank_reference: '' })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Hotel — Hotel Kota, 1 night · order expense paid by company card');
    expect(zohoBooksService.expenseDescription(row({ category: 'travel_ticket', details: 'Train to the factory', paid_by: 'staff', staff_name: 'Export Executive', bank_reference: null })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Travel — train / flight / bus — Train to the factory · order expense paid by staff (Export Executive), to reimburse');
    expect(zohoBooksService.expenseDescription(row({ category: 'travel_ticket', details: 'Train to the factory', paid_by: 'staff', staff_name: 'Export Executive', bank_reference: 'UPI-6123' })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Travel — train / flight / bus — Train to the factory · order expense paid by staff (Export Executive), to reimburse · transaction ref UPI-6123');
  });

  it('ZOH-005: the cost description names what it is, the order, what paid and the UTR', () => {
    expect(zohoBooksService.costEntryDescription(row({ category: 'ecgc_insurance', description: 'ECGC cover', paid_from: 'company_account', bank_reference: 'HDFCN26304123456' })))
      .toBe('TEST-QZP Buyer A / TQZPA1: ECGC Insurance — ECGC cover · order cost paid from the company account · UTR HDFCN26304123456');
    expect(zohoBooksService.costEntryDescription(row({ category: 'ecgc_insurance', description: 'ECGC cover', paid_from: 'company_account', bank_reference: null })))
      .toBe('TEST-QZP Buyer A / TQZPA1: ECGC Insurance — ECGC cover · order cost paid from the company account');
    expect(zohoBooksService.costEntryDescription(row({ category: 'documentation', description: 'Legalisation fee', paid_from: 'company_card', bank_reference: '4512-990011' })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Documentation — Legalisation fee · order cost paid by company card · transaction ref 4512-990011');
    expect(zohoBooksService.costEntryDescription(row({ category: 'documentation', description: 'Legalisation fee', paid_from: 'company_card', bank_reference: null })))
      .toBe('TEST-QZP Buyer A / TQZPA1: Documentation — Legalisation fee · order cost paid by company card');
  });

  it('ZOH-005: an expense paid from the company account is refused without its bank reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const base = { expense_date: todayIst(), category: 'courier', details: 'Samples to buyer', amount_inr: '1800', paid_by: 'company_account', no_receipt_reason: 'Courier gave no bill at the counter' };

    let req = fakeReq({ id: String(orderId) }, base);
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req)).toEqual({ type: 'error', message: 'Enter the bank reference (UTR / transaction ID): the expense was paid from the company account.' });
    expect(await orderExpenseRepository.forOrder(orderId)).toEqual([]);

    req = fakeReq({ id: String(orderId) }, { ...base, bank_reference: '9'.repeat(151) });
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).message).toContain('at most 150 characters');

    req = fakeReq({ id: String(orderId) }, { ...base, bank_reference: '  HDFCN26304123456 ' });
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).type).toBe('success');

    req = fakeReq({ id: String(orderId) }, { ...base, paid_by: 'company_card' });
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).type).toBe('success'); // optional for the company card

    expect((await orderExpenseRepository.forOrder(orderId)).map((e) => e.bank_reference)).toEqual(['HDFCN26304123456', null]);
  });

  it('ZOH-005: a cost paid from the company account is refused without its bank reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const base = { category: 'ecgc_insurance', amount_inr: '4200', incurred_at: todayIst(), description: 'ECGC cover for this shipment, policy TEST-1', paid_from: 'company_account' };

    let req = fakeReq({ id: String(orderId) }, base);
    await orderFinancialsController.addCostEntry(req, fakeRes());
    expect(lastFlash(req)).toEqual({ type: 'error', message: 'Enter the bank reference (UTR / transaction ID): the cost was paid from the company account.' });
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);

    req = fakeReq({ id: String(orderId) }, { ...base, bank_reference: 'HDFCN26304123456' });
    await orderFinancialsController.addCostEntry(req, fakeRes());
    expect(lastFlash(req).type).toBe('success');

    req = fakeReq({ id: String(orderId) }, { ...base, paid_from: 'company_card' });
    await orderFinancialsController.addCostEntry(req, fakeRes());
    expect(lastFlash(req).type).toBe('success'); // optional for the company card

    const refs = (await db.query('SELECT bank_reference FROM order_cost_entries WHERE order_id = :id ORDER BY id', { id: orderId })).map((r) => r.bank_reference);
    expect(refs).toEqual(['HDFCN26304123456', null]);
  });

  it('ZOH-005: both forms ask for the bank reference in the browser when the company account paid', () => {
    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    expect(view).toContain('<input type="text" name="bank_reference" maxlength="150" data-required-if="paid_by=company_account"');
    expect(view).toContain('<input type="text" name="bank_reference" maxlength="150" data-required-if="paid_from=company_account"');
    expect(view.split('Bank reference (UTR / transaction ID) <small class="muted">(required when paid from the company account)</small>')).toHaveLength(3);
  });

  it('ZOH-005: the order page lists show the bank reference', () => {
    expect(zohoBooksService.moneyBankRef('company_account', ' HDFCN26304123456 ')).toBe('UTR HDFCN26304123456');
    expect(zohoBooksService.moneyBankRef('company_card', '4512')).toBe('transaction ref 4512');
    expect(zohoBooksService.moneyBankRef('company_account', null)).toBeNull();
    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    expect(view.split('moneyBankRef(')).toHaveLength(3);
  });
});
