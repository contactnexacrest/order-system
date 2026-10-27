'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const orderRepository = require('../../src/repositories/orderRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderSupplierPoDocumentRepository = require('../../src/repositories/orderSupplierPoDocumentRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const wetSignatureGuardService = require('../../src/services/wetSignatureGuardService');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderPageT4Test.php. Owner requests (2026-10-26, t4): Step 12 can
 * be skipped with a reason (figures stay blank, never 0), and Step 7c gets
 * a no-login Supplier Action Link plus a reasoned "supplier did not
 * acknowledge" skip.
 */
describe('Order page t4 (owner requests 2026-10-26)', () => {
  let adminId;

  beforeAll(async () => {
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function fakeReq(params, body) {
    return { params, body, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' } };
  }
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : null;
  };

  async function createSupplierPo(orderId) {
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'T4 Quarry Supplier Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `T4-SPO-${orderId}`, {
      material_stone_type: null, grade: null, surface_finish: null,
      dimensions: null, dimensional_tolerance: null, quantity: null,
      unit: null, colour_reference: null, special_requirements: null,
      unit_price_inr: '1234', basic_value_inr: null, gst_rate_pct: null,
      gst_amount_inr: null, total_payable_inr: null, advance_pct: null,
      advance_amount_inr: null, balance_amount_inr: null,
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
    }, 'issued');
    return orderSupplierPoRepository.findLatestForOrder(orderId);
  }

  it('skips Step 12 only with a reason and writes no figures', async () => {
    const orderId = await createTestOrder(await createTestClient());

    await orderFinancialsController.skipFinancials(fakeReq({ id: String(orderId) }, { skip_reason: 'short' }), fakeRes());
    expect((await orderRepository.find(orderId)).financials_skipped_at).toBeNull();

    await orderFinancialsController.skipFinancials(fakeReq({ id: String(orderId) }, { skip_reason: 'No costs to record on this sample order' }), fakeRes());
    const order = await orderRepository.find(orderId);
    expect(order.financials_skipped_at).not.toBeNull();
    expect(order.financials_skip_reason).toBe('No costs to record on this sample order');
    const costs = await db.queryOne('SELECT COUNT(*) AS n FROM order_cost_entries WHERE order_id = :id', { id: orderId });
    expect(Number(costs.n)).toBe(0);

    await orderFinancialsController.undoSkipFinancials(fakeReq({ id: String(orderId) }, {}), fakeRes());
    expect((await orderRepository.find(orderId)).financials_skipped_at).toBeNull();
  });

  it('opens a supplier link only for its own PO, shows no prices or buyer, and takes the upload', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierPo = await createSupplierPo(orderId);

    expect(await orderSupplierPoRepository.findByValidActionToken('not-a-real-token')).toBeNull();
    const expired = await orderSupplierPoRepository.generateActionToken(supplierPo.id, -1);
    expect(await orderSupplierPoRepository.findByValidActionToken(expired)).toBeNull();

    const token = await orderSupplierPoRepository.generateActionToken(supplierPo.id);
    expect((await orderSupplierPoRepository.findByValidActionToken(token)).id).toBe(supplierPo.id);

    const agent = request.agent(app);
    const page = await agent.get(`/supplier-action/${token}`);
    expect(page.status).toBe(200);
    expect(page.text).toContain(supplierPo.supplier_po_reference);
    // Random link/CSRF tokens can contain any digit run — drop them before
    // checking that the PO price never reaches the supplier page.
    expect(page.text.replace(/[A-Za-z0-9_-]{20,}/g, '')).not.toContain('1234');
    expect(page.text).not.toContain((await orderRepository.find(orderId)).company_legal_name);
    expect((await agent.get('/supplier-action/bogus')).text).toContain('Link No Longer Valid');

    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(`/supplier-action/${token}/acknowledgment`)
      .field('_csrf', csrf)
      .attach('document', Buffer.from('%PDF-1.4 signed supplier po'), 'signed-supplier-po.pdf');
    expect(res.status).toBe(302);
    const docs = await orderSupplierPoDocumentRepository.forSupplierPo(supplierPo.id);
    expect(docs.map((d) => d.original_filename)).toEqual(['signed-supplier-po.pdf']);
    expect(docs[0].server_path).toContain('/07-supplier-po/received/');
  });

  it('lets staff record that the supplier did not acknowledge', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierPo = await createSupplierPo(orderId);
    const previous = await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = 'wet_signature_required_supplier_po'");
    await db.execute("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'wet_signature_required_supplier_po'");
    try {
      expect(await wetSignatureGuardService.supplierPoBlocked(supplierPo.id)).toBe(true);

      await ordersController.skipSupplierPoAck(fakeReq({ id: String(orderId) }, { skip_reason: 'nope' }), fakeRes());
      expect(await wetSignatureGuardService.supplierPoBlocked(supplierPo.id)).toBe(true);

      await ordersController.skipSupplierPoAck(fakeReq({ id: String(orderId) }, { skip_reason: 'Supplier confirmed by phone, will not sign' }), fakeRes());
      expect(await wetSignatureGuardService.supplierPoBlocked(supplierPo.id)).toBe(false);
      expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).ack_skip_reason).toBe('Supplier confirmed by phone, will not sign');
    } finally {
      await db.execute("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'wet_signature_required_supplier_po'", { v: previous ? previous.setting_value : '0' });
    }
  });

  it('generates the supplier link from the order page', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createSupplierPo(orderId);
    const req = fakeReq({ id: String(orderId) }, {});
    await ordersController.generateSupplierActionLink(req, fakeRes());
    const message = lastFlash(req);
    expect(message.type).toBe('success');
    expect(message.message).toContain('/supplier-action/');
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).supplier_action_token_hash).not.toBeNull();
  });
});
