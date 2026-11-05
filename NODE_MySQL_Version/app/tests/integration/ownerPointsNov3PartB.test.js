'use strict';

const path = require('path');
const fs = require('fs');
const nunjucks = require('nunjucks');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const { todayIst } = require('../../src/helpers/dbDateTime');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const vendorBillController = require('../../src/controllers/vendorBillController');
const ordersController = require('../../src/controllers/ordersController');
const stageGateService = require('../../src/services/stageGateService');
const courierCharges = require('../../src/services/courierCharges');
const vendorBillsView = require('../../src/helpers/vendorBillsView');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OwnerPointsNov3PartBTest.php. Owner points of 3 Nov 2026 (part B):
 *  4 — logistics bill: Payment "Advance" goes with "Proforma / advance request", "Final" with "Tax invoice / final bill";
 *  5 — a read-only, system-calculated "To pay now" with its working on every payment form;
 *  6 — the bills table "Total cost" row counts the advance paid on a replaced proforma, once
 *      (screenshot: ₹196,664.70 + ₹458,884.30 = ₹655,549.00, Nothing due);
 *  7 — "Paid from" / "Paid by" default to Company account;
 *  8 — Step 13f courier charges booked as the order cost "Courier Charges" with the D-212 description;
 *  9 — Step 12d hidden while the team incentive program is off, unless an associate's commission is due.
 */
describe('owner points of 3 Nov 2026 — part B', () => {
  let adminId;
  let agent;

  beforeAll(async () => {
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await incentiveRepository.setSetting('program_enabled', '0', adminId);
    await db.pool.end();
  });

  const fakeReq = (params, body, file = null) => ({ params, body, file, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' }, permissions: { ca_fy_lock_override: true, close_orders: true } });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : { type: null, message: '' };
  };
  const page = async (orderId) => (await agent.get(`/orders/${orderId}`)).text;

  /** The screenshot's order: Supplier PO ₹655,549.00, advance ₹196,664.70. */
  async function orderWithSupplierPo() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    for (let n = 1; n <= 8; n += 1) {
      await db.execute('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)', { s: n <= 7 ? 'gate_passed' : 'in_progress', id: orderId, n });
    }
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Nov3 Quarry Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `N3-SPO-${orderId}`, {
      material_stone_type: null, grade: null, surface_finish: null, dimensions: null,
      dimensional_tolerance: null, quantity: '100', unit: 'sqm', colour_reference: null,
      special_requirements: null, unit_price_inr: '6555.49', basic_value_inr: '655549', gst_rate_pct: '0',
      gst_amount_inr: '0', total_payable_inr: '655549', advance_pct: '30',
      advance_amount_inr: '196664.70', balance_amount_inr: '458884.30',
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
    }, 'issued');
    return [orderId, await orderSupplierPoRepository.findLatestForOrder(orderId)];
  }

  const bill = (orderId, vendorKind, extra) => orderVendorBillRepository.create(orderId, vendorKind, { bill_date: todayIst(), gst_inr: 0, ...extra }, adminId);

  async function pay(orderId, billId, amount, leg = null) {
    const body = { paid_at: todayIst(), paid_amount_inr: String(amount), payment_mode: 'NEFT / RTGS', payment_ref: `UTR${billId}-${amount}`, ...(leg !== null ? { leg } : {}) };
    const req = fakeReq({ id: String(orderId), billId: String(billId) }, body);
    await vendorBillController.payBill(req, fakeRes());
    return lastFlash(req);
  }

  it('4: Advance is a proforma and Final is the tax invoice', async () => {
    const { paymentKindMismatch } = vendorBillController;
    expect(paymentKindMismatch('advance', 'proforma')).toBeNull();
    expect(paymentKindMismatch('final', 'invoice')).toBeNull();
    const advanceMsg = 'An Advance bill is a Proforma / advance request — pick that bill type, or choose Payment "Final" for a tax invoice.';
    const finalMsg = 'A Final bill is the Tax invoice / final bill — pick that bill type, or choose Payment "Advance" for a proforma.';
    expect(paymentKindMismatch('advance', 'invoice')).toBe(advanceMsg);
    expect(paymentKindMismatch('final', 'proforma')).toBe(finalMsg);

    const [orderId] = await orderWithSupplierPo();
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Nov3 CHA', service_type: 'cha' }, adminId);
    const base = { logistics_partner_id: String(partnerId), bill_for: 'cha', bill_date: todayIst(), taxable_inr: '1000', gst_rate_pct: '18' };
    const file = { originalname: 'bill.pdf', mimetype: 'application/pdf', size: 13, buffer: Buffer.from('%PDF-1.4 bill') };
    for (const [expected, fields] of [
      [advanceMsg, { payment_kind: 'advance', doc_kind: 'invoice', bill_no: 'N3-A' }],
      [finalMsg, { payment_kind: 'final', doc_kind: 'proforma', bill_no: 'N3-B' }],
    ]) {
      const req = fakeReq({ id: String(orderId) }, { ...base, ...fields }, file);
      await vendorBillController.addLogisticsBill(req, fakeRes());
      expect(lastFlash(req).message).toBe(expected); // refused on the server, even when posted directly
    }
    expect(await orderVendorBillRepository.forOrder(orderId, 'logistics')).toHaveLength(0);
    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    expect(view).toContain("var want=s.value==='advance'?'proforma':'invoice'");
    expect(view).toContain('Advance = a Proforma / advance request; Final = the Tax invoice / final bill.');
  });

  it('5 + 6: To pay now and the Total row with the screenshot figures', async () => {
    const [orderId, po] = await orderWithSupplierPo();
    const proforma = await bill(orderId, 'supplier', { supplier_po_id: po.id, bill_for: 'advance', doc_kind: 'proforma', requested_amount_inr: 196664.70, bill_no: 'PI-7', taxable_inr: 655549 });
    expect(vendorBillsView.toPayNow(await orderVendorBillRepository.find(proforma))).toEqual({ amount: 196664.70, working: 'asked now on the proforma ₹196,664.70' });
    let bills = await orderVendorBillRepository.forOrder(orderId);
    expect(vendorBillsView.supplierLegToPayNow(bills, po, 'advance')).toEqual({ amount: 196664.70, working: 'planned advance ₹196,664.70 − already paid ₹0.00' });

    expect((await pay(orderId, proforma, 196664.70, 'advance')).type).toBe('success');
    const invoice = await bill(orderId, 'supplier', { supplier_po_id: po.id, bill_for: 'balance', doc_kind: 'invoice', replaces_bill_id: proforma, bill_no: 'TI-7', taxable_inr: 655549 });
    expect(vendorBillsView.toPayNow(await orderVendorBillRepository.find(invoice))).toEqual({ amount: 458884.30, working: 'bill total ₹655,549.00 − advance paid on proforma PI-7 ₹196,664.70' });
    bills = await orderVendorBillRepository.forOrder(orderId);
    expect(vendorBillsView.supplierLegToPayNow(bills, po, 'balance')).toEqual({ amount: 458884.30, working: 'PO total ₹655,549.00 − advance paid ₹196,664.70 − balance already paid ₹0.00' });
    expect(vendorBillsView.supplierLegToPayNow(bills, po, 'advance')).toEqual({ amount: 0, working: 'planned advance ₹196,664.70 − already paid ₹196,664.70' });

    let html = await page(orderId);
    expect(html).toContain('<strong>To pay now: &#8377;458,884.30</strong> <small class="muted">(bill total ₹655,549.00 − advance paid on proforma PI-7 ₹196,664.70)</small>');
    expect(html).toContain('<strong>Balance to pay now: &#8377;458,884.30</strong>');

    expect((await pay(orderId, invoice, 458884.30, 'balance')).type).toBe('success');
    bills = await orderVendorBillRepository.forOrder(orderId);
    const invoiceOnly = bills.filter((b) => Number(b.id) === Number(invoice));
    expect(vendorBillsView.totals(invoiceOnly)).toEqual({ cost: 655549, paid: 655549, due: 0 }); // 10a lists only the tax invoice
    expect(vendorBillsView.totals(bills)).toEqual({ cost: 655549, paid: 655549, due: 0 }); // both listed: the advance counted once
    html = await page(orderId);
    expect(html).toContain('<span class="vendor-bill-paid">Paid &#8377;655,549.00</span><br><span class="vendor-bill-paid">Nothing due</span>');
  });

  it('6: a logistics proforma payment counts once in the Total row', async () => {
    const [orderId] = await orderWithSupplierPo();
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Nov3 Forwarder', service_type: 'cha' }, adminId);
    const proforma = await bill(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: 'cha', payment_kind: 'advance', doc_kind: 'proforma', bill_no: 'CHA-PI', taxable_inr: 196664.70 });
    expect((await pay(orderId, proforma, 196664.70)).type).toBe('success');
    const invoice = await bill(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: 'cha', payment_kind: 'final', doc_kind: 'invoice', replaces_bill_id: proforma, bill_no: 'CHA-TI', taxable_inr: 655549 });
    expect(vendorBillsView.toPayNow(await orderVendorBillRepository.find(invoice)).working).toBe('bill total ₹655,549.00 − advance paid on proforma CHA-PI ₹196,664.70');
    expect((await pay(orderId, invoice, 458884.30)).type).toBe('success');
    expect(vendorBillsView.totals(await orderVendorBillRepository.forOrder(orderId, 'logistics'))).toEqual({ cost: 655549, paid: 655549, due: 0 });
  });

  it('5: the buyer "to pay now" line', () => {
    const html = nunjucks.render('orders/_remittance_difference.njk', { rd: { label: 'Balance', due: 12500, so_far: 4000, currency: 'USD', max_pct: 3 }, remittanceReasons: [] });
    expect(html).toContain('<p class="full to-pay-now"><strong>Buyer to pay now: USD 8,500.00</strong> <small class="muted">(balance due USD 12,500.00 − already received USD 4,000.00)</small></p>');
  });

  it('7: Paid from / Paid by default to Company account', async () => {
    const [orderId] = await orderWithSupplierPo();
    const html = await page(orderId);
    for (const name of ['paid_from', 'paid_by']) {
      const m = html.match(new RegExp(`<select name="${name}" required>([\\s\\S]*?)</select>`));
      expect(m).not.toBeNull();
      expect(m[1]).not.toContain('Choose…');
      expect(m[1]).toContain('<option value="company_account" selected>Company account</option>');
    }
  });

  async function createTestDocument(orderId, typeCode, status) {
    const docType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    await db.execute('INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :dt, :ref, :status)',
      { o: orderId, dt: docType.id, ref: `JEST-N3-${Math.random().toString(16).slice(2, 10)}`, status });
  }

  async function orderAtStage9() {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');
    // QA batch 4, ORD-14 (owner decision 5 Nov 2026): closing needs the original BLs received from the CHA and endorsed.
    await db.execute('UPDATE order_shipping SET bl_originals_received_at = NOW(), bl_originals_received_count = 3, bl_endorsed_at = NOW() WHERE order_id = :o', { o: orderId });
    for (let stage = 1; stage <= 8; stage += 1) await stageGateService.passAndUnlockNext(orderId, stage, null);
    await createTestDocument(orderId, 'BLI', 'approved');
    await createTestDocument(orderId, 'COOPREP', 'sent');
    return orderId;
  }

  async function close(orderId, body) {
    const req = fakeReq({ id: String(orderId) }, body);
    await ordersController.closeOrder(req, fakeRes());
    return lastFlash(req);
  }

  it('8: courier charges are checked and booked with their description', async () => {
    expect(courierCharges.parse({ courier_charges_inr: '' })).toBeNull();
    expect(courierCharges.parse({ courier_charges_inr: '0' })).toBeNull();
    expect(courierCharges.parse({ courier_charges_inr: '-5' })).toBe('Courier charges must be a number of rupees (0 or more), or leave the box blank.');
    expect(courierCharges.parse({ courier_charges_inr: 'abc' })).toBe('Courier charges must be a number of rupees (0 or more), or leave the box blank.');
    expect(courierCharges.parse({ courier_charges_inr: '100000.01' })).toBe('Courier charges of ₹100,000.01 look too high for one document courier (more than ₹100,000.00). Check the courier bill.');
    expect(courierCharges.parse({ courier_charges_inr: '10', courier_paid_from: 'cash' })).toBe('Choose what the courier charges were paid from.');
    expect(courierCharges.parse({ courier_charges_inr: '100000' })).toEqual({ amount: 100000, company: '', paid_from: 'company_account' });

    const orderId = await orderAtStage9();
    expect((await close(orderId, { courier_tracking_number: '1234567890', courier_charges_inr: '250000' })).message)
      .toBe('Courier charges of ₹250,000.00 look too high for one document courier (more than ₹100,000.00). Check the courier bill.');
    expect((await orderRepository.find(orderId)).status).toBe('active');

    await close(orderId, { courier_tracking_number: '1234567890', courier_company: 'DHL Express', courier_charges_inr: '3540', courier_paid_from: 'company_card' });
    const order = await orderRepository.find(orderId);
    expect(order.status).toBe('shipped_docs_sent');
    const entries = (await orderCostEntryRepository.forOrder(orderId)).filter((e) => e.category === 'courier');
    expect(entries).toHaveLength(1);
    expect(entries[0].description).toBe(`Courier charges for the original document set of order ${order.buyer_inquiry_ref} to ${order.company_legal_name} — DHL Express AWB 1234567890, ₹3,540.00 incl. GST`);
    expect(Number(entries[0].amount_inr)).toBe(3540);
    expect(entries[0].paid_from).toBe('company_card');
    expect(orderCostEntryRepository.CATEGORIES.courier).toBe('Courier Charges');
    expect(orderCostEntryRepository.ZOHO_CATEGORIES).toContain('courier'); // goes to Zoho Books at the next sync
    const audit = await db.queryOne("SELECT new_value FROM audit_log WHERE action_type = 'COURIER_CHARGES_RECORDED' AND entity_id = :id", { id: entries[0].id });
    expect(audit.new_value).toBe('3540.00');

    const other = await orderAtStage9();
    await close(other, { courier_tracking_number: 'AWB-2' });
    expect((await orderRepository.find(other)).status).toBe('shipped_docs_sent');
    expect((await orderCostEntryRepository.forOrder(other)).filter((e) => e.category === 'courier')).toHaveLength(0);
    expect(courierCharges.description({ buyer_inquiry_ref: 'NC/SC/2026/0411001', company_legal_name: 'Acme GmbH' }, 1, '', 'X1', 10.5))
      .toBe('Courier charges for the original document set of order NC/SC/2026/0411001 to Acme GmbH — AWB X1, ₹10.50 incl. GST');
  });

  // Point 9 (3 Nov), revised by D-245 P4: with the program off there is no global team incentive, but the MD
  // may still set one by hand on one order — so the MD (manage_pricing) still sees Step 12d, marked "program off".
  it('9: Step 12d follows the incentive program', async () => {
    const plain = await createTestOrder(await createTestClient());
    await incentiveRepository.setSetting('program_enabled', '0', adminId);
    let html = await page(plain);
    expect(html).toContain('Step 12d &middot; Team Incentive');
    expect(html).toContain('The team incentive program is off');
    expect(html).toContain('program off — none');

    await incentiveRepository.setSetting('program_enabled', '1', adminId);
    html = await page(plain);
    expect(html).toContain('Step 12d &middot; Team Incentive');
    expect(html).toContain('Who gets it');
    expect(html).toContain("and the team's incentive");

    const associateId = (await db.execute("INSERT INTO associates (name) VALUES ('TEST-Associate Nov3')")).insertId;
    const client = await createTestClient();
    await db.execute('UPDATE clients SET associate_id = :a WHERE id = :c', { a: associateId, c: client });
    const withAssociate = await createTestOrder(client);
    await incentiveRepository.setSetting('program_enabled', '0', adminId);
    html = await page(withAssociate);
    expect(html).toContain("and the associate's commission");
    expect(html).toContain('Associate commission on this order');
    expect(html).not.toContain('Team reward');
    expect(html).not.toContain('Exclude this order (no team reward)');
  });
});
