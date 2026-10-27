'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of SupplierBuyerSeparationTest.php. Owner rule (2026-10-27,
 * non-negotiable): the supplier and the buyer are two different parties and
 * never cross. A supplier link opens only the supplier's page, a buyer link
 * only the buyer's; Step 7c only ever offers the Supplier Action Link; and
 * the Supplier PO document carries nothing about the client or its order.
 */
describe('supplier and buyer never cross', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function orderWithSupplierPo() {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET buyer_inquiry_ref = 'BUYER-INQ-7781' WHERE id = :id", { id: orderId });
    // Step 7 (stage 5) open, so the 7c card shows its contents.
    await db.execute("UPDATE order_stages SET status = 'in_progress' WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = 5)", { id: orderId });
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Separation Quarry Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `SEP-SPO-${orderId}`, {
      material_stone_type: 'Kota Blue', grade: null, surface_finish: null,
      dimensions: null, dimensional_tolerance: null, quantity: '100',
      unit: 'sqm', colour_reference: null, special_requirements: null,
      unit_price_inr: '450', basic_value_inr: '45000', gst_rate_pct: '5',
      gst_amount_inr: '2250', total_payable_inr: '47250', advance_pct: '30',
      advance_amount_inr: '14175', balance_amount_inr: '33075',
      delivery_location: 'Kandla CFS', required_delivery_date: '2026-12-01', packing_requirement: null,
    }, 'issued');
    return [orderId, await orderSupplierPoRepository.findLatestForOrder(orderId)];
  }

  function supplierLinkBox(html) {
    const start = html.indexOf('class="supplier-action-link"');
    expect(start).toBeGreaterThan(-1);
    return html.slice(start, html.indexOf('</form>', start));
  }

  it('Step 7c offers only the supplier link, and none before it is generated', async () => {
    const [orderId] = await orderWithSupplierPo();
    // A live buyer link on the same order must never leak into 7c.
    const buyerToken = await orderRepository.generateBuyerActionToken(orderId);

    let box = supplierLinkBox((await agent.get(`/orders/${orderId}`)).text);
    expect(box).toContain('Generate Supplier Link');
    expect(box).not.toContain('aria-label="Supplier Action Link"');
    expect(box).not.toContain('buyer-action');
    expect(box).not.toContain(buyerToken);

    const page = await agent.get(`/orders/${orderId}`);
    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post(`/orders/${orderId}/supplier-po/supplier-link`).type('form').send({ _csrf: csrf });
    box = supplierLinkBox((await agent.get(`/orders/${orderId}`)).text);
    expect(box).toMatch(/\/supplier-action\/[0-9a-f]{64}"/);
    expect(box).not.toContain('buyer-action');
    expect(box).not.toContain(buyerToken);
  });

  it('a supplier link never opens the buyer page, and a buyer link never opens the supplier page', async () => {
    const [orderId, supplierPo] = await orderWithSupplierPo();
    const buyerToken = await orderRepository.generateBuyerActionToken(orderId);
    const supplierToken = await orderSupplierPoRepository.generateActionToken(supplierPo.id);
    const visitor = request.agent(app);

    expect((await visitor.get(`/buyer-action/${supplierToken}`)).text).toContain('Link No Longer Valid');
    expect((await visitor.get(`/supplier-action/${buyerToken}`)).text).toContain('Link No Longer Valid');

    const supplierPage = (await visitor.get(`/supplier-action/${supplierToken}`)).text;
    const order = await orderRepository.find(orderId);
    expect(supplierPage).toContain(`SEP-SPO-${orderId}`);
    for (const clientDetail of [order.company_legal_name, 'BUYER-INQ-7781', order.order_reference]) {
      expect(supplierPage).not.toContain(clientDetail);
    }
  });

  it('the Supplier PO document carries nothing about the client', async () => {
    const [orderId] = await orderWithSupplierPo();
    const order = await orderRepository.find(orderId);
    const data = await documentDataAssembler.assemble(orderId, 'SUPPO');
    const json = JSON.stringify(data);

    expect(data.supplier_po.supplier_po_reference).toBe(`SEP-SPO-${orderId}`);
    expect(json).toContain('Kandla CFS');
    for (const clientDetail of [order.company_legal_name, 'BUYER-INQ-7781', order.order_reference, order.client_email].filter(Boolean)) {
      expect(json).not.toContain(clientDetail);
    }
    expect(data.buyer).toEqual({});
    expect(data.products).toEqual([]);
    expect(data.payment_status).toBeNull();
    expect(data.order.has_active_amendment).toBe(false);
    expect(data.financial.advance_pct).toBe('30');

    // A buyer-facing document still gets the full client context.
    expect((await documentDataAssembler.assemble(orderId, 'PI')).buyer.company_legal_name).toBe(order.company_legal_name);
  });

  it('the Supplier PO templates reference no client data', () => {
    for (const file of [
      path.join(__dirname, '../../templates/SUPPO/supplier_po.njk'),
      path.join(__dirname, '../../../../PHP_MySQL_Version/app/templates/SUPPO/supplier_po.html.twig'),
    ]) {
      const template = fs.readFileSync(file, 'utf8');
      expect(template).not.toMatch(/\b(buyer|consignee|notify_party_block|products|financial|payment_status)\./);
      expect(template).not.toMatch(/order\.(buyer_inquiry_ref|pi_ref|quotation_ref|buyers_po_ref|order_reference)/);
    }
  });
});
