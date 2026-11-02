'use strict';

// Owner decision D6 of 2 Nov 2026 (confirmed twice; register D-225): the
// Step 9a confirmed freight rate and insurance are entered in the ORDER's
// currency — the Debit Note charges the buyer exactly that — so Step 12, the
// CA annexure (CAFIN), the reports and the incentive must count them in
// rupees at a real rate, never add USD 6,000 as ₹6,000.
// PHP twin: FreightCostInInrTest.php.

const db = require('../../src/config/db');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderVendorBillRepository = require('../../src/repositories/orderVendorBillRepository');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

describe('Step 9a freight and insurance are counted in rupees (D6, 2 Nov 2026)', () => {
  let userId;

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D6 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-d6-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** A USD CIF order with freight 6,000 + insurance 200 confirmed at Step 9a. */
  async function cifOrder() {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await db.execute("UPDATE orders SET currency_code = 'USD' WHERE id = :id", { id: orderId });
    await orderProductRepository.add(orderId, 1, 'Test granite', null, null, '10', false, 'PCS', '1000');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '6000', insurance_amount: '200' });
    return orderId;
  }

  it('converts freight and insurance at the advance rate', async () => {
    const orderId = await cifOrder();
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 83.5, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.freight_cost_inr).toBe(501000);
    expect(p.insurance_cost_inr).toBe(16700);
    expect(p.freight_rate_missing).toBe(false);
    expect(p.freight_rate_source).toBe('advance_rate');
    expect(p.freight_conversion_text).toBe('USD 6,000.00 × 83.50 = ₹501,000.00');
    expect(p.insurance_conversion_text).toBe('USD 200.00 × 83.50 = ₹16,700.00');
    expect(p.total_cost_inr).toBeCloseTo(p.supplier_cost_inr + 501000 + 16700 + p.other_costs_inr + p.logistics_cost_inr + p.expenses_inr + p.zoho_expenses_inr, 2);
  });

  it("uses the buyer's freight payment rate once it is recorded", async () => {
    const orderId = await cifOrder();
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 83.5, userId);
    await orderPaymentStatusRepository.recordFreightReceived(orderId, 6200, '2026-06-10');
    await orderPaymentStatusRepository.setFreightInrActual(orderId, 520800, userId); // 6,200 × 84

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.freight_rate_source).toBe('freight_payment');
    expect(p.freight_cost_inr).toBe(504000);
    expect(p.insurance_cost_inr).toBe(16800);
  });

  it('leaves the foreign amount out and flags it when there is no rate', async () => {
    const orderId = await cifOrder();

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.freight_cost_inr).toBe(0);
    expect(p.insurance_cost_inr).toBe(0);
    expect(p.freight_rate_missing).toBe(true);
    expect(p.freight_conversion_text).toBe('USD 6,000.00 — not counted yet, no exchange rate');
  });

  it("still lets the forwarder's rupee bills replace the confirmed rate", async () => {
    const orderId = await cifOrder();
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 83.5, userId);
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'D6 Forwarder', service_type: 'freight_forwarder' }, userId);
    await orderVendorBillRepository.create(orderId, 'logistics', { logistics_partner_id: partnerId, bill_for: 'ocean_freight', bill_no: 'OF-D6', bill_date: todayIst(), taxable_inr: 480000, gst_inr: 0 }, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.freight_from_bills).toBe(true);
    expect(p.freight_cost_inr).toBe(480000);
    expect(p.freight_conversion_text).toBeNull();
    expect(p.insurance_cost_inr).toBe(16700);
  });

  it('shows the conversion in the CA annexure', async () => {
    const orderId = await cifOrder();
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 83.5, userId);

    const text = JSON.stringify(await caFinancialSnapshotService.build(orderId));

    expect(text).toContain('Confirmed rate (Step 9a): USD 6,000.00 × 83.50 = ₹501,000.00');
    expect(text).toContain('Step 9a: USD 200.00 × 83.50 = ₹16,700.00');
  });
});
