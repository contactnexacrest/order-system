'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const financialYear = require('../../src/helpers/financialYear');
const { todayIst } = require('../../src/helpers/dbDateTime');
const caFyLockRepository = require('../../src/repositories/caFyLockRepository');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../../src/repositories/orderExpenseRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const vendorBillController = require('../../src/controllers/vendorBillController');
const orderExpenseController = require('../../src/controllers/orderExpenseController');
const orderLedgerService = require('../../src/services/orderLedgerService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of VendorBillsAndExpensesTest.php. Owner decisions (2026-10-27): bill
 * first, then pay — the supplier's bills (7e advance, 10a balance) and the
 * logistics partners' bills (CHA, transport, forwarder; on CFR/CIF payable
 * only after 9d), the order's actual expenses with receipts (travel replaces
 * the provisional %), a warning (not a block) on 13h for unpaid bills, and
 * all of it in Step 12 and the ledger.
 */
describe('vendor bills and order expenses', () => {
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

  const fakeReq = (params, body, permissions = { ca_fy_lock_override: true }) => ({ params, body, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' }, permissions });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : { type: null, message: '' };
  };

  async function setStages(orderId, numbers, status) {
    for (const n of numbers) {
      await db.execute('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)', { s: status, id: orderId, n });
    }
  }

  async function orderWithSupplierPo(incoterm = 'FOB') {
    const orderId = await createTestOrder(await createTestClient(), incoterm);
    await setStages(orderId, [1, 2, 3, 4], 'gate_passed');
    await setStages(orderId, [5], 'in_progress');
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Bills Quarry Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `BILL-SPO-${orderId}`, {
      material_stone_type: null, grade: null, surface_finish: null, dimensions: null,
      dimensional_tolerance: null, quantity: '100', unit: 'sqm', colour_reference: null,
      special_requirements: null, unit_price_inr: '450', basic_value_inr: '45000', gst_rate_pct: '5',
      gst_amount_inr: '2250', total_payable_inr: '47250', advance_pct: '30',
      advance_amount_inr: '14175', balance_amount_inr: '33075',
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
    }, 'issued');
    return [orderId, await orderSupplierPoRepository.findLatestForOrder(orderId)];
  }

  const supplierBill = (orderId, poId, leg, taxable, gst) => orderVendorBillRepository.create(orderId, 'supplier', { supplier_po_id: poId, bill_for: leg, bill_no: `${leg.toUpperCase()}-1`, bill_date: todayIst(), taxable_inr: taxable, gst_inr: gst }, adminId);
  const logisticsBill = (orderId, partnerId, forWhat, total) => orderVendorBillRepository.create(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: forWhat, bill_no: `LB-${forWhat}`, bill_date: todayIst(), taxable_inr: total, gst_inr: 0 }, adminId);

  async function pay(orderId, billId, amount) {
    const req = fakeReq({ id: String(orderId), billId: String(billId) }, { paid_at: todayIst(), paid_amount_inr: String(amount), payment_mode: 'NEFT / RTGS', payment_ref: `UTR${billId}` });
    await vendorBillController.payBill(req, fakeRes());
    return lastFlash(req);
  }
  const clearFreight = (orderId) => db.execute('INSERT INTO order_payment_status (order_id, freight_cleared_at) VALUES (:id, NOW()) ON DUPLICATE KEY UPDATE freight_cleared_at = NOW()', { id: orderId });

  it('needs the bill details and the bill attached', async () => {
    const [orderId] = await orderWithSupplierPo();
    const body = { bill_for: 'advance', bill_no: 'SPI-1', bill_date: todayIst(), taxable_inr: '13500', gst_rate_pct: '5' };
    let req = fakeReq({ id: String(orderId) }, body);
    await vendorBillController.addSupplierBill(req, fakeRes());
    expect(lastFlash(req).message).toBe('Attach the bill / invoice.');
    req = fakeReq({ id: String(orderId) }, { ...body, bill_date: '2999-01-01' });
    await vendorBillController.addSupplierBill(req, fakeRes());
    expect(lastFlash(req).message).toContain('not in the future');
    req = fakeReq({ id: String(orderId) }, { ...body, gst_rate_pct: '7' });
    await vendorBillController.addSupplierBill(req, fakeRes());
    expect(lastFlash(req).message).toBe('Choose the GST rate (%) on the bill.');
    expect(await orderVendorBillRepository.forOrder(orderId)).toEqual([]);
  });

  it('records the supplier advance and balance from the bills paid', async () => {
    const [orderId, po] = await orderWithSupplierPo();
    const balance = await supplierBill(orderId, po.id, 'balance', 31500, 1575);
    expect((await pay(orderId, balance, 33075)).message).toContain('Pay the supplier advance (Step 7e) first');

    const advance = await supplierBill(orderId, po.id, 'advance', 13500, 675);
    expect((await pay(orderId, advance, 14175)).type).toBe('success');
    let current = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(String(current.advance_paid_at).slice(0, 10)).toBe(todayIst());
    expect(Number(current.advance_paid_amount_inr)).toBe(14175);
    expect((await pay(orderId, advance, 14175)).type).toBe('error');

    expect((await pay(orderId, balance, 33075)).type).toBe('success');
    current = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(Number(current.balance_paid_amount_inr)).toBe(33075);
    expect(await orderVendorBillRepository.deleteUnpaid(advance)).toBe(false);
  });

  it('logistics bills on CIF wait for 9d, on FOB they do not', async () => {
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Bills CHA & Transport', service_type: 'both' }, adminId);
    const [cifOrder] = await orderWithSupplierPo('CIF');
    const bill = await logisticsBill(cifOrder, partnerId, 'cha', 18500);
    expect((await pay(cifOrder, bill, 18500)).message).toContain('Debit Note payment has cleared (Step 9d)');
    await clearFreight(cifOrder);
    expect((await pay(cifOrder, bill, 18500)).type).toBe('success');

    const [fobOrder] = await orderWithSupplierPo('FOB');
    const fobBill = await logisticsBill(fobOrder, partnerId, 'transport', 22000);
    expect((await pay(fobOrder, fobBill, 22000)).type).toBe('success');
  });

  // Owner decision (2026-10-27): a logistics Advance bill is paid any time; only the Final bill waits for 9d.
  it('a logistics Advance bill is payable before 9d on CIF', async () => {
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Advance Forwarder', service_type: 'both' }, adminId);
    const [cifOrder] = await orderWithSupplierPo('CIF');
    const advance = await orderVendorBillRepository.create(cifOrder, 'logistics', { logistics_partner_id: partnerId, bill_for: 'ocean_freight', payment_kind: 'advance', bill_no: 'OF-ADV', bill_date: todayIst(), taxable_inr: 50000, gst_inr: 0 }, adminId);
    const final = await logisticsBill(cifOrder, partnerId, 'cha', 18500);

    expect((await orderVendorBillRepository.find(advance)).payment_kind).toBe('advance');
    expect((await orderVendorBillRepository.find(final)).payment_kind).toBe('final');
    expect((await pay(cifOrder, advance, 50000)).type).toBe('success');
    expect((await pay(cifOrder, final, 18500)).message).toContain('Step 9d');
  });

  it('a logistics bill must say Advance or Final', async () => {
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Kind Check CHA', service_type: 'both' }, adminId);
    const [orderId] = await orderWithSupplierPo('CIF');
    const req = fakeReq({ id: String(orderId) }, { logistics_partner_id: String(partnerId), bill_for: 'cha', payment_kind: '', bill_no: 'K-1', bill_date: todayIst(), taxable_inr: '1000', gst_rate_pct: '18' });
    await vendorBillController.addLogisticsBill(req, fakeRes());
    expect(lastFlash(req).message).toBe('Choose whether the bill is an Advance or the Final bill.');
  });

  it('an expense needs a receipt or a reason and respects the FY lock', async () => {
    const [orderId] = await orderWithSupplierPo();
    const base = { expense_date: todayIst(), category: 'hotel', details: 'Hotel Kota, 1 night', amount_inr: '3540', paid_by: 'company_card' };
    let req = fakeReq({ id: String(orderId) }, base);
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).message).toContain('Attach the receipt');

    req = fakeReq({ id: String(orderId) }, { ...base, paid_by: 'staff', no_receipt_reason: 'Roadside stop — no bill given' });
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).message).toContain('staff member');

    req = fakeReq({ id: String(orderId) }, { ...base, paid_by: 'staff', staff_name: 'Ramesh', no_receipt_reason: 'Roadside stop — no bill given' });
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).type).toBe('success');

    // A late voucher keeps its real date — unless that year is locked.
    const late = new Date(Date.now() - 400 * 86400000).toISOString().slice(0, 10);
    const lateBody = { ...base, expense_date: late, no_receipt_reason: 'Voucher written up at audit time' };
    req = fakeReq({ id: String(orderId) }, lateBody);
    await orderExpenseController.add(req, fakeRes());
    expect(lastFlash(req).type).toBe('success');
    const lateRow = (await orderExpenseRepository.forOrder(orderId)).find((e) => e.expense_date === late);
    expect(Number(lateRow.added_later)).toBe(1);

    const fy = financialYear.label(late);
    await caFyLockRepository.lock(fy, adminId);
    try {
      req = fakeReq({ id: String(orderId) }, lateBody, { ca_fy_lock_override: false });
      await orderExpenseController.add(req, fakeRes());
      expect(lastFlash(req).message).toContain('locked');
    } finally {
      await caFyLockRepository.unlock(fy, adminId, 'test cleanup');
    }
    expect(await orderExpenseRepository.forOrder(orderId)).toHaveLength(2);
  });

  it('Step 12 and the ledger count bills and expenses once each', async () => {
    const [orderId] = await orderWithSupplierPo('CIF');
    await db.execute('INSERT INTO order_freight (order_id, confirmed_freight_rate, insurance_amount) VALUES (:id, 90000, 1000) ON DUPLICATE KEY UPDATE confirmed_freight_rate = 90000, insurance_amount = 1000', { id: orderId });
    const travelEntry = await orderCostEntryRepository.create(orderId, 'travel_expenses', 'Travel 7% of FOB', 50000, todayIst(), adminId);
    const before = await orderProfitabilityService.computeForOrder(orderId);
    expect(before.freight_cost_inr).toBe(90000);
    expect(before.travel_is_provisional).toBe(true);

    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Bills Forwarder', service_type: 'freight_forwarder' }, adminId);
    await logisticsBill(orderId, partnerId, 'ocean_freight', 85000);
    const cha = await logisticsBill(orderId, partnerId, 'cha', 18500);
    await orderExpenseRepository.create(orderId, { expense_date: todayIst(), category: 'travel_fuel', details: 'Factory visit', amount_inr: 6200, paid_by: 'company_account', no_receipt_reason: null }, adminId);

    const after = await orderProfitabilityService.computeForOrder(orderId);
    expect(after.freight_cost_inr).toBe(85000);
    expect(after.freight_from_bills).toBe(true);
    expect(after.logistics_cost_inr).toBe(18500);
    expect(after.expenses_inr).toBe(6200);
    expect(after.travel_is_provisional).toBe(false);
    expect(after.total_cost_inr).toBeCloseTo(before.total_cost_inr - 90000 + 85000 + 18500 + 6200 - 50000, 2);

    await clearFreight(orderId);
    await pay(orderId, cha, 18500);
    const ledger = await orderLedgerService.forOrder(orderId, 'USD', true);
    expect(ledger.totals.logistics_inr).toBe(18500);
    expect(ledger.totals.expenses_inr).toBe(6200);
    expect(await orderCostEntryRepository.find(travelEntry)).not.toBeNull();
  });

  it('the order page shows the bills and warns on 13h', async () => {
    const [orderId, po] = await orderWithSupplierPo('CIF');
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Page CHA Co', service_type: 'cha' }, adminId);
    await supplierBill(orderId, po.id, 'advance', 13500, 675);
    await logisticsBill(orderId, partnerId, 'cha', 18500);
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: orderId });

    const { text } = await agent.get(`/orders/${orderId}`);
    expect(text).toContain('id="logistics-bills"');
    expect(text).toContain('Page CHA Co');
    expect(text).toContain('ADVANCE-1');
    expect(text).toContain("Debit Note payment has cleared (Step 9d)");
    expect(text).toContain('the Supplier PO terms plan an advance of ₹14,175.00.');
    expect(text).toContain('id="unpaid-vendor-bills-warning"');
    expect(text).toContain('you can still mark the order fully settled');
    expect(text).toContain('id="order-expenses"');
  });

  // Batch 5 parity: a Supplier PO with no advance entered means nothing to pay in 7e, on both stacks.
  it('a blank supplier advance means nothing to pay in 7e', async () => {
    const [orderId] = await orderWithSupplierPo('CIF');
    await db.execute('UPDATE order_supplier_po SET advance_pct = NULL, advance_amount_inr = NULL WHERE order_id = :id', { id: orderId });

    const { text } = await agent.get(`/orders/${orderId}`);
    expect(text).toContain('No advance on this order');
  });
});
