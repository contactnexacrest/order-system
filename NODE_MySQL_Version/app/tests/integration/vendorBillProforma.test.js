'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const { todayIst } = require('../../src/helpers/dbDateTime');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const vendorBillController = require('../../src/controllers/vendorBillController');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const orderLedgerService = require('../../src/services/orderLedgerService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of VendorBillProformaTest.php. Owner decision (2026-10-28, Option B,
 * approved mockups m1–m6): a supplier or logistics bill is a Proforma (asks
 * for money now — not a cost) or a Tax invoice (the cost). A proforma can say
 * how much it asks for now; a tax invoice can replace an earlier proforma
 * from the same vendor, so what was paid on it counts towards the invoice; a
 * bill takes several payments; a 7e tax invoice still owed is shown in 10a;
 * Step 12, the ledger and CAFIN count tax invoices plus what was paid on
 * proformas not yet replaced.
 */
describe('vendor bills: proforma and tax invoice (Option B)', () => {
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
    await db.pool.end();
  });

  const fakeReq = (params, body, file = null) => ({ params, body, file, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' }, permissions: { ca_fy_lock_override: true } });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : { type: null, message: '' };
  };

  async function orderWithSupplierPo() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    for (const [n, s] of [[1, 'gate_passed'], [2, 'gate_passed'], [3, 'gate_passed'], [4, 'gate_passed'], [5, 'in_progress']]) {
      await db.execute('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)', { s, id: orderId, n });
    }
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Proforma Quarry Co' });
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

  const bill = (orderId, vendorKind, extra) => orderVendorBillRepository.create(orderId, vendorKind, { bill_date: todayIst(), gst_inr: 0, ...extra }, adminId);

  async function pay(orderId, billId, amount, leg = null) {
    const body = { paid_at: todayIst(), paid_amount_inr: String(amount), payment_mode: 'NEFT / RTGS', payment_ref: `UTR${billId}-${amount}`, ...(leg !== null ? { leg } : {}) };
    const req = fakeReq({ id: String(orderId), billId: String(billId) }, body);
    await vendorBillController.payBill(req, fakeRes());
    return lastFlash(req);
  }

  it('an advance proforma then its tax invoice pays the supplier exactly once', async () => {
    let [orderId, po] = await orderWithSupplierPo();
    const proforma = await bill(orderId, 'supplier', { supplier_po_id: po.id, bill_for: 'advance', doc_kind: 'proforma', requested_amount_inr: 14175, bill_no: 'SPI-1', taxable_inr: 45000, gst_inr: 2250 });
    expect((await orderVendorBillRepository.find(proforma)).remaining).toBe(14175);

    expect((await pay(orderId, proforma, 14175, 'advance')).type).toBe('success');
    po = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(Number(po.advance_paid_amount_inr)).toBe(14175);
    expect((await orderVendorBillRepository.find(proforma)).settled).toBe(true);

    const invoice = await bill(orderId, 'supplier', { supplier_po_id: po.id, bill_for: 'balance', doc_kind: 'invoice', replaces_bill_id: proforma, bill_no: 'TI-1', taxable_inr: 45000, gst_inr: 2250 });
    const inv = await orderVendorBillRepository.find(invoice);
    expect(inv.credited).toBe(14175);
    expect(inv.remaining).toBe(33075);
    expect((await orderVendorBillRepository.find(proforma)).replaced_by).toBe('TI-1');
    expect((await orderVendorBillRepository.find(proforma)).cost_inr).toBe(0);

    expect((await pay(orderId, invoice, 47250, 'balance')).message).toBe('That is more than is still due on bill TI-1 (₹33,075.00).');
    expect((await pay(orderId, proforma, 100, 'advance')).message).toBe('Nothing is due on bill SPI-1 — it was replaced by tax invoice TI-1.');

    expect((await pay(orderId, invoice, 33075, 'balance')).type).toBe('success');
    po = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(Number(po.balance_paid_amount_inr)).toBe(33075);
    expect((await orderVendorBillRepository.find(invoice)).settled).toBe(true);
    expect(String((await orderVendorBillRepository.find(invoice)).paid_at)).toBe(todayIst());

    const snapshot = await caFinancialSnapshotService.build(orderId);
    expect(snapshot.supplier.paid_total).toBe('47,250.00');
    expect(snapshot.supplier.paid_check).toBe('= Total payable ✓');
    expect(snapshot.supplier.bills[0].bill).toContain('Proforma SPI-1');
    expect(snapshot.supplier.bills[0].bill).toContain('asks ₹14,175.00 now — replaced by tax invoice TI-1, not a cost');
    expect(snapshot.supplier.bills[1].bill).toContain('Tax invoice TI-1');
    expect(snapshot.supplier.bills[1].bill).toContain('replaces proforma SPI-1');
    expect(snapshot.supplier.bills[1].paid).toContain('₹14,175.00 on');
    expect(snapshot.supplier.bills[1].paid).toContain('(on proforma SPI-1); ₹33,075.00 on');
  });

  it('one supplier tax invoice is paid from 7e and then 10a', async () => {
    const [orderId, po] = await orderWithSupplierPo();
    const invoice = await bill(orderId, 'supplier', { supplier_po_id: po.id, bill_for: 'advance', bill_no: 'TI-ALL', taxable_inr: 45000, gst_inr: 2250 });
    expect((await pay(orderId, invoice, 14175, 'advance')).type).toBe('success');
    expect((await orderVendorBillRepository.find(invoice)).settled).toBe(false);
    expect(await orderVendorBillRepository.deleteUnpaid(invoice)).toBe(false);

    const { text } = await agent.get(`/orders/${orderId}`);
    expect(text).toContain('Added in Step 7e — shown here until fully paid');
    expect(text).toContain('Record another payment');

    expect((await pay(orderId, invoice, 33075, 'balance')).type).toBe('success');
    expect(await orderVendorBillRepository.supplierLegPaid(po.id, 'advance')).toEqual([14175, todayIst()]);
    expect(await orderVendorBillRepository.supplierLegPaid(po.id, 'balance')).toEqual([33075, todayIst()]);
    expect((await orderVendorBillRepository.find(invoice)).payments).toHaveLength(2);
  });

  it('Step 12 and the ledger count tax invoices and unreplaced proforma payments', async () => {
    const [orderId] = await orderWithSupplierPo();
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Proforma CHA', service_type: 'cha' }, adminId);
    const proforma = await bill(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: 'cha', payment_kind: 'advance', doc_kind: 'proforma', bill_no: 'CHA-PI', taxable_inr: 5000 });
    expect((await orderProfitabilityService.computeForOrder(orderId)).logistics_cost_inr).toBe(0);

    expect((await pay(orderId, proforma, 5000)).type).toBe('success');
    expect((await orderProfitabilityService.computeForOrder(orderId)).logistics_cost_inr).toBe(5000);

    const invoice = await bill(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: 'cha', doc_kind: 'invoice', replaces_bill_id: proforma, bill_no: 'CHA-TI', taxable_inr: 12000 });
    expect((await orderProfitabilityService.computeForOrder(orderId)).logistics_cost_inr).toBe(12000);
    expect((await orderVendorBillRepository.find(invoice)).remaining).toBe(7000);

    expect((await pay(orderId, invoice, 3000)).type).toBe('success');
    expect((await pay(orderId, invoice, 4000)).type).toBe('success');
    expect((await orderVendorBillRepository.find(invoice)).settled).toBe(true);

    const ledger = await orderLedgerService.forOrder(orderId, 'USD', true);
    expect(ledger.totals.logistics_inr).toBe(12000);
    expect(ledger.rows.filter((r) => r.event.startsWith('Logistics paid'))).toHaveLength(3);

    const snapshot = await caFinancialSnapshotService.build(orderId);
    expect(snapshot.logistics_total).toBe('12,000.00');
    expect(snapshot.logistics_paid).toBe('12,000.00');
    expect(snapshot.logistics_due).toBe('0.00');
  });

  it('checks the bill type fields', async () => {
    const [orderId] = await orderWithSupplierPo();
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Check CHA', service_type: 'cha' }, adminId);
    const otherId = await logisticsPartnerRepository.create({ partner_name: 'Other CHA', service_type: 'cha' }, adminId);
    const otherProforma = await bill(orderId, 'logistics', { logistics_partner_id: otherId, bill_for: 'cha', doc_kind: 'proforma', bill_no: 'OTHER-PI', taxable_inr: 1000 });

    const base = { logistics_partner_id: String(partnerId), bill_for: 'cha', payment_kind: 'final', bill_no: 'CHK-1', bill_date: todayIst(), taxable_inr: '1000', gst_rate_pct: '18' };
    const file = { originalname: 'bill.pdf', mimetype: 'application/pdf', size: 13, buffer: Buffer.from('%PDF-1.4 bill') };
    const cases = [
      ['Choose whether the bill is a Proforma or a Tax invoice.', { doc_kind: 'quote' }],
      ['The amount asked now cannot be more than the bill total (₹1,180.00).', { doc_kind: 'proforma', requested_amount_inr: '1500' }],
      ['That proforma cannot be linked — it is from another vendor, or already replaced by an invoice.', { doc_kind: 'invoice', replaces_bill_id: String(otherProforma) }],
    ];
    for (const [expected, fields] of cases) {
      const req = fakeReq({ id: String(orderId) }, { ...base, ...fields }, file);
      await vendorBillController.addLogisticsBill(req, fakeRes());
      expect(lastFlash(req).message).toBe(expected);
    }
    expect(await orderVendorBillRepository.forOrder(orderId)).toHaveLength(1);
  });
});
