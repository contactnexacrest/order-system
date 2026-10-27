'use strict';

const db = require('../../src/config/db');
const { todayIst } = require('../../src/helpers/dbDateTime');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../../src/repositories/orderExpenseRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const userRepository = require('../../src/repositories/userRepository');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CaFinancialSnapshotTest.php. Owner request (2026-10-27, approved
 * mockup, confirmed twice): the CAFIN internal annexure is the complete order
 * snapshot — A order details, B supplier cost, C every expense, D government
 * benefits, E profit, F open items — with readable cost names and the same
 * profit as the order page.
 */
describe('CAFIN complete order snapshot', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('covers the whole order in the approved order', async () => {
    const userId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Snapshot Quarry Co' });
    await orderSupplierPoRepository.create(orderId, supplierId, `SNAP-SPO-${orderId}`, {
      material_stone_type: 'Black granite', grade: null, surface_finish: null, dimensions: null,
      dimensional_tolerance: null, quantity: '100.000', unit: 'sqm', colour_reference: null,
      special_requirements: null, unit_price_inr: '450', basic_value_inr: '45000', gst_rate_pct: null,
      gst_amount_inr: '0', total_payable_inr: '45000', advance_pct: null,
      advance_amount_inr: null, balance_amount_inr: null,
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
    }, 'signed');
    const po = await orderSupplierPoRepository.findLatestForOrder(orderId);
    const bill = await orderVendorBillRepository.create(orderId, 'supplier', { supplier_po_id: po.id, bill_for: 'balance', bill_no: 'SNAP-TI-1', bill_date: todayIst(), taxable_inr: 45000, gst_inr: 0 }, userId);
    await orderVendorBillRepository.markPaid(bill, todayIst(), 45000, 'NEFT / RTGS', 'UTR-SNAP', userId);
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Snapshot CHA', service_type: 'cha' }, userId);
    await orderVendorBillRepository.create(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: 'cha', bill_no: 'SNAP-CHA-1', bill_date: todayIst(), taxable_inr: 18500, gst_inr: 0 }, userId);
    await orderCostEntryRepository.create(orderId, 'ecgc_insurance', 'ECGC cover', 5000, todayIst(), userId);
    await orderExpenseRepository.create(orderId, { expense_date: todayIst(), category: 'hotel', details: 'Hotel Kota', amount_inr: 3540, paid_by: 'staff', staff_name: 'Ramesh', no_receipt_reason: 'No bill given at the hotel' }, userId);
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'R-1', 5000, todayIst(), 'INR', null, userId);

    const s = await caFinancialSnapshotService.build(orderId);

    expect(s.details[0][0]).toBe('Order Reference');
    expect(s.details[3][1]).toContain('CIF');
    expect(s.supplier.rows[1][3]).toBe('100 sqm × ₹450.00');
    expect(s.supplier.paid_total).toBe('45,000.00');
    expect(s.supplier.paid_check).toBe('= Total payable ✓');
    expect(s.logistics[0].partner).toBe('Snapshot CHA');
    expect(s.logistics[0].unpaid).toBe(true);
    expect(s.costs[0].category).toBe('ECGC Insurance');
    expect(s.expenses[0].paid_by).toBe('Staff (to reimburse) — Ramesh');
    expect(s.benefits[0].status).toBe('Claimed / Pending');

    const profit = await orderProfitabilityService.computeForOrder(orderId);
    const grand = s.pl.find((r) => r[1] === 'ORDER PROFIT');
    expect(grand[3]).toBe(profit.profit_inr.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    expect(s.summary[1][1]).toBe('18,500.00');
    expect(s.summary[3][1]).toBe('3,540.00');

    const openText = s.open.map((o) => o.text).join(' | ');
    expect(openText).toContain('Unpaid bill — Snapshot CHA SNAP-CHA-1, ₹18,500.00');
    expect(openText).toContain('Expenses without a receipt: 1 · Staff to reimburse: ₹3,540.00');
    expect(openText).toContain('1 government benefit claim(s) not yet received');
  });
});
