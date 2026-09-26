'use strict';

const db = require('../../src/config/db');
const reportRepository = require('../../src/repositories/reportRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Regression coverage for the CA/Reports gap-analysis additions (debtors
 * ageing, freight cost, product sales, supplier performance, conversion
 * rate) — verified once by hand against real dev data (see the QA-4
 * session notes), pinned here so a future change can't silently break
 * the bucket/aggregation math.
 */
describe('Report gap-analysis additions', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setPaymentStatus(orderId, fields) {
    await db.execute('INSERT INTO order_payment_status (order_id) VALUES (:order_id)', { order_id: orderId });
    if (Object.keys(fields).length) {
      const sets = Object.keys(fields).map((k) => `${k} = :${k}`).join(', ');
      await db.execute(`UPDATE order_payment_status SET ${sets} WHERE order_id = :order_id`, { ...fields, order_id: orderId });
    }
  }

  it('ageingReport buckets an overdue balance leg correctly', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // Order created "today" in the fixture, so force created_at back 45
    // days to land in the 31-60 day bucket deterministically.
    await db.execute('UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 45 DAY) WHERE id = :id', { id: orderId });
    await setPaymentStatus(orderId, { balance_amount: 1000.0 });

    const report = await reportRepository.ageingReport();
    const row = report.rows.find((r) => r.order_id === orderId);

    expect(row).toBeDefined();
    expect(row.leg).toBe('Balance');
    expect(row.amount).toBe(1000);
    expect(row.bucket).toBe('31-60 days');
    expect(row.days_overdue).toBeGreaterThanOrEqual(31);
  });

  it('ageingReport excludes a cleared leg', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await setPaymentStatus(orderId, { advance_amount: 500.0, advance_cleared_at: new Date() });

    const report = await reportRepository.ageingReport();
    expect(report.rows.some((r) => r.order_id === orderId)).toBe(false);
  });

  it('freightCostReport aggregates by forwarder', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'CIF');
    await db.execute(
      `INSERT INTO order_freight (order_id, confirmed_freight_rate, insurance_amount, freight_forwarder_name)
       VALUES (:order_id, 900.00, 50.00, :forwarder)`,
      { order_id: orderId, forwarder: 'Jest Forwarder Ltd' }
    );

    const report = await reportRepository.freightCostReport(null, null);
    expect(report.by_forwarder['Jest Forwarder Ltd']).toBeDefined();
    expect(report.by_forwarder['Jest Forwarder Ltd'].USD.order_count).toBe(1);
    expect(report.by_forwarder['Jest Forwarder Ltd'].USD.total_rate).toBe(900);
  });

  it('productSalesReport groups by HS code', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute(
      `INSERT INTO order_products (order_id, line_no, description, quantity, unit_price, fob_value, hs_code, is_active)
       VALUES (:order_id, 1, :desc, 10, 100.00, 1000.00, :hs, 1)`,
      { order_id: orderId, desc: 'Jest Test Slab', hs: '9999.98' }
    );

    const report = await reportRepository.productSalesReport(null, null);
    expect(report.by_hs_code['9999.98']).toBeDefined();
    expect(report.by_hs_code['9999.98'].by_currency.USD.total_fob_value).toBe(1000);
  });

  it('supplierPerformanceReport computes the on-time delivery rate', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const supplierResult = await db.execute(
      "INSERT INTO suppliers (supplier_legal_name) VALUES ('Jest Test Supplier Co')"
    );
    const supplierId = supplierResult.insertId;
    await db.execute(
      `INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference, required_delivery_date, signed_at)
       VALUES (:order_id, :supplier_id, 'JEST-SPO-1', '2026-12-31', NOW())`,
      { order_id: orderId, supplier_id: supplierId }
    );
    await db.execute(
      "INSERT INTO order_packing (order_id, packing_date) VALUES (:order_id, '2026-12-01')",
      { order_id: orderId }
    );

    const report = await reportRepository.supplierPerformanceReport();
    const row = report.find((s) => s.supplier_name === 'Jest Test Supplier Co');

    expect(row).toBeDefined();
    expect(row.po_count).toBe(1);
    expect(row.signed_count).toBe(1);
    expect(row.delivery_tracked_count).toBe(1);
    expect(row.on_time_count).toBe(1);
    expect(row.on_time_pct).toBe(100);
  });

  it('conversionRateReport handles zero quotations without division by zero', async () => {
    const report = await reportRepository.conversionRateReport('2099-01-01', '2099-01-02'); // a window with no activity
    expect(report.quotations_sent).toBe(0);
    expect(report.quotation_to_pi_pct).toBeNull();
    expect(report.overall_quotation_to_confirmed_pct).toBeNull();
  });
});
