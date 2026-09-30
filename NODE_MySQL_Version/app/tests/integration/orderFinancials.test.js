'use strict';

const db = require('../../src/config/db');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const reportRepository = require('../../src/repositories/reportRepository');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderFinancialsTest.php (docs/schema.sql Section AP) —
 * reorder-to-supplier linking, the order_cost_entries table, the Order
 * Profitability Sheet, the order-page-embedded orderFinancialsController
 * actions, and the documentDataAssembler supplier-cost hardening
 * (client-facing documents must never see order_supplier_po data).
 */
describe('Order Financials (Section AP)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, ...overrides };
  }

  // ---------------------------------------------------------------
  // orderCostEntryRepository
  // ---------------------------------------------------------------

  it('costEntry create/forOrder/totalForOrder/find/remove round-trip', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const id1 = await orderCostEntryRepository.create(orderId, 'cha_charges', 'CHA fee', 1500.0, '2026-06-01', userId);
    const id2 = await orderCostEntryRepository.create(orderId, 'bank_charges', null, 500.5, null, userId);

    const rows = await orderCostEntryRepository.forOrder(orderId);
    expect(rows).toHaveLength(2);
    expect(await orderCostEntryRepository.totalForOrder(orderId)).toBeCloseTo(2000.5, 2);

    const found = await orderCostEntryRepository.find(id1);
    expect(found).not.toBeNull();
    expect(found.category).toBe('cha_charges');

    await orderCostEntryRepository.remove(id1);
    expect(await orderCostEntryRepository.find(id1)).toBeNull();
    expect(await orderCostEntryRepository.totalForOrder(orderId)).toBeCloseTo(500.5, 2);
    expect(await orderCostEntryRepository.find(id2)).not.toBeNull();
  });

  it('costEntry forOrder is scoped to its own order', async () => {
    const userId = await createTestUser();
    const orderA = await createTestOrder(await createTestClient());
    const orderB = await createTestOrder(await createTestClient());

    await orderCostEntryRepository.create(orderA, 'other', null, 100.0, null, userId);

    expect(await orderCostEntryRepository.forOrder(orderA)).toHaveLength(1);
    expect(await orderCostEntryRepository.forOrder(orderB)).toEqual([]);
    expect(await orderCostEntryRepository.totalForOrder(orderB)).toBe(0);
  });

  // ---------------------------------------------------------------
  // orderProfitabilityService
  // ---------------------------------------------------------------

  it('profitability with nothing recorded is all zero and estimated', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.revenue_inr).toBe(0);
    expect(p.revenue_is_estimated).toBe(true);
    expect(p.supplier_cost_inr).toBe(0);
    expect(p.freight_cost_inr).toBe(0);
    expect(p.insurance_cost_inr).toBe(0);
    expect(p.other_costs_inr).toBe(0);
    expect(p.total_cost_inr).toBe(0);
    expect(p.profit_inr).toBe(0);
    expect(p.margin_pct).toBeNull();
  });

  it('profitability uses the assumed rate as estimated revenue when not settled', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderId, 85.0, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.revenue_inr).toBe(85000);
    expect(p.revenue_is_estimated).toBe(true);
    expect(p.profit_inr).toBe(85000);
  });

  it('profitability uses real INR actuals once both legs clear and is not estimated', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderId, 85.0, userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 40000.0, userId);
    await orderPaymentStatusRepository.setBalanceInrActual(orderId, 46000.0, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.revenue_inr).toBe(86000);
    expect(p.revenue_is_estimated).toBe(false);
  });

  it('profitability rolls up supplier/freight/other costs into total and margin', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderId, 85.0, userId); // revenue 85000

    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JTEST-1', { total_payable_inr: '30000' }, 'issued');

    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: 5000, insurance_amount: 1000 });

    await orderCostEntryRepository.create(orderId, 'cha_charges', null, 2000.0, null, userId);
    await orderCostEntryRepository.create(orderId, 'bank_charges', null, 500.0, null, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.revenue_inr).toBe(85000);
    expect(p.supplier_cost_inr).toBe(30000);
    expect(p.freight_cost_inr).toBe(5000);
    expect(p.insurance_cost_inr).toBe(1000);
    expect(p.other_costs_inr).toBe(2500);
    expect(p.total_cost_inr).toBe(38500);
    expect(p.profit_inr).toBe(46500);
    expect(p.margin_pct).toBeCloseTo(Math.round((46500 / 85000) * 100 * 100) / 100, 2);
  });

  // ---------------------------------------------------------------
  // orderFinancialsController — order-page-embedded actions
  // ---------------------------------------------------------------

  it('addCostEntry rejects an invalid category', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = fakeReq({ params: { id: String(orderId) }, body: { category: 'not-a-real-category', amount_inr: '100' }, user: { id: userId } });
    await orderFinancialsController.addCostEntry(req, fakeRes());

    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
    const messages = flash.pull(req);
    expect(messages.length).toBeGreaterThan(0);
    expect(messages[0].type).toBe('error');
  });

  it('addCostEntry records a valid entry and audits it', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = fakeReq({
      params: { id: String(orderId) },
      body: { category: 'cha_charges', amount_inr: '1234.56', incurred_at: '2026-06-01', description: 'Test CHA' },
      user: { id: userId },
    });
    await orderFinancialsController.addCostEntry(req, fakeRes());

    const rows = await orderCostEntryRepository.forOrder(orderId);
    expect(rows).toHaveLength(1);
    expect(parseFloat(rows[0].amount_inr)).toBeCloseTo(1234.56, 2);

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'ORDER_COST_ENTRY_RECORDED' AND entity_id = :id",
      { id: rows[0].id }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('deleteCostEntry removes it and rejects a mismatched order', async () => {
    const userId = await createTestUser();
    const orderA = await createTestOrder(await createTestClient());
    const orderB = await createTestOrder(await createTestClient());
    const entryId = await orderCostEntryRepository.create(orderA, 'other', null, 250.0, null, userId);

    const wrongOrderReq = fakeReq({ params: { id: String(orderB), entryId: String(entryId) }, user: { id: userId } });
    const wrongOrderRes = fakeRes();
    await orderFinancialsController.deleteCostEntry(wrongOrderReq, wrongOrderRes);
    expect(await orderCostEntryRepository.find(entryId)).not.toBeNull();
    expect(wrongOrderRes.statusCode).toBe(404);

    const req = fakeReq({ params: { id: String(orderA), entryId: String(entryId) }, user: { id: userId } });
    await orderFinancialsController.deleteCostEntry(req, fakeRes());
    expect(await orderCostEntryRepository.find(entryId)).toBeNull();
  });

  it('addExportBenefit records against the order and markExportBenefitReceived updates it', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const addReq = fakeReq({
      params: { id: String(orderId) },
      body: { scheme_name: 'RODTEP', claimed_amount: '9000', claimed_at: '2026-06-01' },
      user: { id: userId },
    });
    await orderFinancialsController.addExportBenefit(addReq, fakeRes());

    const benefits = await caExportBenefitRepository.forOrder(orderId);
    expect(benefits).toHaveLength(1);
    expect(benefits[0].received_amount).toBeNull();
    const benefitId = benefits[0].id;

    const receiveReq = fakeReq({
      params: { id: String(orderId), benefitId: String(benefitId) },
      body: { received_amount: '9000', received_at: '2026-07-01' },
      user: { id: userId },
    });
    await orderFinancialsController.markExportBenefitReceived(receiveReq, fakeRes());

    const updated = await caExportBenefitRepository.find(benefitId);
    expect(parseFloat(updated.received_amount)).toBeCloseTo(9000, 2);
  });

  // ---------------------------------------------------------------
  // Reorder-to-supplier linking (orderDuplicationService)
  // ---------------------------------------------------------------

  it('duplicating an order carries forward its Supplier PO as a draft and links both orders', async () => {
    const userId = await createTestUser();
    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);

    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    await orderSupplierPoRepository.create(sourceOrderId, supplierId, 'SUPPO-JSRC-1', {
      material_stone_type: 'Granite',
      total_payable_inr: '55000',
    }, 'issued');

    const newOrderId = await orderDuplicationService.duplicate(sourceOrderId, userId);

    const newOrder = await orderRepository.find(newOrderId);
    expect(newOrder.duplicated_from_order_id).toBe(sourceOrderId);

    const duplicatedInto = await orderRepository.findOrdersDuplicatedFrom(sourceOrderId);
    expect(duplicatedInto).toHaveLength(1);
    expect(duplicatedInto[0].id).toBe(newOrderId);

    const newSupplierPo = await orderSupplierPoRepository.findLatestForOrder(newOrderId);
    expect(newSupplierPo).not.toBeNull();
    expect(newSupplierPo.status).toBe('draft');
    expect(newSupplierPo.material_stone_type).toBe('Granite');
    expect(newSupplierPo.supplier_id).toBe(supplierId);
    expect(newSupplierPo.supplier_po_reference).not.toBe('SUPPO-JSRC-1');

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'SUPPLIER_PO_CARRIED_FORWARD' AND entity_id = :id",
      { id: newSupplierPo.id }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('duplicating an order with no source Supplier PO creates no draft', async () => {
    const userId = await createTestUser();
    const sourceOrderId = await createTestOrder(await createTestClient());

    const newOrderId = await orderDuplicationService.duplicate(sourceOrderId, userId);

    expect(await orderSupplierPoRepository.findLatestForOrder(newOrderId)).toBeNull();
  });

  // ---------------------------------------------------------------
  // documentDataAssembler — client-facing isolation hardening
  // ---------------------------------------------------------------

  it('assemble includes supplier_po only for supplier-facing document types', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Jest Test Supplier' });
    await orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-JASM-1', { total_payable_inr: '10000' }, 'issued');

    const forSuppo = await documentDataAssembler.assemble(orderId, 'SUPPO');
    expect(forSuppo.supplier_po).not.toBeNull();

    for (const clientFacingType of ['QT', 'PI', 'OC', 'BUYERPO', 'CI', 'PL', 'BLI', 'AMD']) {
      const data = await documentDataAssembler.assemble(orderId, clientFacingType);
      expect(data.supplier_po).toBeNull();
    }
  });

  // ---------------------------------------------------------------
  // reportRepository.orderProfitabilityReport() rollup
  // ---------------------------------------------------------------

  it('orderProfitabilityReport rolls up multiple orders with a totals row', async () => {
    const userId = await createTestUser();

    const orderA = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderA, 1, 'Stone A', null, null, '10', false, 'SQM', '100');
    await orderPaymentStatusRepository.initializeForOrder(orderA);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderA, 80.0, userId); // revenue 80000, no costs

    const orderB = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderB, 1, 'Stone B', null, null, '5', false, 'SQM', '200');
    await orderPaymentStatusRepository.initializeForOrder(orderB);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderB, 80.0, userId); // revenue 80000
    await orderCostEntryRepository.create(orderB, 'other', null, 10000.0, null, userId);

    const data = await reportRepository.orderProfitabilityReport(null, null);
    const rowsById = {};
    for (const r of data.rows) rowsById[r.order_id] = r;

    expect(rowsById[orderA]).toBeDefined();
    expect(rowsById[orderB]).toBeDefined();
    expect(rowsById[orderA].revenue_inr).toBe(80000);
    expect(rowsById[orderA].profit_inr).toBe(80000);
    expect(rowsById[orderB].profit_inr).toBe(70000);

    // The report's own totals row must equal the sum of every row it
    // lists (checked directly rather than against a fixed constant — other
    // tests share this same disposable database and may add their own
    // orders in today's date range).
    const sumRevenue = data.rows.reduce((s, r) => s + r.revenue_inr, 0);
    const sumProfit = data.rows.reduce((s, r) => s + r.profit_inr, 0);
    expect(Math.round(data.totals.revenue_inr * 100) / 100).toBeCloseTo(Math.round(sumRevenue * 100) / 100, 2);
    expect(Math.round(data.totals.profit_inr * 100) / 100).toBeCloseTo(Math.round(sumProfit * 100) / 100, 2);
  });

  it('orderProfitabilityReport respects the date range filter', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const farFuture = new Date(Date.now() + 5 * 365 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const data = await reportRepository.orderProfitabilityReport(farFuture, null);

    const ids = data.rows.map((r) => r.order_id);
    expect(ids).not.toContain(orderId);
  });
});
