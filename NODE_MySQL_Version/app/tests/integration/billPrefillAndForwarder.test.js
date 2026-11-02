'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { todayIst } = require('../../src/helpers/dbDateTime');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const vendorBillsView = require('../../src/helpers/vendorBillsView');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of BillPrefillAndForwarderTest.php. Owner requests (2 Nov 2026):
 * - "I am not sure what to put as taxable value — can you prefill taxable value / amount asked
 *   now": the supplier bill forms (7e advance, 10a balance) start from the Supplier PO — its value
 *   before GST, its GST rate, and on the advance the amount still to ask for — with a hint naming
 *   the PO; the logistics bill form says what to type instead.
 * - Step 9a: the Freight Forwarder drop-down starts on the logistics partner already used on this order.
 */
describe('bill forms start from what the app knows; 9a preselects the forwarder', () => {
  let adminId;
  let agent;

  beforeAll(async () => {
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function orderWithSupplierPo(incoterm = 'FOB', passedUpTo = 4) {
    const orderId = await createTestOrder(await createTestClient(), incoterm);
    for (let n = 1; n <= passedUpTo + 1; n += 1) {
      await db.execute('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)', { s: n <= passedUpTo ? 'gate_passed' : 'in_progress', id: orderId, n });
    }
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Prefill Quarry Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `PF-SPO-${orderId}`, {
      material_stone_type: null, grade: null, surface_finish: null, dimensions: null,
      dimensional_tolerance: null, quantity: '100', unit: 'sqm', colour_reference: null,
      special_requirements: null, unit_price_inr: '450', basic_value_inr: '45000', gst_rate_pct: '5',
      gst_amount_inr: '2250', total_payable_inr: '47250', advance_pct: '30',
      advance_amount_inr: '14175', balance_amount_inr: '33075',
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
    }, 'issued');
    return [orderId, await orderSupplierPoRepository.findLatestForOrder(orderId)];
  }

  const page = async (orderId) => (await agent.get(`/orders/${orderId}`)).text;

  function supplierForm(html, leg) {
    const start = html.indexOf(`<input type="hidden" name="bill_for" value="${leg}">`);
    expect(start).toBeGreaterThan(-1);
    return html.slice(start, html.indexOf('</form>', start));
  }

  it('the advance bill form starts from the Supplier PO', async () => {
    const [orderId, po] = await orderWithSupplierPo();
    const form = supplierForm(await page(orderId), 'advance');
    const ref = po.supplier_po_reference;
    expect(form).toMatch(/name="taxable_inr"[^>]*value="45000\.00"/);
    expect(form).toContain(`Pre-filled from Supplier PO ${ref} — change it if the bill shows a different amount.`);
    expect(form).toMatch(/<option value="5(?:\.00)?" selected>/);
    expect(form).toContain('data-gst-amount value="2250.00"');
    expect(form).toContain('data-gst-total value="47250.00"');
    expect(form).toMatch(/name="requested_amount_inr"[^>]*value="14175\.00"/);
    expect(form).toContain(`Pre-filled with the advance still to ask for under Supplier PO ${ref}`);
  });

  it('what was already paid on an advance proforma is not asked for again', async () => {
    const [orderId, po] = await orderWithSupplierPo();
    const proforma = await orderVendorBillRepository.create(orderId, 'supplier', {
      supplier_po_id: po.id, bill_for: 'advance', doc_kind: 'proforma', requested_amount_inr: 10000,
      bill_no: 'SPI-PART', taxable_inr: 45000, gst_inr: 2250, bill_date: todayIst(),
    }, adminId);
    await orderVendorBillRepository.addPayment(proforma, 'advance', todayIst(), 10000, 'NEFT / RTGS', 'UTR-PART', adminId);
    const form = supplierForm(await page(orderId), 'advance');
    expect(form).toMatch(/name="requested_amount_inr"[^>]*value="4175\.00"/);
  });

  it('the balance bill form starts from the PO too, with no asked amount', async () => {
    const [orderId] = await orderWithSupplierPo('FOB', 6);
    const form = supplierForm(await page(orderId), 'balance');
    expect(form).toMatch(/name="taxable_inr"[^>]*value="45000\.00"/);
    expect(form).not.toMatch(/name="requested_amount_inr"[^>]*value=/);
  });

  it('the logistics bill form says what to type without guessing', async () => {
    const [orderId] = await orderWithSupplierPo('CIF', 6);
    const html = await page(orderId);
    const start = html.indexOf(`action="/orders/${orderId}/vendor-bills/logistics"`);
    expect(start).toBeGreaterThan(-1);
    const form = html.slice(start, html.indexOf('</form>', start));
    expect(form).toMatch(/The amount before GST printed on the partner(?:'|&#39;)s bill/);
    expect(form).not.toMatch(/name="taxable_inr"[^>]*value=/);
  });

  it('the suggested forwarder', () => {
    const partners = [{ id: 1, service_type: 'both' }, { id: 2, service_type: 'freight_forwarder' }, { id: 3, service_type: 'cha' }];
    const bill = (id) => ({ vendor_kind: 'logistics', logistics_partner_id: id });
    expect(vendorBillsView.suggestedForwarderId({ freight_forwarder_partner_id: 3 }, [bill(1)], partners)).toBe(3);
    expect(vendorBillsView.suggestedForwarderId(null, [bill(1), bill(1)], partners)).toBe(1);
    expect(vendorBillsView.suggestedForwarderId(null, [bill(1), bill(2)], partners)).toBe(2);
    expect(vendorBillsView.suggestedForwarderId(null, [bill(1), bill(3)], partners)).toBeNull();
    expect(vendorBillsView.suggestedForwarderId(null, [], partners)).toBeNull();
    expect(vendorBillsView.suggestedForwarderId(null, [{ vendor_kind: 'supplier', logistics_partner_id: 1 }], partners)).toBeNull();
  });

  it('Step 9a preselects the partner already billing this order', async () => {
    const [orderId] = await orderWithSupplierPo('CIF', 5);
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Prefill CHA & Transport', service_type: 'both' }, adminId);
    await orderVendorBillRepository.create(orderId, 'logistics', {
      logistics_partner_id: partnerId, bill_for: 'cha_transport', doc_kind: 'proforma', bill_no: 'LP-1',
      taxable_inr: 1000, gst_inr: 180, bill_date: todayIst(),
    }, adminId);
    const html = await page(orderId);
    const start = html.indexOf('name="freight_forwarder_partner_id"');
    expect(start).toBeGreaterThan(-1);
    const select = html.slice(start, html.indexOf('</select>', start));
    expect(select).toContain(`<option value="${partnerId}" selected>Prefill CHA &amp; Transport`);
    expect(select.split(' selected>').length - 1).toBe(1);
    expect(html).toContain('Pre-selected: the logistics partner already used on this order');
  });
});
