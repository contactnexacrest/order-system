'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderExportBenefitSchemeRepository = require('../../src/repositories/orderExportBenefitSchemeRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const orderLedgerService = require('../../src/services/orderLedgerService');
const testimonialSuggestionService = require('../../src/services/testimonialSuggestionService');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderPageT3Test.php. Owner requests (2026-10-26, t3): the ledger
 * shows every money event — in full only to Order Financials holders; the
 * feedback box starts with an order-specific testimonial; every export
 * benefit scheme saves in one submit; a recorded remittance reads back
 * with its exchange rate.
 */
describe('Order page t3 requests (2026-10-26)', () => {
  let agent;
  let adminId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const fakeReq = (params, body) => ({ params, body, query: {}, session: {}, user: { id: adminId }, permissions: new Proxy({}, { get: () => true }) });
  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = () => res;
    res.send = () => {};
    return res;
  }
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1].message : '';
  };

  async function orderWithMoney() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-10-20');
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 83.5, adminId);
    const supplierId = await supplierRepository.create({ supplier_legal_name: `Ledger Quarry ${Math.random().toString(16).slice(2, 8)}`, types: ['Trader'] });
    const poId = await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-LEDGER-1', { advance_amount_inr: '20000', balance_amount_inr: '30000' });
    await orderSupplierPoRepository.markAdvancePaid(poId, '2026-10-21', adminId, 20000.0);
    await orderCostEntryRepository.create(orderId, 'ecgc_insurance', 'ECGC premium', 1500.0, '2026-10-22', adminId);
    return orderId;
  }

  it('shows money in and out with a net in the full ledger', async () => {
    const ledger = await orderLedgerService.forOrder(await orderWithMoney(), 'USD', true);

    const events = ledger.rows.map((r) => r.event);
    expect(events).toContain('Advance remittance received from buyer');
    expect(events).toContain('Expense — ECGC Insurance: ECGC premium');
    expect(events.filter((e) => e.startsWith('Supplier Advance paid'))).toHaveLength(1);
    expect(ledger.totals.buyer_inr).toBeCloseTo(83500, 3);
    expect(ledger.totals.supplier_inr).toBeCloseTo(20000, 3);
    expect(ledger.totals.expenses_inr).toBeCloseTo(1500, 3);
    expect(ledger.totals.net_inr).toBeCloseTo(62000, 3);
  });

  it('keeps the ledger to buyer receipts without Order Financials', async () => {
    const ledger = await orderLedgerService.forOrder(await orderWithMoney(), 'USD', false);

    expect(ledger.totals).toBeNull();
    expect(ledger.rows.filter((r) => r.direction === 'out')).toHaveLength(0);
  });

  it('builds the testimonial from the order and never repeats an earlier one', () => {
    const order = { order_reference: 'SC/ORD/2026/777', port_of_discharge_city: 'Hamburg' };
    const products = [{ description: 'Black Granite Slabs', quantity: '120.000', unit: 'm2', is_active: 1 }];

    const first = testimonialSuggestionService.build(order, products);
    expect(first).toContain('120 m2 of Black Granite Slabs');
    expect(first).toContain('Hamburg');
    expect(testimonialSuggestionService.build(order, products)).toBe(first);
    expect(testimonialSuggestionService.build(order, products, [first])).not.toBe(first);
  });

  it('saves every export benefit scheme in one submit, all or nothing', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderExportBenefitSchemeRepository.initializeForOrder(orderId);
    const schemes = await orderExportBenefitSchemeRepository.forOrder(orderId);
    expect(schemes.length).toBeGreaterThan(1);

    let req = fakeReq({ id: String(orderId) }, { schemes: schemes.map((s) => ({ scheme_name: s.scheme_name, status: 'not_eligible', reason: 'short' })) });
    await orderFinancialsController.setExportBenefitSchemeStatus(req, fakeRes());
    expect(lastFlash(req)).toContain('Nothing saved');
    expect((await orderExportBenefitSchemeRepository.summaryForOrder(orderId)).pending).toBe(schemes.length);

    req = fakeReq({ id: String(orderId) }, { schemes: schemes.map((s, i) => ({ scheme_name: s.scheme_name, status: i === 0 ? 'eligible' : 'not_eligible', reason: 'Not claimed on this shipment' })) });
    await orderFinancialsController.setExportBenefitSchemeStatus(req, fakeRes());
    expect(lastFlash(req)).toContain('eligibility saved for');
    const after = Object.fromEntries((await orderExportBenefitSchemeRepository.forOrder(orderId)).map((s) => [s.scheme_name, s.status]));
    expect(after[schemes[0].scheme_name]).toBe('eligible');
    expect(after[schemes[1].scheme_name]).toBe('not_eligible');
  });

  it('reads a recorded remittance back with its exchange rate', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1800.0, '2026-10-26');
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 83.25, adminId);
    await orderPaymentStatusRepository.setLegBankReference(orderId, 'advance', 'UTR-TEST-0001');

    const { text } = await agent.get(`/orders/${orderId}`);

    expect(text).toContain("Received USD 1,800.00 on 26 Oct 2026 at today's exchange rate of &#8377;83.2500 (= &#8377;149,850.00). Bank ref UTR-TEST-0001.");
  });
});
